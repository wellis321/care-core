<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Totp;

final class AuthController extends Controller
{
    public function loginForm(Request $r, array $p = []): Response
    {
        if (Auth::user()) {
            return $this->redirect('/');
        }
        return $this->view('auth/login', ['title' => 'Sign in', 'next' => $r->input('next'), 'email' => '', 'error' => null]);
    }

    public function login(Request $r, array $p = []): Response
    {
        try {
            $user = Auth::verifyPassword($r->input('email'), (string) ($r->post['password'] ?? ''), $r->ip);
        } catch (DomainException $e) {
            return $this->view('auth/login', ['title' => 'Sign in', 'next' => $r->input('next'), 'email' => $r->input('email'), 'error' => $e->getMessage()], 401);
        }
        $next = $this->safeNext($r->input('next'), '/');
        if (TwoFactor::on((int) $user['id'])) {
            // The password was right; one more step. Nothing is signed in yet.
            Session::set('pending_2fa', ['uid' => (int) $user['id'], 'next' => $next, 'at' => time()]);
            return $this->redirect('/login/2fa');
        }
        Auth::complete($user);
        return $this->redirect($next);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>}|null the waiting sign-in and its user, or null when it has lapsed (10 minutes) */
    private function pending(): ?array
    {
        $p = Session::get('pending_2fa');
        if (!is_array($p) || time() - (int) $p['at'] > 600) {
            Session::set('pending_2fa', null);
            return null;
        }
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$p['uid']]);
        return $u && UserRepo::canSignIn($u) ? [$p, $u] : null;
    }

    public function twoFactorForm(Request $r, array $p = []): Response
    {
        if (!$this->pending()) {
            $this->fail('Start signing in again.');
            return $this->redirect('/login');
        }
        return $this->view('auth/twofactor', ['title' => 'Second step', 'error' => null]);
    }

    public function twoFactorCheck(Request $r, array $p = []): Response
    {
        $pe = $this->pending();
        if (!$pe) {
            $this->fail('Start signing in again.');
            return $this->redirect('/login');
        }
        [$wait, $user] = $pe;
        try {
            $ok = TwoFactor::check($user, (string) ($r->post['code'] ?? ''), $r->ip);
        } catch (DomainException $e) {
            Session::set('pending_2fa', null);
            return $this->view('auth/login', ['title' => 'Sign in', 'next' => '', 'email' => '', 'error' => $e->getMessage()], 429);
        }
        if (!$ok) {
            return $this->view('auth/twofactor', ['title' => 'Second step', 'error' => 'That code is not right. Use the current code from your app, or a recovery code.'], 401);
        }
        Session::set('pending_2fa', null);
        Auth::complete($user, true);
        return $this->redirect((string) $wait['next']);
    }

    /** Demo only: sign in as the shared manager, staff or administrator account with one press. See DemoEntry for how robots are kept out. */
    public function demoEnter(Request $r, array $p = []): Response
    {
        if (!\App\Core\Config::demo()) {
            return \App\Core\Router::error(404, 'Page not found', 'We could not find that page.');
        }
        $fail = fn(string $m): Response => $this->view('auth/login', ['title' => 'Sign in', 'next' => '', 'email' => '', 'error' => $m], 400);
        if (!DemoEntry::allowed($r->ip)) return $fail('That is a lot of attempts. Please wait a little and try again.');
        $human = ($r->post[DemoEntry::HONEYPOT] ?? '') === '' && !DemoEntry::looksAutomated($r->header('user-agent')) && (Auth::user() !== null || DemoEntry::tokenOk($r->input('ft')));
        $u = $human ? DemoEntry::userFor($r->input('role')) : null;
        if (!$u) return $fail('That did not work. Please wait a moment after the page loads and press the button again, or use one of the demo accounts below.');
        DB::update('users', ['last_login_at' => gmdate('Y-m-d H:i:s')], ['id' => $u['id']]);
        Auth::signInAs($u);
        return $this->redirect('/');
    }

    public function logout(Request $r, array $p = []): Response
    {
        Auth::logout();
        return $this->redirect('/login');
    }

    public function inviteForm(Request $r, array $p): Response
    {
        $user = DB::one("SELECT email FROM users WHERE invite_token_hash = ? AND status = 'invited' AND invite_expires_at > UTC_TIMESTAMP()", [hash('sha256', $p['token'])]);
        if (!$user) {
            return $this->view('auth/invite', ['title' => 'Invitation', 'email' => null, 'error' => 'This invitation has expired or has already been used.'], 410);
        }
        return $this->view('auth/invite', ['title' => 'Choose a password', 'email' => $user['email'], 'error' => null]);
    }

    public function invite(Request $r, array $p): Response
    {
        $password = (string) ($r->post['password'] ?? '');
        if ($password !== (string) ($r->post['password2'] ?? '')) {
            return $this->view('auth/invite', ['title' => 'Choose a password', 'email' => 'your account', 'error' => 'The two passwords do not match.'], 400);
        }
        try {
            UserRepo::acceptInvite($p['token'], $password);
        } catch (DomainException $e) {
            return $this->view('auth/invite', ['title' => 'Choose a password', 'email' => 'your account', 'error' => $e->getMessage()], 400);
        }
        $this->ok('Your password is set. You can sign in now.');
        return $this->redirect('/login');
    }

    public function accountForm(Request $r, array $p = []): Response
    {
        $u = Auth::user();
        return $this->view('auth/account', ['title' => 'Your account', 'hasPassword' => (bool) DB::value('SELECT password_hash FROM users WHERE id = ?', [$u['id']]), 'tf' => $this->tfState($u), 'demoLocked' => UserRepo::isDemoAccount($u['email']), 'error' => null]);
    }

    public function changePassword(Request $r, array $p = []): Response
    {
        $u = Auth::user();
        $hasPassword = (bool) DB::value('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
        $new = (string) ($r->post['new_password'] ?? '');
        try {
            if ($new !== (string) ($r->post['new_password2'] ?? '')) {
                throw new DomainException('The two new passwords do not match.');
            }
            UserRepo::changePassword((int) $u['id'], (string) ($r->post['current_password'] ?? ''), $new, $this->actor());
        } catch (DomainException $e) {
            return $this->view('auth/account', ['title' => 'Your account', 'hasPassword' => $hasPassword, 'demoLocked' => UserRepo::isDemoAccount($u['email']), 'error' => $e->getMessage()], 422);
        }
        session_regenerate_id(true);
        $this->ok('Your password has been changed.');
        return $this->redirect('/account');
    }

    /** What the account page needs to show about two-step sign-in. @return array<string,mixed> */
    private function tfState(array $u): array
    {
        $uid = (int) $u['id'];
        $secret = TwoFactor::pendingSecret($uid);
        $hasPassword = (bool) DB::value('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        return [
            'available' => $hasPassword && !UserRepo::isDemoAccount((string) $u['email']),
            'on' => TwoFactor::on($uid), 'left' => TwoFactor::recoveryLeft($uid), 'secret' => $secret,
            'uri' => $secret ? Totp::uri($secret, (string) $u['email'], 'Care Core') : null,
        ];
    }

    private function tfBack(string $error = ''): Response
    {
        if ($error !== '') {
            $this->fail($error);
        }
        return $this->redirect('/account#two-step');
    }

    public function twoFactorStart(Request $r, array $p = []): Response
    {
        $u = Auth::user();
        if (!$this->tfState($u)['available']) {
            return $this->tfBack('Two-step sign-in is for accounts that sign in with a password. A shared demonstration account cannot use it.');
        }
        TwoFactor::begin((int) $u['id']);
        return $this->tfBack();
    }

    public function twoFactorEnable(Request $r, array $p = []): Response
    {
        $u = Auth::user();
        try {
            $codes = TwoFactor::enable($this->orgId(), $u, (string) ($r->post['code'] ?? ''), $this->actor());
        } catch (DomainException $e) {
            return $this->tfBack($e->getMessage());
        }
        return $this->view('auth/twofactor-codes', ['title' => 'Save your recovery codes', 'codes' => $codes]);
    }

    public function twoFactorDisable(Request $r, array $p = []): Response
    {
        try {
            TwoFactor::disable($this->orgId(), Auth::user(), (string) ($r->post['password'] ?? ''), $this->actor());
        } catch (DomainException $e) {
            return $this->tfBack($e->getMessage());
        }
        $this->ok('Two-step sign-in is switched off.');
        return $this->tfBack();
    }

    public function twoFactorCodes(Request $r, array $p = []): Response
    {
        $u = Auth::user();
        $hash = (string) DB::value('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
        if (!TwoFactor::on((int) $u['id']) || $hash === '' || !password_verify((string) ($r->post['password'] ?? ''), $hash)) {
            return $this->tfBack('Your password is not right.');
        }
        return $this->view('auth/twofactor-codes', ['title' => 'Save your recovery codes', 'codes' => TwoFactor::newRecoveryCodes((int) $u['id'])]);
    }
}
