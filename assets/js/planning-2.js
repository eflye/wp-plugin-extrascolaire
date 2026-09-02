(function () {
    'use strict';

    var boot = null;
    var DAY_ABBR = { 1: 'day_short_1', 2: 'day_short_2', 3: 'day_short_3', 4: 'day_short_4', 5: 'day_short_5', 6: 'day_short_6', 0: 'day_short_0' };
    var SERVICES = ['GM', 'CANT', 'GS', 'FORF'];

    // Chaînes traduites côté serveur (PSC.i18n, cf. Psc_Frontend::assets()).
    function t(key) {
        var s = (window.PSC && window.PSC.i18n && window.PSC.i18n[key]) || '';
        for (var i = 1; i < arguments.length; i++) {
            s = s.replace('%s', arguments[i]);
        }
        return s;
    }

    function post(payload) {
        var params = { nonce: window.PSC.nonce, parent_nonce: window.PSC.parent_nonce || '' };
        Object.keys(payload).forEach(function (k) { params[k] = payload[k]; });
        return window.PscAjax.envelope(window.PSC.ajax_url, params);
    }

    function euro(v) {
        return Number(v || 0).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function messageFor(res, fallback) {
        if (res && res.data && res.data.message) return res.data.message;
        var code = res && res.data && res.data.code;
        return (window.PSC.i18n && window.PSC.i18n[code]) || (window.PSC.i18n && window.PSC.i18n[fallback]) || t('generic');
    }

    function activeChildMeta() {
        for (var i = 0; i < boot.children.length; i++) {
            if (boot.children[i].id === boot.active_child) return boot.children[i];
        }
        return null;
    }

    /* ---------- Rendu ---------- */

    function renderFrieze(frieze) {
        if (!frieze) return;
        var labelFor = function (m) {
            for (var i = 0; i < boot.months.length; i++) {
                if (boot.months[i].key === m) return boot.months[i].label;
            }
            return m;
        };
        document.querySelectorAll('.psc-frieze-btn').forEach(function (btn) {
            var m = btn.dataset.month;
            var count = typeof frieze[m] === 'number' ? frieze[m] : 0;
            btn.dataset.count = String(count);
            var countEl = btn.querySelector('.psc-frieze-count');
            if (countEl) countEl.textContent = count + ' j';
            btn.classList.toggle('is-zero', count === 0);
            btn.setAttribute('aria-label', labelFor(m) + ' — ' + count + ' ' + (count > 1 ? t('days') : t('day')));
        });
        var estimate = document.querySelector('[data-year-amount]');
        if (estimate && typeof state_year_amount === 'number') {
            estimate.textContent = euro(state_year_amount) + ' €';
        }
    }

    var state_year_amount = 0;

    // patterns : le rythme de l'enfant affiché, restreint à son année
    // ({weekday => {service => true}}) — le serveur retire les niveaux
    // enfant et année (cf. planning_state()).
    function renderPatternGrid(patterns) {
        var grid = document.querySelector('[data-testid="pattern-grid"]');
        if (!grid) return;
        grid.querySelectorAll('.psc-pat-btn').forEach(function (btn) {
            var wd = parseInt(btn.dataset.weekday, 10);
            var svc = btn.dataset.service;
            var on = !!(patterns && patterns[wd] && patterns[wd][svc]);
            btn.classList.toggle('is-on', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.textContent = on ? '✓' : '';
            btn.disabled = false;
        });
        var child = activeChildMeta();
        if (child) grid.dataset.child = String(child.id);
    }

    // Origine visible d'une case : l'exception prime sur le pattern.
    function stateForCell(cell) {
        if (cell.exception_value === true) return 'add';
        if (cell.exception_value === false) return 'remove';
        if (cell.origin === 'pattern') return 'pattern';
        return 'none';
    }

    function dayShortLabel(date) {
        var d = new Date(date + 'T12:00:00');
        var key = DAY_ABBR[d.getDay()];
        var dd = ('0' + d.getDate()).slice(-2);
        var mm = ('0' + (d.getMonth() + 1)).slice(-2);
        return t(key) + ' ' + dd + '/' + mm;
    }

    function buildRow(date, day) {
        var tr = document.createElement('tr');
        tr.setAttribute('data-testid', 'exc-row-' + date);
        if (day.locked) tr.className = 'psc-row-locked';

        var th = document.createElement('th');
        th.scope = 'row';
        th.className = 'psc-exc-day' + (day.locked ? ' is-locked' : '');
        th.textContent = dayShortLabel(date);
        if (day.locked) {
            var lock = document.createElement('span');
            lock.className = 'psc-lock';
            lock.setAttribute('aria-hidden', 'true');
            lock.textContent = '\uD83D\uDD12';
            th.appendChild(lock);
        }
        tr.appendChild(th);

        SERVICES.forEach(function (svc) {
            var cell = day.services[svc];
            if (!cell) return;
            var td = document.createElement('td');
            var btn = document.createElement('button');
            var state = stateForCell(cell);
            if (day.locked) state = 'locked';

            btn.type = 'button';
            btn.className = 'psc-exc-cell psc-exc-' + state;
            btn.dataset.date = date;
            btn.dataset.service = svc;
            btn.dataset.state = state;
            btn.setAttribute('aria-pressed', cell.declared ? 'true' : 'false');
            btn.title = day.locked ? t('locked_title') : t('modifiable_until');
            btn.disabled = !!day.locked || !!cell.closed;
            btn.setAttribute('data-testid', 'exc-' + date + '-' + svc);

            var glyph = document.createElement('span');
            glyph.className = 'psc-exc-glyph';
            glyph.setAttribute('aria-hidden', 'true');
            glyph.textContent = (state === 'pattern' || state === 'add') ? '✓' : (state === 'remove' ? '–' : '');
            btn.appendChild(glyph);

            var sr = document.createElement('span');
            sr.className = 'screen-reader-text';
            sr.textContent = day.locked ? t('locked_title') : (t('exc_state_' + state) + (cell.closed ? ' ' + t('service_closed') : ''));
            btn.appendChild(sr);

            if (!btn.disabled) btn.addEventListener('click', onExceptionClick);

            td.appendChild(btn);
            tr.appendChild(td);
        });

        return tr;
    }

    function renderExceptionTable(state) {
        var table = document.querySelector('[data-testid="exception-grid"]');
        var body = document.getElementById('psc-exception-body');
        if (!table || !body || !state || !state.cells) return;

        table.dataset.child = String(state.active_child ? state.active_child.id : '');

        body.innerHTML = '';
        var openDates = {};
        Object.keys(state.cells).forEach(function (date) {
            body.appendChild(buildRow(date, state.cells[date]));
            if (!state.cells[date].locked) openDates[date] = true;
        });

        // Boutons « Mois entier » : Tout / Aucun selon l'état de la colonne,
        // portée = mois affiché, jours non verrouillés (maquette v3).
        table.querySelectorAll('.psc-exc-tout').forEach(function (btn) {
            var svc = btn.dataset.service;
            var dates = [];
            Object.keys(openDates).forEach(function (date) {
                var cell = state.cells[date].services[svc];
                if (cell && !cell.closed) dates.push(date);
            });
            var allOn = dates.length > 0 && dates.every(function (d) { return state.cells[d].services[svc].declared; });
            btn.dataset.dates = dates.join(',');
            btn.dataset.state = allOn ? 'all' : 'none';
            btn.textContent = allOn ? t('exc_none') : t('exc_all');
            btn.disabled = dates.length === 0;
        });
    }

    function renderExcBar(state) {
        var child = state.active_child || activeChildMeta();
        if (!child) return;
        var row = state.per_child && state.per_child[child.id];
        var el = document.querySelector('[data-exc-bar-summary]');
        if (el && row) {
            el.innerHTML = '';
            el.appendChild(document.createTextNode(child.name + ' · ' + row.month_days + ' ' + (row.month_days > 1 ? t('days') : t('day')) + ' · '));
            var strong = document.createElement('strong');
            strong.textContent = euro(row.month_amount) + ' €';
            el.appendChild(strong);
            el.appendChild(document.createTextNode(' ' + t('exc_bar_month')));
        }
        var reset = document.getElementById('psc-exc-reset');
        if (reset) {
            var count = parseInt(state.exceptions_count, 10) || 0;
            reset.dataset.count = String(count);
            reset.hidden = count === 0;
            reset.textContent = count === 0 ? '' : t('exc_reset_link', count);
        }
    }

    function renderRecap(state) {
        if (!state) return;

        // Onglets : classe + jours du mois par enfant.
        document.querySelectorAll('.psc-child-tab').forEach(function (tab) {
            var cid = tab.dataset.childTab;
            var row = state.per_child && state.per_child[cid];
            var child = null;
            (state.children_list || boot.children).forEach(function (c) { if (String(c.id) === String(cid)) child = c; });
            var el = tab.querySelector('[data-tab-days]');
            if (el && row && child) el.textContent = child.classe + ' · ' + row.month_days + ' j. ' + t('exc_bar_month');
        });

        // Blocs récapitulatifs : mois et année par enfant.
        Object.keys(state.per_child || {}).forEach(function (cid) {
            var row = state.per_child[cid];
            var monthEl = document.querySelector('[data-recap-month="' + cid + '"]');
            var yearEl = document.querySelector('[data-recap-year="' + cid + '"]');
            if (monthEl) monthEl.textContent = row.month_days + ' j · ' + euro(row.month_amount) + ' € ' + t('exc_bar_month');
            if (yearEl) yearEl.textContent = row.year_days + ' j · ' + euro(row.year_amount) + ' € ' + t('recap_year_suffix');
        });

        var familyEl = document.querySelector('[data-recap-family-month]');
        if (familyEl) familyEl.textContent = euro(state.month_amount) + ' €';

        state_year_amount = state.year_amount;
        var estimate = document.querySelector('[data-year-amount]');
        if (estimate) estimate.textContent = euro(state.year_amount) + ' €';
    }

    function renderMonthLabel(state) {
        var el = document.querySelector('[data-exc-month]');
        if (el && state.month_label) el.textContent = state.month_label;
        var prev = document.querySelector('.psc-exc-prev');
        var next = document.querySelector('.psc-exc-next');
        if (prev) prev.disabled = monthShift(-1) === null;
        if (next) next.disabled = monthShift(1) === null;
    }

    function applyState(state) {
        if (!state) return;
        renderExceptionTable(state);
        renderPatternGrid(state.patterns || (state.all_patterns || {})[boot.active_child]);
        renderFrieze(state.frieze);
        renderExcBar(state);
        renderRecap(state);
        renderMonthLabel(state);
    }

    function setBusy(busy) {
        document.querySelectorAll('.psc-pat-btn, .psc-exc-cell, .psc-exc-tout, .psc-month-nav-btn').forEach(function (el) {
            if (busy) {
                el.dataset.pscBusyWasDisabled = el.disabled ? '1' : '0';
                el.disabled = true;
            } else if (el.dataset.pscBusyWasDisabled !== '1') {
                el.disabled = false;
                delete el.dataset.pscBusyWasDisabled;
            }
        });
    }

    /* ---------- Interactions ---------- */

    function flashNote(message) {
        var el = document.getElementById('psc-apply-siblings-feedback');
        if (!el) return;
        el.textContent = message;
        setTimeout(function () { if (el.textContent === message) el.textContent = ''; }, 6000);
    }

    function btnNotice(btn, message) {
        var cell = btn.closest('td') || btn.closest('th');
        if (!cell) return;
        var old = cell.querySelector('.psc-error');
        if (old) old.remove();
        var span = document.createElement('span');
        span.className = 'psc-error';
        span.setAttribute('role', 'alert');
        span.textContent = message;
        cell.appendChild(span);
        setTimeout(function () { span.remove(); }, 7000);
    }

    function onExceptionClick(e) {
        var btn = e.currentTarget;
        var declared = btn.getAttribute('aria-pressed') === 'true';
        var target = !declared;

        btn.disabled = true;

        post({
            action: 'psc_toggle_exception',
            child_id: boot.active_child,
            date: btn.dataset.date,
            service_code: btn.dataset.service,
            checked: target ? '1' : '0'
        }).then(function (res) {
            if (res && res.success) {
                window.PSC_DATA_STALE = true;
                applyState(res.data.state);
            } else {
                btn.disabled = false;
                btnNotice(btn, messageFor(res, 'generic'));
            }
        }).catch(function () {
            btn.disabled = false;
            btnNotice(btn, t('network'));
        });
    }

    function onPatternClick(e) {
        var btn = e.currentTarget;
        var target = btn.getAttribute('aria-pressed') !== 'true';
        btn.disabled = true;

        post({
            action: 'psc_toggle_pattern',
            child_id: boot.active_child,
            weekday: btn.dataset.weekday,
            service_code: btn.dataset.service,
            checked: target ? '1' : '0'
        }).then(function (res) {
            if (res && res.success) {
                window.PSC_DATA_STALE = true;
                // Le figeage des jours verrouillés (frozen > 0) est un
                // comportement attendu, pas une anomalie : ces jours sont
                // de toute façon grisés comme non modifiables — pas de
                // message, une notice ici n'aurait jamais été comprise.
                applyState(res.data.state);
            } else {
                btn.disabled = false;
                flashNote(messageFor(res, 'generic'));
            }
        }).catch(function () {
            btn.disabled = false;
            flashNote(t('network'));
        });
    }

    function onToutClick(e) {
        var btn = e.currentTarget;
        var dates = (btn.dataset.dates || '').split(',').filter(Boolean);
        if (!dates.length || btn.disabled) return;

        // Tout / Aucun : portée = mois affiché, jours non verrouillés.
        var willCheck = btn.dataset.state !== 'all';
        btn.disabled = true;

        post({
            action: 'psc_toggle_exception_bulk',
            child_id: boot.active_child,
            service_code: btn.dataset.service,
            checked: willCheck ? '1' : '0',
            dates: dates.join(',')
        }).then(function (res) {
            if (res && res.success) {
                window.PSC_DATA_STALE = true;
                applyState(res.data.state);
            } else {
                btn.disabled = false;
                btnNotice(btn, messageFor(res, 'generic'));
            }
        }).catch(function () {
            btn.disabled = false;
            btnNotice(btn, t('network'));
        });
    }

    function onReset(e) {
        e.preventDefault();
        var link = e.currentTarget;
        if (!parseInt(link.dataset.count, 10)) return;
        if (!window.confirm(t('exc_reset_confirm'))) return;

        setBusy(true);
        post({
            action: 'psc_reset_month_exceptions',
            child_id: boot.active_child,
            month: boot.month
        }).then(function (res) {
            setBusy(false);
            if (res && res.success) {
                window.PSC_DATA_STALE = true;
                applyState(res.data.state);
                flashNote(t('exc_reset_done', res.data.deleted));
            } else {
                flashNote(messageFor(res, 'generic'));
            }
        }).catch(function () {
            setBusy(false);
            flashNote(t('network'));
        });
    }

    function onApplySiblings() {
        var mine = (boot.patterns && boot.patterns[boot.active_child]) || {};
        var sourcePatterns = (mine && mine[boot.year_key]) || {};
        var sourceNonEmpty = Object.keys(sourcePatterns).some(function (wd) {
            return Object.keys(sourcePatterns[wd] || {}).length > 0;
        });

        // Aucun rythme à copier : la copie est sans effet, on le dit
        // plutôt que de laisser croire à une action réussie.
        if (!sourceNonEmpty) {
            flashNote(t('apply_siblings_empty'));
            return;
        }

        // Confirmation avant d'écraser des patterns non vides.
        var siblingsFilled = boot.children.some(function (c) {
            if (c.id === boot.active_child) return false;
            var p = (boot.patterns && boot.patterns[c.id]) || {};
            var yearly = (p && p[boot.year_key]) || {};
            return Object.keys(yearly).some(function (wd) { return Object.keys(yearly[wd] || {}).length > 0; });
        });
        if (siblingsFilled && !window.confirm(t('apply_siblings_confirm'))) return;

        setBusy(true);
        post({
            action: 'psc_apply_pattern_to_siblings',
            source_child_id: boot.active_child
        }).then(function (res) {
            setBusy(false);
            if (res && res.success) {
                window.PSC_DATA_STALE = true;
                applyState(res.data.state);
                // Les patterns de TOUS les enfants ont changé.
                if (res.data.state && res.data.state.all_patterns) {
                    boot.patterns = res.data.state.all_patterns;
                }
                flashNote(t('apply_siblings_done'));
            } else {
                flashNote(messageFor(res, 'generic'));
            }
        }).catch(function () {
            setBusy(false);
            flashNote(t('network'));
        });
    }

    /* ---------- Navigation (mois, onglets enfants) ---------- */

    function monthShift(delta) {
        var idx = -1;
        boot.months.forEach(function (m, i) { if (m.key === boot.month) idx = i; });
        var next = idx + delta;
        if (next < 0 || next >= boot.months.length) return null;
        return boot.months[next];
    }

    function loadMonth(childId, monthKey) {
        setBusy(true);
        post({
            action: 'psc_load_month',
            child_id: childId,
            month: monthKey
        }).then(function (res) {
            setBusy(false);
            if (res && res.success) {
                var state = res.data.state;
                boot.month = monthKey;
                boot.active_child = state.active_child ? state.active_child.id : boot.active_child;
                if (state.all_patterns) boot.patterns = state.all_patterns;
                if (state.children_list) boot.children = state.children_list;
                applyState(state);
                // L'année peut avoir changé de clé si un état inattendu est
                // revenu : on réaligne silencieusement.
                if (state.year_key) boot.year_key = state.year_key;
            } else {
                flashNote(messageFor(res, 'generic'));
            }
        }).catch(function () {
            setBusy(false);
            flashNote(t('network'));
        });
    }

    function initTabs() {
        document.querySelectorAll('.psc-child-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var cid = parseInt(tab.dataset.childTab, 10);
                if (cid === boot.active_child) return;
                document.querySelectorAll('.psc-child-tab').forEach(function (tb) {
                    var active = tb === tab;
                    tb.classList.toggle('is-active', active);
                    tb.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                loadMonth(cid, boot.month);
            });
        });
    }

    function initNav() {
        var prev = document.querySelector('.psc-exc-prev');
        var next = document.querySelector('.psc-exc-next');
        if (prev) prev.addEventListener('click', function () {
            var m = monthShift(-1);
            if (m) loadMonth(boot.active_child, m.key);
        });
        if (next) next.addEventListener('click', function () {
            var m = monthShift(1);
            if (m) loadMonth(boot.active_child, m.key);
        });
    }

    function onConfirm(btn, feedback) {
        btn.disabled = true;
        var original = btn.textContent;
        btn.textContent = t('sending');
        feedback.textContent = '';
        feedback.className = 'psc-confirm-feedback';

        post({ action: 'psc_confirm' }).then(function (res) {
            btn.disabled = false;
            btn.textContent = original;
            if (res && res.success) {
                feedback.className = 'psc-confirm-feedback psc-ok-text';
                feedback.textContent = (res.data && res.data.message) || t('summary_sent');
            } else {
                feedback.className = 'psc-confirm-feedback psc-err-text';
                feedback.textContent = messageFor(res, 'generic');
            }
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = original;
            feedback.className = 'psc-confirm-feedback psc-err-text';
            feedback.textContent = t('network');
        });
    }

    function bootFromDom() {
        var el = document.getElementById('psc-planning2-data');
        if (!el) return false;
        try {
            boot = JSON.parse(el.textContent);
        } catch (err) {
            return false;
        }
        return !!boot;
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!bootFromDom()) return;
        state_year_amount = boot.year_amount;

        document.querySelectorAll('.psc-pat-btn').forEach(function (btn) {
            btn.addEventListener('click', onPatternClick);
        });
        document.querySelectorAll('.psc-exc-cell').forEach(function (btn) {
            btn.addEventListener('click', onExceptionClick);
        });
        document.querySelectorAll('.psc-exc-tout').forEach(function (btn) {
            btn.addEventListener('click', onToutClick);
        });

        var reset = document.getElementById('psc-exc-reset');
        if (reset) reset.addEventListener('click', onReset);

        var apply = document.getElementById('psc-apply-siblings');
        if (apply) apply.addEventListener('click', onApplySiblings);

        initTabs();
        initNav();

        var btn = document.getElementById('psc-confirm-2');
        var feedback = document.getElementById('psc-confirm-feedback-2');
        if (btn && feedback) {
            btn.addEventListener('click', function () { onConfirm(btn, feedback); });
        }
    });
})();
