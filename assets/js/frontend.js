(function () {
    'use strict';

    var MESSAGES = {
        auth: 'Votre session a expiré. Rechargez la page pour demander un nouveau lien.',
        forbidden: "Vous n'êtes pas autorisé à modifier cette inscription.",
        notfound: 'Enfant introuvable. Merci de recharger la page.',
        day_closed: "Ce jour n'est pas ouvert aux inscriptions.",
        closed: "Aucune période d'inscription n'est ouverte actuellement.",
        locked: 'Le délai de modification est dépassé pour ce jour. Contactez la mairie.',
        assurance_missing: 'L\'assurance scolaire de cet enfant n\'a pas été fournie pour l\'année en cours. Ajoutez-la depuis « Mes enfants ».',
        service_closed: 'Cette prestation est fermée ce jour-là. Contactez la mairie.',
        service: 'Prestation inconnue.',
        invalid: 'Données invalides.',
        nochild: 'Aucun enfant rattaché à votre compte.',
        rate: 'Trop de demandes. Merci de patienter quelques minutes.',
        mail: "L'envoi de l'e-mail a échoué.",
        network: 'Erreur réseau. Vérifiez votre connexion et réessayez.',
        generic: "Une erreur est survenue. Merci de réessayer."
    };

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

    function recomputeMonthSummary(monthBlock) {
        var summaryEl = monthBlock.querySelector('[data-month-summary]');
        if (!summaryEl) return;

        var daysWithReg = {};
        var total = 0;
        monthBlock.querySelectorAll('.psc-check').forEach(function (cb) {
            if (!cb.checked) return;
            daysWithReg[cb.dataset.date] = true;
            total += parseFloat(cb.dataset.price) || 0;
        });

        var count = Object.keys(daysWithReg).length;
        if (count > 0) {
            summaryEl.textContent = count + ' jour' + (count > 1 ? 's' : '') + ' · ' +
                total.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
            summaryEl.classList.add('psc-month-summary-active');
            summaryEl.classList.remove('psc-month-summary-empty');
        } else {
            summaryEl.textContent = 'Aucun jour déclaré';
            summaryEl.classList.remove('psc-month-summary-active');
            summaryEl.classList.add('psc-month-summary-empty');
        }
    }

    // Total période par enfant (espace famille v2) : somme sur tous les
    // mois de ce bloc enfant, pas seulement le mois affecté par le clic.
    // Absent des anciennes pages (pas de [data-child-total]) : no-op.
    function recomputeChildTotal(childBlock) {
        var totalEl = childBlock.querySelector('[data-child-total]');
        if (!totalEl) return;

        var daysWithReg = {};
        var total = 0;
        childBlock.querySelectorAll('.psc-check').forEach(function (cb) {
            if (!cb.checked) return;
            daysWithReg[cb.dataset.date] = true;
            total += parseFloat(cb.dataset.price) || 0;
        });

        var count = Object.keys(daysWithReg).length;
        totalEl.textContent = count + ' jour' + (count > 1 ? 's' : '') + ' · ' +
            total.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
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
        btn.textContent = allChecked ? 'Retirer' : 'Tout';
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
        var monthBlock = btn.closest('.psc-month-block');
        var childBlock = btn.closest('.psc-portal-child-block');

        btn.disabled = true;

        // Application optimiste : les cases déjà désactivées (assurance
        // manquante sur un jour encore non déclaré) ne sont pas touchées,
        // le serveur les rejettera de toute façon.
        var previousChecked = {};
        dates.forEach(function (d) {
            var cb = targets[d];
            previousChecked[d] = cb.checked;
            if (!cb.disabled) {
                cb.checked = willCheck;
                // Forfait <-> prestations individuelles mutuellement
                // exclusifs : appliquer tout de suite (comme onToggle())
                // pour ne pas surcompter le total le temps de la requête.
                if (btn.dataset.service === 'FORF') applyForfaitUI(cb, willCheck);
            }
        });

        if (monthBlock) recomputeMonthSummary(monthBlock);
        if (childBlock) recomputeChildTotal(childBlock);
        if (table) recomputeToutButtonsIn(table);

        post({
            action: 'psc_toggle_bulk',
            child_id: btn.dataset.child,
            service: btn.dataset.service,
            checked: willCheck ? '1' : '0',
            dates: dates.join(',')
        }).then(function (res) {
            if (res && res.success) {
                var applied = (res.data && res.data.dates) || [];
                var appliedSet = {};
                applied.forEach(function (d) { appliedSet[d] = true; });

                // Dates non réellement appliquées (verrou tombé entre le
                // chargement de la page et le clic, etc.) : reviennent à
                // leur état précédent plutôt que de mentir sur l'écran.
                dates.forEach(function (d) {
                    if (appliedSet[d]) return;
                    targets[d].checked = previousChecked[d];
                });
                dates.forEach(function (d) {
                    if (!appliedSet[d]) return;
                    var cb = targets[d];
                    // La case peut avoir été rendue désactivée par un
                    // forfait déjà présent ce jour-là (page chargée avant
                    // ce lot) : le serveur vient de trancher, on réaligne
                    // l'affichage sur son verdict plutôt que de laisser une
                    // case cochée/décochée obsolète.
                    cb.disabled = false;
                    cb.checked = willCheck;
                    if (btn.dataset.service === 'FORF') {
                        applyForfaitUI(cb, willCheck);
                    } else if (willCheck) {
                        // Une prestation individuelle exclut le forfait sur
                        // ce jour (mutuellement exclusifs côté serveur) :
                        // décocher le forfait de la ligne et réactiver les
                        // autres cases qu'il avait désactivées.
                        var row = cb.closest('tr');
                        var forf = row && row.querySelector('.psc-check[data-service="FORF"]');
                        if (forf && forf.checked) {
                            forf.checked = false;
                            applyForfaitUI(forf, false);
                            cb.checked = true; // réactivé par applyForfaitUI(forf, false), re-cocher
                        }
                    }
                    var cell = cb.closest('td');
                    if (!cell) return;
                    cell.classList.add('psc-ok');
                    setTimeout(function () { cell.classList.remove('psc-ok'); }, 700);
                });

                window.PSC_DATA_STALE = true;
            } else {
                dates.forEach(function (d) { targets[d].checked = previousChecked[d]; });
                btnNotice(btn, messageFor(res, 'generic'));
            }

            btn.disabled = false;
            if (monthBlock) recomputeMonthSummary(monthBlock);
            if (childBlock) recomputeChildTotal(childBlock);
            if (table) recomputeToutButtonsIn(table);
        }).catch(function () {
            dates.forEach(function (d) { targets[d].checked = previousChecked[d]; });
            btn.disabled = false;
            if (monthBlock) recomputeMonthSummary(monthBlock);
            if (childBlock) recomputeChildTotal(childBlock);
            if (table) recomputeToutButtonsIn(table);
            btnNotice(btn, MESSAGES.network);
        });
    }

    function applyForfaitUI(forf, isChecked) {
        var row = forf.closest('tr');
        if (!row) return;
        row.querySelectorAll('.psc-check').forEach(function (c) {
            if (c === forf) return;
            if (isChecked) {
                c.checked = false;
                c.disabled = true;
            } else if (!row.classList.contains('psc-row-locked')) {
                c.disabled = false;
            }
        });
    }

    function onToggle(cb) {
        cb.disabled = true;

        var isForf = cb.dataset.service === 'FORF';
        var willBeChecked = cb.checked;
        var monthBlock = cb.closest('.psc-month-block');
        var childBlock = cb.closest('.psc-portal-child-block');
        var table = cb.closest('table');

        // Pour FORF : appliquer le changement d'UI immédiatement, sans attendre l'AJAX.
        // On collecte d'abord les cases cochées à décocher côté serveur.
        var siblingsToUncheck = [];
        if (isForf) {
            if (willBeChecked) {
                var row = cb.closest('tr');
                if (row) {
                    row.querySelectorAll('.psc-check').forEach(function (c) {
                        if (c !== cb && c.checked && !c.disabled) siblingsToUncheck.push(c);
                    });
                }
            }
            applyForfaitUI(cb, willBeChecked);
        }

        if (monthBlock) recomputeMonthSummary(monthBlock);
        if (childBlock) recomputeChildTotal(childBlock);
        if (table) recomputeToutButtonsIn(table);

        post({
            action: 'psc_toggle',
            child_id: cb.dataset.child,
            date: cb.dataset.date,
            service: cb.dataset.service,
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

                if (isForf && willBeChecked) {
                    siblingsToUncheck.forEach(function (sib) {
                        post({
                            action: 'psc_toggle',
                            child_id: sib.dataset.child,
                            date: sib.dataset.date,
                            service: sib.dataset.service,
                            checked: '0'
                        });
                    });
                }
                return;
            }

            // Erreur : annuler le changement d'UI
            cb.checked = !willBeChecked;
            if (isForf) applyForfaitUI(cb, !willBeChecked);
            if (monthBlock) recomputeMonthSummary(monthBlock);
            if (childBlock) recomputeChildTotal(childBlock);
            if (table) recomputeToutButtonsIn(table);

            var code = res && res.data && res.data.code;

            // Si le serveur signale un verrou ou une assurance manquante, la
            // case reste désactivée : la situation a pu changer pendant que
            // la page était ouverte (délai expiré, ou document retiré).
            if (code === 'locked' || code === 'assurance_missing' || code === 'service_closed') {
                cb.closest('tr').classList.add('psc-row-locked');
            } else {
                cb.disabled = false;
            }
            cellNotice(cb, messageFor(res, 'generic'));
        }).catch(function () {
            cb.disabled = false;
            cb.checked = !willBeChecked;
            if (isForf) applyForfaitUI(cb, !willBeChecked);
            if (monthBlock) recomputeMonthSummary(monthBlock);
            if (childBlock) recomputeChildTotal(childBlock);
            if (table) recomputeToutButtonsIn(table);
            cellNotice(cb, MESSAGES.network);
        });
    }

    /* ---------- Bouton de confirmation ---------- */

    function onConfirm(btn, feedback) {
        btn.disabled = true;
        var original = btn.textContent;
        btn.textContent = 'Envoi en cours...';
        feedback.textContent = '';
        feedback.className = 'psc-confirm-feedback';

        post({ action: 'psc_confirm' }).then(function (res) {
            btn.disabled = false;
            btn.textContent = original;

            if (res && res.success) {
                feedback.className = 'psc-confirm-feedback psc-ok-text';
                feedback.textContent = (res.data && res.data.message) || 'Récapitulatif envoyé.';
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

        // Écouteur FORF indépendant : applique l'UI immédiatement, avant toute logique AJAX.
        document.querySelectorAll('.psc-check[data-service="FORF"]').forEach(function (forf) {
            if (forf.checked) applyForfaitUI(forf, true);
            forf.addEventListener('change', function () {
                applyForfaitUI(forf, forf.checked);
            });
        });

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
    });
})();
