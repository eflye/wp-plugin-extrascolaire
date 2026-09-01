(function () {
    'use strict';

    // Fournie par le serveur (psc_unit_services()) : une seule source pour
    // la composition du forfait, PHP et JavaScript confondus.
    var SERVICES = PSC_CAL_V2.unit_services;
    var menuEl = null;

    // Chaînes traduites côté serveur (PSC_CAL_V2.i18n, cf.
    // Psc_Admin_Calendar_V2::assets()) : t('cle', args…) remplace les %s
    // dans l'ordre des arguments.
    function t(key) {
        var s = PSC_CAL_V2.i18n[key] || '';
        for (var i = 1; i < arguments.length; i++) {
            s = s.replace('%s', arguments[i]);
        }
        return s;
    }

    function svcLabel(code) {
        return (PSC_CAL_V2.services[code] && PSC_CAL_V2.services[code].label) || code;
    }

    function formatDDMMYYYY(dateStr) {
        var parts = (dateStr || '').split('-');
        return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : (dateStr || '');
    }

    /* ---------------- AJAX ---------------- */

    function ajax(action, params) {
        var all = { action: action, nonce: PSC_CAL_V2.nonce };
        Object.keys(params || {}).forEach(function (k) { all[k] = params[k]; });
        return window.PscAjax.data(PSC_CAL_V2.ajax_url, all);
    }

    function reload() { window.location.reload(); }

    function showAjaxError() { window.alert(t('ajax_error')); }

    /* ---------------- Menu contextuel (clic sur un jour) ---------------- */

    function closeMenu() {
        if (menuEl) { menuEl.remove(); menuEl = null; }
    }

    function positionNear(el, anchor) {
        var r = anchor.getBoundingClientRect();
        el.style.position = 'fixed';
        el.style.top = Math.max(8, Math.min(r.bottom, window.innerHeight - 220)) + 'px';
        el.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 260)) + 'px';
    }

    function addMenuItem(items, label, run) {
        items.push({ label: label, run: run });
    }

    function openMenu(cell) {
        closeMenu();
        var date = cell.getAttribute('data-date');
        var status = cell.getAttribute('data-status');
        var items = [];

        if (status === 'closed_day') {
            addMenuItem(items, t('reopen_day'), function () {
                confirmSimple(t('reopen_date', formatDDMMYYYY(date)), function () {
                    return ajax('psc_cal_v2_open_day', { date: date });
                });
            });
        } else if (status === 'open') {
            addMenuItem(items, t('close_all_day'), function () { openCloseDayModal(date); });
            SERVICES.forEach(function (code) {
                var closed = cell.getAttribute('data-closed-' + code.toLowerCase()) === '1';
                if (closed) {
                    addMenuItem(items, t('reopen_service', svcLabel(code)), function () {
                        confirmSimple(t('reopen_service_date', svcLabel(code), formatDDMMYYYY(date)), function () {
                            return ajax('psc_cal_v2_open_service', { date: date, service: code });
                        });
                    });
                } else {
                    addMenuItem(items, t('close_service', svcLabel(code)), function () { openCloseServiceModal(date, code); });
                }
            });
        }

        if (!items.length) return;

        menuEl = document.createElement('div');
        menuEl.className = 'psc-cal2-menu';
        items.forEach(function (it) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'psc-cal2-menu-item';
            btn.textContent = it.label;
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                closeMenu();
                it.run();
            });
            menuEl.appendChild(btn);
        });
        document.body.appendChild(menuEl);
        positionNear(menuEl, cell);
    }

    function confirmSimple(message, run) {
        if (!window.confirm(message)) return;
        run().then(reload).catch(showAjaxError);
    }

    /* ---------------- Modal de confirmation (fermetures avec preview) ---------------- */

    function showConfirmModal(opts) {
        var modal = document.getElementById('psc-cal2-modal');
        var backdrop = document.getElementById('psc-cal2-modal-backdrop');
        document.getElementById('psc-cal2-modal-title').textContent = opts.title;
        document.getElementById('psc-cal2-modal-body').textContent = opts.body;
        var labelInput = document.getElementById('psc-cal2-modal-label');
        labelInput.value = '';
        modal.hidden = false;
        backdrop.hidden = false;

        var confirmBtn = document.getElementById('psc-cal2-modal-confirm');
        var cancelBtn = document.getElementById('psc-cal2-modal-cancel');
        confirmBtn.disabled = false;

        function cleanup() {
            modal.hidden = true;
            backdrop.hidden = true;
            confirmBtn.removeEventListener('click', onConfirm);
            cancelBtn.removeEventListener('click', onCancel);
        }
        function onCancel() { cleanup(); }
        function onConfirm() {
            confirmBtn.disabled = true;
            opts.onConfirm(labelInput.value)
                .then(function () { cleanup(); reload(); })
                .catch(function (e) { confirmBtn.disabled = false; showAjaxError(e); });
        }
        confirmBtn.addEventListener('click', onConfirm);
        cancelBtn.addEventListener('click', onCancel);
    }

    function openCloseDayModal(date) {
        ajax('psc_cal_v2_preview_close_day', { date: date }).then(function (data) {
            var body = data.registrations > 0
                ? t('preview_day', data.registrations, data.families)
                : t('no_registrations');
            showConfirmModal({
                title: t('close_date', formatDDMMYYYY(date)),
                body: body,
                onConfirm: function (label) {
                    return ajax('psc_cal_v2_close_day', { date: date, label: label });
                },
            });
        }).catch(showAjaxError);
    }

    function openCloseServiceModal(date, service) {
        ajax('psc_cal_v2_preview_close_service', { date: date, service: service }).then(function (data) {
            var parts = [];
            if (data.direct_registrations > 0) {
                parts.push(t('preview_service_direct', data.direct_registrations, svcLabel(service), data.direct_families));
            }
            if (data.forf_registrations > 0) {
                parts.push(t('preview_service_forf', data.forf_registrations, data.forf_families));
            }
            var body = parts.length ? parts.join(' ') : t('no_registrations');
            showConfirmModal({
                title: t('close_service_date', svcLabel(service), formatDDMMYYYY(date)),
                body: body,
                onConfirm: function (label) {
                    return ajax('psc_cal_v2_close_service', { date: date, service: service, label: label });
                },
            });
        }).catch(showAjaxError);
    }

    /* ---------------- Init ---------------- */

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.psc-cal2-day[data-date]').forEach(function (cell) {
            cell.addEventListener('click', function (e) {
                e.stopPropagation();
                openMenu(cell);
            });
        });
        document.addEventListener('click', closeMenu);
        document.getElementById('psc-cal2-modal-backdrop').addEventListener('click', function () {
            document.getElementById('psc-cal2-modal').hidden = true;
            document.getElementById('psc-cal2-modal-backdrop').hidden = true;
        });
    });
})();
