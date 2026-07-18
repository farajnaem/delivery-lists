<?php
/** @var string $title */
/** @var string $description */
/** @var list<array{label:string,url?:string,primary?:bool,class?:string,modal?:string}> $crumbs */
/** @var list<array{label:string,url?:string,primary?:bool,class?:string,modal?:string}> $actions */
$title = $title ?? '';
$description = $description ?? '';
$crumbs = $crumbs ?? [];
$actions = $actions ?? [];
?>
<header class="page-header">
    <div class="page-header-main">
        <?php if ($crumbs !== []): ?>
        <ol class="breadcrumbs" aria-label="مسار التنقل">
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
        <?php if ($title !== ''): ?>
        <h1 class="page-title"><?= e($title) ?></h1>
        <?php endif; ?>
        <?php if ($description !== ''): ?>
        <p class="page-desc"><?= e($description) ?></p>
        <?php endif; ?>
    </div>
    <?php if ($actions !== []): ?>
    <div class="page-actions">
        <?php foreach ($actions as $action): ?>
        <?php
            $cls = !empty($action['class'])
                ? $action['class']
                : ('btn btn-sm' . (empty($action['primary']) ? ' btn-outline' : ''));
            $modal = $action['modal'] ?? '';
        ?>
        <?php if ($modal !== ''): ?>
        <button type="button" class="<?= e($cls) ?>" data-modal-open="<?= e($modal) ?>"><?= e($action['label']) ?></button>
        <?php else: ?>
        <a href="<?= e(url($action['url'] ?? '#')) ?>" class="<?= e($cls) ?>"><?= e($action['label']) ?></a>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</header>
