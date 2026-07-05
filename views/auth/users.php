<h1>إدارة المستخدمين</h1>
<div class="grid-2">
    <div class="card">
        <h2>المستخدمون</h2>
        <table class="table">
            <thead><tr><th>الاسم</th><th>البريد</th><th>الدور</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['name']) ?></td>
                <td><?= e($u['email']) ?></td>
                <td><?= e(\App\RoleHelper::label($u['role'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card">
        <h2>إضافة مستخدم</h2>
        <form method="post" action="<?= e(url('/users/create')) ?>">
            <?= \App\Csrf::field() ?>
            <div class="form-group">
                <label>الاسم</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="form-group">
                <label>البريد</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="form-group">
                <label>كلمة المرور</label>
                <input type="password" name="password" class="form-control" minlength="8" required>
            </div>
            <div class="form-group">
                <label>الدور</label>
                <select name="role" class="form-control">
                    <?php foreach ($roles as $key => $label): ?>
                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn">إضافة</button>
        </form>
    </div>
</div>
