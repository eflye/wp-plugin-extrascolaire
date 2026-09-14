<?php
/** wp eval-file tests/integration/impersonation-retention.php */
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
$retention = function () { return 30; };
$table = psc_table('impersonations');
$now = current_time('timestamp');

$wpdb->query('START TRANSACTION');
add_filter('psc_impersonation_retention_days', $retention);
try {
    foreach (array('retention-cible', 'retention-temoin') as $slug) {
        $wpdb->insert(psc_table('parents'), array(
            'email'      => $slug . '@example.invalid',
            'nom'        => 'Famille ' . $slug,
            'prenom'     => 'Test',
            'active'     => 1,
            'created_at' => current_time('mysql'),
        ));
        if ($slug === 'retention-cible') {
            $family_id = (int) $wpdb->insert_id;
        } else {
            $other_family_id = (int) $wpdb->insert_id;
        }
    }

    $insert_impersonation = function ($family, $type, $started, $expires, $ended = null, $detail = null) use ($wpdb, $table, $admin) {
        $wpdb->insert($table, array(
            'wp_user_id'   => (int) $admin->ID,
            'family_id'    => $family,
            'motif_type'   => $type,
            'motif_detail' => $detail,
            'started_at'   => gmdate('Y-m-d H:i:s', $started),
            'expires_at'   => gmdate('Y-m-d H:i:s', $expires),
            'ended_at'     => $ended ? gmdate('Y-m-d H:i:s', $ended) : null,
            'ended_reason' => $ended ? 'manuel' : null,
            'ip'           => '192.0.2.42',
        ));
        return (int) $wpdb->insert_id;
    };

    $recent_id = $insert_impersonation($family_id, 'verification', $now - DAY_IN_SECONDS, $now - 23 * HOUR_IN_SECONDS, $now - 23 * HOUR_IN_SECONDS);
    $expired_id = $insert_impersonation($family_id, 'reclamation', $now - 2 * HOUR_IN_SECONDS, $now - HOUR_IN_SECONDS, null, 'CONFIDENTIEL-NE-JAMAIS-AFFICHER');
    $old_id = $insert_impersonation($family_id, 'autre', $now - 40 * DAY_IN_SECONDS, $now - 39 * DAY_IN_SECONDS, $now - 39 * DAY_IN_SECONDS);
    $outside_history_id = $insert_impersonation($family_id, 'verification', strtotime('-13 months', $now), strtotime('-13 months', $now) + HOUR_IN_SECONDS, strtotime('-13 months', $now) + HOUR_IN_SECONDS);
    $other_id = $insert_impersonation($other_family_id, 'verification', $now - DAY_IN_SECONDS, $now + HOUR_IN_SECONDS, $now - HOUR_IN_SECONDS);

    $history = Psc_Impersonation::history_for_family($family_id);
    $assert(count($history) === 3, 'L’historique famille ne respecte pas la fenêtre de douze mois.');
    $assert(!property_exists($history[0], 'wp_user_id'), 'L’identifiant de l’agent fuit dans l’historique famille.');
    $assert(!property_exists($history[0], 'motif_detail'), 'Le détail libre fuit dans l’historique famille.');
    $assert(Psc_Impersonation::family_motif_label('verification') === 'Vérification du dossier', 'Le motif générique est incorrect.');

    $parent = Psc_Parents::get_by_id($family_id);
    $psc_family_impersonations = $history;
    ob_start();
    include PSC_PATH . 'templates/portal-profil.php';
    $profile = ob_get_clean();
    $assert(strpos($profile, 'Consultations de votre espace par la mairie') !== false, 'La section de transparence manque dans Mon profil.');
    $assert(strpos($profile, 'La mairie') !== false, 'L’intervenant générique manque.');
    $assert(strpos($profile, '>' . esc_html($admin->display_name) . '<') === false, 'Le nom de l’agent est affiché à la famille.');
    $assert(strpos($profile, 'CONFIDENTIEL-NE-JAMAIS-AFFICHER') === false, 'Le détail libre est affiché à la famille.');

    $psc_family_impersonations = null;
    ob_start();
    include PSC_PATH . 'templates/portal-profil.php';
    $hidden_profile = ob_get_clean();
    $assert(strpos($hidden_profile, 'data-testid="profil-impersonation-history"') === false, 'La section reste visible quand le réglage est désactivé.');

    $cleanup = Psc_Impersonation::cleanup();
    $expired = $wpdb->get_row($wpdb->prepare("SELECT ended_at, ended_reason FROM $table WHERE id = %d", $expired_id));
    $assert($expired && $expired->ended_at !== null && $expired->ended_reason === 'expiration', 'La consultation expirée n’a pas été clôturée.');
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE id = %d", $recent_id)) === 1, 'Une consultation récente a été supprimée.');
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE id = %d", $old_id)) === 0, 'Le filtre de rétention n’a pas supprimé une ancienne consultation.');
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE id = %d", $outside_history_id)) === 0, 'Une consultation hors rétention subsiste.');
    $assert($cleanup['closed'] >= 1 && $cleanup['deleted'] >= 2, 'Le bilan de purge est incohérent.');

    Psc_Impersonation::delete_for_family($family_id);
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE family_id = %d", $family_id)) === 0, 'La purge explicite de la famille a échoué.');
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE id = %d", $other_id)) === 1, 'La purge a touché une autre famille.');
    $assert((bool) wp_next_scheduled('psc_cleanup_impersonations'), 'La tâche de purge quotidienne n’est pas planifiée.');

    WP_CLI::log(sprintf('OK : %d vérifications de transparence et de rétention.', $checks));
} finally {
    remove_filter('psc_impersonation_retention_days', $retention);
    $wpdb->query('ROLLBACK');
}
