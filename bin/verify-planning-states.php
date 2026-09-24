<?php
/**
 * Script de vérification autonome (P2-01) — les cases visibles du planning
 * correspondent aux déclarations réellement prises en compte. Même rôle que
 * bin/verify-promotion-logic.php : ce script joue celui du test unitaire,
 * en conditions WP-CLI réelles.
 *
 * Vérifie :
 *  - pour chaque (date, prestation) du mois, l'état affiché par les écrans
 *    Planning 1 et 2 (month_explicit_map, month_state) égale celui que
 *    comptent la facturation et les listes (declared_map) ;
 *  - le passage « avec repas → sans repas » (flag mairie) sur un planning
 *    déjà rempli : la cantine du rythme apparaît en « midi sans repas »,
 *    modifiable dans les deux sens, y compris sur un jour qui porte une
 *    ancienne exception de cantine ;
 *  - le retrait d'une prestation couverte par une exception forfait
 *    persiste ;
 *  - un ajout sur une prestation fermée ce jour-là est refusé.
 *
 * Usage :
 *   wp --require=bin/verify-planning-states.php verify-planning-states
 *
 * Les données sont scopées par l'adresse e-mail d'un parent de test
 * dédié, purgées avant ET après le run.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-planning-states', function () {

    if (!class_exists('Psc_Planning') || !class_exists('Psc_School_Year')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $email = 'verify-planning-states@example.invalid';
    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_pat    = psc_table('pattern');
    $t_exc    = psc_table('exception');
    $t_close  = psc_table('service_closures');

    $purge = function () use ($wpdb, $email, $t_parent, $t_child, $t_pat, $t_exc) {
        $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_parent WHERE email = %s", $email));
        if (!$pid) return;
        foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_child WHERE parent_id = %d", $pid)) as $cid) {
            $wpdb->delete($t_pat, array('child_id' => (int) $cid));
            $wpdb->delete($t_exc, array('child_id' => (int) $cid));
            $wpdb->delete($t_child, array('id' => (int) $cid));
        }
        $wpdb->delete($t_parent, array('id' => $pid));
    };
    $purge();

    // Année configurée la plus longue, pas forcément l'active : un passage
    // d'année rejoué par d'autres scénarios peut laisser une année active
    // de quelques jours seulement.
    $year = null;
    $best = 0;
    foreach ((array) Psc_School_Year::all() as $candidate_year) {
        $n = count(Psc_School_Year::school_days($candidate_year->date_start, $candidate_year->date_end));
        if ($n > $best) { $best = $n; $year = $candidate_year; }
    }
    if (!$year) WP_CLI::error('Aucune année scolaire configurée.');
    $year_key = $year->year_key;

    // Premier mois de l'année qui compte au moins deux lundis et deux mardis d'école.
    $ym = null;
    $mondays = $tuesdays = array();
    $cursor = new DateTime(substr($year->date_start, 0, 7) . '-01');
    $end = new DateTime($year->date_end);
    while ($cursor <= $end && $ym === null) {
        $candidate = $cursor->format('Y-m');
        // Jours du mois compris dans l'année : school_days_in_month() ne
        // borne pas par les dates de l'année scolaire.
        $days = array_values(array_filter(Psc_School_Year::school_days_in_month($candidate), function ($d) use ($year) {
            return $d >= $year->date_start && $d <= $year->date_end;
        }));
        $m = array_values(array_filter($days, function ($d) { return date('N', strtotime($d)) === '1'; }));
        $t = array_values(array_filter($days, function ($d) { return date('N', strtotime($d)) === '2'; }));
        if (count($m) >= 2 && count($t) >= 2) { $ym = $candidate; $mondays = $m; $tuesdays = $t; }
        $cursor->modify('+1 month');
    }
    if ($ym === null) WP_CLI::error('Aucun mois exploitable dans l’année ' . $year_key . '.');

    $wpdb->insert($t_parent, array('email' => $email, 'nom' => 'VerifyPlanning', 'active' => 1, 'created_at' => current_time('mysql')));
    $pid = (int) $wpdb->insert_id;
    $wpdb->insert($t_child, array('parent_id' => $pid, 'nom' => 'VerifyPlanning', 'prenom' => 'Lou', 'statut' => 'actif', 'created_at' => current_time('mysql')));
    $cid = (int) $wpdb->insert_id;

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };
    $reset_caches = function () use ($wpdb, $t_child, $cid) {
        Psc_Planning::flush_cache();
        // Le flag « cantine sans repas » est mis en cache par requête.
        $prop = new ReflectionProperty('Psc_Planning', 'csr_flag_cache');
        $prop->setAccessible(true);
        $prop->setValue(null, array());
    };
    $declared = function ($date, $svc) use ($cid, $reset_caches) {
        $reset_caches();
        $map = Psc_Planning::declared_map(array($cid), array($date));
        return !empty($map[$cid][$date][$svc]);
    };
    // Chaque case visible = ce que compte la facturation.
    $screens_match = function ($label) use ($cid, $ym, $check, $reset_caches) {
        $reset_caches();
        $state = Psc_Planning::month_state($cid, $ym);
        $explicit = Psc_Planning::month_explicit_map(array($cid), $ym);
        $map = Psc_Planning::declared_map(array($cid), $state['dates']);
        foreach ($state['dates'] as $date) {
            foreach (psc_allowed_services() as $svc) {
                $billed = !empty($map[$cid][$date][$svc]);
                $cell = $state['cells'][$date]['services'][$svc];
                $check($cell['declared'] === $billed, "$label : Planning 2 $date $svc affiche " . var_export($cell['declared'], true) . ', facturé ' . var_export($billed, true));
                $check($explicit[$cid][$date][$svc]['declared'] === $billed, "$label : Planning 1 $date $svc affiche " . var_export($explicit[$cid][$date][$svc]['declared'], true) . ', facturé ' . var_export($billed, true));
                // Une case déclarée ne peut pas être fermée à la saisie : la
                // famille doit pouvoir la retirer.
                if ($billed) $check(!$cell['closed'], "$label : $date $svc déclaré mais case fermée");
            }
        }
    };
    $set_csr = function ($on) use ($wpdb, $t_child, $cid) {
        $wpdb->update($t_child, array('cantine_sans_repas' => $on ? 1 : 0), array('id' => $cid));
    };

    try {
        // 1. Planning rempli AVEC repas : cantine le lundi, exceptions variées.
        Psc_Planning::toggle_pattern($cid, $year_key, 1, 'CANT', true);
        Psc_Planning::toggle_pattern($cid, $year_key, 1, 'GM', true);
        Psc_Planning::toggle_exception($cid, $mondays[1], 'CANT', false, true); // retrait ancien
        Psc_Planning::toggle_exception($cid, $tuesdays[0], 'CANT', true, true); // ajout ancien
        $screens_match('avec repas');

        // 2. Passage « sans repas » par la mairie, sans toucher aux données.
        $set_csr(true);
        $screens_match('sans repas');
        $check($declared($mondays[0], 'MSR'), 'sans repas : la cantine du rythme ne vaut pas midi sans repas');
        $check(!$declared($mondays[1], 'MSR'), 'sans repas : le retrait de cantine ne fige pas l’absence du midi');
        $check($declared($tuesdays[0], 'MSR'), 'sans repas : l’ajout de cantine ne vaut pas midi sans repas');

        // 3. La famille retire le midi sans repas issu du rythme, puis le remet.
        Psc_Planning::toggle_exception($cid, $mondays[0], 'MSR', false, true);
        $check(!$declared($mondays[0], 'MSR'), 'sans repas : retrait du midi (rythme) sans effet');
        $screens_match('sans repas, retrait rythme');
        Psc_Planning::toggle_exception($cid, $mondays[0], 'MSR', true, true);
        $check($declared($mondays[0], 'MSR'), 'sans repas : remise du midi (rythme) sans effet');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_exc WHERE child_id = %d AND jour_date = %s", $cid, $mondays[0])) === 0,
            'sans repas : retour au rythme laisse une exception résiduelle');

        // 4. Jours portant une ancienne exception de cantine.
        Psc_Planning::toggle_exception($cid, $tuesdays[0], 'MSR', false, true);
        $check(!$declared($tuesdays[0], 'MSR'), 'sans repas : retrait impossible sur un ancien ajout de cantine');
        Psc_Planning::toggle_exception($cid, $mondays[1], 'MSR', true, true);
        $check($declared($mondays[1], 'MSR'), 'sans repas : ajout impossible sur un ancien retrait de cantine');
        $screens_match('sans repas, anciennes exceptions');

        // 5. Un changement de rythme sur ce jour de semaine ne purge pas le
        //    retrait du midi posé par la famille.
        Psc_Planning::toggle_exception($cid, $mondays[0], 'MSR', false, true);
        Psc_Planning::toggle_pattern($cid, $year_key, 1, 'GS', true);
        $check(!$declared($mondays[0], 'MSR'), 'sans repas : changement de rythme efface le retrait du midi');
        $screens_match('sans repas, après changement de rythme');
        $set_csr(false);

        // 6. Retrait d'une garderie couverte par une exception forfait.
        $wpdb->delete($t_pat, array('child_id' => $cid));
        $wpdb->delete($t_exc, array('child_id' => $cid));
        $day = $tuesdays[1];
        Psc_Planning::toggle_exception($cid, $day, 'FORF', true, true);
        $check($declared($day, 'GM'), 'forfait exceptionnel : garderie du matin non couverte');
        Psc_Planning::toggle_exception($cid, $day, 'GM', false, true);
        $check(!$declared($day, 'GM'), 'forfait exceptionnel : retrait de la garderie non persistant');
        $screens_match('forfait exceptionnel');

        // 7. Ajout refusé sur une prestation fermée ; retrait toujours possible.
        $wpdb->delete($t_exc, array('child_id' => $cid));
        $closed_day = $tuesdays[1];
        $wpdb->insert($t_close, array('jour_date' => $closed_day, 'service' => 'GS', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')));
        $closure_id = (int) $wpdb->insert_id;
        $reset_caches();
        $prop = new ReflectionProperty('Psc_Planning', 'svc_closed_cache');
        $prop->setAccessible(true);
        $prop->setValue(null, array());
        $result = Psc_Planning::toggle_exception($cid, $closed_day, 'GS', true, true);
        $check($result['status'] === 'service_closed', 'prestation fermée : ajout accepté (' . $result['status'] . ')');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_exc WHERE child_id = %d AND jour_date = %s AND service_code = 'GS'", $cid, $closed_day)) === 0,
            'prestation fermée : exception écrite');
        $result = Psc_Planning::toggle_exception($cid, $closed_day, 'FORF', true, true);
        $check($result['status'] === 'service_closed', 'forfait sur prestation fermée : ajout accepté (' . $result['status'] . ')');
        $wpdb->delete($t_close, array('id' => $closure_id));
    } finally {
        $purge();
        $reset_caches();
    }

    if ($failures) {
        foreach (array_slice($failures, 0, 30) as $failure) WP_CLI::warning($failure);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Cases du planning : $checks vérifications.");
});
