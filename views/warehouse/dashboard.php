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
    <a href="<?= e(url('/warehouse/deliver?campaign_id=' . (int) $campaign['id'])) ?>" class="btn">فتح صفحة التسليم (جوال)</a>
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
            <td><?= e($row['disbursement_code']) ?></td>
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

<div class="card">
    <h2>آخر التسليمات</h2>
    <div class="table-wrap">
    <table class="table">
        <thead>
            <tr><th>الكود</th><th>الاسم</th><th>النوع</th><th>الوقت</th><th>بواسطة</th></tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
        <tr>
            <td><?= e($r['disbursement_code']) ?></td>
            <td><?= e($r['name']) ?></td>
            <td><?= ($r['delivery_type'] ?? '') === 'late' ? 'متأخر' : 'في الموعد' ?></td>
            <td><?= e($r['delivered_at'] ?? '') ?></td>
            <td><?= e($r['delivered_by_name'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recent)): ?>
        <tr><td colspan="5" class="text-muted">لا تسليمات بعد</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<p><a href="<?= e(url('/campaigns/view?id=' . (int) $campaign['id'])) ?>">← العودة لتفاصيل العملية</a></p>

<script>
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
        if (!confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
});
</script>
