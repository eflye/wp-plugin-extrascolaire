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
        span.textContent = message;
        cell.appendChild(span);
        setTimeout(function () { span.remove(); }, 7000);
    }

    function onToggle(cb) {
        cb.disabled = true;

        post({
            action: 'psc_toggle',
            child_id: cb.dataset.child,
            date: cb.dataset.date,
            service: cb.dataset.service,
            checked: cb.checked ? '1' : '0'
        }).then(function (res) {
            if (res && res.success) {
                cb.disabled = false;
                var cell = cb.closest('td');
                cell.classList.add('psc-ok');
                setTimeout(function () { cell.classList.remove('psc-ok'); }, 700);
                return;
            }

            cb.checked = !cb.checked;
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
            cb.checked = !cb.checked;
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

    document.addEventListener('DOMContentLoaded', function () {
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
