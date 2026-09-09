<?php
// WP-CLI : tables temporaires propres à la connexion, aucune donnée réelle modifiée.
if (!defined('WP_CLI') || !WP_CLI) return;
global $wpdb;
$tables = array(psc_table('pickup_persons'), psc_table('pickup_history'), psc_table('requests'));
try {
    $wpdb->query("CREATE TEMPORARY TABLE {$tables[0]} (id INT, piece_identite TINYINT)");
    $wpdb->query("CREATE TEMPORARY TABLE {$tables[1]} (id INT, person_snapshot TEXT)");
    $wpdb->query("CREATE TEMPORARY TABLE {$tables[2]} (id INT, children_json TEXT)");
    $wpdb->insert($tables[1], array('id'=>1, 'person_snapshot'=>wp_json_encode(array('nom'=>'Test','piece_identite'=>1))));
    $wpdb->insert($tables[2], array('id'=>1, 'children_json'=>wp_json_encode(array(array('prenom'=>'Enfant','personnes_autorisees'=>array(array('nom'=>'Test','piece_identite'=>0)))))));
    $migration = new ReflectionMethod('Psc_Installer', 'remove_pickup_identity_data');
    $migration->setAccessible(true);
    if (!$migration->invoke(null) || !$migration->invoke(null)) throw new RuntimeException('Migration ou réexécution échouée');
    if ($wpdb->get_var("SHOW COLUMNS FROM {$tables[0]} LIKE 'piece_identite'")) throw new RuntimeException('Colonne conservée');
    $snapshot = json_decode($wpdb->get_var("SELECT person_snapshot FROM {$tables[1]} WHERE id=1"), true);
    if ($snapshot !== array('nom'=>'Test')) throw new RuntimeException('Historique incorrect');
    $children = json_decode($wpdb->get_var("SELECT children_json FROM {$tables[2]} WHERE id=1"), true);
    if ($children[0]['prenom'] !== 'Enfant' || $children[0]['personnes_autorisees'][0] !== array('nom'=>'Test')) throw new RuntimeException('Ancienne demande incorrecte');
    echo "OK : colonne supprimée, historiques et demandes nettoyés, migration réexécutable.\n";
} finally {
    foreach ($tables as $table) $wpdb->query("DROP TEMPORARY TABLE IF EXISTS $table");
}
