<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;

try {
    $pdo = Database::getConnection();
    $pdo->query('SELECT 1');
    exit(0);
} catch (Throwable) {
    exit(1);
}
