<?php
page_header(
    'نسخ احتياطي لقاعدة البيانات',
    [['label' => 'الإدارة'], ['label' => 'نسخ احتياطي']],
    [],
    'النسخ تُحفظ في database/backups — النوع الحالي: ' . (\App\DatabaseBackupService::isSqlite() ? 'SQLite' : 'MySQL')
);
?>

<div class="grid-2">
    <div class="card">
        <h2 class="panel-title" style="margin-bottom:0.5rem">إنشاء نسخة جديدة</h2>
        <p class="text-muted" style="margin-bottom:1rem">احفظ لقطة كاملة من قاعدة البيانات الحالية.</p>
        <form method="post" action="<?= e(url('/admin/database/backup')) ?>">
            <?= \App\Csrf::field() ?>
            <button type="submit" class="btn">نسخ احتياطي الآن</button>
        </form>
    </div>
    <div class="card" style="background:var(--warning-soft);border-color:#FDE68A">
        <h2 class="panel-title" style="margin-bottom:0.5rem">تنبيهات</h2>
        <ul class="text-muted" style="margin:0;padding-inline-start:1.2rem">
            <li>قبل الاستعادة يُنشأ نسخ أمان تلقائياً.</li>
            <li>على MySQL استخدم ملفات <code>.sql</code> فقط.</li>
            <li>لا ترفع ملفات النسخ على GitHub.</li>
        </ul>
    </div>
</div>

<div class="card table-panel" data-table-filterable>
    <div class="table-toolbar">
        <div>
            <div class="panel-title">النسخ المحفوظة</div>
        </div>
        <?php if (!empty($backups)): ?>
        <div class="table-toolbar-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" placeholder="بحث…" data-table-search aria-label="بحث النسخ">
        </div>
        <?php endif; ?>
    </div>
    <?php if (empty($backups)): ?>
    <div class="empty-state" data-empty-row>
        <strong>لا توجد نسخ احتياطية بعد</strong>
        <span>أنشئ نسخة من البطاقة أعلاه.</span>
    </div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr><th>الملف</th><th>الحجم</th><th>التاريخ</th><th>إجراءات</th></tr>
        </thead>
        <tbody>
        <?php foreach ($backups as $b): ?>
        <tr>
            <td><code><?= e($b['filename']) ?></code></td>
            <td><?= e(number_format($b['size'] / 1024, 1)) ?> KB</td>
            <td><?= e($b['created_at']) ?></td>
            <td>
                <div class="row-actions">
                    <form method="post" action="<?= e(url('/admin/database/restore')) ?>"
                          data-confirm="استعادة هذه النسخة؟ سيتم استبدال البيانات الحالية (يُنشأ نسخ أمان تلقائياً).">
                        <?= \App\Csrf::field() ?>
                        <input type="hidden" name="filename" value="<?= e($b['filename']) ?>">
                        <button type="submit" class="btn btn-outline btn-sm">استعادة</button>
                    </form>
                    <form method="post" action="<?= e(url('/admin/database/delete')) ?>"
                          data-confirm="حذف هذه النسخة الاحتياطية؟">
                        <?= \App\Csrf::field() ?>
                        <input type="hidden" name="filename" value="<?= e($b['filename']) ?>">
                        <button type="submit" class="btn btn-danger btn-sm">حذف</button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
