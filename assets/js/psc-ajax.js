/**
 * Appels AJAX WordPress — mécanique commune à tous les écrans.
 *
 * Chacun des quatre écrans portait son propre enrobage de fetch(), et ils
 * ne traitaient pas l'échec de la même façon : l'un absorbait une réponse
 * illisible en un code générique, un autre levait une exception, les deux
 * derniers se contentaient de res.json(). Un même incident réseau
 * produisait donc trois comportements selon la page.
 *
 * Deux garanties, ici, quelle que soit la panne :
 *
 *   - une réponse illisible (page d'erreur PHP, coupure en cours de
 *     transfert) devient un échec propre, jamais une exception de parsing
 *     remontée telle quelle ;
 *   - une panne réseau devient elle aussi un échec structuré, portant le
 *     code « network » — c'est le seul cas que l'appelant peut distinguer
 *     pour proposer de réessayer.
 *
 * Deux formes sont proposées parce que les écrans en attendent deux :
 * envelope() rend toujours { success, data } et ne rejette jamais ;
 * data() rend directement data et rejette si le serveur a refusé. Les
 * unifier de force aurait imposé de réécrire tous les appelants sans rien
 * gagner — c'est la façon de signaler l'échec qui devait converger, pas
 * la façon d'appeler.
 */
(function () {
    'use strict';

    function send(url, params) {
        var body = new URLSearchParams();
        Object.keys(params || {}).forEach(function (k) {
            if (params[k] !== undefined && params[k] !== null) body.set(k, params[k]);
        });

        return fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            // Permet au serveur de reconnaître un appel AJAX ; deux des
            // quatre écrans l'omettaient.
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            return res.json().catch(function () {
                return { success: false, data: { code: 'generic' } };
            });
        }).catch(function () {
            return { success: false, data: { code: 'network' } };
        });
    }

    window.PscAjax = {

        /** Toujours { success, data }. Ne rejette jamais. */
        envelope: function (url, params) {
            return send(url, params).then(function (json) {
                if (!json || typeof json !== 'object') {
                    return { success: false, data: { code: 'generic' } };
                }
                if (!json.data) json.data = {};
                return json;
            });
        },

        /**
         * Rend data, ou rejette avec une Error portant l'enveloppe reçue
         * dans .data — forme attendue par les écrans qui enchaînent sur
         * un .catch().
         */
        data: function (url, params) {
            return this.envelope(url, params).then(function (json) {
                if (!json.success) {
                    var err = new Error('psc_ajax_failed');
                    err.data = json;
                    throw err;
                }
                return json.data;
            });
        }
    };
})();
