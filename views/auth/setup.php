<h1>إعداد النظام</h1>
<p class="text-muted">أنشئ حساب مدير النظام للبدء.</p>
<div class="card">
    <form method="post" action="<?= e(url('/setup')) ?>">
        <?= \App\Csrf::field() ?>
        <div class="form-group">
            <label>الاسم</label>
            <input type="text" name="name" class="form-control" required value="<?= e(old('name', 'مدير النظام')) ?>">
        </div>
        <div class="form-group">
            <label>البريد الإلكتروني</label>
            <input type="email" name="email" class="form-control" required value="<?= e(old('email')) ?>">
        </div>
        <div class="form-group">
            <label>كلمة المرور (8+)</label>
            <input type="password" name="password" class="form-control" minlength="8" required>
        </div>
        <button type="submit" class="btn">إنشاء الحساب</button>
    </form>
</div>
