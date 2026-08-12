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
// روابط قديمة → أيام التوزيع (يشمل التنزيل)
if ($panel === 'downloads') {
    $panel = 'days';
}
if ($panel === '') {
    $panel = 'search';
}

$viewActions = [];
if (!empty($canEdit)) {
    $viewActions[] = ['label' => 'تعديل العملية', 'url' => '/campaigns/edit?id=' . $cid];
}
if ($isGenerated && !empty($canViewStock)) {
    $viewActions[] = ['label' => 'المخزن', 'url' => '/campaigns/stock?id=' . $cid];
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
    </div>
    <div class="stat-card">
        <div class="stat-label">رصيد المخزن</div>
        <div class="stat-value"><?= ar_digits((int) ($deliveryStats['balance'] ?? $dailyCapacity)) ?></div>
    </div>
</div>

<nav class="op-hubs" aria-label="أبواب العملية">
    <a class="op-hub<?= $panel === 'search' ? ' op-hub-active' : '' ?>" href="<?= e($panelUrl('search')) ?>">
        <strong>1) بحث الأشخاص</strong>
        <span>هوية / اسم / مجموعة — حالة وإجراء</span>
    </a>
    <a class="op-hub<?= $panel === 'days' ? ' op-hub-active' : '' ?>" href="<?= e($panelUrl('days')) ?>">
        <strong>2) الأيام والكشوف</strong>
        <span>اعتماد يوم، إلغاء، طباعة الكشوف</span>
    </a>
    <a class="op-hub<?= $panel === 'candidates' ? ' op-hub-active' : '' ?>" href="<?= e($panelUrl('candidates')) ?>">
        <strong>3) إضافة مرشحين</strong>
        <span>رفع Excel أو إضافة مجموعة</span>
    </a>
</nav>

<?php if ($panel === 'search'): ?>
<section class="op-panel">
    <div class="card">
        <h2 class="panel-title" style="margin-top:0">بحث الأشخاص</h2>
        <p class="text-muted" style="margin:0 0 0.75rem">
            ابحث عن شخص أو الصق عدة هويات — تظهر الهوية والجوال والاسم والمخيم والحالة، مع حذف أو تسجيل استلام.
        </p>
        <?php if ($totalBen > 0): ?>
        <form method="post" action="<?= e(url('/campaigns/beneficiaries/search')) ?>" class="actions-row" style="align-items:flex-end;gap:0.75rem;flex-wrap:wrap">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="id" value="<?= $cid ?>">
            <div style="flex:1;min-width:260px">
                <label class="field-label" for="campaign-beneficiary-search">هوية أو اسم أو مجموعة هويات</label>
                <textarea id="campaign-beneficiary-search" name="q" class="form-control" rows="3"
                          placeholder="هوية واحدة، أو عدة هويات (سطر أو فاصلة)" autofocus required></textarea>
            </div>
            <button type="submit" class="btn">بحث</button>
        </form>
        <?php else: ?>
        <p class="text-muted" style="margin:0">لا مرشحين بعد — ارفع الكشف من تبويب «إضافة مرشحين».</p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($panel === 'days'): ?>
<section class="op-panel">

    <?php if ($isGenerated && !empty($canExport)): ?>
    <div class="card" style="border:2px solid var(--accent, #2563eb)">
        <h2 class="panel-title" style="margin-top:0">الكشوف النهائية / المطابقة</h2>
        <p class="text-muted" style="margin:0 0 0.75rem">بعد اعتماد الأيام أو انتهاء الطرد — من هنا تطبع أو تنزّل الكشوف.</p>
        <div class="actions-row" style="flex-wrap:wrap;gap:0.5rem">
            <a href="<?= e(url('/campaigns/export?id=' . $cid)) ?>" class="btn">كشوف التسليم المعتمدة (الكل)</a>
            <?php if (!empty($canViewStock)): ?>
            <a href="<?= e(url('/campaigns/stock?id=' . $cid)) ?>" class="btn btn-outline">مطابقة التسليم والمخزن</a>
            <?php endif; ?>
            <a href="<?= e(url('/campaigns/export-candidates?id=' . $cid)) ?>" class="btn btn-outline">كشف المرشحين Excel</a>
            <a href="<?= e(url('/campaigns/export-deliveries?id=' . $cid)) ?>" class="btn btn-outline">تقرير المستلمين</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($dayStats)): ?>
    <div class="card table-panel">
        <div class="table-toolbar">
            <div>
                <div class="panel-title">الأيام المعتمدة</div>
                <div class="panel-subtitle">مستلم هذا اليوم = من مخطط ذلك اليوم واستلموا في تاريخه. مستلم من المتأخرين = استلموا في هذا التاريخ وموعدهم يوم سابق. <strong>إجمالي التسليم</strong> = ما خرج من المخزن ذلك اليوم (= مجموع أمناء المخزن). غير مستلم = من كشف اليوم وما استلموا (للمطابقة). إلغاء آخر يوم فقط.</div>
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
                <tr>
                    <th>اليوم</th>
                    <th>التاريخ</th>
                    <th>العدد</th>
                    <th>مستلم هذا اليوم</th>
                    <th>مستلم من المتأخرين</th>
                    <th>إجمالي التسليم</th>
                    <th>غير مستلم</th>
                    <th>الشبابيك</th>
                    <th>كشف التسليم</th>
                    <th>رسائل</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $sumPending = 0;
            $sumDeliveredTotal = 0;
            foreach ($dayStats as $day):
                $di = (int) ($day['day_index'] ?? 0);
                $deliveredDay = (int) ($day['delivered'] ?? 0);
                $deliveredLate = (int) ($day['delivered_late'] ?? 0);
                $deliveredTotal = (int) ($day['delivered_total'] ?? ($deliveredDay + $deliveredLate));
                $pendingDay = (int) ($day['pending'] ?? 0);
                $sumPending += $pendingDay;
                $sumDeliveredTotal += $deliveredTotal;
            ?>
            <tr>
                <td>
                    <?= ar_digits($di) ?>
                    <?php if ($di === $lastDayIndex): ?>
                    <span class="badge badge-pending">آخر</span>
                    <?php endif; ?>
                </td>
                <td><?= e((string) ($day['delivery_date'] ?? '')) ?></td>
                <td><?= ar_digits((int) ($day['cnt'] ?? 0)) ?></td>
                <td><?= ar_digits($deliveredDay) ?></td>
                <td><?= ar_digits($deliveredLate) ?></td>
                <td><?= ar_digits($deliveredTotal) ?></td>
                <td><?= ar_digits($pendingDay) ?></td>
                <td><?= ar_digits((int) ($day['windows'] ?? 0)) ?></td>
                <td>
                    <?php if (!empty($canExport) && $isGenerated): ?>
                    <a class="btn btn-sm" href="<?= e(url('/campaigns/export-day?id=' . $cid . '&day=' . $di)) ?>">تنزيل</a>
                    <?php else: ?>
                    —
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($canExport) && $isGenerated): ?>
                    <div class="actions-row" style="flex-wrap:wrap;gap:0.35rem">
                        <a class="btn btn-sm btn-outline" href="<?= e(url('/campaigns/export-messages?id=' . $cid . '&day=' . $di . '&network=jawwal')) ?>">جوال</a>
                        <a class="btn btn-sm btn-outline" href="<?= e(url('/campaigns/export-messages?id=' . $cid . '&day=' . $di . '&network=ooredoo')) ?>">أوريدو</a>
                    </div>
                    <?php else: ?>
                    —
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if ($dayStats !== []): ?>
            <tr style="font-weight:600;background:var(--surface-2, #f8fafc)">
                <td colspan="5">المجموع</td>
                <td><?= ar_digits($sumDeliveredTotal) ?></td>
                <td><?= ar_digits($sumPending) ?></td>
                <td colspan="3" class="text-muted" style="font-weight:400">غير المستلمين للمطابقة مرة واحدة</td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($canEdit) && $totalBen > 0 && $unassigned > 0): ?>
    <div class="card">
        <h2 class="panel-title" style="margin-bottom:0.35rem">اعتماد يوم توزيع جديد</h2>
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
                <label class="field-label">عدد المستفيدين *</label>
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
            <div>
                <label class="field-label">طريقة اختيار الأسماء *</label>
                <select name="day_selection_mode" class="form-control" required>
                    <option value="registration">حسب ترتيب التسجيل (الأقدم أولاً)</option>
                    <option value="random">اختيار عشوائي من غير المعيّنين</option>
                </select>
            </div>
            <div style="display:flex;align-items:flex-end">
                <button type="submit" class="btn">اعتماد اليوم</button>
            </div>
        </form>
    </div>
    <?php elseif ($totalBen === 0 && !empty($canEdit)): ?>
    <div class="card">
        <p class="text-muted" style="margin:0">ارفع كشف المرشحين من تبويب «إضافة مرشحين» أولاً.</p>
    </div>
    <?php elseif ($unassigned < 1 && $assigned > 0): ?>
    <div class="card">
        <p class="text-muted" style="margin:0">لا يوجد غير معيّنين — كل المرشحين معيّنون لأيام. استخدم الكشوف أعلاه للطباعة.</p>
    </div>
    <?php endif; ?>

    <?php if (!empty($canEdit) && $assigned === 0 && $totalBen > 0): ?>
    <details class="card" style="padding:1rem">
        <summary style="cursor:pointer;color:var(--muted)">خيار متقدم: توليد كل الأيام دفعة واحدة</summary>
        <form method="post" action="<?= e(url('/campaigns/generate')) ?>" style="margin-top:0.75rem"
              data-confirm="توليد كل الأيام دفعة واحدة؟ الأفضل عادةً اعتماد يوم بيوم.">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= $cid ?>">
            <button type="submit" class="btn btn-outline">توليد الكل</button>
        </form>
    </details>
    <?php endif; ?>

</section>
<?php endif; ?>

<?php if ($panel === 'candidates'): ?>
<section class="op-panel">
    <?php if ($totalBen === 0 && !empty($canEdit)): ?>
    <div class="card">
        <h2 class="panel-title" style="margin-top:0">رفع كشف المرشحين</h2>
        <p class="text-muted" style="margin:0 0 0.75rem">Excel: الاسم، رقم الهوية، الجوال، ومركز الإيواء (اختياري).</p>
        <form method="post" action="<?= e(url('/campaigns/import')) ?>" enctype="multipart/form-data" class="actions-row">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= $cid ?>">
            <input type="file" name="excel_file" accept=".xlsx,.xls" required class="form-control" style="max-width:280px">
            <button type="submit" class="btn">رفع الكشف</button>
        </form>
    </div>
    <?php elseif (!empty($canEdit) && $totalBen > 0): ?>
    <div class="card">
        <h2 class="panel-title" style="margin-top:0">إضافة مجموعة لغير المعيّنين</h2>
        <p class="text-muted" style="margin:0 0 0.75rem">
            الجدد يُضافون كغير معيّنين. المكرر بالهوية أو الاسم يُتجاهل.
        </p>
        <form method="post" action="<?= e(url('/campaigns/append-import')) ?>" enctype="multipart/form-data"
              class="actions-row" style="flex-wrap:wrap;align-items:flex-end;gap:0.75rem"
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
            رفعت بالخطأ؟
            <a href="<?= e(url('/campaigns/beneficiaries?id=' . $cid . '&filter=unassigned_today')) ?>">احذف آخر دفعة غير معيّنين</a>
            أو ارفع نفس الملف من صفحة البحث واختر حذف المطابقين.
        </p>
    </div>
    <?php else: ?>
    <div class="card">
        <p class="text-muted" style="margin:0">لا صلاحية لإضافة مرشحين.</p>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
