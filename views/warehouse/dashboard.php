<?php
$stockActions = [
    ['label' => 'تفاصيل العملية', 'url' => '/campaigns/view?id=' . (int) $campaign['id']],
];
if (!empty($canDeliver)) {
    $stockActions[] = ['label' => 'التسليم الرسمي', 'url' => '/warehouse/deliver?campaign_id=' . (int) $campaign['id'], 'primary' => true];
}

page_header(
    'متابعة المخزن',
    [
        ['label' => 'العمليات', 'url' => '/'],
        ['label' => $campaign['name'], 'url' => '/campaigns/view?id=' . (int) $campaign['id']],
        ['label' => 'متابعة المخزن'],
    ],
    $stockActions,
    $campaign['parcel_name'] . ' | ' . $campaign['warehouse_name']
);

$opening = (int) ($stock['opening_quantity'] ?? 0);
$delivered = (int) ($stock['delivered'] ?? 0);
$delPct = $opening > 0 ? (int) round(($delivered / $opening) * 100) : 0;
?>

<div class="grid-stats">
    <div class="stat-card">
        <div class="stat-label">الكمية الافتتاحية</div>
        <div class="stat-value"><?= ar_digits($opening) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">مُسلَّم</div>
        <div class="stat-value"><?= ar_digits($delivered) ?></div>
        <div class="progress"><span style="width:<?= min(100, $delPct) ?>%"></span></div>
        <div class="stat-meta"><?= ar_digits($delPct) ?>% من الافتتاحي</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">الرصيد المتبقي</div>
        <div class="stat-value"><?= ar_digits((int) ($stock['balance'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">بانتظار التسليم</div>
        <div class="stat-value"><?= ar_digits((int) ($stock['pending'] ?? 0)) ?></div>
    </div>
</div>

<div class="grid-stats">
    <div class="stat-card">
        <div class="stat-label">في الموعد</div>
        <div class="stat-value"><?= ar_digits((int) ($stock['on_time'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">متأخر</div>
        <div class="stat-value"><?= ar_digits((int) ($stock['late'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">تسليم اليوم / المخطط</div>
        <div class="stat-value"><?= ar_digits((int) ($stock['today_delivered'] ?? 0)) ?> / <?= ar_digits((int) ($stock['planned_today'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">الحالة</div>
        <div class="stat-value" style="font-size:1.1rem">
            <?php if (!empty($stock['campaign_active'])): ?>
            <span class="badge badge-ok">مفتوح</span>
            <?php else: ?>
            <span class="badge badge-pending">مُنهى</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($canCloseDelivery)): ?>
<div class="card">
    <h2 class="panel-title" style="margin-bottom:0.75rem">حالة التسليم</h2>
    <?php if (!empty($stock['campaign_active'])): ?>
    <p class="text-muted">عملية التسليم <strong>مفتوحة</strong> — تستمر حتى تُنهيها يدوياً.</p>
    <form method="post" action="<?= e(url('/campaigns/close-delivery')) ?>" data-confirm="إنهاء عملية التسليم؟ لن يستطيع أمين المخزن تسجيل تسليمات جديدة.">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <button type="submit" class="btn btn-outline">إنهاء عملية التسليم</button>
    </form>
    <?php else: ?>
    <p class="text-muted">عملية التسليم <strong>مُنهية</strong> منذ <?= e($campaign['delivery_closed_at'] ?? '') ?>.</p>
    <form method="post" action="<?= e(url('/campaigns/reopen-delivery')) ?>">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <button type="submit" class="btn btn-outline">إعادة فتح التسليم</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($canBulkDeliver)): ?>
<div class="card">
    <h2 class="panel-title" style="margin-bottom:0.75rem">تسليم جماعي وتصحيح (مدير)</h2>
    <p class="text-muted">مطابقة سريعة للطرد، استبعاد من لم يستلم، تصحيح فردي بعد التسليم، والتراجع عن دفعة كاملة.</p>
    <a class="btn" href="<?= e(url('/campaigns/bulk-delivery?id=' . (int) $campaign['id'])) ?>">فتح التسليم الجماعي والتصحيح</a>
</div>
<?php endif; ?>

<?php if (!empty($canEdit)): ?>
<div class="card">
    <h2 class="panel-title" style="margin-bottom:0.75rem">الكمية الافتتاحية</h2>
    <form method="post" action="<?= e(url('/campaigns/opening-quantity')) ?>" class="actions-row">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <input type="number" name="opening_quantity" class="form-control" style="max-width:200px" min="0"
               value="<?= (int) ($campaign['opening_quantity'] ?? 0) ?: (int) ($stock['total_beneficiaries'] ?? 0) ?>" required>
        <button type="submit" class="btn btn-outline">حفظ</button>
    </form>
    <p class="text-muted" style="margin-top:0.75rem">قد تختلف عن عدد المستفيدين (مثال: 10,200 طرد لـ 10,000 مستفيد).</p>
</div>
<?php endif; ?>

<?php if (!empty($canExport)): ?>
<div class="card">
    <h2 class="panel-title" style="margin-bottom:0.75rem">التقارير والرسائل</h2>
    <div class="actions-row">
        <a href="<?= e(url('/campaigns/export-deliveries?id=' . (int) $campaign['id'])) ?>" class="btn">تقرير Excel للتسليمات</a>
        <?php if (!empty($canEdit) && !empty($smsEnabled) && ($smsPending ?? 0) > 0): ?>
        <form method="post" action="<?= e(url('/campaigns/sms-send')) ?>" data-confirm="إرسال <?= (int) $smsPending ?> رسالة SMS معلّقة؟">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
            <button type="submit" class="btn btn-outline">إرسال SMS المعلّقة (<?= ar_digits((int) $smsPending) ?>)</button>
        </form>
        <?php endif; ?>
    </div>
    <p class="text-muted" style="margin-top:0.75rem">
        التقرير يشمل: ملخص المخزن، الكشف الكامل، المُسلَّم، بانتظار التسليم، المتأخر، ورسائل التأكيد.
        <?php if (empty($smsEnabled)): ?>
        <br>إرسال SMS تلقائي غير مفعّل — الرسائل تُحفظ في التقرير للتصدير اليدوي.
        <?php endif; ?>
    </p>
</div>
<?php endif; ?>

<?php if (!empty($lateList)): ?>
<div class="card table-panel" data-table-filterable>
    <div class="table-toolbar">
        <div class="panel-title">متأخرون عن موعدهم (<?= ar_digits(count($lateList)) ?>+)</div>
    </div>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr><th>الكود</th><th>الاسم</th><th>الموعد</th><th>الشباك</th></tr>
        </thead>
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
</div>
<?php endif; ?>

<?php if (!empty($canCancelDeliveries) && (int) ($stock['delivered'] ?? 0) > 0): ?>
<div class="danger-zone">
    <h2>إلغاء التسليمات (مدير النظام)</h2>
    <p class="text-muted">يوجد <strong><?= ar_digits((int) ($stock['delivered'] ?? 0)) ?></strong> تسليم مسجّل.</p>
    <form method="post" action="<?= e(url('/campaigns/undo-deliveries')) ?>" data-confirm="إلغاء جميع التسليمات لهذه العملية؟" class="actions-row">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <button type="submit" class="btn btn-outline">إلغاء جميع التسليمات</button>
        <a href="<?= e(url('/campaigns/edit?id=' . (int) $campaign['id'])) ?>" class="btn btn-ghost">تعديل / حذف العملية</a>
    </form>
</div>
<?php endif; ?>

<?php partial('partials/delivered-table', [
    'deliveredList' => $deliveredList ?? [],
    'totalDelivered' => $deliveredTotal ?? ($stock['delivered'] ?? 0),
    'codePrefix' => $campaign['parcel_code'] ?? '',
    'codeSuffix' => $campaign['parcel_code_suffix'] ?? '',
]); ?>
