(function () {
    'use strict';

    var MAX_CHILDREN = 5;

    /* ---------- Stepper "Première inscription" ---------- */

    function initWizard() {
        var wizard = document.getElementById('psc-wizard');
        if (!wizard) return;

        var steps   = Array.prototype.slice.call(wizard.querySelectorAll('.psc-wizard-step'));
        var circles = Array.prototype.slice.call(wizard.querySelectorAll('.psc-wizard-circle'));
        var lines   = Array.prototype.slice.call(wizard.querySelectorAll('.psc-wizard-line'));
        var labels  = Array.prototype.slice.call(wizard.querySelectorAll('.psc-wizard-step-label'));
        var prevBtn   = document.getElementById('psc-wizard-prev');
        var nextBtn   = document.getElementById('psc-wizard-next');
        var submitBtn = document.getElementById('psc-wizard-submit');

        var current = parseInt(wizard.dataset.defaultStep, 10) || 0;
        if (current < 0 || current >= steps.length) current = 0;

        function render() {
            steps.forEach(function (step, i) {
                step.classList.toggle('is-active', i === current);
            });
            circles.forEach(function (c, i) {
                c.classList.remove('is-current', 'is-done');
                if (i < current) c.classList.add('is-done');
                else if (i === current) c.classList.add('is-current');
            });
            lines.forEach(function (l, i) {
                l.classList.toggle('is-done', i < current);
            });
            labels.forEach(function (l, i) {
                l.classList.toggle('is-current', i === current);
            });
            var isLast = current === steps.length - 1;
            prevBtn.hidden = current === 0;
            nextBtn.hidden = isLast;
            submitBtn.hidden = !isLast;
        }

        // Ne bloque "Suivant"/un saut de cercle que si l'étape quittée est
        // réellement invalide — mêmes règles que le serveur rejetterait de
        // toute façon (email requis, IBAN/BIC requis seulement si SEPA
        // choisi, etc.), cf. handoff.
        function stepIsValid(index) {
            var fields = steps[index].querySelectorAll('input, select, textarea');
            for (var i = 0; i < fields.length; i++) {
                if (!fields[i].checkValidity()) {
                    fields[i].reportValidity();
                    return false;
                }
            }
            return true;
        }

        circles.forEach(function (c, i) {
            c.addEventListener('click', function () {
                if (i <= current) { current = i; render(); return; }
                for (var s = current; s < i; s++) {
                    if (!stepIsValid(s)) { current = s; render(); return; }
                }
                current = i;
                render();
            });
        });

        prevBtn.addEventListener('click', function () {
            if (current > 0) { current--; render(); }
        });
        nextBtn.addEventListener('click', function () {
            if (!stepIsValid(current)) return;
            if (current < steps.length - 1) { current++; render(); }
        });

        // Dégradation propre : sans JS, aucune étape n'est masquée (règle
        // CSS .psc-wizard[data-js-ready] .psc-wizard-step:not(.is-active))
        // et le formulaire reste utilisable d'une seule traite comme avant
        // cette refonte.
        wizard.setAttribute('data-js-ready', '1');
        render();

        // Une soumission ratée recharge toute la page (redirection serveur) :
        // le wizard rouvre sur la bonne étape avec le message d'erreur, mais
        // celui-ci reste invisible si la page charge tout en haut, très au-
        // dessus de ce bloc (widget menu + carte de connexion avant lui).
        if (wizard.dataset.hasError === '1') {
            wizard.scrollIntoView({ block: 'start' });
        }
    }

    /* ---------- Mode de paiement (remplace les radios par des cartes) ---------- */

    function initPaymentCards() {
        var autreCard = document.getElementById('psc-pm-autre-card');
        var sepaCard  = document.getElementById('psc-pm-prelevement-card');
        var modeInput = document.getElementById('psc-payment-mode-input');
        var sepaBlock = document.getElementById('psc-sepa-block');
        if (!autreCard || !sepaCard || !modeInput || !sepaBlock) return;

        var sepaRequiredIds = ['psc-sepa-iban', 'psc-sepa-bic', 'psc-sepa-titulaire', 'psc-sepa-reglement-cb'];

        function setMode(mode) {
            modeInput.value = mode;
            var isSepa = mode === 'prelevement';
            autreCard.classList.toggle('is-active', !isSepa);
            sepaCard.classList.toggle('is-active', isSepa);
            sepaBlock.hidden = !isSepa;
            sepaRequiredIds.forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.required = isSepa;
            });
        }

        autreCard.addEventListener('click', function () { setMode('autre'); });
        sepaCard.addEventListener('click', function () { setMode('prelevement'); });
        setMode(modeInput.value || 'autre');

        var sameAddress = document.getElementById('psc-sepa-same-address');
        if (sameAddress) {
            sameAddress.addEventListener('change', function () {
                if (!sameAddress.checked) return;
                document.getElementById('psc-sepa-adresse').value = document.getElementById('psc-req-adresse').value;
                document.getElementById('psc-sepa-cp').value       = document.getElementById('psc-req-cp').value;
                document.getElementById('psc-sepa-ville').value    = document.getElementById('psc-req-ville').value;
            });
        }
    }

    /* ---------- Ajout / suppression d'un enfant ---------- */

    function initChildren() {
        var list   = document.getElementById('psc-children-list');
        var addBtn = document.getElementById('psc-add-child');
        if (!list || !addBtn) return;

        var next = 1;
        var firstSelect = list.querySelector('select');
        var classeOptionsHTML = firstSelect ? firstSelect.innerHTML : '';

        function updateRemoveButtons() {
            var rows = list.querySelectorAll('.psc-wizard-child-row');
            rows.forEach(function (row) {
                var btn = row.querySelector('.psc-wizard-remove-btn');
                if (btn) btn.hidden = rows.length <= 1;
            });
        }

        function updateAddBtn() {
            addBtn.disabled = list.children.length >= MAX_CHILDREN;
        }

        function wireRemove(row) {
            var btn = row.querySelector('.psc-wizard-remove-btn');
            if (!btn) return;
            btn.addEventListener('click', function () {
                row.remove();
                updateRemoveButtons();
                updateAddBtn();
            });
        }

        list.querySelectorAll('.psc-wizard-child-row').forEach(wireRemove);
        updateRemoveButtons();
        updateAddBtn();

        addBtn.addEventListener('click', function () {
            if (list.children.length >= MAX_CHILDREN) return;

            var idx = next++;
            var n = list.children.length + 1;

            var row = document.createElement('div');
            row.className = 'psc-wizard-child-row';
            row.dataset.index = idx;
            row.innerHTML =
                '<div><label class="psc-portal-field-label screen-reader-text" for="psc-cp-' + idx + '">Prénom de l’enfant ' + n + '</label>' +
                '<input id="psc-cp-' + idx + '" class="psc-portal-field-underline" type="text" name="child_prenom_' + idx + '" placeholder="Prénom" maxlength="190"></div>' +
                '<div><label class="psc-portal-field-label screen-reader-text" for="psc-cn-' + idx + '">Nom de l’enfant ' + n + '</label>' +
                '<input id="psc-cn-' + idx + '" class="psc-portal-field-underline" type="text" name="child_nom_' + idx + '" placeholder="Nom" maxlength="190"></div>' +
                '<div><label class="psc-portal-field-label screen-reader-text" for="psc-cc-' + idx + '">Classe de l’enfant ' + n + '</label>' +
                '<select id="psc-cc-' + idx + '" class="psc-portal-field-underline" name="child_classe_' + idx + '">' + classeOptionsHTML + '</select></div>' +
                '<label class="psc-wizard-diet-check"><input type="checkbox" name="child_sans_porc_' + idx + '" value="1"> Sans porc</label>' +
                '<label class="psc-wizard-diet-check"><input type="checkbox" name="child_vegan_' + idx + '" value="1"> Sans viande</label>' +
                '<button type="button" class="psc-wizard-remove-btn" aria-label="Supprimer cet enfant">Retirer</button>';

            list.appendChild(row);
            wireRemove(row);
            updateRemoveButtons();
            updateAddBtn();
            row.querySelector('input[type="text"]').focus();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initWizard();
        initPaymentCards();
        initChildren();
    });
})();
