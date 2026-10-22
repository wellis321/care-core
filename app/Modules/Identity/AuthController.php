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
