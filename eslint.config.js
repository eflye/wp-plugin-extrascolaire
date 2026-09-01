/**
 * ESLint minimal — un filet, pas une police (audit 01).
 *
 * Le code de assets/ est du JavaScript navigateur assumé ES5 (var,
 * IIFE, pas de modules) : le filet ne demande pas une réécriture, il
 * attrape ce que PHPStan attrape côté PHP — symbole non défini,
 * variable jamais lue, comparaison accidentelle, réaffectation d'une
 * const — c'est-à-dire les erreurs qui échappent au simple lint
 * syntaxique et ne se voient qu'en console, chez l'utilisateur.
 *
 * Trois choix, à connaître avant d'élargir :
 *   - eqeqeq en mode « smart » : `s == null` est idiomatique et garde
 *     son sens (undefined ET null) ;
 *   - no-unused-vars ignore arguments et catch : les callbacks de
 *     signature imposée et les catch() vides sont la norme ici ;
 *   - les globales du navigateur sont listées à la main plutôt que par
 *     le paquet `globals` : une dépendance de plus ne se justifie pas
 *     pour une liste de dix lignes.
 *
 * Exécution : npm run lint:js (CI : job eslint de lint.yml). Ne
 * concerne que assets/js — les specs Playwright de tests/ ont leurs
 * propres garanties de type via TypeScript.
 */
const js = require('@eslint/js');

module.exports = [
    {
        files: ['assets/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2019,
            sourceType: 'script',
            globals: {
                document: 'readonly',
                window: 'readonly',
                history: 'readonly',
                location: 'readonly',
                navigator: 'readonly',
                console: 'readonly',
                alert: 'readonly',
                fetch: 'readonly',
                URL: 'readonly',
                URLSearchParams: 'readonly',
                XMLHttpRequest: 'readonly',
                localStorage: 'readonly',
                sessionStorage: 'readonly',
                // Built-ins asynchrones du langage.
                setTimeout: 'readonly',
                clearTimeout: 'readonly',
                setInterval: 'readonly',
                clearInterval: 'readonly',
                // Objets de configuration injectés par wp_localize_script()
                // au chargement des écrans (PSC_* est le préfixe de
                // l'extension) : le contrat entre l'enqueue PHP et le JS.
                PSC: 'readonly',
                PSC_SIDSCM: 'readonly',
                PSC_CAL_V2: 'readonly',
            },
        },
        rules: {
            ...js.configs.recommended.rules,
            'eqeqeq': ['error', 'smart'],
            'no-unused-vars': ['error', { args: 'none', caughtErrors: 'none' }],
        },
    },
];
