<?php
/** wp eval-file tests/integration/impersonation-admin-screens.php */
if (!defined('WP_CLI') || !WP_CLI) return;

global $wpdb;
$checks = 0;
$assert = function ($condition, $message) use (&$checks) {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$admins = get_users(array('role' => 'administrator', 'number' => 1));
$assert(!empty($admins), 'Aucun administrateur disponible pour le test.');
$admin = $admins[0];
wp_set_current_user((int) $admin->ID);
$assert(current_user_can('psc_impersonate_family'), 'La capacité de consultation manque à l’administrateur.');

$wpdb->query('START TRANSACTION');
try {
    $wpdb->insert(psc_table('parents'), array(
        'email'      => 'ecran-consultation@example.invalid',
        'nom'        => 'Famille Écran',
        'active'     => 1,
        'created_at' => current_time('mysql'),
    ));
    $family_id = (int) $wpdb->insert_id;
    $wpdb->insert(psc_table('children'), array(
        'parent_id'  => $family_id,
        'nom'        => 'Écran',
        'prenom'     => 'Camille',
        'created_at' => current_time('mysql'),
    ));
    // Enfant actif = inscrit à l'année active (4.15.0).
    Psc_School_Years::enroll((int) $wpdb->insert_id, Psc_School_Years::active_id(), 'CP');
    $wpdb->insert(psc_table('impersonations'), array(
        'wp_user_id'  => (int) $admin->ID,
        'family_id'   => $family_id,
        'motif_type'  => 'verification',
        'started_at'  => gmdate('Y-m-d H:i:s', current_time('timestamp') - 10 * MINUTE_IN_SECONDS),
        'expires_at'  => gmdate('Y-m-d H:i:s', current_time('timestamp') + 20 * MINUTE_IN_SECONDS),
        'ended_at'    => current_time('mysql'),
        'ended_reason'=> 'manuel',
    ));

    $_GET = array('page' => 'psc_impersonate', 'family_id' => $family_id);
    ob_start();
    Psc_Admin_Familles::page_impersonate();
    $confirmation = ob_get_clean();
    $assert(strpos($confirmation, 'Famille Écran') !== false, 'Le nom de la famille manque sur la confirmation.');
    $assert(strpos($confirmation, '1 enfant') !== false, 'Le nombre d’enfants actifs manque.');
    $assert(strpos($confirmation, 'data-testid="impersonate-motif-other"') !== false, 'Le motif Autre manque.');
    $assert(strpos($confirmation, 'data-testid="impersonate-submit"') !== false, 'Le bouton d’ouverture manque.');

    $_GET = array('page' => 'psc_impersonate', 'family_id' => $family_id, 'psc_msg' => 'motif_detail_required');
    ob_start();
    Psc_Admin_Familles::page_impersonate();
    $invalid = ob_get_clean();
    $assert(strpos($invalid, 'aria-invalid="true"') !== false, 'Le champ détail invalide n’est pas annoncé.');
    $assert(strpos($invalid, 'psc-motif-detail-error') !== false, 'Le champ détail n’est pas relié à son erreur.');

    $_GET = array('page' => 'psc_parents', 'edit' => $family_id);
    ob_start();
    Psc_Admin_Familles::page_parents();
    $family_screen = ob_get_clean();
    $assert(strpos($family_screen, 'data-testid="impersonate-open-' . $family_id . '"') !== false, 'Le bouton Voir son espace manque.');
    $assert(strpos($family_screen, 'Les 20 dernières consultations') !== false, 'La légende accessible de l’historique manque.');
    $assert(strpos($family_screen, 'Vérification avant de répondre') !== false, 'Le motif manque dans l’historique.');
    $assert(strpos($family_screen, esc_html($admin->display_name)) !== false, 'L’agent manque dans l’historique.');

    WP_CLI::log(sprintf('OK : %d vérifications des écrans admin de consultation.', $checks));
} finally {
    $wpdb->query('ROLLBACK');
}
