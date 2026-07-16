<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\CampaignService;
use App\DeliveryService;
use App\MobileSyncService;

$campaigns = CampaignService::all();
if (!$campaigns) {
    echo "No campaigns.\n";
    exit(1);
}

$id = (int) $campaigns[0]['id'];
$beneficiaries = CampaignService::beneficiaries($id);
$pending = array_values(array_filter(
    $beneficiaries,
    fn ($b) => ($b['receipt_status'] ?? '') !== DeliveryService::STATUS_DELIVERED
));

if (count($pending) < 2) {
    echo "Need at least 2 pending beneficiaries for sync test.\n";
    exit(1);
}

$b1 = $pending[0];
$b2 = $pending[1];

echo "Campaign={$id}\n";

$search = DeliveryService::search($id, (string) ($b1['disbursement_code'] ?? ''));
echo ($search ? 'OK' : 'FAIL') . " online_search_by_code\n";

$batch = [
    ['beneficiary_id' => (int) $b1['id'], 'client_id' => 'offline-test-' . $b1['id']],
    ['beneficiary_id' => (int) $b2['id'], 'client_id' => 'offline-test-' . $b2['id']],
];
$sync = DeliveryService::syncBatch($id, 1, $batch);
echo ($sync['ok'] ? 'OK' : 'FAIL') . " warehouse_sync_batch synced={$sync['synced']}\n";

$mobile = MobileSyncService::sync($id, 1, null, []);
echo ($mobile['ok'] ? 'OK' : 'FAIL') . " mobile_sync_pull\n";

$snapshot = MobileSyncService::snapshot($id);
$sample = $snapshot['beneficiaries'][0] ?? null;
$hasDisplay = is_array($sample) && array_key_exists('display_code', $sample);
echo ($hasDisplay ? 'OK' : 'FAIL') . " mobile_snapshot_display_code\n";

$dup = DeliveryService::syncBatch($id, 1, [$batch[0]]);
echo (($dup['results'][0]['already'] ?? false) ? 'OK' : 'FAIL') . " duplicate_client_id_idempotent\n";

echo "DONE\n";
