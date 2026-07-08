<?php
use App\CampaignService;
$parcelLabel = CampaignService::parcelLabel($campaign);
$isGenerated = ($campaign['status'] ?? '') === 'generated';
$delivered = CampaignService::deliveredCount((int) $campaign['id']);
?>

<h1>تعديل العملية</h1>
<?php context_nav([
    ['label' => 'العمليات', 'url' => '/'],
    ['label' => $campaign['name'], 'url' => '/campaigns/view?id=' . (int) $campaign['id']],
    ['label' => 'تعديل'],
]); ?>
<p class="text-muted"><?= e($campaign['name']) ?> — كود الطرد: <strong><?= e($parcelLabel) ?></strong></p>

<?php if ($isGenerated): ?>
<div class="card" style="border-color:#f59e0b;background:#fffbeb">
    <p><strong>تنبيه:</strong> العملية مُولَّدة. تعديل ملحق كود الطرد يتطلب <strong>إعادة التوليد</strong> لتحديث أكواد المستفيدين.
    <?php if ($delivered > 0): ?>
    يوجد <strong><?= $delivered ?></strong> تسليم مسجّل — الحذف والتنظيف محظوران حتى إلغاء التسليمات.
    <?php endif; ?>
    </p>
</div>
<?php endif; ?>

<form method="post" action="<?= e(url('/campaigns/edit?id=' . (int) $campaign['id'])) ?>" class="card">
    <?= \App\Csrf::field() ?>
    <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">

    <?php partial('campaigns/_form_fields', ['prefix' => 'edit', 'campaign' => $campaign]); ?>

    <button type="submit" class="btn" style="margin-top:1rem">حفظ التعديلات</button>
    <a href="<?= e(url('/campaigns/view?id=' . (int) $campaign['id'])) ?>" class="btn btn-outline" style="margin-top:1rem">إلغاء</a>
</form>

<?php if (!empty($canEdit)): ?>
<div class="card" style="margin-top:1.5rem;border-color:#fca5a5">
    <h2 style="color:#b91c1c">منطقة الخطر</h2>

    <?php if (!empty($canCancelDeliveries) && $delivered > 0): ?>
    <form method="post" action="<?= e(url('/campaigns/undo-deliveries')) ?>" style="margin-bottom:1rem"
          data-confirm="إلغاء جميع التسليمات (<?= $delivered ?>) وإعادة المستفيدين لـ «قيد التسليم»؟ يتيح ذلك حذف العملية.">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <button type="submit" class="btn btn-outline" style="border-color:#d97706;color:#b45309">إلغاء جميع التسليمات (مدير فقط)</button>
        <small class="text-muted" style="display:block;margin-top:0.35rem">للمدير فقط — يُلغي سجلات التسليم لتمكين حذف أو تنظيف العملية.</small>
    </form>
    <?php endif; ?>

    <?php if (($stats['total'] ?? 0) > 0 && $delivered === 0): ?>
    <form method="post" action="<?= e(url('/campaigns/clear-beneficiaries')) ?>" style="margin-bottom:1rem"
          data-confirm="حذف جميع المستفيدين (<?= (int) ($stats['total'] ?? 0) ?>) وإعادة العملية لمسودة؟ لا يمكن التراجع.">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <button type="submit" class="btn btn-outline" style="border-color:#dc2626;color:#dc2626">تنظيف المستفيدين</button>
        <small class="text-muted" style="display:block;margin-top:0.35rem">يحذف كل المستفيدين ويعيد العملية لمسودة — لإعادة رفع Excel من جديد.</small>
    </form>
    <?php endif; ?>

    <?php if ($delivered === 0): ?>
    <form method="post" action="<?= e(url('/campaigns/delete')) ?>"
          data-confirm="حذف العملية «<?= e($campaign['name']) ?>» نهائياً مع كل بياناتها؟ لا يمكن التراجع.">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
        <input type="hidden" name="confirm_name" value="<?= e($campaign['name']) ?>">
        <div class="form-group" style="max-width:320px">
            <label>اكتب اسم العملية للتأكيد</label>
            <input type="text" name="confirm_name_input" class="form-control" required placeholder="<?= e($campaign['name']) ?>">
        </div>
        <button type="submit" class="btn" style="background:#dc2626;border-color:#dc2626">حذف العملية نهائياً</button>
    </form>
    <?php else: ?>
    <p class="text-muted">لا يمكن حذف أو تنظيف عملية فيها تسليمات مسجّلة (<?= $delivered ?> مستفيد).
    <?php if (!empty($canCancelDeliveries)): ?>
    استخدم «إلغاء جميع التسليمات» أعلاه أولاً.
    <?php else: ?>
    اطلب من مدير النظام إلغاء التسليمات.
    <?php endif; ?>
    </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
        if (!confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
});
</script>
