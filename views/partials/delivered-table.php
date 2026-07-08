<?php
/** @var list<array> $deliveredList */
/** @var int|null $totalDelivered */
$totalDelivered = $totalDelivered ?? count($deliveredList ?? []);
?>
<div class="card">
    <h2>المستلمون (<?= (int) $totalDelivered ?>)</h2>
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
            <td><?= e($r['disbursement_code']) ?></td>
            <td><?= e($r['name']) ?></td>
            <td><?= e($r['national_id'] ?? '') ?></td>
            <td><?= e($r['delivery_date'] ?? '') ?> — ش <?= (int) ($r['window_num'] ?? 0) ?></td>
            <td><?= ($r['delivery_type'] ?? '') === 'late' ? 'متأخر' : 'في الموعد' ?></td>
            <td><?= e($r['delivered_at'] ?? '') ?></td>
            <td><?= e($r['delivered_by_name'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if (($totalDelivered ?? 0) > count($deliveredList)): ?>
    <p class="text-muted">يعرض أول <?= count($deliveredList) ?> — للقائمة الكاملة نزّل <strong>تقرير Excel للتسليمات</strong>.</p>
    <?php endif; ?>
    <?php endif; ?>
</div>
