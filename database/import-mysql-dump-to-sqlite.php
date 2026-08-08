<?php

declare(strict_types=1);

/**
 * استيراد نسخة MySQL (.sql) إلى قاعدة SQLite المحلية.
 *
 * Usage:
 *   php database/import-mysql-dump-to-sqlite.php "C:\path\backup.sql"
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$dumpPath = $argv[1] ?? '';
if ($dumpPath === '' || !is_file($dumpPath)) {
    fwrite(STDERR, "Usage: php database/import-mysql-dump-to-sqlite.php <backup.sql>\n");
    exit(1);
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;

if (config('db_driver') !== 'sqlite') {
    fwrite(STDERR, "هذا السكربت مخصص لـ SQLite المحلي فقط. DB_DRIVER الحالي: " . config('db_driver') . "\n");
    exit(1);
}

$sqlitePath = (string) config('db_path');
$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0775, true);
}

if (is_file($sqlitePath)) {
    $safety = $backupDir . '/pre_import_' . date('Y-m-d_His') . '.sqlite';
    if (!copy($sqlitePath, $safety)) {
        fwrite(STDERR, "فشل نسخ احتياطي قبل الاستيراد.\n");
        exit(1);
    }
    echo "Safety backup: {$safety}\n";
}

// أغلق اتصال PDO الحالي إن وُجد ثم أعد إنشاء الملف
Database::resetConnection();
if (is_file($sqlitePath)) {
    unlink($sqlitePath);
}

$schema = file_get_contents(__DIR__ . '/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "schema.sql not found.\n");
    exit(1);
}

$pdo = Database::getConnection();
$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->exec('PRAGMA journal_mode = OFF');
$pdo->exec('PRAGMA synchronous = OFF');

foreach (array_filter(array_map('trim', explode(";\n", $schema))) as $statement) {
    if ($statement === '') {
        continue;
    }
    $pdo->exec($statement);
}

$fh = fopen($dumpPath, 'rb');
if ($fh === false) {
    fwrite(STDERR, "Cannot open dump.\n");
    exit(1);
}

$counts = [
    'users' => 0,
    'campaigns' => 0,
    'beneficiaries' => 0,
    'delivery_events' => 0,
    'delivery_batches' => 0,
    'sms_outbox' => 0,
];
$errors = 0;
$imported = 0;

$pdo->beginTransaction();

while (($line = fgets($fh)) !== false) {
    $trim = ltrim($line);
    if (!str_starts_with($trim, 'INSERT INTO')) {
        continue;
    }

    $sql = rtrim($line);
    if (!str_ends_with($sql, ';')) {
        // تجميع الأسطر النادرة متعددة الأسطر
        while (!str_ends_with($sql, ';') && ($more = fgets($fh)) !== false) {
            $sql .= $more;
            $sql = rtrim($sql);
        }
    }

    $sql = str_replace('`', '', $sql);
    // تحويل تهريب MySQL \' إلى تهريب SQLite ''
    $sql = str_replace("\\'", "''", $sql);

    try {
        $pdo->exec($sql);
        $imported++;
        foreach ($counts as $table => $_) {
            if (str_contains($sql, "INSERT INTO {$table} ") || str_contains($sql, "INSERT INTO {$table}(")) {
                $counts[$table]++;
                break;
            }
        }
        if ($imported % 2000 === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
            echo "Imported {$imported} rows...\n";
        }
    } catch (Throwable $e) {
        $errors++;
        if ($errors <= 10) {
            echo 'ERR: ' . $e->getMessage() . "\n";
        }
    }
}

fclose($fh);

if ($pdo->inTransaction()) {
    $pdo->commit();
}

$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('PRAGMA synchronous = NORMAL');

echo "\nImport finished. Statements OK={$imported} errors={$errors}\n";
foreach ($counts as $table => $n) {
    $actual = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    echo "{$table}: inserted_lines={$n} table_count={$actual}\n";
}

// migrations الإضافية
ob_start();
require __DIR__ . '/migrate.php';
ob_end_clean();
echo "Migrations applied.\n";
echo "SQLite ready at: {$sqlitePath}\n";
