<?php
/**
 * wp eval-file tests/integration/invoice-snapshot.php
 *
 * P1-16 : une facture émise ne bouge plus. Changer un tarif en octobre ne
 * doit pas altérer une facture de septembre déjà envoyée ; la correction
 * crée une version distincte, et la version remise à la famille reste
 * consultable, PDF compris.
 */
if (!defined('WP_CLI') || !WP_CLI) return;

global $wpdb;
$checks = 0;
$assert = function ($condition, $message) use (&$checks) {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$t_inv  = psc_table('invoices');
$t_invv = psc_table('invoice_versions');

/* Un mois réellement scolaire de l'année active : la génération part des
   jours d'école, pas du calendrier civil. */
$mois = null;
$dates = array();
foreach (Psc_School_Year::all() as $year) {
    $cursor = new DateTime($year->date_start);
    $end = new DateTime($year->date_end);
    $guard = 0;
    while ($cursor <= $end && $guard++ < 24) {
        $candidate = $cursor->format('Y-m');
        $days = Psc_School_Year::school_days_in_month($candidate);
        if (count($days) >= 2) { $mois = $candidate; $dates = $days; break 2; }
        $cursor->modify('first day of next month');
    }
}
$assert($mois !== null, 'Aucune année scolaire ne fournit de mois avec jours d’école.');

$files = array();
$prices_before = get_option('psc_service_prices', array());

$wpdb->query('START TRANSACTION');
try {
    $wpdb->insert(psc_table('parents'), array(
        'email'      => 'sonde-facture@example.invalid',
        'nom'        => 'Sonde',
        'prenom'     => 'Facture',
        'active'     => 1,
        'created_at' => current_time('mysql'),
    ));
    $parent_id = (int) $wpdb->insert_id;
    $wpdb->insert(psc_table('children'), array(
        'parent_id'  => $parent_id,
        'nom'        => 'Sonde',
        'prenom'     => 'Enfant',
        'statut'     => 'actif',
        'created_at' => current_time('mysql'),
    ));
    $child_id = (int) $wpdb->insert_id;

    foreach (array_slice($dates, 0, 2) as $date) {
        Psc_Planning::toggle_exception($child_id, $date, 'CANT', true, true);
    }
    Psc_Planning::flush_cache();

    /* 1. Première émission : instantané présent, version 1. */
    $invoice_id = Psc_Invoices::generate_one($parent_id, $mois);
    $assert(!is_wp_error($invoice_id), 'La génération initiale échoue : '
        . (is_wp_error($invoice_id) ? $invoice_id->get_error_message() : ''));

    $v1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_inv WHERE id = %d", $invoice_id));
    $assert((int) $v1->version === 1, 'La première facture ne porte pas la version 1.');
    $snapshot1 = json_decode((string) $v1->lines_json, true);
    $assert(is_array($snapshot1) && !empty($snapshot1['lignes']), 'L’instantané des lignes est absent.');
    $unit1 = (float) $snapshot1['lignes'][0]['prix_unitaire'];
    $assert($unit1 > 0, 'L’instantané ne porte pas le tarif appliqué.');
    $total1 = (float) $v1->total;
    $assert($total1 > 0, 'Le total de la première facture est nul.');

    $pdf1_abs = psc_private_path($v1->pdf_path);
    $assert($pdf1_abs && file_exists($pdf1_abs), 'Le PDF de la première facture est absent.');
    $files[] = $pdf1_abs;

    /* 2. Facture envoyée : elle devient un document remis. */
    $wpdb->update($t_inv, array('sent_at' => current_time('mysql')), array('id' => $invoice_id));

    /* 3. Régénération sans changement : aucun effet, pas même sur le PDF.
          L'en-tête du PDF porte la date du jour — le réécrire modifierait
          un document déjà remis. */
    $mtime_before = filemtime($pdf1_abs);
    $pdf1_bytes = file_get_contents($pdf1_abs);
    sleep(1);
    $again = Psc_Invoices::generate_one($parent_id, $mois);
    $assert((int) $again === (int) $invoice_id, 'La régénération à l’identique crée une autre facture.');
    clearstatcache(true, $pdf1_abs);
    $assert(filemtime($pdf1_abs) === $mtime_before, 'Le PDF d’une facture émise inchangée a été réécrit.');
    $unchanged = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_inv WHERE id = %d", $invoice_id));
    $assert((int) $unchanged->version === 1, 'Une version a été créée sans changement de calcul.');
    $assert(!empty($unchanged->sent_at), 'Le statut d’envoi a été perdu sur une régénération sans effet.');
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_invv WHERE invoice_id = %d", $invoice_id)) === 0,
        'Une archive a été créée alors que rien n’a changé.');

    /* 4. Le tarif change APRÈS l'émission : le cas même de P1-16. */
    $prices = is_array($prices_before) ? $prices_before : array();
    $prices['CANT'] = round($unit1 + 1.30, 2);
    update_option('psc_service_prices', $prices);

    $corrected = Psc_Invoices::generate_one($parent_id, $mois);
    $assert(!is_wp_error($corrected), 'La rectification échoue : '
        . (is_wp_error($corrected) ? $corrected->get_error_message() : ''));
    $assert((int) $corrected === (int) $invoice_id, 'La rectification a créé une facture parallèle.');

    $v2 = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_inv WHERE id = %d", $invoice_id));
    $assert((int) $v2->version === 2, 'La rectification ne crée pas de version 2.');
    $assert((float) $v2->total > $total1, 'Le nouveau total n’intègre pas le tarif modifié.');
    $assert(empty($v2->sent_at), 'La version rectifiée est présentée comme déjà envoyée.');

    /* 5. La version remise à la famille est archivée telle quelle : c'est
          l'assertion centrale. Son instantané porte encore l'ANCIEN tarif. */
    $archived = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $t_invv WHERE invoice_id = %d AND version = 1", $invoice_id
    ));
    $assert($archived !== null, 'La version 1 n’a pas été archivée.');
    $assert(abs((float) $archived->total - $total1) < 0.005, 'Le total archivé a été réécrit.');
    $assert(!empty($archived->sent_at), 'L’archive a perdu la date d’envoi de la version remise.');

    $archived_snapshot = json_decode((string) $archived->lines_json, true);
    $assert(is_array($archived_snapshot), 'L’instantané archivé est illisible.');
    $assert(abs((float) $archived_snapshot['lignes'][0]['prix_unitaire'] - $unit1) < 0.005,
        'L’instantané archivé a été recalculé avec le tarif du jour.');

    $archived_abs = psc_private_path($archived->pdf_path);
    $assert($archived_abs && file_exists($archived_abs), 'Le PDF de la version remise a disparu.');
    $files[] = $archived_abs;
    $assert(file_get_contents($archived_abs) === $pdf1_bytes, 'Le PDF archivé n’est pas celui remis à la famille.');

    $new_abs = psc_private_path($v2->pdf_path);
    $assert($new_abs && file_exists($new_abs), 'Le PDF de la version rectifiée est absent.');
    $assert($new_abs !== $archived_abs, 'Les deux versions partagent le même fichier.');
    $files[] = $new_abs;

    $versions = Psc_Invoices::versions_for_invoice($invoice_id);
    $assert(count($versions) === 1 && (int) $versions[0]->version === 1,
        'Le lecteur de versions ne restitue pas l’historique.');

    /* 6. Le statut « cantine sans repas » compte aussi comme un changement
          de calcul : il n'est pas daté, il ne doit pas modifier le passé
          en silence. */
    $wpdb->update($t_inv, array('sent_at' => current_time('mysql')), array('id' => $invoice_id));
    $wpdb->update(psc_table('children'), array('cantine_sans_repas' => 1), array('id' => $child_id));
    Psc_Planning::flush_cache();

    $third = Psc_Invoices::generate_one($parent_id, $mois);
    $assert(!is_wp_error($third), 'La rectification par changement de statut échoue.');
    $v3 = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_inv WHERE id = %d", $invoice_id));
    $assert((int) $v3->version === 3, 'Le changement de statut « sans repas » ne crée pas de version.');
    $archived2 = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $t_invv WHERE invoice_id = %d AND version = 2", $invoice_id
    ));
    $assert($archived2 !== null, 'La version 2 n’a pas été archivée à son tour.');
    if (!empty($archived2->pdf_path)) {
        $a2 = psc_private_path($archived2->pdf_path);
        if ($a2 && file_exists($a2)) $files[] = $a2;
    }
    if (!empty($v3->pdf_path)) {
        $p3 = psc_private_path($v3->pdf_path);
        if ($p3 && file_exists($p3)) $files[] = $p3;
    }

    /* 7. Le journal d'audit porte la trace des rectifications. */
    $logged = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . psc_table('audit_log') . " WHERE action = %s AND famille_id = %d",
        'facture.rectification', $parent_id
    ));
    $assert($logged === 2, sprintf('Le journal d’audit porte %d rectification(s) au lieu de 2.', $logged));

    WP_CLI::log(sprintf('OK : %d vérifications du gel des factures émises (mois %s).', $checks, $mois));
} finally {
    update_option('psc_service_prices', $prices_before);
    $wpdb->query('ROLLBACK');
    foreach (array_unique($files) as $file) {
        if (file_exists($file)) unlink($file);
    }
}
