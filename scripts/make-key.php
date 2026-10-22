<?php
declare(strict_types=1);

/**
 * Add APP_KEY to .env if it is not there yet (never overwrites an existing key: that would make stored National
 * Insurance and bank details unreadable).  php scripts/make-key.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$env = dirname(__DIR__) . '/.env';
$text = is_file($env) ? (string) file_get_contents($env) : '';
if (preg_match('/^APP_KEY=\S+/m', $text)) {
    echo "APP_KEY is already set.\n";
    exit(0);
}
$line = 'APP_KEY=' . base64_encode(random_bytes(32));
$text = preg_replace('/^APP_KEY=\s*$/m', '', $text) ?? $text;
file_put_contents($env, rtrim($text, "\r\n") . "\n" . $line . "\n", LOCK_EX);
echo "APP_KEY added.\n";
