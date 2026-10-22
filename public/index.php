<?php
declare(strict_types=1);

// Serve real files directly when run with PHP's built-in server.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;

$request = Request::capture();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
if ($request->secure()) {
    header('Strict-Transport-Security: max-age=31536000');
}

try {
    if (App\Api\Api::handles($request->path)) {
        $response = App\Api\Api::handle($request);   // API keys, no session, no cookies
    } elseif (str_starts_with($request->path, '/scim/')) {
        $response = (new App\Modules\Identity\ScimController())->handle($request);   // token auth, no session
    } else {
        Session::start($request->secure());
        $router = new Router();
        require ROOT_PATH . '/app/routes.php';
        $response = $router->dispatch($request);
    }
} catch (App\Core\DomainException $e) {
    $response = Router::error(400, 'Something is wrong', $e->getMessage());
} catch (Throwable $e) {
    error_log('Care Core error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    $message = Config::bool('APP_DEBUG') ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : 'Something went wrong on our side. Please try again.';
    $response = Router::error(500, 'Something went wrong', $message);
}
$response->send();
