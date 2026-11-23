<?php
declare(strict_types=1);
/**
 * Prove a backup can be restored: load it into a SCRATCH database, compare every table's row count with the manifest, and check the files archive against its checksum and count.
 *   php scripts/restore-check.php storage/backups/carecore-....sql.gz --into=SCRATCH_DB_NAME
 * Safe by design: it refuses unless the scratch database name contains "restore" or "test", and refuses the live database name. It never touches the live database.
 * The database must already exist and the user must be able to write to it (on shared hosting, create one in hPanel).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\{Config, Database};

$file = $argv[1] ?? ''; $into = '';
foreach ($argv as $a) if (str_starts_with($a, '--into=')) $into = substr($a, 7);
if (!is_file($file) || $into === '') { fwrite(STDERR, "Usage: php scripts/restore-check.php BACKUP.sql.gz --into=SCRATCH_DB\n"); exit(2); }
$live = Config::get('DB_NAME', 'care_core');
if ($into === $live || !preg_match('/restore|test/i', $into)) { fwrite(STDERR, "Refusing: the scratch database name must contain \"restore\" or \"test\" and must not be the live database ($live).\n"); exit(2); }
$man = json_decode((string) @file_get_contents($file . '.json'), true);
if (!$man) { fwrite(STDERR, "No manifest next to the backup ($file.json).\n"); exit(2); }
if (hash_file('sha256', $file) !== $man['sha256']) { fwrite(STDERR, "FAILED: the file does not match its checksum: it is damaged or has been changed.\n"); exit(1); }
echo "Checksum matches.\n";
$bad = 0;
if (!empty($man['files']['file'])) {
    $tgz = dirname($file) . '/' . $man['files']['file'];
    if (!is_file($tgz) || hash_file('sha256', $tgz) !== $man['files']['sha256']) { echo "FAILED: the files archive is missing or does not match its checksum.\n"; $bad++; }
    else { $n = 0; foreach (new RecursiveIteratorIterator(new PharData($tgz)) as $f) $n++; echo $n === (int) $man['files']['count'] ? "Files archive OK ($n files).\n" : "MISMATCH: the archive holds $n files, the backup recorded {$man['files']['count']}.\n"; if ($n !== (int) $man['files']['count']) $bad++; }
}
$pdo = (function () use ($into) { putenv('DB_NAME=' . $into); Database::reset(); return Database::pdo(); })();
$in = gzopen($file, 'rb'); $buf = ''; $stmts = 0;
while (($line = gzgets($in)) !== false) {
    if ($line === "-- ;;\n") { if (trim($buf) !== '') { $pdo->exec($buf); $stmts++; } $buf = ''; continue; }
    $buf .= $line;
}
gzclose($in);
echo "Ran $stmts statements into $into.\n";
foreach ($man['tables'] as $t => $n) {
    $got = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    if ($got !== (int) $n) { $bad++; echo "MISMATCH  $t: backup says $n rows, restored $got\n"; }
}
echo $bad ? "FAILED: $bad problem(s).\n" : "Every table has the number of rows the backup recorded (" . count($man['tables']) . " tables).\n";
exit($bad ? 1 : 0);
