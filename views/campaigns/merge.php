<?php
use App\CampaignMergeService;
use App\CampaignService;

$source = $source ?? [];
$campaigns = $campaigns ?? [];
$preview = is_array($preview ?? null) ? $preview : null;
$toId = (int) ($toId ?? 0);
$sourceId = (int) ($source['id'] ?? 0);
$sourceName = (string) ($source['name'] ?? '');
$parcelLabel = CampaignService::parcelLabel($source);
$previewOk = is_array($preview) && !empty($preview['ok']);
$counts = $previewOk ? ($preview['counts'] ?? []) : [];
$projected = $previewOk ? ($preview['projected'] ?? []) : [];
$sourceStock = $previewOk ? ($preview['source_stock'] ?? []) : [];
$targetStock = $previewOk ? ($preview['target_stock'] ?? []) : [];
$codeConflicts = $previewOk ? ($preview['code_conflicts'] ?? []) : [];
$samples = $previewOk ? ($preview['duplicate_samples'] ?? []) : [];
$canConfirm = $previewOk && $codeConflicts === [];

page_header(
    'دمج عملية في أخرى',
    [
        ['label' => 'العمليات', 'url' => '/'],
        ['label' => $sourceName, 'url' => '/campaigns/view?id=' . $sourceId],
        ['label' => 'تعديل', 'url' => '/campaigns/edit?id=' . $sourceId],
        ['label' => 'دمج'],
    ],
    [],
    $sourceName . ' — كود الطرد: ' . $parcelLabel
);
?>

<div class="alert alert-warning">
    <div>
        <strong>إجراء لمرة واحدة.</strong>
        تُنقل الأسماء والتسليمات وتعيينات الكشوف من هذه العملية إلى عملية الوجهة.
        تُنشأ أولاً نسخة احتياط من المصدر (مقفلة)، ثم تُفرَّغ العملية المصدر ليمكنك حذفها بعد المراجعة.
        رصيد المخزن بعد الدمج = مجموع الافتتاحيين − المستلمين الفريدين.
    </div>
</div>

<div class="card">
    <h2 class="panel-title" style="margin-top:0">1) اختر العملية الوجهة</h2>
    <p class="text-muted">الوجهة هي العملية التي تبقى ويعمل عليها المخزن والتقارير بعد الدمج.</p>
    <form method="get" action="<?= e(url('/campaigns/merge')) ?>" class="actions-row" style="align-items:flex-end;flex-wrap:wrap;gap:0.75rem">
        <input type="hidden" name="from" value="<?= $sourceId ?>">
        <div style="flex:1;min-width:260px">
            <label class="field-label" for="merge-to">ادمج «<?= e($sourceName) ?>» داخل</label>
            <select id="merge-to" name="to" class="form-control" required>
                <option value="">— اختر العملية الوجهة —</option>
                <?php foreach ($campaigns as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $toId === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e((string) $c['name']) ?>
                    — <?= e((string) ($c['parcel_name'] ?? '')) ?>
                    (<?= ar_digits((int) ($c['beneficiary_count'] ?? 0)) ?> مستفيد)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn">معاينة الدمج</button>
        <a href="<?= e(url('/campaigns/edit?id=' . $sourceId)) ?>" class="btn btn-outline">رجوع</a>
    </form>
</div>

<?php if (is_array($preview) && empty($preview['ok'])): ?>
<div class="alert alert-error" style="margin-top:1rem">
    <?= e((string) ($preview['error'] ?? 'تعذّرت المعاينة.')) ?>
</div>
<?php endif; ?>

<?php if ($previewOk): ?>
<?php if (!empty($preview['parcel_mismatch'])): ?>
<div class="alert alert-warning" style="margin-top:1rem">
    كود أو اسم الطرد مختلف بين العمليتين. الدمج ممكن، لكن تأكد أنهما نفس التوزيع فعلياً.
</div>
<?php endif; ?>

<div class="grid-stats" style="margin-top:1rem">
    <div class="stat-card">
        <div class="stat-label">المصدر الآن</div>
        <div class="stat-value"><?= ar_digits((int) ($sourceStock['delivered'] ?? 0)) ?> / <?= ar_digits((int) ($sourceStock['total'] ?? 0)) ?></div>
        <div class="stat-meta">مستلم / مرشح — رصيد <?= ar_digits((int) ($sourceStock['balance'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">الوجهة الآن</div>
        <div class="stat-value"><?= ar_digits((int) ($targetStock['delivered'] ?? 0)) ?> / <?= ar_digits((int) ($targetStock['total'] ?? 0)) ?></div>
        <div class="stat-meta">مستلم / مرشح — رصيد <?= ar_digits((int) ($targetStock['balance'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">بعد الدمج (الوجهة)</div>
        <div class="stat-value"><?= ar_digits((int) ($projected['delivered'] ?? 0)) ?> / <?= ar_digits((int) ($projected['total'] ?? 0)) ?></div>
        <div class="stat-meta">مستلم / مرشح — رصيد <?= ar_digits((int) ($projected['balance'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">افتتاحي موحّد</div>
        <div class="stat-value"><?= ar_digits((int) ($projected['opening'] ?? 0)) ?></div>
        <div class="stat-meta"><?= ar_digits((int) ($sourceStock['opening'] ?? 0)) ?> + <?= ar_digits((int) ($targetStock['opening'] ?? 0)) ?></div>
    </div>
</div>

<div class="card" style="margin-top:1rem">
    <h2 class="panel-title" style="margin-top:0">ماذا سيحدث</h2>
    <ul class="text-muted" style="margin:0;padding-inline-start:1.2rem;line-height:1.9">
        <li>نقل <?= ar_digits((int) ($counts['move'] ?? 0)) ?> مستفيد غير موجود في الوجهة (مع كود الصرف وتعيين اليوم والتسليم إن وُجد).</li>
        <li>هويات مكررة: <?= ar_digits((int) ($counts['duplicates'] ?? 0)) ?> —
            ننقل التسليم من المصدر فقط في <?= ar_digits((int) ($counts['apply_delivery'] ?? 0)) ?> حالة،
            ونتجاهل نسخة المصدر في الباقي حتى لا يُحسب الشخص مرتين.</li>
        <li>أيام كشوف المصدر تُرقَّم بعد آخر يوم في الوجهة حتى لا تختلط الكشوف.</li>
        <li>إذا كان الافتتاحي مكرراً في العمليتين (نفس المخزون كُتب مرتين)، عدّله بعد الدمج من «تعديل العملية».</li>
        <li>بعد النجاح: أعد تحميل عملية الوجهة على تطبيق المخزن. لا تسلّم من النسخة الاحتياط.</li>
    </ul>
</div>

<?php if ($samples !== []): ?>
<div class="card table-panel" style="margin-top:1rem">
    <div class="panel-title">عيّنة من الهويات المكررة بين العمليتين</div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>الهوية</th><th>في المصدر</th><th>في الوجهة</th><th>الإجراء</th></tr>
            </thead>
            <tbody>
            <?php foreach ($samples as $row): ?>
                <tr>
                    <td><code><?= e((string) ($row['national_id'] ?? '')) ?></code></td>
                    <td><?= e((string) ($row['source_name'] ?? '')) ?></td>
                    <td><?= e((string) ($row['target_name'] ?? '')) ?></td>
                    <td><?= e(CampaignMergeService::actionLabel((string) ($row['action'] ?? ''))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($codeConflicts !== []): ?>
<div class="card" style="margin-top:1rem;border-color:#FECACA;background:#FFF7F7">
    <h2 class="panel-title" style="margin-top:0">تعارض أكواد لمستلمين</h2>
    <p class="text-muted">نفس كود الصرف لمستلمين مختلفين. عالج هذا يدوياً قبل الدمج.</p>
    <ul>
        <?php foreach ($codeConflicts as $row): ?>
        <li><code><?= e((string) ($row['code'] ?? '')) ?></code>
            — <?= e((string) ($row['source_name'] ?? '')) ?>
            / <?= e((string) ($row['target_name'] ?? '')) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($canConfirm): ?>
<div class="danger-zone">
    <h2>تأكيد الدمج</h2>
    <p class="text-muted" style="margin-bottom:1rem">
        اكتب اسم العملية المصدر كما هو بالضبط:
        <strong><?= e($sourceName) ?></strong>
    </p>
    <form method="post" action="<?= e(url('/campaigns/merge')) ?>"
          data-confirm="دمج «<?= e($sourceName) ?>» داخل العملية الوجهة؟ تُنشأ نسخة احتياط أولاً. لا يمكن التراجع إلا من النسخة الاحتياط أو نسخة قاعدة البيانات.">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="from" value="<?= $sourceId ?>">
        <input type="hidden" name="to" value="<?= $toId ?>">
        <div class="form-group" style="max-width:420px">
            <label class="field-label">اسم العملية المصدر</label>
            <input type="text" name="confirm_name_input" class="form-control" required placeholder="<?= e($sourceName) ?>">
        </div>
        <button type="submit" class="btn btn-danger">دمج الآن مع نسخة احتياط</button>
    </form>
</div>
<?php endif; ?>
<?php endif; ?>
