<?php
declare(strict_types=1);

/**
 * Read one of the older systems' databases (read only) and write an import bundle.
 *
 *   php scripts/export-old-systems.php staff  --db=people_management --org=1 > staff.json
 *   php scripts/export-old-systems.php people --db=people_service    --org=1 > people.json
 *   php scripts/export-old-systems.php teams  --db=team_service      --org=1 > teams.json
 *
 * Options: --host=127.0.0.1 --port=3306 --user=NAME --pass=SECRET   (or set OLD_DB_USER and OLD_DB_PASS)
 * The organisation number is the id in the OLD system's organisations table. Nothing is written to the old database.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Modules\Import\Exporters\PeopleService;
use App\Modules\Import\Exporters\StaffService;
use App\Modules\Import\Exporters\TeamService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$kind = $argv[1] ?? '';
$opt = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) {
        $opt[$m[1]] = $m[2];
    }
}
$exporter = ['staff' => StaffService::class, 'people' => PeopleService::class, 'teams' => TeamService::class][$kind] ?? null;
if (!$exporter || empty($opt['db']) || !isset($opt['org'])) {
    fwrite(STDERR, "Usage: php scripts/export-old-systems.php staff|people|teams --db=DATABASE --org=ORGANISATION_ID [--host= --port= --user= --pass=]\n");
    exit(1);
}
$user = $opt['user'] ?? getenv('OLD_DB_USER') ?: 'root';
$pass = $opt['pass'] ?? getenv('OLD_DB_PASS') ?: '';
try {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $opt['host'] ?? '127.0.0.1', $opt['port'] ?? '3306', $opt['db']), $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $bundle = $exporter::export($pdo, ['org' => (int) $opt['org']]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not export: ' . $e->getMessage() . "\n");
    exit(1);
}
foreach ($bundle['_notes'] ?? [] as $n) {
    fwrite(STDERR, "Note: $n\n");
}
$c = App\Modules\Import\Bundle::counts(App\Modules\Import\Bundle::normalise($bundle));
fwrite(STDERR, 'Exported: ' . implode(', ', array_map(static fn($k, $v) => "$v $k", array_keys(array_filter($c)), array_filter($c))) . "\n");
echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
