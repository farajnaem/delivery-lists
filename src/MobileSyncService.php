<?php

declare(strict_types=1);

namespace App;

use PDO;

final class MobileSyncService
{
    /** @return list<array<string, mixed>> */
    public static function listCampaigns(): array
    {
        $campaigns = DeliveryService::warehouseCampaigns();
        $result = [];
        foreach ($campaigns as $c) {
            $stats = DeliveryService::stockStats((int) $c['id']);
            $result[] = [
                'id' => (int) $c['id'],
                'name' => $c['name'],
                'parcel_name' => $c['parcel_name'],
                'warehouse_name' => $c['warehouse_name'],
                'delivery_start' => $c['delivery_start'],
                'delivery_end' => $c['delivery_end'],
                'delivery_closed_at' => $c['delivery_closed_at'] ?? null,
                'campaign_active' => DeliveryService::isCampaignActive($c),
                'beneficiary_count' => (int) ($c['beneficiary_count'] ?? 0),
                'delivered_count' => (int) ($c['delivered_count'] ?? 0),
                'sync_token' => self::campaignSyncToken((int) $c['id']),
                'stock' => [
                    'opening_quantity' => (int) ($stats['opening_quantity'] ?? 0),
                    'delivered' => (int) ($stats['delivered'] ?? 0),
                    'balance' => (int) ($stats['balance'] ?? 0),
                    'pending' => (int) ($stats['pending'] ?? 0),
                ],
            ];
        }
        return $result;
    }

    /** @return array{campaigns:list<array<string,mixed>>, hint:?string} */
    public static function campaignsPayload(): array
    {
        $campaigns = self::listCampaigns();
        return [
            'campaigns' => $campaigns,
            'hint' => $campaigns === []
                ? 'لا توجد عمليات مُولَّدة. من لوحة المدير: ارفع Excel ثم اضغط «توليد الكشوف». حساب التطبيق يجب أن يكون بدور «أمين مخزن» أو «مدير النظام».'
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function snapshot(int $campaignId): array
    {
        $campaign = CampaignService::find($campaignId);
        if (!$campaign || ($campaign['status'] ?? '') !== 'generated') {
            throw new \RuntimeException('العملية غير جاهزة للتسليم.');
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT b.*, u.name AS delivered_by_name
            FROM beneficiaries b
            LEFT JOIN users u ON u.id = b.delivered_by
            WHERE b.campaign_id = ?
            ORDER BY b.sort_order ASC, b.id ASC
        ');
        $stmt->execute([$campaignId]);
        $rows = $stmt->fetchAll();

        $codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
        $codePrefix = (string) ($campaign['parcel_code'] ?? '');
        $beneficiaries = array_map(
            fn (array $row): array => self::formatBeneficiary($row, $codeSuffix, $codePrefix),
            $rows
        );
        $stats = DeliveryService::stockStats($campaignId);

        return [
            'campaign' => self::formatCampaign($campaign),
            'sync_token' => self::campaignSyncToken($campaignId),
            'beneficiaries' => $beneficiaries,
            'stock' => self::formatStock($stats),
            'recent_delivered' => DeliveryService::deliveredBeneficiaries($campaignId, 50),
            'late' => DeliveryService::pendingLate($campaignId, 100),
        ];
    }

    /**
     * @param list<array{beneficiary_id:int,client_id?:string}> $pending
     * @return array<string, mixed>
     */
    public static function sync(int $campaignId, int $userId, ?string $lastSyncToken, array $pending): array
    {
        $campaign = CampaignService::find($campaignId);
        if (!$campaign || ($campaign['status'] ?? '') !== 'generated') {
            throw new \RuntimeException('العملية غير جاهزة.');
        }

        $upload = DeliveryService::syncBatch($campaignId, $userId, $pending);
        $updated = self::changesSince($campaignId, $lastSyncToken);
        $stats = DeliveryService::stockStats($campaignId);

        return [
            'ok' => true,
            'upload' => $upload,
            'sync_token' => self::campaignSyncToken($campaignId),
            'updated_beneficiaries' => $updated,
            'campaign' => self::formatCampaign($campaign),
            'stock' => self::formatStock($stats),
            'recent_delivered' => DeliveryService::deliveredBeneficiaries($campaignId, 50),
            'late' => DeliveryService::pendingLate($campaignId, 100),
        ];
    }

    /**
     * تسليم فوري من الموبايل (أونلاين) — يمنع التسليم المزدوج بين الأجهزة.
     *
     * @return array<string, mixed>
     */
    public static function deliver(int $campaignId, int $beneficiaryId, int $userId, ?string $clientId): array
    {
        $campaign = CampaignService::find($campaignId);
        if (!$campaign || ($campaign['status'] ?? '') !== 'generated') {
            throw new \RuntimeException('العملية غير جاهزة.');
        }

        $result = DeliveryService::markDelivered($campaignId, $beneficiaryId, $userId, $clientId);
        $codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
        $codePrefix = (string) ($campaign['parcel_code'] ?? '');
        $beneficiary = null;
        if (!empty($result['beneficiary']) && is_array($result['beneficiary'])) {
            $beneficiary = self::formatBeneficiary($result['beneficiary'], $codeSuffix, $codePrefix);
        } elseif ($beneficiaryId > 0) {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('SELECT * FROM beneficiaries WHERE id = ? AND campaign_id = ? LIMIT 1');
            $stmt->execute([$beneficiaryId, $campaignId]);
            $row = $stmt->fetch();
            if ($row) {
                $beneficiary = self::formatBeneficiary($row, $codeSuffix, $codePrefix);
            }
        }

        $stats = DeliveryService::stockStats($campaignId);

        return [
            'ok' => !empty($result['ok']),
            'already' => !empty($result['already']),
            'error' => $result['error'] ?? null,
            'beneficiary' => $beneficiary,
            'stock' => self::formatStock($stats),
            'campaign' => self::formatCampaign($campaign),
            'sync_token' => self::campaignSyncToken($campaignId),
            'recent_delivered' => DeliveryService::deliveredBeneficiaries($campaignId, 50),
            'late' => DeliveryService::pendingLate($campaignId, 100),
        ];
    }

    public static function campaignSyncToken(int $campaignId): string
    {
        $pdo = Database::getConnection();
        $benMax = $pdo->prepare('SELECT MAX(updated_at) FROM beneficiaries WHERE campaign_id = ?');
        $benMax->execute([$campaignId]);
        $b = (string) ($benMax->fetchColumn() ?: '');

        $camp = CampaignService::find($campaignId);
        $c = (string) ($camp['delivery_closed_at'] ?? '');

        $max = max($b, $c, '1970-01-01 00:00:00');
        return $max;
    }

    /** @return list<array<string, mixed>> */
    public static function changesSince(int $campaignId, ?string $since): array
    {
        $pdo = Database::getConnection();
        $campaign = CampaignService::find($campaignId);
        $codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
        $codePrefix = (string) ($campaign['parcel_code'] ?? '');
        $since = trim((string) $since);
        if ($since === '') {
            return [];
        }

        $stmt = $pdo->prepare('
            SELECT b.*, u.name AS delivered_by_name
            FROM beneficiaries b
            LEFT JOIN users u ON u.id = b.delivered_by
            WHERE b.campaign_id = ?
              AND b.updated_at > ?
            ORDER BY b.updated_at ASC, b.id ASC
        ');
        $stmt->execute([$campaignId, $since]);
        return array_map(
            fn (array $row): array => self::formatBeneficiary($row, $codeSuffix, $codePrefix),
            $stmt->fetchAll()
        );
    }

    /** @param array<string, mixed> $row */
    public static function formatBeneficiary(array $row, string $codeSuffix = '', string $codePrefix = ''): array
    {
        $enriched = DeliveryService::enrichForDisplay(
            $row,
            $codeSuffix !== '' ? $codeSuffix : null,
            $codePrefix !== '' ? $codePrefix : null
        );
        return [
            'id' => (int) ($enriched['id'] ?? 0),
            'campaign_id' => (int) ($enriched['campaign_id'] ?? 0),
            'name' => $enriched['name'] ?? '',
            'national_id' => $enriched['national_id'] ?? '',
            'mobile' => $enriched['mobile'] ?? '',
            'receipt_status' => $enriched['receipt_status'] ?? DeliveryService::STATUS_PENDING,
            'disbursement_code' => $enriched['disbursement_code'] ?? '',
            'display_code' => $enriched['display_code'] ?? '',
            'sort_order' => (int) ($enriched['sort_order'] ?? 0),
            'delivery_date' => $enriched['delivery_date'] ?? null,
            'window_num' => isset($enriched['window_num']) ? (int) $enriched['window_num'] : null,
            'time_from' => $enriched['time_from'] ?? null,
            'time_to' => $enriched['time_to'] ?? null,
            'delivered_at' => $enriched['delivered_at'] ?? null,
            'delivery_type' => $enriched['delivery_type'] ?? null,
            'actual_delivery_date' => $enriched['actual_delivery_date'] ?? null,
            'delivered_by_name' => $enriched['delivered_by_name'] ?? null,
            'updated_at' => $enriched['updated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $campaign */
    public static function formatCampaign(array $campaign): array
    {
        return [
            'id' => (int) $campaign['id'],
            'name' => $campaign['name'],
            'parcel_name' => $campaign['parcel_name'],
            'warehouse_name' => $campaign['warehouse_name'],
            'delivery_start' => $campaign['delivery_start'],
            'delivery_end' => $campaign['delivery_end'],
            'delivery_closed_at' => $campaign['delivery_closed_at'] ?? null,
            'campaign_active' => DeliveryService::isCampaignActive($campaign),
            'opening_quantity' => (int) ($campaign['opening_quantity'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $stats */
    public static function formatStock(array $stats): array
    {
        return [
            'opening_quantity' => (int) ($stats['opening_quantity'] ?? 0),
            'delivered' => (int) ($stats['delivered'] ?? 0),
            'balance' => (int) ($stats['balance'] ?? 0),
            'pending' => (int) ($stats['pending'] ?? 0),
            'on_time' => (int) ($stats['on_time'] ?? 0),
            'late' => (int) ($stats['late'] ?? 0),
            'today_delivered' => (int) ($stats['today_delivered'] ?? 0),
            'planned_today' => (int) ($stats['planned_today'] ?? 0),
            'campaign_active' => (bool) ($stats['campaign_active'] ?? false),
            'total_beneficiaries' => (int) ($stats['total_beneficiaries'] ?? 0),
        ];
    }
}
