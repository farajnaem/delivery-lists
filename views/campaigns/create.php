<h1>عملية توزيع جديدة</h1>
<p class="text-muted">ارفع ملف Excel المرشحين وأدخل بيانات الطرد والمخزن.</p>

<form method="post" action="<?= e(url('/campaigns/create')) ?>" enctype="multipart/form-data" class="card">
    <?= \App\Csrf::field() ?>

    <h2>ملف المرشحين</h2>
    <div class="form-group">
        <label>Excel (xlsx) *</label>
        <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls" required>
        <small class="text-muted">الأعمدة المعتمدة: <strong>اسم رب الأسرة</strong>، <strong>رقم الهوية</strong>، <strong>رقم التواصل</strong>، حالة الاستلام (اختياري). أعمدة مثل مركز الإيواء وتاريخ الترشيح تُتجاهل حالياً.</small>
    </div>

    <h2 style="margin-top:1.5rem">بيانات العملية</h2>
    <div class="grid-2">
        <div class="form-group">
            <label>اسم العملية *</label>
            <input type="text" name="name" class="form-control" required placeholder="مثال: طرود رمضان 2026" value="<?= e(old('name')) ?>">
        </div>
        <div class="form-group">
            <label>اسم الطرد *</label>
            <input type="text" name="parcel_name" class="form-control" required value="<?= e(old('parcel_name')) ?>">
        </div>
        <div class="form-group">
            <label>كود الطرد * (يبدأ بـ SOCI)</label>
            <input type="text" name="parcel_code" class="form-control" required placeholder="SOCI-R26" value="<?= e(old('parcel_code', 'SOCI')) ?>">
        </div>
        <div class="form-group">
            <label>عدد أيام التسليم *</label>
            <input type="number" name="num_days" id="numDays" class="form-control" min="1" required value="<?= e(old('num_days', '5')) ?>">
        </div>
        <div class="form-group">
            <label>تاريخ بدء التسليم *</label>
            <input type="date" name="delivery_start" class="form-control" required value="<?= e(old('delivery_start')) ?>">
        </div>
        <div class="form-group">
            <label>تاريخ انتهاء التسليم *</label>
            <input type="date" name="delivery_end" class="form-control" required value="<?= e(old('delivery_end')) ?>">
        </div>
        <div class="form-group">
            <label>اسم المخزن *</label>
            <input type="text" name="warehouse_name" class="form-control" required value="<?= e(old('warehouse_name')) ?>">
        </div>
        <div class="form-group">
            <label>موقع المخزن *</label>
            <input type="text" name="warehouse_location" class="form-control" required placeholder="عنوان أو رابط خرائط" value="<?= e(old('warehouse_location')) ?>">
        </div>
        <div class="form-group">
            <label>مستفيدون لكل شباك *</label>
            <input type="number" name="per_window_capacity" id="perWindow" class="form-control" min="1" required value="<?= e(old('per_window_capacity', '500')) ?>">
            <small class="text-muted">مثال: 2000 مستفيد/يوم ÷ 500 = <strong>4 شبابيك</strong> → 20 كشف (5 أيام × 4)</small>
        </div>
        <div class="form-group">
            <label>بداية الدوام</label>
            <input type="time" name="work_start" class="form-control" value="<?= e(old('work_start', '09:00')) ?>">
        </div>
        <div class="form-group">
            <label>نهاية الدوام</label>
            <input type="time" name="work_end" class="form-control" value="<?= e(old('work_end', '15:00')) ?>">
        </div>
        <div class="form-group">
            <label>الكمية الافتتاحية (اختياري)</label>
            <input type="number" name="opening_quantity" class="form-control" min="0" placeholder="مثال: 10200 — يُترك 0 لاستخدام عدد المستفيدين" value="<?= e(old('opening_quantity', '0')) ?>">
            <small class="text-muted">قد تختلف عن عدد المستفيدين في Excel (فائض مخزني).</small>
        </div>
    </div>

    <button type="submit" class="btn" style="margin-top:1rem">إنشاء واستيراد</button>
    <a href="<?= e(url('/')) ?>" class="btn btn-outline" style="margin-top:1rem">إلغاء</a>
</form>
