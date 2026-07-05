<?php

declare(strict_types=1);

require_once __DIR__ . '/install.php';

use App\UserService;

if (UserService::count() > 0) {
    echo "يوجد مستخدمون بالفعل.\n";
    exit(0);
}

$name = $argv[1] ?? 'مدير النظام';
$email = $argv[2] ?? 'admin@local.test';
$password = $argv[3] ?? 'Admin1234';

UserService::create($name, $email, $password, 'admin');
echo "تم إنشاء مدير النظام:\n  البريد: {$email}\n  كلمة المرور: {$password}\n";
