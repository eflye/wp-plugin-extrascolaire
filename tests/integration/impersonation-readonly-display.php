<?php
/** wp eval-file tests/integration/impersonation-readonly-display.php */
if (!defined('WP_CLI') || !WP_CLI) return;

global $wpdb;
$checks = 0;
$assert = function ($condition, $message) use (&$checks) {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$legacy_exists = get_option('psc_legacy_usage_counts', null) !== null;
$legacy_before = get_option('psc_legacy_usage_counts', array());
$reflection = new ReflectionClass('Psc_Impersonation');
$cache = $reflection->getProperty('active_cache');
$cache_set = $reflection->getProperty('active_cache_set');
$cache->setAccessible(true);
$cache_set->setAccessible(true);

$wpdb->query('START TRANSACTION');
try {
    $wpdb->insert(psc_table('parents'), array(
        'email'      => 'consultation-readonly@example.invalid',
        'nom'        => 'Lecture seule',
        'active'     => 1,
        'created_at' => current_time('mysql'),
    ));
    $family_id = (int) $wpdb->insert_id;

    $wpdb->insert(psc_table('messages'), array(
        'titre'       => 'Message non lu',
        'corps'       => 'Ce message doit rester non lu.',
        'categorie'   => 'information',
        'statut'      => 'envoye',
        'cible_type'  => 'familles',
        'canaux'      => wp_json_encode(array('portail' => true, 'push' => true)),
        'date_envoi'  => current_time('mysql'),
        'auteur_id'   => 1,
        'created_at'  => current_time('mysql'),
        'updated_at'  => current_time('mysql'),
    ));
    $message_id = (int) $wpdb->insert_id;
    $wpdb->insert(psc_table('message_destinataires'), array(
        'message_id' => $message_id,
        'family_id'  => $family_id,
        'token'      => wp_generate_password(32, false, false),
    ));

    // Le cache positif évite de fabriquer un cookie signé et une session
    // WordPress : ce test cible uniquement les effets du rendu déjà reconnu
    // comme consultation valide.
    $cache->setValue(null, (object) array('id' => 999999, 'family_id' => $family_id));
    $cache_set->setValue(null, true);

    $psc_messages_data = Psc_Messages_Frontend::data_for_family($family_id, true);
    $seen = $wpdb->get_var($wpdb->prepare(
        'SELECT vu_le FROM ' . psc_table('message_destinataires') . ' WHERE message_id = %d AND family_id = %d',
        $message_id,
        $family_id
    ));
    $assert($seen === null, 'La consultation a marqué le message comme lu.');
    $assert(empty($psc_messages_data['selected']->vu_le), 'Le rendu a falsifié l’état de lecture en mémoire.');
    $assert((int) $psc_messages_data['unread'] === 1, 'Le compteur de non-lus ne reflète plus la famille.');

    ob_start();
    include PSC_PATH . 'templates/frontend-messages.php';
    $html = ob_get_clean();
    $assert(strpos($html, 'Non lu par la famille') !== false, 'Le statut non lu n’est pas affiché.');

    psc_record_legacy_usage('planning_v1_url');
    $assert(get_option('psc_legacy_usage_counts', array()) === $legacy_before, 'Le compteur legacy a été modifié.');

    WP_CLI::log(sprintf('OK : %d effets de bord de consultation neutralisés.', $checks));
} finally {
    $wpdb->query('ROLLBACK');
    $cache->setValue(null, null);
    $cache_set->setValue(null, false);
    if ($legacy_exists) {
        update_option('psc_legacy_usage_counts', $legacy_before, false);
    } else {
        delete_option('psc_legacy_usage_counts');
    }
}
