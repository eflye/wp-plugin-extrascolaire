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
 * dédiée (pas manage_options) : elle est accordée par défaut aux
 * administrateurs ET aux éditeurs (cf. Psc_Installer::sync_roles()),
 * pour qu'un membre de la mairie puisse gérer le périscolaire sans avoir
 * les droits d'administration complète du site (thèmes, extensions,
 * réglages WordPress). Filtrable pour pointer vers une capacité
 * entièrement personnalisée si besoin.
 */
function psc_manage_cap() {
    return apply_filters('psc_manage_capability', 'psc_manage_periscolaire');
}

/**
 * Rôles WordPress auxquels psc_manage_cap() est accordée par défaut à
 * l'activation/mise à jour du plugin. Filtrable : retourner un tableau
 * vide désactive l'attribution automatique (utile si la capacité a été
 * personnalisée via psc_manage_capability et gérée à la main).
 */
function psc_manage_default_roles() {
    return apply_filters('psc_manage_default_roles', array('administrator', 'editor'));
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
