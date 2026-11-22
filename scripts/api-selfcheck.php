<?php
/**
 * Calls a few read endpoints in-process with the demo link key and prints each status (with the error detail, because APP_DEBUG is switched on for this run only).
 *   php scripts/api-selfcheck.php        (never prints the key)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('APP_DEBUG=1'); $_ENV['APP_DEBUG'] = '1';
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Api\Api;
use App\Core\Database as DB;
use App\Core\Request;

$token = trim((string) getenv('DEMO_LINK_TOKEN'));
$uuid = (string) DB::value("SELECT p.uuid FROM persons p JOIN employments e ON e.person_id = p.id WHERE e.end_date IS NULL ORDER BY p.id DESC LIMIT 1");
foreach (['/people/' . $uuid . '/learning', '/people/' . $uuid . '/photo', '/people/' . $uuid . '/employments', '/referrals?limit=1'] as $path) {
    $req = new Request('GET', Api::PREFIX . $path, [], [], ['host' => 'localhost', 'authorization' => 'Bearer ' . $token, 'accept' => 'application/json'], '', '127.0.0.1');
    $res = Api::handle($req);
    echo $res->status . ' ' . preg_replace('/[0-9a-f-]{36}/', '<id>', $path) . ' ' . substr(str_replace(["\n", $token], [' ', '<key>'], (string) $res->body), 0, 260) . "\n";
}
