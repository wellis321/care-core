<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @param array<string,string> $headers */
    private function __construct(public int $status, public string $body, public array $headers)
    {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $url): self
    {
        return new self(303, '', ['Location' => $url]);
    }

    /** @param array<string,mixed>|null $data */
    public static function json(?array $data, int $status = 200, string $type = 'application/json'): self
    {
        return new self($status, $data === null ? '' : (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ['Content-Type' => $type . '; charset=utf-8']);
    }

    /** A private file (an image the person is allowed to see). Never cached by shared caches, never sniffed. */
    public static function file(string $body, string $mime): self
    {
        return new self(200, $body, ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline']);
    }

    public function send(): void
    {
        $body = $this->body;
        $headers = $this->headers;
        // Send one complete response of a known length. Left to itself the web server compresses a page as it streams it, in
        // chunks with no Content-Length, and a stall in the middle (seen on shared hosting) leaves the browser with the top of
        // the page and a spinner until it is refreshed. So compress it here, whole, and say exactly how long it is.
        if ($body !== '' && strlen($body) > 1024 && $this->status !== 204 && $this->status !== 304 && $this->compressible($headers)) {
            $accept = strtolower($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
            if (str_contains($accept, 'gzip') && function_exists('gzencode') && !isset($headers['Content-Encoding'])) {
                $zipped = gzencode($body, 6);
                if ($zipped !== false) {
                    $body = $zipped;
                    $headers['Content-Encoding'] = 'gzip';
                }
            }
            $headers['Vary'] = 'Accept-Encoding';
        }
        // Hostinger's edge offers browsers HTTP/3 (Alt-Svc), and a browser that reuses a connection the edge has quietly closed
        // sits for ~20 seconds before it gives up (a page that "spins" until it is refreshed). Tell browsers to stay on HTTP/2.
        $headers['Alt-Svc'] = 'clear';
        $headers['Content-Length'] = (string) strlen($body);
        if (function_exists('ini_set')) {
            @ini_set('zlib.output_compression', '0');
        }
        http_response_code($this->status);
        foreach ($headers as $k => $v) {
            header($k . ': ' . $v, true);
        }
        echo $body;
    }

    /** @param array<string,string> $headers */
    private function compressible(array $headers): bool
    {
        $type = strtolower($headers['Content-Type'] ?? '');
        return str_starts_with($type, 'text/') || str_contains($type, 'json') || str_contains($type, 'xml') || str_contains($type, 'javascript');
    }
}
