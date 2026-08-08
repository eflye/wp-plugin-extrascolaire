(function () {
    'use strict';

    var MESSAGES = {
        auth: 'Votre session a expiré. Rechargez la page pour demander un nouveau lien.',
        forbidden: "Vous n'êtes pas autorisé à modifier cette inscription.",
        notfound: 'Enfant introuvable. Merci de recharger la page.',
        day_closed: "Ce jour n'est pas ouvert aux inscriptions.",
        closed: "Aucune période d'inscription n'est ouverte actuellement.",
        locked: 'Le délai de modification est dépassé pour ce jour. Contactez la mairie.',
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
        var body = new URLSearchParams();
        body.append('nonce', PSC.nonce);
        Object.keys(payload).forEach(function (k) { body.append(k, payload[k]); });

        return fetch(PSC.ajax_url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.json().catch(function () {
                return { success: false, data: { code: 'generic' } };
            });
        });
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

            var code = res && res.data && res.data.code;

            // Si le serveur signale un verrou, la case reste désactivée :
            // le délai a pu expirer pendant que la page était ouverte.
            if (code === 'locked') {
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
    // seule après 3 s, et nettoie ?psc_msg de l'URL pour qu'un
    // rechargement de page ne la réaffiche pas.
    function initToast() {
        var toast = document.querySelector('.psc-toast');
        if (!toast) return;

        setTimeout(function () {
            toast.classList.add('psc-toast-hide');
            toast.addEventListener('animationend', function () {
                toast.remove();
            }, { once: true });
        }, 3000);

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

        var btn = document.getElementById('psc-confirm');
        var feedback = document.getElementById('psc-confirm-feedback');
        if (btn && feedback) {
            btn.addEventListener('click', function () { onConfirm(btn, feedback); });
        }
    });
})();
