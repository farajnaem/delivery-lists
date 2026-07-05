<h1>تسجيل الدخول</h1>
<div class="card" style="max-width:420px;margin:2rem auto">
    <form method="post" action="<?= e(url('/login')) ?>">
        <?= \App\Csrf::field() ?>
        <div class="form-group">
            <label>البريد الإلكتروني</label>
            <input type="email" name="email" class="form-control" required autofocus>
        </div>
        <div class="form-group">
            <label>كلمة المرور</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn" style="width:100%">دخول</button>
    </form>
</div>
