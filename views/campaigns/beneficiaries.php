<?php
use App\DeliveryService;

$codePrefix = (string) ($campaign['parcel_code'] ?? '');
$codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
$q = (string) ($q ?? '');
$filter = (string) ($filter ?? '');
$page = max(1, (int) ($page ?? 1));
$total = (int) ($total ?? 0);
$perPage = max(1, (int) ($perPage ?? 50));
$totalPages = max(1, (int) ceil($total / $perPage));
$rows = $rows ?? [];
$canManualDeliver = !empty($canManualDeliver);
$canDeleteBeneficiary = !empty($canDeleteBeneficiary);
$canEditRow = $canDeleteBeneficiary;
$searched = !empty($searched);
$review = is_array($reviewCounts ?? null) ? $reviewCounts : [];
$cid = (int) $campaign['id'];
$showReviewFilters = $canManualDeliver || $canDeleteBeneficiary;
$showManualChecks = $canManualDeliver && $filter === 'anomaly';
$showBulkDelete = $canDeleteBeneficiary;
$idListCount = (int) ($idListCount ?? 0);

$colspan = 7
    + ($showManualChecks ? 1 : 0)
    + ($showBulkDelete ? 1 : 0)
    + ($canEditRow ? 1 : 0);

$filterUrl = static function (string $f = '', string $query = '') use ($cid): string {
    $url = '/campaigns/beneficiaries?id=' . $cid;
    if ($f !== '') {
        $url .= '&filter=' . rawurlencode($f);
    }
    if ($query !== '') {
        $url .= '&q=' . rawurlencode($query);
    }
    return url($url);
};

page_header(
    'بحث المرشحين — ' . (string) $campaign['name'],
    [
        ['label' => 'العمليات', 'url' => '/'],
        ['label' => (string) $campaign['name'], 'url' => '/campaigns/view?id=' . $cid],
        ['label' => 'بحث المرشحين'],
    ],
    [
        ['label' => 'عودة', 'url' => '/campaigns/view?id=' . $cid . '&panel=candidates'],
    ],
    'ابحث برقم الهوية أو جزء من الاسم — أو الصق مجموعة هويات دفعة واحدة ثم احذف المحددين.'
);
?>

<div class="card">
    <form method="get" action="<?= e(url('/campaigns/beneficiaries')) ?>" class="actions-row" style="align-items:flex-end;gap:0.75rem;flex-wrap:wrap">
        <input type="hidden" name="id" value="<?= $cid ?>">
        <?php if ($filter !== ''): ?>
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <?php endif; ?>
        <div style="flex:1;min-width:260px">
            <label class="field-label">هوية / اسم / أو مجموعة هويات</label>
            <textarea name="q" class="form-control" rows="<?= $idListCount >= 2 || substr_count($q, "\n") > 0 ? '5' : '2' ?>"
                      placeholder="هوية واحدة، أو الصق عدة هويات (سطر أو فاصلة بين كل رقم)" autofocus><?= e($q) ?></textarea>
            <p class="field-hint" style="margin:0.35rem 0 0">مثال لصق مجموعة: كل هوية في سطر، أو مفصولة بفاصلة/مسافة. مع فلتر «غير معيّنين» تظهر فقط غير المعيّنة منها.</p>
        </div>
        <button type="submit" class="btn">بحث</button>
        <?php if ($q !== '' || $filter !== ''): ?>
        <a class="btn btn-ghost" href="<?= e(url('/campaigns/beneficiaries?id=' . $cid)) ?>">مسح</a>
        <?php endif; ?>
    </form>

    <?php if ($showReviewFilters): ?>
    <details class="op-filters" style="margin-top:0.85rem" <?= $filter !== '' ? 'open' : '' ?>>
        <summary style="cursor:pointer;color:var(--muted);font-size:0.9rem">فلاتر إضافية (اختياري)</summary>
        <div class="actions-row" style="margin-top:0.65rem;gap:0.5rem;flex-wrap:wrap">
            <a class="btn btn-sm <?= $filter === 'unassigned' ? '' : 'btn-outline' ?>" href="<?= e($filterUrl('unassigned', $q)) ?>">غير معيّنين</a>
            <a class="btn btn-sm <?= $filter === 'duplicates' ? '' : 'btn-outline' ?>" href="<?= e($filterUrl('duplicates', $q)) ?>">مكررون</a>
            <?php if ($canManualDeliver): ?>
            <a class="btn btn-sm <?= $filter === 'today' ? '' : 'btn-outline' ?>" href="<?= e($filterUrl('today', $q)) ?>">مستلمو اليوم</a>
            <a class="btn btn-sm <?= $filter === 'anomaly' ? '' : 'btn-outline' ?>" href="<?= e($filterUrl('anomaly', $q)) ?>">تسليم بلا تعيين</a>
            <?php endif; ?>
        </div>
    </details>
    <?php endif; ?>

    <?php if ($searched): ?>
    <p class="text-muted" style="margin:0.75rem 0 0">
        النتيجة: <strong><?= ar_digits($total) ?></strong>
        <?php if ($idListCount >= 2): ?>
        — تم التعرّف على <strong><?= ar_digits($idListCount) ?></strong> هوية ملصوقة
        <?php endif; ?>
        <?php if ($totalPages > 1): ?>
        — صفحة <?= ar_digits($page) ?> من <?= ar_digits($totalPages) ?>
        <?php endif; ?>
    </p>
    <?php else: ?>
    <p class="text-muted" style="margin:0.75rem 0 0">أدخل هوية أو اسماً، أو الصق مجموعة هويات — ويفضَّل مع فلتر «غير معيّنين».</p>
    <?php endif; ?>
</div>

<?php if ($searched): ?>

<?php if ($showManualChecks): ?>
<form method="post" action="<?= e(url('/campaigns/beneficiaries/mark-delivered')) ?>" id="manual-deliver-form">
    <?= \App\Csrf::field() ?>
    <input type="hidden" name="campaign_id" value="<?= $cid ?>">
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <input type="hidden" name="page" value="<?= (int) $page ?>">
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <div class="card actions-row" style="align-items:flex-end;gap:0.75rem;flex-wrap:wrap">
        <div style="flex:1;min-width:240px">
            <label class="field-label">سبب الاستلام اليدوي *</label>
            <input type="text" name="reason" class="form-control" required placeholder="سبب التثبيت">
        </div>
        <button type="submit" class="btn" data-confirm="تأكيد تسجيل الاستلام اليدوي للمحددين؟">تسجيل استلام المحددين</button>
    </div>
</form>
<?php endif; ?>

<?php if ($showBulkDelete): ?>
<form method="post" action="<?= e(url('/campaigns/beneficiaries/delete-many')) ?>" id="bulk-delete-form"
      data-confirm="حذف المحددين نهائياً؟ لا يمكن التراجع.">
    <?= \App\Csrf::field() ?>
    <input type="hidden" name="campaign_id" value="<?= $cid ?>">
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <input type="hidden" name="page" value="<?= (int) $page ?>">
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <div class="card actions-row" style="align-items:center;gap:0.75rem;flex-wrap:wrap">
        <p class="text-muted" style="margin:0;flex:1;min-width:220px">
            حدّد غير المعيّنين من القائمة ثم احذفهم دفعة واحدة.
            <?php if ($filter === 'unassigned'): ?>
            <strong>(فلتر غير المعيّنين نشط)</strong>
            <?php endif; ?>
        </p>
        <button type="submit" class="btn btn-danger" id="bulk-delete-btn">حذف المحددين</button>
    </div>
</form>
<?php endif; ?>

<div class="card table-panel">
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <?php if ($showBulkDelete): ?>
                <th style="width:2.5rem">
                    <input type="checkbox" id="bulk-check-all" title="تحديد الكل القابل للحذف" aria-label="تحديد الكل القابل للحذف">
                </th>
                <?php endif; ?>
                <?php if ($showManualChecks): ?>
                <th style="width:2.5rem">
                    <input type="checkbox" id="manual-check-all" title="تحديد الكل" aria-label="تحديد الكل">
                </th>
                <?php endif; ?>
                <th>الاسم</th>
                <th>الهوية</th>
                <th>الجوال</th>
                <th>الكود</th>
                <th>اليوم</th>
                <th>الحالة</th>
                <?php if ($canEditRow): ?>
                <th>إجراء</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
        <tr><td colspan="<?= (int) $colspan ?>" class="text-muted">لا نتائج لهذا البحث.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $b): ?>
        <?php
            $assigned = (int) ($b['day_index'] ?? 0) > 0 && trim((string) ($b['disbursement_code'] ?? '')) !== '';
            $delivered = DeliveryService::isDeliveredStatus($b['receipt_status'] ?? '');
            $canDeleteRow = $canDeleteBeneficiary && !$delivered && !$assigned;
            $display = \App\ParcelCodeHelper::displayForBeneficiary(
                (string) ($b['disbursement_code'] ?? ''),
                $codeSuffix !== '' ? $codeSuffix : null,
                $codePrefix !== '' ? $codePrefix : null
            );
            if ($display === '' && !empty($b['sort_order'])) {
                $display = (string) $b['sort_order'];
            }
            $rowId = (int) $b['id'];
        ?>
        <tr>
            <?php if ($showBulkDelete): ?>
            <td>
                <?php if ($canDeleteRow): ?>
                <input type="checkbox" form="bulk-delete-form" name="beneficiary_ids[]" value="<?= $rowId ?>" class="bulk-delete-check">
                <?php endif; ?>
            </td>
            <?php endif; ?>
            <?php if ($showManualChecks): ?>
            <td>
                <?php if (!$delivered): ?>
                <input type="checkbox" form="manual-deliver-form" name="beneficiary_ids[]" value="<?= $rowId ?>" class="manual-check" checked>
                <?php endif; ?>
            </td>
            <?php endif; ?>
            <td><?= e((string) ($b['name'] ?? '')) ?></td>
            <td><?= e((string) ($b['national_id'] ?? '')) ?></td>
            <td><?= e((string) ($b['mobile'] ?? '')) ?></td>
            <td><?= e($display !== '' ? $display : '—') ?></td>
            <td><?= $assigned ? ar_digits((int) $b['day_index']) : '—' ?></td>
            <td>
                <?php if ($delivered): ?>
                <span class="badge badge-ok">مستلم</span>
                <?php elseif ($assigned): ?>
                <span class="badge badge-pending">قيد التسليم</span>
                <?php else: ?>
                <span class="badge">غير معيّن</span>
                <?php endif; ?>
            </td>
            <?php if ($canEditRow): ?>
            <td>
                <div class="actions-row" style="gap:0.35rem;flex-wrap:wrap">
                    <button type="button" class="btn btn-sm btn-outline" data-edit-toggle="<?= $rowId ?>">تعديل</button>
                    <?php if ($canDeleteRow): ?>
                    <form method="post" action="<?= e(url('/campaigns/beneficiaries/delete')) ?>" style="margin:0"
                          data-confirm="حذف «<?= e((string) ($b['name'] ?? '')) ?>» نهائياً؟">
                        <?= \App\Csrf::field() ?>
                        <input type="hidden" name="campaign_id" value="<?= $cid ?>">
                        <input type="hidden" name="beneficiary_id" value="<?= $rowId ?>">
                        <input type="hidden" name="q" value="<?= e($q) ?>">
                        <input type="hidden" name="page" value="<?= (int) $page ?>">
                        <input type="hidden" name="filter" value="<?= e($filter) ?>">
                        <button type="submit" class="btn btn-sm btn-danger">حذف</button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
            <?php endif; ?>
        </tr>
        <?php if ($canEditRow): ?>
        <tr id="edit-row-<?= $rowId ?>" class="edit-row" hidden>
            <td colspan="<?= (int) $colspan ?>">
                <form method="post" action="<?= e(url('/campaigns/beneficiaries/update')) ?>" class="grid-2" style="gap:0.65rem;align-items:end">
                    <?= \App\Csrf::field() ?>
                    <input type="hidden" name="campaign_id" value="<?= $cid ?>">
                    <input type="hidden" name="beneficiary_id" value="<?= $rowId ?>">
                    <input type="hidden" name="q" value="<?= e($q) ?>">
                    <input type="hidden" name="page" value="<?= (int) $page ?>">
                    <input type="hidden" name="filter" value="<?= e($filter) ?>">
                    <div>
                        <label class="field-label">الاسم</label>
                        <input type="text" name="name" class="form-control" required value="<?= e((string) ($b['name'] ?? '')) ?>">
                    </div>
                    <div>
                        <label class="field-label">رقم الهوية<?= $delivered ? ' (للمستلم لا يُغيَّر)' : '' ?></label>
                        <input type="text" name="national_id" class="form-control" required value="<?= e((string) ($b['national_id'] ?? '')) ?>"
                            <?= $delivered ? 'readonly' : '' ?>>
                    </div>
                    <div>
                        <label class="field-label">الجوال</label>
                        <input type="text" name="mobile" class="form-control" value="<?= e((string) ($b['mobile'] ?? '')) ?>">
                    </div>
                    <div class="actions-row" style="gap:0.5rem">
                        <button type="submit" class="btn btn-sm">حفظ</button>
                        <button type="button" class="btn btn-sm btn-ghost" data-edit-toggle="<?= $rowId ?>">إلغاء</button>
                    </div>
                </form>
            </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<div class="actions-row" style="justify-content:center;gap:0.5rem;flex-wrap:wrap">
    <?php
    $from = max(1, $page - 3);
    $to = min($totalPages, $page + 3);
    for ($p = $from; $p <= $to; $p++):
        $pageUrl = '/campaigns/beneficiaries?id=' . $cid
            . ($filter !== '' ? '&filter=' . rawurlencode($filter) : '')
            . ($q !== '' ? '&q=' . rawurlencode($q) : '')
            . '&page=' . $p;
    ?>
        <?php if ($p === $page): ?>
        <span class="btn btn-sm"><?= ar_digits($p) ?></span>
        <?php else: ?>
        <a class="btn btn-sm btn-outline" href="<?= e(url($pageUrl)) ?>"><?= ar_digits($p) ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>
<?php endif; ?>

<script>
(function () {
    document.querySelectorAll('[data-edit-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-edit-toggle');
            var row = document.getElementById('edit-row-' + id);
            if (!row) return;
            row.hidden = !row.hidden;
        });
    });
    var all = document.getElementById('manual-check-all');
    if (all) {
        all.addEventListener('change', function () {
            document.querySelectorAll('.manual-check').forEach(function (el) {
                el.checked = all.checked;
            });
        });
    }
    var bulkAll = document.getElementById('bulk-check-all');
    if (bulkAll) {
        bulkAll.addEventListener('change', function () {
            document.querySelectorAll('.bulk-delete-check').forEach(function (el) {
                el.checked = bulkAll.checked;
            });
        });
    }
    var bulkForm = document.getElementById('bulk-delete-form');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function (e) {
            var checked = document.querySelectorAll('.bulk-delete-check:checked').length;
            if (checked < 1) {
                e.preventDefault();
                e.stopImmediatePropagation();
                alert('حدّد مستفيداً واحداً على الأقل للحذف.');
            }
        }, true);
    }
})();
</script>

<?php endif; ?>
