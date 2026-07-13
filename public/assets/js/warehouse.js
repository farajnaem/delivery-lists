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

    // ——— تخزين محلي للمستفيدين (IndexedDB) للبحث أوفلاين ———
    var DB_NAME = 'wh_offline';
    var STORE = 'beneficiaries';
    var dbPromise = null;
    var snapshotKey = 'wh_snap_token_' + campaignId;

    function openDb() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise(function (resolve, reject) {
            if (!('indexedDB' in window)) {
                reject(new Error('no-indexeddb'));
                return;
            }
            var req = indexedDB.open(DB_NAME, 1);
            req.onupgradeneeded = function () {
                var db = req.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    db.createObjectStore(STORE, { keyPath: 'key' });
                }
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
        return dbPromise;
    }

    function beneficiaryKey(id) {
        return campaignId + '_' + id;
    }

    function storeBeneficiaries(list) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE, 'readwrite');
                var store = tx.objectStore(STORE);
                list.forEach(function (b) {
                    store.put({
                        key: beneficiaryKey(b.id),
                        campaignId: campaignId,
                        id: b.id,
                        name: b.name || '',
                        national_id: String(b.national_id || ''),
                        mobile: b.mobile || '',
                        display_code: String(b.display_code || b.sort_order || b.disbursement_code || ''),
                        disbursement_code: String(b.disbursement_code || ''),
                        sort_order: b.sort_order || null,
                        receipt_status: b.receipt_status || '',
                        delivery_date: b.delivery_date || null,
                        window_num: b.window_num || null,
                        delivered_at: b.delivered_at || null
                    });
                });
                tx.oncomplete = function () { resolve(true); };
                tx.onerror = function () { reject(tx.error); };
            });
        }).catch(function () { return false; });
    }

    function updateLocalBeneficiary(id, changes) {
        return openDb().then(function (db) {
            return new Promise(function (resolve) {
                var tx = db.transaction(STORE, 'readwrite');
                var store = tx.objectStore(STORE);
                var getReq = store.get(beneficiaryKey(id));
                getReq.onsuccess = function () {
                    var rec = getReq.result;
                    if (rec) {
                        Object.keys(changes).forEach(function (k) { rec[k] = changes[k]; });
                        store.put(rec);
                    }
                };
                tx.oncomplete = function () { resolve(true); };
                tx.onerror = function () { resolve(false); };
            });
        }).catch(function () { return false; });
    }

    function normalizeCode(s) {
        return String(s == null ? '' : s).replace(/\s+/g, '').toUpperCase();
    }

    function localSearch(rawQuery) {
        var q = (rawQuery || '').trim();
        if (!q) return Promise.resolve(null);
        var norm = q.replace(/\s+/g, '');
        var normUpper = norm.toUpperCase();

        return openDb().then(function (db) {
            return new Promise(function (resolve) {
                var tx = db.transaction(STORE, 'readonly');
                var store = tx.objectStore(STORE);
                var req = store.openCursor();
                var found = null;
                req.onsuccess = function () {
                    var cursor = req.result;
                    if (!cursor || found) {
                        resolve(found);
                        return;
                    }
                    var b = cursor.value;
                    if (b.campaignId === campaignId) {
                        var nid = String(b.national_id || '').replace(/\s+/g, '');
                        if (
                            (nid && nid === norm) ||
                            (b.display_code && normalizeCode(b.display_code) === normUpper) ||
                            (b.disbursement_code && normalizeCode(b.disbursement_code) === normUpper)
                        ) {
                            found = b;
                        }
                    }
                    cursor.continue();
                };
                req.onerror = function () { resolve(null); };
            });
        }).catch(function () { return null; });
    }

    function downloadSnapshot() {
        if (!navigator.onLine) return Promise.resolve(false);
        return api('/snapshot?campaign_id=' + campaignId)
            .then(function (data) {
                if (data && data.beneficiaries) {
                    localStorage.setItem(snapshotKey, data.sync_token || '');
                    return storeBeneficiaries(data.beneficiaries);
                }
                return false;
            })
            .catch(function () { return false; });
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
        var retryOnCsrf = options.retryOnCsrf !== false;
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
                if (r.status === 403 && data.csrf_expired && retryOnCsrf) {
                    return refreshCsrf().then(function () {
                        return api(path, Object.assign({}, options, { retryOnCsrf: false }));
                    });
                }
                if (!r.ok) throw new Error(data.error || 'خطأ في الخادم');
                return data;
            });
        });
    }

    function refreshCsrf() {
        return fetch(cfg.apiBase + '/csrf', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.json().then(function (data) {
                if (!r.ok || !data.csrf) throw new Error(data.error || 'تعذّر تحديث الجلسة');
                cfg.csrf = data.csrf;
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
            html += '<button type="button" id="btnConfirm" class="wh-btn wh-btn-success wh-btn-block">تأكيد الاستلام</button>';
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
            localSearch(q).then(function (b) {
                if (!b) {
                    showError('لم يُعثر على مستفيد (بحث دون اتصال)');
                    return;
                }
                currentBeneficiary = b;
                renderBeneficiary(b);
            });
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
                var isNetwork = !navigator.onLine || /fetch|network|failed/i.test(e.message || '');
                if (!isNetwork) {
                    showError(e.message);
                    return;
                }
                localSearch(q).then(function (b) {
                    if (!b) {
                        showError('تعذّر الاتصال ولا توجد بيانات محلية — افتح الصفحة مرة وأنت متصل لتحميلها');
                        return;
                    }
                    currentBeneficiary = b;
                    renderBeneficiary(b);
                });
            });
    }

    function queueOffline(item, b) {
        pending.push(item);
        saveQueue();
        b.receipt_status = 'مستلم';
        updateLocalBeneficiary(b.id, { receipt_status: 'مستلم', delivered_at: item.queued_at });
        renderBeneficiary(b);
        showSuccess('تم الحفظ محلياً — ستُزامَن عند عودة الاتصال');
        prependRecent(b, 'on_time');
        if (elBalance) elBalance.textContent = Math.max(0, parseInt(elBalance.textContent, 10) - 1);
        if (elDelivered) elDelivered.textContent = parseInt(elDelivered.textContent, 10) + 1;
        elQuery && elQuery.focus();
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
            queueOffline(item, b);
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
            if (!navigator.onLine || /fetch|network|failed/i.test(e.message || '')) {
                queueOffline(item, b);
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
        syncQueue().then(downloadSnapshot);
    });
    window.addEventListener('offline', function () { setOnline(false); });

    setOnline(navigator.onLine);
    updatePendingUI();
    if (navigator.onLine) {
        syncQueue().then(downloadSnapshot);
    }

    setInterval(function () {
        if (navigator.onLine) refreshCsrf().catch(function () {});
    }, 1200000);

    setInterval(function () {
        if (navigator.onLine && pending.length > 0) syncQueue();
    }, 30000);
})();
