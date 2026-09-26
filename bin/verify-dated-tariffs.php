<?php
/**
 * Script de vérification autonome (P1-16) — tarifs et statut « cantine
 * sans repas » datés (schéma 4.17.0), en conditions WP-CLI réelles, sur
 * une famille et une année réservées (2091-2092) :
 *
 *  - un tarif change au 15 octobre : les jours d'avant gardent l'ancien
 *    prix, la facture d'octobre porte deux lignes (libellé précisé par la
 *    date), l'estimation du portail est identique à la facture ;
 *  - la cantine devient « sans repas » au 15 octobre : repas commandé et
 *    facturé avant, midi sans repas après ;
 *  - un tarif à venir se supprime, un tarif entré en vigueur non ;
 *  - la migration 4.17.0 reprend la grille de l'ancienne option et les
 *    enfants signalés, puis supprime la colonne (grille et colonne
 *    restaurées ensuite).
 *
 * Usage :
 *   wp --require=bin/verify-dated-tariffs.php verify-dated-tariffs
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-dated-tariffs', function () {

    if (!class_exists('Psc_Tarifs') || !Psc_Tarifs::ready()) {
        WP_CLI::error('Schéma 4.17.0 absent : la mise à jour n’a pas tourné.');
    }

    global $wpdb;
    $email = 'verify-dated-tariffs@example.invalid';
    $t_par = psc_table('parents');
    $t_ch  = psc_table('children');
    $t_inv = psc_table('invoices');
    $t_tar = psc_table('tarifs');
    $t_sr  = psc_table('sans_repas');
    $mois  = '2091-10';
    $saved_tarifs = $wpdb->get_results("SELECT code, prix_centimes, debut, fin, created_at, created_by FROM $t_tar", ARRAY_A);

    $purge = function () use ($wpdb, $email, $t_par, $t_ch, $t_inv, $t_tar, $saved_tarifs) {
        $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_par WHERE email = %s", $email));
        if ($pid) {
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, pdf_path FROM $t_inv WHERE parent_id = %d", $pid)) as $inv) {
                if ($inv->pdf_path) @unlink(psc_private_path($inv->pdf_path)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                $wpdb->delete(psc_table('invoice_versions'), array('invoice_id' => (int) $inv->id));
            }
            $wpdb->delete($t_inv, array('parent_id' => $pid));
            foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_ch WHERE parent_id = %d", $pid)) as $cid) {
                Psc_Planning::delete_for_child((int) $cid);
                $wpdb->delete(psc_table('child_school_years'), array('child_id' => (int) $cid));
            }
            $wpdb->delete($t_ch, array('parent_id' => $pid));
            $wpdb->delete($t_par, array('id' => $pid));
        }
        // Grille d'origine, à l'identique.
        $wpdb->query("DELETE FROM $t_tar");
        foreach ($saved_tarifs as $row) $wpdb->insert($t_tar, $row);
        $year = Psc_School_Years::get_by_key('2091-2092');
        if ($year && $year->statut !== 'active') Psc_School_Years::delete((int) $year->id);
        Psc_Tarifs::flush_cache();
        Psc_Planning::flush_cache();
    };
    $purge();

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    try {
        $y = Psc_School_Years::ensure('2091-09-03', '2092-07-04');
        Psc_School_Year::save('2091-2092', '2091-09-03', '2092-07-04', '[]', 48);
        $pid = Psc_Parents::create($email, 'Tarifs');
        $wpdb->insert($t_ch, array('parent_id' => $pid, 'nom' => 'Tarif', 'prenom' => 'Lina', 'created_at' => current_time('mysql')));
        $cid = (int) $wpdb->insert_id;
        Psc_School_Years::enroll($cid, $y, 'CE1');
        foreach (array(1, 2, 4, 5) as $wd) Psc_Planning::toggle_pattern($cid, '2091-2092', $wd, 'CANT', true);
        Psc_Planning::flush_cache();

        $days = Psc_School_Year::school_days_in_month($mois);
        $before = array_values(array_filter($days, function ($d) { return $d < '2091-10-15'; }));
        $after  = array_values(array_filter($days, function ($d) { return $d >= '2091-10-15'; }));
        $check(count($before) > 0 && count($after) > 0, 'jeu de données : jours avant et après le 15 absents');
        $old = (int) round(psc_billing_tariffs('2091-10-01')['CANT']['price'] * 100);
        $today = (int) round(psc_billing_tariffs()['CANT']['price'] * 100);

        // 1. Nouveau tarif de cantine au 15 octobre.
        $check(Psc_Tarifs::set('CANT', $old + 100, '2091-10-15') === true, 'tarif daté : enregistrement');
        $check((int) round(psc_billing_tariffs('2091-10-14')['CANT']['price'] * 100) === $old, 'tarif daté : la veille a changé');
        $check((int) round(psc_billing_tariffs('2091-10-15')['CANT']['price'] * 100) === $old + 100, 'tarif daté : non appliqué à sa date');
        $check((int) round(psc_billing_tariffs()['CANT']['price'] * 100) === $today, 'tarif daté : le tarif du jour a changé');

        $id = Psc_Invoices::generate_one($pid, $mois);
        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_inv WHERE id = %d", $id));
        $snap = json_decode((string) $inv->lines_json, true);
        $cant = array_values(array_filter($snap['lignes'] ?? array(), function ($l) { return $l['service'] === 'CANT'; }));
        $check(count($cant) === 2, 'facture : ' . count($cant) . ' ligne(s) de cantine au lieu de 2');
        if (count($cant) === 2) {
            $check($cant[0]['quantite'] === count($before) && abs($cant[0]['prix_unitaire'] - $old / 100) < 0.005, 'facture : jours avant le 15 au mauvais tarif');
            $check($cant[1]['quantite'] === count($after) && abs($cant[1]['prix_unitaire'] - ($old + 100) / 100) < 0.005, 'facture : jours à partir du 15 au mauvais tarif');
            $check(strpos($cant[1]['libelle'], '(à partir du 15/10)') !== false, 'facture : libellé de la seconde ligne non précisé');
        }
        $expected = round((count($before) * $old + count($after) * ($old + 100)) / 100, 2);
        $check(abs((float) $inv->total - $expected) < 0.005, "facture : total {$inv->total} au lieu de $expected");
        $child = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_ch WHERE id = %d", $cid));
        $summary = Psc_Planning::sibling_summary(array($child), array($mois));
        $check(abs((float) $summary['month_total'] - (float) $inv->total) < 0.005, 'portail : estimation différente de la facture');

        // 2. Cantine sans repas au 15 octobre.
        $check(!is_wp_error(Psc_Sans_Repas::set($cid, true, '2091-10-15')), 'sans repas daté : enregistrement');
        $check(!Psc_Sans_Repas::on($cid, '2091-10-14') && Psc_Sans_Repas::on($cid, '2091-10-15'), 'sans repas daté : mauvaise date d’effet');
        $map = Psc_Planning::declared_map(array($cid), array($before[0], $after[0]));
        $check(!empty($map[$cid][$before[0]]['CANT']) && empty($map[$cid][$before[0]]['MSR']), 'sans repas daté : la veille, la cantine est devenue sans repas');
        $check(empty($map[$cid][$after[0]]['CANT']) && !empty($map[$cid][$after[0]]['MSR']), 'sans repas daté : à sa date, la cantine reste un repas');
        $counts = Psc_Supplier_Orders::compute_counts($after[0]);
        $row = $counts['rows'][array_search($after[0], $counts['jours'], true)] ?? array();
        $check(($row['midi'] ?? -1) === 0, 'commande : repas commandé pendant le statut sans repas');
        $counts = Psc_Supplier_Orders::compute_counts($before[0]);
        $row = $counts['rows'][array_search($before[0], $counts['jours'], true)] ?? array();
        $check(($row['midi'] ?? -1) === 1, 'commande : repas non commandé avant le statut');
        Psc_Invoices::generate_one($pid, $mois);
        $snap = json_decode((string) $wpdb->get_var($wpdb->prepare("SELECT lines_json FROM $t_inv WHERE id = %d", $id)), true);
        $by = array();
        foreach ($snap['lignes'] as $l) $by[$l['service']] = ($by[$l['service']] ?? 0) + $l['quantite'];
        $check(($by['CANT'] ?? 0) === count($before) && ($by['MSR'] ?? 0) === count($after), 'facture : répartition cantine / sans repas ' . wp_json_encode($by));
        $check(!is_wp_error(Psc_Sans_Repas::set($cid, false, '2091-11-01')) && Psc_Sans_Repas::on($cid, '2091-10-31') && !Psc_Sans_Repas::on($cid, '2091-11-02'), 'sans repas daté : levée sans effet sur le passé');

        // 3. Suppression : à venir oui, entrée en vigueur non.
        $future = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_tar WHERE code = 'CANT' AND debut = %s", '2091-10-15'));
        $check(Psc_Tarifs::delete($future) === true, 'suppression : tarif à venir refusé');
        Psc_Tarifs::set('GM', 190, '2001-01-01');
        $past = (int) $wpdb->get_var("SELECT id FROM $t_tar WHERE code = 'GM' AND debut = '2001-01-01'");
        $refus = Psc_Tarifs::delete($past);
        $check(is_wp_error($refus) && $refus->get_error_code() === 'past', 'suppression : tarif entré en vigueur supprimé');

        // 4. Migration 4.17.0 : grille de l'ancienne option, enfant signalé.
        $wpdb->query("DELETE FROM $t_tar");
        update_option('psc_service_prices', array('CANT' => 6.25, 'FSR' => 7.10));
        $wpdb->query("ALTER TABLE $t_ch ADD COLUMN cantine_sans_repas TINYINT(1) NOT NULL DEFAULT 0");
        $wpdb->delete($t_sr, array('child_id' => $cid));
        $wpdb->update($t_ch, array('cantine_sans_repas' => 1), array('id' => $cid));
        $migrate = new ReflectionMethod('Psc_Installer', 'migrate_4_17_0');
        $migrate->setAccessible(true);
        $check($migrate->invoke(null) === true, 'migration : échec');
        Psc_Tarifs::flush_cache();
        Psc_Planning::flush_cache();
        $check((int) $wpdb->get_var("SELECT prix_centimes FROM $t_tar WHERE code = 'CANT'") === 625, 'migration : tarif de l’option non repris');
        $check((int) $wpdb->get_var("SELECT prix_centimes FROM $t_tar WHERE code = 'FSR'") === 710, 'migration : tarif FSR non repris');
        $check((int) $wpdb->get_var("SELECT prix_centimes FROM $t_tar WHERE code = 'GM'") === 185, 'migration : tarif par défaut non créé');
        $check(Psc_Sans_Repas::on($cid, '2091-10-01'), 'migration : enfant signalé sans période');
        $check(!$wpdb->get_var("SHOW COLUMNS FROM $t_ch LIKE 'cantine_sans_repas'"), 'migration : colonne conservée');
        $check(get_option('psc_service_prices', null) === null, 'migration : ancienne option conservée');
        $check($migrate->invoke(null) === true && (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_tar WHERE code = 'CANT'") === 1, 'migration : non idempotente');
    } finally {
        if ($wpdb->get_var("SHOW COLUMNS FROM $t_ch LIKE 'cantine_sans_repas'")) {
            $wpdb->query("ALTER TABLE $t_ch DROP COLUMN cantine_sans_repas");
        }
        delete_option('psc_service_prices');
        $purge();
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Tarifs et statut datés : $checks vérifications.");
});
