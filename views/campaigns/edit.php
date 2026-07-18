<?php
use App\CampaignService;
$parcelLabel = CampaignService::parcelLabel($campaign);
$isGenerated = ($campaign['status'] ?? '') === 'generated';
$delivered = CampaignService::deliveredCount((int) $campaign['id']);

page_header(
    'تعديل العملية',
    [
        ['label' => 'العمليات', 'url' => '/'],
        ['label' => $campaign['name'], 'url' => '/campaigns/view?id=' . (int) $campaign['id']],
        ['label' => 'تعديل'],
    ],
    [],
    $campaign['name'] . ' — كود الطرد: ' . $parcelLabel
);
?>

<?php if ($isGenerated): ?>
<div class="alert alert-warning">
    <div>
        <strong>تنبيه:</strong> العملية مُولَّدة. تعديل كود الطرد يتطلب إعادة التوليد لتحديث أكواد المستفيدين.
        <?php if ($delivered > 0): ?>
        يوجد <strong><?= ar_digits($delivered) ?></strong> تسليم مسجّل — الحذف والتنظيف محظوران حتى إلغاء التسليمات.
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<form method="post" action="<?= e(url('/campaigns/edit?id=' . (int) $campaign['id'])) ?>" class="card">
    <?= \App\Csrf::field() ?>
    <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">

    <?php partial('campaigns/_form_fields', ['prefix' => 'edit', 'campaign' => $campaign]); ?>

    <div class="actions-row">
        <button type="submit" class="btn">حفظ التعديلات</button>
        <a href="<?= e(url('/campaigns/view?id=' . (int) $campaign['id'])) ?>" class="btn btn-outline">إلغاء</a>
    </div>
</form>

<?php if (!empty($canEdit)): ?>
<div class="danger-zone">
    <h2>منطقة الخطر</h2>
    <p class="text-muted" style="margin-bottom:1rem">إجراءات لا يمكن التراجع عنها بسهولة — استخدمها بحذر.</p>

    <?php if (!empty($canCancelDeliveries) && $delivered > 0): ?>
    <form method="post" action="<?= e(url('/campaigns/undo-deliveries')) ?>" style="margin-bottom:1rem"
          data-confirm="إلغاء جميع التسليمات (<?= $delivered ?>) وإعادة المستفيدين لـ «قيد التسليم»؟ يتيح ذلك حذف العملية.">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <button type="submit" class="btn btn-outline">إلغاء جميع التسليمات (مدير فقط)</button>
        <span class="field-hint">يُلغي سجلات التسليم لتمكين حذف أو تنظيف العملية.</span>
    </form>
    <?php endif; ?>

    <?php if (($stats['total'] ?? 0) > 0 && $delivered === 0): ?>
    <form method="post" action="<?= e(url('/campaigns/clear-beneficiaries')) ?>" style="margin-bottom:1rem"
          data-confirm="حذف جميع المستفيدين (<?= (int) ($stats['total'] ?? 0) ?>) وإعادة العملية لمسودة؟ لا يمكن التراجع.">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <button type="submit" class="btn btn-outline">تنظيف المستفيدين</button>
        <span class="field-hint">يحذف كل المستفيدين ويعيد العملية لمسودة.</span>
    </form>
    <?php endif; ?>

    <?php if ($delivered === 0): ?>
    <form method="post" action="<?= e(url('/campaigns/delete')) ?>"
          data-confirm="حذف العملية «<?= e($campaign['name']) ?>» نهائياً مع كل بياناتها؟ لا يمكن التراجع.">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <input type="hidden" name="confirm_name" value="<?= e($campaign['name']) ?>">
        <div class="form-group" style="max-width:320px">
            <label class="field-label">اكتب اسم العملية للتأكيد</label>
            <input type="text" name="confirm_name_input" class="form-control" required placeholder="<?= e($campaign['name']) ?>">
        </div>
        <button type="submit" class="btn btn-danger">حذف العملية نهائياً</button>
    </form>
    <?php else: ?>
    <p class="text-muted">لا يمكن حذف أو تنظيف عملية فيها تسليمات مسجّلة (<?= ar_digits($delivered) ?> مستفيد).
    <?php if (!empty($canCancelDeliveries)): ?>
    استخدم «إلغاء جميع التسليمات» أعلاه أولاً.
    <?php else: ?>
    اطلب من مدير النظام إلغاء التسليمات.
    <?php endif; ?>
    </p>
    <?php endif; ?>
</div>
<?php endif; ?>
