<h1>نسخ احتياطي لقاعدة البيانات</h1>
<p class="text-muted">
    النسخ تُحفظ في مجلد <code>database/backups</code> على نفس الجهاز.
    النوع الحالي: <strong><?= \App\DatabaseBackupService::isSqlite() ? 'SQLite' : 'MySQL' ?></strong>
</p>

<div class="card">
    <h2>إنشاء نسخة جديدة</h2>
    <form method="post" action="<?= e(url('/admin/database/backup')) ?>">
        <?= \App\Csrf::field() ?>
        <button type="submit" class="btn">نسخ احتياطي الآن</button>
    </form>
</div>

<div class="card">
    <h2>النسخ المحفوظة</h2>
    <?php if (empty($backups)): ?>
    <p class="text-muted">لا توجد نسخ احتياطية بعد.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead>
            <tr><th>الملف</th><th>الحجم</th><th>التاريخ</th><th>إجراءات</th></tr>
        </thead>
        <tbody>
        <?php foreach ($backups as $b): ?>
        <tr>
            <td><code><?= e($b['filename']) ?></code></td>
            <td><?= number_format($b['size'] / 1024, 1) ?> KB</td>
            <td><?= e($b['created_at']) ?></td>
            <td>
                <form method="post" action="<?= e(url('/admin/database/restore')) ?>" style="display:inline"
                      data-confirm="استعادة هذه النسخة؟ سيتم استبدال البيانات الحالية (يُنشأ نسخ أمان تلقائياً).">
                    <?= \App\Csrf::field() ?>
                    <input type="hidden" name="filename" value="<?= e($b['filename']) ?>">
                    <button type="submit" class="btn btn-outline btn-sm">استعادة</button>
                </form>
                <form method="post" action="<?= e(url('/admin/database/delete')) ?>" style="display:inline"
                      data-confirm="حذف هذه النسخة الاحتياطية؟">
                    <?= \App\Csrf::field() ?>
                    <input type="hidden" name="filename" value="<?= e($b['filename']) ?>">
                    <button type="submit" class="btn btn-outline btn-sm" style="color:#dc2626">حذف</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<div class="card" style="background:#fffbeb;border-color:#f59e0b">
    <h2>تنبيهات</h2>
    <ul class="text-muted">
        <li>قبل الاستعادة يُنشأ نسخ أمان تلقائياً من البيانات الحالية.</li>
        <li>على السيرفر (Coolify/MySQL) استخدم نسخ <code>.sql</code> فقط عندما يكون النظام على MySQL.</li>
        <li>لا ترفع ملفات النسخ الاحتياطي على GitHub — المجلد مستثنى في <code>.gitignore</code>.</li>
    </ul>
</div>

<script>
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
        if (!confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
});
</script>
