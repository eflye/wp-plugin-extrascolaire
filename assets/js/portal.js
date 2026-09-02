(function () {
    'use strict';

    // Chaînes traduites côté serveur (PSC.i18n, cf. Psc_Frontend::assets())
    // : t('cle', args…) remplace les %s dans l'ordre des arguments.
    function t(key) {
        var s = PSC.i18n[key] || '';
        for (var i = 1; i < arguments.length; i++) {
            s = s.replace('%s', arguments[i]);
        }
        return s;
    }

    // Bascule d'onglets côté client : chaque lien (sidebar + accès rapide
    // du tableau de bord) reste un vrai <a href="?psc_tab=..."> — rendu
    // côté serveur avec la bonne section déjà visible (progressive
    // enhancement, cf. templates/frontend-portal.php). Ce script ne fait
    // qu'éviter le rechargement complet quand JS est disponible.
    function activateTab(tab) {
        document.querySelectorAll('[data-portal-section]').forEach(function (section) {
            section.classList.toggle('is-active', section.dataset.portalSection === tab);
        });
        document.querySelectorAll('[data-portal-tab]').forEach(function (link) {
            link.classList.toggle('is-active', link.dataset.portalTab === tab);
        });
    }

    function onTabLinkClick(e) {
        var link = e.currentTarget;
        var tab = link.dataset.portalTab || link.dataset.portalTabLink;
        if (!tab) return;

        // Une case du "Planning cantine & garderie" a réellement changé côté serveur
        // depuis le chargement de la page : les autres onglets déjà rendus
        // (ex : Tableau de bord, "X jours déclarés") sont maintenant
        // obsolètes. On laisse le lien naviguer normalement (rechargement
        // complet, données fraîches partout) plutôt que de juste basculer
        // l'affichage côté client — cf. assets/js/frontend.js:onToggle().
        if (window.PSC_DATA_STALE) return;

        e.preventDefault();
        activateTab(tab);

        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, '', link.getAttribute('href'));
        }
    }

    /* ---------- Menu de la semaine : navigation sans rechargement ---------- */

    function menuNotice(container, message) {
        var old = container.querySelector('.psc-error');
        if (old) old.remove();

        var p = document.createElement('p');
        p.className = 'psc-error';
        p.setAttribute('role', 'alert');
        p.setAttribute('data-testid', 'portal-menu-error');
        p.textContent = message;
        container.after(p);
        setTimeout(function () { p.remove(); }, 7000);
    }

    function initMenuNav() {
        var block = document.getElementById('psc-menu-block');
        if (!block || typeof PSC === 'undefined') return;

        function bindLinks() {
            block.querySelectorAll(
                '[data-testid="portal-menu-nav-prev"], [data-testid="portal-menu-nav-next"], [data-testid="portal-menu-today-link"]'
            ).forEach(function (link) {
                link.addEventListener('click', onNavClick);
            });
        }

        function onNavClick(e) {
            e.preventDefault();
            if (block.classList.contains('psc-menu-loading')) return; // requête déjà en cours

            var href = e.currentTarget.getAttribute('href');
            var semaine = '';
            try {
                semaine = new URL(href, window.location.href).searchParams.get('psc_semaine') || '';
            } catch (err) { /* href invalide : on retente quand même avec semaine vide */ }

            block.classList.add('psc-menu-loading');

            window.PscAjax.envelope(PSC.ajax_url, {
                action: 'psc_menu_week',
                nonce: PSC.nonce,
                parent_nonce: PSC.parent_nonce || '',
                semaine: semaine
            }).then(function (res) {
                block.classList.remove('psc-menu-loading');

                if (!res.success || typeof res.data.html !== 'string') {
                    // Une panne réseau se distingue d'un refus du serveur :
                    // seule la première vaut la peine d'être réessayée.
                    menuNotice(block, res.data.code === 'network'
                        ? PSC.i18n.network
                        : PSC.i18n.week_load_failed);
                    return;
                }

                block.innerHTML = res.data.html;
                bindLinks();
                if (window.history && window.history.pushState) {
                    window.history.pushState({}, '', href);
                }
            });
        }

        bindLinks();
    }

    /* ---------- Popin "Annulation prestations" ---------- */

    function initAbsenceModal() {
        var trigger = document.getElementById('psc-absence-trigger');
        var overlay = document.getElementById('psc-absence-modal');
        if (!trigger || !overlay) return;

        // La sémantique de dialogue (role="dialog", aria-modal, piège de
        // focus, Échap, restitution du focus) vit dans PscDialog — cf.
        // assets/js/psc-dialog.js. Idem pour les popins suivantes.
        function open() {
            window.PscDialog.open(overlay, { focus: '#psc-absence-child' });
        }
        function close() { window.PscDialog.close(overlay); }

        trigger.addEventListener('click', open);
        overlay.querySelectorAll('[data-absence-close]').forEach(function (btn) {
            btn.addEventListener('click', close);
        });
        // Clic sur le fond (pas sur le contenu de la popin) : ferme aussi.
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) close();
        });

        // Liste des prestations annulables dépend de l'enfant choisi.
        var childSelect = document.getElementById('psc-absence-child');
        var itemsList   = document.getElementById('psc-absence-items');
        var itemsError  = document.getElementById('psc-absence-items-error');
        var form        = overlay.querySelector('[data-testid="absence-form"]');
        var dataEl      = document.getElementById('psc-absence-data');
        if (!childSelect || !itemsList || !dataEl) return;

        var data;
        try {
            data = JSON.parse(dataEl.textContent);
        } catch (err) {
            return;
        }

        function fillItems() {
            var items = (data[childSelect.value] && data[childSelect.value].items) || [];
            itemsList.innerHTML = '';
            items.forEach(function (it) {
                var label = document.createElement('label');
                label.className = 'psc-absence-item';
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.name = 'items[]';
                cb.value = it.date + '|' + it.service;
                label.appendChild(cb);
                label.appendChild(document.createTextNode(it.label));
                itemsList.appendChild(label);
            });
            if (itemsError) itemsError.hidden = true;
        }

        childSelect.addEventListener('change', fillItems);
        fillItems();

        if (form && itemsError) {
            form.addEventListener('submit', function (e) {
                var checked = itemsList.querySelectorAll('input[type="checkbox"]:checked');
                itemsError.hidden = checked.length > 0;
                if (!checked.length) e.preventDefault();
            });
        }
    }

    /* ---------- Popin "Corriger les informations" (Mes enfants) ---------- */

    function initChildEditModal() {
        var overlay = document.getElementById('psc-child-edit-modal');
        var dataEl  = document.getElementById('psc-child-edit-data');
        if (!overlay || !dataEl) return;

        var data;
        try {
            data = JSON.parse(dataEl.textContent);
        } catch (err) {
            return;
        }

        var idField = document.getElementById('psc-child-edit-id');
        var prenomField = document.getElementById('psc-child-edit-prenom');
        var nomField = document.getElementById('psc-child-edit-nom');
        var naissanceField = document.getElementById('psc-child-edit-naissance');
        var allergiesField = document.getElementById('psc-child-edit-allergies');

        function open(childId) {
            var c = data[childId];
            if (!c) return;
            idField.value = childId;
            prenomField.value = c.prenom || '';
            nomField.value = c.nom || '';
            naissanceField.value = c.naissance || '';
            if (allergiesField) allergiesField.value = c.allergies || '';
            window.PscDialog.open(overlay, { focus: '#psc-child-edit-prenom' });
        }
        function close() { window.PscDialog.close(overlay); }

        document.querySelectorAll('[data-child-edit-trigger]').forEach(function (btn) {
            btn.addEventListener('click', function () { open(btn.dataset.childId); });
        });
        overlay.querySelectorAll('[data-child-edit-close]').forEach(function (btn) {
            btn.addEventListener('click', close);
        });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) close();
        });
    }

    /* ---------- Popin d'envoi du justificatif d'assurance (Mes enfants) ---------- */

    function initAssuranceUploadModal() {
        var overlay = document.getElementById('psc-assurance-upload-modal');
        var dataEl  = document.getElementById('psc-assurance-upload-data');
        if (!overlay || !dataEl) return;

        var data;
        try {
            data = JSON.parse(dataEl.textContent);
        } catch (err) {
            return;
        }

        var title     = document.getElementById('psc-assurance-upload-title');
        var idField   = document.getElementById('psc-assurance-upload-child-id');
        var fileInput = document.getElementById('psc-assurance-upload-file');
        var fileName  = overlay.querySelector('[data-psc-file-name]');

        function open(childId) {
            var c = data[childId];
            if (!c) return;
            idField.value = childId;
            title.textContent = c.label;
            fileInput.value = '';
            fileName.textContent = '';
            window.PscDialog.open(overlay, { focus: '#psc-assurance-upload-file' });
        }
        function close() { window.PscDialog.close(overlay); }

        document.querySelectorAll('[data-assurance-upload-trigger]').forEach(function (btn) {
            btn.addEventListener('click', function () { open(btn.dataset.childId); });
        });
        overlay.querySelectorAll('[data-assurance-upload-close]').forEach(function (btn) {
            btn.addEventListener('click', close);
        });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) close();
        });

        // Le nom natif ("Aucun fichier choisi") reste masqué en permanence :
        // on n'affiche un nom de fichier qu'une fois qu'un fichier est
        // réellement choisi.
        fileInput.addEventListener('change', function () {
            fileName.textContent = fileInput.files && fileInput.files[0] ? fileInput.files[0].name : '';
        });
    }

    /* ---------- Popin "Personne autorisée à récupérer" (Habilitations) ---------- */

    function initPickupPersonModal() {
        var overlay = document.getElementById('psc-pickup-modal');
        var dataEl  = document.getElementById('psc-pickup-data');
        if (!overlay || !dataEl) return;

        var data;
        try {
            data = JSON.parse(dataEl.textContent);
        } catch (err) {
            return;
        }

        var title       = document.getElementById('psc-pickup-modal-title');
        var actionField = document.getElementById('psc-pickup-form-action');
        var childField  = document.getElementById('psc-pickup-child-id');
        var idField     = document.getElementById('psc-pickup-id');
        var prenomField = document.getElementById('psc-pickup-prenom');
        var nomField    = document.getElementById('psc-pickup-nom');
        var telField    = document.getElementById('psc-pickup-telephone');
        var lienField   = document.getElementById('psc-pickup-lien');
        var pieceField  = document.getElementById('psc-pickup-piece-identite');

        /* L'ajout vaut pour tous les enfants : l'endpoint foyer boucle sur
           toute la fratrie (psc_parent_add_household_pickup_person). */
        function openAddHousehold() {
            title.textContent = t('pickup_add_all_title');
            actionField.value = 'psc_parent_add_household_pickup_person';
            childField.value = '';
            idField.value = '';
            prenomField.value = '';
            nomField.value = '';
            telField.value = '';
            lienField.value = '';
            pieceField.checked = false;
            window.PscDialog.open(overlay, { focus: '#psc-pickup-prenom' });
        }

        function openEdit(pickupId) {
            var p = (data.persons || {})[pickupId];
            if (!p) return;
            title.textContent = t('pickup_edit_title', p.prenom + ' ' + p.nom);
            actionField.value = 'psc_parent_update_pickup_person';
            childField.value = p.child_id;
            idField.value = pickupId;
            prenomField.value = p.prenom || '';
            nomField.value = p.nom || '';
            telField.value = p.telephone || '';
            lienField.value = p.lien || '';
            pieceField.checked = !!p.piece_identite;
            window.PscDialog.open(overlay, { focus: '#psc-pickup-prenom' });
        }

        function close() { window.PscDialog.close(overlay); }

        document.querySelectorAll('[data-pickup-add-all-trigger]').forEach(function (btn) {
            btn.addEventListener('click', openAddHousehold);
        });
        document.querySelectorAll('[data-pickup-edit-trigger]').forEach(function (btn) {
            btn.addEventListener('click', function () { openEdit(btn.dataset.pickupId); });
        });
        overlay.querySelectorAll('[data-pickup-modal-close]').forEach(function (btn) {
            btn.addEventListener('click', close);
        });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) close();
        });
    }

    /* ---------- Bloc "ajout" repliable (Mon profil : second parent) ---------- */

    function initToggleAddBlock(addBtnId, blockId, cancelSelector) {
        var addBtn = document.getElementById(addBtnId);
        var block  = document.getElementById(blockId);
        if (!addBtn || !block) return;

        addBtn.addEventListener('click', function () {
            addBtn.hidden = true;
            block.hidden = false;
            var first = block.querySelector('input');
            if (first) first.focus();
        });

        if (cancelSelector) {
            var cancelBtn = block.querySelector(cancelSelector);
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function () {
                    block.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"]').forEach(function (f) { f.value = ''; });
                    block.hidden = true;
                    addBtn.hidden = false;
                });
            }
        }
    }

    /* ---------- Popin de découverte (première connexion) ---------- */

    function initOnboardingTour() {
        var overlay = document.getElementById('psc-onboarding-overlay');
        if (!overlay) return;

        var steps = Array.prototype.slice.call(overlay.querySelectorAll('.psc-onboarding-step'));
        var dots  = Array.prototype.slice.call(overlay.querySelectorAll('.psc-onboarding-dot'));
        var prevBtn = document.getElementById('psc-onboarding-prev');
        var nextBtn = document.getElementById('psc-onboarding-next');
        var skipBtn = document.getElementById('psc-onboarding-skip');
        var dismissForm = document.getElementById('psc-onboarding-dismiss-form');
        var current = 0;

        function render() {
            steps.forEach(function (step, i) { step.classList.toggle('is-active', i === current); });
            dots.forEach(function (dot, i) { dot.classList.toggle('is-active', i === current); });
            prevBtn.hidden = current === 0;
            nextBtn.textContent = current === steps.length - 1 ? PSC.i18n.finish : PSC.i18n.next;
        }

        prevBtn.addEventListener('click', function () {
            if (current > 0) { current--; render(); }
        });
        // Dernière étape : "Suivant" devient "Terminer" et soumet le
        // formulaire de fermeture (persiste onboarding_seen_at côté
        // serveur, cf. Psc_Frontend_Profil::handle_parent_dismiss_onboarding()) —
        // même formulaire que "Passer", qui saute directement à la fin
        // sans repasser par les étapes intermédiaires.
        nextBtn.addEventListener('click', function () {
            if (current < steps.length - 1) { current++; render(); }
            else { dismissForm.submit(); }
        });
        skipBtn.addEventListener('click', function () { dismissForm.submit(); });

        // Le tour est ouvert dès le chargement (première connexion) : la
        // sémantique de dialogue y est posée immédiatement. Échap vaut
        // "Passer" — seule fermeture possible, elle persiste le seen_at.
        window.PscDialog.open(overlay, {
            focus: '#psc-onboarding-next',
            onEscape: function () { dismissForm.submit(); }
        });

        render();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-portal-tab], [data-portal-tab-link]').forEach(function (link) {
            link.addEventListener('click', onTabLinkClick);
        });
        initAbsenceModal();
        initChildEditModal();
        initAssuranceUploadModal();
        initPickupPersonModal();
        initMenuNav();
        initToggleAddBlock('psc-add-second-parent', 'psc-second-parent-block');
        initOnboardingTour();
    });
})();
