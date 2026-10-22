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

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $this->body;
    }
}
