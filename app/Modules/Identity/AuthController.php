<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;

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
            Auth::attempt($r->input('email'), (string) ($r->post['password'] ?? ''), $r->ip);
        } catch (DomainException $e) {
            return $this->view('auth/login', ['title' => 'Sign in', 'next' => $r->input('next'), 'email' => $r->input('email'), 'error' => $e->getMessage()], 401);
        }
        return $this->redirect($this->safeNext($r->input('next'), '/'));
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
        return $this->view('auth/account', ['title' => 'Your account', 'hasPassword' => (bool) DB::value('SELECT password_hash FROM users WHERE id = ?', [$u['id']]), 'demoLocked' => UserRepo::isDemoAccount($u['email']), 'error' => null]);
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
}
