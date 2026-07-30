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
?>

<div class="grid-stats">
    <div class="stat-card">
        <div class="stat-label">إجمالي المستفيدين</div>
        <div class="stat-value"><?= ar_digits((int) ($stats['total'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">معيّنون / غير معيّنين</div>
        <div class="stat-value"><?= ar_digits($assigned) ?> / <?= ar_digits($unassigned) ?></div>
        <div class="stat-meta">الأيام المعتمدة لا تُعاد كتابتها</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">أيام معتمدة</div>
        <div class="stat-value"><?= ar_digits(count($dayStats)) ?></div>
        <div class="stat-meta">افتراضي: <?= ar_digits($numWindows) ?> شباك × <?= ar_digits($perWindow) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">طاقة يومية افتراضية</div>
        <div class="stat-value"><?= ar_digits($dailyCapacity) ?></div>
        <?php if (!empty($deliveryStats)): ?>
        <div class="stat-meta">رصيد: <?= ar_digits((int) ($deliveryStats['balance'] ?? 0)) ?></div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($deliveryStats)): ?>
<div class="grid-stats" style="grid-template-columns:repeat(2,minmax(0,1fr))">
    <div class="stat-card">
        <div class="stat-label">مُسلَّم / افتتاحي</div>
        <div class="stat-value"><?= ar_digits((int) ($deliveryStats['delivered'] ?? 0)) ?> / <?= ar_digits((int) ($deliveryStats['opening_quantity'] ?? 0)) ?></div>
        <?php
        $openQ = max(1, (int) ($deliveryStats['opening_quantity'] ?? 1));
        $delPct = (int) round(((int) ($deliveryStats['delivered'] ?? 0) / $openQ) * 100);
        ?>
        <div class="progress"><span style="width:<?= min(100, $delPct) ?>%"></span></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">رصيد المخزن</div>
        <div class="stat-value"><?= ar_digits((int) ($deliveryStats['balance'] ?? 0)) ?></div>
    </div>
</div>
<?php endif; ?>

<?php if ((int) ($stats['total'] ?? 0) > 0): ?>
<div class="card">
    <h2 class="panel-title" style="margin-top:0">بحث في كامل الطرد</h2>
    <p class="text-muted" style="margin:0 0 0.75rem">بالاسم أو رقم الهوية أو الكود — المعيّنين وغير المعيّنين والمستلمين.</p>
    <form method="get" action="<?= e(url('/campaigns/beneficiaries')) ?>" class="actions-row" style="align-items:flex-end;gap:0.75rem;flex-wrap:wrap">
        <input type="hidden" name="id" value="<?= (int) $campaign['id'] ?>">
        <div style="flex:1;min-width:240px">
            <label class="field-label" for="campaign-beneficiary-search">بحث</label>
            <input type="search" id="campaign-beneficiary-search" name="q" class="form-control"
                   placeholder="مثال: رامز سكر أو 802469809 أو 1084423" required autofocus>
        </div>
        <button type="submit" class="btn">بحث</button>
    </form>
</div>
<?php endif; ?>

<?php if (!empty($dayStats)): ?>
<?php
$lastDayIndex = 0;
foreach ($dayStats as $dRow) {
    $lastDayIndex = max($lastDayIndex, (int) ($dRow['day_index'] ?? 0));
}
$lastDayMeta = null;
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
?>
<div class="card table-panel">
    <div class="table-toolbar">
        <div>
            <div class="panel-title">الأيام المعتمدة</div>
            <div class="panel-subtitle">كل يوم ثابت: أكواده ورسائله وكشوفه لا تتأثر باعتماد يوم لاحق. يمكن إلغاء آخر يوم فقط ثم الذي قبله.</div>
        </div>
        <?php if (!empty($canEdit) && $lastDayIndex > 0): ?>
        <form method="post" action="<?= e(url('/campaigns/cancel-last-day')) ?>"
              data-confirm="<?= e($lastDayConfirm) ?>">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
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

<?php if (!empty($plan) && $assigned === 0): ?>
<div class="card table-panel">
    <div class="table-toolbar">
        <div>
            <div class="panel-title">معاينة خطة كاملة (اختياري)</div>
            <div class="panel-subtitle">
                لو ولّدت الكل دفعة واحدة بالطاقة الافتراضية:
                <?= ar_digits((int) $plan['total']) ?> ÷ <?= ar_digits((int) ($plan['daily_capacity'] ?? $dailyCapacity)) ?>
                = <?= ar_digits((int) $plan['num_days']) ?> أيام
            </div>
        </div>
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

<div class="card">
    <div class="panel-header">
        <div>
            <h2 class="panel-title">بيانات الطرد والمخزن</h2>
        </div>
    </div>
    <div class="grid-2">
        <p><strong>تاريخ البداية:</strong> <?= e($campaign['delivery_start']) ?></p>
        <p><strong>تاريخ النهاية:</strong> <?= e($campaign['delivery_end']) ?></p>
        <p><strong>موقع المخزن:</strong> <?= e($campaign['warehouse_location']) ?></p>
        <p><strong>ساعات العمل:</strong> <?= e(substr($campaign['work_start'], 0, 5)) ?> – <?= e(substr($campaign['work_end'], 0, 5)) ?></p>
        <p><strong>الحالة:</strong>
            <?php if ($isGenerated): ?>
            <span class="badge badge-ok">مُولَّد <?= e($campaign['generated_at'] ?? '') ?></span>
            <?php if (!CampaignService::isDeliveryOpen($campaign)): ?>
            <span class="badge badge-pending">التسليم مُنهى</span>
            <?php else: ?>
            <span class="badge badge-ok">التسليم مفتوح</span>
            <?php endif; ?>
            <?php else: ?>
            <span class="badge badge-pending">مسودة — اعتمد يوماً أو ولّد الكل</span>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if (!empty($canEdit) && (int) ($stats['total'] ?? 0) > 0 && $unassigned > 0): ?>
<?php
$defaultDayWorkStart = substr((string) ($campaign['work_start'] ?? '09:00'), 0, 5);
$defaultDayWorkEnd = substr((string) ($campaign['work_end'] ?? '15:00'), 0, 5);
?>
<div class="card">
    <h2 class="panel-title" style="margin-bottom:0.35rem">اعتماد يوم توزيع</h2>
    <p class="text-muted" style="margin-bottom:1rem">
        حدّد مستفيدي هذا اليوم والشبابيك وساعات الدوام. الأيام السابقة تبقى كما هي بدون تغيير أكواد أو رسائل أو مواعيد.
        المتبقي غير المعيّن: <strong><?= ar_digits($unassigned) ?></strong>
    </p>
    <form method="post" action="<?= e(url('/campaigns/generate-day')) ?>" class="grid-2" data-confirm="اعتماد هذا اليوم؟ لن يُمسّ أي يوم سابق.">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
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
            <span class="field-hint">يُوزَّع العدد على الشبابيك بالتساوي قدر الإمكان.</span>
        </div>
        <div>
            <label class="field-label">بداية الدوام لهذا اليوم *</label>
            <input type="time" name="day_work_start" class="form-control" required value="<?= e($defaultDayWorkStart) ?>">
        </div>
        <div>
            <label class="field-label">نهاية الدوام لهذا اليوم *</label>
            <input type="time" name="day_work_end" class="form-control" required value="<?= e($defaultDayWorkEnd) ?>">
            <span class="field-hint">افتراضي من إعدادات العملية — يمكن تغييره لهذا اليوم فقط.</span>
        </div>
        <div style="display:flex;align-items:flex-end">
            <button type="submit" class="btn">اعتماد اليوم وتوليد كشوفه</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="panel-header">
        <div>
            <h2 class="panel-title">عمليات الكشوف</h2>
            <div class="panel-subtitle">التوصية: اعتمد يوماً بيوم. التوليد الكامل للكل دفعة واحدة فقط إن لم يُعتمد أي يوم بعد.</div>
        </div>
    </div>
    <div class="actions-row">
        <?php if (!empty($canEdit)): ?>
        <?php if ($assigned === 0 && (int) ($stats['total'] ?? 0) > 0): ?>
        <form method="post" action="<?= e(url('/campaigns/generate')) ?>" data-confirm="توليد كل الأيام دفعة واحدة بالطاقة الافتراضية؟ الأفضل عادةً اعتماد يوم بيوم.">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
            <button type="submit" class="btn btn-outline">توليد الكل دفعة واحدة</button>
        </form>
        <?php elseif ((int) ($stats['total'] ?? 0) === 0): ?>
        <form method="post" action="<?= e(url('/campaigns/import')) ?>" enctype="multipart/form-data" class="actions-row">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
            <input type="file" name="excel_file" accept=".xlsx,.xls" required class="form-control" style="max-width:280px">
            <button type="submit" class="btn btn-outline">رفع Excel</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($isGenerated && !empty($canExport)): ?>
        <a href="<?= e(url('/campaigns/export?id=' . (int) $campaign['id'])) ?>" class="btn">تنزيل Excel الكامل</a>
        <?php endif; ?>

        <?php if ($isGenerated && !empty($canViewStock) && !empty($canExport)): ?>
        <a href="<?= e(url('/campaigns/export-deliveries?id=' . (int) $campaign['id'])) ?>" class="btn btn-outline">تقرير التسليمات</a>
        <?php endif; ?>
    </div>
    <p class="text-muted" style="margin-top:0.75rem">
        Excel الكامل: <strong>الكشف الإجمالي</strong> + كشوف التسليم للأيام المعتمدة.
        الرسائل وكشوف يوم معيّن تُنزَّل من الجدول أدناه (يوم بيوم).
        للبحث عن مستفيد: استخدم مربع البحث أعلى الصفحة.
    </p>
</div>

<?php if ($isGenerated && !empty($canExport) && !empty($dayStats)): ?>
<div class="card table-panel" data-table-filterable>
    <div class="table-toolbar">
        <div>
            <div class="panel-title">تنزيل يوم بيوم — رسائل وكشوف تسليم</div>
            <div class="panel-subtitle">
                ملف جوال وملف أوريدو منفصلان — صف أول: الجوال | الرسالة ثم البيانات.
                المتأخر عن موعده يبقى قابلاً للتسليم حتى إنهاء العملية.
            </div>
        </div>
    </div>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>اليوم</th>
                <th>التاريخ</th>
                <th>المستفيدون</th>
                <th>كشوف الرسائل</th>
                <th>كشوف التسليم</th>
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
                        <a class="btn btn-sm btn-outline" href="<?= e(url('/campaigns/export-messages?id=' . (int) $campaign['id'] . '&day=' . $di . '&network=jawwal')) ?>">رسائل جوال</a>
                        <a class="btn btn-sm btn-outline" href="<?= e(url('/campaigns/export-messages?id=' . (int) $campaign['id'] . '&day=' . $di . '&network=ooredoo')) ?>">رسائل أوريدو</a>
                    </div>
                </td>
                <td>
                    <a class="btn btn-sm btn-outline" href="<?= e(url('/campaigns/export-day?id=' . (int) $campaign['id'] . '&day=' . $di)) ?>">تسليم يوم <?= ar_digits($di) ?></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($preview)): ?>
<div class="card table-panel">
    <div class="table-toolbar">
        <div>
            <div class="panel-title">معاينة (أول 20 مستفيد<?= $isGenerated ? ' — بعد التعيين' : '' ?>)</div>
            <div class="panel-subtitle">للبحث في الكل: مربع «بحث في كامل الطرد» أعلى الصفحة.</div>
        </div>
    </div>
    <div class="table-wrap">
    <table class="data-table">
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
            <td><?= e($b['display_code'] ?? '') ?></td>
            <td>
                <?php if (($b['receipt_status'] ?? '') === 'مستلم'): ?>
                <span class="badge-delivered-inline">مستلم</span>
                <?php else: ?>
                <span class="badge-pending-inline">قيد التسليم</span>
                <?php endif; ?>
            </td>
            <td><?= e($b['delivery_date'] ?? '') ?></td>
            <td><?= e((string) ($b['window_num'] ?? '')) ?></td>
            <td><?= e($b['time_from'] ?? '') ?></td>
            <td><?= e($b['time_to'] ?? '') ?></td>
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
    'codePrefix' => $campaign['parcel_code'] ?? '',
    'codeSuffix' => $campaign['parcel_code_suffix'] ?? '',
]); ?>
<?php elseif ($isGenerated): ?>
<div class="card">
    <h2 class="panel-title">المستلمون</h2>
    <div class="empty-state">
        <strong>لا يوجد مستلمون بعد</strong>
        <span>ستظهر القائمة بعد تسجيل التسليم من <a href="<?= e(url('/warehouse/deliver?campaign_id=' . (int) $campaign['id'])) ?>">صفحة المخزن</a>.</span>
    </div>
</div>
<?php endif; ?>
