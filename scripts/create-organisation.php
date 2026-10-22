<?php
declare(strict_types=1);

/**
 * Create an organisation and its first administrator, for a new installation.
 *   php scripts/create-organisation.php "Organisation name" admin@example.org
 * Prints a one-time invitation link: the administrator opens it and chooses their own password.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Actor;
use App\Core\Database as DB;
use App\Core\Uuid;
use App\Modules\Identity\UserRepo;
use App\Modules\Staff\Checklists;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$name = trim((string) ($argv[1] ?? ''));
$email = strtolower(trim((string) ($argv[2] ?? '')));
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/create-organisation.php \"Organisation name\" admin@example.org\n");
    exit(1);
}
$slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'organisation';
if (DB::value('SELECT 1 FROM organisations WHERE slug = ?', [$slug])) {
    $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
}
$token = null;
try {
    DB::transaction(function () use ($name, $slug, $email, &$token) {
        $org = DB::insert('organisations', ['uuid' => Uuid::v4(), 'name' => $name, 'slug' => $slug]);
        Checklists::seedDefaults($org);
        $token = UserRepo::create($org, null, $email, 'admin', Actor::system('install'), true)['invite_token'];
    });
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not create it: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "Created \"$name\".\nGive $email this one-time link (valid 7 days) to choose a password:\n" . \App\Core\Config::appUrl() . "/invite/$token\n";
