<?php
$cid = (int) $campaign['id'];
$stockActions = [
    ['label' => 'عودة للعملية', 'url' => '/campaigns/view?id=' . $cid],
    ['label' => 'الأيام والكشوف', 'url' => '/campaigns/view?id=' . $cid . '&panel=days'],
];
if (!empty($canDeliver)) {
    $stockActions[] = ['label' => 'التسليم الرسمي', 'url' => '/warehouse/deliver?campaign_id=' . $cid, 'primary' => true];
}

page_header(
    'المخزن والتسليم',
    [
        ['label' => 'العمليات', 'url' => '/'],
        ['label' => $campaign['name'], 'url' => '/campaigns/view?id=' . $cid],
        ['label' => 'المخزن'],
    ],
    $stockActions,
    $campaign['parcel_name'] . ' | ' . $campaign['warehouse_name']
);

$opening = (int) ($stock['opening_quantity'] ?? 0);
$delivered = (int) ($stock['delivered'] ?? 0);
$delPct = $opening > 0 ? (int) round(($delivered / $opening) * 100) : 0;
$gate = is_array($deliveryGate ?? null) ? $deliveryGate : \App\CampaignService::deliveryGateStatus($campaign);
$gateState = (string) ($gate['state'] ?? '');
$canManageGate = !empty($canStartDelivery) || !empty($canCloseDelivery);
$scheduleDefault = date('Y-m-d') . 'T09:00';
$opensRaw = trim((string) ($campaign['delivery_opens_at'] ?? ''));
if ($opensRaw !== '') {
    $ots = strtotime($opensRaw);
    if ($ots !== false) {
        $scheduleDefault = date('Y-m-d\TH:i', $ots);
    }
}
$todayDelivered = (int) ($stock['today_delivered'] ?? 0);
$plannedToday = (int) ($stock['planned_today'] ?? 0);
?>

<div class="grid-stats">
    <div class="stat-card">
        <div class="stat-label">مُسلَّم / افتتاحي</div>
        <div class="stat-value"><?= ar_digits($delivered) ?> / <?= ar_digits($opening) ?></div>
        <div class="progress"><span style="width:<?= min(100, $delPct) ?>%"></span></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">الرصيد المتبقي</div>
        <div class="stat-value"><?= ar_digits((int) ($stock['balance'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">بانتظار التسليم</div>
        <div class="stat-value"><?= ar_digits((int) ($stock['pending'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">اليوم (نظام / مخطط)</div>
        <div class="stat-value"><?= ar_digits($todayDelivered) ?> / <?= ar_digits($plannedToday) ?></div>
        <div class="stat-meta">
            <?php if ($gateState === 'open'): ?>
            <span class="badge badge-ok"><?= e((string) ($gate['label'] ?? 'مفتوح')) ?></span>
            <?php else: ?>
            <span class="badge badge-pending"><?= e((string) ($gate['label'] ?? 'مغلق')) ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="border:2px solid var(--accent, #2563eb)">
    <h2 class="panel-title" style="margin-top:0">المطابقة والكشوف النهائية</h2>
    <p class="text-muted" style="margin:0 0 0.75rem">
        قارن مستلمي النظام مع الميدان، ثم اطبع الكشوف من هنا أو من تبويب الأيام.
    </p>
    <div class="actions-row" style="flex-wrap:wrap;gap:0.5rem">
        <a class="btn" href="<?= e(url('/campaigns/beneficiaries?id=' . $cid . '&filter=today')) ?>">مستلمو اليوم</a>
        <?php if (!empty($canExport)): ?>
        <a class="btn" href="<?= e(url('/campaigns/export?id=' . $cid)) ?>">كشوف التسليم المعتمدة</a>
        <a class="btn btn-outline" href="<?= e(url('/campaigns/export-deliveries?id=' . $cid)) ?>">تقرير المستلمين Excel</a>
        <?php endif; ?>
        <a class="btn btn-outline" href="<?= e(url('/campaigns/view?id=' . $cid . '&panel=days')) ?>">أيام وكشوف يوم بيوم</a>
        <?php if (!empty($canBulkDeliver)): ?>
        <a class="btn btn-outline" href="<?= e(url('/campaigns/bulk-delivery?id=' . $cid)) ?>">تسليم جماعي / تصحيح</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManageGate): ?>
<details class="card" style="padding:1rem" <?= $gateState !== 'open' ? 'open' : '' ?>>
    <summary style="cursor:pointer;font-weight:600">تشغيل التسليم (بدء / قفل / إنهاء)</summary>
    <p class="text-muted" style="margin:0.75rem 0"><?= e((string) ($gate['detail'] ?? $gate['label'] ?? '')) ?></p>
    <?php if (!empty($canStartDelivery) && $gateState !== 'closed'): ?>
    <div class="actions-row" style="flex-wrap:wrap;gap:0.5rem;margin-bottom:0.75rem">
        <form method="post" action="<?= e(url('/campaigns/start-delivery')) ?>" data-confirm="بدء التسليم الآن؟">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= $cid ?>">
            <input type="hidden" name="opens_at" value="">
            <button type="submit" class="btn">بدء الآن</button>
        </form>
        <?php if (in_array($gateState, ['open', 'scheduled'], true)): ?>
        <form method="post" action="<?= e(url('/campaigns/lock-delivery')) ?>" data-confirm="قفل التسليم مؤقتاً؟">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= $cid ?>">
            <button type="submit" class="btn btn-outline">قفل مؤقت</button>
        </form>
        <?php endif; ?>
    </div>
    <form method="post" action="<?= e(url('/campaigns/start-delivery')) ?>" class="actions-row" data-confirm="جدولة بدء التسليم؟">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= $cid ?>">
        <input type="datetime-local" name="opens_at" class="form-control" style="max-width:240px" required value="<?= e($scheduleDefault) ?>">
        <button type="submit" class="btn btn-outline">حفظ الجدولة</button>
    </form>
    <?php endif; ?>
    <?php if (!empty($canCloseDelivery)): ?>
    <hr style="border:0;border-top:1px solid #e5e8e8;margin:1rem 0">
    <?php if ($gateState !== 'closed'): ?>
    <form method="post" action="<?= e(url('/campaigns/close-delivery')) ?>" data-confirm="إنهاء عملية التسليم نهائياً؟">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= $cid ?>">
        <button type="submit" class="btn btn-outline">إنهاء التسليم</button>
    </form>
    <?php else: ?>
    <form method="post" action="<?= e(url('/campaigns/reopen-delivery')) ?>">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= $cid ?>">
        <button type="submit" class="btn btn-outline">إعادة فتح التسليم</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
</details>
<?php endif; ?>

<?php if (!empty($canCloseDelivery) && !empty($keeperStats)): ?>
<?php
$keepers = $keeperStats['keepers'] ?? [];
$bulkStats = $keeperStats['bulk'] ?? ['today' => 0, 'total' => 0];
$hasBulk = ((int) ($bulkStats['total'] ?? 0)) > 0;
?>
<?php if (!empty($keepers) || $hasBulk): ?>
<details class="card table-panel" style="padding:1rem">
    <summary style="cursor:pointer;font-weight:600">التسليم حسب أمين المخزن</summary>
    <div class="table-wrap" style="margin-top:0.75rem">
    <table class="data-table">
        <thead><tr><th>أمين المخزن</th><th>اليوم</th><th>الإجمالي</th></tr></thead>
        <tbody>
        <?php foreach ($keepers as $k): ?>
            <tr>
                <td><?= e($k['name'] ?? 'غير معروف') ?></td>
                <td><?= ar_digits((int) ($k['today'] ?? 0)) ?></td>
                <td><?= ar_digits((int) ($k['total'] ?? 0)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($hasBulk): ?>
            <tr>
                <td>تسليم جماعي</td>
                <td><?= ar_digits((int) ($bulkStats['today'] ?? 0)) ?></td>
                <td><?= ar_digits((int) ($bulkStats['total'] ?? 0)) ?></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</details>
<?php endif; ?>
<?php endif; ?>

<details class="card" style="padding:1rem">
    <summary style="cursor:pointer;color:var(--muted)">إعدادات إضافية (كمية، SMS، إلغاء تسليمات)</summary>
    <div style="margin-top:0.85rem;display:grid;gap:0.85rem">
        <?php if (!empty($canEdit)): ?>
        <form method="post" action="<?= e(url('/campaigns/opening-quantity')) ?>" class="actions-row">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= $cid ?>">
            <label class="field-label" style="margin:0">الكمية الافتتاحية</label>
            <input type="number" name="opening_quantity" class="form-control" style="max-width:160px" min="0"
                   value="<?= (int) ($campaign['opening_quantity'] ?? 0) ?: (int) ($stock['total_beneficiaries'] ?? 0) ?>" required>
            <button type="submit" class="btn btn-outline">حفظ</button>
        </form>
        <?php endif; ?>
        <?php if (!empty($canEdit) && !empty($smsEnabled) && ($smsPending ?? 0) > 0): ?>
        <form method="post" action="<?= e(url('/campaigns/sms-send')) ?>" data-confirm="إرسال <?= (int) $smsPending ?> رسالة؟">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= $cid ?>">
            <button type="submit" class="btn btn-outline">إرسال SMS المعلّقة (<?= ar_digits((int) $smsPending) ?>)</button>
        </form>
        <?php endif; ?>
        <?php if (!empty($canCancelDeliveries) && $delivered > 0): ?>
        <form method="post" action="<?= e(url('/campaigns/undo-deliveries')) ?>" data-confirm="إلغاء جميع التسليمات؟">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= $cid ?>">
            <button type="submit" class="btn btn-danger">إلغاء جميع التسليمات (<?= ar_digits($delivered) ?>)</button>
        </form>
        <?php endif; ?>
    </div>
</details>

<?php if (!empty($lateList)): ?>
<details class="card table-panel" style="padding:1rem">
    <summary style="cursor:pointer;color:var(--muted)">متأخرون (<?= ar_digits(count($lateList)) ?>)</summary>
    <div class="table-wrap" style="margin-top:0.75rem">
    <table class="data-table">
        <thead><tr><th>الكود</th><th>الاسم</th><th>الموعد</th><th>الشباك</th></tr></thead>
        <tbody>
        <?php foreach ($lateList as $row): ?>
        <tr>
            <td><?= e($row['display_code'] ?? $row['sort_order'] ?? $row['disbursement_code']) ?></td>
            <td><?= e($row['name']) ?></td>
            <td><?= e($row['delivery_date']) ?></td>
            <td><?= ar_digits((int) $row['window_num']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</details>
<?php endif; ?>

<details class="card" style="padding:1rem">
    <summary style="cursor:pointer;color:var(--muted)">آخر المستلمين</summary>
    <div style="margin-top:0.75rem">
    <?php partial('partials/delivered-table', [
        'deliveredList' => $deliveredList ?? [],
        'totalDelivered' => $deliveredTotal ?? ($stock['delivered'] ?? 0),
        'codePrefix' => $campaign['parcel_code'] ?? '',
        'codeSuffix' => $campaign['parcel_code_suffix'] ?? '',
    ]); ?>
    </div>
</details>
