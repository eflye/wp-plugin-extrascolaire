<?php
/**
 * Plugin Name: Périscolaire — pas d'appel aux serveurs de WordPress (dev/CI)
 * Description: Environnements de développement et d'intégration continue
 *              uniquement (dossier mu-plugins/, jamais déployé). Les
 *              vérifications de mises à jour de WordPress, des extensions
 *              et des thèmes interrogent wordpress.org au premier écran
 *              d'administration : sur un réseau de CI lent, ces appels
 *              successifs (3 s de délai chacun) retardaient la page
 *              d'arrivée après connexion au-delà du délai des tests
 *              Playwright. Ils sont coupés ici, et tout autre appel vers
 *              wordpress.org échoue immédiatement au lieu d'attendre.
 */

if (!defined('ABSPATH')) exit;

// Vérifications de mises à jour déclenchées à l'affichage de l'administration.
foreach (array('_maybe_update_core', '_maybe_update_plugins', '_maybe_update_themes') as $psc_hook) {
    remove_action('admin_init', $psc_hook);
}
remove_action('admin_init', '_wp_check_for_scheduled_update_checks');
remove_action('wp_version_check', 'wp_version_check');
remove_action('wp_update_plugins', 'wp_update_plugins');
remove_action('wp_update_themes', 'wp_update_themes');
remove_action('wp_maybe_auto_update', 'wp_maybe_auto_update');
add_filter('automatic_updater_disabled', '__return_true');
unset($psc_hook);

// Filet : tout appel restant vers les serveurs de WordPress échoue tout de
// suite (widget d'actualités, traductions, catalogue d'extensions…).
add_filter('pre_http_request', function ($pre, $args, $url) {
    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    if (preg_match('/(^|\.)(wordpress\.org|w\.org)$/', $host)) {
        return new WP_Error('psc_offline_dev', 'Appel à wordpress.org coupé en développement / CI.');
    }
    return $pre;
}, 10, 3);
