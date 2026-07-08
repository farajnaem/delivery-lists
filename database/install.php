<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;

$driver = config('db_driver');
$schemaPath = __DIR__ . ($driver === 'mysql' ? '/schema.mysql.sql' : '/schema.sql');
if (!is_file($schemaPath)) {
    fwrite(STDERR, basename($schemaPath) . " not found.\n");
    exit(1);
}

$pdo = Database::getConnection();
$sql = file_get_contents($schemaPath);

// نفّذ كل جملة على حدة لضمان التوافق مع MySQL وSQLite
foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $statement) {
    if ($statement === '') {
        continue;
    }
    $pdo->exec($statement);
}

if (PHP_SAPI !== 'cli') {
    ob_start();
}
require __DIR__ . '/migrate.php';
if (PHP_SAPI !== 'cli') {
    ob_end_clean();
}

echo "قاعدة البيانات جاهزة.\n";
