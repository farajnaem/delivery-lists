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
            <?php
            $gateBadge = is_array($deliveryGate ?? null) ? $deliveryGate : \App\CampaignService::deliveryGateStatus($campaign);
            $st = (string) ($gateBadge['state'] ?? '');
            ?>
            <?php if ($st === 'open'): ?>
            <span class="badge badge-ok"><?= e((string) ($gateBadge['label'] ?? 'مفتوح')) ?></span>
            <?php else: ?>
            <span class="badge badge-pending"><?= e((string) ($gateBadge['label'] ?? 'مغلق')) ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$gate = is_array($deliveryGate ?? null) ? $deliveryGate : \App\CampaignService::deliveryGateStatus($campaign);
$gateState = (string) ($gate['state'] ?? '');
$canManageGate = !empty($canStartDelivery) || !empty($canCloseDelivery);
$scheduleDefault = date('Y-m-d') . 'T09:00';
$opensRaw = trim((string) ($campaign['delivery_opens_at'] ?? ''));
if ($opensRaw !== '') {
    $ots = strtotime($opensRaw);
    if ($ots !== false) {
        $scheduleDefault = date('Y-m-d\TH:i', $ots);
    }
}
?>

<?php if ($canManageGate): ?>
<div class="card">
    <h2 class="panel-title" style="margin-bottom:0.5rem">حالة التسليم</h2>
    <p class="text-muted" style="margin-bottom:1rem">
        الحالة: <strong><?= e((string) ($gate['label'] ?? '')) ?></strong>
        — <?= e((string) ($gate['detail'] ?? '')) ?>
    </p>

    <?php if (!empty($canStartDelivery) && $gateState !== 'closed'): ?>
    <div class="grid-2" style="margin-bottom:1rem;align-items:end">
        <form method="post" action="<?= e(url('/campaigns/start-delivery')) ?>" data-confirm="بدء التسليم الآن؟ سيتمكن أمناء المخزن من التسجيل فوراً.">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
            <input type="hidden" name="opens_at" value="">
            <button type="submit" class="btn">بدء التسليم الآن</button>
        </form>
        <?php if (in_array($gateState, ['open', 'scheduled'], true)): ?>
        <form method="post" action="<?= e(url('/campaigns/lock-delivery')) ?>" data-confirm="قفل التسليم مؤقتاً؟">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
            <button type="submit" class="btn btn-outline">قفل مؤقت</button>
        </form>
        <?php endif; ?>
    </div>
    <form method="post" action="<?= e(url('/campaigns/start-delivery')) ?>" class="actions-row" data-confirm="جدولة بدء التسليم لهذا الوقت؟">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <label class="field-label" style="margin:0">جدولة ساعة البدء</label>
        <input type="datetime-local" name="opens_at" class="form-control" style="max-width:240px" required value="<?= e($scheduleDefault) ?>">
        <button type="submit" class="btn btn-outline">حفظ الجدولة</button>
    </form>
    <p class="text-muted" style="margin-top:0.75rem;font-size:0.9rem">
        بعد اعتماد يوم توزيع تبقى العملية مقفلة حتى تبدأها أو تجدولها — حتى لا يُسلَّم أحد قبل الموعد.
    </p>
    <?php endif; ?>

    <?php if (!empty($canCloseDelivery)): ?>
    <hr style="border:0;border-top:1px solid #e5e8e8;margin:1.1rem 0">
    <?php if ($gateState !== 'closed'): ?>
    <form method="post" action="<?= e(url('/campaigns/close-delivery')) ?>" data-confirm="إنهاء عملية التسليم نهائياً؟ لن يستطيع أمين المخزن تسجيل تسليمات جديدة.">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <button type="submit" class="btn btn-outline">إنهاء عملية التسليم</button>
    </form>
    <?php else: ?>
    <form method="post" action="<?= e(url('/campaigns/reopen-delivery')) ?>">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <button type="submit" class="btn btn-outline">إعادة فتح التسليم (مدير)</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$cidStock = (int) $campaign['id'];
$todayDelivered = (int) ($stock['today_delivered'] ?? 0);
$plannedToday = (int) ($stock['planned_today'] ?? 0);
$review = is_array($reviewCounts ?? null) ? $reviewCounts : null;
?>
<div class="card">
    <h2 class="panel-title" style="margin-top:0">مطابقة يومية — النظام ↔ الميدان</h2>
    <p class="text-muted" style="margin:0 0 0.85rem">
        قارن <strong>عدد النظام</strong> مع العدّ الفعلي في المخزن. الورقة والتوقيع وحدها لا تكفي عند اختلاف البحث بالهوية/الكود.
    </p>
    <div class="grid-stats" style="margin-bottom:0.75rem">
        <div class="stat-card">
            <div class="stat-label">مُسلَّم اليوم (النظام)</div>
            <div class="stat-value"><?= ar_digits($todayDelivered) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">مخطط لهذا التاريخ</div>
            <div class="stat-value"><?= ar_digits($plannedToday) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">الفرق (مُسلَّم − مخطط)</div>
            <div class="stat-value"><?= ar_digits($todayDelivered - $plannedToday) ?></div>
        </div>
    </div>
    <div class="actions-row" style="flex-wrap:wrap;gap:0.5rem">
        <a class="btn" href="<?= e(url('/campaigns/beneficiaries?id=' . $cidStock . '&filter=today')) ?>">قائمة مستلمي اليوم</a>
        <a class="btn btn-outline" href="<?= e(url('/campaigns/export-deliveries?id=' . $cidStock)) ?>">تقرير التسليمات Excel</a>
        <?php if ($review !== null): ?>
        <a class="btn btn-outline" href="<?= e(url('/campaigns/beneficiaries?id=' . $cidStock . '&filter=anomaly')) ?>">
            تسليم بلا تعيين (<?= ar_digits((int) ($review['anomaly'] ?? 0)) ?>)
        </a>
        <a class="btn btn-outline" href="<?= e(url('/campaigns/beneficiaries?id=' . $cidStock . '&filter=arabic_id')) ?>">
            هوية بأرقام عربية (<?= ar_digits((int) ($review['arabic_id'] ?? 0)) ?>)
        </a>
        <a class="btn btn-outline" href="<?= e(url('/campaigns/beneficiaries?id=' . $cidStock . '&filter=delivered_no_mobile')) ?>">
            مستلم بلا جوال (<?= ar_digits((int) ($review['delivered_no_mobile'] ?? 0)) ?>)
        </a>
        <?php endif; ?>
    </div>
    <p class="text-muted" style="margin:0.75rem 0 0;font-size:0.9rem">
        طريقة عملية: اطبع/افتح «مستلمو اليوم» وعدّ الأسماء مع المخزن. أي زيادة عن المخطط أو أسماء ليست في كشف اليوم تحتاج مراجعة فورية.
    </p>
</div>

<?php if (!empty($canCloseDelivery)): ?>
<?php
$keepers = $keeperStats['keepers'] ?? [];
$bulkStats = $keeperStats['bulk'] ?? ['today' => 0, 'total' => 0];
$hasBulk = ((int) ($bulkStats['total'] ?? 0)) > 0;
$keeperTodaySum = 0;
$keeperTotalSum = 0;
foreach ($keepers as $k) {
    $keeperTodaySum += (int) ($k['today'] ?? 0);
    $keeperTotalSum += (int) ($k['total'] ?? 0);
}
?>
<div class="card table-panel">
    <div class="table-toolbar">
        <div>
            <div class="panel-title">التسليم حسب أمين المخزن</div>
            <p class="text-muted" style="margin:0.35rem 0 0;font-size:0.9rem">عدد ما سجّله كل حساب ميدانياً — التسليم الجماعي منفصل أدناه.</p>
        </div>
    </div>
    <?php if (empty($keepers) && !$hasBulk): ?>
    <div class="empty-state">
        <strong>لا توجد تسليمات مسجّلة بعد</strong>
        <span>ستظهر هنا أعداد كل أمين مخزن فور بدء التسليم.</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>أمين المخزن</th>
                <th>اليوم</th>
                <th>الإجمالي</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($keepers as $k): ?>
            <tr>
                <td><?= e($k['name'] ?? 'غير معروف') ?></td>
                <td><?= ar_digits((int) ($k['today'] ?? 0)) ?></td>
                <td><?= ar_digits((int) ($k['total'] ?? 0)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($hasBulk): ?>
            <tr>
                <td>تسليم جماعي (مدير)</td>
                <td><?= ar_digits((int) ($bulkStats['today'] ?? 0)) ?></td>
                <td><?= ar_digits((int) ($bulkStats['total'] ?? 0)) ?></td>
            </tr>
        <?php endif; ?>
        </tbody>
        <?php if (count($keepers) > 1 || $hasBulk): ?>
        <tfoot>
            <tr>
                <th>المجموع</th>
                <th><?= ar_digits($keeperTodaySum + (int) ($bulkStats['today'] ?? 0)) ?></th>
                <th><?= ar_digits($keeperTotalSum + (int) ($bulkStats['total'] ?? 0)) ?></th>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
    </div>
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
