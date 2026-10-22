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
if ($existing && !$fresh) {
    fwrite(STDERR, "The database already has tables. Use --fresh to drop and recreate them (this deletes all data).\n");
    exit(1);
}
if ($fresh && !Config::demo() && !str_contains((string) Config::get('DB_NAME'), 'test') && !str_contains((string) Config::get('DB_NAME'), 'demo')) {
    fwrite(STDERR, "Refusing --fresh on a database that is not a demo or test database.\n");
    exit(1);
}
DB::pdo()->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
DB::run('INSERT INTO schema_migrations (version) VALUES (?)', ['0001_initial']);
echo "Database ready.\n";
