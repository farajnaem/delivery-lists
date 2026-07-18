<div class="guest-card">
    <div class="brand-row">
        <span class="brand-mark">DL</span>
        <div>
            <strong><?= e(config('app_name')) ?></strong>
            <div class="text-muted" style="font-size:0.8rem">تسجيل الدخول</div>
        </div>
    </div>
    <h1>مرحباً بعودتك</h1>
    <p class="lead">سجّل الدخول لإدارة عمليات التوزيع والتسليم.</p>
    <form method="post" action="<?= e(url('/login')) ?>">
        <?= \App\Csrf::field() ?>
        <div class="form-group">
            <label class="field-label">البريد الإلكتروني</label>
            <input type="email" name="email" class="form-control" required autofocus autocomplete="username">
        </div>
        <div class="form-group">
            <label class="field-label">كلمة المرور</label>
            <input type="password" name="password" class="form-control" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-lg" style="width:100%">دخول</button>
    </form>
</div>
