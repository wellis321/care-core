<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/** "Sign in with Microsoft". */
final class SsoController extends Controller
{
    public function start(Request $r, array $p = []): Response
    {
        if (!Oidc::configured()) {
            return \App\Core\Router::error(404, 'Not found', 'Microsoft sign-in is not set up.');
        }
        return $this->redirect(Oidc::begin($this->safeNext($r->input('next'), '/')));
    }

    public function callback(Request $r, array $p = []): Response
    {
        try {
            [$user, $next] = Oidc::complete($r->query);
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
            return $this->redirect('/login');
        }
        Auth::signInAs($user);
        return $this->redirect($this->safeNext($next, '/'));
    }
}
