<?php
/**
 * Infrastructure WordPress : accès aux tables et droits d'accès.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Nom complet d'une table du plugin (avec préfixe WP).
 */
function psc_table($name) {
    global $wpdb;
    return $wpdb->prefix . 'psc_' . $name;
}

/**
 * Capacité requise pour accéder au backoffice périscolaire. Capacité
 * dédiée (pas manage_options), accordée par défaut aux seuls
 * administrateurs (cf. Psc_Installer::sync_roles()). Elle ouvre le
 * tableau de bord ; chaque écran exige en plus sa capacité métier
 * (psc_domain_capabilities()), qu'un agent de la mairie peut recevoir
 * sans droits d'administration du site. Filtrable pour pointer vers une
 * capacité entièrement personnalisée si besoin.
 */
function psc_manage_cap() {
    return apply_filters('psc_manage_capability', 'psc_manage_periscolaire');
}

/**
 * Clés des capacités métier, sans libellé : Psc_Installer::sync_roles()
 * s'exécute au chargement de l'extension, avant « init », trop tôt pour
 * charger les traductions qu'exige psc_domain_capabilities().
 */
function psc_domain_capability_keys() {
    return array('psc_manage_families', 'psc_manage_presence', 'psc_manage_billing', 'psc_manage_messages', 'psc_manage_config', 'psc_view_health', 'psc_view_audit');
}

/** Capacités métier indépendantes, cumulables sur un même utilisateur. */
function psc_domain_capabilities() {
    return array(
        'psc_manage_families'   => __('Familles, enfants et assurances', 'periscolaire-registration'),
        'psc_manage_presence'   => __('Planning, menus et présences', 'periscolaire-registration'),
        'psc_manage_billing'    => __('Facturation et prélèvements', 'periscolaire-registration'),
        'psc_manage_messages'   => __('Messages aux familles', 'periscolaire-registration'),
        'psc_manage_config'     => __('Configuration du service', 'periscolaire-registration'),
        'psc_view_health'       => __('Données de santé (allergies)', 'periscolaire-registration'),
        'psc_view_audit'        => __('Journal d’audit', 'periscolaire-registration'),
    );
}

/** Associe un nonce d’action ou un slug admin à sa capacité minimale. */
function psc_required_capability($context) {
    $context = sanitize_key((string) $context);
    if (strpos($context, 'audit') !== false) return 'psc_view_audit';
    if (strpos($context, 'invoice') !== false || strpos($context, 'factur') !== false || strpos($context, 'sepa') !== false) return 'psc_manage_billing';
    if (strpos($context, 'message') !== false || strpos($context, 'conversation') !== false) return 'psc_manage_messages';
    if (strpos($context, 'setting') !== false || strpos($context, 'email_template') !== false || strpos($context, 'calendar') !== false || strpos($context, 'school_year') !== false || strpos($context, 'config') !== false || strpos($context, 'tarif') !== false) return 'psc_manage_config';
    if (strpos($context, 'inscription') !== false || strpos($context, 'attendance') !== false || strpos($context, 'menu') !== false || strpos($context, 'supplier') !== false) return 'psc_manage_presence';
    if (strpos($context, 'parent') !== false || strpos($context, 'family') !== false || strpos($context, 'child') !== false || strpos($context, 'assurance') !== false || strpos($context, 'pickup') !== false || strpos($context, 'request') !== false || strpos($context, 'impersonate') !== false) return 'psc_manage_families';
    return psc_manage_cap();
}

/**
 * Rôles WordPress auxquels psc_manage_cap() est accordée par défaut à
 * l'activation/mise à jour du plugin. Filtrable : retourner un tableau
 * vide désactive l'attribution automatique (utile si la capacité a été
 * personnalisée via psc_manage_capability et gérée à la main).
 */
function psc_manage_default_roles() {
    return apply_filters('psc_manage_default_roles', array('administrator'));
}

/**
 * Les allergies sont une donnée de santé : elles ne s'affichent qu'aux
 * personnes titulaires de psc_view_health, pas à toute personne qui gère
 * les dossiers. Sans cette capacité, la cellule porte la même mention pour
 * chaque enfant — n'afficher un repère que pour les enfants concernés
 * révélerait déjà l'existence d'une allergie.
 */
function psc_user_can_view_health() {
    return current_user_can('psc_view_health');
}

/** Mention affichée à la place d'une donnée de santé non consultable. */
function psc_health_restricted_html() {
    return '<span class="description">' . esc_html__('Accès restreint', 'periscolaire-registration') . '</span>';
}

/**
 * Adresse du guide des familles (documentation en ligne), proposée dans
 * l'espace familles connecté comme non connecté. Filtrable si la commune
 * héberge sa propre copie de la documentation.
 */
function psc_family_guide_url() {
    return (string) apply_filters('psc_family_guide_url', 'https://eflye.github.io/wp-plugin-extrascolaire/familles/');
}

/**
 * Lien vers le guide des familles. Il s'ouvre dans un nouvel onglet pour ne
 * pas quitter l'espace familles (formulaire en cours) ; l'annonce en est
 * faite aux lecteurs d'écran.
 */
function psc_family_guide_link($class, $label, $testid) {
    printf(
        '<a class="%1$s" href="%2$s" target="_blank" rel="noopener" data-testid="%3$s">%4$s<span class="psc-sr-only"> %5$s</span></a>',
        esc_attr($class),
        esc_url(psc_family_guide_url()),
        esc_attr($testid),
        esc_html($label),
        esc_html__('(s’ouvre dans un nouvel onglet)', 'periscolaire-registration')
    );
}

function psc_user_can_manage() {
    return current_user_can(psc_manage_cap());
}

/**
 * Neutralise l'injection de formules CSV (Excel / LibreOffice).
 *
 * Une valeur commençant par = + - @ (ou tabulation / retour chariot) est
 * interprétée comme une formule à l'ouverture du fichier. Un nom d'enfant
 * saisi par un parent finit dans cet export : sans échappement, un parent
 * malveillant peut faire exécuter du code sur le poste de l'agent qui ouvre
 * le fichier. On préfixe par une apostrophe, qu'Excel traite comme
 * "forcer le format texte".
 */
function psc_csv_escape($value) {
    $value = (string) $value;
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
        return "'" . $value;
    }
    return $value;
}
