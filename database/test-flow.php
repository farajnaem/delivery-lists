<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\CampaignService;
use App\DistributionService;
use App\ExcelExportService;
use App\ExcelImportService;

$items = ExcelImportService::parse(dirname(__DIR__) . '/storage/sample-beneficiaries.xlsx');
echo 'Imported: ' . count($items) . PHP_EOL;

$id = CampaignService::create([
    'name' => 'اختبار',
    'parcel_name' => 'طرد غذائي',
    'parcel_code' => 'SOCI',
    'parcel_code_suffix' => '',
    'delivery_start' => '2026-07-10',
    'delivery_end' => '2026-07-14',
    'warehouse_name' => 'مخزن الشمال',
    'warehouse_location' => 'رام الله',
    'num_days' => 2,
    'work_start' => '09:00',
    'work_end' => '15:00',
    'per_window_capacity' => 2,
], 1);

ExcelImportService::saveBeneficiaries($id, $items);
$summary = DistributionService::generate($id);
echo 'Generated days: ' . count($summary['days']) . PHP_EOL;
print_r($summary);

$path = ExcelExportService::export($id);
echo 'Export: ' . $path . PHP_EOL;
