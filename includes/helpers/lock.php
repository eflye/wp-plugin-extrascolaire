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
    // current_time('timestamp') ajoute le décalage du site à time() et ne
    // constitue donc pas un timestamp Unix réel. DateTime conserve le même
    // instant absolu quel que soit le fuseau configuré.
    $now = new DateTimeImmutable('now', wp_timezone());
    return (int) apply_filters('psc_now_ts', $now->getTimestamp());
}

/**
 * Délai minimal, en heures, avant le jour concerné, en deçà duquel un
 * parent ne peut plus modifier son planning. La valeur vit dans la
 * configuration de l'année scolaire (psc_school_year.lock_hours) — 48 h
 * par défaut, repli sur l'option historique psc_lock_hours si aucune
 * année n'est configurée.
 *
 * Le verrou s'applique aux DEUX écritures du modèle : une exception à
 * moins de 48 h est refusée côté serveur, et un changement de pattern ne
 * repropage jamais sur les jours déjà verrouillés (ils sont matérialisés
 * en exceptions figées à la sauvegarde du pattern).
 */
function psc_lock_hours() {
    static $cache = null;
    if ($cache === null) {
        $h = null;
        $year = class_exists('Psc_School_Year') ? Psc_School_Year::active() : null;
        if ($year && $year->lock_hours !== null) {
            $h = (int) $year->lock_hours;
        }
        if ($h === null) {
            $h = (int) get_option('psc_lock_hours', 48);
        }
        if ($h < 0) $h = 0;
        if ($h > 720) $h = 720; // 30 jours max
        $cache = $h;
    }
    // Filtrable comme psc_now_ts(), à chaque appel et non une seule fois :
    // bin/verify-lock-clock.php fait varier le délai dans un même processus.
    return max(0, (int) apply_filters('psc_lock_hours', $cache));
}

/**
 * Instant à partir duquel un jour donné n'est plus modifiable.
 * Le décompte part du début du jour de service (00:00), pas de l'heure
 * de la prestation : c'est plus simple à expliquer aux familles et cela
 * couvre la garderie du matin.
 *
 * Le délai se compte en heures réellement écoulées. Quand un changement
 * d'heure tombe dans l'intervalle, l'échéance n'est donc pas à minuit
 * (48 h avant le lundi qui suit le passage à l'heure d'hiver, c'est le
 * samedi à 01:00). L'arithmétique en heure légale donnerait minuit, mais
 * son comportement varie selon les versions de PHP ; psc_lock_message()
 * affiche toujours l'instant exact contrôlé ici.
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
    // wp_date() et non date_i18n() : ce dernier attend un timestamp déjà
    // décalé du fuseau du site, et affichait l'échéance une à deux heures
    // plus tôt que l'instant contrôlé par psc_is_locked().
    return sprintf(
        /* translators: %s: date et heure limites de modification. */
        __('Modifiable jusqu’au %s', 'periscolaire-registration'),
        wp_date(__('j F Y à H:i', 'periscolaire-registration'), $deadline)
    );
}
