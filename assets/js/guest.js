(function () {
    'use strict';

    var MAX_CHILDREN = 5;

    // Chaînes traduites côté serveur (PSC.i18n, cf. Psc_Frontend::assets())
    // : t('cle', args…) remplace les %s dans l'ordre des arguments.
    function t(key) {
        var s = PSC.i18n[key] || '';
        for (var i = 1; i < arguments.length; i++) {
            s = s.replace('%s', arguments[i]);
        }
        return s;
    }

    function debounce(fn, ms) {
        var timer = null;
        return function () {
            var self = this, args = arguments;
            if (timer) clearTimeout(timer);
            timer = setTimeout(function () {
                timer = null;
                fn.apply(self, args);
            }, ms);
        };
    }

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
                '<div><label class="psc-portal-field-label screen-reader-text" for="psc-cp-' + idx + '">' + t('child_firstname', n) + '</label>' +
                '<input id="psc-cp-' + idx + '" class="psc-portal-field-underline" type="text" name="child_prenom_' + idx + '" placeholder="' + t('firstname') + '" maxlength="190" required></div>' +
                '<div><label class="psc-portal-field-label screen-reader-text" for="psc-cn-' + idx + '">' + t('child_lastname', n) + '</label>' +
                '<input id="psc-cn-' + idx + '" class="psc-portal-field-underline" type="text" name="child_nom_' + idx + '" placeholder="' + t('lastname') + '" maxlength="190" required></div>' +
                '<div><label class="psc-portal-field-label screen-reader-text" for="psc-cc-' + idx + '">' + t('child_class', n) + '</label>' +
                '<select id="psc-cc-' + idx + '" class="psc-portal-field-underline" name="child_classe_' + idx + '" required>' + classeOptionsHTML + '</select></div>' +
                '<div><label class="psc-portal-field-label screen-reader-text" for="psc-cb-' + idx + '">' + t('child_birthdate', n) + '</label>' +
                '<input id="psc-cb-' + idx + '" class="psc-portal-field-underline" type="date" name="child_naissance_' + idx + '" max="' + t('child_birthdate_max') + '" required></div>' +
                '<div><label class="psc-portal-field-label" for="psc-ca-' + idx + '">' + t('insurance') + '</label>' +
                '<input id="psc-ca-' + idx + '" type="file" name="child_assurance_' + idx + '" accept=".pdf,.jpg,.jpeg,.png" required></div>' +
                '<div class="psc-wizard-diet-cell"><div class="psc-portal-field-label">' + t('diet') + '</div>' +
                '<div class="psc-wizard-diet-group">' +
                '<label class="psc-wizard-diet-check"><input type="checkbox" name="child_sans_porc_' + idx + '" value="1"> ' + t('diet_pork') + '</label>' +
                '<label class="psc-wizard-diet-check"><input type="checkbox" name="child_vegan_' + idx + '" value="1"> ' + t('diet_meat') + '</label>' +
                '</div></div>' +
                '<div class="psc-wizard-pickup-block">' +
                '<p class="psc-wizard-pickup-title">' + t('pickup_title') + '</p>' +
                '<div class="psc-wizard-pickup-list" data-pickup-list></div>' +
                '<button type="button" class="psc-wizard-add-pickup-btn" data-testid="add-pickup-person-' + idx + '">' + t('pickup_add') + '</button>' +
                '</div>' +
                '<button type="button" class="psc-wizard-remove-btn" aria-label="' + t('child_remove') + '">' + t('remove') + '</button>';

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
                '<div><label class="psc-portal-field-label screen-reader-text" for="' + base + '-prenom">' + t('pickup_firstname') + '</label>' +
                '<input id="' + base + '-prenom" class="psc-portal-field-underline" type="text" name="child_pickup_prenom_' + childIdx + '_' + n + '" placeholder="' + t('firstname') + '" maxlength="191"></div>' +
                '<div><label class="psc-portal-field-label screen-reader-text" for="' + base + '-nom">' + t('pickup_lastname') + '</label>' +
                '<input id="' + base + '-nom" class="psc-portal-field-underline" type="text" name="child_pickup_nom_' + childIdx + '_' + n + '" placeholder="' + t('lastname') + '" maxlength="191"></div>' +
                '<div><label class="psc-portal-field-label screen-reader-text" for="' + base + '-tel">' + t('pickup_phone') + '</label>' +
                '<input id="' + base + '-tel" class="psc-portal-field-underline" type="tel" name="child_pickup_telephone_' + childIdx + '_' + n + '" placeholder="' + t('phone') + '" maxlength="40" pattern="(?:\\+33|0)[1-9](?:[ .-]?[0-9]{2}){4}" title="' + t('phone_pattern_title') + '"></div>' +
                '<div><label class="psc-portal-field-label screen-reader-text" for="' + base + '-lien">' + t('pickup_link') + '</label>' +
                '<input id="' + base + '-lien" class="psc-portal-field-underline" type="text" name="child_pickup_lien_' + childIdx + '_' + n + '" placeholder="' + t('link_placeholder') + '" maxlength="100" list="psc-pickup-lien-suggestions"></div>' +
                '<label class="psc-wizard-diet-check"><input type="checkbox" name="child_pickup_piece_identite_' + childIdx + '_' + n + '" value="1"> ' + t('pickup_id_check') + '</label>' +
                '<button type="button" class="psc-wizard-remove-pickup-btn" aria-label="' + t('pickup_remove') + '">' + t('remove') + '</button>';

            pickupList.appendChild(row);
            wirePickupRemove(row, childRow);
            updateAddPickupBtn(childRow);
            row.querySelector('input[type="text"]').focus();
        });
    }

    /* ---------- Autocomplétion d'adresse (Base Adresse Nationale) ---------- */

    // L'API BAN est appelée directement par le navigateur de la famille :
    // rien ne transite par le serveur WordPress (aucune adresse ne lui est
    // envoyée, aucune clé, quota public). Sans JS, le bloc ci-dessous
    // reste invisible et le trio adresse / code postal / ville rendu par
    // le serveur reste visible et requis : le formulaire historique est
    // intact — règle de dégradation du wizard.
    function initAddressAutocomplete() {
        var wrap       = document.querySelector('.psc-address-wrap');
        var search     = document.getElementById('psc-req-adresse-search');
        var list       = document.getElementById('psc-address-listbox');
        var toggle     = document.getElementById('psc-address-toggle');
        var label      = document.getElementById('psc-req-adresse-label');
        var adresse    = document.getElementById('psc-req-adresse');
        var cp         = document.getElementById('psc-req-cp');
        var ville      = document.getElementById('psc-req-ville');
        if (!wrap || !search || !list || !toggle || !label || !adresse || !cp || !ville) return;
        var status     = wrap.querySelector('.psc-address-status');
        var attribution = wrap.querySelector('.psc-address-attribution');

        var ADDRESS_MIN_CHARS  = 3;
        var ADDRESS_DEBOUNCE_MS = 250;
        var ADDRESS_API = 'https://api-adresse.data.gouv.fr/search/';

        var lastLabel = '';  // Dernier libellé sélectionné : retaper autre chose vide le trio.
        var options   = [];  // Résultats courants (propriétés BAN filtrées).
        var activeIdx = -1;
        var aborter   = null;
        var mode      = 'auto';

        function closeList() {
            list.hidden = true;
            list.innerHTML = '';
            options = [];
            activeIdx = -1;
            search.removeAttribute('aria-activedescendant');
            search.setAttribute('aria-expanded', 'false');
        }

        function setActive(i) {
            if (!options.length) return;
            activeIdx = (i + options.length) % options.length;
            Array.prototype.forEach.call(list.children, function (li, k) {
                li.classList.toggle('is-active', k === activeIdx);
                if (k === activeIdx) li.setAttribute('aria-selected', 'true');
                else li.removeAttribute('aria-selected');
            });
            search.setAttribute('aria-activedescendant', list.children[activeIdx].id);
        }

        function refreshValidity() {
            // La validité de l'adresse ne dépend pas du champ de recherche
            // lui-même mais du trio : tant que adresse/cp/ville sont vides,
            // aucune adresse n'a été retenue. Jamais d'attribut required
            // natif sur la recherche — après une erreur serveur le wizard
            // rouvre sur une étape avancée et un champ requis invisible
            // d'une autre étape déadlockerait la soumission en silence.
            if (mode !== 'auto') { search.setCustomValidity(''); return; }
            search.setCustomValidity(adresse.value.trim() ? '' : t('address_pick_required'));
        }

        function pick(props) {
            adresse.value = props.name || props.label;
            cp.value     = props.postcode;
            ville.value  = props.city;
            lastLabel    = props.label;
            search.value = props.label;
            status.textContent = '';
            refreshValidity();
            closeList();
        }

        function selectActive() {
            if (!options.length) return;
            pick(options[activeIdx >= 0 ? activeIdx : 0]);
        }

        var runSearch = debounce(function () {
            var q = search.value.trim();
            if (q.length < ADDRESS_MIN_CHARS) {
                if (aborter) aborter.abort();
                closeList();
                status.textContent = '';
                return;
            }

            if (aborter) aborter.abort();
            aborter = new AbortController();
            fetch(ADDRESS_API + '?q=' + encodeURIComponent(q) + '&limit=5', { signal: aborter.signal })
                .then(function (r) {
                    if (!r.ok) throw new Error('BAN http ' + r.status);
                    return r.json();
                })
                .then(function (data) {
                    // Données externes : on ne conserve que les propriétés
                    // utiles et le rendu passe par createElement/textContent
                    // (jamais innerHTML) — cf. gestion des données non
                    // approuvées.
                    options = (data.features || []).filter(function (f) {
                        var p = f && f.properties;
                        return p && p.postcode && p.city;
                    }).slice(0, 5).map(function (f) { return f.properties; });

                    list.innerHTML = '';
                    activeIdx = -1;
                    options.forEach(function (p, i) {
                        var li = document.createElement('li');
                        li.className = 'psc-address-option';
                        li.id = 'psc-address-opt-' + i;
                        li.setAttribute('role', 'option');
                        li.setAttribute('data-testid', 'address-option');
                        li.textContent = p.label;
                        li.addEventListener('mousedown', function (e) {
                            // mousedown : sélection avant le blur du champ
                            // (preventDefault empêche le transfert de focus).
                            e.preventDefault();
                            pick(p);
                            search.focus();
                        });
                        list.appendChild(li);
                    });

                    if (options.length) {
                        list.hidden = false;
                        search.setAttribute('aria-expanded', 'true');
                    } else {
                        closeList();
                        status.textContent = t('address_no_result');
                    }
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') return;
                    closeList();
                    status.textContent = t('address_service_error');
                });
        }, ADDRESS_DEBOUNCE_MS);

        search.addEventListener('input', function () {
            if (search.value !== lastLabel) {
                // Retape autre chose que la sélection : le trio n'est plus
                // garanti exact, on le vide (refreshValidity ré-arme ensuite
                // l'exigence de choix, cf. ci-dessous).
                adresse.value = '';
                cp.value = '';
                ville.value = '';
            }
            refreshValidity();
            runSearch();
        });

        search.addEventListener('keydown', function (e) {
            var open = !list.hidden && options.length > 0;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (!open) return;
                e.preventDefault();
                setActive(e.key === 'ArrowDown'
                    ? (activeIdx < 0 ? 0 : activeIdx + 1)
                    : (activeIdx < 0 ? options.length - 1 : activeIdx - 1));
            } else if (e.key === 'Enter') {
                if (!open) return; // liste fermée : soumission normale
                e.preventDefault(); // liste ouverte : sélection, jamais d'envoi
                selectActive();
            } else if (e.key === 'Escape') {
                if (!list.hidden) {
                    closeList();
                    e.preventDefault();
                }
            } else if (e.key === 'Tab') {
                closeList();
            }
        });

        search.addEventListener('blur', function () { closeList(); });
        document.addEventListener('click', function (e) {
            if (!list.hidden && !wrap.contains(e.target)) closeList();
        });

        function setMode(next) {
            mode = next;
            var isAuto = mode === 'auto';

            wrap.hidden = !isAuto;
            toggle.hidden = false;

            // Bascule par type, pas par visibilité : type="hidden" sort le
            // champ de la validation de contraintes MAIS il reste soumis.
            // Retirer aussi required est indispensable — la soumission
            // finale valide tous les champs du formulaire et un champ
            // requis caché la déadlockerait.
            adresse.type = isAuto ? 'hidden' : 'text';
            adresse.required = !isAuto;
            cp.type = isAuto ? 'hidden' : 'text';
            cp.required = !isAuto;
            ville.type = isAuto ? 'hidden' : 'text';
            ville.required = !isAuto;

            // Les libellés CP / ville restent dans leurs cellules de la
            // grille : on masque les cellules entières.
            [cp, ville].forEach(function (input) {
                var cell = input.closest('div');
                if (cell) cell.hidden = isAuto;
            });

            if (isAuto) {
                label.setAttribute('for', 'psc-req-adresse-search');
                search.setAttribute('placeholder', t('address_search_placeholder'));
                if (attribution) attribution.textContent = t('address_attribution');
                toggle.textContent = t('address_toggle_manual');
            } else {
                label.setAttribute('for', 'psc-req-adresse');
                closeList();
                if (status) status.textContent = '';
                toggle.textContent = t('address_toggle_search');
            }
            refreshValidity();
        }

        toggle.addEventListener('click', function () {
            setMode(mode === 'auto' ? 'manual' : 'auto');
            var target = mode === 'auto' ? search : adresse;
            if (target && !target.hidden) target.focus();
        });

        setMode('auto');
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
        initAddressAutocomplete();
        initPaymentCards();
        initChildren();
        initPickupPersons();
        initSecondParent();
    });
})();
