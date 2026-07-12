<?php use App\Auth; ?>
<?php
$indexActions = [];
if (!empty($canDeliver) && (Auth::role() ?? '') !== 'admin') {
    $indexActions[] = ['label' => 'تسليم المخزن', 'url' => '/warehouse', 'primary' => true];
}
context_nav([['label' => 'عمليات التوزيع']], $indexActions);
?>
<h1>عمليات التوزيع</h1>
<?php if (!empty($canCreate)): ?>
<p><a href="<?= e(url('/campaigns/create')) ?>" class="btn">+ عملية توزيع جديدة</a></p>
<?php endif; ?>

<div class="card">
    <table class="table">
        <thead>
            <tr>
                <th>الاسم</th>
                <th>الطرد</th>
                <th>المستفيدون</th>
                <th>الأيام</th>
                <th>الحالة</th>
                <th>إجراءات</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($campaigns)): ?>
            <tr><td colspan="6" class="text-muted">لا توجد عمليات بعد.</td></tr>
        <?php else: foreach ($campaigns as $c): ?>
            <tr>
                <td><?= e($c['name']) ?></td>
                <td><?= e($c['parcel_name']) ?> <small class="text-muted">(<?= e(\App\CampaignService::parcelLabel($c)) ?>)</small></td>
                <td><?= (int) ($c['beneficiary_count'] ?? 0) ?></td>
                <td><?= (int) $c['num_days'] ?></td>
                <td>
                    <?php if ($c['status'] === 'generated'): ?>
                    <span class="badge badge-ok">مُولَّد</span>
                    <?php else: ?>
                    <span class="badge badge-pending">مسودة</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= e(url('/campaigns/view?id=' . (int)$c['id'])) ?>">فتح</a>
                    <?php if (!empty($canEdit)): ?>
                    · <a href="<?= e(url('/campaigns/edit?id=' . (int)$c['id'])) ?>">تعديل</a>
                    <?php endif; ?>
                    <?php if ($c['status'] === 'generated' && !empty($canDeliver)): ?>
                    · <a href="<?= e(url('/warehouse/deliver?campaign_id=' . (int)$c['id'])) ?>">تسليم</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
