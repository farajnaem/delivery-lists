<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\CampaignService;
use App\DeliveryService;

$campaigns = CampaignService::all();
if (!$campaigns) {
    echo "No campaigns.\n";
    exit(0);
}

$c = $campaigns[0];
$id = (int) $c['id'];
echo "Campaign: {$c['name']} (id={$id})\n";

$stats = DeliveryService::stockStats($id);
echo "Opening: {$stats['opening_quantity']}, Delivered: {$stats['delivered']}, Balance: {$stats['balance']}\n";

$beneficiaries = CampaignService::beneficiaries($id);
$pending = array_values(array_filter($beneficiaries, fn ($b) => ($b['receipt_status'] ?? '') !== 'مستلم'));
if (!$pending) {
    echo "All delivered or no beneficiaries.\n";
    exit(0);
}

$b = $pending[0];
$code = $b['disbursement_code'] ?? '';
echo "Search by code: {$code}\n";
$found = DeliveryService::search($id, $code);
echo $found ? "Found: {$found['name']}\n" : "Not found\n";

$result = DeliveryService::markDelivered($id, (int) $b['id'], 1, 'test-client-' . $b['id']);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

$stats2 = DeliveryService::stockStats($id);
echo "After: Delivered={$stats2['delivered']}, Balance={$stats2['balance']}\n";
