<?php
/**
 * Délai de modification : jusqu'à quand une famille peut encore changer un jour.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Horodatage courant dans le fuseau du site.
 * On n'utilise pas time() directement : le serveur peut être en UTC alors
 * que la commune est en Europe/Paris, ce qui décalerait le verrouillage.
 *
 * Filtrable pour rendre l'horloge injectable. Le délai de modification se
 * mesure par rapport à « maintenant » : un test qui en dépend est donc à la
 * merci de la date d'exécution, et la suite échouait chaque été — pendant
 * les vacances, le premier jour d'école est à plus d'une semaine alors que
 * le verrou n'en couvre que deux. Figer cette fonction suffit à rendre le
 * verrouillage déterministe, puisque psc_is_locked() en dépend seule.
 *
 * En production rien ne s'abonne à ce filtre : le comportement est inchangé.
 */
function psc_now_ts() {
    return (int) apply_filters('psc_now_ts', (int) current_time('timestamp'));
}

/**
 * Délai minimal, en heures, avant le jour concerné, en deçà duquel un
 * parent ne peut plus modifier son planning. Par défaut 48 h.
 */
function psc_lock_hours() {
    $h = (int) get_option('psc_lock_hours', 48);
    if ($h < 0) $h = 0;
    if ($h > 720) $h = 720; // 30 jours max
    return $h;
}

/**
 * Instant à partir duquel un jour donné n'est plus modifiable.
 * Le décompte part du début du jour de service (00:00), pas de l'heure
 * de la prestation : c'est plus simple à expliquer aux familles et cela
 * couvre la garderie du matin.
 */
function psc_lock_deadline_ts($date_str) {
    $tz = wp_timezone();
    $day = new DateTime($date_str . ' 00:00:00', $tz);
    return $day->getTimestamp() - (psc_lock_hours() * HOUR_IN_SECONDS);
}

/**
 * Un jour est-il verrouillé pour les parents ?
 * La mairie n'est jamais concernée par ce verrou (elle doit pouvoir
 * corriger une erreur de dernière minute).
 */
function psc_is_locked($date_str) {
    if (psc_lock_hours() === 0) return false;
    return psc_now_ts() >= psc_lock_deadline_ts($date_str);
}

/**
 * Message lisible expliquant jusqu'à quand un jour reste modifiable.
 */
function psc_lock_message($date_str) {
    $deadline = psc_lock_deadline_ts($date_str);
    return sprintf(
        'Modifiable jusqu\'au %s',
        date_i18n('j F Y à H:i', $deadline)
    );
}
