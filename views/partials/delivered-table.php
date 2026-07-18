<?php
/** @var int|null $totalDelivered */
/** @var string|null $codePrefix */
/** @var string|null $codeSuffix */
$totalDelivered = $totalDelivered ?? count($deliveredList ?? []);
$codePrefix = $codePrefix ?? '';
$codeSuffix = $codeSuffix ?? '';
?>
<div class="card table-panel" data-table-filterable>
    <div class="table-toolbar">
        <div>
            <div class="panel-title">المستلمون (<?= e(ar_digits((string) $totalDelivered)) ?>)</div>
        </div>
        <?php if (!empty($deliveredList)): ?>
        <div class="table-toolbar-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" placeholder="بحث…" data-table-search aria-label="بحث المستلمين">
        </div>
        <?php endif; ?>
    </div>
    <?php if (empty($deliveredList)): ?>
    <div class="empty-state" data-empty-row>
        <strong>لا يوجد مستلمون مسجّلون بعد</strong>
        <span>ستظهر هنا فور تسجيل التسليم من المخزن.</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>الكود</th><th>الاسم</th><th>الهوية</th><th>موعده</th><th>النوع</th><th>وقت التسليم</th><th>بواسطة</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($deliveredList as $r): ?>
        <tr>
            <td><?= e($r['display_code'] ?? '') ?></td>
            <td><?= e($r['name']) ?></td>
            <td><?= e($r['national_id'] ?? '') ?></td>
            <td><?= e($r['delivery_date'] ?? '') ?> — ش <?= e((string) ($r['window_num'] ?? '0')) ?></td>
            <td>
                <?php if (($r['delivery_type'] ?? '') === 'late'): ?>
                <span class="badge badge-warning">متأخر</span>
                <?php else: ?>
                <span class="badge badge-ok">في الموعد</span>
                <?php endif; ?>
            </td>
            <td><?= e($r['delivered_at'] ?? '') ?></td>
            <td><?= e($r['delivered_by_name'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if (($totalDelivered ?? 0) > count($deliveredList)): ?>
    <p class="text-muted" style="padding:0.75rem 1rem">يعرض أول <?= e(ar_digits((string) count($deliveredList))) ?> — للقائمة الكاملة نزّل <strong>تقرير Excel للتسليمات</strong>.</p>
    <?php endif; ?>
    <?php endif; ?>
</div>
