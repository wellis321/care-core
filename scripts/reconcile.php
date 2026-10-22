<?php
declare(strict_types=1);

/**
 * Nightly consistency check across every organisation.
 *   php scripts/reconcile.php          report only (exit code 1 if anything serious is found)
 *   php scripts/reconcile.php --fix    also end the places, links and logins that should have ended
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Actor;
use App\Core\Database as DB;
use App\Modules\Staff\Reconcile;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$fix = in_array('--fix', $argv, true);
$serious = 0;
foreach (DB::all('SELECT id, name FROM organisations ORDER BY id') as $org) {
    $issues = Reconcile::check((int) $org['id']);
    $high = array_filter($issues, static fn($i) => $i['severity'] === 'high');
    $serious += count($high);
    echo $org['name'] . ': ' . count($issues) . ' finding(s), ' . count($high) . " serious\n";
    foreach ($issues as $i) {
        echo '  [' . $i['severity'] . '] ' . $i['person'] . ': ' . $i['detail'] . "\n";
    }
    if ($fix && $issues) {
        echo '  fixed: ' . Reconcile::fix((int) $org['id'], Actor::system('reconcile'))['fixed'] . "\n";
    }
}
exit($serious > 0 && !$fix ? 1 : 0);
