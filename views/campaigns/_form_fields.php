<?php
/** @var array|null $campaign */
/** @var string $prefix */
$isEdit = ($prefix ?? '') === 'edit';
$c = $campaign ?? [];
$parcelCode = old('parcel_code', $c['parcel_code'] ?? 'SOCI');
$suffix = old('parcel_code_suffix', $c['parcel_code_suffix'] ?? '');
$defaultWindows = ((int) ($c['num_windows'] ?? 0) > 0) ? (string) (int) $c['num_windows'] : '4';
?>
<h2 style="margin-top:<?= $isEdit ? '0' : '1.5rem' ?>">بيانات العملية</h2>
<div class="grid-2">
    <div class="form-group">
        <label>اسم العملية *</label>
        <input type="text" name="name" class="form-control" required placeholder="مثال: طرود رمضان 2026" value="<?= e(old('name', $c['name'] ?? '')) ?>">
    </div>
    <div class="form-group">
        <label>PipeLine *</label>
        <input type="text" name="pipeline_name" class="form-control" required placeholder="e.g. Ramadan 2026 Pipeline" value="<?= e(old('pipeline_name', $c['pipeline_name'] ?? '')) ?>">
        <small class="text-muted">الاسم الإنجليزي للعملية — يظهر في التقارير والعرض.</small>
    </div>
    <div class="form-group">
        <label>اسم الطرد *</label>
        <input type="text" name="parcel_name" class="form-control" required value="<?= e(old('parcel_name', $c['parcel_name'] ?? '')) ?>">
    </div>
    <div class="form-group">
        <label>كود الطرد *</label>
        <input type="text" name="parcel_code" class="form-control" required placeholder="مثال: SOCI أو REC" value="<?= e($parcelCode) ?>" pattern="[A-Za-z0-9]+" title="أحرف وأرقام فقط">
        <small class="text-muted">حرف أو مجموعة حروف تسبق الرقم. الكود النهائي = <strong>كود الطرد</strong> + <strong>7 أرقام عشوائية</strong> (مثل <strong>SOCI4829103</strong>). في الرسائل وتسليم المخزن يظهر الرقم فقط.</small>
        <input type="hidden" name="parcel_code_suffix" value="<?= e($suffix) ?>">
    </div>
    <div class="form-group">
        <label>عدد الشبابيك *</label>
        <input type="number" name="num_windows" class="form-control" min="1" required value="<?= e(old('num_windows', $defaultWindows)) ?>">
        <small class="text-muted">ثابت لكل يوم عمل. الطاقة اليومية = الشبابيك × مستفيد/شباك.</small>
    </div>
    <div class="form-group">
        <label>مستفيدون لكل شباك *</label>
        <input type="number" name="per_window_capacity" class="form-control" min="1" required value="<?= e(old('per_window_capacity', $c['per_window_capacity'] ?? '400')) ?>">
    </div>
    <div class="form-group">
        <label>تاريخ بدء التسليم *</label>
        <input type="date" name="delivery_start" class="form-control" required value="<?= e(old('delivery_start', $c['delivery_start'] ?? '')) ?>">
        <small class="text-muted">أيام العمل تُحسب بعد رفع Excel. <strong>الجمعة لا تُحسب</strong> يوم عمل، ويُحدَّث تاريخ النهاية تلقائياً عند التوليد.</small>
    </div>
    <div class="form-group">
        <label>تاريخ انتهاء التسليم (تقديري)</label>
        <input type="date" name="delivery_end" class="form-control" value="<?= e(old('delivery_end', $c['delivery_end'] ?? '')) ?>">
        <small class="text-muted">اختياري — يُستبدل بآخر يوم عمل فعلي بعد التوليد (مع تخطّي الجمعة).</small>
    </div>
    <input type="hidden" name="num_days" value="<?= e(old('num_days', $c['num_days'] ?? '1')) ?>">
    <div class="form-group">
        <label>اسم المخزن *</label>
        <input type="text" name="warehouse_name" class="form-control" required value="<?= e(old('warehouse_name', $c['warehouse_name'] ?? '')) ?>">
    </div>
    <div class="form-group">
        <label>موقع المخزن *</label>
        <input type="text" name="warehouse_location" class="form-control" required placeholder="عنوان أو رابط خرائط" value="<?= e(old('warehouse_location', $c['warehouse_location'] ?? '')) ?>">
    </div>
    <div class="form-group">
        <label>بداية الدوام</label>
        <input type="time" name="work_start" class="form-control" value="<?= e(old('work_start', substr($c['work_start'] ?? '09:00', 0, 5))) ?>">
    </div>
    <div class="form-group">
        <label>نهاية الدوام</label>
        <input type="time" name="work_end" class="form-control" value="<?= e(old('work_end', substr($c['work_end'] ?? '15:00', 0, 5))) ?>">
    </div>
    <div class="form-group">
        <label>الكمية الافتتاحية (اختياري)</label>
        <input type="number" name="opening_quantity" class="form-control" min="0" placeholder="0 = عدد المستفيدين عند التوليد" value="<?= e(old('opening_quantity', $c['opening_quantity'] ?? '0')) ?>">
    </div>
</div>
