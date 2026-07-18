(function () {
  'use strict';

  var shell = document.querySelector('[data-app-shell]');
  if (!shell) return;

  var STORAGE_KEY = 'dl_sidebar_collapsed';

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function qsa(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  /* Sidebar collapse (desktop) */
  try {
    if (localStorage.getItem(STORAGE_KEY) === '1' && window.matchMedia('(min-width: 901px)').matches) {
      shell.classList.add('is-collapsed');
    }
  } catch (e) {}

  var collapseBtn = qs('[data-sidebar-collapse]');
  if (collapseBtn) {
    collapseBtn.addEventListener('click', function () {
      shell.classList.toggle('is-collapsed');
      try {
        localStorage.setItem(STORAGE_KEY, shell.classList.contains('is-collapsed') ? '1' : '0');
      } catch (e) {}
    });
  }

  /* Mobile drawer */
  var openBtns = qsa('[data-sidebar-open]');
  var closeTargets = qsa('[data-sidebar-close]');
  openBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      shell.classList.add('is-mobile-open');
    });
  });
  closeTargets.forEach(function (el) {
    el.addEventListener('click', function () {
      shell.classList.remove('is-mobile-open');
    });
  });

  /* User menu */
  qsa('[data-dropdown]').forEach(function (wrap) {
    var trigger = qs('[data-dropdown-trigger]', wrap);
    var menu = qs('[data-dropdown-menu]', wrap);
    if (!trigger || !menu) return;
    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = menu.classList.contains('is-open');
      qsa('[data-dropdown-menu].is-open').forEach(function (m) { m.classList.remove('is-open'); });
      if (!open) menu.classList.add('is-open');
    });
  });
  document.addEventListener('click', function () {
    qsa('[data-dropdown-menu].is-open').forEach(function (m) { m.classList.remove('is-open'); });
  });

  /* Confirm forms / buttons */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    var msg = form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
    }
  });
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (!el || el.tagName === 'FORM') return;
    if (el.tagName === 'A' || el.tagName === 'BUTTON') {
      var msg = el.getAttribute('data-confirm');
      if (msg && !window.confirm(msg)) {
        e.preventDefault();
      }
    }
  });

  /* Table filter — topbar or local toolbar */
  function filterTables(query) {
    var q = (query || '').trim().toLowerCase();
    qsa('[data-table-filterable] tbody tr').forEach(function (row) {
      if (row.querySelector('[data-empty-row]')) {
        row.style.display = '';
        return;
      }
      var text = (row.textContent || '').toLowerCase();
      row.style.display = !q || text.indexOf(q) !== -1 ? '' : 'none';
    });
  }

  var topSearch = qs('[data-global-search]');
  if (topSearch) {
    topSearch.addEventListener('input', function () {
      filterTables(topSearch.value);
      var local = qs('[data-table-search]');
      if (local && local !== topSearch) local.value = topSearch.value;
    });
  }

  qsa('[data-table-search]').forEach(function (input) {
    input.addEventListener('input', function () {
      filterTables(input.value);
      if (topSearch) topSearch.value = input.value;
    });
  });

  /* Modals */
  function openModal(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.hidden = false;
    el.classList.add('is-open');
    var focusEl = el.querySelector('input, select, textarea, button');
    if (focusEl) focusEl.focus();
  }

  function closeModal(el) {
    if (!el) return;
    el.classList.remove('is-open');
    el.hidden = true;
  }

  document.addEventListener('click', function (e) {
    var openBtn = e.target.closest('[data-modal-open]');
    if (openBtn) {
      e.preventDefault();
      openModal(openBtn.getAttribute('data-modal-open'));
      return;
    }
    var closeBtn = e.target.closest('[data-modal-close]');
    if (closeBtn) {
      closeModal(closeBtn.closest('[data-modal]'));
      return;
    }
    if (e.target.classList.contains('modal-backdrop') && e.target.hasAttribute('data-modal')) {
      closeModal(e.target);
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    qsa('[data-modal].is-open').forEach(function (m) { closeModal(m); });
  });
})();
