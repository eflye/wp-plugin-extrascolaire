<?php
/**
 * Environnement minimal pour l'analyse statique (phpstan.neon) : les
 * fichiers d'includes/ gardent leur garde-fou d'exécution directe et
 * référencent quelques constantes de WordPress que les stubs de
 * szepeviktor/phpstan-wordpress ne fournissent pas. Aucun code de
 * l'extension ni de WordPress n'est chargé ici — seule l'analyse en a
 * besoin, jamais un site réel.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/wp/');
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('WP_ENVIRONMENT_TYPE')) {
    define('WP_ENVIRONMENT_TYPE', 'production');
}
// Constantes de l'extension, définies dans le fichier principal —
// includes/ les lit partout.
if (!defined('PSC_VERSION')) {
    define('PSC_VERSION', '0.0.0-analyse');
}
if (!defined('PSC_PATH')) {
    define('PSC_PATH', __DIR__ . '/');
}
if (!defined('PSC_URL')) {
    define('PSC_URL', 'http://analyse.invalid/wp-content/plugins/periscolaire-registration/');
}
// Cookie de WordPress, lu par la gestion de session.
if (!defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}
