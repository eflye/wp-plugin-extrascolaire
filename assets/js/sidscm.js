(function () {
    'use strict';

    var STORAGE_KEY = 'psc_sidscm_code';
    var SERVICE_ORDER = ['GM', 'CANT', 'GS'];

    var state = {
        code: '',
        viewMode: 'day',
        activeDay: null,
        activeService: 'GM',
        days: {},      // jour => date (Y-m-d), ordre lundi/mardi/jeudi/vendredi
        services: {},  // code => { label, price }
        children: [],  // { id, prenom, nom, classe, diet, GM: [jours], CANT: [jours], GS: [jours] }
        attendance: {}, // "childId|date|service" => 0|1
        departures: {}, // "childId|date|GS" => "HH:MM"
        authExpanded: {}, // childId => bool, replié par défaut, survit aux re-rendus (checkbox, départ...)
    };

    var els = {};

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function capitalize(s) {
        return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
    }

    function shortDay(jour) {
        return capitalize(jour).slice(0, 3);
    }

    /** "2026-08-17" -> "17/08" — la date vient toujours de state.days
     *  (résolue côté serveur depuis le vrai calendrier), jamais calculée
     *  ici à partir d'une date codée en dur. */
    function formatDDMM(dateStr) {
        var parts = (dateStr || '').split('-');
        return parts.length === 3 ? parts[2] + '/' + parts[1] : '';
    }

    function svcLabel(code) {
        var s = state.services[code];
        return s && s.label ? s.label : code;
    }

    /* ---------------- AJAX ---------------- */

    function ajax(action, params) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', PSC_SIDSCM.nonce);
        // Le code d'accès est revalidé côté serveur à chaque appel (voir
        // Psc_Sidscm::code_valid) : toujours envoyé depuis l'état courant,
        // sauf si l'appelant fournit le sien explicitement (unlock, avant
        // que state.code ne soit renseigné).
        body.set('code', state.code);
        Object.keys(params || {}).forEach(function (k) {
            body.set(k, params[k]);
        });
        return fetch(PSC_SIDSCM.ajax_url, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json || !json.success) {
                    var err = new Error('psc_sidscm_ajax_failed');
                    err.data = json;
                    throw err;
                }
                return json.data;
            });
    }

    /* ---------------- Déverrouillage ---------------- */

    function showApp() {
        els.lock.hidden = true;
        els.app.hidden = false;
    }

    function showLock() {
        els.app.hidden = true;
        els.lock.hidden = false;
    }

    function unlock(code, silent) {
        return ajax('psc_sidscm_unlock', { code: code }).then(function () {
            state.code = code;
            localStorage.setItem(STORAGE_KEY, code);
            els.codeError.hidden = true;
            return fetchData().then(showApp);
        }).catch(function () {
            localStorage.removeItem(STORAGE_KEY);
            if (!silent) {
                els.codeError.hidden = false;
            }
        });
    }

    function lock() {
        localStorage.removeItem(STORAGE_KEY);
        state.code = '';
        els.codeInput.value = '';
        showLock();
    }

    /* ---------------- Données ---------------- */

    function fetchData() {
        return ajax('psc_sidscm_data').then(function (data) {
            state.days = data.days || {};
            state.services = data.services || {};
            state.children = data.children || [];
            state.attendance = data.attendance || {};
            state.departures = data.departures || {};

            var dayKeys = Object.keys(state.days);
            if (dayKeys.indexOf(state.activeDay) === -1) {
                state.activeDay = dayKeys[0] || null;
            }
            renderAll();
        });
    }

    function toggleAttendance(childId, date, service, present) {
        // Optimiste : l'affichage a déjà changé (case cochée/décochée), on
        // persiste ensuite sans bloquer l'interface — un échec réseau ne
        // doit jamais empêcher l'intervenant de continuer son pointage.
        ajax('psc_sidscm_toggle', {
            child_id: childId,
            jour_date: date,
            service: service,
            present: present ? '1' : '0',
        }).catch(function () { /* pointage local conservé malgré l'échec réseau */ });
    }

    function setDeparture(childId, date, time) {
        ajax('psc_sidscm_departure', {
            child_id: childId,
            jour_date: date,
            departure_time: time,
        }).catch(function () { /* valeur locale conservée malgré l'échec réseau */ });
    }

    /* ---------------- Rendu ---------------- */

    function countExpected(svc) {
        var day = state.activeDay;
        return state.children.filter(function (c) {
            return (c[svc] || []).indexOf(day) !== -1;
        }).length;
    }

    function sortChildren(list) {
        return list.slice().sort(function (a, b) {
            return (a.classe || '').localeCompare(b.classe || '') || a.nom.localeCompare(b.nom);
        });
    }

    function renderDays() {
        var dayKeys = Object.keys(state.days);
        els.days.innerHTML = dayKeys.map(function (d) {
            var active = d === state.activeDay;
            var label = capitalize(d) + ' ' + formatDDMM(state.days[d]);
            return '<button type="button" class="psc-sidscm-day-btn' + (active ? ' is-active' : '') +
                '" data-day="' + d + '" data-testid="sidscm-day-' + d + '">' + escapeHtml(label) + '</button>';
        }).join('');
        els.days.querySelectorAll('button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                state.activeDay = btn.dataset.day;
                renderAll();
            });
        });
    }

    function renderServices() {
        els.services.innerHTML = SERVICE_ORDER.map(function (code) {
            var active = code === state.activeService;
            return '<button type="button" class="psc-sidscm-svc-btn' + (active ? ' is-active' : '') +
                '" data-svc="' + code + '" data-testid="sidscm-svc-' + code + '">' +
                escapeHtml(svcLabel(code)) +
                ' <span class="psc-sidscm-svc-count">' + countExpected(code) + '</span></button>';
        }).join('');
        els.services.querySelectorAll('button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                state.activeService = btn.dataset.svc;
                renderAll();
            });
        });
    }

    function renderModeButtons() {
        els.modeDay.classList.toggle('is-active', state.viewMode === 'day');
        els.modeWeek.classList.toggle('is-active', state.viewMode === 'week');
    }

    /**
     * Lecture seule : aucune action de gestion sur cet écran, uniquement
     * la consultation de la liste à jour (parents + éventuel second parent
     * + tiers ajoutés côté famille) — la gestion reste dans "Mon profil".
     */
    function renderAuthPanel(c) {
        var persons = c.authorized || [];
        var personsHtml = persons.length
            ? persons.map(function (p) {
                return '<div class="psc-sidscm-auth-person">' +
                    '<span class="psc-sidscm-auth-badge">' + escapeHtml(p.role) + '</span>' +
                    '<span class="psc-sidscm-auth-name">' + escapeHtml(p.prenom) + ' ' + escapeHtml(p.nom) + '</span>' +
                    '<span class="psc-sidscm-auth-tel">' + escapeHtml(p.telephone || '—') + '</span>' +
                    '</div>';
            }).join('')
            : '<div class="psc-sidscm-auth-empty">Aucune personne autorisée renseignée.</div>';
        return '<div class="psc-sidscm-auth-panel" data-testid="sidscm-auth-panel-' + c.id + '">' + personsHtml + '</div>';
    }

    function renderDayView() {
        var svc = state.activeService;
        var day = state.activeDay;
        var date = state.days[day];

        var rows = sortChildren(state.children.filter(function (c) {
            return (c[svc] || []).indexOf(day) !== -1;
        }));

        var presentCount = 0;
        var rowsHtml = rows.map(function (c) {
            var key = c.id + '|' + date + '|' + svc;
            var present = Object.prototype.hasOwnProperty.call(state.attendance, key) ? !!state.attendance[key] : true;
            if (present) presentCount++;
            var dietHtml = (svc === 'CANT' && c.diet)
                ? '<span class="psc-sidscm-row-diet">' + escapeHtml(c.diet) + '</span>'
                : '';
            var departureHtml = '';
            if (svc === 'GS') {
                var departureVal = state.departures[key] || '';
                departureHtml = '<label class="psc-sidscm-row-departure">Départ' +
                    '<input type="time" class="psc-sidscm-row-departure-input" data-child-id="' + c.id + '"' +
                    ' value="' + escapeHtml(departureVal) + '" data-testid="sidscm-departure-' + c.id + '"></label>';
            }
            var expanded = !!state.authExpanded[c.id];
            var authToggleHtml = '<button type="button" class="psc-sidscm-auth-toggle' + (expanded ? ' is-expanded' : '') +
                '" data-child-id="' + c.id + '" data-testid="sidscm-auth-toggle-' + c.id + '" aria-expanded="' + (expanded ? 'true' : 'false') + '">' +
                '<span class="psc-sidscm-auth-toggle-icon">' + (expanded ? '−' : '+') + '</span> Autorisés</button>';
            return '<div class="psc-sidscm-row" data-testid="sidscm-row-' + c.id + '">' +
                '<label class="psc-sidscm-row-label">' +
                '<input type="checkbox" class="psc-sidscm-row-check" data-child-id="' + c.id + '"' +
                (present ? ' checked' : '') + ' data-testid="sidscm-check-' + c.id + '">' +
                '<span class="psc-sidscm-row-name">' + escapeHtml(c.prenom) + ' ' + escapeHtml(c.nom) + '</span>' +
                '<span class="psc-sidscm-row-classe">' + escapeHtml(c.classe || '') + '</span>' +
                '</label>' + dietHtml + departureHtml + authToggleHtml +
                '</div>' + (expanded ? renderAuthPanel(c) : '');
        }).join('');

        var emptyHtml = rows.length === 0
            ? '<div class="psc-sidscm-empty" data-testid="sidscm-empty">Aucun enfant attendu ce jour pour ce service.</div>'
            : '';

        els.content.innerHTML =
            '<div class="psc-sidscm-panel" data-testid="sidscm-day-panel">' +
            '<div class="psc-sidscm-panel-head">' +
            '<div class="psc-sidscm-panel-title">' + escapeHtml(svcLabel(svc)) + ' — ' + escapeHtml(capitalize(day)) + '</div>' +
            '<div class="psc-sidscm-panel-count" data-testid="sidscm-present-count">' + presentCount + ' / ' + rows.length + ' présents</div>' +
            '</div>' + rowsHtml + emptyHtml +
            '</div>';

        els.content.querySelectorAll('.psc-sidscm-row-check').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var childId = cb.dataset.childId;
                var key = childId + '|' + date + '|' + svc;
                state.attendance[key] = cb.checked ? 1 : 0;
                toggleAttendance(childId, date, svc, cb.checked);
                renderDayView(); // recalcule le compteur "X / Y présents"
            });
        });

        // Départ (Garderie soir uniquement) : pas de recalcul de compteur,
        // pas besoin de re-rendre toute la vue à chaque frappe/changement.
        els.content.querySelectorAll('.psc-sidscm-row-departure-input').forEach(function (input) {
            input.addEventListener('change', function () {
                var childId = input.dataset.childId;
                var key = childId + '|' + date + '|' + svc;
                state.departures[key] = input.value;
                setDeparture(childId, date, input.value);
            });
        });

        // Plusieurs lignes peuvent être dépliées en même temps (comparer
        // avant un départ groupé) : chaque bouton ne replie que sa ligne.
        els.content.querySelectorAll('.psc-sidscm-auth-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var childId = btn.dataset.childId;
                state.authExpanded[childId] = !state.authExpanded[childId];
                renderDayView();
            });
        });
    }

    function renderWeekView() {
        var svc = state.activeService;
        var dayKeys = Object.keys(state.days);

        var rows = sortChildren(state.children.filter(function (c) {
            return dayKeys.some(function (d) { return (c[svc] || []).indexOf(d) !== -1; });
        }));

        var headHtml = dayKeys.map(function (d) {
            return '<th>' + escapeHtml(shortDay(d)) + '</th>';
        }).join('');

        var rowsHtml = rows.map(function (c) {
            var marksHtml = dayKeys.map(function (d) {
                var expected = (c[svc] || []).indexOf(d) !== -1;
                return '<td><span class="psc-sidscm-mark' + (expected ? '' : ' is-absent') + '">' +
                    (expected ? '●' : '—') + '</span></td>';
            }).join('');
            return '<tr><td class="psc-sidscm-table-child-cell">' + escapeHtml(c.prenom) + ' ' + escapeHtml(c.nom) +
                ' <span class="psc-sidscm-table-child-classe">(' + escapeHtml(c.classe || '') + ')</span>' +
                '<button type="button" class="psc-sidscm-auth-toggle psc-sidscm-auth-toggle--week" data-child-id="' + c.id + '"' +
                ' data-testid="sidscm-auth-week-' + c.id + '"><span class="psc-sidscm-auth-toggle-icon">+</span> Autorisés</button></td>' +
                marksHtml + '</tr>';
        }).join('');

        els.content.innerHTML =
            '<div class="psc-sidscm-panel" data-testid="sidscm-week-panel">' +
            '<div class="psc-sidscm-panel-head"><div class="psc-sidscm-panel-title">' + escapeHtml(svcLabel(svc)) + ' — semaine</div></div>' +
            '<div class="psc-sidscm-table-scroll"><table class="psc-sidscm-table">' +
            '<thead><tr><th class="psc-sidscm-table-child-head">Enfant</th>' + headHtml + '</tr></thead>' +
            '<tbody>' + rowsHtml + '</tbody></table></div>' +
            '</div>';

        // Pas de panneau dépliable dans le tableau semaine (une ligne par
        // enfant, pas de place) : on renvoie vers la vue Jour du premier
        // jour où l'enfant est attendu, avec son panneau déjà déplié.
        els.content.querySelectorAll('.psc-sidscm-auth-toggle--week').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var childId = btn.dataset.childId;
                var child = state.children.filter(function (c) { return String(c.id) === String(childId); })[0];
                var targetDay = child ? dayKeys.filter(function (d) { return (child[svc] || []).indexOf(d) !== -1; })[0] : null;
                state.authExpanded[childId] = true;
                state.viewMode = 'day';
                if (targetDay) state.activeDay = targetDay;
                renderAll();
            });
        });
    }

    function renderAll() {
        renderDays();
        renderServices();
        renderModeButtons();
        if (state.viewMode === 'day') {
            renderDayView();
        } else {
            renderWeekView();
        }
    }

    /* ---------------- Initialisation ---------------- */

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('psc-sidscm-root');
        if (!root || typeof PSC_SIDSCM === 'undefined') return;

        els.lock = document.getElementById('psc-sidscm-lock');
        els.app = document.getElementById('psc-sidscm-app');
        els.codeForm = document.getElementById('psc-sidscm-code-form');
        els.codeInput = document.getElementById('psc-sidscm-code-input');
        els.codeError = document.getElementById('psc-sidscm-code-error');
        els.modeDay = document.getElementById('psc-sidscm-mode-day');
        els.modeWeek = document.getElementById('psc-sidscm-mode-week');
        els.lockBtn = document.getElementById('psc-sidscm-lock-btn');
        els.days = document.getElementById('psc-sidscm-days');
        els.services = document.getElementById('psc-sidscm-services');
        els.content = document.getElementById('psc-sidscm-content');

        els.codeForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var code = els.codeInput.value.trim();
            if (!code) return;
            unlock(code, false);
        });

        els.modeDay.addEventListener('click', function () {
            state.viewMode = 'day';
            renderAll();
        });
        els.modeWeek.addEventListener('click', function () {
            state.viewMode = 'week';
            renderAll();
        });
        els.lockBtn.addEventListener('click', lock);

        var stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
            unlock(stored, true);
        }
    });
})();
