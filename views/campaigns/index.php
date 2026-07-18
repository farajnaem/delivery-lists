<?php
$campaigns = $campaigns ?? [];
$total = count($campaigns);
$generated = 0;
$drafts = 0;
$beneficiaries = 0;
foreach ($campaigns as $c) {
    if (($c['status'] ?? '') === 'generated') {
        $generated++;
    } else {
        $drafts++;
    }
    $beneficiaries += (int) ($c['beneficiary_count'] ?? 0);
}
$generatedPct = $total > 0 ? (int) round(($generated / $total) * 100) : 0;

$actions = [];
if (!empty($canCreate)) {
    $actions[] = ['label' => '+ عملية جديدة', 'url' => '/campaigns/create', 'primary' => true];
}

page_header(
    'لوحة العمليات',
    [['label' => 'الرئيسية'], ['label' => 'العمليات']],
    $actions,
    'نظرة عامة على عمليات التوزيع وحالتها.'
);
?>

<div class="grid-stats">
    <div class="stat-card">
        <div class="stat-label">إجمالي العمليات</div>
        <div class="stat-value"><?= ar_digits($total) ?></div>
        <div class="stat-meta">كل العمليات المسجّلة</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">مُولَّدة</div>
        <div class="stat-value"><?= ar_digits($generated) ?></div>
        <div class="progress" aria-hidden="true"><span style="width:<?= $generatedPct ?>%"></span></div>
        <div class="stat-meta"><?= ar_digits($generatedPct) ?>% من الإجمالي</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">مسودات</div>
        <div class="stat-value"><?= ar_digits($drafts) ?></div>
        <div class="stat-meta">بانتظار التوليد</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">المستفيدون</div>
        <div class="stat-value"><?= ar_digits($beneficiaries) ?></div>
        <div class="stat-meta">مجموع المسجّلين في العمليات</div>
    </div>
</div>

<div class="card table-panel" data-table-filterable>
    <div class="table-toolbar">
        <div>
            <div class="panel-title">قائمة العمليات</div>
            <div class="panel-subtitle">ابحث بالاسم أو الطرد أو الحالة</div>
        </div>
        <div class="table-toolbar-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" placeholder="بحث…" data-table-search aria-label="بحث في العمليات">
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
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
                <tr>
                    <td colspan="6">
                        <div class="empty-state" data-empty-row>
                            <strong>لا توجد عمليات بعد</strong>
                            <span>أنشئ عملية توزيع جديدة للبدء.</span>
                            <?php if (!empty($canCreate)): ?>
                            <div style="margin-top:1rem">
                                <a class="btn" href="<?= e(url('/campaigns/create')) ?>">+ عملية جديدة</a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php else: foreach ($campaigns as $c): ?>
                <tr>
                    <td>
                        <strong><?= e($c['name']) ?></strong>
                    </td>
                    <td>
                        <?= e($c['parcel_name']) ?>
                        <div class="text-muted" style="font-size:0.8rem"><?= e(\App\CampaignService::parcelLabel($c)) ?></div>
                    </td>
                    <td><?= ar_digits((int) ($c['beneficiary_count'] ?? 0)) ?></td>
                    <td><?= ar_digits((int) $c['num_days']) ?></td>
                    <td>
                        <?php if ($c['status'] === 'generated'): ?>
                        <span class="badge badge-ok">مُولَّد</span>
                        <?php else: ?>
                        <span class="badge badge-pending">مسودة</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-sm btn-outline" href="<?= e(url('/campaigns/view?id=' . (int)$c['id'])) ?>">فتح</a>
                            <?php if (!empty($canEdit)): ?>
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('/campaigns/edit?id=' . (int)$c['id'])) ?>">تعديل</a>
                            <?php endif; ?>
                            <?php if ($c['status'] === 'generated' && !empty($canDeliver)): ?>
                            <a class="btn btn-sm btn-secondary" href="<?= e(url('/warehouse/deliver?campaign_id=' . (int)$c['id'])) ?>">تسليم</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
