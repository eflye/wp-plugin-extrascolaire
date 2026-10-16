<?php
/**
 * wp eval-file tests/integration/sidscm-pointage.php
 *
 * Pointage à trois états de l'écran intervenants (schéma 4.22.0), joué
 * à travers les vrais points d'entrée AJAX (nonce, session d'intervenant,
 * cohérence jour/service) :
 *  - à pointer par défaut : aucune clé dans la carte `attendance` ;
 *  - state = present / absent / none, heure posée, conservée ou effacée ;
 *  - une heure (arrivée, départ) n'est acceptée que pour un présent ;
 *  - « tous présents » : seulement les attendus à pointer (cantine
 *    « midi sans repas » comprise), jamais un absent, heure posée là où
 *    elle manque, une seule ligne de journal par geste ;
 *  - refus : état inconnu, heure mal formée, enfant non attendu, jour fermé.
 *
 * Tout le passage se joue dans une transaction annulée à la fin :
 * « tous présents » agit sur tous les enfants attendus du jour, ceux du
 * site compris — rien de ce qu'il écrit ne doit survivre au test. Les
 * assertions ne portent que sur la famille dédiée « PscTestPointage »
 * (supprimée aussi explicitement) ; le registre des intervenants est
 * restauré. Ignoré, sans échec, si la semaine en cours n'a aucun jour
 * ouvert (vacances).
 */
if (!defined('WP_CLI') || !WP_CLI) return;

global $wpdb;

$monday = psc_week_start(current_time('Y-m-d'));
$open_days = psc_open_days($monday);
$year_id = $open_days ? Psc_School_Years::id_for_date(reset($open_days)) : 0;
if (!$open_days || !$year_id) {
    WP_CLI::log('IGNORÉ : aucun jour ouvert (ou aucune année scolaire) cette semaine, pointage non vérifiable.');
    return;
}
$date = reset($open_days);
$closed_day = date('Y-m-d', strtotime($monday . ' +2 days')); // mercredi : jamais ouvert

$t_att = psc_table('attendance');
$t_par = psc_table('parents');
$t_child = psc_table('children');
$failures = array();
$check = function ($ok, $label) use (&$failures) {
    if (!$ok) $failures[] = $label;
};

// Appel d'un point d'entrée AJAX : wp_send_json() se termine par wp_die(),
// intercepté ici pour relire la réponse JSON.
$die = function () {
    return function () { throw new RuntimeException('psc_test_die'); };
};
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', $die, 99);
$session = array('token' => '', 'id' => '');
$call = function ($method, array $post) use (&$session) {
    $_POST = $post + array(
        'nonce' => wp_create_nonce('psc_sidscm_front'),
        'session_token' => $session['token'],
        'intervenant_id' => $session['id'],
    );
    $_REQUEST = $_POST;
    ob_start();
    try {
        call_user_func(array('Psc_Sidscm', $method));
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'psc_test_die') { ob_end_clean(); throw $e; }
    }
    $out = (string) ob_get_clean();
    $json = json_decode($out, true);
    return is_array($json) ? $json : array('success' => null, 'raw' => $out);
};
$code = function ($res) {
    return isset($res['data']['code']) ? $res['data']['code'] : null;
};
$row = function ($cid, $service) use ($wpdb, $t_att, $date) {
    return $wpdb->get_row($wpdb->prepare("SELECT present, arrival_time, departure_time FROM $t_att WHERE child_id = %d AND jour_date = %s AND service = %s", $cid, $date, $service));
};
$audit_count = function ($action) use ($wpdb) {
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . psc_table('audit_log') . ' WHERE action = %s', $action));
};

$registry_before = get_option('psc_sidscm_intervenants', null);
$parent_id = 0;
$children = array();

try {
    // Intervenant de test et session ouverte, comme après ajax_unlock().
    $rows = Psc_Sidscm::sanitize_intervenants(array(array('nom' => 'PscTestPointage', 'code' => 'PscTest-Pointage-1', 'active' => 1)));
    update_option('psc_sidscm_intervenants', $rows);
    $session['id'] = (string) $rows[0]['id'];
    $session['token'] = wp_generate_password(48, false, false);
    set_transient('psc_sidscm_session_' . hash('sha256', $session['token']), array('id' => $session['id'], 'nom' => 'PscTestPointage'), 600);

    $wpdb->insert($t_par, array('email' => 'psc-test-pointage@example.test', 'nom' => 'PscTestPointage', 'active' => 1, 'created_at' => current_time('mysql')));
    $parent_id = (int) $wpdb->insert_id;
    foreach (array('a', 'b', 'c', 'd') as $k) {
        $wpdb->insert($t_child, array('parent_id' => $parent_id, 'nom' => 'PscTestPointage', 'prenom' => strtoupper($k), 'created_at' => current_time('mysql')));
        $children[$k] = (int) $wpdb->insert_id;
        Psc_School_Years::enroll($children[$k], $year_id, 'CE1');
    }
    // a : GM + CANT + GS ; b : CANT ; c : midi sans repas ; d : rien.
    foreach (array('GM', 'CANT', 'GS') as $svc) Psc_Planning::toggle_exception($children['a'], $date, $svc, true, true);
    Psc_Planning::toggle_exception($children['b'], $date, 'CANT', true, true);
    // « Midi sans repas » : réservé à un enfant en cantine sans repas.
    Psc_Sans_Repas::set($children['c'], true, $monday);
    $msr = Psc_Planning::toggle_exception($children['c'], $date, psc_midi_sans_repas_code(), true, true);
    $with_msr = isset($msr['status']) && $msr['status'] === 'added';
    Psc_Planning::flush_cache();

    // Transaction ouverte APRÈS la mise en place : l'écriture du planning
    // valide sa propre transaction (COMMIT implicite pour la nôtre). Elle
    // annule les pointages « tous présents » des enfants du site et les
    // lignes de journal écrites pendant le test ; aucun appel ci-dessous
    // ne valide de transaction.
    $wpdb->query('START TRANSACTION');

    $a = $children['a'];
    $key = function ($cid, $svc) use ($date) { return $cid . '|' . $date . '|' . $svc; };

    // À pointer par défaut.
    $week = Psc_Sidscm::week_children($open_days, false);
    $check(!isset($week['attendance'][$key($a, 'GM')]), 'à pointer : aucune clé attendance sans ligne');

    // Heure refusée tant que l'enfant n'est pas présent, sans créer de ligne.
    $res = $call('ajax_set_arrival', array('child_id' => $a, 'jour_date' => $date, 'arrival_time' => '07:40'));
    $check($res['success'] === false && $code($res) === 'not_present', 'arrivée sans pointage : not_present');
    $check($row($a, 'GM') === null, 'arrivée sans pointage : aucune ligne créée');

    // Paramètres refusés.
    $res = $call('ajax_toggle', array('child_id' => $a, 'jour_date' => $date, 'service' => 'GM', 'state' => 'peut-etre'));
    $check($code($res) === 'invalid', 'état inconnu : invalid');
    $res = $call('ajax_toggle', array('child_id' => $a, 'jour_date' => $date, 'service' => 'GM', 'present' => '1'));
    $check($code($res) === 'invalid', 'ancien paramètre present seul : invalid');
    $res = $call('ajax_toggle', array('child_id' => $a, 'jour_date' => $date, 'service' => 'GM', 'state' => 'present', 'time' => '7h40'));
    $check($code($res) === 'invalid', 'heure mal formée : invalid');
    $res = $call('ajax_toggle', array('child_id' => $children['d'], 'jour_date' => $date, 'service' => 'GM', 'state' => 'present'));
    $check($code($res) === 'not_expected', 'enfant non attendu : not_expected');
    $res = $call('ajax_toggle', array('child_id' => $a, 'jour_date' => $closed_day, 'service' => 'GM', 'state' => 'present'));
    $check($code($res) === 'not_open', 'jour fermé : not_open');

    // Présent avec heure.
    $res = $call('ajax_toggle', array('child_id' => $a, 'jour_date' => $date, 'service' => 'GM', 'state' => 'present', 'time' => '07:40'));
    $check($res['success'] === true && $res['data'] === array('state' => 'present', 'time' => '07:40'), 'présent + heure : réponse');
    $r = $row($a, 'GM');
    $check($r && $r->present === '1' && $r->arrival_time === '07:40:00', 'présent + heure : ligne écrite');

    // Heure modifiée sur un présent, puis conservée par un nouveau « présent » sans heure.
    $res = $call('ajax_set_arrival', array('child_id' => $a, 'jour_date' => $date, 'arrival_time' => '07:50'));
    $check($res['success'] === true, 'arrivée d’un présent acceptée');
    $res = $call('ajax_toggle', array('child_id' => $a, 'jour_date' => $date, 'service' => 'GM', 'state' => 'present'));
    $check($res['success'] === true && $res['data']['time'] === '07:50', 'présent sans heure : heure existante conservée');

    // Absent : heure du service effacée, et plus aucune heure acceptée.
    $res = $call('ajax_toggle', array('child_id' => $a, 'jour_date' => $date, 'service' => 'GM', 'state' => 'absent', 'time' => '08:00'));
    $check($res['success'] === true && $res['data'] === array('state' => 'absent', 'time' => null), 'absent : réponse sans heure');
    $r = $row($a, 'GM');
    $check($r && $r->present === '0' && $r->arrival_time === null, 'absent : heure effacée');
    $res = $call('ajax_set_arrival', array('child_id' => $a, 'jour_date' => $date, 'arrival_time' => '07:55'));
    $check($code($res) === 'not_present', 'arrivée d’un absent : not_present');
    $week = Psc_Sidscm::week_children($open_days, false);
    $check(($week['attendance'][$key($a, 'GM')] ?? null) === 0, 'absent : attendance = 0');

    // Annulation : la ligne reste, à pointer, sans heure.
    $res = $call('ajax_toggle', array('child_id' => $a, 'jour_date' => $date, 'service' => 'GM', 'state' => 'none'));
    $check($res['success'] === true && $res['data'] === array('state' => 'none', 'time' => null), 'annulation : réponse');
    $r = $row($a, 'GM');
    $check($r && $r->present === null && $r->arrival_time === null && $r->departure_time === null, 'annulation : ligne à pointer sans heure');
    $week = Psc_Sidscm::week_children($open_days, false);
    $check(!array_key_exists($key($a, 'GM'), $week['attendance']), 'annulation : clé attendance retirée');
    // Export RGPD : la ligne ouverte mais non pointée est restituée « à pointer ».
    $exported = false;
    foreach (Psc_Privacy::export_family_data('psc-test-pointage@example.test')['data'] as $item) {
        foreach ($item['data'] as $f) {
            if ($item['group_id'] === 'psc-presences' && strpos((string) $f['name'], $date) !== false && $f['value'] === __('à pointer', 'periscolaire-registration')) $exported = true;
        }
    }
    $check($exported, 'export RGPD : pointage annulé restitué « à pointer »');

    // Départ (GS) : refusé avant pointage, accepté puis effaçable une fois présent.
    $res = $call('ajax_set_departure', array('child_id' => $a, 'jour_date' => $date, 'departure_time' => '17:30'));
    $check($code($res) === 'not_present', 'départ sans pointage : not_present');
    $call('ajax_toggle', array('child_id' => $a, 'jour_date' => $date, 'service' => 'GS', 'state' => 'present'));
    $res = $call('ajax_set_departure', array('child_id' => $a, 'jour_date' => $date, 'departure_time' => '17:30'));
    $check($res['success'] === true && $row($a, 'GS')->departure_time === '17:30:00', 'départ d’un présent enregistré');
    $res = $call('ajax_set_departure', array('child_id' => $a, 'jour_date' => $date, 'departure_time' => ''));
    $check($res['success'] === true && $row($a, 'GS')->departure_time === null, 'départ vide : heure effacée');

    // Cantine : heure ignorée.
    $res = $call('ajax_toggle', array('child_id' => $children['b'], 'jour_date' => $date, 'service' => 'CANT', 'state' => 'absent'));
    $check($res['success'] === true, 'cantine : absent');

    // « Tous présents » : cantine — a et c (midi sans repas) marqués, b reste absent.
    $before = $audit_count('presence.pointage_groupe');
    $res = $call('ajax_all_present', array('jour_date' => $date, 'service' => 'CANT', 'time' => '12:00'));
    $updated = $res['success'] === true ? $res['data']['updated'] : array();
    // Seuls les enfants du test comptent : ceux du site peuvent être attendus aussi.
    $mine = function ($res) use (&$children) {
        return $res['success'] === true ? array_values(array_intersect($res['data']['updated'], array_values($children))) : null;
    };
    $check($res['success'] === true && $res['data']['time'] === null, 'tous présents cantine : pas d’heure');
    $check(in_array($a, $updated, true), 'tous présents cantine : attendu à pointer marqué');
    $check(!in_array($children['b'], $updated, true) && $row($children['b'], 'CANT')->present === '0', 'tous présents : un absent reste absent');
    $check(!in_array($children['d'], $updated, true) && $row($children['d'], 'CANT') === null, 'tous présents : un non-attendu reste sans ligne');
    if ($with_msr) $check(in_array($children['c'], $updated, true), 'tous présents cantine : midi sans repas compris');
    $check($audit_count('presence.pointage_groupe') === $before + 1, 'tous présents : une seule ligne de journal');

    // Garderie matin : heure posée sur le seul à pointer, puis plus rien à faire.
    $res = $call('ajax_all_present', array('jour_date' => $date, 'service' => 'GM', 'time' => '07:30'));
    $check($res['success'] === true && $mine($res) === array($a) && $res['data']['time'] === '07:30', 'tous présents GM : réponse');
    $r = $row($a, 'GM');
    $check($r && $r->present === '1' && $r->arrival_time === '07:30:00', 'tous présents GM : présent avec heure');
    $res = $call('ajax_all_present', array('jour_date' => $date, 'service' => 'GM', 'time' => '07:45'));
    $check($mine($res) === array() && $row($a, 'GM')->arrival_time === '07:30:00', 'tous présents rejoué : rien ne change');

    // Garderie soir : déjà présent, pas réécrit.
    $res = $call('ajax_all_present', array('jour_date' => $date, 'service' => 'GS'));
    $check($mine($res) === array(), 'tous présents GS : déjà pointé ignoré');

    // Refus.
    $res = $call('ajax_all_present', array('jour_date' => $date, 'service' => 'GS', 'time' => '25:00'));
    $check($code($res) === 'invalid', 'tous présents heure mal formée : invalid');
    $res = $call('ajax_all_present', array('jour_date' => $date, 'service' => 'FORF'));
    $check($code($res) === 'invalid', 'tous présents service inconnu : invalid');
    $res = $call('ajax_all_present', array('jour_date' => $closed_day, 'service' => 'CANT'));
    $check($code($res) === 'not_open', 'tous présents jour fermé : not_open');
    $session_ok = $session;
    $session = array('token' => 'jeton-invalide', 'id' => '');
    $res = $call('ajax_all_present', array('jour_date' => $date, 'service' => 'CANT', 'code' => 'faux'));
    $check(in_array($code($res), array('auth', 'rate'), true), 'tous présents sans session : refusé');
    $session = $session_ok;
} finally {
    $wpdb->query('ROLLBACK');
    remove_filter('wp_doing_ajax', '__return_true');
    remove_filter('wp_die_ajax_handler', $die, 99);
    $_POST = array();
    $_REQUEST = array();
    foreach ($children as $cid) {
        $wpdb->delete($t_att, array('child_id' => $cid), array('%d'));
        Psc_Planning::delete_for_child($cid);
        $wpdb->delete(psc_table('child_school_years'), array('child_id' => $cid), array('%d'));
        $wpdb->delete(psc_table('sans_repas'), array('child_id' => $cid), array('%d'));
    }
    if ($parent_id) {
        $wpdb->delete($t_child, array('parent_id' => $parent_id), array('%d'));
        $wpdb->delete($t_par, array('id' => $parent_id), array('%d'));
    }
    if ($session['token'] !== '') delete_transient('psc_sidscm_session_' . hash('sha256', $session['token']));
    if ($registry_before === null) delete_option('psc_sidscm_intervenants'); else update_option('psc_sidscm_intervenants', $registry_before);
    Psc_Planning::flush_cache();
}

if ($failures) {
    foreach ($failures as $f) WP_CLI::log('  ÉCHEC — ' . $f);
    WP_CLI::error(sprintf('%d échec(s) sur le pointage intervenants.', count($failures)));
}
WP_CLI::success('Pointage intervenants à trois états conforme' . ($with_msr ? '' : ' (midi sans repas non vérifié : déclaration refusée ce jour)') . '.');
