<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\CampaignService;
use App\DistributionService;
use App\ExcelImportService;

$pdo = App\Database::getConnection();
$items = [];
for ($i = 1; $i <= 245; $i++) {
    $items[] = [
        'name' => 'مستفيد ' . $i,
        'national_id' => (string) (400000000 + $i),
        'mobile' => '0599' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
        'receipt_status' => 'قيد التسليم',
    ];
}

$id = CampaignService::create([
    'name' => 'test-245',
    'parcel_name' => 'طرد',
    'parcel_code' => 'SOCI-T',
    'delivery_start' => '2026-07-10',
    'delivery_end' => '2026-07-14',
    'warehouse_name' => 'مخزن',
    'warehouse_location' => 'موقع',
    'num_days' => 5,
    'num_windows' => 4,
    'work_start' => '09:00',
    'work_end' => '15:00',
    'per_window_capacity' => 500,
], 1);

ExcelImportService::saveBeneficiaries($id, $items);
$summary = DistributionService::generate($id);

foreach ($summary['days'] as $day) {
    echo "Day {$day['day_index']}: {$day['beneficiaries']} beneficiaries, windows: " . implode(',', $day['window_sizes']) . PHP_EOL;
}

$stmt = $pdo->prepare('SELECT day_index, window_num, COUNT(*) c FROM beneficiaries WHERE campaign_id = ? GROUP BY day_index, window_num ORDER BY day_index, window_num');
$stmt->execute([$id]);
foreach ($stmt->fetchAll() as $r) {
    if ((int) $r['c'] > 0) {
        echo "  Sheet day{$r['day_index']}_window{$r['window_num']}: {$r['c']}" . PHP_EOL;
    }
}

$mobile = $pdo->query("SELECT mobile FROM beneficiaries WHERE campaign_id = {$id} LIMIT 1")->fetchColumn();
echo "Sample mobile: {$mobile}" . PHP_EOL;
