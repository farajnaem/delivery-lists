<?php use App\Auth; ?>
<header class="wh-header">
    <div class="wh-header-inner">
        <a href="<?= e(url('/warehouse')) ?>" class="wh-brand">تسليم المخزن</a>
        <div class="wh-user">
            <span><?= e(Auth::user()['name'] ?? '') ?></span>
            <a href="<?= e(url('/logout')) ?>" class="wh-link">خروج</a>
        </div>
    </div>
</header>
<main class="wh-main">
    <?php if ($flash = get_flash()): ?>
    <div class="wh-alert wh-alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <h1 class="wh-title">اختر العملية</h1>
    <p class="wh-sub">العمليات المُولَّدة الجاهزة للتسليم من المخزن</p>

    <?php if (empty($campaigns)): ?>
    <div class="wh-card wh-empty">
        <p>لا توجد عمليات جاهزة للتسليم حالياً.</p>
    </div>
    <?php else: ?>
    <div class="wh-campaign-list">
        <?php foreach ($campaigns as $c): ?>
        <a href="<?= e(url('/warehouse/deliver?campaign_id=' . (int) $c['id'])) ?>" class="wh-campaign-card">
            <strong><?= e($c['name']) ?></strong>
            <span><?= e($c['parcel_name']) ?></span>
            <span class="wh-meta"><?= e($c['delivery_start']) ?> — <?= e($c['delivery_end']) ?></span>
            <span class="wh-meta"><?= (int) ($c['beneficiary_count'] ?? 0) ?> مستفيد</span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>
