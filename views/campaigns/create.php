<?php
page_header(
    'عملية توزيع جديدة',
    [
        ['label' => 'العمليات', 'url' => '/'],
        ['label' => 'عملية جديدة'],
    ],
    [],
    'ارفع ملف Excel المرشحين وأدخل بيانات الطرد والمخزن.'
);
?>

<form method="post" action="<?= e(url('/campaigns/create')) ?>" enctype="multipart/form-data" class="card">
    <?= \App\Csrf::field() ?>

    <div class="form-section">
        <div class="form-section-title">ملف المرشحين</div>
        <div class="form-section-desc">الأعمدة المعتمدة: اسم رب الأسرة، رقم الهوية، رقم التواصل، وحالة الاستلام (اختياري).</div>
        <div class="form-group">
            <label class="field-label">Excel (xlsx) *</label>
            <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls" required>
        </div>
    </div>

    <?php partial('campaigns/_form_fields', ['prefix' => 'create']); ?>

    <div class="actions-row" style="margin-top:0.5rem">
        <button type="submit" class="btn">إنشاء واستيراد</button>
        <a href="<?= e(url('/')) ?>" class="btn btn-outline">إلغاء</a>
    </div>
</form>
