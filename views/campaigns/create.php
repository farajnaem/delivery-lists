<h1>عملية توزيع جديدة</h1>
<?php context_nav([
    ['label' => 'العمليات', 'url' => '/'],
    ['label' => 'عملية جديدة'],
]); ?>
<p class="text-muted">ارفع ملف Excel المرشحين وأدخل بيانات الطرد والمخزن.</p>

<form method="post" action="<?= e(url('/campaigns/create')) ?>" enctype="multipart/form-data" class="card">
    <?= \App\Csrf::field() ?>

    <h2>ملف المرشحين</h2>
    <div class="form-group">
        <label>Excel (xlsx) *</label>
        <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls" required>
        <small class="text-muted">الأعمدة المعتمدة: <strong>اسم رب الأسرة</strong>، <strong>رقم الهوية</strong>، <strong>رقم التواصل</strong>، حالة الاستلام (اختياري).</small>
    </div>

    <?php partial('campaigns/_form_fields', ['prefix' => 'create']); ?>

    <button type="submit" class="btn" style="margin-top:1rem">إنشاء واستيراد</button>
    <a href="<?= e(url('/')) ?>" class="btn btn-outline" style="margin-top:1rem">إلغاء</a>
</form>
