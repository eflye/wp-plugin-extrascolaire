<?php
/**
 * Script de vérification autonome — calendrier scolaire : fermeture d'une
 * plage par la mairie et import ICS (épinglage DNS, requêtes groupées,
 * corrections manuelles prioritaires — règle n°7), en conditions WP-CLI
 * réelles. Aucun e-mail ne part (pre_wp_mail), aucune requête réseau
 * (pre_http_request). Chaque section crée puis supprime ses données.
 *
 * Origine : audit du 14/10/2026, lot 3 (script scindé par domaine avec
 * verify-notifications.php et verify-performance.php). Les références
 * renvoient à AUDIT.md. L'import
 * ICS lui-même (réseau interne, réponses aberrantes) est couvert par
 * bin/verify-ics-import.php.
 *
 * Vérifie :
 *  - PERF-01 : fermeture d'une plage en transaction (rien n'est fermé ni
 *    notifié si l'écriture échoue), un récapitulatif par famille, envoi
 *    hors requête, fermeture conservée sans donnée personnelle, relance ;
 *  - SEC-11 : l'adresse validée est épinglée pour la connexion (pas de
 *    seconde résolution DNS) ;
 *  - PERF-11 : import ICS en requêtes groupées, corrections manuelles
 *    prioritaires sur l'import.
 *
 * Usage :
 *   wp --require=bin/verify-school-calendar.php verify-school-calendar
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-school-calendar', function () {

    if (!class_exists('Psc_Mailer')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    // Capture des e-mails : aucun envoi réel.
    $mails = array();
    $capture = function ($null, $atts) use (&$mails) { $mails[] = $atts; return true; };
    add_filter('pre_wp_mail', $capture, 10, 2);

    $sections = array();

    /* PERF-01 : fermeture d'une plage en transaction, un récapitulatif par famille, hors requête. */
    $sections['PERF-01'] = function () use ($check, &$mails) {
        global $wpdb;
        $t_par = psc_table('parents');
        $t_ch  = psc_table('children');
        $t_cal = psc_table('school_calendar');
        $t_env = psc_table('envois');
        $emails = array('verify-school-calendar-ferm-a@example.invalid', 'verify-school-calendar-ferm-b@example.invalid');
        $year_key = '2085-2086';
        $purge = function () use ($wpdb, $emails, $t_par, $t_ch, $t_cal, $t_env, $year_key) {
            foreach ($emails as $email) {
                $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_par WHERE email = %s", $email));
                if (!$pid) continue;
                foreach ($wpdb->get_col($wpdb->prepare("SELECT DISTINCT objet_id FROM $t_env WHERE famille_id = %d AND objet_type IN ('fermeture','fermeture_service','forfait_declasse')", $pid)) as $oid) {
                    delete_option('psc_fermeture_' . (int) $oid);
                }
                foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_ch WHERE parent_id = %d", $pid)) as $cid) {
                    Psc_Planning::delete_for_child((int) $cid);
                    $wpdb->delete(psc_table('child_school_years'), array('child_id' => (int) $cid));
                }
                $wpdb->delete($t_ch, array('parent_id' => $pid));
                $wpdb->delete($t_par, array('id' => $pid));
            }
            $wpdb->query("DELETE FROM $t_cal WHERE jour_date BETWEEN '2085-09-01' AND '2086-07-31'");
            $year = Psc_School_Years::get_by_key($year_key);
            if ($year && $year->statut !== 'active') Psc_School_Years::delete((int) $year->id);
            Psc_School_Calendar::flush_closed_cache();
            Psc_Planning::flush_cache();
        };
        $purge();
        try {
            $y = Psc_School_Years::ensure('2085-09-03', '2086-07-03');
            Psc_School_Year::save($year_key, '2085-09-03', '2086-07-03', '[]', 48);
            $pids = array();
            foreach ($emails as $i => $email) {
                $pid = (int) Psc_Parents::create($email, 'Fermeture');
                $wpdb->insert($t_ch, array('parent_id' => $pid, 'nom' => 'Fermeture', 'prenom' => 'Enfant' . $i, 'created_at' => current_time('mysql')));
                $cid = (int) $wpdb->insert_id;
                Psc_School_Years::enroll($cid, $y, 'CE1');
                Psc_Planning::toggle_pattern($cid, $year_key, 1, 'GM', true); // lundi
                Psc_Planning::toggle_pattern($cid, $year_key, 2, 'CANT', true); // mardi
                $pids[$email] = $pid;
            }
            Psc_Planning::flush_cache();
            Psc_School_Year::flush_cache();

            // Deux semaines : 2 lundis + 2 mardis par famille.
            $debut = '2085-10-08';
            $fin   = '2085-10-19';
            $check(date('N', strtotime($debut)) === '1', 'PERF-01 : jeu de dates à revoir (le début doit être un lundi)');

            // Échec d'écriture : rien n'est fermé, personne n'est prévenu.
            $sabotage = function ($sql) use ($t_cal) { return (strpos($sql, "INSERT INTO $t_cal") === 0) ? 'SELECT * FROM psc_table_inexistante' : $sql; };
            add_filter('query', $sabotage);
            $suppress = $wpdb->suppress_errors(true);
            $mails = array();
            $r = Psc_School_Calendar::close_range($debut, $fin, 'Vérif');
            $wpdb->suppress_errors($suppress);
            remove_filter('query', $sabotage);
            $check(is_wp_error($r), 'PERF-01 : échec d\'écriture non signalé');
            $check((int) $wpdb->get_var("SELECT COUNT(*) FROM $t_cal WHERE jour_date BETWEEN '$debut' AND '$fin'") === 0, 'PERF-01 : jours fermés malgré l\'échec');
            $check(!$mails, 'PERF-01 : familles prévenues d\'une fermeture non enregistrée');

            // Fermeture réelle.
            $mails = array();
            $queries = $wpdb->num_queries;
            $r = Psc_School_Calendar::close_range($debut, $fin, 'Vérif fermeture');
            $check(!is_wp_error($r) && $r['days'] === 12, 'PERF-01 : ' . (is_wp_error($r) ? $r->get_error_code() : $r['days']) . ' jours au lieu de 12');
            $closed = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_cal WHERE jour_date BETWEEN '$debut' AND '$fin' AND is_closed = 1 AND source = 'manual'");
            $check($closed === 12, "PERF-01 : $closed jours marqués fermés au lieu de 12");
            $check(Psc_School_Calendar::is_closed($debut), 'PERF-01 : jour non vu fermé (cache)');
            $check($wpdb->num_queries - $queries < 60, 'PERF-01 : ' . ($wpdb->num_queries - $queries) . ' requêtes pour fermer 12 jours');

            foreach ($emails as $email) {
                $mine = array_values(array_filter($mails, function ($m) use ($email) { return in_array($email, (array) $m['to'], true); }));
                $check(count($mine) === 1, "PERF-01 : $email reçoit " . count($mine) . ' e-mail(s) au lieu d\'un récapitulatif');
                if ($mine) {
                    $i = array_search($email, $emails, true);
                    $rows = substr_count((string) $mine[0]['message'], 'Enfant' . $i . ' Fermeture</td>');
                    $check($rows === 4, "PERF-01 : récapitulatif de $email avec $rows ligne(s) au lieu de 4");
                    $check(strpos((string) $mine[0]['subject'], date_i18n('d/m/Y', strtotime($debut))) !== false, 'PERF-01 : période absente du sujet');
                }
            }
            $bilan = $r['bilan'];
            $check($bilan && $bilan['total'] === 2 && $bilan[Psc_Envois::ACCEPTE] === 2, 'PERF-01 : bilan des envois ' . wp_json_encode($bilan));
            $oid = (int) $wpdb->get_var($wpdb->prepare("SELECT objet_id FROM $t_env WHERE objet_type = 'fermeture' AND lot = %s LIMIT 1", $bilan ? $bilan['lot'] : ''));
            $stored = Psc_School_Calendar::get_closure($oid);
            $check($stored && $stored['debut'] === $debut && $stored['fin'] === $fin, 'PERF-01 : fermeture à notifier non conservée');
            $check($stored && strpos(wp_json_encode($stored), 'example.invalid') === false && strpos(wp_json_encode($stored), 'Fermeture') === false,
                'PERF-01 : la fermeture conservée contient une adresse ou un nom');
            $autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'psc_fermeture_' . $oid));
            $check(in_array($autoload, array('no', 'off'), true), 'PERF-01 : fermeture conservée en option chargée d\'office');

            // Correction manuelle prioritaire : la plage fermée est marquée manual.
            // Un renvoi via Psc_Envois (relance) relit la fermeture conservée.
            $wpdb->update($t_env, array('statut' => Psc_Envois::ECHEC), array('objet_type' => 'fermeture', 'objet_id' => $oid));
            $mails = array();
            $b2 = Psc_Envois::relancer_echecs('fermeture', $oid);
            $check(count($mails) === 2 && $b2[Psc_Envois::ACCEPTE] === 2, 'PERF-01 : relance d\'une fermeture inopérante');
        } finally {
            $purge();
        }
    };

    /* SEC-11 : l'adresse validée est épinglée pour la connexion (pas de seconde résolution). */
    $sections['SEC-11'] = function () use ($check) {
        $host = 'ics-pin.school-calendar.invalid';
        $resolve = function ($ips, $h) use ($host) { return $h === $host ? array('2001:4860:4860::8888', '8.8.8.8') : $ips; };
        add_filter('psc_ics_resolved_ips', $resolve, 10, 2);
        $old_url = get_option('psc_school_calendar_ics_url', null);
        $seen = array();
        $short = function ($pre, $args, $url) use (&$seen) {
            $seen[] = array($url, has_action('http_api_curl') !== false);
            return array('headers' => array(), 'body' => '', 'response' => array('code' => 500, 'message' => 'x'), 'cookies' => array(), 'filename' => null);
        };
        add_filter('pre_http_request', $short, 10, 3);
        try {
            $check(Psc_School_Calendar::validate_ics_url("https://$host/cal.ics") === true, 'SEC-11 : hôte public simulé refusé');
            $check(Psc_School_Calendar::curl_resolve_entry("https://$host/cal.ics") === "$host:443:8.8.8.8", 'SEC-11 : entrée CURLOPT_RESOLVE inattendue « ' . Psc_School_Calendar::curl_resolve_entry("https://$host/cal.ics") . ' »');
            $check(Psc_School_Calendar::curl_resolve_entry("http://$host/cal.ics") === "$host:80:8.8.8.8", 'SEC-11 : port http non pris en compte');
            $check(Psc_School_Calendar::curl_resolve_entry('https://8.8.8.8/cal.ics') === null, 'SEC-11 : adresse IP littérale épinglée inutilement');
            $check(Psc_School_Calendar::curl_resolve_entry('https://jamais-valide.invalid/cal.ics') === null, 'SEC-11 : hôte non validé épinglé');
            update_option('psc_school_calendar_ics_url', "https://$host/cal.ics");
            Psc_School_Calendar::import();
            $check(count($seen) === 1 && $seen[0][0] === "https://$host/cal.ics" && $seen[0][1] === true, 'SEC-11 : épinglage non branché pendant le téléchargement');
            $check(has_action('http_api_curl') === false || !$seen, 'SEC-11 : épinglage resté branché après le téléchargement');
        } finally {
            remove_filter('psc_ics_resolved_ips', $resolve, 10);
            remove_filter('pre_http_request', $short, 10);
            if ($old_url === null) delete_option('psc_school_calendar_ics_url'); else update_option('psc_school_calendar_ics_url', $old_url);
        }
    };

    /* PERF-11 : import ICS en requêtes groupées, corrections manuelles prioritaires (règle n°7). */
    $sections['PERF-11'] = function () use ($check) {
        global $wpdb;
        $t = psc_table('school_calendar');
        $year = (int) current_time('Y') + 4;
        $label = 'Vacances verify-school-calendar';
        $cleanup = function () use ($wpdb, $t, $year) {
            $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE jour_date BETWEEN %s AND %s", "$year-02-01", "$year-03-31"));
            Psc_School_Calendar::flush_closed_cache();
        };
        $cleanup();
        try {
            // Correction manuelle : jour rouvert au milieu des vacances, et
            // jour fermé avec un autre libellé.
            $now = current_time('mysql');
            $wpdb->insert($t, array('jour_date' => "$year-02-20", 'label' => null, 'is_closed' => 0, 'source' => 'manual', 'created_at' => $now, 'updated_at' => $now));
            $wpdb->insert($t, array('jour_date' => "$year-02-21", 'label' => 'Manuel school-calendar', 'is_closed' => 1, 'source' => 'manual', 'created_at' => $now, 'updated_at' => $now));
            // Jour déjà importé : mis à jour.
            $wpdb->insert($t, array('jour_date' => "$year-02-22", 'label' => 'Ancien import', 'is_closed' => 1, 'source' => 'import', 'created_at' => $now, 'updated_at' => $now));
            $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:{$year}0214\r\nDTEND;VALUE=DATE:{$year}0302\r\nSUMMARY:$label - Zone C\r\nLOCATION:Zone C\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
            $expected = count(Psc_School_Calendar::parse_ics($ics));
            $q = $wpdb->num_queries;
            $n = Psc_School_Calendar::import_from_upload($ics);
            $used = $wpdb->num_queries - $q;
            $check($n === $expected - 2, 'PERF-11 : ' . (is_wp_error($n) ? $n->get_error_code() : $n) . " jours importés au lieu de " . ($expected - 2));
            $check($used <= 8, "PERF-11 : $used requêtes pour importer $expected jours");
            $row = function ($d) use ($wpdb, $t) { return $wpdb->get_row($wpdb->prepare("SELECT label, is_closed, source FROM $t WHERE jour_date = %s", $d)); };
            $r = $row("$year-02-20");
            $check($r && (int) $r->is_closed === 0 && $r->source === 'manual', 'PERF-11 : réouverture manuelle écrasée par l\'import');
            $r = $row("$year-02-21");
            $check($r && $r->label === 'Manuel school-calendar' && $r->source === 'manual', 'PERF-11 : fermeture manuelle écrasée par l\'import');
            $r = $row("$year-02-22");
            $check($r && $r->source === 'import' && strpos((string) $r->label, $label) === 0, 'PERF-11 : jour importé non mis à jour');
            $check(Psc_School_Calendar::is_closed("$year-02-16") && !Psc_School_Calendar::is_closed("$year-02-20"), 'PERF-11 : fermetures lues incohérentes');
        } finally {
            $cleanup();
        }
    };

    try {
        foreach ($sections as $name => $run) {
            $run();
        }
    } finally {
        remove_filter('pre_wp_mail', $capture, 10);
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(sprintf('%d/%d vérifications en échec.', count($failures), $checks));
    }
    WP_CLI::success(sprintf('%d vérifications OK (%s).', $checks, implode(', ', array_keys($sections))));
});
