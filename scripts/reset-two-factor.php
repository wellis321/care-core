<?php
declare(strict_types=1);

/** For a locked-out administrator who has lost both their phone and their recovery codes:  php scripts/reset-two-factor.php email */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Actor;
use App\Core\Database as DB;
use App\Modules\Identity\TwoFactor;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$email = strtolower(trim((string) ($argv[1] ?? '')));
$u = $email === '' ? null : DB::one('SELECT id, uuid, email, organisation_id FROM users WHERE email = ?', [$email]);
if (!$u) {
    fwrite(STDERR, "Usage: php scripts/reset-two-factor.php email   (no login with that address)\n");
    exit(1);
}
TwoFactor::reset((int) $u['organisation_id'], $u, Actor::system('reset-two-factor'));
echo "Two-step sign-in removed for {$u['email']}. They can sign in with their password and set it up again.\n";
