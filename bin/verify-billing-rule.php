<?php
/**
 * Script de vérification autonome (P1-15) — une règle unique de facturation
 * d'une journée, partagée par toutes les sorties. Même rôle que
 * bin/verify-write-integrity.php : celui du test unitaire, en conditions
 * WP-CLI réelles.
 *
 * Vérifie, sur un enfant de test et une année réservée (2085-2086) :
 *  - la matrice de la règle 2 : forfait seulement pour une journée
 *    complète (matin, midi, soir), jamais au-dessus de la somme des
 *    créneaux ; retrait et fermeture traités de la même façon ; aucun
 *    cumul ; drapeau « sans repas » ;
 *  - une facture déjà envoyée sous la règle 1 n'est pas rectifiée par le
 *    seul changement de règle, mais l'est encore par un vrai changement de
 *    déclarations — avec sa règle d'origine ;
 *  - une facture non envoyée suit la règle 2, et son montant est celui de
 *    l'estimation du portail (même règle, mêmes tarifs).
 *
 * Tarifs utilisés : ceux enregistrés sur le site ; les montants attendus
 * sont recalculés à partir d'eux, pas codés en dur.
 *
 * Usage :
 *   wp --require=bin/verify-billing-rule.php verify-billing-rule
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-billing-rule', function () {

    if (!function_exists('psc_billing_services') || !class_exists('Psc_Invoices')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $email = 'verify-billing-rule@example.invalid';
    $mois  = '2085-10';
    $t_par = psc_table('parents');
    $t_ch  = psc_table('children');
    $t_inv = psc_table('invoices');

    $purge = function () use ($wpdb, $email, $t_par, $t_ch, $t_inv, $mois) {
        $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_par WHERE email = %s", $email));
        if ($pid) {
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, pdf_path FROM $t_inv WHERE parent_id = %d", $pid)) as $inv) {
                if ($inv->pdf_path) @unlink(psc_private_path($inv->pdf_path)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT pdf_path FROM ' . psc_table('invoice_versions') . ' WHERE invoice_id = %d', $inv->id)) as $p) {
                    if ($p) @unlink(psc_private_path($p)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }
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
        $wpdb->query($wpdb->prepare('DELETE FROM ' . psc_table('service_closures') . " WHERE jour_date LIKE %s", '2085-10-%'));
        $year = Psc_School_Years::get_by_key('2085-2086');
        if ($year && $year->statut !== 'active') Psc_School_Years::delete((int) $year->id);
        Psc_Planning::flush_cache();
    };
    $purge();

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    $tar = psc_billing_tariffs();
    $price = function (array $codes) use ($tar) {
        $sum = 0.0;
        foreach ($codes as $c) $sum += (float) $tar[$c]['price'];
        return round($sum, 2);
    };

    try {
        // 1. Matrice de la règle 2, sur des cartes de déclarations.
        $unit = array('GM', 'CANT', 'GS');
        $full_price = min($price(array('FORF')), $price($unit));
        $full_codes = $price(array('FORF')) <= $price($unit) ? array('FORF') : $unit;
        $sans = array('GM', 'MSR', 'GS');
        $sans_codes = $price(array('FSR')) <= $price($sans) ? array('FSR') : $sans;
        $matrix = array(
            'forfait complet'                  => array(array('FORF' => 1, 'GM' => 1, 'CANT' => 1, 'GS' => 1), false, $full_codes),
            'forfait, cantine retirée'         => array(array('FORF' => 1, 'GM' => 1, 'GS' => 1), false, array('GM', 'GS')),
            'forfait, garderie matin retirée'  => array(array('FORF' => 1, 'CANT' => 1, 'GS' => 1), false, array('CANT', 'GS')),
            'forfait, garderie soir fermée'    => array(array('GM' => 1, 'CANT' => 1), false, array('GM', 'CANT')),
            'trois créneaux cochés séparément' => array(array('GM' => 1, 'CANT' => 1, 'GS' => 1), false, $full_codes),
            'cantine seule'                    => array(array('CANT' => 1), false, array('CANT')),
            'rien'                             => array(array(), false, array()),
            'drapeau : journée complète'       => array(array('FORF' => 1, 'GM' => 1, 'MSR' => 1, 'GS' => 1), true, $sans_codes),
            'drapeau : soir retiré'            => array(array('FORF' => 1, 'GM' => 1, 'MSR' => 1), true, array('GM', 'MSR')),
            'drapeau : midi seul'              => array(array('MSR' => 1), true, array('MSR')),
        );
        foreach ($matrix as $label => list($day, $flag, $expected)) {
            $got = psc_billing_services($day, $flag);
            sort($got); sort($expected);
            $check($got === $expected, "règle 2, $label : " . implode('+', $got) . ' au lieu de ' . implode('+', $expected));
            $check($price($got) <= $price(array_diff(array_keys(array_filter($day)), array('FORF'))) + 0.001, "règle 2, $label : facturé au-dessus de ses créneaux");
        }
        // Règle 1 conservée à l'identique (cumul compris) pour les factures émises sous elle.
        $check(psc_billing_services(array('FORF' => 1, 'GM' => 1, 'GS' => 1), false, 1) === array('FORF', 'GM', 'GS'), 'règle 1 : non conservée');

        // 2. Factures : une famille, forfait tous les lundis, cantine
        //    retirée un lundi (cas du cumul de la règle 1).
        $y = Psc_School_Years::ensure('2085-09-03', '2086-07-04');
        Psc_School_Year::save('2085-2086', '2085-09-03', '2086-07-04', '[]', 48);
        $pid = Psc_Parents::create($email, 'Facturation');
        $wpdb->insert($t_ch, array('parent_id' => $pid, 'nom' => 'Regle', 'prenom' => 'Lou', 'created_at' => current_time('mysql')));
        $cid = (int) $wpdb->insert_id;
        Psc_School_Years::enroll($cid, $y, 'CE1');
        Psc_Planning::toggle_pattern($cid, '2085-2086', 1, 'FORF', true);
        $mondays = array_values(array_filter(Psc_School_Year::school_days_in_month($mois), function ($d) { return date('N', strtotime($d)) === '1'; }));
        Psc_Planning::toggle_exception($cid, $mondays[0], 'CANT', false, true);
        Psc_Planning::flush_cache();

        $n = count($mondays);
        $expected_r2 = round($full_price * ($n - 1) + $price(array('GM', 'GS')), 2);
        $expected_r1 = round($price(array('FORF')) * ($n - 1) + $price(array('FORF', 'GM', 'GS')), 2);

        // Non envoyée : règle 2, même montant que l'estimation du portail.
        $id = Psc_Invoices::generate_one($pid, $mois);
        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_inv WHERE id = %d", $id));
        $check(abs((float) $inv->total - $expected_r2) < 0.005, "facture non envoyée : {$inv->total} au lieu de $expected_r2");
        $check((int) (json_decode($inv->lines_json, true)['calcul'] ?? 0) === 2, 'facture non envoyée : règle non inscrite');
        $child = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_ch WHERE id = %d", $cid));
        $summary = Psc_Planning::sibling_summary(array($child), array($mois));
        $check(abs((float) $summary['month_total'] - (float) $inv->total) < 0.005, "estimation du portail {$summary['month_total']} ≠ facture {$inv->total}");

        // Envoyée sous la règle 1 : on l'y remet (instantané calcul 1).
        $snap = json_decode($inv->lines_json, true);
        $snap['calcul'] = 1;
        $snap['total'] = $expected_r1;
        $snap['lignes'] = array(
            array('service' => 'FORF', 'libelle' => $tar['FORF']['label'], 'enfant_id' => $cid, 'enfant' => 'Lou Regle', 'quantite' => $n, 'prix_unitaire' => round((float) $tar['FORF']['price'], 2), 'total' => round((float) $tar['FORF']['price'] * $n, 2)),
            array('service' => 'GM', 'libelle' => $tar['GM']['label'], 'enfant_id' => $cid, 'enfant' => 'Lou Regle', 'quantite' => 1, 'prix_unitaire' => round((float) $tar['GM']['price'], 2), 'total' => round((float) $tar['GM']['price'], 2)),
            array('service' => 'GS', 'libelle' => $tar['GS']['label'], 'enfant_id' => $cid, 'enfant' => 'Lou Regle', 'quantite' => 1, 'prix_unitaire' => round((float) $tar['GS']['price'], 2), 'total' => round((float) $tar['GS']['price'], 2)),
        );
        $wpdb->update($t_inv, array('lines_json' => wp_json_encode($snap), 'total' => $expected_r1, 'sent_at' => current_time('mysql')), array('id' => $id));

        Psc_Invoices::generate_one($pid, $mois);
        $again = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_inv WHERE id = %d", $id));
        $check((int) $again->version === 1 && !empty($again->sent_at), 'envoyée sous la règle 1 : rectifiée par le seul changement de règle');
        $check(abs((float) $again->total - $expected_r1) < 0.005, "envoyée sous la règle 1 : total {$again->total} au lieu de $expected_r1");

        // Vrai changement de déclarations : rectification, avec la règle d'origine.
        Psc_Planning::toggle_exception($cid, $mondays[1], 'FORF', false, true);
        Psc_Planning::flush_cache();
        Psc_Invoices::generate_one($pid, $mois);
        $rect = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_inv WHERE id = %d", $id));
        $check((int) $rect->version === 2 && empty($rect->sent_at), 'changement de déclarations : pas de rectification');
        $check((int) (json_decode($rect->lines_json, true)['calcul'] ?? 0) === 1, 'rectification : règle d’origine perdue');
        $check(abs((float) $rect->total - round($expected_r1 - $price(array('FORF')), 2)) < 0.005, "rectification : total {$rect->total}");
    } finally {
        $purge();
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Règle de facturation : $checks vérifications.");
});
