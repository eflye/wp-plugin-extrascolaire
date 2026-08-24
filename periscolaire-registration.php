<?php
/**
 * Plugin Name: Périscolaire - Inscriptions
 * Description: Formulaire d'inscription en ligne aux services périscolaires (garderie matin, cantine, garderie soir, forfait) avec backoffice de centralisation pour la mairie. Remplace le fichier calendrier rempli à la main.
 * Version: 4.32.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Mairie
 * License: GPLv2 or later
 * Text Domain: periscolaire-registration
 */

// Empêche l'exécution directe du fichier via son URL.
if (!defined('ABSPATH')) exit;

define('PSC_VERSION', '4.32.0');
define('PSC_PATH', plugin_dir_path(__FILE__));
define('PSC_URL', plugin_dir_url(__FILE__));
define('PSC_FILE', __FILE__);

require_once PSC_PATH . 'includes/helpers.php';
require_once PSC_PATH . 'includes/class-psc-installer.php';
require_once PSC_PATH . 'includes/class-psc-mailer.php';
require_once PSC_PATH . 'includes/class-psc-assurances.php';
require_once PSC_PATH . 'includes/class-psc-parents.php';
require_once PSC_PATH . 'includes/class-psc-requests.php';
require_once PSC_PATH . 'includes/class-psc-email-templates.php';
// Administration : un socle commun, un noyau (menu, tableau de bord) et
// une classe par domaine métier. Psc_Admin::init() déclare les siennes.
require_once PSC_PATH . 'includes/class-psc-admin-base.php';
require_once PSC_PATH . 'includes/class-psc-admin.php';
require_once PSC_PATH . 'includes/class-psc-admin-trimestres.php';
require_once PSC_PATH . 'includes/class-psc-admin-school-years.php';
require_once PSC_PATH . 'includes/class-psc-admin-familles.php';
require_once PSC_PATH . 'includes/class-psc-admin-inscriptions.php';
require_once PSC_PATH . 'includes/class-psc-admin-cantine.php';
require_once PSC_PATH . 'includes/class-psc-admin-invoices.php';
require_once PSC_PATH . 'includes/class-psc-admin-config.php';
require_once PSC_PATH . 'includes/class-psc-admin-requests.php';
require_once PSC_PATH . 'includes/class-psc-invoices.php';
require_once PSC_PATH . 'includes/class-psc-sepa-mandate.php';
require_once PSC_PATH . 'includes/class-psc-menus.php';
require_once PSC_PATH . 'includes/class-psc-supplier-orders.php';
require_once PSC_PATH . 'includes/class-psc-school-calendar.php';
require_once PSC_PATH . 'includes/class-psc-admin-calendar-v2.php';
require_once PSC_PATH . 'includes/class-psc-trimestres.php';
require_once PSC_PATH . 'includes/class-psc-school-years.php';
require_once PSC_PATH . 'includes/class-psc-pickup-persons.php';
require_once PSC_PATH . 'includes/class-psc-frontend.php';
require_once PSC_PATH . 'includes/class-psc-sidscm.php';

register_activation_hook(__FILE__, function () {
    Psc_Installer::activate();
    Psc_Requests::schedule_cleanup();
});

register_deactivation_hook(__FILE__, function () {
    Psc_Requests::unschedule_cleanup();
});

add_action('plugins_loaded', function () {
    Psc_Installer::maybe_upgrade();
    Psc_Admin::init();
    Psc_Admin_Calendar_V2::init();
    Psc_Parents::init();
    Psc_Requests::init();
    Psc_Frontend::init();
    Psc_Sidscm::init();
});
