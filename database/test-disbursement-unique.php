<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\CampaignService;
use App\DistributionService;
use App\ExcelImportService;
use App\ParcelCodeHelper;

$failures = 0;
function assert_true(bool $cond, string $label): void
{
    global $failures;
    if (!$cond) {
        echo "FAIL: {$label}\n";
        $failures++;
    } else {
        echo "OK: {$label}\n";
    }
}

$id = CampaignService::create([
    'name' => 'اختبار تفرد الأكواد',
    'parcel_name' => 'طرد',
    'parcel_code' => 'REC',
    'parcel_code_suffix' => '',
    'delivery_start' => '2026-07-10',
    'delivery_end' => '2026-07-14',
    'warehouse_name' => 'مخزن',
    'warehouse_location' => 'رام الله',
    'num_days' => 2,
    'work_start' => '09:00',
    'work_end' => '15:00',
    'per_window_capacity' => 50,
    'num_windows' => 2,
], 1);

$items = ExcelImportService::parse(dirname(__DIR__) . '/storage/sample-beneficiaries.xlsx');
ExcelImportService::saveBeneficiaries($id, $items);
DistributionService::generate($id);

$rows = CampaignService::beneficiariesDetailed($id);
$codes = array_values(array_filter(array_map(
    static fn (array $b): string => (string) ($b['disbursement_code'] ?? ''),
    $rows
)));

assert_true($codes !== [], 'codes generated');
assert_true(count($codes) === count(array_unique($codes)), 'full codes unique');

$display = [];
foreach ($rows as $row) {
    $code = (string) ($row['disbursement_code'] ?? '');
    if ($code === '') {
        continue;
    }
    $pin = ParcelCodeHelper::displayForBeneficiary($code, null, 'REC');
    $display[] = $pin;
}
assert_true(count($display) === count(array_unique($display)), 'display pins unique');

try {
    ParcelCodeHelper::assertUniqueDisbursementCodes(['REC4829101', 'REC4829101'], 'REC');
    assert_true(false, 'duplicate detection throws');
} catch (RuntimeException) {
    assert_true(true, 'duplicate detection throws');
}

echo $failures === 0 ? "ALL PASSED\n" : "FAILURES: {$failures}\n";
exit($failures === 0 ? 0 : 1);
