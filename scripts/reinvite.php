<?php
declare(strict_types=1);

/**
 * Issue a new one-time invitation link for a login from the server: for when the only administrator is locked out.
 *   php scripts/reinvite.php admin@example.org
 * The old password stops working immediately. Needs shell access to the server, so it is not exposed on the web.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Actor;
use App\Core\Database as DB;
use App\Modules\Identity\UserRepo;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$email = strtolower(trim((string) ($argv[1] ?? '')));
$user = $email !== '' ? DB::one('SELECT id, organisation_id FROM users WHERE email = ?', [$email]) : null;
if (!$user) {
    fwrite(STDERR, "Usage: php scripts/reinvite.php email@example.org   (no login with that email was found)\n");
    exit(1);
}
try {
    $token = UserRepo::reinvite((int) $user['organisation_id'], (int) $user['id'], Actor::system('server'));
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "Give $email this one-time link (valid 7 days) to choose a new password:\n" . \App\Core\Config::appUrl() . "/invite/$token\n";
