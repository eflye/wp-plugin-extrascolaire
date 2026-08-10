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

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-portal-tab], [data-portal-tab-link]').forEach(function (link) {
            link.addEventListener('click', onTabLinkClick);
        });
    });
})();
