<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;

$schemaPath = __DIR__ . '/schema.sql';
if (!is_file($schemaPath)) {
    fwrite(STDERR, "schema.sql not found.\n");
    exit(1);
}

$pdo = Database::getConnection();
$sql = file_get_contents($schemaPath);
$pdo->exec($sql);

if (PHP_SAPI !== 'cli') {
    ob_start();
}
require __DIR__ . '/migrate.php';
if (PHP_SAPI !== 'cli') {
    ob_end_clean();
}

echo "قاعدة البيانات جاهزة.\n";
