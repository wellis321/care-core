<?php
declare(strict_types=1);

/** Create or rebuild the database from database/schema.sql.  php scripts/migrate.php [--fresh] */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database as DB;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$fresh = in_array('--fresh', $argv, true);
$existing = (int) DB::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'organisations'");
$files = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
sort($files);
if ($existing && !$fresh) {
    // Bring an existing database up to date: apply each numbered migration once, in order. Nothing is dropped.
    $applied = array_column(DB::all('SELECT version FROM schema_migrations'), 'version');
    $n = 0;
    foreach ($files as $f) {
        $version = basename($f, '.sql');
        if (in_array($version, $applied, true)) {
            continue;
        }
        DB::pdo()->exec((string) file_get_contents($f));
        DB::run('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
        echo "Applied $version\n";
        $n++;
    }
    echo $n ? "Database up to date.\n" : "Nothing to apply.\n";
    exit(0);
}
if ($fresh && !Config::demo() && !str_contains((string) Config::get('DB_NAME'), 'test') && !str_contains((string) Config::get('DB_NAME'), 'demo')) {
    fwrite(STDERR, "Refusing --fresh on a database that is not a demo or test database.\n");
    exit(1);
}
DB::pdo()->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
DB::run('INSERT INTO schema_migrations (version) VALUES (?)', ['0001_initial']);
foreach ($files as $f) {   // schema.sql already contains them
    DB::run('INSERT INTO schema_migrations (version) VALUES (?)', [basename($f, '.sql')]);
}
echo "Database ready.\n";
