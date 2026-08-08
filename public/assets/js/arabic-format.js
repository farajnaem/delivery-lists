/**
 * تنسيق عربي مشترك — أرقام إنجليزية ووقت 12 ساعة (ص/م).
 * يُستخدم من واجهة المخزن وأي سكربت عرض يحتاج نفس القواعد.
 */
(function (global) {
    'use strict';

    var WESTERN = '0123456789';
    var ARABIC = '٠١٢٣٤٥٦٧٨٩';

    function toWesternDigits(s) {
        return String(s == null ? '' : s).replace(/[٠-٩]/g, function (ch) {
            var i = ARABIC.indexOf(ch);
            return i >= 0 ? WESTERN[i] : ch;
        });
    }

    function formatTime12(time24) {
        var t = toWesternDigits(String(time24 || '').trim());
        var m = t.match(/^(\d{1,2}):(\d{2})/);
        if (!m) return t;
        var hour = parseInt(m[1], 10);
        var minute = m[2];
        var period = hour < 12 ? 'ص' : 'م';
        var hour12 = hour % 12;
        if (hour12 === 0) hour12 = 12;
        return hour12 + ':' + minute + ' ' + period;
    }

    function formatDateTimeAr(dt) {
        var t = toWesternDigits(String(dt || '').trim());
        if (!t) return '';
        var parts = t.split(' ');
        if (parts.length >= 2) {
            return parts[0] + ' ' + formatTime12(parts[1]);
        }
        return t;
    }

    function localizeBeneficiary(b) {
        if (!b) return b;
        var copy = Object.assign({}, b);
        ['display_code', 'national_id', 'mobile', 'sort_order', 'window_num', 'delivery_date', 'delivered_at'].forEach(function (k) {
            if (copy[k] != null && copy[k] !== '') {
                if (k === 'delivered_at') copy[k] = formatDateTimeAr(copy[k]);
                else if (k === 'time_from' || k === 'time_to') copy[k] = formatTime12(copy[k]);
                else copy[k] = toWesternDigits(copy[k]);
            }
        });
        if (copy.time_from) copy.time_from = formatTime12(copy.time_from);
        if (copy.time_to) copy.time_to = formatTime12(copy.time_to);
        return copy;
    }

    global.RecArabicFormat = {
        toWesternDigits: toWesternDigits,
        formatTime12: formatTime12,
        formatDateTimeAr: formatDateTimeAr,
        localizeBeneficiary: localizeBeneficiary,
    };
})(typeof window !== 'undefined' ? window : this);
