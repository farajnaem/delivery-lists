<?php
$stockActions = [
    ['label' => 'تفاصيل العملية', 'url' => '/campaigns/view?id=' . (int) $campaign['id']],
];
if (!empty($canDeliver)) {
    $stockActions[] = ['label' => 'التسليم الرسمي', 'url' => '/warehouse/deliver?campaign_id=' . (int) $campaign['id'], 'primary' => true];
}
context_nav([
    ['label' => 'العمليات', 'url' => '/'],
    ['label' => $campaign['name'], 'url' => '/campaigns/view?id=' . (int) $campaign['id']],
    ['label' => 'متابعة المخزن'],
], $stockActions);
?>
<h1>متابعة المخزن — <?= e($campaign['name']) ?></h1>
<p class="text-muted"><?= e($campaign['parcel_name']) ?> | <?= e($campaign['warehouse_name']) ?></p>

<div class="stats">
    <div class="stat-box">
        <div class="value"><?= (int) ($stock['opening_quantity'] ?? 0) ?></div>
        <div class="label">الكمية الافتتاحية</div>
    </div>
    <div class="stat-box">
        <div class="value"><?= (int) ($stock['delivered'] ?? 0) ?></div>
        <div class="label">مُسلَّم</div>
    </div>
    <div class="stat-box">
        <div class="value"><?= (int) ($stock['balance'] ?? 0) ?></div>
        <div class="label">الرصيد المتبقي</div>
    </div>
    <div class="stat-box">
        <div class="value"><?= (int) ($stock['pending'] ?? 0) ?></div>
        <div class="label">بانتظار التسليم</div>
    </div>
    <div class="stat-box">
        <div class="value"><?= (int) ($stock['on_time'] ?? 0) ?></div>
        <div class="label">في الموعد</div>
    </div>
    <div class="stat-box">
        <div class="value"><?= (int) ($stock['late'] ?? 0) ?></div>
        <div class="label">متأخر</div>
    </div>
    <div class="stat-box">
        <div class="value"><?= (int) ($stock['today_delivered'] ?? 0) ?> / <?= (int) ($stock['planned_today'] ?? 0) ?></div>
        <div class="label">تسليم اليوم / المخطط</div>
    </div>
</div>

<?php if (!empty($canEdit)): ?>
<div class="card">
    <h2>حالة التسليم</h2>
    <?php if (!empty($stock['campaign_active'])): ?>
    <p class="text-muted">عملية التسليم <strong>مفتوحة</strong> — تستمر حتى تُنهيها يدوياً (لا تُغلق تلقائياً بانتهاء التاريخ).</p>
    <form method="post" action="<?= e(url('/campaigns/close-delivery')) ?>" data-confirm="إنهاء عملية التسليم؟ لن يستطيع أمين المخزن تسجيل تسليمات جديدة.">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <button type="submit" class="btn btn-outline" style="border-color:#d97706;color:#b45309">إنهاء عملية التسليم</button>
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

<?php if (!empty($canEdit)): ?>
<div class="card">
    <h2>الكمية الافتتاحية</h2>
    <form method="post" action="<?= e(url('/campaigns/opening-quantity')) ?>" class="actions-row">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <input type="number" name="opening_quantity" class="form-control" style="max-width:200px" min="0"
               value="<?= (int) ($campaign['opening_quantity'] ?? 0) ?: (int) ($stock['total_beneficiaries'] ?? 0) ?>" required>
        <button type="submit" class="btn btn-outline">حفظ</button>
    </form>
    <p class="text-muted">قد تختلف عن عدد المستفيدين (مثال: 10,200 طرد لـ 10,000 مستفيد).</p>
</div>
<?php endif; ?>

<?php if (!empty($canExport)): ?>
<div class="card">
    <h2>التقارير والرسائل</h2>
    <div class="actions-row">
        <a href="<?= e(url('/campaigns/export-deliveries?id=' . (int) $campaign['id'])) ?>" class="btn">تقرير Excel للتسليمات</a>
        <?php if (!empty($canEdit) && !empty($smsEnabled) && ($smsPending ?? 0) > 0): ?>
        <form method="post" action="<?= e(url('/campaigns/sms-send')) ?>" data-confirm="إرسال <?= (int) $smsPending ?> رسالة SMS معلّقة؟">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
            <button type="submit" class="btn btn-outline">إرسال SMS المعلّقة (<?= (int) $smsPending ?>)</button>
        </form>
        <?php endif; ?>
    </div>
    <p class="text-muted">
        التقرير يشمل: ملخص المخزن، الكشف الكامل، المُسلَّم، بانتظار التسليم، المتأخر، ورسائل التأكيد.
        <?php if (empty($smsEnabled)): ?>
        <br>إرسال SMS تلقائي غير مفعّل — الرسائل تُحفظ في التقرير للتصدير اليدوي.
        <?php endif; ?>
    </p>
</div>
<?php endif; ?>

<?php if (!empty($canDeliver)): ?>
<div class="card">
    <a href="<?= e(url('/warehouse/deliver?campaign_id=' . (int) $campaign['id'])) ?>" class="btn">فتح التسليم الرسمي</a>
</div>
<?php endif; ?>

<?php if (!empty($lateList)): ?>
<div class="card">
    <h2>متأخرون عن موعدهم (<?= count($lateList) ?>+)</h2>
    <div class="table-wrap">
    <table class="table">
        <thead>
            <tr><th>الكود</th><th>الاسم</th><th>الموعد</th><th>الشباك</th></tr>
        </thead>
        <tbody>
        <?php foreach ($lateList as $row): ?>
        <tr>
            <td><?= e($row['display_code'] ?? $row['sort_order'] ?? $row['disbursement_code']) ?></td>
            <td><?= e($row['name']) ?></td>
            <td><?= e($row['delivery_date']) ?></td>
            <td><?= (int) $row['window_num'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($canCancelDeliveries) && (int) ($stock['delivered'] ?? 0) > 0): ?>
<div class="card" style="border-color:#f59e0b;background:#fffbeb">
    <h2>إلغاء التسليمات (مدير النظام)</h2>
    <p class="text-muted">يوجد <strong><?= (int) ($stock['delivered'] ?? 0) ?></strong> تسليم مسجّل. لإعادة المستفيدين لـ «قيد التسليم» وحذف العملية لاحقاً:</p>
    <form method="post" action="<?= e(url('/campaigns/undo-deliveries')) ?>" data-confirm="إلغاء جميع التسليمات لهذه العملية؟">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <button type="submit" class="btn btn-outline" style="border-color:#d97706;color:#b45309">إلغاء جميع التسليمات</button>
        <a href="<?= e(url('/campaigns/edit?id=' . (int) $campaign['id'])) ?>" class="btn btn-outline">تعديل / حذف العملية</a>
    </form>
</div>
<?php endif; ?>

<?php partial('partials/delivered-table', [
    'deliveredList' => $deliveredList ?? [],
    'totalDelivered' => $deliveredTotal ?? ($stock['delivered'] ?? 0),
    'codeSuffix' => $campaign['parcel_code_suffix'] ?? '',
]); ?>

<script>
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
        if (!confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
});
</script>
