<?php
/** wp eval-file tests/integration/menu-origin.php — transaction annulée, e-mails interceptés. */
if (!defined('WP_CLI') || !WP_CLI) return;
global $wpdb;
$checks = 0;
$assert = function ($condition, $message) use (&$checks) {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$captured = '';
$intercept = function ($return, $atts) use (&$captured) { $captured = $atts['message']; return true; };
add_filter('pre_wp_mail', $intercept, 10, 2);
$wpdb->query('START TRANSACTION');
try {
    $year = Psc_School_Year::active();
    $dates = Psc_School_Year::school_days($year->date_start, $year->date_end);
    $week = psc_week_start($dates[0]);
    $days = array_fill_keys(Psc_Menus::JOURS, 'Menu de test');
    $origin = Psc_Menus::default_meat_origin();
    $assert(strpos($origin, 'Les viandes de bœuf') === 0, 'Texte prérempli');
    $id = Psc_Menus::save(0, $week, $days, $origin);
    $assert(!is_wp_error($id) && $id > 0, 'Sauvegarde');
    $menu = Psc_Menus::get($id);
    $assert(Psc_Menus::meat_origin($menu) === $origin, 'Persistance');
    Psc_Menus::save($id, $week, $days);
    $assert(Psc_Menus::meat_origin(Psc_Menus::get($id)) === $origin, 'Appel historique conserve le champ');

    // Régression : les menus créés avant l'ajout du champ ont NULL en base.
    $wpdb->query($wpdb->prepare('UPDATE '.psc_table('menus').' SET origine_viande = NULL WHERE id = %d', $id));
    $legacy_menu = Psc_Menus::get($id);
    $assert(Psc_Menus::meat_origin($legacy_menu) === $origin, 'Valeur par défaut pour un menu historique');
    $views = array(
        'portal-menu-block.php' => Psc_Frontend_Menus::portal_menu_data($week, home_url('/')),
        'guest-menu.php'        => Psc_Frontend_Menus::guest_menu_data($week, home_url('/')),
    );
    foreach ($views as $file => $nav) {
        $psc_portal_menu = $nav; $psc_guest_menu = $nav;
        ob_start(); include PSC_PATH.'templates/'.$file; $html = ob_get_clean();
        $assert(strpos($html, 'Origine de la viande') !== false, 'Titre '.$file);
        $assert(strpos($html, esc_html($origin)) !== false, 'Texte '.$file);
    }
    $menu = $legacy_menu;
    Psc_Mailer::send_weekly_menu((object) array('email'=>'menu-test@example.invalid'), $menu);
    $assert(strpos($captured, esc_html($origin)) !== false, 'E-mail contient la mention');
    Psc_Menus::save($id, $week, $days, '<b>France</b>');
    $assert(Psc_Menus::meat_origin(Psc_Menus::get($id)) === 'France', 'Nettoyage HTML');
    Psc_Menus::save($id, $week, $days, '');
    $menu = Psc_Menus::get($id);
    $assert(Psc_Menus::meat_origin($menu) === '', 'Vide conservé');
    Psc_Mailer::send_weekly_menu((object) array('email'=>'menu-test@example.invalid'), $menu);
    $assert(strpos($captured, 'Origine de la viande') === false, 'E-mail sans mention vide');
    WP_CLI::log($checks.' vérifications réussies ; transaction annulée, aucun e-mail envoyé.');
} finally {
    $wpdb->query('ROLLBACK');
    remove_filter('pre_wp_mail', $intercept, 10);
}
