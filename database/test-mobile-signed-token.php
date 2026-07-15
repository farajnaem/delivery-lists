<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;
use App\MobileAuth;
use App\UserService;

// اختبار توقيع التوكن عبر reflection لأن دوال mint خاصة
$ref = new ReflectionClass(MobileAuth::class);
$mint = $ref->getMethod('mintToken');
$mint->setAccessible(true);
$parse = $ref->getMethod('parseSignedToken');
$parse->setAccessible(true);
$looks = $ref->getMethod('looksLikeToken');
$looks->setAccessible(true);

$token = $mint->invoke(null, 42, time() + 3600);
echo "TOKEN=$token\n";
echo "LOOKS=" . ($looks->invoke(null, $token) ? 'yes' : 'no') . "\n";
$parsed = $parse->invoke(null, $token);
echo "PARSED=" . json_encode($parsed) . "\n";

if (!is_array($parsed) || (int) $parsed['user_id'] !== 42) {
    fwrite(STDERR, "FAIL parse\n");
    exit(1);
}

// محاكاة توكن في الطلب
$_SERVER = [];
$_GET = ['mobile_token' => $token];
$_SERVER['REQUEST_METHOD'] = 'GET';

// بدون مستخدم 42 قد يفشل authenticate — نختبر looks + parse فقط هنا
echo "OK signed token plumbing\n";
