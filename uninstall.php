<?php
/**
 * Désinstallation du plugin.
 *
 * Exécuté uniquement lorsque l'administrateur SUPPRIME le plugin
 * (pas lors d'une simple désactivation).
 *
 * RGPD : ce plugin stocke des données concernant des mineurs (nom, prénom,
 * classe, présences). La suppression du plugin doit donc effacer réellement
 * ces données, et non les laisser indéfiniment en base.
 *
 * Sécurité : la suppression est conditionnée à une constante explicite, pour
 * éviter une perte de données irréversible sur une désinstallation faite par
 * erreur. Pour effacer les données, ajouter dans wp-config.php :
 *     define('PSC_REMOVE_DATA_ON_UNINSTALL', true);
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

if (!defined('PSC_REMOVE_DATA_ON_UNINSTALL') || !PSC_REMOVE_DATA_ON_UNINSTALL) {
    return;
}

global $wpdb;

$tables = array(
    $wpdb->prefix . 'psc_registrations',
    $wpdb->prefix . 'psc_calendar_days',
    $wpdb->prefix . 'psc_children',
    $wpdb->prefix . 'psc_trimestres',
    $wpdb->prefix . 'psc_parents',
    $wpdb->prefix . 'psc_requests',
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS `$table`");
}

delete_option('psc_service_prices');
delete_option('psc_db_version');
delete_option('psc_lock_hours');
delete_option('psc_notify_mairie');
delete_option('psc_mairie_email');
delete_option('psc_form_page_id');
