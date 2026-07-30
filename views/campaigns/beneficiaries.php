<?php
use App\CampaignService;
use App\DeliveryService;

$codePrefix = (string) ($campaign['parcel_code'] ?? '');
$codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
$q = (string) ($q ?? '');
$page = max(1, (int) ($page ?? 1));
$total = (int) ($total ?? 0);
$perPage = max(1, (int) ($perPage ?? 100));
$totalPages = max(1, (int) ceil($total / $perPage));
$rows = $rows ?? [];
$canManualDeliver = !empty($canManualDeliver);

page_header(
    'كشف المستفيدين — ' . (string) $campaign['name'],
    [
        ['label' => 'العمليات', 'url' => '/'],
        ['label' => (string) $campaign['name'], 'url' => '/campaigns/view?id=' . (int) $campaign['id']],
        ['label' => 'كشف المستفيدين'],
    ],
    [
        ['label' => 'عودة للعملية', 'url' => '/campaigns/view?id=' . (int) $campaign['id']],
        ['label' => 'Excel الكامل', 'url' => '/campaigns/export?id=' . (int) $campaign['id']],
    ],
    'بحث بالهوية أو الاسم أو الكود — يشمل المعيّنين وغير المعيّنين والمستلمين'
);
?>

<div class="card">
    <form method="get" action="<?= e(url('/campaigns/beneficiaries')) ?>" class="actions-row" style="align-items:flex-end;gap:0.75rem;flex-wrap:wrap">
        <input type="hidden" name="id" value="<?= (int) $campaign['id'] ?>">
        <div style="flex:1;min-width:220px">
            <label class="field-label">بحث</label>
            <input type="search" name="q" class="form-control" value="<?= e($q) ?>" placeholder="رقم الهوية أو الاسم أو الكود" autofocus>
        </div>
        <button type="submit" class="btn">بحث</button>
        <?php if ($q !== ''): ?>
        <a class="btn btn-ghost" href="<?= e(url('/campaigns/beneficiaries?id=' . (int) $campaign['id'])) ?>">مسح</a>
        <?php endif; ?>
    </form>
    <p class="text-muted" style="margin:0.75rem 0 0">
        النتيجة: <strong><?= ar_digits($total) ?></strong>
        — صفحة <?= ar_digits($page) ?> من <?= ar_digits($totalPages) ?>
    </p>
</div>

<?php if ($canManualDeliver): ?>
<div class="card">
    <h2 class="panel-title" style="margin-top:0">استلام يدوي (مدير النظام)</h2>
    <p class="text-muted" style="margin:0 0 0.75rem">
        لمن استلم فعلياً ولم يظهر في كشوف الرسائل/التسليم، أو حالته «غير معيّن».
        بعد التسجيل لن يُعاد تعيينه في أيام لاحقة ولن يظهر كغير مستلم.
    </p>
</div>
<?php endif; ?>

<form method="post" action="<?= e(url('/campaigns/beneficiaries/mark-delivered')) ?>" id="manual-deliver-form">
    <?= \App\Csrf::field() ?>
    <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <input type="hidden" name="page" value="<?= (int) $page ?>">

<?php if ($canManualDeliver): ?>
<div class="card actions-row" style="align-items:flex-end;gap:0.75rem;flex-wrap:wrap">
    <div style="flex:1;min-width:240px">
        <label class="field-label">سبب الاستلام اليدوي *</label>
        <input type="text" name="reason" class="form-control" required
               placeholder="مثال: استلم من جهاز ميداني ولم يكن بالكشوف المطبوعة">
    </div>
    <button type="submit" class="btn" data-confirm="تأكيد تسجيل الاستلام اليدوي للمحددين؟">تسجيل استلام المحددين</button>
</div>
<?php endif; ?>

<div class="card table-panel">
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <?php if ($canManualDeliver): ?>
                <th style="width:2.5rem">
                    <input type="checkbox" id="manual-check-all" title="تحديد الكل" aria-label="تحديد الكل">
                </th>
                <?php endif; ?>
                <th>الاسم</th>
                <th>الهوية</th>
                <th>الجوال</th>
                <th>الكود</th>
                <th>اليوم</th>
                <th>التاريخ</th>
                <th>الحالة</th>
                <th>الاستلام</th>
                <th>أمين المخزن</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
        <tr><td colspan="<?= $canManualDeliver ? 10 : 9 ?>" class="text-muted">لا نتائج.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $b): ?>
        <?php
            $assigned = (int) ($b['day_index'] ?? 0) > 0 && trim((string) ($b['disbursement_code'] ?? '')) !== '';
            $delivered = DeliveryService::isDeliveredStatus($b['receipt_status'] ?? '');
            $display = \App\ParcelCodeHelper::displayForBeneficiary(
                (string) ($b['disbursement_code'] ?? ''),
                $codeSuffix !== '' ? $codeSuffix : null,
                $codePrefix !== '' ? $codePrefix : null
            );
            if ($display === '' && !empty($b['sort_order'])) {
                $display = (string) $b['sort_order'];
            }
        ?>
        <tr>
            <?php if ($canManualDeliver): ?>
            <td>
                <?php if (!$delivered): ?>
                <input type="checkbox" name="beneficiary_ids[]" value="<?= (int) $b['id'] ?>" class="manual-check">
                <?php endif; ?>
            </td>
            <?php endif; ?>
            <td><?= e((string) ($b['name'] ?? '')) ?></td>
            <td><?= e((string) ($b['national_id'] ?? '')) ?></td>
            <td><?= e((string) ($b['mobile'] ?? '')) ?></td>
            <td><?= e($display) ?></td>
            <td><?= $assigned ? ar_digits((int) $b['day_index']) : '—' ?></td>
            <td><?= e((string) ($b['delivery_date'] ?? '—')) ?></td>
            <td>
                <?php if ($delivered): ?>
                <span class="badge badge-ok">مستلم</span>
                <?php elseif ($assigned): ?>
                <span class="badge badge-pending">قيد التسليم</span>
                <?php else: ?>
                <span class="badge">غير معيّن</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($delivered): ?>
                <?= e((string) ($b['actual_delivery_date'] ?? $b['delivered_at'] ?? '')) ?>
                <div class="text-muted" style="font-size:0.85em">
                    <?= e(DeliveryService::receivedByLabel($b['received_by_mode'] ?? null, $b['received_by_name'] ?? null) ?: '') ?>
                </div>
                <?php else: ?>
                —
                <?php endif; ?>
            </td>
            <td><?= e((string) ($b['delivered_by_name'] ?? '—')) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
</form>

<?php if ($totalPages > 1): ?>
<div class="actions-row" style="justify-content:center;gap:0.5rem;flex-wrap:wrap">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <?php if ($p === $page): ?>
        <span class="btn btn-sm"><?= ar_digits($p) ?></span>
        <?php else: ?>
        <a class="btn btn-sm btn-outline" href="<?= e(url('/campaigns/beneficiaries?id=' . (int) $campaign['id'] . '&q=' . rawurlencode($q) . '&page=' . $p)) ?>"><?= ar_digits($p) ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php if ($canManualDeliver): ?>
<script>
(function () {
    var all = document.getElementById('manual-check-all');
    if (!all) return;
    all.addEventListener('change', function () {
        document.querySelectorAll('.manual-check').forEach(function (el) {
            el.checked = all.checked;
        });
    });
})();
</script>
<?php endif; ?>
