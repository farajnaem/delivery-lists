<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;

$pdo = Database::getConnection();
$isMysql = config('db_driver') === 'mysql';

$migrations = [
    'parcel_code_suffix' => "ALTER TABLE campaigns ADD COLUMN parcel_code_suffix TEXT NOT NULL DEFAULT ''",
    'opening_quantity' => "ALTER TABLE campaigns ADD COLUMN opening_quantity INTEGER NOT NULL DEFAULT 0",
    'delivery_closed_at' => 'ALTER TABLE campaigns ADD COLUMN delivery_closed_at TEXT',
    'delivered_at' => 'ALTER TABLE beneficiaries ADD COLUMN delivered_at TEXT',
    'delivered_by' => 'ALTER TABLE beneficiaries ADD COLUMN delivered_by INTEGER',
    'delivery_type' => 'ALTER TABLE beneficiaries ADD COLUMN delivery_type TEXT',
    'actual_delivery_date' => 'ALTER TABLE beneficiaries ADD COLUMN actual_delivery_date TEXT',
];

foreach ($migrations as $name => $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: {$name}\n";
    } catch (Throwable) {
        echo "SKIP: {$name} (exists)\n";
    }
}

// ترحيل كود الطرد القديم → SOCI + ملحق منفصل
try {
    $rows = $pdo->query('SELECT id, parcel_code, parcel_code_suffix FROM campaigns')->fetchAll();
    $upd = $pdo->prepare('UPDATE campaigns SET parcel_code = ?, parcel_code_suffix = ? WHERE id = ?');
    foreach ($rows as $row) {
        $suffix = trim((string) ($row['parcel_code_suffix'] ?? ''));
        if ($suffix === '') {
            $suffix = \App\ParcelCodeHelper::extractSuffixFromLegacy((string) ($row['parcel_code'] ?? ''));
        }
        $upd->execute([\App\ParcelCodeHelper::PREFIX, $suffix, $row['id']]);
    }
    echo "OK: parcel_code legacy migration\n";
} catch (Throwable $e) {
    echo "SKIP: parcel_code legacy migration\n";
}

// جداول/فهارس إضافية بصيغة SQLite فقط. على MySQL يتكفّل schema.mysql.sql بإنشائها.
if (!$isMysql) {
    $pdo->exec('
CREATE TABLE IF NOT EXISTS delivery_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    beneficiary_id INTEGER NOT NULL,
    campaign_id INTEGER NOT NULL,
    action TEXT NOT NULL DEFAULT \'delivered\',
    delivery_type TEXT,
    delivered_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
    delivered_by INTEGER,
    client_id TEXT,
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (delivered_by) REFERENCES users(id)
)
');
    echo "OK: delivery_events table\n";

    $indexes = [
        'idx_beneficiaries_code' => 'CREATE INDEX IF NOT EXISTS idx_beneficiaries_code ON beneficiaries(campaign_id, disbursement_code)',
        'idx_beneficiaries_national_id' => 'CREATE INDEX IF NOT EXISTS idx_beneficiaries_national_id ON beneficiaries(campaign_id, national_id)',
        'idx_beneficiaries_status' => 'CREATE INDEX IF NOT EXISTS idx_beneficiaries_status ON beneficiaries(campaign_id, receipt_status)',
        'idx_delivery_events_client' => 'CREATE UNIQUE INDEX IF NOT EXISTS idx_delivery_events_client ON delivery_events(client_id) WHERE client_id IS NOT NULL',
    ];

    foreach ($indexes as $name => $sql) {
        $pdo->exec($sql);
        echo "OK: {$name}\n";
    }

    $pdo->exec('
CREATE TABLE IF NOT EXISTS sms_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    beneficiary_id INTEGER NOT NULL,
    mobile TEXT NOT NULL,
    message_text TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT \'pending\',
    sent_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE
)
');
    echo "OK: sms_outbox table\n";
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sms_outbox_campaign ON sms_outbox(campaign_id, status)');
    echo "OK: idx_sms_outbox_campaign\n";
}

// تحديث أكواد الصرف: بدون أصفار + رسائل بالرقم التسلسلي فقط
try {
    $rows = $pdo->query('
        SELECT b.id, b.sort_order, b.message_text, c.parcel_code_suffix
        FROM beneficiaries b
        JOIN campaigns c ON c.id = b.campaign_id
        WHERE b.sort_order > 0
    ')->fetchAll();
    $updCode = $pdo->prepare('UPDATE beneficiaries SET disbursement_code = ?, message_text = ? WHERE id = ?');
    foreach ($rows as $row) {
        $serial = (int) $row['sort_order'];
        $suffix = (string) ($row['parcel_code_suffix'] ?? '');
        $code = \App\ParcelCodeHelper::buildDisbursementCode($suffix, $serial);
        $message = (string) ($row['message_text'] ?? '');
        if ($message !== '') {
            $message = preg_replace(
                '/كود\s+[^،]+،/u',
                'كود ' . \App\ParcelCodeHelper::displaySerial($serial) . '،',
                $message,
                1
            ) ?? $message;
        }
        $updCode->execute([$code, $message, $row['id']]);
    }
    echo "OK: disbursement_code format migration\n";
} catch (Throwable $e) {
    echo "SKIP: disbursement_code format migration\n";
}

if (!$isMysql) {
    try {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_beneficiaries_sort_order ON beneficiaries(campaign_id, sort_order)');
        echo "OK: idx_beneficiaries_sort_order\n";
    } catch (Throwable) {
        echo "SKIP: idx_beneficiaries_sort_order\n";
    }
}

echo "Migration complete.\n";
