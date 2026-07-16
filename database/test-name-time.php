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

$campaign = [
    'parcel_name' => 'طرد',
    'parcel_code' => 'SOCI',
    'parcel_code_suffix' => '',
    'warehouse_name' => 'مخزن الشمال',
];
$msg = MessageTemplates::appointment(
    $campaign,
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

$confirm = MessageTemplates::deliveryConfirmation($campaign, [
    'name' => 'أحمد',
    'disbursement_code' => 'SOCI00482',
]);
echo $confirm . PHP_EOL;
if (!str_contains($confirm, 'في مخزن الشمال')) {
    fwrite(STDERR, "confirmation missing warehouse\n");
    exit(1);
}

$fromRow = MessageTemplates::appointmentFromBeneficiary($campaign, [
    'name' => 'سارة',
    'delivery_date' => '2026-07-21',
    'disbursement_code' => 'SOCI00111',
    'window_num' => 3,
    'time_from' => '10:00',
    'time_to' => '11:00',
]);
if (!str_contains($fromRow, 'في مخزن الشمال') || !str_contains($fromRow, 'شباك رقم 3')) {
    fwrite(STDERR, "appointmentFromBeneficiary missing warehouse/window\n");
    exit(1);
}

try {
    MessageTemplates::appointment(
        ['parcel_name' => 'طرد', 'parcel_code' => 'SOCI', 'warehouse_name' => ''],
        'أحمد',
        '2026-07-20',
        'SOCI00482',
        1
    );
    fwrite(STDERR, "expected empty warehouse to throw\n");
    exit(1);
} catch (\RuntimeException $e) {
    // ok
}

echo "OK\n";
