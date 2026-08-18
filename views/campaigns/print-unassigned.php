<?php
$cid = (int) ($campaign['id'] ?? 0);
$rows = $rows ?? [];
$total = count($rows);
?>
<div class="print-toolbar">
    <button type="button" class="btn" onclick="window.print()">طباعة</button>
    <a class="btn btn-outline" href="<?= e(url('/campaigns/export-unassigned?id=' . $cid)) ?>">تنزيل Excel</a>
    <a class="btn btn-ghost" href="<?= e(url('/campaigns/view?id=' . $cid . '&panel=days')) ?>">عودة</a>
</div>
<div class="print-sheet">
    <h1 class="print-title">كشف غير المعيّنين — <?= e((string) ($campaign['name'] ?? '')) ?></h1>
    <p class="print-meta">
        العدد: <?= ar_digits($total) ?>
        — مرتّب حسب مركز الإيواء ثم الاسم
        <?php if (!empty($campaign['parcel_name'])): ?>
        — <?= e((string) $campaign['parcel_name']) ?>
        <?php endif; ?>
    </p>
    <table class="print-table">
        <thead>
            <tr>
                <th style="width:3rem">#</th>
                <th>الاسم</th>
                <th style="width:9rem">رقم الهوية</th>
                <th style="width:8rem">رقم الجوال</th>
                <th>مركز الإيواء</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $n = 0;
        $lastShelter = "\0";
        foreach ($rows as $b):
            $n++;
            $shelter = trim((string) ($b['shelter_name'] ?? ''));
            $nid = \App\ArabicFormat::toWesternDigits((string) ($b['national_id'] ?? ''));
            $mobile = \App\ArabicFormat::toWesternDigits((string) ($b['mobile'] ?? ''));
            $shelterLabel = $shelter !== '' ? $shelter : 'بدون مركز إيواء';
            if ($shelter !== $lastShelter):
                $lastShelter = $shelter;
        ?>
            <tr class="print-shelter-head">
                <td colspan="5"><?= e($shelterLabel) ?></td>
            </tr>
        <?php endif; ?>
            <tr>
                <td><?= ar_digits($n) ?></td>
                <td><?= e((string) ($b['name'] ?? '')) ?></td>
                <td><?= e($nid) ?></td>
                <td><?= e($mobile !== '' ? $mobile : '—') ?></td>
                <td><?= e($shelter !== '' ? $shelter : '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?>
            <tr><td colspan="5">لا يوجد غير معيّنين.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
