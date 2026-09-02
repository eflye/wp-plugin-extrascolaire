<?php
/**
 * Fonctions utilitaires, réparties par domaine.
 *
 * Ce fichier n'en contient plus aucune : il ne fait que charger les
 * fichiers ci-dessous. Il en réunissait soixante-seize, couvrant une
 * dizaine de sujets sans rapport — chiffrement, jours fériés, plafonds de
 * réglages, progression scolaire. Un fichier sans critère d'appartenance
 * attire tout ce qui ne trouve pas sa place ailleurs, et s'aggrave à
 * chaque évolution.
 *
 * Chaque fichier porte en tête le critère qui décide de ce qui y entre.
 * Une nouvelle fonction qui n'entre dans aucun mérite son propre fichier,
 * pas d'être ajoutée au moins mauvais.
 */

if (!defined('ABSPATH')) exit;

foreach (array(
    'core',
    'request',
    'dates',
    'school-calendar',
    'planning',
    'lock',
    'services',
    'banking',
    'crypto',
    'session',
    'throttle',
    'files',
    'settings',
    'admin-ui',
) as $psc_helper) {
    require_once PSC_PATH . 'includes/helpers/' . $psc_helper . '.php';
}
unset($psc_helper);
