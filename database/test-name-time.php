<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\DistributionService;
use App\MessageTemplates;

$h = DistributionService::splitCountEndHeavy(400, 6);
echo 'hours400: ' . implode(',', $h) . PHP_EOL;
if ($h !== [66, 66, 67, 67, 67, 67]) {
    fwrite(STDERR, "unexpected split\n");
    exit(1);
}

$msg = MessageTemplates::appointment(
    [
        'parcel_name' => 'طرد',
        'parcel_code' => 'SOCI',
        'parcel_code_suffix' => '',
        'warehouse_name' => 'مخزن الشمال',
    ],
    'أحمد',
    '2026-07-20',
    'SOCI00482',
    2,
    '09:00',
    '10:00'
);
echo $msg . PHP_EOL;
if (
    !str_contains($msg, 'من الساعة 09:00 إلى 10:00')
    || !str_contains($msg, 'شباك رقم 2')
    || !str_contains($msg, 'في مخزن الشمال')
) {
    fwrite(STDERR, "message missing time/window/warehouse\n");
    exit(1);
}

echo "OK\n";
