/**
 * Sémantique de dialogue pour les popins du plugin — RGAA 7.1 / 7.3.
 *
 * Les popins étaient de simples blocs affichés/masqués : aucun rôle de
 * dialogue, aucun aria-modal, et le focus restait derrière la popin — un
 * lecteur d'écran continuait à lire la page masquée, et la navigation
 * clavier traversait le fond. Tout ce qu'une popin modale doit faire est
 * concentré ici ; chaque écran se contente de deux appels :
 *
 *   PscDialog.open(overlay, { focus: '#champ-initial', onEscape: fn });
 *   PscDialog.close(overlay);
 *
 * Le conteneur passé est l'élément affiché/masqué (l'overlay) ; la boîte
 * de dialogue elle-même porte role="dialog" aria-modal="true" dans le
 * markup, avec tabindex="-1" pour rester focalisable par repli. Tab est
 * piégé dans la popin, Échap la ferme (ou déclenche onEscape), et la
 * fermeture restitue le focus au déclencheur.
 */
(function () {
    'use strict';

    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), '
        + 'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    // offsetParent est null dès que l'élément (ou un ancêtre) n'est pas
    // rendu : filtre les champs d'une étape masquée d'assistant ou les
    // variantes d'un état, sans état à tenir à jour.
    function isRendered(el) {
        return el.offsetParent !== null;
    }

    function renderedFocusables(container) {
        return Array.prototype.filter.call(
            container.querySelectorAll(FOCUSABLE), isRendered
        );
    }

    function boxOf(container) {
        return container.querySelector('[role="dialog"]') || container;
    }

    function focusInitial(container, selector) {
        var target = selector ? container.querySelector(selector) : null;
        if (target && !isRendered(target)) target = null;
        if (!target) {
            var items = renderedFocusables(container);
            target = items.length ? items[0] : boxOf(container);
        }
        target.focus();
    }

    function onKeydown(e) {
        var container = e.currentTarget;
        if (e.key === 'Escape') {
            if (typeof container.__pscOnEscape === 'function') container.__pscOnEscape();
            else close(container);
            return;
        }
        if (e.key !== 'Tab') return;

        var items = renderedFocusables(container);
        if (!items.length) { e.preventDefault(); boxOf(container).focus(); return; }

        var first = items[0];
        var last  = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault(); last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault(); first.focus();
        } else if (!container.contains(document.activeElement)) {
            // Un focus égaré hors de la popin (champ rendu non focalisable,
            // élément retiré du DOM entre deux Tab) : on ramène au début.
            e.preventDefault(); first.focus();
        }
    }

    function open(container, options) {
        options = options || {};
        container.__pscOnEscape = options.onEscape || null;
        container.__pscReturnFocus = options.returnFocus || document.activeElement;
        container.hidden = false;
        container.addEventListener('keydown', onKeydown);
        focusInitial(container, options.focus);
    }

    function close(container) {
        container.hidden = true;
        container.removeEventListener('keydown', onKeydown);
        container.__pscOnEscape = null;

        var back = container.__pscReturnFocus;
        container.__pscReturnFocus = null;
        // body n'est pas un déclencheur : popin ouverte au chargement
        // (tour de découverte) — la fermeture navigue ou laisse la page.
        if (back && back !== document.body && document.contains(back)) {
            back.focus();
        }
    }

    window.PscDialog = { open: open, close: close };
})();
