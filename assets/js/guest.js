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
                '<input id="psc-cp-' + idx + '" class="psc-portal-field-underline" type="text" name="child_prenom_' + idx + '" placeholder="Prénom" maxlength="190" required></div>' +
                '<div><label class="psc-portal-field-label screen-reader-text" for="psc-cn-' + idx + '">Nom de l’enfant ' + n + '</label>' +
                '<input id="psc-cn-' + idx + '" class="psc-portal-field-underline" type="text" name="child_nom_' + idx + '" placeholder="Nom" maxlength="190" required></div>' +
                '<div><label class="psc-portal-field-label screen-reader-text" for="psc-cc-' + idx + '">Classe de l’enfant ' + n + '</label>' +
                '<select id="psc-cc-' + idx + '" class="psc-portal-field-underline" name="child_classe_' + idx + '" required>' + classeOptionsHTML + '</select></div>' +
                '<div><label class="psc-portal-field-label screen-reader-text" for="psc-cb-' + idx + '">Date de naissance de l’enfant ' + n + '</label>' +
                '<input id="psc-cb-' + idx + '" class="psc-portal-field-underline" type="date" name="child_naissance_' + idx + '" required></div>' +
                '<div><label class="psc-portal-field-label" for="psc-ca-' + idx + '">Justificatif d’assurance scolaire</label>' +
                '<input id="psc-ca-' + idx + '" type="file" name="child_assurance_' + idx + '" accept=".pdf,.jpg,.jpeg,.png" required></div>' +
                '<div class="psc-wizard-diet-cell"><div class="psc-portal-field-label">Régime alimentaire</div>' +
                '<div class="psc-wizard-diet-group">' +
                '<label class="psc-wizard-diet-check"><input type="checkbox" name="child_sans_porc_' + idx + '" value="1"> Sans porc</label>' +
                '<label class="psc-wizard-diet-check"><input type="checkbox" name="child_vegan_' + idx + '" value="1"> Sans viande</label>' +
                '</div></div>' +
                '<div class="psc-wizard-pickup-block">' +
                '<p class="psc-wizard-pickup-title">Personnes autorisées à récupérer cet enfant en fin de garderie du soir (facultatif)</p>' +
                '<div class="psc-wizard-pickup-list" data-pickup-list></div>' +
                '<button type="button" class="psc-wizard-add-pickup-btn" data-testid="add-pickup-person-' + idx + '">+ Ajouter une personne autorisée</button>' +
                '</div>' +
                '<button type="button" class="psc-wizard-remove-btn" aria-label="Supprimer cet enfant">Retirer</button>';

            list.appendChild(row);
            wireRemove(row);
            updateRemoveButtons();
            updateAddBtn();
            row.querySelector('input[type="text"]').focus();
        });
    }

    /* ---------- Personnes autorisées à récupérer (sous-répéteur par enfant) ---------- */

    function initPickupPersons() {
        var list = document.getElementById('psc-children-list');
        if (!list) return;
        var MAX_PICKUP = 8; // cf. psc_max_pickup_persons_per_child() côté serveur, seule source d'autorité

        function personCount(pickupList) {
            return pickupList.querySelectorAll('.psc-wizard-pickup-row').length;
        }

        function updateAddPickupBtn(childRow) {
            var pickupList = childRow.querySelector('[data-pickup-list]');
            var addBtn = childRow.querySelector('.psc-wizard-add-pickup-btn');
            if (pickupList && addBtn) addBtn.disabled = personCount(pickupList) >= MAX_PICKUP;
        }

        function wirePickupRemove(row, childRow) {
            var btn = row.querySelector('.psc-wizard-remove-pickup-btn');
            if (!btn) return;
            btn.addEventListener('click', function () {
                row.remove();
                updateAddPickupBtn(childRow);
            });
        }

        // Câble les lignes déjà présentes au chargement (aucune par défaut,
        // la liste est facultative — robustesse si le serveur les réinjecte
        // après une erreur de validation ailleurs dans le formulaire).
        list.querySelectorAll('.psc-wizard-pickup-row').forEach(function (row) {
            var childRow = row.closest('.psc-wizard-child-row');
            if (childRow) wirePickupRemove(row, childRow);
        });

        // Délégation sur la liste plutôt qu'un câblage bouton par bouton :
        // le bouton "+ Ajouter une personne" existe aussi dans les lignes
        // enfant créées dynamiquement par initChildren().
        list.addEventListener('click', function (e) {
            var addBtn = e.target.closest('.psc-wizard-add-pickup-btn');
            if (!addBtn) return;
            var childRow = addBtn.closest('.psc-wizard-child-row');
            var pickupList = childRow.querySelector('[data-pickup-list]');
            var childIdx = childRow.dataset.index;
            var n = personCount(pickupList);
            if (n >= MAX_PICKUP) return;

            var row = document.createElement('div');
            row.className = 'psc-wizard-pickup-row';
            var base = 'psc-pp-' + childIdx + '-' + n;
            row.innerHTML =
                '<div><label class="psc-portal-field-label screen-reader-text" for="' + base + '-prenom">Prénom de la personne autorisée</label>' +
                '<input id="' + base + '-prenom" class="psc-portal-field-underline" type="text" name="child_pickup_prenom_' + childIdx + '_' + n + '" placeholder="Prénom" maxlength="191"></div>' +
                '<div><label class="psc-portal-field-label screen-reader-text" for="' + base + '-nom">Nom de la personne autorisée</label>' +
                '<input id="' + base + '-nom" class="psc-portal-field-underline" type="text" name="child_pickup_nom_' + childIdx + '_' + n + '" placeholder="Nom" maxlength="191"></div>' +
                '<div><label class="psc-portal-field-label screen-reader-text" for="' + base + '-tel">Téléphone de la personne autorisée</label>' +
                '<input id="' + base + '-tel" class="psc-portal-field-underline" type="tel" name="child_pickup_telephone_' + childIdx + '_' + n + '" placeholder="Téléphone" maxlength="40"></div>' +
                '<div><label class="psc-portal-field-label screen-reader-text" for="' + base + '-lien">Lien avec l’enfant</label>' +
                '<input id="' + base + '-lien" class="psc-portal-field-underline" type="text" name="child_pickup_lien_' + childIdx + '_' + n + '" placeholder="Lien (ex : Grand-parent)" maxlength="100" list="psc-pickup-lien-suggestions"></div>' +
                '<label class="psc-wizard-diet-check"><input type="checkbox" name="child_pickup_piece_identite_' + childIdx + '_' + n + '" value="1"> Présentera une pièce d’identité</label>' +
                '<button type="button" class="psc-wizard-remove-pickup-btn" aria-label="Retirer cette personne autorisée">Retirer</button>';

            pickupList.appendChild(row);
            wirePickupRemove(row, childRow);
            updateAddPickupBtn(childRow);
            row.querySelector('input[type="text"]').focus();
        });
    }

    /* ---------- Second parent (facultatif, étape "Coordonnées") ---------- */

    function initSecondParent() {
        var addBtn    = document.getElementById('psc-add-second-parent');
        var block     = document.getElementById('psc-second-parent-block');
        var removeBtn = document.getElementById('psc-remove-second-parent');
        if (!addBtn || !block || !removeBtn) return;

        var fields = block.querySelectorAll('input');

        addBtn.addEventListener('click', function () {
            addBtn.hidden = true;
            block.hidden = false;
            var first = block.querySelector('input');
            if (first) first.focus();
        });

        removeBtn.addEventListener('click', function () {
            fields.forEach(function (f) { f.value = ''; });
            block.hidden = true;
            addBtn.hidden = false;
            addBtn.focus();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initWizard();
        initPaymentCards();
        initChildren();
        initPickupPersons();
        initSecondParent();
    });
})();
