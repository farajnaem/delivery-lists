(function () {
    'use strict';

    var cfg = window.WH_CONFIG || {};
    var campaignId = cfg.campaignId;
    var queueKey = 'wh_pending_' + campaignId;
    var pending = loadQueue();

    var elQuery = document.getElementById('searchQuery');
    var elResult = document.getElementById('searchResult');
    var elError = document.getElementById('searchError');
    var elSuccess = document.getElementById('searchSuccess');
    var elPending = document.getElementById('pendingSync');
    var elPendingCount = document.getElementById('pendingCount');
    var elOnline = document.getElementById('onlineStatus');
    var elBalance = document.getElementById('stockBalance');
    var elDelivered = document.getElementById('stockDelivered');
    var elRecent = document.getElementById('recentList');

    var currentBeneficiary = null;

    function loadQueue() {
        try {
            return JSON.parse(localStorage.getItem(queueKey) || '[]');
        } catch (e) {
            return [];
        }
    }

    function saveQueue() {
        localStorage.setItem(queueKey, JSON.stringify(pending));
        updatePendingUI();
    }

    function uuid() {
        if (crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'wh-' + Date.now() + '-' + Math.random().toString(36).slice(2);
    }

    function setOnline(online) {
        if (!elOnline) return;
        elOnline.textContent = online ? 'متصل' : 'دون اتصال';
        elOnline.classList.toggle('wh-status-offline', !online);
    }

    function updatePendingUI() {
        if (!elPending) return;
        if (pending.length > 0) {
            elPending.classList.remove('hidden');
            elPendingCount.textContent = pending.length;
        } else {
            elPending.classList.add('hidden');
        }
    }

    function hideAlerts() {
        elError && elError.classList.add('hidden');
        elSuccess && elSuccess.classList.add('hidden');
    }

    function showError(msg) {
        hideAlerts();
        if (elError) {
            elError.textContent = msg;
            elError.classList.remove('hidden');
        }
    }

    function showSuccess(msg) {
        hideAlerts();
        if (elSuccess) {
            elSuccess.textContent = msg;
            elSuccess.classList.remove('hidden');
        }
    }

    function api(path, options) {
        options = options || {};
        var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (options.json) {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-Token'] = cfg.csrf;
        }
        return fetch(cfg.apiBase + path, {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: headers,
            body: options.body ? JSON.stringify(options.body) : undefined
        }).then(function (r) {
            return r.json().then(function (data) {
                if (!r.ok) throw new Error(data.error || 'خطأ في الخادم');
                return data;
            });
        });
    }

    function renderBeneficiary(b) {
        var delivered = b.receipt_status === 'مستلم';
        var html = '<dl>';
        html += '<dt>الاسم</dt><dd>' + esc(b.name) + '</dd>';
        html += '<dt>الكود</dt><dd>' + esc(b.display_code || b.sort_order || b.disbursement_code || '—') + '</dd>';
        html += '<dt>الهوية</dt><dd>' + esc(b.national_id) + '</dd>';
        html += '<dt>موعد التسليم</dt><dd>' + esc(b.delivery_date || '—') + ' — شباك ' + esc(String(b.window_num || '—')) + '</dd>';
        html += '<dt>الحالة</dt><dd>';
        html += delivered
            ? '<span class="badge-delivered">مستلم</span>'
            : '<span class="badge-pending">قيد التسليم</span>';
        html += '</dd></dl>';

        if (!delivered && cfg.campaignActive) {
            html += '<div class="wh-result-actions">';
            html += '<button type="button" id="btnConfirm" class="wh-btn wh-btn-success wh-btn-block">تأكيد التسليم</button>';
            html += '</div>';
        } else if (delivered) {
            html += '<p class="text-muted" style="margin:0.5rem 0 0">تم التسليم ' + esc(b.delivered_at || '') + '</p>';
        }

        elResult.innerHTML = html;
        elResult.classList.remove('hidden');

        var btn = document.getElementById('btnConfirm');
        if (btn) {
            btn.addEventListener('click', function () {
                confirmDelivery(b);
            });
        }
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function prependRecent(b, deliveryType) {
        if (!elRecent) return;
        var empty = document.getElementById('recentEmpty');
        if (empty) empty.remove();
        var li = document.createElement('li');
        li.innerHTML = '<strong>' + esc(b.display_code || b.sort_order || b.disbursement_code || '') + '</strong> ' + esc(b.name) +
            '<small>الآن' + (deliveryType === 'late' ? ' — متأخر' : '') + '</small>';
        elRecent.insertBefore(li, elRecent.firstChild);
    }

    function refreshDeliveredList() {
        if (!elRecent || !navigator.onLine) return;
        api('/delivered?campaign_id=' + campaignId + '&limit=50')
            .then(function (data) {
                if (!data.delivered) return;
                elRecent.innerHTML = '';
                var empty = document.getElementById('recentEmpty');
                if (empty) empty.remove();
                if (data.delivered.length === 0) {
                    var p = document.createElement('p');
                    p.id = 'recentEmpty';
                    p.className = 'wh-sub';
                    p.textContent = 'لا مستلمين بعد — ستظهر القائمة هنا بعد كل تسليم.';
                    elRecent.parentNode.appendChild(p);
                } else {
                    data.delivered.forEach(function (b) {
                        var li = document.createElement('li');
                        li.innerHTML = '<strong>' + esc(b.display_code || b.sort_order || b.disbursement_code || '') + '</strong> ' + esc(b.name) +
                            '<small>' + esc(b.delivered_at || '') +
                            (b.delivery_type === 'late' ? ' — متأخر' : '') + '</small>';
                        elRecent.appendChild(li);
                    });
                }
                var totalEl = document.getElementById('deliveredTotal');
                if (totalEl && data.total !== undefined) totalEl.textContent = data.total;
            })
            .catch(function () {});
    }

    function updateStock(stock) {
        if (stock && elBalance) elBalance.textContent = stock.balance;
        if (stock && elDelivered) elDelivered.textContent = stock.delivered;
        var totalEl = document.getElementById('deliveredTotal');
        if (stock && totalEl) totalEl.textContent = stock.delivered;
    }

    function doSearch() {
        hideAlerts();
        elResult && elResult.classList.add('hidden');
        var q = (elQuery && elQuery.value || '').trim();
        if (!q) {
            showError('أدخل الكود أو رقم الهوية');
            return;
        }

        if (!navigator.onLine) {
            showError('لا يوجد اتصال — ابحث عند عودة الإنترنت');
            return;
        }

        api('/search?campaign_id=' + campaignId + '&q=' + encodeURIComponent(q))
            .then(function (data) {
                if (!data.beneficiary) {
                    showError('لم يُعثر على مستفيد');
                    return;
                }
                currentBeneficiary = data.beneficiary;
                renderBeneficiary(data.beneficiary);
            })
            .catch(function (e) {
                showError(e.message);
            });
    }

    function confirmDelivery(b) {
        var clientId = uuid();
        var item = {
            beneficiary_id: b.id,
            client_id: clientId,
            code: b.display_code || b.sort_order || b.disbursement_code,
            name: b.name,
            queued_at: new Date().toISOString()
        };

        if (!navigator.onLine) {
            pending.push(item);
            saveQueue();
            b.receipt_status = 'مستلم';
            renderBeneficiary(b);
            showSuccess('تم الحفظ محلياً — ستُزامَن عند عودة الاتصال');
            prependRecent(b, 'on_time');
            if (elBalance) elBalance.textContent = Math.max(0, parseInt(elBalance.textContent, 10) - 1);
            if (elDelivered) elDelivered.textContent = parseInt(elDelivered.textContent, 10) + 1;
            elQuery && elQuery.focus();
            return;
        }

        api('/deliver', {
            method: 'POST',
            json: true,
            body: { campaign_id: campaignId, beneficiary_id: b.id, client_id: clientId }
        }).then(function (data) {
            if (data.stock) updateStock(data.stock);
            renderBeneficiary(data.beneficiary || b);
            showSuccess(data.already ? 'كان مُسلَّماً مسبقاً' : 'تم تسجيل التسليم بنجاح');
            if (!data.already) prependRecent(data.beneficiary || b, data.delivery_type);
            elQuery && (elQuery.value = '', elQuery.focus());
            elResult && elResult.classList.add('hidden');
        }).catch(function (e) {
            if (!navigator.onLine || e.message.indexOf('fetch') !== -1) {
                pending.push(item);
                saveQueue();
                showSuccess('تم الحفظ محلياً — ستُزامَن عند عودة الاتصال');
            } else {
                showError(e.message);
            }
        });
    }

    function syncQueue() {
        if (pending.length === 0 || !navigator.onLine) return Promise.resolve();

        var batch = pending.slice();
        return api('/sync', {
            method: 'POST',
            json: true,
            body: { campaign_id: campaignId, items: batch }
        }).then(function (data) {
            var failedIds = {};
            (data.results || []).forEach(function (r) {
                if (!r.ok && !r.already) failedIds[r.beneficiary_id] = true;
            });
            pending = pending.filter(function (p) {
                return failedIds[p.beneficiary_id];
            });
            saveQueue();
            if (data.stock) updateStock(data.stock);
            if (data.synced > 0) {
                showSuccess('تمت مزامنة ' + data.synced + ' تسليم');
                refreshDeliveredList();
            }
        }).catch(function () {});
    }

    document.getElementById('btnSearch') && document.getElementById('btnSearch').addEventListener('click', doSearch);
    elQuery && elQuery.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            doSearch();
        }
    });

    document.getElementById('btnSyncNow') && document.getElementById('btnSyncNow').addEventListener('click', syncQueue);

    window.addEventListener('online', function () {
        setOnline(true);
        syncQueue();
    });
    window.addEventListener('offline', function () { setOnline(false); });

    setOnline(navigator.onLine);
    updatePendingUI();
    if (navigator.onLine) syncQueue();

    setInterval(function () {
        if (navigator.onLine && pending.length > 0) syncQueue();
    }, 30000);
})();
