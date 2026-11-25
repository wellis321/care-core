<?php
declare(strict_types=1);
/**
 * One-off, idempotent, for the hosted demo: makes sure DEMO_LEARN_TOKEN (cc_ + 8 hex + _ + 48 hex) is in this app's .env. The nightly demo reset creates the matching API key
 * (people, staff and teams read; learning records read and write) for the Learning Core demo. Never prints the value.   php scripts/make-learn-token.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$env = dirname(__DIR__) . '/.env'; $text = is_file($env) ? (string) file_get_contents($env) : '';
if (preg_match('/^DEMO_LEARN_TOKEN=cc_[0-9a-f]{8}_[0-9a-f]{48}\s*$/m', $text)) { echo "DEMO_LEARN_TOKEN is already in .env.\n"; exit(0); }
$tok = 'cc_' . bin2hex(random_bytes(4)) . '_' . bin2hex(random_bytes(24));
file_put_contents($env, rtrim(preg_replace('/^DEMO_LEARN_TOKEN=.*\R?/m', '', $text)) . "\nDEMO_LEARN_TOKEN=$tok\n", LOCK_EX);
echo "DEMO_LEARN_TOKEN added to .env.\n";
