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

<div class="card table-panel">
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
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
        <tr><td colspan="9" class="text-muted">لا نتائج.</td></tr>
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
