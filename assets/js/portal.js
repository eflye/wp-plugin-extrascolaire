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

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-portal-tab], [data-portal-tab-link]').forEach(function (link) {
            link.addEventListener('click', onTabLinkClick);
        });
        initAbsenceModal();
    });
})();
