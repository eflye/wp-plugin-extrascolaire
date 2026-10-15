/**
 * Bascule de thème du portail famille : Encre (par défaut) / Clair.
 *
 * Le thème est porté par data-psc-theme="light" sur <html> (cf.
 * Psc_Theme_Toggle) : basculer revient à poser ou retirer cet attribut,
 * sans rechargement. La préférence est écrite à la fois dans le cookie
 * psc_theme, que le serveur lit pour rendre directement le bon thème (pas
 * de flash au chargement suivant), et dans localStorage, filet de
 * sécurité relu par le script en ligne de <head> si le cookie disparaît.
 *
 * Plusieurs boutons peuvent coexister (bandeau du thème, repli dans le
 * contenu) : tous sont tenus à jour. Bouton bascule : nom fixe « Thème
 * clair », aria-pressed vaut "true" quand le Clair est actif ; title
 * annonce l'action du prochain clic. Aucune animation ici : la seule transition, de couleur, est en CSS.
 */
(function () {
    'use strict';

    var ATTR = 'data-psc-theme';
    var COOKIE = 'psc_theme';
    var STORAGE_KEY = 'psc-theme';
    var ONE_YEAR = 31536000;

    var root = document.documentElement;
    var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-psc-theme-toggle]'));
    if (!buttons.length) return;

    function isLight() {
        return root.getAttribute(ATTR) === 'light';
    }

    function syncButtons() {
        var light = isLight();
        buttons.forEach(function (button) {
            // Nom accessible fixe (« Thème clair ») : seul aria-pressed
            // porte l'état. L'infobulle décrit le prochain clic.
            var title = light ? button.getAttribute('data-title-ink') : button.getAttribute('data-title-light');
            button.setAttribute('aria-pressed', light ? 'true' : 'false');
            if (title) button.setAttribute('title', title);
        });
    }

    function persist(value) {
        try {
            window.localStorage.setItem(STORAGE_KEY, value);
        } catch (e) {
            // Stockage indisponible (navigation privée, quota) : le cookie suffit.
        }
        document.cookie = COOKIE + '=' + value + '; path=/; max-age=' + ONE_YEAR + '; SameSite=Lax'
            + (window.location.protocol === 'https:' ? '; Secure' : '');
    }

    function toggle() {
        var next = isLight() ? 'ink' : 'light';
        if (next === 'light') {
            root.setAttribute(ATTR, 'light');
        } else {
            root.removeAttribute(ATTR);
        }
        persist(next);
        syncButtons();
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', toggle);
    });

    // L'attribut a pu être posé par le script anti-flash de <head> alors que
    // le serveur, sans cookie, avait rendu les boutons dans l'état Encre.
    syncButtons();
})();
