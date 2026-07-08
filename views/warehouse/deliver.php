<?php
$stats = $stock ?? [];
$balance = (int) ($stats['balance'] ?? 0);
$opening = (int) ($stats['opening_quantity'] ?? 0);
$delivered = (int) ($stats['delivered'] ?? 0);
$campaignActive = !empty($stats['campaign_active']);
?>
<header class="wh-header">
    <div class="wh-header-inner">
        <a href="<?= e(url('/warehouse')) ?>" class="wh-back" title="العمليات">←</a>
        <div class="wh-header-title">
            <strong><?= e($campaign['name']) ?></strong>
            <small><?= e($campaign['warehouse_name']) ?></small>
        </div>
        <div class="wh-header-links">
            <?php if (!empty($canViewStock)): ?>
            <a href="<?= e(url('/campaigns/stock?id=' . (int) $campaign['id'])) ?>" class="wh-link">المخزن</a>
            <?php endif; ?>
            <span id="onlineStatus" class="wh-status wh-status-online">متصل</span>
        </div>
    </div>
</header>

<main class="wh-main wh-deliver">
    <div class="wh-stock-bar">
        <div class="wh-stock-item">
            <span class="wh-stock-val" id="stockBalance"><?= $balance ?></span>
            <span class="wh-stock-lbl">الرصيد</span>
        </div>
        <div class="wh-stock-item">
            <span class="wh-stock-val" id="stockDelivered"><?= $delivered ?></span>
            <span class="wh-stock-lbl">مُسلَّم</span>
        </div>
        <div class="wh-stock-item">
            <span class="wh-stock-val"><?= $opening ?></span>
            <span class="wh-stock-lbl">افتتاحي</span>
        </div>
    </div>

    <?php if (!$campaignActive): ?>
    <div class="wh-alert wh-alert-error">انتهت فترة التسليم — لا يمكن تسجيل تسليمات جديدة.</div>
    <?php endif; ?>

    <div id="pendingSync" class="wh-pending hidden">
        <span id="pendingCount">0</span> تسليم بانتظار المزامنة
        <button type="button" id="btnSyncNow" class="wh-btn wh-btn-sm">مزامنة الآن</button>
    </div>

    <div class="wh-search-box">
        <label for="searchQuery">ابحث بالكود (مثل SOCIR2600001) أو رقم الهوية</label>
        <input type="search" id="searchQuery" class="wh-input" placeholder="SOCIR2600001 أو 1xxxxxxxxx" autocomplete="off" inputmode="search" <?= $campaignActive ? '' : 'disabled' ?>>
        <button type="button" id="btnSearch" class="wh-btn wh-btn-block" <?= $campaignActive ? '' : 'disabled' ?>>بحث</button>
    </div>

    <div id="searchResult" class="wh-result hidden"></div>
    <div id="searchError" class="wh-alert wh-alert-error hidden"></div>
    <div id="searchSuccess" class="wh-alert wh-alert-success hidden"></div>

    <div class="wh-recent">
        <h2>المستلمون (<span id="deliveredTotal"><?= (int) $delivered ?></span>)</h2>
        <ul id="recentList" class="wh-recent-list">
            <?php foreach ($recent as $r): ?>
            <li>
                <strong><?= e($r['disbursement_code']) ?></strong>
                <?= e($r['name']) ?>
                <small><?= e($r['delivered_at'] ?? '') ?>
                <?= ($r['delivery_type'] ?? '') === 'late' ? ' — متأخر' : '' ?></small>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if (empty($recent)): ?>
        <p id="recentEmpty" class="wh-sub">لا مستلمين بعد — ستظهر القائمة هنا بعد كل تسليم.</p>
        <?php endif; ?>
    </div>
</main>

<script>
window.WH_CONFIG = {
    campaignId: <?= (int) $campaign['id'] ?>,
    csrf: <?= json_encode(\App\Csrf::token(), JSON_UNESCAPED_UNICODE) ?>,
    apiBase: <?= json_encode(url('/api/warehouse'), JSON_UNESCAPED_UNICODE) ?>,
    campaignActive: <?= $campaignActive ? 'true' : 'false' ?>
};
</script>
<script src="<?= e(asset('/assets/js/warehouse.js')) ?>"></script>
