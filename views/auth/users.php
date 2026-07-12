<?php use App\Auth; use App\RoleHelper; ?>
<h1>إدارة المستخدمين</h1>

<div class="card" style="margin-bottom:1rem">
    <h2>الأدوار والصلاحيات</h2>
    <div class="table-wrap">
    <table class="table">
        <thead>
            <tr><th>الدور</th><th>الصلاحيات</th></tr>
        </thead>
        <tbody>
        <?php foreach ($roles as $key => $label): ?>
        <tr>
            <td><strong><?= e($label) ?></strong></td>
            <td>
                <ul style="margin:0;padding-right:1.2rem">
                <?php foreach (RoleHelper::permissions($key) as $perm): ?>
                    <li><?= e($perm) ?></li>
                <?php endforeach; ?>
                </ul>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="text-muted">تطبيق أندرويد يعمل فقط لحسابات <strong>أمين المخزن</strong> و<strong>مدير النظام</strong>، وتظهر فيه العمليات بعد <strong>توليد الكشوف</strong>.</p>
</div>

<div class="grid-2">
    <div class="card">
        <h2>المستخدمون</h2>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>الاسم</th><th>البريد</th><th>الدور</th><th>الحالة</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['name']) ?></td>
                <td><?= e($u['email']) ?></td>
                <td><?= e(RoleHelper::label($u['role'])) ?></td>
                <td><?= ((int) ($u['is_active'] ?? 1)) === 1 ? 'نشط' : 'معطّل' ?></td>
                <td>
                    <details>
                        <summary>تعديل</summary>
                        <form method="post" action="<?= e(url('/users/update')) ?>" style="margin-top:0.75rem">
                            <?= \App\Csrf::field() ?>
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <div class="form-group">
                                <label>الاسم</label>
                                <input type="text" name="name" class="form-control" required value="<?= e($u['name']) ?>">
                            </div>
                            <div class="form-group">
                                <label>البريد</label>
                                <input type="email" name="email" class="form-control" required value="<?= e($u['email']) ?>">
                            </div>
                            <div class="form-group">
                                <label>كلمة مرور جديدة (اختياري)</label>
                                <input type="password" name="password" class="form-control" minlength="8" placeholder="اتركها فارغة للإبقاء على الحالية">
                            </div>
                            <div class="form-group">
                                <label>الدور</label>
                                <select name="role" class="form-control">
                                    <?php foreach ($roles as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= ($u['role'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="is_active" value="1" <?= ((int) ($u['is_active'] ?? 1)) === 1 ? 'checked' : '' ?>>
                                    الحساب نشط
                                </label>
                            </div>
                            <button type="submit" class="btn btn-sm">حفظ التعديلات</button>
                        </form>
                        <?php if ((int) $u['id'] !== (int) (Auth::id() ?? 0)): ?>
                        <form method="post" action="<?= e(url('/users/deactivate')) ?>" style="margin-top:0.5rem" data-confirm="تعطيل هذا المستخدم؟ لن يستطيع تسجيل الدخول.">
                            <?= \App\Csrf::field() ?>
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm" style="border-color:#b91c1c;color:#b91c1c">تعطيل الحساب</button>
                        </form>
                        <?php endif; ?>
                    </details>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
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
