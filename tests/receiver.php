<?php
// A tiny webhook receiver for tests: records what it gets, and fails on demand (?fail=1 in the URL).
$body = file_get_contents('php://input');
file_put_contents(sys_get_temp_dir() . '/carecore_hook.log', json_encode([
    'sig' => $_SERVER['HTTP_X_CARECORE_SIGNATURE'] ?? null, 'event' => $_SERVER['HTTP_X_CARECORE_EVENT'] ?? null,
    'id' => $_SERVER['HTTP_X_CARECORE_EVENT_ID'] ?? null, 'body' => $body,
]) . "\n", FILE_APPEND);
http_response_code(isset($_GET['fail']) ? 500 : 204);
