<?php
/**
 * Script de vérification autonome (P3-01) — les canaux lisent une journée
 * par les mêmes contrats (includes/helpers/planning.php) : déclaration,
 * présence, repas fourni, facturation. Même rôle que
 * bin/verify-billing-rule.php, en conditions WP-CLI réelles.
 *
 * Trois familles sur une année réservée (2087-2088), un lundi :
 *  - Forfait  : forfait journée au rythme ;
 *  - Garderies: garderie du matin et du soir ;
 *  - Allergie : cantine seule, allergie alimentaire déclarée.
 *
 * Vérifie :
 *  - la carte résolue de chaque enfant est la ligne attendue de la table de
 *    décision partagée (tests/unit/contrats-journee.php) ;
 *  - commande fournisseur : repas et goûters selon psc_day_meal() ;
 *  - avis de fermeture du jour : les créneaux de présence, jamais le
 *    forfait en plus ;
 *  - fermeture d'une prestation : familles au forfait et inscriptions
 *    directes dans deux groupes disjoints, créneaux restants de chaque
 *    enfant au forfait (retraits compris) annoncés par l'e-mail ;
 *  - annulation de la cantine d'une classe : l'enfant au forfait est
 *    concerné, sa journée devient « forfait, cantine retirée », sa facture
 *    passe aux garderies seules ce jour-là, le repas sort de la commande.
 *
 * Usage :
 *   wp --require=bin/verify-channel-contracts.php verify-channel-contracts
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-channel-contracts', function () {

    if (!function_exists('psc_day_slots') || !class_exists('Psc_Supplier_Orders')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $contrats = require dirname(__DIR__) . '/tests/unit/contrats-journee.php';
    $emails = array(
        'forfait'   => 'verify-contracts-forfait@example.invalid',
        'garderies' => 'verify-contracts-garderies@example.invalid',
        'allergie'  => 'verify-contracts-allergie@example.invalid',
    );
    $mois  = '2087-10';
    $t_par = psc_table('parents');
    $t_ch  = psc_table('children');
    $t_inv = psc_table('invoices');

    $purge = function () use ($wpdb, $emails, $t_par, $t_ch, $t_inv) {
        foreach ($emails as $email) {
            $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_par WHERE email = %s", $email));
            if (!$pid) continue;
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
        $wpdb->query($wpdb->prepare('DELETE FROM ' . psc_table('service_closures') . ' WHERE jour_date LIKE %s', '2087-%'));
        $year = Psc_School_Years::get_by_key('2087-2088');
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
    $codes = array('FORF', 'GM', 'CANT', 'GS', psc_midi_sans_repas_code());
    $day_of = function ($cid, $date) use ($codes) {
        Psc_Planning::flush_cache();
        $map = Psc_Planning::declared_map(array($cid), array($date));
        $out = array();
        foreach ($codes as $c) $out[$c] = !empty($map[$cid][$date][$c]);
        return $out;
    };
    $items_of = function ($families, $pid) {
        $out = array();
        foreach ((array) ($families[$pid]['items'] ?? array()) as $it) $out[] = $it->service;
        return $out;
    };

    try {
        $y = Psc_School_Years::ensure('2087-09-02', '2088-07-02');
        Psc_School_Year::save('2087-2088', '2087-09-02', '2088-07-02', '[]', 48);

        $kids = array();
        foreach (array(
            'forfait'   => array('FORF'),
            'garderies' => array('GM', 'GS'),
            'allergie'  => array('CANT'),
        ) as $key => $services) {
            $pid = Psc_Parents::create($emails[$key], 'Contrats');
            $row = array('parent_id' => $pid, 'nom' => 'Contrat', 'prenom' => ucfirst($key), 'created_at' => current_time('mysql'));
            if ($key === 'allergie') $row['food_allergies'] = 'Arachide';
            $wpdb->insert($t_ch, $row);
            $cid = (int) $wpdb->insert_id;
            Psc_School_Years::enroll($cid, $y, 'CE1');
            foreach ($services as $svc) Psc_Planning::toggle_pattern($cid, '2087-2088', 1, $svc, true);
            $kids[$key] = array('pid' => (int) $pid, 'cid' => $cid);
        }
        Psc_Planning::flush_cache();

        $mondays = array_values(array_filter(Psc_School_Year::school_days_in_month($mois), function ($d) { return date('N', strtotime($d)) === '1'; }));
        $lundi = $mondays[0];

        // 1. Carte résolue = ligne de la table de décision.
        $lignes = array(
            'forfait'   => 'forfait déclaré, journée complète',
            'garderies' => 'garderies seules',
            'allergie'  => 'cantine seule, allergie alimentaire',
        );
        foreach ($lignes as $key => $ligne) {
            $check($day_of($kids[$key]['cid'], $lundi) === $contrats[$ligne]['jour'], "déclaration : $key ne résout pas en « $ligne »");
        }

        // 2. Commande fournisseur : repas fourni (forfait seul : l'allergique
        //    apporte le sien), goûter pour la garderie du soir.
        $counts = Psc_Supplier_Orders::compute_counts($lundi);
        $row = $counts['rows'][array_search($lundi, $counts['jours'], true)] ?? array();
        $check(($row['midi'] ?? -1) === 1, 'commande : ' . ($row['midi'] ?? '?') . ' repas au lieu de 1');
        $check(($row['gouter'] ?? -1) === 2, 'commande : ' . ($row['gouter'] ?? '?') . ' goûters au lieu de 2');

        // 3. Avis de fermeture du jour : les créneaux de présence.
        $aff = Psc_School_Calendar::affected_families($lundi);
        foreach ($lignes as $key => $ligne) {
            $got = $items_of($aff['families'], $kids[$key]['pid']);
            $check($got === $contrats[$ligne]['presence'], "fermeture du jour, $key : " . implode('+', $got) . ' au lieu de ' . implode('+', $contrats[$ligne]['presence']));
        }
        $check((int) $aff['registrations'] === 6, "fermeture du jour : {$aff['registrations']} créneaux au lieu de 6");
        $range = Psc_School_Calendar::affected_families_range($lundi, $lundi);
        $check($items_of($range['families'], $kids['forfait']['pid']) === array('GM', 'CANT', 'GS'), 'fermeture d’une période : le forfait annoncé en plus de ses créneaux');

        // 4. Fermeture d'une prestation : deux groupes disjoints.
        $svc = Psc_School_Calendar::affected_families_for_service($lundi, 'GS');
        $check(isset($svc['direct']['families'][$kids['garderies']['pid']]), 'fermeture du soir : famille « garderies » absente des inscriptions directes');
        $check(!isset($svc['direct']['families'][$kids['forfait']['pid']]), 'fermeture du soir : famille au forfait aussi parmi les inscriptions directes');
        $check(isset($svc['forf']['families'][$kids['forfait']['pid']]), 'fermeture du soir : famille au forfait absente du groupe forfait');
        $restant = $svc['forf']['families'][$kids['forfait']['pid']]['items'][0]->remaining ?? null;
        $check($restant === array('GM', 'CANT'), 'fermeture du soir, forfait : créneaux restants ' . wp_json_encode($restant) . ' au lieu de GM+CANT');
        $range = Psc_School_Calendar::affected_families_range($mondays[0], $mondays[1]);
        $check((int) $range['registrations'] === 12, "fermeture de deux lundis : {$range['registrations']} créneaux au lieu de 12");

        // 5. Annulation de la cantine de la classe : le forfait est concerné.
        $concernes = array_map('intval', wp_list_pluck(Psc_Supplier_Orders::cantine_registrations_for_class_day($lundi, 'CE1'), 'child_id'));
        sort($concernes);
        $attendus = array($kids['forfait']['cid'], $kids['allergie']['cid']);
        sort($attendus);
        $check($concernes === $attendus, 'annulation de classe : enfants concernés ' . implode(',', $concernes) . ' au lieu de ' . implode(',', $attendus));

        $avant = Psc_Invoices::generate_one($kids['forfait']['pid'], $mois);
        $total_avant = (float) $wpdb->get_var($wpdb->prepare("SELECT total FROM $t_inv WHERE id = %d", $avant));
        $wpdb->delete($t_inv, array('id' => (int) $avant));

        $n = Psc_Supplier_Orders::cancel_class_meals($lundi, 'CE1', 'Sortie scolaire');
        $check($n === 2, "annulation de classe : $n enfant(s) au lieu de 2");
        $check($day_of($kids['forfait']['cid'], $lundi) === $contrats['forfait, cantine retirée (famille ou annulation de classe)']['jour'], 'annulation de classe : le forfait garde sa cantine');
        $counts = Psc_Supplier_Orders::compute_counts($lundi);
        $row = $counts['rows'][array_search($lundi, $counts['jours'], true)] ?? array();
        $check(($row['midi'] ?? -1) === 0, 'annulation de classe : repas encore commandé');

        // Fermeture du soir après le retrait de la cantine : il ne reste au
        // forfait que la garderie du matin, et l'e-mail le dit.
        $svc = Psc_School_Calendar::affected_families_for_service($lundi, 'GS');
        $restant = $svc['forf']['families'][$kids['forfait']['pid']]['items'][0]->remaining ?? null;
        $check($restant === array('GM'), 'fermeture du soir après retrait : créneaux restants ' . wp_json_encode($restant) . ' au lieu de GM');
        $mails = array();
        $capture = function ($null, $atts) use (&$mails) { $mails[] = $atts; return true; };
        add_filter('pre_wp_mail', $capture, 10, 2);
        Psc_School_Calendar::close_service($lundi, 'GS', 'Test');
        remove_filter('pre_wp_mail', $capture, 10);
        $forf_mail = null;
        foreach ($mails as $m) {
            if ((array) $m['to'] === array($emails['forfait']) || $m['to'] === $emails['forfait']) $forf_mail = $m;
        }
        $check($forf_mail !== null && count(array_filter($mails, function ($m) use ($emails) { return in_array($emails['forfait'], (array) $m['to'], true); })) === 1, 'fermeture du soir : la famille au forfait ne reçoit pas exactement un e-mail');
        $gm = psc_services()['GM']['label'];
        $check($forf_mail && strpos($forf_mail['message'], 'Forfait Contrat : ' . esc_html($gm) . '</li>') !== false, 'e-mail « forfait modifié » : créneau restant de l’enfant absent');
        $wpdb->query($wpdb->prepare('DELETE FROM ' . psc_table('service_closures') . ' WHERE jour_date = %s', $lundi));

        $tar = psc_billing_tariffs();
        $jour_complet = psc_billing_services($contrats['forfait déclaré, journée complète']['jour']);
        $prix = function (array $c) use ($tar) { $s = 0.0; foreach ($c as $x) $s += (float) $tar[$x]['price']; return $s; };
        $attendu = round($total_avant - $prix($jour_complet) + $prix(array('GM', 'GS')), 2);
        $apres = Psc_Invoices::generate_one($kids['forfait']['pid'], $mois);
        $total_apres = (float) $wpdb->get_var($wpdb->prepare("SELECT total FROM $t_inv WHERE id = %d", $apres));
        $check(abs($total_apres - $attendu) < 0.005, "annulation de classe : facture $total_apres au lieu de $attendu");
    } finally {
        $purge();
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Contrats des canaux : $checks vérifications.");
});
