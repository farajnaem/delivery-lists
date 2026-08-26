<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\CampaignMergeService;
use App\CampaignService;
use App\Database;
use App\DeliveryService;

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

$pdo = Database::getConnection();
$userId = (int) $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($userId < 1) {
    fwrite(STDERR, "No users — skip merge test.\n");
    exit(0);
}

$ids = [];
$cleanup = static function () use (&$ids): void {
    foreach ($ids as $id) {
        if ($id > 0) {
            try {
                CampaignService::delete($id);
            } catch (Throwable) {
            }
        }
    }
};

try {
    $sourceId = CampaignService::create([
        'name' => '__merge_src__',
        'pipeline_name' => 'merge-src',
        'parcel_name' => 'فرشات',
        'parcel_code' => 'SOCI',
        'parcel_code_suffix' => '',
        'delivery_start' => '2026-08-01',
        'delivery_end' => '2026-08-10',
        'warehouse_name' => 'مخزن اختبار',
        'warehouse_location' => 'غزة',
        'num_days' => 1,
        'work_start' => '09:00',
        'work_end' => '15:00',
        'per_window_capacity' => 400,
        'num_windows' => 4,
        'opening_quantity' => 100,
        'message_extra' => '',
    ], $userId);
    $targetId = CampaignService::create([
        'name' => '__merge_dst__',
        'pipeline_name' => 'merge-dst',
        'parcel_name' => 'فرشات',
        'parcel_code' => 'SOCI',
        'parcel_code_suffix' => '',
        'delivery_start' => '2026-08-01',
        'delivery_end' => '2026-08-10',
        'warehouse_name' => 'مخزن اختبار',
        'warehouse_location' => 'غزة',
        'num_days' => 1,
        'work_start' => '09:00',
        'work_end' => '15:00',
        'per_window_capacity' => 400,
        'num_windows' => 4,
        'opening_quantity' => 80,
        'message_extra' => '',
    ], $userId);
    $ids[] = $sourceId;
    $ids[] = $targetId;

    $ins = $pdo->prepare('
        INSERT INTO beneficiaries (
            campaign_id, name, national_id, mobile, receipt_status, shelter_name,
            disbursement_code, day_index, sort_order, delivery_date, window_num,
            time_from, time_to, created_at, updated_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ');
    $now = db_now();
    $people = [
        // source unique, delivered
        [$sourceId, 'أحمد المصدر', '111111111', '0590000001', 'مستلم', 'أ', 'SOCI4829103', 1, 1, '2026-08-02', 1, '09:00', '10:00'],
        // source unique, pending assigned
        [$sourceId, 'خالد المصدر', '222222222', '0590000002', 'قيد التسليم', 'أ', 'SOCI4829104', 1, 2, '2026-08-02', 1, '09:00', '10:00'],
        // overlap: delivered in source, pending in target
        [$sourceId, 'سامي مشترك', '333333333', '0590000003', 'مستلم', 'ب', 'SOCI4829105', 1, 3, '2026-08-02', 2, '10:00', '11:00'],
        // overlap: pending both
        [$sourceId, 'ماجد مشترك', '444444444', '0590000004', 'قيد التسليم', '', 'SOCI4829106', 1, 4, '2026-08-02', 2, '10:00', '11:00'],
        // target unique delivered
        [$targetId, 'يوسف الوجهة', '555555555', '0590000005', 'مستلم', 'ج', 'SOCI5829103', 1, 1, '2026-08-03', 1, '09:00', '10:00'],
        // target unique pending
        [$targetId, 'فادي الوجهة', '666666666', '0590000006', 'قيد التسليم', 'ج', 'SOCI5829104', 1, 2, '2026-08-03', 1, '09:00', '10:00'],
        // overlap pending in target (same 333)
        [$targetId, 'سامي مشترك', '333333333', '0590000003', 'قيد التسليم', 'ب', 'SOCI5829105', 1, 3, '2026-08-03', 2, '10:00', '11:00'],
        // overlap pending in target (same 444)
        [$targetId, 'ماجد مشترك', '444444444', '0590000004', 'قيد التسليم', '', 'SOCI5829106', 1, 4, '2026-08-03', 2, '10:00', '11:00'],
    ];
    foreach ($people as $p) {
        $ins->execute([...$p, $now, $now]);
        $benId = (int) $pdo->lastInsertId();
        if ($p[4] === 'مستلم') {
            $pdo->prepare('
                UPDATE beneficiaries SET delivered_at = ?, delivered_by = ?, delivery_type = ?, actual_delivery_date = ?
                WHERE id = ?
            ')->execute([$now, $userId, 'on_time', $p[9], $benId]);
            try {
                $pdo->prepare('
                    INSERT INTO delivery_events (beneficiary_id, campaign_id, action, delivery_type, delivered_at, delivered_by, client_id)
                    VALUES (?,?,?,?,?,?,?)
                ')->execute([$benId, $p[0], 'delivered', 'on_time', $now, $userId, 'merge-test-' . $benId]);
            } catch (Throwable) {
            }
        }
    }

    CampaignService::markGenerated($sourceId);
    CampaignService::markGenerated($targetId);

    $preview = CampaignMergeService::preview($sourceId, $targetId);
    assert_true(!empty($preview['ok']), 'preview ok');
    assert_true((int) ($preview['counts']['move'] ?? 0) === 2, 'preview move=2');
    assert_true((int) ($preview['counts']['apply_delivery'] ?? 0) === 1, 'preview apply_delivery=1');
    assert_true((int) ($preview['projected']['total'] ?? 0) === 6, 'projected total=6');
    assert_true((int) ($preview['projected']['delivered'] ?? 0) === 3, 'projected delivered=3');
    assert_true((int) ($preview['projected']['opening'] ?? 0) === 180, 'projected opening=180');

    $result = CampaignMergeService::merge($sourceId, $targetId, $userId);
    assert_true(!empty($result['ok']), 'merge ok: ' . (string) ($result['error'] ?? ''));
    $backupId = (int) ($result['backup_campaign_id'] ?? 0);
    $ids[] = $backupId;

    $srcStats = CampaignService::stats($sourceId);
    $dstStats = CampaignService::stats($targetId);
    $bakStats = $backupId > 0 ? CampaignService::stats($backupId) : ['total' => 0, 'delivered' => 0];
    $dstCamp = CampaignService::find($targetId);
    $srcCamp = CampaignService::find($sourceId);

    assert_true((int) ($srcStats['total'] ?? -1) === 0, 'source emptied');
    assert_true((int) ($dstStats['total'] ?? 0) === 6, 'target total=6');
    assert_true((int) ($dstStats['delivered'] ?? 0) === 3, 'target delivered=3');
    assert_true((int) ($dstCamp['opening_quantity'] ?? 0) === 180, 'opening summed');
    assert_true((int) ($bakStats['total'] ?? 0) === 4, 'backup kept 4 source people');
    assert_true((int) ($bakStats['delivered'] ?? 0) === 2, 'backup kept 2 delivered');
    assert_true(str_contains((string) ($srcCamp['name'] ?? ''), 'فارغة بعد الدمج'), 'source renamed empty');

    $sami = $pdo->prepare("SELECT receipt_status, day_index FROM beneficiaries WHERE campaign_id = ? AND national_id = '333333333' LIMIT 1");
    $sami->execute([$targetId]);
    $samiRow = $sami->fetch();
    assert_true(is_array($samiRow) && ($samiRow['receipt_status'] ?? '') === 'مستلم', 'overlap got source delivery');

    $ahmad = $pdo->prepare("SELECT day_index FROM beneficiaries WHERE campaign_id = ? AND national_id = '111111111' LIMIT 1");
    $ahmad->execute([$targetId]);
    $ahmadDay = (int) $ahmad->fetchColumn();
    assert_true($ahmadDay === 2, 'source day offset to 2, got ' . $ahmadDay);

    $dupNid = (int) $pdo->query("
        SELECT COUNT(*) FROM beneficiaries WHERE campaign_id = {$targetId} AND national_id = '333333333'
    ")->fetchColumn();
    assert_true($dupNid === 1, 'no duplicate national id in target');

    $stock = DeliveryService::stockStats($targetId);
    assert_true((int) $stock['delivered'] === 3, 'stock delivered=3');
    assert_true((int) $stock['balance'] === 177, 'stock balance=177');
} catch (Throwable $e) {
    echo 'FAIL: exception ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    $cleanup();
}

if ($failures > 0) {
    echo "FAILED: {$failures}\n";
    exit(1);
}
echo "ALL OK\n";
