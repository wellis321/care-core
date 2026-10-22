<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{method:string,regex:string,handler:array{0:class-string,1:string},perm:?string,module:?string,csrf:bool,auth:bool}> */
    private array $routes = [];

    /** @param array{0:class-string,1:string} $handler */
    public function add(string $method, string $pattern, array $handler, ?string $perm = null, ?string $module = null, bool $csrf = true, bool $auth = true): void
    {
        $regex = '#^' . preg_replace('/\{(\w+)\}/', '(?P<$1>[A-Za-z0-9-]+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'perm' => $perm, 'module' => $module, 'csrf' => $csrf, 'auth' => $auth];
    }

    public function dispatch(Request $r): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $r->method || !preg_match($route['regex'], $r->path, $m)) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            if ($route['auth'] && !Auth::user()) {
                return Response::redirect('/login' . ($r->method === 'GET' && $r->path !== '/' ? '?next=' . rawurlencode($r->path) : ''));
            }
            if ($route['auth']) {
                if ($route['module'] && !Modules::enabled(Auth::orgId(), $route['module'])) {
                    return self::error(404, 'Not found', 'That part of Care Core is not switched on for your organisation.');
                }
                if ($route['perm'] && !Auth::can($route['perm'])) {
                    return self::error(403, 'Not allowed', 'Your role does not allow that.');
                }
            }
            if ($route['method'] !== 'GET' && $route['csrf'] && !Csrf::valid($r)) {
                return self::error(419, 'Please try again', 'Your session expired or the form was out of date. Go back, reload the page and try again.');
            }
            [$class, $method] = $route['handler'];
            return (new $class())->$method($r, $params);
        }
        return self::error(404, 'Page not found', 'We could not find that page.');
    }

    public static function error(int $status, string $title, string $message): Response
    {
        return Response::html(View::render('error', ['title' => $title, 'status' => $status, 'message' => $message]), $status);
    }
}
