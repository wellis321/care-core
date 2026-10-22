<?php
declare(strict_types=1);

/**
 * Test runner with no dependencies:  DB_NAME=care_core_test php tests/run.php [filter]
 * Each test builds its own organisation, so tests do not depend on each other.
 */
putenv('DB_NAME=' . (getenv('DB_NAME') ?: 'care_core_test'));
putenv('DEMO_MODE=true');
putenv('APP_KEY=' . base64_encode(str_repeat('k', 32)));
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database as DB;

if (!str_contains((string) App\Core\Config::get('DB_NAME'), 'test')) {
    fwrite(STDERR, "Refusing to run tests against a database that is not named *test*.\n");
    exit(1);
}

// Start every run from a clean database.
DB::pdo()->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));

$GLOBALS['__tests'] = [];
$GLOBALS['__fail'] = 0;
$GLOBALS['__pass'] = 0;

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
}

function ok(bool $cond, string $msg = 'expected true'): void
{
    if (!$cond) {
        throw new AssertionError($msg);
    }
}

function eq(mixed $actual, mixed $expected, string $msg = ''): void
{
    if ($actual != $expected) {
        throw new AssertionError(($msg !== '' ? $msg . ': ' : '') . 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
}

function throws(callable $fn, string $contains = ''): void
{
    try {
        $fn();
    } catch (App\Core\DomainException $e) {
        if ($contains !== '' && stripos($e->getMessage(), $contains) === false) {
            throw new AssertionError('wrong message: "' . $e->getMessage() . '" does not contain "' . $contains . '"');
        }
        return;
    }
    throw new AssertionError('expected a DomainException' . ($contains !== '' ? ' containing "' . $contains . '"' : ''));
}

require __DIR__ . '/Fx.php';
foreach (glob(__DIR__ . '/cases/*.php') as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
foreach ($GLOBALS['__tests'] as [$name, $fn]) {
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }
    App\Core\Clock::freeze(null);
    try {
        $fn();
        $GLOBALS['__pass']++;
        echo "PASS  $name\n";
    } catch (Throwable $e) {
        $GLOBALS['__fail']++;
        echo "FAIL  $name\n      " . get_class($e) . ': ' . $e->getMessage() . "\n      at " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}
echo "\n{$GLOBALS['__pass']} passed, {$GLOBALS['__fail']} failed\n";
exit($GLOBALS['__fail'] ? 1 : 0);
