<?php
declare(strict_types=1);

/**
 * Import one or more bundles into an organisation.
 *
 *   php scripts/import.php --org=your-org-slug staff.json people.json teams.json              (a dry run: changes nothing)
 *   php scripts/import.php --org=your-org-slug --commit staff.json people.json teams.json     (does it)
 *
 * Options:
 *   --org=SLUG|UUID      the organisation to import into (required)
 *   --commit             really import. Without it, everything is checked and rolled back.
 *   --skip-errors        import the records that are fine even if some cannot be (default: all or nothing)
 *   --files-from=DIR     the old system's uploads folder, to bring photos and signatures across
 *   --json               print the report as JSON
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database as DB;
use App\Modules\Import\Bundle;
use App\Modules\Import\Importer;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$opt = [];
$files = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? '1';
    } else {
        $files[] = $a;
    }
}
$org = isset($opt['org']) ? DB::one('SELECT id, name FROM organisations WHERE slug = ? OR uuid = ?', [$opt['org'], $opt['org']]) : null;
if (!$org || !$files) {
    fwrite(STDERR, "Usage: php scripts/import.php --org=SLUG [--commit] [--skip-errors] [--files-from=DIR] bundle.json [more.json …]\n");
    exit(1);
}
try {
    $bundles = array_map(static function (string $f) {
        if (!is_file($f)) {
            throw new RuntimeException("File not found: $f");
        }
        return Bundle::parse((string) file_get_contents($f));
    }, $files);
    $bundle = Bundle::merge($bundles);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$report = (new Importer((int) $org['id'], !isset($opt['commit']), isset($opt['skip-errors']), $opt['files-from'] ?? null))->run($bundle, implode(', ', array_map('basename', $files)));
$report['notes'] = $bundle['notes'];
if (isset($opt['json'])) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit($report['errors'] ? 2 : 0);
}
echo ($report['mode'] === 'dry_run' ? 'DRY RUN: nothing was changed' : ($report['imported'] ? 'IMPORTED into ' . $org['name'] : 'NOT IMPORTED (rolled back)')) . "\n\n";
foreach ($report['counts'] as $entity => $c) {
    echo str_pad($entity, 22) . implode(', ', array_map(static fn($k, $v) => "$v " . str_replace('_', ' ', $k), array_keys($c), $c)) . "\n";
}
foreach ($bundle['notes'] as $n) {
    echo "\nNote: $n";
}
if ($report['warnings']) {
    echo "\n\nWarnings (" . count($report['warnings']) . ") - something was left out or adjusted:\n";
    foreach ($report['warnings'] as $w) {
        echo "  [{$w['ref']}] {$w['message']}\n";
    }
}
if ($report['errors']) {
    echo "\nErrors (" . count($report['errors']) . ") - these records could not be imported:\n";
    foreach ($report['errors'] as $e) {
        echo "  [{$e['ref']}] {$e['message']}\n";
    }
    echo "\n" . ($report['imported'] ? "The rest was imported (--skip-errors).\n" : "Nothing was imported. Fix the source data, or use --skip-errors to import the records that are fine.\n");
}
exit($report['errors'] ? 2 : 0);
