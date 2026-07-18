<div class="guest-card">
    <div class="brand-row">
        <span class="brand-mark">DL</span>
        <div>
            <strong><?= e(config('app_name')) ?></strong>
            <div class="text-muted" style="font-size:0.8rem">الإعداد الأولي</div>
        </div>
    </div>
    <h1>إعداد النظام</h1>
    <p class="lead">أنشئ حساب مدير النظام للبدء.</p>
    <form method="post" action="<?= e(url('/setup')) ?>">
        <?= \App\Csrf::field() ?>
        <div class="form-group">
            <label class="field-label">الاسم</label>
            <input type="text" name="name" class="form-control" required value="<?= e(old('name', 'مدير النظام')) ?>">
        </div>
        <div class="form-group">
            <label class="field-label">البريد الإلكتروني</label>
            <input type="email" name="email" class="form-control" required value="<?= e(old('email')) ?>">
        </div>
        <div class="form-group">
            <label class="field-label">كلمة المرور (8+)</label>
            <input type="password" name="password" class="form-control" minlength="8" required>
        </div>
        <button type="submit" class="btn btn-lg" style="width:100%">إنشاء الحساب</button>
    </form>
</div>
