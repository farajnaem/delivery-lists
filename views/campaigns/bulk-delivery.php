<?php
/** @var array $campaign */
/** @var array $stock */
/** @var list<array> $pendingList */
/** @var list<array> $deliveredList */
/** @var list<array> $batches */
/** @var string $tab */

$tab = $tab ?? 'bulk';
$cid = (int) $campaign['id'];
$pendingCount = count($pendingList ?? []);
$deliveredCount = count($deliveredList ?? []);
$balance = (int) ($stock['balance'] ?? 0);

/** تجميع الكشف حسب اليوم ثم الشباك */
$pendingGroups = [];
foreach ($pendingList ?? [] as $row) {
    $day = (int) ($row['day_index'] ?? 0);
    $win = (int) ($row['window_num'] ?? 0);
    $pendingGroups[$day][$win][] = $row;
}
ksort($pendingGroups);
foreach ($pendingGroups as &$wins) {
    ksort($wins);
}
unset($wins);
?>

<?php
page_header(
    'تسليم جماعي وتصحيح',
    [
        ['label' => 'العمليات', 'url' => '/'],
        ['label' => $campaign['name'], 'url' => '/campaigns/view?id=' . $cid],
        ['label' => 'متابعة المخزن', 'url' => '/campaigns/stock?id=' . $cid],
        ['label' => 'تسليم جماعي'],
    ],
    [
        ['label' => 'متابعة المخزن', 'url' => '/campaigns/stock?id=' . $cid],
    ],
    'مدير النظام فقط — الكشف الكامل محدَّد، ألغِ من لم يستلم'
);
?>

<div class="grid-stats">
    <div class="stat-card">
        <div class="stat-label">بانتظار التسليم (الكشف)</div>
        <div class="stat-value"><?= ar_digits($pendingCount) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">مُسلَّم سابقاً</div>
        <div class="stat-value"><?= ar_digits((int) ($stock['delivered'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">الرصيد المتاح</div>
        <div class="stat-value"><?= ar_digits($balance) ?></div>
    </div>
</div>

<div class="card" style="margin-bottom:1rem">
    <div class="actions-row" style="gap:0.5rem;flex-wrap:wrap">
        <a class="btn <?= $tab === 'bulk' ? '' : 'btn-outline' ?>" href="<?= e(url('/campaigns/bulk-delivery?id=' . $cid . '&tab=bulk')) ?>">1) تسليم جماعي — الكشف الكامل</a>
        <a class="btn <?= $tab === 'correct' ? '' : 'btn-outline' ?>" href="<?= e(url('/campaigns/bulk-delivery?id=' . $cid . '&tab=correct')) ?>">2) تصحيح فردي</a>
        <a class="btn <?= $tab === 'batches' ? '' : 'btn-outline' ?>" href="<?= e(url('/campaigns/bulk-delivery?id=' . $cid . '&tab=batches')) ?>">3) الدفعات والتراجع</a>
    </div>
</div>

<?php if ($tab === 'bulk'): ?>
<div class="card">
    <h2 class="panel-title">الكشف الكامل — بانتظار التسليم</h2>
    <div class="callout" style="margin:0.75rem 0 1rem;padding:0.85rem 1rem;background:var(--fill-secondary, #f1f5f9);border-radius:10px">
        <strong>طريقة العمل:</strong>
        كل الأسماء في الكشف <strong>محدَّدة للتسليم</strong>.
        ألغِ التحديد فقط عن من <strong>لم يأتوا للاستلام</strong>، ثم اكتب السبب واضغط التأكيد.
    </div>

    <?php if ($pendingCount === 0): ?>
    <p class="text-muted" style="font-size:1.05rem">
        لا يوجد أحد «بانتظار التسليم» في هذه العملية — إما الكل مُسلَّم، أو لم يُولَّد الكشف بعد.
        للتصحيح استخدم تبويب «تصحيح فردي».
    </p>
    <?php else: ?>
    <form method="post" action="<?= e(url('/campaigns/bulk-delivery/confirm')) ?>" id="bulk-form"
          data-confirm="تأكيد تسليم المحددين من الكشف؟ يمكن التراجع لاحقاً من تبويب الدفعات.">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= $cid ?>">
        <input type="hidden" name="deliver_mode" value="all_except">
        <div id="exclude-ids-container"></div>

        <div class="actions-row" style="margin-bottom:0.75rem;align-items:center">
            <button type="button" class="btn btn-outline btn-sm" data-bulk-select="all">تحديد كل الكشف</button>
            <button type="button" class="btn btn-outline btn-sm" data-bulk-select="none">إلغاء تحديد الكل</button>
            <input type="search" id="bulk-search" class="form-control" placeholder="بحث بالاسم أو الكود أو الهوية…" style="max-width:280px">
            <strong id="bulk-selected-count" style="margin-inline-start:auto">
                سيُسلَّم: <?= ar_digits($pendingCount) ?> / <?= ar_digits($pendingCount) ?>
            </strong>
        </div>

        <label class="form-label">سبب التسليم الجماعي <span class="text-danger">*</span></label>
        <input type="text" name="reason" class="form-control" required maxlength="500"
               placeholder="مثال: مطابقة سريعة — تسليم كامل ما عدا من لم يحضر">

        <div id="bulk-sheet" style="margin-top:1rem">
            <?php foreach ($pendingGroups as $day => $windows): ?>
                <?php
                $dayCount = 0;
                foreach ($windows as $rows) {
                    $dayCount += count($rows);
                }
                $dayDate = '';
                foreach ($windows as $rows) {
                    if (!empty($rows[0]['delivery_date'])) {
                        $dayDate = (string) $rows[0]['delivery_date'];
                        break;
                    }
                }
                ?>
                <div class="card" style="margin-bottom:1rem;padding:0;overflow:hidden" data-bulk-day="<?= (int) $day ?>">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;padding:0.75rem 1rem;background:var(--fill-tertiary, #e2e8f0)">
                        <div>
                            <strong>اليوم <?= ar_digits((int) $day) ?></strong>
                            <?php if ($dayDate !== ''): ?>
                            <span class="text-muted">— <?= e($dayDate) ?></span>
                            <?php endif; ?>
                            <span class="text-muted">(<?= ar_digits($dayCount) ?> مستفيد)</span>
                        </div>
                        <div class="actions-row" style="gap:0.35rem">
                            <button type="button" class="btn btn-ghost btn-sm" data-day-select="all" data-day="<?= (int) $day ?>">تحديد اليوم</button>
                            <button type="button" class="btn btn-ghost btn-sm" data-day-select="none" data-day="<?= (int) $day ?>">إلغاء اليوم</button>
                        </div>
                    </div>

                    <?php foreach ($windows as $win => $rows): ?>
                    <div style="padding:0.5rem 1rem 0.75rem;border-top:1px solid var(--stroke-tertiary, #cbd5e1)">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.4rem">
                            <strong style="font-size:0.95rem">الشباك <?= ar_digits((int) $win) ?> — <?= ar_digits(count($rows)) ?></strong>
                            <div class="actions-row" style="gap:0.25rem">
                                <button type="button" class="btn btn-ghost btn-sm" data-win-select="all" data-day="<?= (int) $day ?>" data-win="<?= (int) $win ?>">تحديد</button>
                                <button type="button" class="btn btn-ghost btn-sm" data-win-select="none" data-day="<?= (int) $day ?>" data-win="<?= (int) $win ?>">إلغاء</button>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="data-table bulk-window-table" data-day="<?= (int) $day ?>" data-win="<?= (int) $win ?>">
                                <thead>
                                    <tr>
                                        <th style="width:3rem">تسليم؟</th>
                                        <th>الكود</th>
                                        <th>الاسم</th>
                                        <th>الهوية</th>
                                        <th>الوقت</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $searchBlob = mb_strtolower(
                                        trim(
                                            ($row['display_code'] ?? '') . ' '
                                            . ($row['name'] ?? '') . ' '
                                            . ($row['national_id'] ?? '') . ' '
                                            . ($row['disbursement_code'] ?? '') . ' '
                                            . ($row['sort_order'] ?? '')
                                        ),
                                        'UTF-8'
                                    );
                                    ?>
                                    <tr data-search="<?= e($searchBlob) ?>">
                                        <td>
                                            <input type="checkbox"
                                                   class="bulk-check"
                                                   value="<?= (int) $row['id'] ?>"
                                                   data-day="<?= (int) $day ?>"
                                                   data-win="<?= (int) $win ?>"
                                                   checked>
                                        </td>
                                        <td><?= e($row['display_code'] ?? $row['sort_order'] ?? $row['disbursement_code'] ?? '') ?></td>
                                        <td><?= e($row['name'] ?? '') ?></td>
                                        <td><?= e($row['national_id'] ?? '') ?></td>
                                        <td>
                                            <?php
                                            $tf = trim((string) ($row['time_from'] ?? ''));
                                            $tt = trim((string) ($row['time_to'] ?? ''));
                                            echo e($tf !== '' || $tt !== '' ? trim($tf . ' – ' . $tt, ' –') : '—');
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="actions-row" style="margin-top:1.25rem;position:sticky;bottom:0;background:var(--bg-elevated, #fff);padding:0.75rem 0;border-top:1px solid var(--stroke-tertiary, #e2e8f0)">
            <button type="submit" class="btn btn-lg" id="bulk-submit" <?= $balance <= 0 ? 'disabled' : '' ?>>
                تأكيد تسليم المحددين من الكشف
            </button>
            <span class="text-muted" id="bulk-exclude-hint">المستبعدون (لم يستلموا): 0</span>
            <?php if ($balance <= 0): ?>
            <span class="text-danger">لا يوجد رصيد متاح — عدّل الكمية الافتتاحية أولاً.</span>
            <?php elseif ($balance < $pendingCount): ?>
            <span class="text-danger">تنبيه: الرصيد (<?= ar_digits($balance) ?>) أقل من عدد الكشف (<?= ar_digits($pendingCount) ?>).</span>
            <?php endif; ?>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
(function () {
    var form = document.getElementById('bulk-form');
    if (!form) return;
    var total = <?= (int) $pendingCount ?>;
    var excludeBox = document.getElementById('exclude-ids-container');
    var countEl = document.getElementById('bulk-selected-count');
    var hintEl = document.getElementById('bulk-exclude-hint');
    var searchEl = document.getElementById('bulk-search');

    function checks() {
        return Array.prototype.slice.call(form.querySelectorAll('.bulk-check'));
    }

    function refresh() {
        var list = checks();
        var selected = list.filter(function (c) { return c.checked; }).length;
        var excluded = total - selected;
        if (countEl) {
            countEl.textContent = 'سيُسلَّم: ' + selected + ' / ' + total;
        }
        if (hintEl) {
            hintEl.textContent = 'المستبعدون (لم يستلموا): ' + excluded;
        }
    }

    function setChecks(nodes, on) {
        nodes.forEach(function (c) { c.checked = !!on; });
        refresh();
    }

    form.querySelectorAll('[data-bulk-select]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setChecks(checks(), btn.getAttribute('data-bulk-select') === 'all');
        });
    });

    form.querySelectorAll('[data-day-select]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var day = btn.getAttribute('data-day');
            var on = btn.getAttribute('data-day-select') === 'all';
            setChecks(checks().filter(function (c) { return c.getAttribute('data-day') === day; }), on);
        });
    });

    form.querySelectorAll('[data-win-select]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var day = btn.getAttribute('data-day');
            var win = btn.getAttribute('data-win');
            var on = btn.getAttribute('data-win-select') === 'all';
            setChecks(checks().filter(function (c) {
                return c.getAttribute('data-day') === day && c.getAttribute('data-win') === win;
            }), on);
        });
    });

    form.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('bulk-check')) refresh();
    });

    if (searchEl) {
        searchEl.addEventListener('input', function () {
            var q = (searchEl.value || '').trim().toLowerCase();
            form.querySelectorAll('#bulk-sheet tr[data-search]').forEach(function (tr) {
                var blob = tr.getAttribute('data-search') || '';
                tr.style.display = (!q || blob.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    form.addEventListener('submit', function () {
        if (!excludeBox) return;
        excludeBox.innerHTML = '';
        checks().forEach(function (c) {
            if (c.checked) return;
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'exclude_ids[]';
            input.value = c.value;
            excludeBox.appendChild(input);
        });
    });

    refresh();
})();
</script>
<?php endif; ?>

<?php if ($tab === 'correct'): ?>
<div class="card">
    <h2 class="panel-title">تصحيح حالة الاستلام</h2>
    <p class="text-muted">
        حدّد مستفيدين لتسجيلهم <strong>مستلم</strong> أو إرجاعهم إلى <strong>قيد التسليم</strong>.
        السبب إلزامي. الجوالات تتحدث بعد المزامنة.
    </p>

    <form method="post" action="<?= e(url('/campaigns/bulk-delivery/correct')) ?>"
          data-confirm="تأكيد تصحيح الحالات المحددة؟">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="campaign_id" value="<?= $cid ?>">

        <label class="form-label">سبب التصحيح <span class="text-danger">*</span></label>
        <input type="text" name="reason" class="form-control" required maxlength="500"
               placeholder="مثال: تصحيح مطابقة — لم يستلم / استلم ولم يُسجَّل">

        <div class="grid-2" style="margin-top:1.25rem;gap:1rem;display:grid;grid-template-columns:1fr 1fr">
            <div>
                <h3 class="panel-title" style="font-size:1rem">تسجيل كمستلم (<?= ar_digits($pendingCount) ?>)</h3>
                <p class="text-muted" style="font-size:0.9rem">من «قيد التسليم» → «مستلم»</p>
                <?php if ($pendingCount === 0): ?>
                <p class="text-muted">لا يوجد.</p>
                <?php else: ?>
                <input type="search" class="form-control" placeholder="بحث…" data-filter-table="correct-pending" style="margin-bottom:0.5rem">
                <div class="table-wrap" style="max-height:360px;overflow:auto">
                    <table class="data-table" id="correct-pending">
                        <thead><tr><th></th><th>الكود</th><th>الاسم</th><th>شباك</th></tr></thead>
                        <tbody>
                        <?php foreach ($pendingList as $row): ?>
                            <tr>
                                <td><input type="checkbox" name="to_deliver[]" value="<?= (int) $row['id'] ?>"></td>
                                <td><?= e($row['display_code'] ?? $row['sort_order'] ?? '') ?></td>
                                <td><?= e($row['name'] ?? '') ?></td>
                                <td><?= ar_digits((int) ($row['window_num'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <div>
                <h3 class="panel-title" style="font-size:1rem">إرجاع لغير مستلم (<?= ar_digits($deliveredCount) ?>)</h3>
                <p class="text-muted" style="font-size:0.9rem">من «مستلم» → «قيد التسليم»</p>
                <?php if ($deliveredCount === 0): ?>
                <p class="text-muted">لا يوجد.</p>
                <?php else: ?>
                <input type="search" class="form-control" placeholder="بحث…" data-filter-table="correct-delivered" style="margin-bottom:0.5rem">
                <div class="table-wrap" style="max-height:360px;overflow:auto">
                    <table class="data-table" id="correct-delivered">
                        <thead><tr><th></th><th>الكود</th><th>الاسم</th><th>وقت التسليم</th></tr></thead>
                        <tbody>
                        <?php foreach ($deliveredList as $row): ?>
                            <tr>
                                <td><input type="checkbox" name="to_undeliver[]" value="<?= (int) $row['id'] ?>"></td>
                                <td><?= e($row['display_code'] ?? $row['sort_order'] ?? '') ?></td>
                                <td><?= e($row['name'] ?? '') ?></td>
                                <td><?= e($row['delivered_at'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="actions-row" style="margin-top:1rem">
            <button type="submit" class="btn">تطبيق التصحيح</button>
        </div>
    </form>
</div>

<script>
(function () {
    document.querySelectorAll('[data-filter-table]').forEach(function (input) {
        var tableId = input.getAttribute('data-filter-table');
        var table = document.getElementById(tableId);
        if (!table) return;
        input.addEventListener('input', function () {
            var q = (input.value || '').trim().toLowerCase();
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                var text = (tr.textContent || '').toLowerCase();
                tr.style.display = !q || text.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    });
})();
</script>
<?php endif; ?>

<?php if ($tab === 'batches'): ?>
<div class="card">
    <h2 class="panel-title">دفعات التسليم الجماعي</h2>
    <p class="text-muted">التراجع يعيد فقط مستفيدي تلك الدفعة إلى «قيد التسليم».</p>

    <?php if (empty($batches)): ?>
    <p class="text-muted">لا توجد دفعات بعد.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>السبب</th>
                    <th>العدد</th>
                    <th>بواسطة</th>
                    <th>التاريخ</th>
                    <th>الحالة</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($batches as $b): ?>
                <?php
                $undone = !empty($b['undone_at']);
                $active = (int) ($b['active_count'] ?? 0);
                ?>
                <tr>
                    <td><?= ar_digits((int) $b['id']) ?></td>
                    <td><?= e($b['reason'] ?? '') ?></td>
                    <td><?= ar_digits((int) ($b['delivered_count'] ?? 0)) ?></td>
                    <td><?= e($b['created_by_name'] ?? '') ?></td>
                    <td><?= e($b['created_at'] ?? '') ?></td>
                    <td>
                        <?php if ($undone): ?>
                        <span class="badge badge-pending">تم التراجع</span>
                        <?php elseif ($active > 0): ?>
                        <span class="badge badge-ok">نشطة (<?= ar_digits($active) ?>)</span>
                        <?php else: ?>
                        <span class="badge badge-pending">بدون مستفيدين نشطين</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$undone && $active > 0): ?>
                        <form method="post" action="<?= e(url('/campaigns/bulk-delivery/undo')) ?>"
                              data-confirm="التراجع عن الدفعة #<?= (int) $b['id'] ?>؟"
                              class="actions-row" style="flex-wrap:wrap">
                            <?= \App\Csrf::field() ?>
                            <input type="hidden" name="campaign_id" value="<?= $cid ?>">
                            <input type="hidden" name="batch_id" value="<?= (int) $b['id'] ?>">
                            <input type="text" name="reason" class="form-control" required maxlength="500"
                                   placeholder="سبب التراجع" style="min-width:160px">
                            <button type="submit" class="btn btn-outline btn-sm">تراجع</button>
                        </form>
                        <?php elseif ($undone): ?>
                        <span class="text-muted"><?= e($b['undo_reason'] ?? '') ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
