<?php
/**
 * Seed de tests/contrats-canaux.spec.ts (P3-01) : une classe CE1 sur une
 * année réservée (2089-2090), le premier lundi d'octobre 2089 :
 *  - enfant au forfait journée ;
 *  - enfant à la cantine seule.
 * Deux familles distinctes. Purge-et-recrée à chaque appel ; --purge
 * nettoie seulement.
 *
 * Usage :
 *   wp --require=bin/seed-channel-contracts.php seed-channel-contracts [--purge]
 * Sortie : une ligne JSON {date, forfait: {parent_id, child_id}, cantine: {…}}.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('seed-channel-contracts', function ($args, $assoc) {
    global $wpdb;
    $emails = array(
        'forfait' => 'seed-contracts-forfait@example.invalid',
        'cantine' => 'seed-contracts-cantine@example.invalid',
    );
    $t_par = psc_table('parents');
    $t_ch  = psc_table('children');

    foreach ($emails as $email) {
        $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_par WHERE email = %s", $email));
        if (!$pid) continue;
        foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_ch WHERE parent_id = %d", $pid)) as $cid) {
            Psc_Planning::delete_for_child((int) $cid);
            $wpdb->delete(psc_table('child_school_years'), array('child_id' => (int) $cid));
        }
        $wpdb->delete($t_ch, array('parent_id' => $pid));
        $wpdb->delete(psc_table('invoices'), array('parent_id' => $pid));
        $wpdb->delete($t_par, array('id' => $pid));
    }
    $year = Psc_School_Years::get_by_key('2089-2090');
    if ($year && $year->statut !== 'active') Psc_School_Years::delete((int) $year->id);
    Psc_Planning::flush_cache();
    if (!empty($assoc['purge'])) {
        WP_CLI::line(wp_json_encode(array('purged' => true)));
        return;
    }

    $y = Psc_School_Years::ensure('2089-09-03', '2090-07-05');
    Psc_School_Year::save('2089-2090', '2089-09-03', '2090-07-05', '[]', 48);
    $out = array();
    foreach (array('forfait' => 'FORF', 'cantine' => 'CANT') as $key => $svc) {
        $pid = Psc_Parents::create($emails[$key], 'Famille ' . ucfirst($key));
        $wpdb->insert($t_ch, array('parent_id' => $pid, 'nom' => 'Contrat', 'prenom' => ucfirst($key), 'created_at' => current_time('mysql')));
        $cid = (int) $wpdb->insert_id;
        Psc_School_Years::enroll($cid, $y, 'CE1');
        Psc_Planning::toggle_pattern($cid, '2089-2090', 1, $svc, true);
        $out[$key] = array('parent_id' => (int) $pid, 'child_id' => $cid);
    }
    Psc_Planning::flush_cache();
    $mondays = array_values(array_filter(Psc_School_Year::school_days_in_month('2089-10'), function ($d) { return date('N', strtotime($d)) === '1'; }));
    $out['date'] = $mondays[0];
    WP_CLI::line(wp_json_encode($out));
});
