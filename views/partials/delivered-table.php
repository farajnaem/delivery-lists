<?php
/** @var int|null $totalDelivered */
/** @var string|null $codePrefix */
/** @var string|null $codeSuffix */
$totalDelivered = $totalDelivered ?? count($deliveredList ?? []);
$codePrefix = $codePrefix ?? '';
$codeSuffix = $codeSuffix ?? '';
?>
<div class="card">
    <h2>المستلمون (<?= e((string) $totalDelivered) ?>)</h2>
    <?php if (empty($deliveredList)): ?>
    <p class="text-muted">لا يوجد مستلمون مسجّلون بعد — ستظهر هنا فور تسجيل التسليم من المخزن.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
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
            <td><?= e($r['delivery_date'] ?? '') ?> — ش <?= e((string) ($r['window_num'] ?? '٠')) ?></td>
            <td><?= ($r['delivery_type'] ?? '') === 'late' ? 'متأخر' : 'في الموعد' ?></td>
            <td><?= e($r['delivered_at'] ?? '') ?></td>
            <td><?= e($r['delivered_by_name'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if (($totalDelivered ?? 0) > count($deliveredList)): ?>
    <p class="text-muted">يعرض أول <?= e(ar_digits((string) count($deliveredList))) ?> — للقائمة الكاملة نزّل <strong>تقرير Excel للتسليمات</strong>.</p>
    <?php endif; ?>
    <?php endif; ?>
</div>
