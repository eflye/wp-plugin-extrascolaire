(function () {
    'use strict';

    // Chaînes traduites côté serveur (PSC_SIDSCM.i18n, cf. Psc_Sidscm::assets())
    // : t('cle', args…) remplace les %s dans l'ordre des arguments.
    // Marqueurs numérotés (%1$s, %2$s : l'ordre peut changer d'une langue
    // à l'autre) ou simples (%s, remplacés dans l'ordre).
    function t(key) {
        var s = PSC_SIDSCM.i18n[key] || '';
        var args = Array.prototype.slice.call(arguments, 1);
        s = s.replace(/%(\d+)\$s/g, function (m, n) {
            var v = args[parseInt(n, 10) - 1];
            return v === undefined ? m : String(v);
        });
        args.forEach(function (v) { s = s.replace('%s', String(v)); });
        return s;
    }
    // Fournie par le serveur (psc_unit_services()) : une seule source pour
    // la composition du forfait, PHP et JavaScript confondus.
    var SERVICE_ORDER = PSC_SIDSCM.services;

    var state = {
        code: '',
        sessionToken: '',
        intervenantId: '',
        viewMode: 'day',
        week: null,
        currentWeek: null,
        weeks: {},
        activeDay: null,
        activeService: SERVICE_ORDER[0], // première prestation de la journée
        days: {},      // jour => date (Y-m-d), ordre lundi/mardi/jeudi/vendredi
        services: {},  // code => { label, price }
        children: [],  // { id, prenom, nom, classe, diet, GM: [jours], CANT: [jours], GS: [jours] }
        attendance: {}, // "childId|date|service" => 1 (présent) | 0 (absent) ; clé absente = à pointer
        arrivals: {},   // "childId|date|GM" => "HH:MM"
        departures: {}, // "childId|date|GS" => "HH:MM"
        authExpanded: {}, // childId => bool, replié par défaut, survit aux re-rendus (checkbox, départ...)
        // Contact d'urgence : numéros des parents chargés à la demande
        // (journalisés côté serveur), gardés en mémoire jusqu'au verrouillage.
        contactsExpanded: {}, // childId => bool
        contacts: {},         // childId => { status: 'loading'|'ok'|'error', list: [...] }
        notice: '',           // message d'échec d'enregistrement affiché en vue Jour
        noticeFresh: false,
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
        var all = {
            action: action,
            nonce: PSC_SIDSCM.nonce,
            // Le code d'accès est revalidé côté serveur à chaque appel (voir
            // Psc_Sidscm::code_valid) : toujours envoyé depuis l'état courant,
            // sauf si l'appelant fournit le sien explicitement (unlock, avant
            // que state.code ne soit renseigné).
            code: state.code,
            session_token: state.sessionToken,
            intervenant_id: state.intervenantId
        };
        Object.keys(params || {}).forEach(function (k) { all[k] = params[k]; });
        return window.PscAjax.data(PSC_SIDSCM.ajax_url, all).catch(function (err) {
            // Session refusée par le serveur (intervenant révoqué, désactivé
            // ou expiré, session échue) : retour immédiat à l'écran de code,
            // la liste affichée n'est plus à jour ni autorisée.
            var code = err && err.data && err.data.data ? err.data.data.code : '';
            if (code === 'auth' && action !== 'psc_sidscm_unlock' && state.sessionToken) {
                lock();
            }
            throw err;
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
        return ajax('psc_sidscm_unlock', { code: code, intervenant_id: els.intervenantId ? els.intervenantId.value : '' }).then(function (data) {
            state.code = '';
            state.sessionToken = data.session_token || '';
            state.intervenantId = data.intervenant ? data.intervenant.id : '';
            els.codeError.hidden = true;
            return fetchData().then(showApp);
        }).catch(function () {
            if (!silent) {
                els.codeError.hidden = false;
            }
        });
    }

    function lock() {
        state.code = '';
        state.sessionToken = '';
        state.intervenantId = '';
        // Numéros des parents : jamais conservés au-delà de la session.
        state.contacts = {};
        state.contactsExpanded = {};
        state.notice = '';
        state.noticeFresh = false;
        els.codeInput.value = '';
        showLock();
    }

    /* ---------------- Données ---------------- */

    function fetchData(week) {
        els.modeDay.disabled = true;
        els.modeWeek.disabled = true;
        return ajax('psc_sidscm_data', { week: week || '' }).then(function (data) {
            state.week = data.week;
            state.currentWeek = data.current_week;
            state.weeks = data.weeks || {};
            state.days = data.days || {};
            state.services = data.services || {};
            state.children = data.children || [];
            state.attendance = data.attendance || {};
            state.arrivals = data.arrivals || {};
            state.departures = data.departures || {};

            var dayKeys = Object.keys(state.days);
            if (dayKeys.indexOf(state.activeDay) === -1) {
                state.activeDay = dayKeys[0] || null;
            }
            renderAll();
        }).finally(function () {
            els.modeDay.disabled = false;
            els.modeWeek.disabled = false;
        });
    }

    /* ---------------- Pointage (présent / absent / à pointer) ---------------- */

    // Seuls la Garderie matin (arrivée) et la Garderie soir (départ) sont
    // horodatées ; la cantine ne porte jamais d'heure.
    var TIME_FIELDS = {
        GM: { map: 'arrivals', label: 'arrival', testid: 'sidscm-arrival-', action: 'psc_sidscm_arrival', param: 'arrival_time' },
        GS: { map: 'departures', label: 'departure', testid: 'sidscm-departure-', action: 'psc_sidscm_departure', param: 'departure_time' },
    };

    // Dernier appel lancé par ligne (enfant × jour × service) : seule la
    // réponse du plus récent peut rétablir l'état — deux appuis rapprochés
    // ne se marchent pas dessus.
    var pending = {};
    // Pointage en cours par ligne : une heure saisie juste après l'appui
    // sur Présent attend qu'il soit enregistré (le serveur refuse une
    // heure pour un enfant pas encore présent).
    var inflight = {};

    function nowHM() {
        var d = new Date();
        return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    }

    function childName(childId) {
        var c = state.children.filter(function (x) { return String(x.id) === String(childId); })[0];
        return c ? (c.prenom + ' ' + c.nom).trim() : '';
    }

    /** "present" | "absent" | "todo" — clé absente de attendance = à pointer. */
    function attendanceState(key) {
        if (!Object.prototype.hasOwnProperty.call(state.attendance, key)) return 'todo';
        return state.attendance[key] ? 'present' : 'absent';
    }

    // Notice d'échec : conservée jusqu'au prochain enregistrement réussi.
    // `fresh` distingue un nouvel échec (annoncé par role="alert") d'un
    // simple re-rendu de la vue (texte déjà affiché, pas de nouvelle annonce).
    function raiseNotice(message) {
        state.notice = message;
        state.noticeFresh = true;
    }

    function clearNotice() {
        if (!state.notice) return;
        state.notice = '';
        state.noticeFresh = false;
        var el = els.content.querySelector('.psc-sidscm-at-notice');
        if (el) { el.textContent = ''; el.hidden = true; }
    }

    /** Cible de focus : "#id" ou valeur de data-testid. Rend true si le focus y est. */
    function focusTarget(target) {
        var el = els.app.querySelector(target.charAt(0) === '#' ? target : '[data-testid="' + target + '"]');
        if (!el) return false;
        el.focus();
        return document.activeElement === el;
    }

    /**
     * Re-rend la vue Jour (et les onglets, pour le ✓) sans perdre le
     * focus clavier : il revient sur le contrôle qui l'avait (recréé par
     * innerHTML), sinon sur `fallback` (le bouton actionné), sinon sur
     * `last` (titre de la liste).
     */
    function rerenderDay(fallback, last) {
        if (state.viewMode !== 'day') return;
        var active = document.activeElement;
        var keep = active && els.app.contains(active)
            ? (active.getAttribute('data-testid') || (active.id ? '#' + active.id : ''))
            : '';
        renderServices();
        renderDayView();
        [keep, fallback, last].some(function (target) { return target && focusTarget(target); });
    }

    /**
     * Toucher Présent ou Absent ; toucher le bouton déjà actif annule le
     * pointage. Optimiste : l'affichage change aussitôt, puis l'état et
     * l'heure précédents reviennent si l'enregistrement échoue.
     */
    function setAttendance(childId, date, svc, target, testid) {
        var key = childId + '|' + date + '|' + svc;
        var field = TIME_FIELDS[svc];
        var times = field ? state[field.map] : null;
        var hadKey = Object.prototype.hasOwnProperty.call(state.attendance, key);
        var prevValue = state.attendance[key];
        var prevTime = times ? (times[key] || '') : '';
        var current = attendanceState(key);
        var next = current === target ? 'none' : target;

        if (next === 'present') {
            state.attendance[key] = 1;
            if (times && !times[key]) times[key] = nowHM();
        } else if (next === 'absent') {
            state.attendance[key] = 0;
            if (times) times[key] = '';
        } else {
            delete state.attendance[key];
            if (times) times[key] = '';
        }
        rerenderDay(testid);

        var params = { child_id: childId, jour_date: date, service: svc, state: next };
        if (next === 'present' && times && times[key]) params.time = times[key];
        var seq = (pending[key] || 0) + 1;
        pending[key] = seq;

        var request = ajax('psc_sidscm_toggle', params);
        inflight[key] = request.catch(function () {});
        request.then(function (data) {
            if (pending[key] !== seq) return;
            clearNotice();
            // Heure conservée côté serveur (présent sans heure envoyée) :
            // l'affichage reprend celle qui fait foi. Jamais quand une heure
            // a été envoyée : l'intervenant a pu la corriger depuis.
            if (times && next === 'present' && !params.time && data && data.time && data.time !== times[key]) {
                times[key] = data.time;
                var input = state.viewMode === 'day' && state.days[state.activeDay] === date && state.activeService === svc
                    ? els.content.querySelector('[data-testid="' + field.testid + childId + '"]')
                    : null;
                if (input) input.value = data.time;
            }
        }).catch(function () {
            if (pending[key] !== seq) return;
            if (hadKey) state.attendance[key] = prevValue; else delete state.attendance[key];
            if (times) times[key] = prevTime;
            raiseNotice(t('save_error', childName(childId)));
            rerenderDay(testid);
        });
    }

    /** Heure d'arrivée (GM) ou de départ (GS) d'un enfant présent ; vide = effacée. */
    function setTime(childId, date, svc, value, previous) {
        var field = TIME_FIELDS[svc];
        var key = childId + '|' + date + '|' + svc;
        state[field.map][key] = value;
        var params = { child_id: childId, jour_date: date };
        params[field.param] = value;
        (inflight[key] || Promise.resolve()).then(function () {
            return ajax(field.action, params);
        }).then(function () {
            clearNotice();
        }).catch(function () {
            if (state[field.map][key] !== value) return; // modifiée depuis : rien à rétablir
            state[field.map][key] = previous;
            raiseNotice(t('time_error', childName(childId)));
            rerenderDay(field.testid + childId);
        });
    }

    /**
     * « Tous présents » : pas d'optimisme ici — la liste ne change qu'avec
     * la réponse du serveur, qui ne marque que les enfants à pointer
     * (jamais un absent) et renvoie leurs identifiants.
     */
    function markAllPresent(date, svc, btn) {
        var field = TIME_FIELDS[svc];
        var params = { jour_date: date, service: svc };
        if (field) params.time = nowHM();
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        ajax('psc_sidscm_all_present', params).then(function (data) {
            clearNotice();
            (data && data.updated || []).forEach(function (id) {
                var key = id + '|' + date + '|' + svc;
                state.attendance[key] = 1;
                if (field && data.time && !state[field.map][key]) state[field.map][key] = data.time;
            });
            // Bouton désactivé une fois la liste complète : le focus va au titre.
            rerenderDay('sidscm-all-present', '#psc-sidscm-at-title');
        }).catch(function () {
            raiseNotice(t('all_error'));
            rerenderDay('sidscm-all-present');
        });
    }

    /* ---------------- Rendu ---------------- */

    function countExpected(svc) {
        var day = state.activeDay;
        return state.children.filter(function (c) {
            return state.viewMode === 'week' ? (c[svc] || []).length > 0 : (c[svc] || []).indexOf(day) !== -1;
        }).length;
    }

    function sortChildren(list) {
        return list.slice().sort(function (a, b) {
            return (a.classe || '').localeCompare(b.classe || '') || a.nom.localeCompare(b.nom);
        });
    }

    function renderDays() {
        if (state.viewMode === 'week') {
            els.days.innerHTML = '<label for="psc-sidscm-week">' + escapeHtml(t('choose_week')) + '</label>' +
                '<select id="psc-sidscm-week" data-testid="sidscm-week-select">' + Object.keys(state.weeks).map(function (week) {
                    return '<option value="' + week + '"' + (week === state.week ? ' selected' : '') + '>' + escapeHtml(state.weeks[week]) + '</option>';
                }).join('') + '</select>';
            els.days.querySelector('select').addEventListener('change', function (event) {
                var select = event.target;
                select.disabled = true;
                els.content.innerHTML = '';
                fetchData(select.value).catch(function () {
                    renderAll();
                    var message = document.createElement('p');
                    message.setAttribute('role', 'alert');
                    message.textContent = t('load_error');
                    els.content.prepend(message);
                });
            });
            return;
        }
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

    /** Vue Jour : le service a au moins un enfant attendu et plus personne à pointer. */
    function serviceDone(svc) {
        var day = state.activeDay;
        var date = state.days[day];
        var expected = state.children.filter(function (c) { return (c[svc] || []).indexOf(day) !== -1; });
        return expected.length > 0 && expected.every(function (c) {
            return attendanceState(c.id + '|' + date + '|' + svc) !== 'todo';
        });
    }

    function renderServices() {
        els.services.innerHTML = SERVICE_ORDER.map(function (code) {
            var active = code === state.activeService;
            var doneHtml = state.viewMode === 'day' && serviceDone(code)
                ? ' <span class="psc-sidscm-svc-done" role="img" aria-label="' + escapeHtml(t('service_done')) + '">' + iconCheck(16) + '</span>'
                : '';
            return '<button type="button" class="psc-sidscm-svc-btn' + (active ? ' is-active' : '') +
                '" data-svc="' + code + '" data-testid="sidscm-svc-' + code + '">' +
                escapeHtml(svcLabel(code)) +
                ' <span class="psc-sidscm-svc-count">' + countExpected(code) + '</span>' + doneHtml + '</button>';
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
        // Les personnes autorisées ne sont transmises que pour la semaine
        // en cours (minimisation, RGPD-06) : ailleurs, on le dit plutôt
        // que d'annoncer une liste vide.
        if (state.week !== state.currentWeek) {
            return '<div class="psc-sidscm-auth-panel" data-testid="sidscm-auth-panel-' + c.id + '"><div class="psc-sidscm-auth-empty">' + t('authorised_current_week') + '</div></div>';
        }
        var persons = c.authorized || [];
        var personsHtml = persons.length
            ? persons.map(function (p) {
                return '<div class="psc-sidscm-auth-person">' +
                    '<span class="psc-sidscm-auth-badge">' + escapeHtml(p.role) + '</span>' +
                    '<span class="psc-sidscm-auth-name">' + escapeHtml(p.prenom) + ' ' + escapeHtml(p.nom) + '</span>' +
                    '<span class="psc-sidscm-auth-tel">' + escapeHtml(p.telephone || '—') + '</span>' +
                    '</div>';
            }).join('')
            : '<div class="psc-sidscm-auth-empty">' + t('no_authorised') + '</div>';
        return '<div class="psc-sidscm-auth-panel" data-testid="sidscm-auth-panel-' + c.id + '">' + personsHtml + '</div>';
    }

    /**
     * Contact d'urgence (tous services, semaine en cours) : rôle, nom et
     * numéros cliquables des parents. Chargés au premier dépliage.
     */
    function renderContactsPanel(c) {
        var entry = state.contacts[c.id];
        var body;
        if (!entry || entry.status === 'loading') {
            body = '<div class="psc-sidscm-auth-empty">' + t('emergency_loading') + '</div>';
        } else if (entry.status === 'error') {
            body = '<div class="psc-sidscm-auth-empty" role="alert">' + t('load_error') + '</div>';
        } else if (!entry.list.length) {
            body = '<div class="psc-sidscm-auth-empty">' + t('emergency_none') + '</div>';
        } else {
            body = '<ul class="psc-sidscm-contacts-list">' + entry.list.map(function (p) {
                var name = (p.prenom + ' ' + p.nom).trim();
                var phones = p.phones.length
                    ? p.phones.map(function (ph) {
                        return '<a class="psc-sidscm-contact-tel" href="tel:' + escapeHtml(ph.tel) + '"' +
                            ' aria-label="' + escapeHtml(t('emergency_call', ph.label, name) + ' : ' + ph.number) + '">' +
                            '<span class="psc-sidscm-contact-tel-label">' + escapeHtml(ph.label) + '</span> ' +
                            '<span class="psc-sidscm-contact-tel-number">' + escapeHtml(ph.number) + '</span></a>';
                    }).join('')
                    : '<span class="psc-sidscm-contact-none">' + t('emergency_no_phone') + '</span>';
                return '<li class="psc-sidscm-auth-person psc-sidscm-contact">' +
                    '<span class="psc-sidscm-auth-badge">' + escapeHtml(p.role) + '</span>' +
                    '<span class="psc-sidscm-auth-name">' + escapeHtml(name) + '</span>' +
                    '<span class="psc-sidscm-contact-phones">' + phones + '</span>' +
                    '</li>';
            }).join('') + '</ul>';
        }
        return '<div class="psc-sidscm-auth-panel psc-sidscm-contacts-panel" id="psc-sidscm-contacts-' + c.id + '"' +
            ' role="region" aria-live="polite" aria-label="' + escapeHtml(t('emergency_for', c.prenom + ' ' + c.nom)) + '"' +
            ' data-testid="sidscm-contacts-panel-' + c.id + '">' + body + '</div>';
    }

    function loadContacts(childId) {
        state.contacts[childId] = { status: 'loading', list: [] };
        return ajax('psc_sidscm_contacts', { child_id: childId }).then(function (data) {
            state.contacts[childId] = { status: 'ok', list: data.contacts || [] };
        }).catch(function () {
            // Nouvel essai au prochain dépliage.
            state.contacts[childId] = { status: 'error', list: [] };
        });
    }

    function focusContactsToggle(childId) {
        var btn = els.content.querySelector('[data-testid="sidscm-contacts-toggle-' + childId + '"]');
        if (btn) btn.focus();
    }

    function iconCheck(size) {
        return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" aria-hidden="true" focusable="false"><path d="M4.5 12.5l5 5 10-11"/></svg>';
    }

    function iconCross(size) {
        return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>';
    }

    /** Libellé pluriel (formes de _n() transmises pour 1 et pour 2) ; `n` peut être déjà balisé. */
    function countLabel(key, count, n) {
        return t(count > 1 ? key : key + '_one', n === undefined ? count : n);
    }

    /** Pastille de compteur : nombre en gras, libellé traduit échappé. */
    function chipHtml(mod, key, count) {
        var MARK = '\u0001';
        var label = escapeHtml(countLabel(key, count, MARK)).replace(MARK, '<b>' + count + '</b>');
        return '<span class="psc-sidscm-at-chip psc-sidscm-at-chip--' + mod + (mod === 't' && !count ? ' is-zero' : '') + '">' + label + '</span>';
    }

    function renderDayView() {
        var svc = state.activeService;
        var day = state.activeDay;
        var date = state.days[day];
        var field = TIME_FIELDS[svc];

        var rows = sortChildren(state.children.filter(function (c) {
            return (c[svc] || []).indexOf(day) !== -1;
        }));

        var counts = { present: 0, absent: 0, todo: 0 };
        var rowsHtml = rows.map(function (c) {
            var key = c.id + '|' + date + '|' + svc;
            var st = attendanceState(key);
            counts[st]++;
            var name = (c.prenom + ' ' + c.nom).trim();
            var dietHtml = (svc === 'CANT' && c.diet)
                ? '<span class="psc-sidscm-at-badge" data-testid="sidscm-diet-' + c.id + '">' + escapeHtml(c.diet) + '</span>'
                : '';
            // Allergie alimentaire : usage critique de la liste cantine —
            // en priorité sur la ligne, lisible sans déplier.
            var allergyHtml = (svc === 'CANT' && c.allergies)
                ? '<span class="psc-sidscm-row-allergy">' + escapeHtml(c.allergies) + '</span>'
                : '';
            // « Midi sans repas » (statut « cantine sans repas » posé par la
            // mairie, allergie comprise) : l'enfant est présent sur le
            // créneau mais n'y déjeune pas — à ne pas compter dans les couverts.
            var noMealHtml = (svc === 'CANT' && (c.MSR || []).indexOf(day) !== -1)
                ? '<span class="psc-sidscm-row-brings-meal">' + t('no_meal') + '</span>'
                : '';
            // Heure (arrivée GM, départ GS) : saisissable seulement pour un
            // enfant présent ; sinon l'espace reste réservé, la ligne ne
            // saute pas d'une mise en page à l'autre.
            var timeHtml = '';
            if (field) {
                timeHtml = st === 'present'
                    ? '<label class="psc-sidscm-at-time">' + escapeHtml(t(field.label)) +
                        ' <input type="time" data-child-id="' + c.id + '" value="' + escapeHtml(state[field.map][key] || '') + '"' +
                        ' data-testid="' + field.testid + c.id + '"></label>'
                    : '<span class="psc-sidscm-at-time-ph" aria-hidden="true"></span>';
            }
            // Personnes autorisées : uniquement pertinent pour la Garderie
            // soir (seul service avec un vrai départ à contrôler) — masqué
            // sur Garderie matin et Cantine pour ne pas surcharger ces
            // listes d'une information qui n'y sert à rien.
            var expanded = svc === 'GS' && !!state.authExpanded[c.id];
            var authToggleHtml = svc === 'GS'
                ? '<button type="button" class="psc-sidscm-auth-toggle' + (expanded ? ' is-expanded' : '') +
                    '" data-child-id="' + c.id + '" data-testid="sidscm-auth-toggle-' + c.id + '" aria-expanded="' + (expanded ? 'true' : 'false') + '">' +
                    '<span class="psc-sidscm-auth-toggle-icon" aria-hidden="true">' + (expanded ? '−' : '+') + '</span> ' + t('authorised') + '</button>'
                : '';
            // Contact d'urgence : sur tous les services, la vue Jour portant
            // toujours sur la semaine en cours.
            var contactsOpen = !!state.contactsExpanded[c.id];
            var contactsToggleHtml = state.week === state.currentWeek
                ? '<button type="button" class="psc-sidscm-auth-toggle psc-sidscm-contacts-toggle' + (contactsOpen ? ' is-expanded' : '') +
                    '" data-child-id="' + c.id + '" data-testid="sidscm-contacts-toggle-' + c.id + '"' +
                    ' aria-expanded="' + (contactsOpen ? 'true' : 'false') + '" aria-controls="psc-sidscm-contacts-' + c.id + '">' +
                    '<span class="psc-sidscm-auth-toggle-icon" aria-hidden="true">☎</span> ' + t('emergency') + '</button>'
                : '';
            // Présent / Absent : état porté par aria-pressed, l'icône et le
            // texte (jamais par la seule couleur). Toucher le bouton actif
            // annule le pointage.
            var segHtml = '<div class="psc-sidscm-at-seg" role="group" aria-label="' + escapeHtml(t('presence_of', name)) + '">' +
                '<button type="button"' + (st === 'present' ? ' class="is-on-p"' : '') + ' aria-pressed="' + (st === 'present') + '"' +
                ' data-child-id="' + c.id + '" data-state="present" data-testid="sidscm-present-' + c.id + '">' + iconCheck(20) + escapeHtml(t('present')) + '</button>' +
                '<button type="button"' + (st === 'absent' ? ' class="is-on-a"' : '') + ' aria-pressed="' + (st === 'absent') + '"' +
                ' data-child-id="' + c.id + '" data-state="absent" data-testid="sidscm-absent-' + c.id + '">' + iconCross(20) + escapeHtml(t('absent')) + '</button>' +
                '</div>';
            return '<div class="psc-sidscm-at-row is-' + st + '" data-testid="sidscm-row-' + c.id + '">' +
                '<div class="psc-sidscm-at-who">' +
                '<span class="psc-sidscm-at-name">' + escapeHtml(name) + '</span>' +
                '<span class="psc-sidscm-at-cls">' + escapeHtml(c.classe || '') + '</span>' +
                dietHtml + allergyHtml + noMealHtml +
                '</div>' + timeHtml +
                ((contactsToggleHtml || authToggleHtml) ? '<span class="psc-sidscm-at-tools">' + contactsToggleHtml + authToggleHtml + '</span>' : '') +
                segHtml +
                '</div>' + (contactsOpen ? renderContactsPanel(c) : '') + (expanded ? renderAuthPanel(c) : '');
        }).join('');

        var total = rows.length;
        var emptyHtml = total === 0
            ? '<div class="psc-sidscm-empty" data-testid="sidscm-empty">' + t('empty_day') + '</div>'
            : '';
        var pct = function (n) { return total ? Math.round(n / total * 1000) / 10 : 0; };
        var help = escapeHtml(t('day_help', '\u0001', '\u0002'))
            .replace('\u0001', '<b>' + escapeHtml(t('present')) + '</b>')
            .replace('\u0002', '<b>' + escapeHtml(t('absent')) + '</b>');
        var doneHtml = total > 0 && counts.todo === 0
            ? '<div class="psc-sidscm-at-done" data-testid="sidscm-done">' + iconCheck(18) +
                escapeHtml(t('day_done', countLabel('count_present', counts.present), countLabel('count_absent', counts.absent))) + '</div>'
            : '';
        // Nouvel échec : la notice est insérée vide puis remplie, pour que
        // role="alert" l'annonce ; un simple re-rendu la remet telle quelle.
        var noticeStatic = state.notice && !state.noticeFresh;

        els.content.innerHTML =
            '<section class="psc-sidscm-at-list" aria-labelledby="psc-sidscm-at-title" data-testid="sidscm-day-panel">' +
            '<div class="psc-sidscm-at-hd">' +
            '<div>' +
            '<h2 class="psc-sidscm-at-title" id="psc-sidscm-at-title" tabindex="-1">' + escapeHtml(svcLabel(svc)) + ' — ' + escapeHtml(capitalize(day)) + '</h2>' +
            '<p class="psc-sidscm-at-help">' + help + '</p>' +
            '</div>' +
            '<div class="psc-sidscm-at-sum">' +
            '<div class="psc-sidscm-at-count" aria-live="polite" data-testid="sidscm-present-count">' +
            chipHtml('p', 'count_present', counts.present) + ' ' +
            chipHtml('a', 'count_absent', counts.absent) + ' ' +
            chipHtml('t', 'count_todo', counts.todo) +
            '</div>' +
            '<button type="button" class="psc-sidscm-at-all" data-testid="sidscm-all-present"' + (counts.todo ? '' : ' disabled') + '>' + escapeHtml(t('all_present')) + '</button>' +
            '</div>' +
            '</div>' +
            '<div class="psc-sidscm-at-bar" aria-hidden="true">' +
            '<i class="psc-sidscm-at-bar-p" style="width:' + pct(counts.present) + '%"></i>' +
            '<i class="psc-sidscm-at-bar-a" style="width:' + pct(counts.absent) + '%"></i>' +
            '</div>' +
            '<div class="psc-sidscm-at-notice" role="alert" data-testid="sidscm-save-error"' + (noticeStatic ? '' : ' hidden') + '>' +
            (noticeStatic ? escapeHtml(state.notice) : '') + '</div>' +
            rowsHtml + emptyHtml + doneHtml +
            '</section>';

        if (state.notice && state.noticeFresh) {
            var notice = els.content.querySelector('.psc-sidscm-at-notice');
            notice.hidden = false;
            notice.textContent = state.notice;
            state.noticeFresh = false;
        }

        els.content.querySelectorAll('.psc-sidscm-at-seg button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                setAttendance(btn.dataset.childId, date, svc, btn.dataset.state, btn.dataset.testid);
            });
        });

        // Heure : pas de recalcul de compteur, pas besoin de re-rendre toute
        // la vue à chaque changement.
        els.content.querySelectorAll('.psc-sidscm-at-time input').forEach(function (input) {
            input.addEventListener('change', function () {
                var childId = input.dataset.childId;
                var key = childId + '|' + date + '|' + svc;
                setTime(childId, date, svc, input.value, state[field.map][key] || '');
            });
        });

        var allBtn = els.content.querySelector('.psc-sidscm-at-all');
        allBtn.addEventListener('click', function () {
            markAllPresent(date, svc, allBtn);
        });

        // Plusieurs lignes peuvent être dépliées en même temps (comparer
        // avant un départ groupé) : chaque bouton ne replie que sa ligne.
        els.content.querySelectorAll('.psc-sidscm-contacts-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var childId = btn.dataset.childId;
                state.contactsExpanded[childId] = !state.contactsExpanded[childId];
                var entry = state.contacts[childId];
                var needsLoad = state.contactsExpanded[childId] && (!entry || entry.status === 'error');
                if (needsLoad) {
                    // Réponse tardive : re-rendu qui garde le focus là où
                    // l'intervenant l'a mis entre-temps (il pointe peut-être
                    // déjà un autre enfant), jamais ramené de force ici.
                    loadContacts(childId).then(function () { rerenderDay(); });
                }
                renderDayView();
                focusContactsToggle(childId);
            });
        });

        els.content.querySelectorAll('.psc-sidscm-auth-toggle:not(.psc-sidscm-contacts-toggle)').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var childId = btn.dataset.childId;
                state.authExpanded[childId] = !state.authExpanded[childId];
                renderDayView();
                focusTarget('sidscm-auth-toggle-' + childId);
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
            return '<th scope="col" class="psc-sidscm-week-day">' + escapeHtml(capitalize(d)) + '<br>' + escapeHtml(formatDDMM(state.days[d])) + '</th>';
        }).join('');

        // Personnes autorisées : uniquement pour la Garderie soir, cf.
        // renderDayView() — masqué en semaine sur les autres services.
        var authWeekBtn = function (c) {
            return svc === 'GS' && state.week === state.currentWeek
                ? '<button type="button" class="psc-sidscm-auth-toggle psc-sidscm-auth-toggle--week" data-child-id="' + c.id + '"' +
                    ' data-testid="sidscm-auth-week-' + c.id + '"><span class="psc-sidscm-auth-toggle-icon">+</span> ' + t('authorised') + '</button>'
                : '';
        };

        var rowsHtml = rows.map(function (c) {
            var marksHtml = dayKeys.map(function (d) {
                var expected = (c[svc] || []).indexOf(d) !== -1;
                return '<td class="psc-sidscm-week-day"><span class="psc-sidscm-mark' + (expected ? '' : ' is-absent') + '">' +
                    (expected ? '●' : '—') + '</span></td>';
            }).join('');
            // Allergie alimentaire + mention « apporte son repas » : usage
            // critique, visibles aussi dans la vue Semaine (cantines).
            var allergyHtml = (svc === 'CANT' && c.allergies)
                ? '<span class="psc-sidscm-row-allergy">' + escapeHtml(c.allergies) + '</span>'
                : '';
            var noMealWeekHtml = (svc === 'CANT' && (c.MSR || []).length)
                ? '<span class="psc-sidscm-row-brings-meal">' + t('no_meal') + '</span>'
                : '';
            var dietWeekHtml = (svc === 'CANT' && c.diet)
                ? '<span class="psc-sidscm-row-diet" data-testid="sidscm-diet-' + c.id + '">' + escapeHtml(c.diet) + '</span>'
                : '';
            return '<tr><td class="psc-sidscm-table-child-cell">' + escapeHtml(c.prenom) + ' ' + escapeHtml(c.nom) +
                ' <span class="psc-sidscm-table-child-classe">(' + escapeHtml(c.classe || '') + ')</span>' +
                allergyHtml + noMealWeekHtml + dietWeekHtml +
                authWeekBtn(c) + '</td>' +
                marksHtml + '</tr>';
        }).join('');

        els.content.innerHTML =
            '<div class="psc-sidscm-panel" data-testid="sidscm-week-panel">' +
            '<div class="psc-sidscm-panel-head"><div class="psc-sidscm-panel-title">' + escapeHtml(svcLabel(svc)) + ' — ' + escapeHtml(state.weeks[state.week] || t('week')) + '</div></div>' +
            '<div class="psc-sidscm-table-scroll"><table class="psc-sidscm-table">' +
            '<thead><tr><th class="psc-sidscm-table-child-head">' + t('child') + '</th>' + headHtml + '</tr></thead>' +
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
        els.intervenantId = document.getElementById('psc-sidscm-intervenant-id');
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
            if (!code || (els.intervenantId && !els.intervenantId.value)) return;
            unlock(code, false);
        });

        els.modeDay.addEventListener('click', function () {
            state.viewMode = 'day';
            if (state.week !== state.currentWeek) {
                els.content.innerHTML = '';
                fetchData(state.currentWeek).catch(function () { state.viewMode = 'week'; renderAll(); });
            } else {
                renderAll();
            }
        });
        els.modeWeek.addEventListener('click', function () {
            state.viewMode = 'week';
            renderAll();
        });
        els.lockBtn.addEventListener('click', lock);

        // Le code n’est conservé ni dans localStorage ni dans sessionStorage.
        // Un rechargement de la page impose une nouvelle authentification.
    });
})();
