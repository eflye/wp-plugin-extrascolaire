<?php
/**
 * Fige « maintenant » pour l'extension périscolaire, sur demande du banc
 * de test. Inerte tant que l'option ci-dessous n'est pas renseignée.
 *
 * Ce dossier n'est monté que dans l'environnement de développement
 * (docker-compose.yml) et n'entre dans aucune archive de distribution :
 * même principe que mailpit-smtp.php à côté.
 *
 * Pourquoi figer le temps. Le délai de modification d'un jour se mesure
 * par rapport à l'instant présent. Un scénario qui vérifie qu'un jour est
 * verrouillé dépend donc de la date à laquelle on le lance : pendant les
 * vacances scolaires, le premier jour d'école est à plus d'une semaine
 * alors que le verrou n'en couvre que deux, et aucun jour ne peut être à
 * la fois ouvert et verrouillé. La suite échouait ainsi tous les ans à la
 * même période, puis redevenait verte d'elle-même.
 *
 * bin/seed-journey.php choisit un jour d'école comme point d'ancrage et
 * l'écrit dans l'option ; ce fichier fait en sorte que l'extension voie la
 * même heure que le peuplement. Sans cela, le peuplement désignerait comme
 * verrouillé un jour que le portail afficherait comme modifiable.
 *
 * L'option est relue à chaque appel, et non au chargement : le peuplement
 * la pose en cours de requête et doit voir l'effet immédiatement.
 */

if (!defined('ABSPATH')) exit;

const PSC_FROZEN_CLOCK_OPTION = 'psc_test_frozen_now';

add_filter('psc_now_ts', function ($now) {
    $frozen = (int) get_option(PSC_FROZEN_CLOCK_OPTION, 0);
    return $frozen > 0 ? $frozen : $now;
});

/**
 * Rappel visible dans l'administration : une horloge figée explique des
 * comportements autrement déroutants (un jour passé encore modifiable, un
 * jour lointain déjà verrouillé). Mieux vaut l'afficher que laisser
 * chercher.
 */
add_action('admin_notices', function () {
    $frozen = (int) get_option(PSC_FROZEN_CLOCK_OPTION, 0);
    if ($frozen <= 0) return;
    printf(
        '<div class="notice notice-warning"><p><strong>Horloge figée (tests)</strong> — l\'extension croit être le %s. '
        . 'Supprimer l\'option <code>%s</code> pour revenir à l\'heure réelle.</p></div>',
        esc_html(date_i18n('l j F Y à H:i', $frozen)),
        esc_html(PSC_FROZEN_CLOCK_OPTION)
    );
});
