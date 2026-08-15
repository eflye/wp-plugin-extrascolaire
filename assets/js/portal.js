(function () {
    'use strict';

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

        // Une case de "Cantine & Garderie" a réellement changé côté serveur
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

    /* ---------- Popin "Annulation / signalement d'absence" ---------- */

    function initAbsenceModal() {
        var trigger = document.getElementById('psc-absence-trigger');
        var overlay = document.getElementById('psc-absence-modal');
        if (!trigger || !overlay) return;

        function open() { overlay.hidden = false; }
        function close() { overlay.hidden = true; }

        trigger.addEventListener('click', open);
        overlay.querySelectorAll('[data-absence-close]').forEach(function (btn) {
            btn.addEventListener('click', close);
        });
        // Clic sur le fond (pas sur le contenu de la popin) : ferme aussi.
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !overlay.hidden) close();
        });

        // Liste des jours annulables dépend de l'enfant choisi.
        var childSelect = document.getElementById('psc-absence-child');
        var dateSelect  = document.getElementById('psc-absence-date');
        var dataEl      = document.getElementById('psc-absence-data');
        if (!childSelect || !dateSelect || !dataEl) return;

        var data;
        try {
            data = JSON.parse(dataEl.textContent);
        } catch (err) {
            return;
        }

        function fillDates() {
            var days = (data[childSelect.value] && data[childSelect.value].days) || [];
            dateSelect.innerHTML = '';
            days.forEach(function (d) {
                var opt = document.createElement('option');
                opt.value = d.date;
                opt.textContent = d.label;
                dateSelect.appendChild(opt);
            });
        }

        childSelect.addEventListener('change', fillDates);
        fillDates();
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

        function open(childId) {
            var c = data[childId];
            if (!c) return;
            idField.value = childId;
            prenomField.value = c.prenom || '';
            nomField.value = c.nom || '';
            naissanceField.value = c.naissance || '';
            overlay.hidden = false;
        }
        function close() { overlay.hidden = true; }

        document.querySelectorAll('[data-child-edit-trigger]').forEach(function (btn) {
            btn.addEventListener('click', function () { open(btn.dataset.childId); });
        });
        overlay.querySelectorAll('[data-child-edit-close]').forEach(function (btn) {
            btn.addEventListener('click', close);
        });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !overlay.hidden) close();
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
            overlay.hidden = false;
        }
        function close() { overlay.hidden = true; }

        document.querySelectorAll('[data-assurance-upload-trigger]').forEach(function (btn) {
            btn.addEventListener('click', function () { open(btn.dataset.childId); });
        });
        overlay.querySelectorAll('[data-assurance-upload-close]').forEach(function (btn) {
            btn.addEventListener('click', close);
        });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !overlay.hidden) close();
        });

        // Le nom natif ("Aucun fichier choisi") reste masqué en permanence :
        // on n'affiche un nom de fichier qu'une fois qu'un fichier est
        // réellement choisi.
        fileInput.addEventListener('change', function () {
            fileName.textContent = fileInput.files && fileInput.files[0] ? fileInput.files[0].name : '';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-portal-tab], [data-portal-tab-link]').forEach(function (link) {
            link.addEventListener('click', onTabLinkClick);
        });
        initAbsenceModal();
        initChildEditModal();
        initAssuranceUploadModal();
    });
})();
