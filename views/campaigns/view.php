<?php
use App\CampaignService;
use App\ParcelCodeHelper;
$isGenerated = ($campaign['status'] ?? '') === 'generated';
$parcelLabel = CampaignService::parcelLabel($campaign);
$dayStats = $stats['days'] ?? [];
$perWindow = max(1, (int) ($campaign['per_window_capacity'] ?? 500));

$viewActions = [];
if ($isGenerated && !empty($canViewStock)) {
    $viewActions[] = ['label' => 'متابعة المخزن', 'url' => '/campaigns/stock?id=' . (int) $campaign['id'], 'primary' => true];
}
if ($isGenerated && !empty($canDeliver)) {
    $viewActions[] = ['label' => 'التسليم الرسمي', 'url' => '/warehouse/deliver?campaign_id=' . (int) $campaign['id']];
}
if (!empty($canEdit)) {
    $viewActions[] = ['label' => 'تعديل', 'url' => '/campaigns/edit?id=' . (int) $campaign['id']];
}

context_nav([
    ['label' => 'العمليات', 'url' => '/'],
    ['label' => $campaign['name']],
], $viewActions);
?>

<h1><?= e($campaign['name']) ?></h1>
<p class="text-muted">
    <?= e($campaign['parcel_name']) ?> — كود الطرد: <strong><?= e($parcelLabel) ?></strong>
    (SOCI + <?= e($campaign['parcel_code_suffix'] ?? '') ?>)
    | <?= e($campaign['warehouse_name']) ?>
</p>

<div class="stats">
    <div class="stat-box">
        <div class="value"><?= (int) ($stats['total'] ?? 0) ?></div>
        <div class="label">إجمالي المستفيدين</div>
    </div>
    <div class="stat-box">
        <div class="value"><?= (int) $campaign['num_days'] ?></div>
        <div class="label">أيام التسليم</div>
    </div>
    <div class="stat-box">
        <div class="value"><?= $perWindow ?></div>
        <div class="label">مستفيد / شباك</div>
    </div>
    <?php if (!empty($plan)): ?>
    <div class="stat-box">
        <div class="value"><?= (int) $plan['total_delivery_sheets'] ?></div>
        <div class="label">كشوف التسليم</div>
    </div>
    <?php endif; ?>
    <?php if (!empty($deliveryStats)): ?>
    <div class="stat-box">
        <div class="value"><?= (int) ($deliveryStats['delivered'] ?? 0) ?> / <?= (int) ($deliveryStats['opening_quantity'] ?? 0) ?></div>
        <div class="label">مُسلَّم / افتتاحي</div>
    </div>
    <div class="stat-box">
        <div class="value"><?= (int) ($deliveryStats['balance'] ?? 0) ?></div>
        <div class="label">رصيد المخزن</div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($plan)): ?>
<div class="card">
    <h2>خطة التوزيع (قبل/بعد التوليد)</h2>
    <p class="text-muted">
        <?= (int) $plan['total'] ?> ÷ <?= (int) $plan['num_days'] ?> أيام
        = <strong><?= (int) ($plan['daily_counts'][0] ?? 0) ?> مستفيد / يوم</strong>
        → ÷ <?= $perWindow ?> / شباك
        = <strong><?= (int) ($plan['days'][0]['windows'] ?? 0) ?> شبابيك / يوم</strong>
        → <strong><?= (int) $plan['total_delivery_sheets'] ?> كشف</strong>
        (<?= (int) $plan['num_days'] ?> أيام × <?= (int) ($plan['days'][0]['windows'] ?? 0) ?> شبابيك)
    </p>
    <table class="table">
        <thead>
            <tr><th>اليوم</th><th>المستفيدون</th><th>الشبابيك</th><th>لكل شباك</th></tr>
        </thead>
        <tbody>
        <?php foreach ($plan['days'] as $day): ?>
        <tr>
            <td><?= (int) $day['day_index'] ?></td>
            <td><?= (int) $day['beneficiaries'] ?></td>
            <td><?= (int) $day['windows'] ?></td>
            <td><?= e(implode('، ', array_map('strval', $day['per_window']))) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card">
    <h2>بيانات الطرد والمخزن</h2>
    <div class="grid-2">
        <p><strong>تاريخ البداية:</strong> <?= e($campaign['delivery_start']) ?></p>
        <p><strong>تاريخ النهاية:</strong> <?= e($campaign['delivery_end']) ?></p>
        <p><strong>موقع المخزن:</strong> <?= e($campaign['warehouse_location']) ?></p>
        <p><strong>ساعات العمل:</strong> <?= e(substr($campaign['work_start'], 0, 5)) ?> – <?= e(substr($campaign['work_end'], 0, 5)) ?></p>
        <p><strong>الحالة:</strong>
            <?php if ($isGenerated): ?>
            <span class="badge badge-ok">مُولَّد <?= e($campaign['generated_at'] ?? '') ?></span>
            <?php if (!\App\CampaignService::isDeliveryOpen($campaign)): ?>
            <span class="badge badge-pending">التسليم مُنهى</span>
            <?php else: ?>
            <span class="badge badge-ok">التسليم مفتوح</span>
            <?php endif; ?>
            <?php else: ?>
            <span class="badge badge-pending">مسودة — اضغط «توليد الكشوف»</span>
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="card">
    <h2>الإجراءات</h2>
    <div class="actions-row">
        <?php if (!empty($canEdit)): ?>
        <?php if (!$isGenerated && ($stats['total'] ?? 0) > 0): ?>
        <form method="post" action="<?= e(url('/campaigns/generate')) ?>" data-confirm="توليد الكشوف حسب الخطة أعلاه؟">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
            <button type="submit" class="btn">توليد الكشوف</button>
        </form>
        <?php elseif (!$isGenerated): ?>
        <form method="post" action="<?= e(url('/campaigns/import')) ?>" enctype="multipart/form-data">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
            <input type="file" name="excel_file" accept=".xlsx,.xls" required class="form-control" style="max-width:280px;display:inline-block">
            <button type="submit" class="btn btn-outline">رفع Excel</button>
        </form>
        <?php endif; ?>

        <?php if ($isGenerated && !empty($canEdit)): ?>
        <form method="post" action="<?= e(url('/campaigns/generate')) ?>" data-confirm="إعادة توليد الكشوف؟">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
            <button type="submit" class="btn btn-outline">إعادة التوليد</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($isGenerated && !empty($canExport)): ?>
        <a href="<?= e(url('/campaigns/export?id=' . (int)$campaign['id'])) ?>" class="btn">تنزيل Excel</a>
        <?php endif; ?>

        <?php if ($isGenerated && !empty($canViewStock)): ?>
        <a href="<?= e(url('/campaigns/stock?id=' . (int)$campaign['id'])) ?>" class="btn btn-outline">متابعة المخزن</a>
        <a href="<?= e(url('/campaigns/export-deliveries?id=' . (int)$campaign['id'])) ?>" class="btn btn-outline">تقرير التسليمات</a>
        <?php endif; ?>

        <?php if ($isGenerated && !empty($canDeliver)): ?>
        <a href="<?= e(url('/warehouse/deliver?campaign_id=' . (int)$campaign['id'])) ?>" class="btn btn-outline">التسليم الرسمي</a>
        <?php endif; ?>

        <?php if (!empty($canEdit)): ?>
        <a href="<?= e(url('/campaigns/edit?id=' . (int)$campaign['id'])) ?>" class="btn btn-outline">تعديل / حذف</a>
        <?php endif; ?>
    </div>
    <p class="text-muted" style="margin-top:0.75rem">
        ملف Excel: <strong>الكشف الإجمالي</strong> + <strong>يوم1_شباك1 … يوم5_شباك4</strong> (<?= (int) ($plan['total_delivery_sheets'] ?? 0) ?> كشف) + <strong>كشف الرسائل</strong>.
    </p>
</div>

<?php if (!empty($preview)): ?>
<div class="card">
    <h2>معاينة (أول 20 مستفيد<?= $isGenerated ? ' — بعد التوليد' : '' ?>)</h2>
    <div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>الاسم</th><th>الهوية</th><th>الجوال</th>
                <?php if ($isGenerated): ?>
                <th>كود</th><th>الحالة</th><th>يوم</th><th>شباك</th><th>من</th><th>إلى</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($preview as $b): ?>
        <tr>
            <td><?= e($b['name']) ?></td>
            <td><?= e($b['national_id']) ?></td>
            <td><?= e($b['mobile']) ?></td>
            <?php if ($isGenerated): ?>
            <td><?= e(ParcelCodeHelper::displayForBeneficiary((string) ($b['disbursement_code'] ?? ''), (string) ($campaign['parcel_code_suffix'] ?? ''))) ?></td>
            <td>
                <?php if (($b['receipt_status'] ?? '') === 'مستلم'): ?>
                <span class="badge-delivered-inline">مستلم</span>
                <?php else: ?>
                <span class="badge-pending-inline">قيد التسليم</span>
                <?php endif; ?>
            </td>
            <td><?= e($b['delivery_date']) ?></td>
            <td><?= (int) $b['window_num'] ?></td>
            <td><?= e($b['time_from']) ?></td>
            <td><?= e($b['time_to']) ?></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if ($isGenerated && ($deliveredTotal ?? 0) > 0): ?>
<?php partial('partials/delivered-table', [
    'deliveredList' => $deliveredList ?? [],
    'totalDelivered' => $deliveredTotal ?? 0,
    'codeSuffix' => $campaign['parcel_code_suffix'] ?? '',
]); ?>
<?php elseif ($isGenerated): ?>
<div class="card">
    <h2>المستلمون</h2>
    <p class="text-muted">لا يوجد مستلمون بعد — ستظهر القائمة هنا بعد تسجيل التسليم من <a href="<?= e(url('/warehouse/deliver?campaign_id=' . (int)$campaign['id'])) ?>">صفحة المخزن</a>.</p>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
        if (!confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
});
</script>
