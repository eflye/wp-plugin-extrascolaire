<?php
/**
 * Réglages : plafonds, destinataires, options d'exploitation.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Nombre maximum de jours qu'un trimestre peut couvrir.
 * Garde-fou contre une saisie erronée qui générerait des millions de lignes.
 */
function psc_max_trimestre_days() {
    return 400;
}

/**
 * Nombre maximum d'enfants qu'un même compte parent peut créer.
 * Empêche qu'un compte compromis ou un script ne remplisse la table.
 */
function psc_max_children_per_user() {
    return apply_filters('psc_max_children_per_user', 10);
}

/**
 * Nombre maximum de personnes autorisées à récupérer un même enfant
 * (liste courante, actives). Même esprit que psc_max_children_per_user().
 */
function psc_max_pickup_persons_per_child() {
    return apply_filters('psc_max_pickup_persons_per_child', 8);
}

/**
 * Suggestions de lien avec l'enfant pour la liste déroulante (<datalist>)
 * du champ "lien" — champ libre malgré tout : psc_pickup_lien_options()
 * n'est qu'une aide à la saisie, jamais une validation côté serveur.
 */
function psc_pickup_lien_suggestions() {
    return array(
        __('Grand-parent', 'periscolaire-registration'),
        __('Oncle / Tante', 'periscolaire-registration'),
        __('Voisin(e)', 'periscolaire-registration'),
        __('Nounou / Assistant(e) maternel(le)', 'periscolaire-registration'),
        __('Ami(e) de la famille', 'periscolaire-registration'),
        __('Autre', 'periscolaire-registration'),
    );
}

function psc_notify_mairie_enabled() {
    return (bool) get_option('psc_notify_mairie', 0);
}

/**
 * Auto-validation des demandes d'inscription : dès que la famille
 * confirme son adresse e-mail, elle accède directement à son espace,
 * sans relecture de la mairie. Désactivé par défaut — la modération
 * manuelle (Périscolaire > Demandes) reste le comportement standard.
 */
function psc_auto_approve_requests_enabled() {
    return (bool) apply_filters('psc_auto_approve_requests', get_option('psc_auto_approve_requests', 0));
}

function psc_mairie_email() {
    $mail = get_option('psc_mairie_email', '');
    if (!$mail || !is_email($mail)) {
        $mail = get_option('admin_email');
    }
    return $mail;
}
