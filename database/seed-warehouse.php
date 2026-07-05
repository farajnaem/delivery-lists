<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\UserService;

if (UserService::count() === 0) {
    fwrite(STDERR, "Run setup first.\n");
    exit(1);
}

$email = $argv[1] ?? 'warehouse@local.test';
$password = $argv[2] ?? 'Warehouse1234';

UserService::create('أمين المخزن', $email, $password, 'warehouse_keeper');
echo "Warehouse keeper: {$email} / {$password}\n";
