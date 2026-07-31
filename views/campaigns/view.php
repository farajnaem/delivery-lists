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

$openDays = $unassigned > 0 || $assigned === 0;
$openView = $isGenerated && $unassigned < 1;
$openStock = $isGenerated;

$viewActions = [];
if ($isGenerated && !empty($canViewStock)) {
    $viewActions[] = ['label' => 'المخزن والتسليم', 'url' => '/campaigns/stock?id=' . $cid, 'primary' => true];
}
if (!empty($canEdit)) {
    $viewActions[] = ['label' => 'تعديل', 'url' => '/campaigns/edit?id=' . $cid];
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
?>

<div class="grid-stats">
    <div class="stat-card">
        <div class="stat-label">إجمالي المستفيدين</div>
        <div class="stat-value"><?= ar_digits($totalBen) ?></div>
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

<?php if ($totalBen > 0): ?>
<div class="card">
    <h2 class="panel-title" style="margin-top:0">بحث في كشف المرشحين بالكامل</h2>
    <p class="text-muted" style="margin:0 0 0.75rem">بالاسم أو رقم الهوية أو الكود — كل من في الكشف المرفوع (معيّن / غير معيّن / مستلم) مع بيان حالته.</p>
    <form method="get" action="<?= e(url('/campaigns/beneficiaries')) ?>" class="actions-row" style="align-items:flex-end;gap:0.75rem;flex-wrap:wrap">
        <input type="hidden" name="id" value="<?= $cid ?>">
        <div style="flex:1;min-width:240px">
            <label class="field-label" for="campaign-beneficiary-search">بحث</label>
            <input type="search" id="campaign-beneficiary-search" name="q" class="form-control"
                   placeholder="الاسم أو رقم الهوية أو الكود" required autofocus>
        </div>
        <button type="submit" class="btn">بحث</button>
        <a class="btn btn-outline" href="<?= e(url('/campaigns/beneficiaries?id=' . $cid)) ?>">فتح الكشف</a>
    </form>
</div>
<?php endif; ?>

<nav class="op-hubs" aria-label="أبواب العملية">
    <a class="op-hub" href="#hub-days">
        <strong>أيام التوزيع</strong>
        <span>اعتماد يوم، إلغاء آخر يوم، توليد</span>
    </a>
    <a class="op-hub" href="#hub-candidates">
        <strong>كشف المرشحين</strong>
        <span>الكشف الكامل، إضافة مجموعة، حذف غير معيّن</span>
    </a>
    <a class="op-hub" href="#hub-view">
        <strong>العرض والتنزيل</strong>
        <span>كشوف التسليم، رسائل، يوم بيوم</span>
    </a>
    <a class="op-hub op-hub-primary" href="<?= $isGenerated && !empty($canViewStock) ? e(url('/campaigns/stock?id=' . $cid)) : '#hub-stock' ?>">
        <strong>المخزن والتسليم</strong>
        <span>متابعة، تسليم رسمي، مطابقة</span>
    </a>
</nav>

<details class="op-section" id="hub-days" <?= $openDays ? 'open' : '' ?>>
    <summary class="op-section-summary">
        <span class="op-section-title">1) أيام التوزيع</span>
        <span class="op-section-hint">توليد واعتماد الأيام فقط</span>
    </summary>
    <div class="op-section-body">

    <?php if (!empty($dayStats)): ?>
    <div class="card table-panel" style="box-shadow:none;border:1px solid var(--border)">
        <div class="table-toolbar">
            <div>
                <div class="panel-title">الأيام المعتمدة</div>
                <div class="panel-subtitle">كل يوم ثابت — يمكن إلغاء آخر يوم فقط ثم الذي قبله.</div>
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
    <div class="card" style="box-shadow:none;border:1px solid var(--border)">
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
    <div class="card" style="box-shadow:none;border:1px solid var(--border)">
        <h2 class="panel-title" style="margin-top:0">إجراءات إضافية</h2>
        <div class="actions-row">
            <?php if ($assigned === 0 && $totalBen > 0): ?>
            <form method="post" action="<?= e(url('/campaigns/generate')) ?>" data-confirm="توليد كل الأيام دفعة واحدة بالطاقة الافتراضية؟ الأفضل عادةً اعتماد يوم بيوم.">
                <?= \App\Csrf::field() ?>
                <input type="hidden" name="campaign_id" value="<?= $cid ?>">
                <button type="submit" class="btn btn-outline">توليد الكل دفعة واحدة</button>
            </form>
            <?php elseif ($totalBen === 0): ?>
            <form method="post" action="<?= e(url('/campaigns/import')) ?>" enctype="multipart/form-data" class="actions-row">
                <?= \App\Csrf::field() ?>
                <input type="hidden" name="campaign_id" value="<?= $cid ?>">
                <input type="file" name="excel_file" accept=".xlsx,.xls" required class="form-control" style="max-width:280px">
                <button type="submit" class="btn btn-outline">رفع Excel</button>
            </form>
            <?php else: ?>
            <p class="text-muted" style="margin:0">لا يوجد إجراء إضافي — اعتمد الأيام المتبقية من النموذج أعلاه.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($plan) && $assigned === 0): ?>
    <div class="card table-panel" style="box-shadow:none;border:1px solid var(--border)">
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

    </div>
</details>

<details class="op-section" id="hub-candidates" open>
    <summary class="op-section-summary">
        <span class="op-section-title">كشف المرشحين بالكامل</span>
        <span class="op-section-hint">نفس الكشف المرفوع + الحالة — البحث والحذف والإضافة</span>
    </summary>
    <div class="op-section-body">

    <div class="card" style="box-shadow:none;border:1px solid var(--border)">
        <h2 class="panel-title" style="margin-top:0">كشف المرشحين بالكامل</h2>
        <p class="text-muted" style="margin:0 0 0.75rem">
            هذا هو الكشف الأصلي في النظام (كل الأسماء والهويات المرفوعة) مع بيان الحالة:
            غير معيّن / قيد التسليم / مستلم. البحث أعلاه يفتح نفس الكشف.
        </p>
        <div class="actions-row" style="flex-wrap:wrap;gap:0.5rem">
            <a class="btn" href="<?= e(url('/campaigns/beneficiaries?id=' . $cid)) ?>">فتح الكشف والبحث</a>
            <a class="btn btn-outline" href="<?= e(url('/campaigns/beneficiaries?id=' . $cid . '&filter=unassigned')) ?>">غير المعيّنين فقط</a>
            <?php if (!empty($canExport) && $totalBen > 0): ?>
            <a class="btn btn-outline" href="<?= e(url('/campaigns/export-candidates?id=' . $cid)) ?>">تنزيل كشف المرشحين (Excel)</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($canEdit) && $totalBen > 0): ?>
    <div class="card" style="box-shadow:none;border:1px solid var(--border)">
        <h2 class="panel-title" style="margin-top:0">إضافة مجموعة لغير المعيّنين</h2>
        <p class="text-muted" style="margin:0 0 0.75rem">
            ارفع Excel بنفس أعمدة الكشف (الاسم، رقم الهوية، الجوال). يُضاف الجدد فقط كـ«غير معيّن»
            لأيام التسليم القادمة. أي صف بنفس رقم الهوية أو نفس الاسم بالكامل الموجود مسبقاً يُتجاهل.
            لا يمس المعيّنين أو المستلمين.
        </p>
        <form method="post" action="<?= e(url('/campaigns/append-import')) ?>" enctype="multipart/form-data" class="actions-row" style="flex-wrap:wrap;align-items:flex-end;gap:0.75rem"
              data-confirm="إضافة الأسماء الجديدة فقط كغير معيّنين؟ المكرر بنفس الهوية أو الاسم لن يُضاف.">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= $cid ?>">
            <div style="flex:1;min-width:220px">
                <label class="field-label" for="append-excel">ملف Excel</label>
                <input type="file" id="append-excel" name="excel_file" accept=".xlsx,.xls" required class="form-control">
            </div>
            <button type="submit" class="btn">إضافة للمجموعة غير المعيّنة</button>
        </form>
    </div>
    <?php endif; ?>

    </div>
</details>

<details class="op-section" id="hub-view" <?= $openView ? 'open' : '' ?>>
    <summary class="op-section-summary">
        <span class="op-section-title">2) العرض والتنزيل</span>
        <span class="op-section-hint">كشوف التسليم المعتمدة — ليس كشف المرشحين</span>
    </summary>
    <div class="op-section-body">

    <div class="card" style="box-shadow:none;border:1px solid var(--border)">
        <h2 class="panel-title" style="margin-top:0">بيانات الطرد</h2>
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
        <?php if ($isGenerated && !empty($canExport)): ?>
        <div class="actions-row" style="margin-top:0.75rem;flex-wrap:wrap;gap:0.5rem">
            <a href="<?= e(url('/campaigns/export?id=' . $cid)) ?>" class="btn">تنزيل كشوف التسليم المعتمدة (Excel)</a>
            <a href="<?= e(url('/campaigns/export-candidates?id=' . $cid)) ?>" class="btn btn-outline">تنزيل كشف المرشحين بالكامل</a>
        </div>
        <p class="text-muted" style="margin:0.5rem 0 0;font-size:0.9rem">
            «كشوف التسليم المعتمدة» = المعيّنون لأيام فقط (كشوف الطباعة). «كشف المرشحين بالكامل» = كل من رُفع في Excel مع الحالة.
        </p>
        <?php endif; ?>
    </div>

    <?php if ($isGenerated && !empty($canExport) && !empty($dayStats)): ?>
    <div class="card table-panel" style="box-shadow:none;border:1px solid var(--border)">
        <div class="panel-title">تنزيل يوم بيوم</div>
        <div class="panel-subtitle" style="margin-bottom:0.75rem">رسائل جوال / أوريدو وكشف التسليم لكل يوم.</div>
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
                            <a class="btn btn-sm btn-outline" href="<?= e(url('/campaigns/export-messages?id=' . $cid . '&day=' . $di . '&network=jawwal')) ?>">رسائل جوال</a>
                            <a class="btn btn-sm btn-outline" href="<?= e(url('/campaigns/export-messages?id=' . $cid . '&day=' . $di . '&network=ooredoo')) ?>">رسائل أوريدو</a>
                        </div>
                    </td>
                    <td>
                        <a class="btn btn-sm btn-outline" href="<?= e(url('/campaigns/export-day?id=' . $cid . '&day=' . $di)) ?>">تسليم يوم <?= ar_digits($di) ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($preview)): ?>
    <div class="card table-panel" style="box-shadow:none;border:1px solid var(--border)">
        <div class="panel-title">معاينة (أول 20)</div>
        <div class="panel-subtitle" style="margin-bottom:0.75rem">للبحث في الكل استخدم مربع البحث أعلى الصفحة.</div>
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

    </div>
</details>

<details class="op-section" id="hub-stock" <?= $openStock && !$openDays && !$openView ? 'open' : '' ?>>
    <summary class="op-section-summary">
        <span class="op-section-title">3) المخزن والتسليم</span>
        <span class="op-section-hint">تشغيل الميدان والمطابقة</span>
    </summary>
    <div class="op-section-body">

    <?php if ($isGenerated): ?>
    <div class="card" style="box-shadow:none;border:1px solid var(--border)">
        <p class="text-muted" style="margin:0 0 0.85rem">كل عمليات التسليم والمطابقة من صفحة المخزن — حتى لا تتكرر الأزرار هنا.</p>
        <div class="actions-row">
            <?php if (!empty($canViewStock)): ?>
            <a href="<?= e(url('/campaigns/stock?id=' . $cid)) ?>" class="btn">فتح متابعة المخزن</a>
            <?php endif; ?>
            <?php if (!empty($canDeliver)): ?>
            <a href="<?= e(url('/warehouse/deliver?campaign_id=' . $cid)) ?>" class="btn btn-outline">التسليم الرسمي</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (($deliveredTotal ?? 0) > 0): ?>
    <?php partial('partials/delivered-table', [
        'deliveredList' => $deliveredList ?? [],
        'totalDelivered' => $deliveredTotal ?? 0,
        'codePrefix' => $campaign['parcel_code'] ?? '',
        'codeSuffix' => $campaign['parcel_code_suffix'] ?? '',
    ]); ?>
    <?php else: ?>
    <div class="card" style="box-shadow:none;border:1px solid var(--border)">
        <h2 class="panel-title" style="margin-top:0">المستلمون</h2>
        <div class="empty-state">
            <strong>لا يوجد مستلمون بعد</strong>
            <span>بعد بدء التسليم ستظهر القائمة هنا وفي متابعة المخزن.</span>
        </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="card" style="box-shadow:none;border:1px solid var(--border)">
        <p class="text-muted" style="margin:0">اعتمد يوماً أولاً من باب «أيام التوزيع» حتى يفتح المخزن والتسليم.</p>
    </div>
    <?php endif; ?>

    </div>
</details>
