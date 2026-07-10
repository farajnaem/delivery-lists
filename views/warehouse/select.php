<?php use App\Auth; ?>
<header class="wh-header">
    <div class="wh-header-inner">
        <a href="<?= e(url('/warehouse')) ?>" class="wh-brand">تسليم المخزن</a>
        <div class="wh-user">
            <?php if (!empty($canViewOperations)): ?>
            <a href="<?= e(url('/')) ?>" class="wh-link">العمليات</a>
            <?php endif; ?>
            <span><?= e(Auth::user()['name'] ?? '') ?></span>
            <a href="<?= e(url('/logout')) ?>" class="wh-link">خروج</a>
        </div>
    </div>
</header>
<main class="wh-main">
    <?php if ($flash = get_flash()): ?>
    <div class="wh-alert wh-alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <h1 class="wh-title">اختر الطرد</h1>
    <p class="wh-sub">اضغط على الطرد لفتح الاستلام والاستعلام — القناة الرسمية لتسجيل التسليم</p>

    <?php if (empty($campaigns)): ?>
    <div class="wh-card wh-empty">
        <p>لا توجد طرود جاهزة للتسليم حالياً.</p>
        <?php if (!empty($canViewOperations)): ?>
        <p><a href="<?= e(url('/')) ?>">← العودة لعمليات التوزيع</a></p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="wh-campaign-list">
        <?php foreach ($campaigns as $c): ?>
        <a href="<?= e(url('/warehouse/deliver?campaign_id=' . (int) $c['id'])) ?>" class="wh-campaign-card">
            <span class="wh-parcel-label">الطرد</span>
            <strong class="wh-parcel-name"><?= e($c['parcel_name']) ?></strong>
            <span class="wh-op-name"><?= e($c['name']) ?></span>
            <span class="wh-meta"><?= e($c['warehouse_name']) ?> · <?= e($c['delivery_start']) ?> — <?= e($c['delivery_end']) ?></span>
            <span class="wh-meta">
                الرصيد: <strong><?= (int) ($c['balance'] ?? 0) ?></strong>
                · <?= (int) ($c['beneficiary_count'] ?? 0) ?> مستفيد
                · <?= (int) ($c['delivered_count'] ?? 0) ?> مُسلَّم
            </span>
            <?php if (empty($c['campaign_active'])): ?>
            <span class="wh-card-badge wh-card-badge-closed">التسليم مُنهى</span>
            <?php else: ?>
            <span class="wh-card-cta">استلام واستعلام ←</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($canViewOperations)): ?>
    <p style="margin-top:1rem"><a href="<?= e(url('/')) ?>">← العودة لعمليات التوزيع</a></p>
    <?php endif; ?>
    <?php endif; ?>
</main>
