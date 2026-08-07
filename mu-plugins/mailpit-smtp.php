<?php
/**
 * Redirige tous les wp_mail() vers Mailpit pour les tests locaux.
 * Activé uniquement si la variable d'environnement MAILPIT_ENABLED=true.
 *
 * Démarrage avec Mailpit :
 *   MAILPIT_ENABLED=true podman compose --profile mailpit up -d
 *
 * Démarrage sans Mailpit :
 *   podman compose up -d
 */
if (getenv('MAILPIT_ENABLED') !== 'true') {
    return;
}

add_filter('wp_mail_from',      fn() => 'wordpress@periscolaire.local');
add_filter('wp_mail_from_name', fn() => 'WordPress Local');

add_action('phpmailer_init', function ($phpmailer) {
    $phpmailer->isSMTP();
    $phpmailer->Host     = 'mailpit';
    $phpmailer->Port     = 1025;
    $phpmailer->SMTPAuth = false;
});
