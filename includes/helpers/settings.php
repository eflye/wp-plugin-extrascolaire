<?php
/**
 * Réglages : plafonds, destinataires, options d'exploitation.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Nombre maximum de jours qu'une plage de dates peut couvrir (fermeture de
 * jours, calcul des jours d'école d'une année). Garde-fou contre une saisie
 * erronée qui générerait des millions d'itérations.
 */
function psc_max_school_days() {
    return (int) apply_filters('psc_max_school_days', 400);
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

/**
 * Variante(s) de l'écran Planning exposée(s) aux familles.
 *
 * Deux variantes sont livrées en parallèle (Planning - 1 : saisie jour par
 * jour ; Planning - 2 : rythme + exceptions) pour que la mairie tranche sur
 * pièces. Le réglage (Réglages > Périscolaire) permet de retirer l'écran non
 * retenu sans redéploiement :
 *   'both' (défaut) → les deux onglets « Planning - 1 » et « Planning - 2 » ;
 *   '1' → un seul onglet « Planning » (saisie jour par jour) ;
 *   '2' → un seul onglet « Planning » (rythme + exceptions).
 *
 * Retour : tableau des slugs d'onglet exposés ('cantine', 'cantine2').
 */
function psc_planning_variants() {
    $setting = get_option('psc_planning_variant', 'both');
    if ($setting === '1') return array('cantine');
    if ($setting === '2') return array('cantine2');
    return array('cantine', 'cantine2');
}

/** Un seul écran Planning exposé ? (le libellé d'onglet redevient « Planning ») */
function psc_planning_single_variant() {
    return count(psc_planning_variants()) === 1;
}
