<?php if (!empty($crumbs) || !empty($actions)): ?>
<nav class="context-nav" aria-label="تنقّل">
    <?php if (!empty($crumbs)): ?>
    <ol class="context-breadcrumbs">
        <?php foreach ($crumbs as $i => $crumb): ?>
        <li>
            <?php if (!empty($crumb['url']) && $i < count($crumbs) - 1): ?>
            <a href="<?= e(url($crumb['url'])) ?>"><?= e($crumb['label']) ?></a>
            <?php else: ?>
            <span><?= e($crumb['label']) ?></span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>
    <?php if (!empty($actions)): ?>
    <div class="context-actions">
        <?php foreach ($actions as $action): ?>
        <a href="<?= e(url($action['url'])) ?>" class="btn <?= !empty($action['primary']) ? '' : 'btn-outline' ?> btn-sm"><?= e($action['label']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</nav>
<?php endif; ?>
