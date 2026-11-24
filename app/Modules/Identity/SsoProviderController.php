<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;

/** The provider's public endpoints (discovery, keys, authorise, token). The admin page for registering products is in AdminController. */
final class SsoProviderController extends Controller
{
    public function discovery(Request $r, array $p = []): Response
    {
        return $this->cached(Response::json(OidcProvider::discovery()));
    }

    public function jwks(Request $r, array $p = []): Response
    {
        return $this->cached(Response::json(OidcProvider::jwks()));
    }

    private function cached(Response $resp): Response
    {
        $resp->headers['Cache-Control'] = 'public, max-age=300';
        return $resp;
    }

    public function authorize(Request $r, array $p = []): Response
    {
        $q = $r->query;
        try {
            [$client, $err] = OidcProvider::checkRequest($q);
        } catch (DomainException $e) {
            return Router::error(400, 'Cannot sign you in', $e->getMessage());   // never redirect to an address we do not trust
        }
        $back = fn(string $error, string $desc = '') => $this->sendBack($q, ['error' => $error] + ($desc !== '' ? ['error_description' => $desc] : []));
        if ($err) {
            return $back($err);
        }
        $user = Auth::user();
        $prompt = (string) ($q['prompt'] ?? '');
        $maxAge = isset($q['max_age']) && ctype_digit((string) $q['max_age']) ? (int) $q['max_age'] : null;
        $stale = $user && $maxAge !== null && time() - (int) \App\Core\Session::get('auth_time', 0) > $maxAge;
        if (!$user || $stale || (int) $user['organisation_id'] !== (int) $client['organisation_id']) {
            if ($prompt === 'none') {
                return $back('login_required');   // a product re-checking quietly: this person is no longer signed in to Care Core (or has left)
            }
            if ($user && ($stale || (int) $user['organisation_id'] !== (int) $client['organisation_id'])) {
                Auth::logout();
            }
            return $this->redirect('/login?next=' . rawurlencode('/oidc/authorize?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986)));
        }
        if (SignInPolicy::mustSetUp($user)) {
            \App\Core\Session::flash('error', 'Your organisation requires two-step sign-in. Set it up below to carry on.');
            return $this->redirect('/account#two-step');
        }
        $code = OidcProvider::issueCode($client, $user, $q, (int) \App\Core\Session::get('auth_time', time()), (string) \App\Core\Session::get('auth_amr', 'pwd'));
        $to = $this->url((string) $q['redirect_uri'], ['code' => $code] + (isset($q['state']) ? ['state' => (string) $q['state']] : []));
        if ($prompt === 'none') {
            return $this->redirect($to);
        }
        // A page, not a redirect: the sign-in form's security policy only allows posting to this site, and some browsers apply that to the redirect that follows. A page with a link is plain navigation.
        $resp = Response::html($this->render('oidc/continue', ['title' => 'Signing you in', 'product' => $client['name'], 'to' => $to, 'who' => $user['email']]));
        $resp->headers['Cache-Control'] = 'no-store';
        $resp->headers['Referrer-Policy'] = 'no-referrer';
        return $resp;
    }

    public function token(Request $r, array $p = []): Response
    {
        $id = $secret = null;
        if (preg_match('/^Basic\s+(.+)$/i', (string) $r->header('authorization'), $m) && ($dec = base64_decode($m[1], true)) !== false && str_contains($dec, ':')) {
            [$id, $secret] = array_map('rawurldecode', explode(':', $dec, 2));
        }
        $post = array_map(static fn($v) => is_array($v) ? '' : (string) $v, $r->post);
        try {
            $out = OidcProvider::exchange($post, $id, $secret);
            $resp = Response::json($out);
        } catch (DomainException $e) {
            $resp = Response::json(['error' => $e->getMessage()], $e->getMessage() === 'invalid_client' ? 401 : 400);
        }
        $resp->headers['Cache-Control'] = 'no-store';
        $resp->headers['Pragma'] = 'no-cache';
        return $resp;
    }

    /** @param array<string,string> $q @param array<string,string> $params */
    private function sendBack(array $q, array $params): Response
    {
        return $this->redirect($this->url((string) $q['redirect_uri'], $params + (isset($q['state']) ? ['state' => (string) $q['state']] : [])));
    }

    /** @param array<string,string> $params */
    private function url(string $base, array $params): string
    {
        return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function render(string $view, array $data): string
    {
        return View::render($view, $data, '');
    }
}
