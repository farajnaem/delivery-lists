<?php use App\Auth; use App\RoleHelper;

$currentId = (int) (Auth::id() ?? 0);

page_header(
    'المستخدمون',
    [['label' => 'الإدارة'], ['label' => 'المستخدمون']],
    [
        [
            'label' => '+ إضافة مستخدم',
            'primary' => true,
            'class' => 'btn',
            'modal' => 'modal-create-user',
        ],
    ],
    'إدارة حسابات الدخول للويب وتطبيق المخزن.'
);
?>

<details class="card" style="margin-bottom:1.25rem">
    <summary style="cursor:pointer;font-weight:650;list-style:none;display:flex;align-items:center;justify-content:space-between;gap:1rem">
        <span>الأدوار والصلاحيات</span>
        <span class="text-muted" style="font-size:0.82rem;font-weight:500">عرض</span>
    </summary>
    <div class="role-chips" style="margin-top:1rem">
        <?php foreach ($roles as $key => $label): ?>
        <span class="role-chip" title="<?= e(implode(' · ', RoleHelper::permissions($key))) ?>">
            <strong><?= e($label) ?></strong>
        </span>
        <?php endforeach; ?>
    </div>
    <p class="text-muted" style="margin-top:0.85rem;font-size:0.85rem">
        تطبيق أندرويد يعمل لحسابات <strong>أمين المخزن</strong> و<strong>مدير النظام</strong> بعد توليد الكشوف.
    </p>
</details>

<div class="card table-panel" data-table-filterable>
    <div class="table-toolbar">
        <div>
            <div class="panel-title">قائمة المستخدمين</div>
            <div class="panel-subtitle"><?= ar_digits(count($users)) ?> حساب</div>
        </div>
        <div class="table-toolbar-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" placeholder="بحث بالاسم أو البريد…" data-table-search aria-label="بحث المستخدمين">
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>الاسم</th>
                    <th>البريد</th>
                    <th>الدور</th>
                    <th>الحالة</th>
                    <th style="width:1%">إجراءات</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($users)): ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state" data-empty-row>
                            <strong>لا يوجد مستخدمون</strong>
                            <span>أضف أول حساب من زر «إضافة مستخدم».</span>
                        </div>
                    </td>
                </tr>
            <?php else: foreach ($users as $u): ?>
                <?php $uid = (int) $u['id']; ?>
                <tr>
                    <td><strong><?= e($u['name']) ?></strong></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge badge-neutral"><?= e(RoleHelper::label($u['role'])) ?></span></td>
                    <td>
                        <?php if (((int) ($u['is_active'] ?? 1)) === 1): ?>
                        <span class="badge badge-ok">نشط</span>
                        <?php else: ?>
                        <span class="badge badge-danger">معطّل</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <button type="button" class="btn btn-sm btn-outline" data-modal-open="modal-edit-user-<?= $uid ?>">تعديل</button>
                            <?php if ($uid !== $currentId): ?>
                            <form method="post" action="<?= e(url('/users/delete')) ?>"
                                  data-confirm="حذف المستخدم «<?= e($u['name']) ?>» نهائياً؟ لا يمكن التراجع.">
                                <?= \App\Csrf::field() ?>
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <button type="submit" class="btn btn-sm btn-danger">حذف</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-backdrop" id="modal-create-user" data-modal role="dialog" aria-modal="true" aria-labelledby="create-user-title" hidden>
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title" id="create-user-title">إضافة مستخدم</h2>
            <button type="button" class="icon-btn" data-modal-close aria-label="إغلاق">
                <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <form method="post" action="<?= e(url('/users/create')) ?>">
            <?= \App\Csrf::field() ?>
            <div class="modal-body">
                <div class="form-group">
                    <label class="field-label">الاسم</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="field-label">البريد</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="field-label">كلمة المرور</label>
                    <input type="password" name="password" class="form-control" minlength="8" required>
                    <span class="field-hint">8 أحرف على الأقل</span>
                </div>
                <div class="form-group">
                    <label class="field-label">الدور</label>
                    <select name="role" class="form-control">
                        <?php foreach ($roles as $key => $label): ?>
                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>إلغاء</button>
                <button type="submit" class="btn">إضافة</button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($users as $u): ?>
<?php $uid = (int) $u['id']; ?>
<div class="modal-backdrop" id="modal-edit-user-<?= $uid ?>" data-modal role="dialog" aria-modal="true" hidden>
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title">تعديل — <?= e($u['name']) ?></h2>
            <button type="button" class="icon-btn" data-modal-close aria-label="إغلاق">
                <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <form method="post" action="<?= e(url('/users/update')) ?>">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="user_id" value="<?= $uid ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="field-label">الاسم</label>
                    <input type="text" name="name" class="form-control" required value="<?= e($u['name']) ?>">
                </div>
                <div class="form-group">
                    <label class="field-label">البريد</label>
                    <input type="email" name="email" class="form-control" required value="<?= e($u['email']) ?>">
                </div>
                <div class="form-group">
                    <label class="field-label">كلمة مرور جديدة</label>
                    <input type="password" name="password" class="form-control" minlength="8" placeholder="اتركها فارغة للإبقاء">
                </div>
                <div class="form-group">
                    <label class="field-label">الدور</label>
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
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>إلغاء</button>
                <button type="submit" class="btn">حفظ</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>
