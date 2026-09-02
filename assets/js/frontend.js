(function () {
    'use strict';

    // Messages traduits côté serveur (PSC.i18n, cf. Psc_Frontend::assets())
    // : les codes restent ceux renvoyés par l'AJAX, seuls les libellés
    // sont injectés ici.
    var MESSAGES = PSC.i18n;

    function messageFor(res, fallback) {
        if (res && res.data && res.data.message) return res.data.message;
        var code = res && res.data && res.data.code;
        return MESSAGES[code] || MESSAGES[fallback] || MESSAGES.generic;
    }

    function post(payload) {
        var params = { nonce: PSC.nonce, parent_nonce: PSC.parent_nonce || '' };
        Object.keys(payload).forEach(function (k) { params[k] = payload[k]; });
        return window.PscAjax.envelope(PSC.ajax_url, params);
    }

    /* ---------- Cases à cocher ---------- */

    function cellNotice(cb, message) {
        var cell = cb.closest('td');
        var old = cell.querySelector('.psc-error');
        if (old) old.remove();

        var span = document.createElement('span');
        span.className = 'psc-error';
        span.setAttribute('role', 'alert');
        span.setAttribute('data-testid', 'toggle-error');
        span.textContent = message;
        cell.appendChild(span);
        setTimeout(function () { span.remove(); }, 7000);
    }

    // Total du mois par enfant (espace famille v2) : somme des jours du
    // tableau affiché (un seul mois est rendu à la fois).
    function recomputeChildTotal(childBlock) {
        var totalEl = childBlock.querySelector('[data-child-total]');
        if (!totalEl) return;

        var daysWithReg = {};
        var total = 0;
        childBlock.querySelectorAll('tbody tr').forEach(function (row) {
            var forf = row.querySelector('.psc-check[data-service="FORF"]');
            if (forf && forf.checked) {
                daysWithReg[forf.dataset.date] = true;
                total += parseFloat(forf.dataset.price) || 0;
                return;
            }
            row.querySelectorAll('.psc-check').forEach(function (cb) {
                if (cb.dataset.service === 'FORF' || !cb.checked) return;
                daysWithReg[cb.dataset.date] = true;
                total += parseFloat(cb.dataset.price) || 0;
            });
        });

        var count = Object.keys(daysWithReg).length;
        totalEl.textContent = count + ' ' + (count > 1 ? MESSAGES.days : MESSAGES.day) + ' · ' +
            total.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    // Bandeau récapitulatif fratrie (au-dessus des tableaux) : jours +
    // montant du mois affiché, tous enfants confondus.
    function recomputeSiblingBanner() {
        var banner = document.getElementById('psc-sibling-banner');
        if (!banner) return;
        var valueEl = banner.querySelector('[data-sibling-total]');
        if (!valueEl) return;

        var daysWithReg = {};
        var total = 0;
        document.querySelectorAll('.psc-portal-child-block').forEach(function (childBlock) {
            childBlock.querySelectorAll('tbody tr').forEach(function (row) {
                var forf = row.querySelector('.psc-check[data-service="FORF"]');
                if (forf && forf.checked) {
                    daysWithReg[childBlock.dataset.childId + '|' + forf.dataset.date] = true;
                    total += parseFloat(forf.dataset.price) || 0;
                    return;
                }
                row.querySelectorAll('.psc-check').forEach(function (cb) {
                    if (cb.dataset.service === 'FORF' || !cb.checked) return;
                    daysWithReg[childBlock.dataset.childId + '|' + cb.dataset.date] = true;
                    total += parseFloat(cb.dataset.price) || 0;
                });
            });
        });

        var count = Object.keys(daysWithReg).length;
        valueEl.textContent = count + ' ' + (count > 1 ? MESSAGES.days : MESSAGES.day) + ' · ' +
            total.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    // La réponse du serveur porte l'état « explicite » de toutes les cases
    // du mois affiché (une case cochée = une ligne stockée) : on réaligne
    // le DOM sur son verdict — l'invariant côté serveur peut avoir retiré
    // une exception résiduelle, et les verrous évoluent avec le temps.
    function applyExplicitState(state) {
        var explicit = state && state.explicit;
        if (!explicit) return;
        Object.keys(explicit).forEach(function (childId) {
            Object.keys(explicit[childId]).forEach(function (date) {
                Object.keys(explicit[childId][date]).forEach(function (service) {
                    var cell = explicit[childId][date][service];
                    var cb = document.querySelector('.psc-check[data-child="' + childId + '"][data-date="' + date + '"][data-service="' + service + '"]');
                    if (!cb) return;
                    cb.checked = !!cell.explicit;
                    cb.disabled = !!cell.locked || !!cell.closed;
                });
            });
        });
    }

    /* ---------- Boutons "Tout" par colonne de service ---------- */

    function btnNotice(btn, message) {
        var cell = btn.closest('th');
        if (!cell) return;
        var old = cell.querySelector('.psc-error');
        if (old) old.remove();

        var span = document.createElement('span');
        span.className = 'psc-error';
        span.setAttribute('role', 'alert');
        span.setAttribute('data-testid', 'tout-error');
        span.textContent = message;
        cell.appendChild(span);
        setTimeout(function () { span.remove(); }, 7000);
    }

    // Jours déclarables (data-dates, calculés côté serveur au chargement)
    // -> cases correspondantes réellement présentes dans le tableau.
    function toutTargets(btn) {
        var dates = (btn.dataset.dates || '').split(',').filter(Boolean);
        var table = btn.closest('table');
        var service = btn.dataset.service;
        var map = {};
        if (!table) return map;
        dates.forEach(function (date) {
            var cb = table.querySelector('.psc-check[data-date="' + date + '"][data-service="' + service + '"]');
            if (cb) map[date] = cb;
        });
        return map;
    }

    // État "Tout" / "Retirer" recalculé depuis l'état réel des cases —
    // jamais suivi séparément, pour ne jamais désynchroniser un clic
    // individuel du bouton de sa colonne.
    function recomputeToutButton(btn) {
        var targets = toutTargets(btn);
        var dates = Object.keys(targets);
        if (!dates.length) return;
        var allChecked = dates.every(function (d) { return targets[d].checked; });
        btn.classList.toggle('psc-tout-btn-all', allChecked);
        btn.textContent = allChecked ? MESSAGES.remove : MESSAGES.tout;
    }

    function recomputeToutButtonsIn(table) {
        if (!table) return;
        table.querySelectorAll('.psc-tout-btn').forEach(recomputeToutButton);
    }

    function onToutClick(btn) {
        if (btn.disabled) return;

        var targets = toutTargets(btn);
        var dates = Object.keys(targets);
        if (!dates.length) return;

        var willCheck  = !btn.classList.contains('psc-tout-btn-all');
        var table      = btn.closest('table');
        var childBlock = btn.closest('.psc-portal-child-block');

        btn.disabled = true;

        // Application optimiste : les cases déjà désactivées (verrou, prestation
        // fermée, assurance manquante) ne sont pas touchées, le serveur les
        // rejettera de toute façon.
        var previousChecked = {};
        dates.forEach(function (d) {
            var cb = targets[d];
            previousChecked[d] = cb.checked;
            if (!cb.disabled) cb.checked = willCheck;
        });

        if (childBlock) recomputeChildTotal(childBlock);
        recomputeSiblingBanner();
        if (table) recomputeToutButtonsIn(table);

        post({
            action: 'psc_toggle_exception_bulk',
            child_id: btn.dataset.child,
            service_code: btn.dataset.service,
            checked: willCheck ? '1' : '0',
            dates: dates.join(',')
        }).then(function (res) {
            if (res && res.success) {
                var applied = (res.data && res.data.applied) || [];
                var appliedSet = {};
                applied.forEach(function (d) { appliedSet[d] = true; });

                // Dates non réellement appliquées (verrou tombé entre le
                // chargement de la page et le clic, etc.) : reviennent à
                // leur état précédent plutôt que de mentir sur l'écran.
                dates.forEach(function (d) {
                    if (!appliedSet[d]) targets[d].checked = previousChecked[d];
                });

                // L'état complet du mois (cases explicites, verrous) est
                // réaligné sur la réponse du serveur.
                applyExplicitState(res.data && res.data.state);
                window.PSC_DATA_STALE = true;
            } else {
                dates.forEach(function (d) { targets[d].checked = previousChecked[d]; });
                btnNotice(btn, messageFor(res, 'generic'));
            }

            btn.disabled = false;
            if (childBlock) recomputeChildTotal(childBlock);
            recomputeSiblingBanner();
            if (table) recomputeToutButtonsIn(table);
        }).catch(function () {
            dates.forEach(function (d) { targets[d].checked = previousChecked[d]; });
            btn.disabled = false;
            if (childBlock) recomputeChildTotal(childBlock);
            recomputeSiblingBanner();
            if (table) recomputeToutButtonsIn(table);
            btnNotice(btn, MESSAGES.network);
        });
    }

    function onToggle(cb) {
        cb.disabled = true;

        var willBeChecked = cb.checked;
        var childBlock = cb.closest('.psc-portal-child-block');
        var table = cb.closest('table');

        // Cascade UI : le forfait et les prestations élémentaires restent
        // mutuellement exclusifs à l'affichage d'un jour. Cocher l'un décoche
        // les autres (le serveur écrit un retrait exceptionnel pour ce jour
        // seulement — le rythme de la semaine n'est pas touché).
        var siblingsToUncheck = [];
        if (willBeChecked) {
            var row = cb.closest('tr');
            if (row) {
                row.querySelectorAll('.psc-check').forEach(function (c) {
                    if (c !== cb && c.checked && !c.disabled && !psc_is_locked_date(c.dataset.date)) {
                        siblingsToUncheck.push(c);
                    }
                });
            }
            siblingsToUncheck.forEach(function (sib) { sib.checked = false; });
        }

        if (childBlock) recomputeChildTotal(childBlock);
        recomputeSiblingBanner();
        if (table) recomputeToutButtonsIn(table);

        post({
            action: 'psc_toggle_exception',
            child_id: cb.dataset.child,
            date: cb.dataset.date,
            service_code: cb.dataset.service,
            checked: willBeChecked ? '1' : '0'
        }).then(function (res) {
            if (res && res.success) {
                cb.disabled = false;
                var cell = cb.closest('td');
                cell.classList.add('psc-ok');
                setTimeout(function () { cell.classList.remove('psc-ok'); }, 700);

                // D'autres onglets déjà rendus au chargement (ex : Tableau
                // de bord, "X jours déclarés") deviennent obsolètes dès
                // qu'une case change réellement côté serveur — cf.
                // portal.js, qui force un rechargement complet au prochain
                // changement d'onglet plutôt qu'une bascule client instantanée.
                window.PSC_DATA_STALE = true;

                applyExplicitState(res.data && res.data.state);

                siblingsToUncheck.forEach(function (sib) {
                    post({
                        action: 'psc_toggle_exception',
                        child_id: sib.dataset.child,
                        date: sib.dataset.date,
                        service_code: sib.dataset.service,
                        checked: '0'
                    });
                });
                return;
            }

            // Erreur : annuler le changement d'UI
            cb.checked = !willBeChecked;
            siblingsToUncheck.forEach(function (sib) { sib.checked = true; });
            if (childBlock) recomputeChildTotal(childBlock);
            recomputeSiblingBanner();
            if (table) recomputeToutButtonsIn(table);

            var code = res && res.data && res.data.code;

            // Si le serveur signale un verrou ou une assurance manquante, la
            // case reste désactivée : la situation a pu changer pendant que
            // la page était ouverte (délai expiré, ou document retiré).
            if (code === 'locked' || code === 'assurance_missing' || code === 'day_closed') {
                cb.closest('tr').classList.add('psc-row-locked');
            } else {
                cb.disabled = false;
            }
            cellNotice(cb, messageFor(res, 'generic'));
        }).catch(function () {
            cb.disabled = false;
            cb.checked = !willBeChecked;
            siblingsToUncheck.forEach(function (sib) { sib.checked = true; });
            if (childBlock) recomputeChildTotal(childBlock);
            recomputeSiblingBanner();
            if (table) recomputeToutButtonsIn(table);
            cellNotice(cb, MESSAGES.network);
        });
    }

    function psc_is_locked_date(date) {
        var cell = document.querySelector('.psc-check[data-date="' + date + '"]');
        return !!(cell && cell.closest('tr') && cell.closest('tr').classList.contains('psc-row-locked'));
    }

    /* ---------- Bouton de confirmation ---------- */

    function onConfirm(btn, feedback) {
        btn.disabled = true;
        var original = btn.textContent;
        btn.textContent = MESSAGES.sending;
        feedback.textContent = '';
        feedback.className = 'psc-confirm-feedback';

        post({ action: 'psc_confirm' }).then(function (res) {
            btn.disabled = false;
            btn.textContent = original;

            if (res && res.success) {
                feedback.className = 'psc-confirm-feedback psc-ok-text';
                feedback.textContent = (res.data && res.data.message) || MESSAGES.summary_sent;
            } else {
                feedback.className = 'psc-confirm-feedback psc-err-text';
                feedback.textContent = messageFor(res, 'generic');
            }
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = original;
            feedback.className = 'psc-confirm-feedback psc-err-text';
            feedback.textContent = MESSAGES.network;
        });
    }

    // Les formulaires du plan (connexion, inscription, gestion des
    // enfants) restent des POST classiques (rechargement de page) : sans
    // intervention, le navigateur revient en haut de page après chaque
    // enregistrement. On mémorise la position juste avant l'envoi, et on
    // la restaure au chargement suivant — la popin reste alors visible
    // au même endroit, sans que la page ne "remonte".
    var SCROLL_KEY = 'psc_scroll_restore';

    function initScrollSave() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (form.matches('.psc-login-form, .psc-request-form, .psc-child-update-form, .psc-add-child-form')) {
                sessionStorage.setItem(SCROLL_KEY, String(window.scrollY));
            }
        });
    }

    function restoreScroll() {
        var saved = sessionStorage.getItem(SCROLL_KEY);
        if (saved === null) return;
        sessionStorage.removeItem(SCROLL_KEY);

        var y = parseInt(saved, 10);
        if (isNaN(y)) return;

        window.scrollTo(0, y);
        // Filet de sécurité : si des éléments encore en cours de chargement
        // (images, polices) décalent la mise en page, on recale une fois
        // tout chargé.
        window.addEventListener('load', function () { window.scrollTo(0, y); }, { once: true });
    }

    // Au tout début du script (chargé en pied de page, le DOM du contenu
    // est déjà présent) : restaurer avant la première peinture visible
    // limite le "saut" perceptible en haut de page.
    restoreScroll();

    // Popin auto-masquée (ex : "lien de connexion envoyé") : disparaît
    // seule après 3 s (ou plus tôt via la croix de fermeture), et nettoie
    // ?psc_msg de l'URL pour qu'un rechargement de page ne la réaffiche pas.
    function hideToast(toast) {
        if (toast.classList.contains('psc-toast-hide')) return;
        toast.classList.add('psc-toast-hide');

        var removed = false;
        var remove = function () {
            if (removed) return;
            removed = true;
            toast.remove();
        };
        toast.addEventListener('animationend', remove, { once: true });
        // Filet de sécurité : un onglet en arrière-plan ou un réglage
        // "réduire les animations" peut empêcher animationend de se
        // déclencher — la popin ne doit jamais rester bloquée à l'écran.
        setTimeout(remove, 400);
    }

    function initToast() {
        var toast = document.querySelector('.psc-toast');
        if (!toast) return;

        var timer = setTimeout(function () { hideToast(toast); }, 3000);

        var closeBtn = toast.querySelector('.psc-toast-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                clearTimeout(timer);
                hideToast(toast);
            });
        }

        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            if (url.searchParams.has('psc_msg')) {
                url.searchParams.delete('psc_msg');
                window.history.replaceState({}, '', url);
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initToast();
        initScrollSave();

        document.querySelectorAll('.psc-check').forEach(function (cb) {
            cb.addEventListener('change', function () { onToggle(cb); });
        });

        document.querySelectorAll('.psc-tout-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { onToutClick(btn); });
        });

        var btn = document.getElementById('psc-confirm');
        var feedback = document.getElementById('psc-confirm-feedback');
        if (btn && feedback) {
            btn.addEventListener('click', function () { onConfirm(btn, feedback); });
        }

        recomputeSiblingBanner();
    });
})();
