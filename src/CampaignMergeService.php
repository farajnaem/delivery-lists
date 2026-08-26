<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

/**
 * دمج لمرة واحدة: ينقل مستفيدي عملية مصدر إلى عملية وجهة مع التسليمات والكشوف.
 * يُنشئ نسخة احتياط من المصدر قبل النقل.
 */
final class CampaignMergeService
{
    public const ACTION_MOVE = 'move';
    public const ACTION_APPLY_DELIVERY = 'apply_delivery';
    public const ACTION_APPLY_ASSIGNMENT = 'apply_assignment';
    public const ACTION_SKIP_PENDING = 'skip_pending';
    public const ACTION_SKIP_BOTH_DELIVERED = 'skip_both_delivered';

    /**
     * @return array{
     *   ok:bool,
     *   error?:string,
     *   source:array<string,mixed>,
     *   target:array<string,mixed>,
     *   parcel_mismatch:bool,
     *   source_stock:array<string,int>,
     *   target_stock:array<string,int>,
     *   projected:array<string,int>,
     *   counts:array<string,int>,
     *   code_conflicts:list<array{code:string,source_name:string,target_name:string}>,
     *   duplicate_samples:list<array{national_id:string,source_name:string,target_name:string,action:string}>
     * }
     */
    public static function preview(int $sourceId, int $targetId): array
    {
        return self::plan($sourceId, $targetId);
    }

    /**
     * @return array{
     *   ok:bool,
     *   error?:string,
     *   backup_campaign_id?:int,
     *   backup_name?:string,
     *   db_backup?:string,
     *   target_id?:int,
     *   source_id?:int,
     *   moved?:int,
     *   duplicates?:int,
     *   codes_regenerated?:int
     * }
     */
    public static function merge(int $sourceId, int $targetId, int $userId): array
    {
        extend_runtime(1800);
        $plan = self::plan($sourceId, $targetId);
        if (empty($plan['ok'])) {
            return ['ok' => false, 'error' => (string) ($plan['error'] ?? 'تعذّر تجهيز الدمج.')];
        }
        if ($plan['code_conflicts'] !== []) {
            return ['ok' => false, 'error' => 'يوجد أكواد صرف متعارضة لمستلمين في العمليتين. لا يمكن الدمج تلقائياً.'];
        }

        $pdo = Database::getConnection();
        $dbBackup = '';
        try {
            $created = DatabaseBackupService::create();
            $dbBackup = (string) ($created['filename'] ?? '');
        } catch (Throwable) {
            $dbBackup = '';
        }

        $pdo->beginTransaction();
        try {
            $backupId = self::cloneCampaign($sourceId, $userId);
            $backup = CampaignService::find($backupId);
            $result = self::executePlan($plan, $pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'فشل الدمج: ' . $e->getMessage()];
        }

        return [
            'ok' => true,
            'backup_campaign_id' => $backupId,
            'backup_name' => (string) ($backup['name'] ?? ''),
            'db_backup' => $dbBackup,
            'target_id' => $targetId,
            'source_id' => $sourceId,
            'moved' => $result['moved'],
            'duplicates' => $result['duplicates'],
            'codes_regenerated' => $result['codes_regenerated'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function plan(int $sourceId, int $targetId): array
    {
        if ($sourceId < 1 || $targetId < 1 || $sourceId === $targetId) {
            return ['ok' => false, 'error' => 'اختر عمليتين مختلفتين للدمج.'];
        }

        $source = CampaignService::find($sourceId);
        $target = CampaignService::find($targetId);
        if (!$source || !$target) {
            return ['ok' => false, 'error' => 'إحدى العمليتين غير موجودة.'];
        }

        $sourcePeople = self::beneficiariesOf($sourceId);
        $targetPeople = self::beneficiariesOf($targetId);
        if ($sourcePeople === []) {
            return ['ok' => false, 'error' => 'العملية المصدر فارغة — لا يوجد ما يُنقل.'];
        }

        $targetByNid = [];
        foreach ($targetPeople as $row) {
            $nid = self::nidKey($row);
            if ($nid === '') {
                continue;
            }
            $targetByNid[$nid] = $row;
        }

        $targetCodes = self::codeMap($targetPeople);
        $counts = [
            'move' => 0,
            'apply_delivery' => 0,
            'apply_assignment' => 0,
            'skip_pending' => 0,
            'skip_both_delivered' => 0,
        ];
        $codeConflicts = [];
        $duplicateSamples = [];
        $decisions = [];

        foreach ($sourcePeople as $src) {
            $nid = self::nidKey($src);
            $tgt = $nid !== '' ? ($targetByNid[$nid] ?? null) : null;
            if ($tgt === null) {
                $code = self::codeKey($src);
                if ($code !== '' && self::isDelivered($src) && isset($targetCodes[$code])) {
                    $other = $targetCodes[$code];
                    if (self::isDelivered($other) && (int) $other['id'] !== (int) $src['id']) {
                        $codeConflicts[] = [
                            'code' => $code,
                            'source_name' => (string) ($src['name'] ?? ''),
                            'target_name' => (string) ($other['name'] ?? ''),
                        ];
                    }
                }
                $decisions[] = ['action' => self::ACTION_MOVE, 'source' => $src, 'target' => null];
                $counts['move']++;
                continue;
            }

            $srcDel = self::isDelivered($src);
            $tgtDel = self::isDelivered($tgt);
            if ($srcDel && !$tgtDel) {
                $action = self::ACTION_APPLY_DELIVERY;
            } elseif ($tgtDel && !$srcDel) {
                $action = self::ACTION_SKIP_PENDING;
            } elseif ($srcDel && $tgtDel) {
                $action = self::ACTION_SKIP_BOTH_DELIVERED;
            } elseif (self::isAssigned($src) && !self::isAssigned($tgt)) {
                $action = self::ACTION_APPLY_ASSIGNMENT;
            } else {
                $action = self::ACTION_SKIP_PENDING;
            }

            $decisions[] = ['action' => $action, 'source' => $src, 'target' => $tgt];
            $counts[$action] = ($counts[$action] ?? 0) + 1;
            if (count($duplicateSamples) < 12) {
                $duplicateSamples[] = [
                    'national_id' => $nid,
                    'source_name' => (string) ($src['name'] ?? ''),
                    'target_name' => (string) ($tgt['name'] ?? ''),
                    'action' => $action,
                ];
            }
        }

        $sourceStock = self::stockSnapshot($sourceId, $source);
        $targetStock = self::stockSnapshot($targetId, $target);
        $dupDeliveredKept = $counts['apply_delivery'] + $counts['skip_both_delivered'];
        $projectedDelivered = $targetStock['delivered']
            + $counts['apply_delivery']
            + self::countMovedDelivered($decisions);
        $projectedTotal = $targetStock['total'] + $counts['move'];
        $projectedOpening = $sourceStock['opening'] + $targetStock['opening'];

        $parcelMismatch = strtoupper(trim((string) ($source['parcel_code'] ?? '')))
            !== strtoupper(trim((string) ($target['parcel_code'] ?? '')))
            || trim((string) ($source['parcel_name'] ?? '')) !== trim((string) ($target['parcel_name'] ?? ''));

        return [
            'ok' => true,
            'source' => $source,
            'target' => $target,
            'parcel_mismatch' => $parcelMismatch,
            'source_stock' => $sourceStock,
            'target_stock' => $targetStock,
            'projected' => [
                'total' => $projectedTotal,
                'delivered' => $projectedDelivered,
                'opening' => $projectedOpening,
                'balance' => max(0, $projectedOpening - $projectedDelivered),
                'duplicates' => $sourceStock['total'] - $counts['move'],
            ],
            'counts' => [
                'move' => $counts['move'],
                'apply_delivery' => $counts['apply_delivery'],
                'apply_assignment' => $counts['apply_assignment'],
                'skip_pending' => $counts['skip_pending'],
                'skip_both_delivered' => $counts['skip_both_delivered'],
                'duplicates' => $sourceStock['total'] - $counts['move'],
            ],
            'code_conflicts' => $codeConflicts,
            'duplicate_samples' => $duplicateSamples,
            'decisions' => $decisions,
            'dup_delivered_kept' => $dupDeliveredKept,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{moved:int,duplicates:int,codes_regenerated:int}
     */
    private static function executePlan(array $plan, PDO $pdo): array
    {
        $source = $plan['source'];
        $target = $plan['target'];
        $sourceId = (int) $source['id'];
        $targetId = (int) $target['id'];
        $dayOffset = CampaignService::maxDayIndex($targetId);
        $sortOffset = CampaignService::maxSortOrder($targetId);
        $prefix = (string) ($target['parcel_code'] ?? ParcelCodeHelper::DEFAULT_PREFIX);
        $suffix = (string) ($target['parcel_code_suffix'] ?? '');
        $usedPins = self::usedPins($targetId, $prefix, $suffix);
        $codesRegenerated = 0;
        $moved = 0;
        $duplicates = 0;

        foreach ($plan['decisions'] as $decision) {
            $action = (string) $decision['action'];
            $src = $decision['source'];
            $tgt = $decision['target'];
            $srcId = (int) $src['id'];

            if ($action === self::ACTION_MOVE) {
                $code = self::ensureUniqueCode($pdo, $src, $targetId, $prefix, $suffix, $usedPins, $codesRegenerated);
                $day = (int) ($src['day_index'] ?? 0);
                $sort = $src['sort_order'] ?? null;
                $newDay = $day > 0 ? $day + $dayOffset : $day;
                $newSort = $sort !== null && $sort !== '' ? ((int) $sort + $sortOffset) : $sort;
                $pdo->prepare('
                    UPDATE beneficiaries SET
                        campaign_id = ?,
                        disbursement_code = ?,
                        day_index = ?,
                        sort_order = ?,
                        updated_at = ?
                    WHERE id = ? AND campaign_id = ?
                ')->execute([
                    $targetId,
                    $code,
                    $newDay > 0 ? $newDay : $src['day_index'],
                    $newSort,
                    db_now(),
                    $srcId,
                    $sourceId,
                ]);
                self::repointRelated($pdo, $srcId, $srcId, $targetId);
                $moved++;
                continue;
            }

            $tgtId = (int) $tgt['id'];
            if ($action === self::ACTION_APPLY_DELIVERY) {
                self::copyDeliveryOnto($pdo, $src, $tgtId, $targetId, $prefix, $suffix, $usedPins, $codesRegenerated, $dayOffset, $sortOffset);
                self::repointRelated($pdo, $srcId, $tgtId, $targetId);
                self::deleteBeneficiaryRow($pdo, $srcId, $sourceId);
                $duplicates++;
                continue;
            }

            if ($action === self::ACTION_APPLY_ASSIGNMENT) {
                self::copyAssignmentOnto($pdo, $src, $tgtId, $targetId, $prefix, $suffix, $usedPins, $codesRegenerated, $dayOffset, $sortOffset);
                self::repointRelated($pdo, $srcId, $tgtId, $targetId);
                self::deleteBeneficiaryRow($pdo, $srcId, $sourceId);
                $duplicates++;
                continue;
            }

            self::repointRelated($pdo, $srcId, $tgtId, $targetId);
            self::deleteBeneficiaryRow($pdo, $srcId, $sourceId);
            $duplicates++;
        }

        self::moveLeftoverRelated($pdo, $sourceId, $targetId);

        $opening = (int) ($source['opening_quantity'] ?? 0) + (int) ($target['opening_quantity'] ?? 0);
        $maxDay = CampaignService::maxDayIndex($targetId);
        $endDates = array_filter([
            (string) ($source['delivery_end'] ?? ''),
            (string) ($target['delivery_end'] ?? ''),
        ]);
        rsort($endDates);
        $deliveryEnd = $endDates[0] ?? (string) ($target['delivery_end'] ?? $target['delivery_start'] ?? '');

        $pdo->prepare('UPDATE campaigns SET opening_quantity = ?, num_days = ?, delivery_end = ? WHERE id = ?')
            ->execute([$opening, max(1, $maxDay, (int) ($target['num_days'] ?? 1)), $deliveryEnd, $targetId]);

        if (($source['status'] ?? '') === 'generated' || ($target['status'] ?? '') === 'generated') {
            CampaignService::markGenerated($targetId);
        }

        $left = (int) $pdo->query('SELECT COUNT(*) FROM beneficiaries WHERE campaign_id = ' . $sourceId)->fetchColumn();
        if ($left > 0) {
            $pdo->prepare('DELETE FROM beneficiaries WHERE campaign_id = ?')->execute([$sourceId]);
        }

        $emptyName = self::truncateName((string) $source['name'] . ' — فارغة بعد الدمج');
        $pdo->prepare("
            UPDATE campaigns SET
                name = ?,
                opening_quantity = 0,
                status = 'draft',
                generated_at = NULL,
                delivery_enabled = 0,
                delivery_opens_at = NULL,
                delivery_closed_at = NULL
            WHERE id = ?
        ")->execute([$emptyName, $sourceId]);

        return [
            'moved' => $moved,
            'duplicates' => $duplicates,
            'codes_regenerated' => $codesRegenerated,
        ];
    }

    private static function cloneCampaign(int $sourceId, int $userId): int
    {
        $pdo = Database::getConnection();
        $source = CampaignService::find($sourceId);
        if (!$source) {
            throw new RuntimeException('العملية المصدر غير موجودة.');
        }

        $row = $source;
        unset($row['id'], $row['creator_name'], $row['beneficiary_count']);
        $row['name'] = self::truncateName('نسخة احتياط — ' . (string) $source['name'] . ' — ' . date('Y-m-d H:i'));
        $row['created_by'] = $userId;
        $row['delivery_enabled'] = 0;
        $row['delivery_opens_at'] = null;
        $row['delivery_closed_at'] = db_now();
        if (array_key_exists('created_at', $row)) {
            $row['created_at'] = db_now();
        }
        $backupId = self::insertRow($pdo, 'campaigns', $row);

        $batchMap = [];
        if (self::tableExists($pdo, 'delivery_batches')) {
            $batches = $pdo->prepare('SELECT * FROM delivery_batches WHERE campaign_id = ?');
            $batches->execute([$sourceId]);
            foreach ($batches->fetchAll() as $batch) {
                $oldId = (int) $batch['id'];
                $copy = $batch;
                unset($copy['id']);
                $copy['campaign_id'] = $backupId;
                $batchMap[$oldId] = self::insertRow($pdo, 'delivery_batches', $copy);
            }
        }

        $people = self::beneficiariesOf($sourceId);
        $benMap = [];
        foreach ($people as $person) {
            $oldId = (int) $person['id'];
            $copy = $person;
            unset($copy['id']);
            $copy['campaign_id'] = $backupId;
            if (isset($copy['delivery_batch_id']) && $copy['delivery_batch_id'] !== null && $copy['delivery_batch_id'] !== '') {
                $oldBatch = (int) $copy['delivery_batch_id'];
                $copy['delivery_batch_id'] = $batchMap[$oldBatch] ?? null;
            }
            $benMap[$oldId] = self::insertRow($pdo, 'beneficiaries', $copy);
        }

        if (self::tableExists($pdo, 'delivery_events')) {
            $events = $pdo->prepare('SELECT * FROM delivery_events WHERE campaign_id = ?');
            $events->execute([$sourceId]);
            foreach ($events->fetchAll() as $event) {
                $oldBen = (int) ($event['beneficiary_id'] ?? 0);
                if (!isset($benMap[$oldBen])) {
                    continue;
                }
                $copy = $event;
                unset($copy['id']);
                $copy['campaign_id'] = $backupId;
                $copy['beneficiary_id'] = $benMap[$oldBen];
                $copy['client_id'] = null;
                self::insertRow($pdo, 'delivery_events', $copy);
            }
        }

        if (self::tableExists($pdo, 'sms_outbox')) {
            $sms = $pdo->prepare('SELECT * FROM sms_outbox WHERE campaign_id = ?');
            $sms->execute([$sourceId]);
            foreach ($sms->fetchAll() as $rowSms) {
                $oldBen = (int) ($rowSms['beneficiary_id'] ?? 0);
                if (!isset($benMap[$oldBen])) {
                    continue;
                }
                $copy = $rowSms;
                unset($copy['id']);
                $copy['campaign_id'] = $backupId;
                $copy['beneficiary_id'] = $benMap[$oldBen];
                self::insertRow($pdo, 'sms_outbox', $copy);
            }
        }

        return $backupId;
    }

    /**
     * @param array<string, mixed> $src
     * @param array<int, true> $usedPins
     */
    private static function copyDeliveryOnto(
        PDO $pdo,
        array $src,
        int $targetBenId,
        int $targetCampaignId,
        string $prefix,
        string $suffix,
        array &$usedPins,
        int &$codesRegenerated,
        int $dayOffset,
        int $sortOffset,
    ): void {
        $tgtStmt = $pdo->prepare('SELECT * FROM beneficiaries WHERE id = ? LIMIT 1');
        $tgtStmt->execute([$targetBenId]);
        $tgt = $tgtStmt->fetch() ?: [];

        $code = trim((string) ($tgt['disbursement_code'] ?? ''));
        $day = (int) ($tgt['day_index'] ?? 0);
        $sort = $tgt['sort_order'] ?? null;
        $deliveryDate = $tgt['delivery_date'] ?? null;
        $windowNum = $tgt['window_num'] ?? null;
        $timeFrom = $tgt['time_from'] ?? null;
        $timeTo = $tgt['time_to'] ?? null;
        $message = $tgt['message_text'] ?? null;

        if (!self::isAssigned($tgt) && self::isAssigned($src)) {
            $code = self::ensureUniqueCode($pdo, $src, $targetCampaignId, $prefix, $suffix, $usedPins, $codesRegenerated, $targetBenId);
            $srcDay = (int) ($src['day_index'] ?? 0);
            $day = $srcDay > 0 ? $srcDay + $dayOffset : $srcDay;
            $srcSort = $src['sort_order'] ?? null;
            $sort = $srcSort !== null && $srcSort !== '' ? ((int) $srcSort + $sortOffset) : $srcSort;
            $deliveryDate = $src['delivery_date'] ?? $deliveryDate;
            $windowNum = $src['window_num'] ?? $windowNum;
            $timeFrom = $src['time_from'] ?? $timeFrom;
            $timeTo = $src['time_to'] ?? $timeTo;
            $message = $src['message_text'] ?? $message;
        }

        $pdo->prepare('
            UPDATE beneficiaries SET
                receipt_status = ?,
                delivered_at = ?,
                delivered_by = ?,
                delivery_type = ?,
                actual_delivery_date = ?,
                received_by_mode = ?,
                received_by_name = ?,
                delivery_batch_id = COALESCE(delivery_batch_id, ?),
                disbursement_code = ?,
                day_index = ?,
                sort_order = ?,
                delivery_date = ?,
                window_num = ?,
                time_from = ?,
                time_to = ?,
                message_text = ?,
                updated_at = ?
            WHERE id = ?
        ')->execute([
            DeliveryService::STATUS_DELIVERED,
            $src['delivered_at'] ?? db_now(),
            $src['delivered_by'] ?? null,
            $src['delivery_type'] ?? null,
            $src['actual_delivery_date'] ?? null,
            $src['received_by_mode'] ?? null,
            $src['received_by_name'] ?? null,
            $src['delivery_batch_id'] ?? null,
            $code !== '' ? $code : ($tgt['disbursement_code'] ?? null),
            $day > 0 ? $day : ($tgt['day_index'] ?? null),
            $sort,
            $deliveryDate,
            $windowNum,
            $timeFrom,
            $timeTo,
            $message,
            db_now(),
            $targetBenId,
        ]);
    }

    /**
     * @param array<string, mixed> $src
     * @param array<int, true> $usedPins
     */
    private static function copyAssignmentOnto(
        PDO $pdo,
        array $src,
        int $targetBenId,
        int $targetCampaignId,
        string $prefix,
        string $suffix,
        array &$usedPins,
        int &$codesRegenerated,
        int $dayOffset,
        int $sortOffset,
    ): void {
        $code = self::ensureUniqueCode($pdo, $src, $targetCampaignId, $prefix, $suffix, $usedPins, $codesRegenerated, $targetBenId);
        $srcDay = (int) ($src['day_index'] ?? 0);
        $srcSort = $src['sort_order'] ?? null;
        $pdo->prepare('
            UPDATE beneficiaries SET
                disbursement_code = ?,
                day_index = ?,
                sort_order = ?,
                delivery_date = ?,
                window_num = ?,
                time_from = ?,
                time_to = ?,
                message_text = ?,
                updated_at = ?
            WHERE id = ?
        ')->execute([
            $code,
            $srcDay > 0 ? $srcDay + $dayOffset : $src['day_index'],
            $srcSort !== null && $srcSort !== '' ? ((int) $srcSort + $sortOffset) : $srcSort,
            $src['delivery_date'] ?? null,
            $src['window_num'] ?? null,
            $src['time_from'] ?? null,
            $src['time_to'] ?? null,
            $src['message_text'] ?? null,
            db_now(),
            $targetBenId,
        ]);
    }

    /**
     * @param array<string, mixed> $src
     * @param array<int, true> $usedPins
     */
    private static function ensureUniqueCode(
        PDO $pdo,
        array $src,
        int $targetCampaignId,
        string $prefix,
        string $suffix,
        array &$usedPins,
        int &$codesRegenerated,
        ?int $ignoreBenId = null,
    ): ?string {
        $code = trim((string) ($src['disbursement_code'] ?? ''));
        if ($code === '') {
            return $src['disbursement_code'] ?? null;
        }

        $owner = self::codeOwner($pdo, $targetCampaignId, $code, $ignoreBenId);
        if ($owner === null) {
            self::rememberPin($usedPins, $code, $prefix, $suffix);
            return $code;
        }

        $ownerDelivered = self::isDelivered($owner);
        $srcDelivered = self::isDelivered($src);

        if ($srcDelivered && !$ownerDelivered) {
            $newCode = self::newCode($prefix, $suffix, $usedPins);
            $pdo->prepare('UPDATE beneficiaries SET disbursement_code = ?, updated_at = ? WHERE id = ?')
                ->execute([$newCode, db_now(), (int) $owner['id']]);
            $codesRegenerated++;
            self::rememberPin($usedPins, $code, $prefix, $suffix);
            return $code;
        }

        if ($srcDelivered && $ownerDelivered) {
            throw new RuntimeException(
                'كود صرف مكرر لمستلمين: ' . $code . ' (' . ($src['name'] ?? '') . ' / ' . ($owner['name'] ?? '') . ')'
            );
        }

        $newCode = self::newCode($prefix, $suffix, $usedPins);
        $codesRegenerated++;
        return $newCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function codeOwner(PDO $pdo, int $campaignId, string $code, ?int $ignoreBenId): ?array
    {
        $sql = 'SELECT * FROM beneficiaries WHERE campaign_id = ? AND disbursement_code = ?';
        $params = [$campaignId, $code];
        if ($ignoreBenId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $ignoreBenId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @param array<int, true> $usedPins
     */
    private static function newCode(string $prefix, string $suffix, array &$usedPins): string
    {
        $pin = ParcelCodeHelper::generateRandomPin($usedPins);
        return ParcelCodeHelper::buildDisbursementCode($prefix, $suffix, $pin);
    }

    /**
     * @param array<int, true> $usedPins
     */
    private static function rememberPin(array &$usedPins, string $code, string $prefix, string $suffix): void
    {
        $pin = ParcelCodeHelper::pinAsInt($code, $suffix !== '' ? $suffix : null, $prefix !== '' ? $prefix : null);
        if ($pin >= ParcelCodeHelper::PIN_MIN) {
            $usedPins[$pin] = true;
        }
    }

    private static function repointRelated(PDO $pdo, int $fromBenId, int $toBenId, int $targetCampaignId): void
    {
        if (self::tableExists($pdo, 'delivery_events')) {
            $pdo->prepare('UPDATE delivery_events SET beneficiary_id = ?, campaign_id = ? WHERE beneficiary_id = ?')
                ->execute([$toBenId, $targetCampaignId, $fromBenId]);
        }
        if (self::tableExists($pdo, 'sms_outbox')) {
            $pdo->prepare('UPDATE sms_outbox SET beneficiary_id = ?, campaign_id = ? WHERE beneficiary_id = ?')
                ->execute([$toBenId, $targetCampaignId, $fromBenId]);
        }
    }

    private static function deleteBeneficiaryRow(PDO $pdo, int $beneficiaryId, int $campaignId): void
    {
        $pdo->prepare('DELETE FROM beneficiaries WHERE id = ? AND campaign_id = ?')
            ->execute([$beneficiaryId, $campaignId]);
    }

    private static function moveLeftoverRelated(PDO $pdo, int $sourceId, int $targetId): void
    {
        if (self::tableExists($pdo, 'delivery_events')) {
            $pdo->prepare('UPDATE delivery_events SET campaign_id = ? WHERE campaign_id = ?')
                ->execute([$targetId, $sourceId]);
        }
        if (self::tableExists($pdo, 'sms_outbox')) {
            $pdo->prepare('UPDATE sms_outbox SET campaign_id = ? WHERE campaign_id = ?')
                ->execute([$targetId, $sourceId]);
        }
        if (self::tableExists($pdo, 'delivery_batches')) {
            $pdo->prepare('UPDATE delivery_batches SET campaign_id = ? WHERE campaign_id = ?')
                ->execute([$targetId, $sourceId]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function insertRow(PDO $pdo, string $table, array $row): int
    {
        unset($row['id']);
        $cols = [];
        $values = [];
        foreach ($row as $col => $value) {
            if (!is_string($col) || $col === '') {
                continue;
            }
            $cols[] = '`' . str_replace('`', '', $col) . '`';
            $values[] = $value;
        }
        if ($cols === []) {
            throw new RuntimeException('لا توجد أعمدة للنسخ في ' . $table);
        }
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO `' . str_replace('`', '', $table) . '` (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')';
        $pdo->prepare($sql)->execute($values);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function beneficiariesOf(int $campaignId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM beneficiaries WHERE campaign_id = ? ORDER BY id ASC');
        $stmt->execute([$campaignId]);
        return $stmt->fetchAll();
    }

    /**
     * @param list<array<string, mixed>> $people
     * @return array<string, array<string, mixed>>
     */
    private static function codeMap(array $people): array
    {
        $map = [];
        foreach ($people as $row) {
            $code = self::codeKey($row);
            if ($code !== '') {
                $map[$code] = $row;
            }
        }
        return $map;
    }

    /**
     * @return array<int, true>
     */
    private static function usedPins(int $campaignId, string $prefix, string $suffix): array
    {
        $used = [];
        foreach (self::beneficiariesOf($campaignId) as $row) {
            $code = trim((string) ($row['disbursement_code'] ?? ''));
            if ($code !== '') {
                self::rememberPin($used, $code, $prefix, $suffix);
            }
        }
        return $used;
    }

    /**
     * @param array<string, mixed> $campaign
     * @return array{total:int,delivered:int,opening:int,balance:int}
     */
    private static function stockSnapshot(int $campaignId, array $campaign): array
    {
        $stats = CampaignService::stats($campaignId);
        $opening = (int) ($campaign['opening_quantity'] ?? 0);
        $delivered = (int) ($stats['delivered'] ?? 0);
        $total = (int) ($stats['total'] ?? 0);
        if ($opening <= 0) {
            $opening = $total;
        }
        return [
            'total' => $total,
            'delivered' => $delivered,
            'opening' => $opening,
            'balance' => max(0, $opening - $delivered),
        ];
    }

    /**
     * @param list<array{action:string,source:array<string,mixed>,target:?array<string,mixed>}> $decisions
     */
    private static function countMovedDelivered(array $decisions): int
    {
        $n = 0;
        foreach ($decisions as $d) {
            if ($d['action'] === self::ACTION_MOVE && self::isDelivered($d['source'])) {
                $n++;
            }
        }
        return $n;
    }

    /** @param array<string, mixed> $row */
    private static function nidKey(array $row): string
    {
        return ArabicFormat::normalizeNationalId((string) ($row['national_id'] ?? ''));
    }

    /** @param array<string, mixed> $row */
    private static function codeKey(array $row): string
    {
        return strtoupper(trim((string) ($row['disbursement_code'] ?? '')));
    }

    /** @param array<string, mixed> $row */
    private static function isDelivered(array $row): bool
    {
        return DeliveryService::isDeliveredStatus($row['receipt_status'] ?? '');
    }

    /** @param array<string, mixed> $row */
    private static function isAssigned(array $row): bool
    {
        return (int) ($row['day_index'] ?? 0) > 0
            && trim((string) ($row['disbursement_code'] ?? '')) !== '';
    }

    public static function actionLabel(string $action): string
    {
        return match ($action) {
            self::ACTION_MOVE => 'نقل مع التسليم والكشف',
            self::ACTION_APPLY_DELIVERY => 'مستلم في المصدر فقط — ننقل التسليم للوجهة',
            self::ACTION_APPLY_ASSIGNMENT => 'نفس الشخص غير معيّن في الوجهة — ننقل تعيين الكشف',
            self::ACTION_SKIP_PENDING => 'موجود في الوجهة — نتجاهل نسخة المصدر',
            self::ACTION_SKIP_BOTH_DELIVERED => 'مستلم في العمليتين — نُبقي تسليم الوجهة',
            default => $action,
        };
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            if (config('db_driver') === 'mysql') {
                $stmt = $pdo->prepare(
                    'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
                );
                $stmt->execute([$table]);
                return (bool) $stmt->fetchColumn();
            }
            $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
            $stmt->execute([$table]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private static function truncateName(string $name): string
    {
        $name = trim($name);
        if (function_exists('mb_substr') && mb_strlen($name) > 180) {
            return mb_substr($name, 0, 180);
        }
        if (strlen($name) > 180) {
            return substr($name, 0, 180);
        }
        return $name;
    }
}
