<?php
declare(strict_types=1);
/**
 * Back up Care Core: the whole database, and the files people have uploaded (staff photos, signatures and documents in storage/uploads), to storage/backups/ as
 * a gzipped SQL file and a gzipped tar of the files, each with a checksum, and a manifest of row and file counts. Plain PHP (no mysqldump or tar needed on shared hosting). CLI only.
 *   php scripts/backup.php [--keep=14] [--to=/path/dir]
 * A backup that has never been restored is not a backup: run scripts/restore-check.php on it.
 * The files hold EVERYTHING, personal data included: keep the folder where only the service can read it (it is blocked from the web), and COPY BACKUPS OFF THE SERVER:
 * a backup on the same server is lost with the server.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\{Config, Database};

$keep = 14; $dir = ROOT_PATH . '/storage/backups';
foreach ($argv as $a) { if (str_starts_with($a, '--keep=')) $keep = max(1, (int) substr($a, 7)); if (str_starts_with($a, '--to=')) $dir = rtrim(substr($a, 5), '/'); }
if (!is_dir($dir) && !mkdir($dir, 0700, true)) { fwrite(STDERR, "Cannot create $dir\n"); exit(1); }
@chmod($dir, 0700);
$pdo = Database::pdo();
$stamp = gmdate('Ymd-His'); $name = 'carecore-' . $stamp . '.sql.gz'; $path = $dir . '/' . $name;
$gz = gzopen($path . '.partial', 'wb6');
if (!$gz) { fwrite(STDERR, "Cannot write $path\n"); exit(1); }
$tables = array_column($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM), 0);
sort($tables);
$counts = [];
$out = static function (string $s) use ($gz): void { gzwrite($gz, rtrim($s, "\n") . "\n-- ;;\n"); };   // every statement ends with a marker line so a restore can split them safely
$out("-- Care Core backup, " . gmdate('c') . " UTC\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';");
foreach ($tables as $t) {
    $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
    $out("\nDROP TABLE IF EXISTS `$t`;\n$create;\n");
    $n = 0;
    // Generated columns are computed by the database and cannot be inserted: leave them out and name the columns that are.
    $cols = array_column(array_filter($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC), fn($c) => stripos((string) $c['Extra'], 'GENERATED') === false), 'Field');
    $list = implode(',', array_map(fn($c) => "`$c`", $cols));
    $st = $pdo->query("SELECT $list FROM `$t`", PDO::FETCH_ASSOC);
    $batch = [];
    $flush = function () use (&$batch, $t, $list, $out): void { if ($batch) { $out("INSERT INTO `$t` ($list) VALUES " . implode(",\n", $batch) . ";\n"); $batch = []; } };
    foreach ($st as $row) {
        $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : str_replace(["\r", "\n"], ['\\r', '\\n'], $pdo->quote((string) $v)), $row)) . ')';
        $n++;
        if (count($batch) >= 200) $flush();
    }
    $flush();
    $counts[$t] = $n;
}
// Triggers go last, so that restoring the rows is not blocked by the rules the triggers enforce.
$trig = 0;
foreach ($pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $tr) {
    $row = $pdo->query('SHOW CREATE TRIGGER `' . $tr['Trigger'] . '`')->fetch(PDO::FETCH_ASSOC);
    $sql = $row['SQL Original Statement'] ?? ($row['Create Trigger'] ?? null);
    if ($sql) { $out("\nDROP TRIGGER IF EXISTS `{$tr['Trigger']}`;\n" . preg_replace('/^CREATE DEFINER=`[^`]*`@`[^`]*`\s+/i', 'CREATE ', (string) $sql) . ";\n"); $trig++; }
}
$out("\nSET FOREIGN_KEY_CHECKS = 1;\n");
gzclose($gz);
rename($path . '.partial', $path);
$man = ['file' => $name, 'created_utc' => gmdate('c'), 'sha256' => hash_file('sha256', $path), 'bytes' => filesize($path), 'tables' => $counts, 'triggers' => $trig];

// The files people uploaded
$up = ROOT_PATH . '/storage/uploads';
if (is_dir($up)) {
    $files = 0; $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($up, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile()) $files++;
    if ($files > 0 && class_exists('PharData')) {
        $tar = $dir . '/carecore-files-' . $stamp . '.tar';
        try {
            $ph = new PharData($tar); $ph->buildFromDirectory($up); $ph->compress(Phar::GZ); unset($ph); @unlink($tar);
            $tgz = $tar . '.gz'; @chmod($tgz, 0600);
            $man['files'] = ['file' => basename($tgz), 'count' => $files, 'sha256' => hash_file('sha256', $tgz), 'bytes' => filesize($tgz)];
        } catch (Throwable $e) { fwrite(STDERR, 'Could not archive the uploaded files: ' . $e->getMessage() . "\n"); $man['files'] = ['error' => $e->getMessage()]; }
    } elseif ($files > 0) { fwrite(STDERR, "The files could not be archived (the Phar extension is missing). Copy $up by hand.\n"); $man['files'] = ['error' => 'no Phar extension']; }
}
file_put_contents($path . '.json', json_encode($man, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
@chmod($path, 0600); @chmod($path . '.json', 0600);
echo "Backup written: $path (" . number_format(filesize($path)) . " bytes, " . count($tables) . " tables, " . array_sum($counts) . " rows, $trig triggers)" . (isset($man['files']['count']) ? ', ' . $man['files']['count'] . ' uploaded files' : '') . "\n";
$old = glob($dir . '/carecore-2*.sql.gz') ?: []; sort($old);
foreach (array_slice($old, 0, max(0, count($old) - $keep)) as $f) {
    $m = json_decode((string) @file_get_contents($f . '.json'), true);
    @unlink($f); @unlink($f . '.json'); if (!empty($m['files']['file'])) @unlink($dir . '/' . $m['files']['file']);
    echo "Removed old backup " . basename($f) . "\n";
}
