<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    /** @param array<string,mixed> $data */
    protected function view(string $view, array $data = [], int $status = 200): Response
    {
        return Response::html(View::render($view, $data), $status);
    }

    protected function redirect(string $url): Response
    {
        return Response::redirect($url);
    }

    protected function orgId(): int
    {
        return Auth::orgId();
    }

    protected function actor(): Actor
    {
        return Auth::actor();
    }

    protected function ok(string $message): void
    {
        Session::flash('ok', $message);
    }

    /** Show something once, prominently, on the page the person lands on: a key they must copy now. */
    protected function reveal(string $title, string $label, string $value, string $note): void
    {
        Session::reveal(['title' => $title, 'label' => $label, 'value' => $value, 'note' => $note]);
    }

    protected function fail(string $message): void
    {
        Session::flash('error', $message);
    }

    /** Only same-site paths, so a crafted "next" cannot send someone elsewhere. */
    protected function safeNext(string $next, string $fallback = '/'): string
    {
        return (preg_match('#^/[A-Za-z0-9/_\-.?=&%]*$#', $next) && !str_starts_with($next, '//')) ? $next : $fallback;
    }
}
