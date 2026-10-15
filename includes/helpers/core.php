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
 * Retire toutes les tâches planifiées du plugin, découvertes par leur
 * préfixe plutôt qu'énumérées à la main (une liste manuelle dérive).
 * wp_unschedule_hook() et non wp_clear_scheduled_hook() : ce dernier ne
 * retire que les événements sans argument, et laisserait en place les
 * envois ponctuels (psc_send_message_emails, psc_conversation_notify).
 */
function psc_unschedule_all_crons() {
    foreach ((array) _get_cron_array() as $events) {
        foreach (array_keys((array) $events) as $hook) {
            if (strpos((string) $hook, 'psc_') === 0) wp_unschedule_hook($hook);
        }
    }
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

/**
 * Table explicite action d'administration → capacité métier requise (PRA-02).
 *
 * Chaque action passée à Psc_Admin_Base::guard() figure ici, rattachée au
 * domaine de l'écran qui l'émet. Une action absente est refusée à tous
 * (do_not_allow) : ajouter une action impose de choisir son domaine, au
 * lieu d'hériter d'une capacité déduite de son nom.
 *
 * @return array<string,string>
 */
function psc_capability_map() {
    return array(
        // Familles, enfants (écrans Familles et Enfants).
        'psc_add_child'                     => 'psc_manage_families',
        'psc_delete_child'                  => 'psc_manage_families',
        'psc_delete_family'                 => 'psc_manage_families',
        'psc_mark_child_sorti'              => 'psc_manage_families',
        'psc_mark_child_actif'              => 'psc_manage_families',
        'psc_toggle_cantine_sans_repas'     => 'psc_manage_families',
        'psc_add_parent'                    => 'psc_manage_families',
        'psc_toggle_parent'                 => 'psc_manage_families',
        'psc_send_link'                     => 'psc_manage_families',
        'psc_edit_parent'                   => 'psc_manage_families',

        // Planning, menus, commande fournisseur et présences.
        'psc_save_menu'                     => 'psc_manage_presence',
        'psc_send_menu'                     => 'psc_manage_presence',
        'psc_retry_menu'                    => 'psc_manage_presence',
        'psc_delete_menu'                   => 'psc_manage_presence',
        'psc_send_supplier_order'           => 'psc_manage_presence',
        'psc_retry_supplier_order'          => 'psc_manage_presence',
        'psc_cancel_class_meals'            => 'psc_manage_presence',
        'psc_dismiss_cancel_class_meals'    => 'psc_manage_presence',
        'psc_admin_update_registrations'    => 'psc_manage_presence',
        // Adresse du fournisseur : destinataire des commandes, réglage du
        // service (capacité inchangée par rapport au mappage historique).
        'psc_save_supplier_settings'        => 'psc_manage_config',

        // Facturation et prélèvements (IBAN en clair dans les exports).
        'psc_payment_received'              => 'psc_manage_billing',
        'psc_download_general'              => 'psc_manage_billing',
        'psc_delete_invoices'               => 'psc_manage_billing',
        'psc_download_sepa'                 => 'psc_manage_billing',
        'psc_download_pain008'              => 'psc_manage_billing',
        'psc_generate_invoices'             => 'psc_manage_billing',
        'psc_send_invoice'                  => 'psc_manage_billing',
        'psc_send_all_invoices'             => 'psc_manage_billing',

        // Configuration : tarifs, réglages, modèles, maintenance.
        'psc_save_tarif'                    => 'psc_manage_config',
        'psc_delete_tarif'                  => 'psc_manage_config',
        'psc_save_settings'                 => 'psc_manage_config',
        'psc_save_email_templates'          => 'psc_manage_config',
        'psc_config_maintenance_sauvegarde' => 'psc_manage_config',
        'psc_config_maintenance_relancer'   => 'psc_manage_config',
        'psc_config_maintenance_rechiffrer' => 'psc_manage_config',
        'psc_config_maintenance_modeles'    => 'psc_manage_config',
        'psc_config_maintenance_recette'    => 'psc_manage_config',

        // Années scolaires, calendrier, vacances, passage d'année.
        'psc_add_school_year'               => 'psc_manage_config',
        'psc_activate_school_year'          => 'psc_manage_config',
        'psc_archive_school_year'           => 'psc_manage_config',
        'psc_update_school_year'            => 'psc_manage_config',
        'psc_delete_school_year'            => 'psc_manage_config',
        'psc_save_school_year_config'       => 'psc_manage_config',
        'psc_stage_promotion'               => 'psc_manage_config',
        'psc_confirm_promotion'             => 'psc_manage_config',
        'psc_cancel_promotion'              => 'psc_manage_config',
        'psc_add_school_holiday'            => 'psc_manage_config',
        'psc_remove_school_holiday'         => 'psc_manage_config',
        'psc_import_school_calendar'        => 'psc_manage_config',
        'psc_upload_school_calendar'        => 'psc_manage_config',
        'psc_close_school_day'              => 'psc_manage_config',
        'psc_cancel_school_day_close'       => 'psc_manage_config',
        'psc_open_school_day'               => 'psc_manage_config',
    );
}

/**
 * Capacité minimale exigée pour une action d'administration (nonce
 * d'action passé à Psc_Admin_Base::guard()). Refus par défaut : une action
 * inconnue renvoie 'do_not_allow', que current_user_can() refuse à tous,
 * administrateurs compris.
 *
 * @param string $action Nom d'action (identique au nonce).
 * @return string
 */
function psc_required_capability($action) {
    $map = psc_capability_map();
    $action = sanitize_key((string) $action);
    return isset($map[$action]) ? $map[$action] : 'do_not_allow';
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
