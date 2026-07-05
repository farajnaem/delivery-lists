<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\CampaignService;
use App\ExcelExportService;
use App\SmsService;

$campaigns = CampaignService::all();
if (!$campaigns) {
    echo "No campaigns.\n";
    exit(0);
}

$id = (int) $campaigns[0]['id'];
echo "Export deliveries for campaign {$id}...\n";
$path = ExcelExportService::exportDeliveries($id);
echo "Saved: {$path}\n";
echo "SMS pending: " . SmsService::pendingCount($id) . "\n";

$msg = SmsService::buildDeliveryConfirmation($campaigns[0], [
    'name' => 'اختبار',
    'disbursement_code' => 'SOCI00099',
]);
echo "Sample SMS: {$msg}\n";
