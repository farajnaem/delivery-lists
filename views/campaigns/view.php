<?php
use App\CampaignService;

$isGenerated = ($campaign['status'] ?? '') === 'generated';
$parcelLabel = CampaignService::parcelLabel($campaign);
$dayStats = $stats['days'] ?? [];
$perWindow = max(1, (int) ($campaign['per_window_capacity'] ?? 400));
$planSafe = is_array($plan ?? null) ? $plan : [];
$numWindows = max(1, (int) ($planSafe['num_windows'] ?? $campaign['num_windows'] ?? 4));
$dailyCapacity = (int) ($planSafe['daily_capacity'] ?? ($numWindows * $perWindow));
$unassigned = (int) ($stats['unassigned'] ?? max(0, (int) ($stats['total'] ?? 0) - (int) ($stats['assigned'] ?? 0)));
$assigned = (int) ($stats['assigned'] ?? 0);
$defaultDayCount = min($unassigned, max(1, $numWindows * $perWindow));
if ($unassigned > 0 && $defaultDayCount < 1) {
    $defaultDayCount = min($unassigned, 1200);
}
$suggestedDayDate = (string) ($suggestedDayDate ?? $campaign['delivery_start'] ?? '');
$cid = (int) $campaign['id'];
$totalBen = (int) ($stats['total'] ?? 0);
$panel = (string) ($panel ?? '');

$viewActions = [];
if ($isGenerated && !empty($canViewStock)) {
    $viewActions[] = ['label' => 'المخزن والتسليم', 'url' => '/campaigns/stock?id=' . $cid, 'primary' => true];
}
if (!empty($canEdit)) {
    $viewActions[] = ['label' => 'تعديل العملية', 'url' => '/campaigns/edit?id=' . $cid];
}

$desc = $campaign['parcel_name'] . ' — كود الطرد: ' . $parcelLabel;
if (!empty($campaign['pipeline_name'])) {
    $desc .= ' · PipeLine: ' . $campaign['pipeline_name'];
}
$desc .= ' | ' . $campaign['warehouse_name'];

page_header(
    (string) $campaign['name'],
    [
        ['label' => 'العمليات', 'url' => '/'],
        ['label' => (string) $campaign['name']],
    ],
    $viewActions,
    $desc
);

$lastDayIndex = 0;
$lastDayMeta = null;
$lastDayConfirm = '';
if (!empty($dayStats)) {
    foreach ($dayStats as $dRow) {
        $lastDayIndex = max($lastDayIndex, (int) ($dRow['day_index'] ?? 0));
    }
    foreach ($dayStats as $dRow) {
        if ((int) ($dRow['day_index'] ?? 0) === $lastDayIndex) {
            $lastDayMeta = $dRow;
            break;
        }
    }
    $lastDayConfirm = 'إلغاء اليوم ' . ar_digits($lastDayIndex);
    if ($lastDayMeta && trim((string) ($lastDayMeta['delivery_date'] ?? '')) !== '') {
        $lastDayConfirm .= ' (' . (string) $lastDayMeta['delivery_date'] . ')';
    }
    $lastDayConfirm .= '؟ سيعود ' . ar_digits((int) ($lastDayMeta['cnt'] ?? 0))
        . ' مستفيد لغير المعيّنين. الأيام السابقة لا تُمس.';
}
$defaultDayWorkStart = substr((string) ($campaign['work_start'] ?? '09:00'), 0, 5);
$defaultDayWorkEnd = substr((string) ($campaign['work_end'] ?? '15:00'), 0, 5);

$panelUrl = static function (string $p) use ($cid): string {
    return url('/campaigns/view?id=' . $cid . ($p !== '' ? '&panel=' . rawurlencode($p) : ''));
};
?>

<div class="grid-stats">
    <div class="stat-card">
        <div class="stat-label">إجمالي المرشحين</div>
        <div class="stat-value"><?= ar_digits($totalBen) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">معيّنون / غير معيّنين</div>
        <div class="stat-value"><?= ar_digits($assigned) ?> / <?= ar_digits($unassigned) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">أيام معتمدة</div>
        <div class="stat-value"><?= ar_digits(count($dayStats)) ?></div>
        <div class="stat-meta">افتراضي: <?= ar_digits($numWindows) ?> شباك × <?= ar_digits($perWindow) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">طاقة يومية</div>
        <div class="stat-value"><?= ar_digits($dailyCapacity) ?></div>
        <?php if (!empty($deliveryStats)): ?>
        <div class="stat-meta">رصيد: <?= ar_digits((int) ($deliveryStats['balance'] ?? 0)) ?></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($totalBen > 0): ?>
<div class="card">
    <h2 class="panel-title" style="margin-top:0">بحث سريع</h2>
    <p class="text-muted" style="margin:0 0 0.75rem">برقم الهوية أو جزء من الاسم — ثم عرض / تعديل / حذف.</p>
    <form method="get" action="<?= e(url('/campaigns/beneficiaries')) ?>" class="actions-row" style="align-items:flex-end;gap:0.75rem;flex-wrap:wrap">
        <input type="hidden" name="id" value="<?= $cid ?>">
        <div style="flex:1;min-width:240px">
            <label class="field-label" for="campaign-beneficiary-search">الهوية أو الاسم</label>
            <input type="search" id="campaign-beneficiary-search" name="q" class="form-control"
                   placeholder="مثال: 802469809 أو جزء من الاسم" required autofocus>
        </div>
        <button type="submit" class="btn">بحث</button>
    </form>
</div>
<?php endif; ?>

<nav class="op-hubs" aria-label="أبواب العملية">
    <a class="op-hub<?= $panel === 'days' ? ' op-hub-active' : '' ?>" href="<?= e($panelUrl('days')) ?>">
        <strong>أيام التوزيع</strong>
        <span>اعتماد يوم أو إلغاء آخر يوم</span>
    </a>
    <a class="op-hub<?= $panel === 'candidates' ? ' op-hub-active' : '' ?>" href="<?= e($panelUrl('candidates')) ?>">
        <strong>كشف المرشحين</strong>
        <span>بحث، إضافة مجموعة، تنزيل الكشف</span>
    </a>
    <a class="op-hub<?= $panel === 'downloads' ? ' op-hub-active' : '' ?>" href="<?= e($panelUrl('downloads')) ?>">
        <strong>التنزيل</strong>
        <span>كشوف التسليم والرسائل يوم بيوم</span>
    </a>
    <?php if ($isGenerated && !empty($canViewStock)): ?>
    <a class="op-hub op-hub-primary" href="<?= e(url('/campaigns/stock?id=' . $cid)) ?>">
        <strong>المخزن والتسليم</strong>
        <span>يفتح صفحة المخزن</span>
    </a>
    <?php else: ?>
    <a class="op-hub" href="<?= e($panelUrl('days')) ?>">
        <strong>المخزن والتسليم</strong>
        <span>اعتمد يوماً أولاً</span>
    </a>
    <?php endif; ?>
</nav>

<?php if ($panel === ''): ?>
<div class="card">
    <p class="text-muted" style="margin:0">اضغط أحد الأبواب أعلاه لعرض إجراءاته فقط — بدون تكرار على الشاشة.</p>
</div>
<?php endif; ?>

<?php if ($panel === 'days'): ?>
<section class="op-panel">
    <?php if (!empty($dayStats)): ?>
    <div class="card table-panel">
        <div class="table-toolbar">
            <div>
                <div class="panel-title">الأيام المعتمدة</div>
                <div class="panel-subtitle">يمكن إلغاء آخر يوم فقط.</div>
            </div>
            <?php if (!empty($canEdit) && $lastDayIndex > 0): ?>
            <form method="post" action="<?= e(url('/campaigns/cancel-last-day')) ?>"
                  data-confirm="<?= e($lastDayConfirm) ?>">
                <?= \App\Csrf::field() ?>
                <input type="hidden" name="campaign_id" value="<?= $cid ?>">
                <button type="submit" class="btn btn-danger btn-sm">إلغاء آخر يوم (<?= ar_digits($lastDayIndex) ?>)</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>اليوم</th><th>التاريخ</th><th>المستفيدون</th><th>الشبابيك</th><th>الدوام</th></tr>
            </thead>
            <tbody>
            <?php foreach ($dayStats as $day): ?>
            <?php $di = (int) ($day['day_index'] ?? 0); ?>
            <tr>
                <td>
                    <?= ar_digits($di) ?>
                    <?php if ($di === $lastDayIndex): ?>
                    <span class="badge badge-pending">آخر يوم</span>
                    <?php endif; ?>
                </td>
                <td><?= e((string) ($day['delivery_date'] ?? '')) ?></td>
                <td><?= ar_digits((int) ($day['cnt'] ?? 0)) ?></td>
                <td><?= ar_digits((int) ($day['windows'] ?? 0)) ?></td>
                <td>
                    <?php
                    $ws = substr((string) ($day['work_start'] ?? ''), 0, 5);
                    $we = substr((string) ($day['work_end'] ?? ''), 0, 5);
                    echo ($ws !== '' && $we !== '') ? e($ws . ' – ' . $we) : '—';
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($canEdit) && $totalBen > 0 && $unassigned > 0): ?>
    <div class="card">
        <h2 class="panel-title" style="margin-bottom:0.35rem">اعتماد يوم توزيع</h2>
        <p class="text-muted" style="margin-bottom:1rem">
            المتبقي غير المعيّن: <strong><?= ar_digits($unassigned) ?></strong>
        </p>
        <form method="post" action="<?= e(url('/campaigns/generate-day')) ?>" class="grid-2" data-confirm="اعتماد هذا اليوم؟ لن يُمسّ أي يوم سابق.">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= $cid ?>">
            <div>
                <label class="field-label">تاريخ اليوم *</label>
                <input type="date" name="day_date" class="form-control" required value="<?= e($suggestedDayDate) ?>">
            </div>
            <div>
                <label class="field-label">عدد المستفيدين لهذا اليوم *</label>
                <input type="number" name="day_beneficiaries" class="form-control" min="1" max="<?= $unassigned ?>" required value="<?= (int) $defaultDayCount ?>">
            </div>
            <div>
                <label class="field-label">عدد الشبابيك *</label>
                <input type="number" name="day_windows" class="form-control" min="1" required value="<?= (int) $numWindows ?>">
            </div>
            <div>
                <label class="field-label">بداية الدوام *</label>
                <input type="time" name="day_work_start" class="form-control" required value="<?= e($defaultDayWorkStart) ?>">
            </div>
            <div>
                <label class="field-label">نهاية الدوام *</label>
                <input type="time" name="day_work_end" class="form-control" required value="<?= e($defaultDayWorkEnd) ?>">
            </div>
            <div style="display:flex;align-items:flex-end">
                <button type="submit" class="btn">اعتماد اليوم وتوليد كشوفه</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!empty($canEdit)): ?>
    <div class="card">
        <?php if ($assigned === 0 && $totalBen > 0): ?>
        <form method="post" action="<?= e(url('/campaigns/generate')) ?>" data-confirm="توليد كل الأيام دفعة واحدة؟ الأفضل عادةً اعتماد يوم بيوم.">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= $cid ?>">
            <button type="submit" class="btn btn-outline">توليد الكل دفعة واحدة</button>
        </form>
        <?php elseif ($totalBen === 0): ?>
        <form method="post" action="<?= e(url('/campaigns/import')) ?>" enctype="multipart/form-data" class="actions-row">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= $cid ?>">
            <input type="file" name="excel_file" accept=".xlsx,.xls" required class="form-control" style="max-width:280px">
            <button type="submit" class="btn btn-outline">رفع Excel المرشحين</button>
        </form>
        <?php elseif ($unassigned < 1): ?>
        <p class="text-muted" style="margin:0">لا يوجد غير معيّنين — كل المرشحين معيّنون لأيام.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($plan) && $assigned === 0): ?>
    <div class="card table-panel">
        <div class="panel-title">معاينة خطة كاملة (اختياري)</div>
        <div class="panel-subtitle" style="margin-bottom:0.75rem">
            <?= ar_digits((int) $plan['total']) ?> ÷ <?= ar_digits((int) ($plan['daily_capacity'] ?? $dailyCapacity)) ?>
            = <?= ar_digits((int) $plan['num_days']) ?> أيام
        </div>
        <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>اليوم</th><th>التاريخ</th><th>المستفيدون</th><th>الشبابيك</th><th>لكل شباك</th></tr>
            </thead>
            <tbody>
            <?php foreach ($plan['days'] as $i => $day): ?>
            <tr>
                <td><?= ar_digits((int) $day['day_index']) ?></td>
                <td><?= e((string) ($day['date'] ?? $plan['dates'][$i] ?? '')) ?></td>
                <td><?= ar_digits((int) $day['beneficiaries']) ?></td>
                <td><?= ar_digits((int) $day['windows']) ?></td>
                <td><?= e(implode('، ', array_map('strval', $day['per_window']))) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($panel === 'candidates'): ?>
<section class="op-panel">
    <div class="card">
        <h2 class="panel-title" style="margin-top:0">كشف المرشحين بالكامل</h2>
        <p class="text-muted" style="margin:0 0 0.75rem">
            البحث بالهوية أو جزء من الاسم، مع عرض الحالة وإمكانية التعديل أو الحذف (لغير المعيّن).
        </p>
        <div class="actions-row" style="flex-wrap:wrap;gap:0.5rem">
            <a class="btn" href="<?= e(url('/campaigns/beneficiaries?id=' . $cid)) ?>">فتح صفحة البحث</a>
            <a class="btn btn-outline" href="<?= e(url('/campaigns/beneficiaries?id=' . $cid . '&filter=unassigned')) ?>">غير المعيّنين</a>
            <?php if (!empty($canExport) && $totalBen > 0): ?>
            <a class="btn btn-outline" href="<?= e(url('/campaigns/export-candidates?id=' . $cid)) ?>">تنزيل الكشف (Excel)</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($canEdit) && $totalBen > 0): ?>
    <div class="card">
        <h2 class="panel-title" style="margin-top:0">إضافة مجموعة لغير المعيّنين</h2>
        <p class="text-muted" style="margin:0 0 0.75rem">
            Excel بنفس الأعمدة. الجدد يُضافون كغير معيّنين؛ المكرر بالهوية أو الاسم يُتجاهل.
        </p>
        <form method="post" action="<?= e(url('/campaigns/append-import')) ?>" enctype="multipart/form-data" class="actions-row" style="flex-wrap:wrap;align-items:flex-end;gap:0.75rem"
              data-confirm="إضافة الجدد فقط كغير معيّنين؟">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= $cid ?>">
            <div style="flex:1;min-width:220px">
                <label class="field-label" for="append-excel">ملف Excel</label>
                <input type="file" id="append-excel" name="excel_file" accept=".xlsx,.xls" required class="form-control">
            </div>
            <button type="submit" class="btn">إضافة</button>
        </form>
        <p class="text-muted" style="margin:0.85rem 0 0">
            رفعت ملفاً بالخطأ؟ من
            <a href="<?= e(url('/campaigns/beneficiaries?id=' . $cid . '&filter=unassigned_today')) ?>">بحث المرشحين — آخر دفعة غير معيّنين</a>
            : راجع الأسماء ثم احذف، أو ارفع نفس الملف واختر حذف المطابقين.
        </p>
    </div>
    <?php elseif ($totalBen === 0 && !empty($canEdit)): ?>
    <div class="card">
        <form method="post" action="<?= e(url('/campaigns/import')) ?>" enctype="multipart/form-data" class="actions-row">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= $cid ?>">
            <input type="file" name="excel_file" accept=".xlsx,.xls" required class="form-control" style="max-width:280px">
            <button type="submit" class="btn">رفع كشف المرشحين</button>
        </form>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($panel === 'downloads'): ?>
<section class="op-panel">
    <div class="card">
        <h2 class="panel-title" style="margin-top:0">بيانات الطرد</h2>
        <div class="grid-2">
            <p><strong>البداية:</strong> <?= e($campaign['delivery_start']) ?></p>
            <p><strong>النهاية:</strong> <?= e($campaign['delivery_end']) ?></p>
            <p><strong>المخزن:</strong> <?= e($campaign['warehouse_location']) ?></p>
            <p><strong>الدوام:</strong> <?= e(substr($campaign['work_start'], 0, 5)) ?> – <?= e(substr($campaign['work_end'], 0, 5)) ?></p>
        </div>
        <?php if (!empty($canExport) && $totalBen > 0): ?>
        <div class="actions-row" style="margin-top:0.75rem;flex-wrap:wrap;gap:0.5rem">
            <?php if ($isGenerated): ?>
            <a href="<?= e(url('/campaigns/export?id=' . $cid)) ?>" class="btn">كشوف التسليم المعتمدة</a>
            <?php endif; ?>
            <a href="<?= e(url('/campaigns/export-candidates?id=' . $cid)) ?>" class="btn btn-outline">كشف المرشحين بالكامل</a>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($isGenerated && !empty($canExport) && !empty($dayStats)): ?>
    <div class="card table-panel">
        <div class="panel-title">تنزيل يوم بيوم</div>
        <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>اليوم</th>
                    <th>التاريخ</th>
                    <th>المستفيدون</th>
                    <th>الرسائل</th>
                    <th>التسليم</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($dayStats as $day): ?>
                <?php $di = (int) ($day['day_index'] ?? 0); ?>
                <tr>
                    <td>اليوم <?= ar_digits($di) ?></td>
                    <td><?= e((string) ($day['delivery_date'] ?? '')) ?></td>
                    <td><?= ar_digits((int) ($day['cnt'] ?? 0)) ?></td>
                    <td>
                        <div class="actions-row" style="flex-wrap:wrap;gap:0.35rem">
                            <a class="btn btn-sm btn-outline" href="<?= e(url('/campaigns/export-messages?id=' . $cid . '&day=' . $di . '&network=jawwal')) ?>">جوال</a>
                            <a class="btn btn-sm btn-outline" href="<?= e(url('/campaigns/export-messages?id=' . $cid . '&day=' . $di . '&network=ooredoo')) ?>">أوريدو</a>
                        </div>
                    </td>
                    <td>
                        <a class="btn btn-sm btn-outline" href="<?= e(url('/campaigns/export-day?id=' . $cid . '&day=' . $di)) ?>">كشف اليوم</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php elseif (!$isGenerated): ?>
    <div class="card">
        <p class="text-muted" style="margin:0">اعتمد يوماً من باب «أيام التوزيع» أولاً حتى تظهر كشوف التسليم والرسائل.</p>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
