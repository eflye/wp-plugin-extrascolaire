<?php
/**
 * Script de vérification autonome (P2-05) — l'import du calendrier
 * scolaire ne peut ni viser le réseau interne du serveur, ni avaler une
 * réponse démesurée ou aberrante, ni abîmer le calendrier en place. Même
 * rôle que bin/verify-promotion-logic.php.
 *
 * Aucune requête réseau réelle : les réponses HTTP sont simulées par le
 * filtre pre_http_request, la résolution DNS par psc_ics_resolved_ips.
 *
 * Usage :
 *   wp --require=bin/verify-ics-import.php verify-ics-import
 *
 * Seules les lignes d'un import de test (année courante + 5, libellé
 * dédié) sont écrites, puis supprimées ; l'option d'URL est restaurée.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-ics-import', function () {

    if (!class_exists('Psc_School_Calendar') || !method_exists('Psc_School_Calendar', 'validate_ics_url')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t = psc_table('school_calendar');
    $label = 'Vacances verify-ics';
    $year = (int) current_time('Y') + 5;
    $old_url = get_option('psc_school_calendar_ics_url', '');

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };
    $snapshot = function () use ($wpdb, $t) {
        return md5(wp_json_encode($wpdb->get_results("SELECT jour_date, label, is_closed, source FROM $t ORDER BY jour_date", ARRAY_N)));
    };
    $cleanup = function () use ($wpdb, $t, $label) {
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE label = %s", $label));
        Psc_School_Calendar::flush_closed_cache();
    };
    $ics = function (array $events) {
        $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
        foreach ($events as $e) {
            $out .= "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:{$e[0]}\r\nDTEND;VALUE=DATE:{$e[1]}\r\nSUMMARY:{$e[2]} - Zone C\r\nLOCATION:Zone C\r\nEND:VEVENT\r\n";
        }
        return $out . "END:VCALENDAR\r\n";
    };

    // Réseau simulé.
    add_filter('psc_ics_resolved_ips', function ($ips, $host) {
        $map = array(
            'calendrier.test' => array('93.184.216.34'),
            'rebond.test'     => array('93.184.216.35'),
            'interne.test'    => array('10.0.0.5'),
            'mixte.test'      => array('93.184.216.36', '192.168.1.10'),
        );
        return $map[$host] ?? $ips;
    }, 10, 2);
    $responses = array();
    add_filter('pre_http_request', function ($pre, $args, $url) use (&$responses) {
        if (!isset($responses[$url])) return new WP_Error('verify_ics', 'URL non simulée : ' . $url);
        list($code, $body, $location) = $responses[$url] + array(2 => null);
        if (!empty($args['limit_response_size']) && strlen($body) > $args['limit_response_size']) {
            $body = substr($body, 0, $args['limit_response_size']);
        }
        return array('response' => array('code' => $code, 'message' => ''), 'body' => $body,
                     'headers' => $location ? array('location' => $location) : array(), 'cookies' => array());
    }, 10, 3);

    $cleanup();
    $valid = $ics(array(array($year . '1019', $year . '1103', $label)));

    try {
        // 1. Adresses refusées dès la validation.
        $refused = array(
            'loopback IPv4'        => 'http://127.0.0.1/cal.ics',
            'localhost'            => 'http://localhost/cal.ics',
            'réseau privé'         => 'http://10.1.2.3/cal.ics',
            'métadonnées cloud'    => 'http://169.254.169.254/latest/meta-data',
            'IPv6 loopback'        => 'http://[::1]/cal.ics',
            'hôte interne résolu'  => 'https://interne.test/cal.ics',
            'une adresse interne sur deux' => 'https://mixte.test/cal.ics',
            'schéma file'          => 'file:///etc/passwd',
            'schéma ftp'           => 'ftp://calendrier.test/cal.ics',
            'port exotique'        => 'http://calendrier.test:6379/',
            'identifiants'         => 'https://user:pass@calendrier.test/cal.ics',
            'hôte introuvable'     => 'https://hote-inexistant.invalid/cal.ics',
        );
        foreach ($refused as $label_case => $url) {
            $check(is_wp_error(Psc_School_Calendar::validate_ics_url($url)), "URL acceptée : $label_case");
        }
        $check(Psc_School_Calendar::validate_ics_url('https://calendrier.test/cal.ics') === true, 'URL publique refusée');

        // 2. Imports refusés : le calendrier existant reste intact.
        $refused_imports = array(
            'redirection vers le réseau interne' => array('https://rebond.test/cal.ics', array(
                'https://rebond.test/cal.ics' => array(302, '', 'http://interne.test/secret'),
            )),
            'boucle de redirections' => array('https://rebond.test/a', array(
                'https://rebond.test/a' => array(302, '', 'https://rebond.test/b'),
                'https://rebond.test/b' => array(302, '', 'https://rebond.test/c'),
                'https://rebond.test/c' => array(302, '', 'https://rebond.test/d'),
                'https://rebond.test/d' => array(302, '', 'https://rebond.test/a'),
            )),
            'réponse trop volumineuse' => array('https://calendrier.test/gros.ics', array(
                'https://calendrier.test/gros.ics' => array(200, $valid . str_repeat('X', Psc_School_Calendar::ICS_MAX_BYTES)),
            )),
            'ICS invalide' => array('https://calendrier.test/faux.ics', array(
                'https://calendrier.test/faux.ics' => array(200, '<html>Pas un calendrier</html>'),
            )),
            'dates aberrantes' => array('https://calendrier.test/loin.ics', array(
                'https://calendrier.test/loin.ics' => array(200, $ics(array(array(($year + 20) . '0101', ($year + 20) . '0110', $label)))),
            )),
            'fermeture de 150 jours' => array('https://calendrier.test/long.ics', array(
                'https://calendrier.test/long.ics' => array(200, $ics(array(array($year . '0101', $year . '0531', $label)))),
            )),
            'réponse d’erreur' => array('https://calendrier.test/500.ics', array(
                'https://calendrier.test/500.ics' => array(500, 'Erreur'),
            )),
        );
        foreach ($refused_imports as $label_case => list($url, $map)) {
            update_option('psc_school_calendar_ics_url', $url);
            $responses = $map;
            $before = $snapshot();
            $result = Psc_School_Calendar::import();
            $check(is_wp_error($result), "import accepté : $label_case");
            $check($snapshot() === $before, "calendrier modifié malgré le refus : $label_case");
        }

        // Fichier téléversé trop gros.
        $before = $snapshot();
        $check(is_wp_error(Psc_School_Calendar::import_from_upload($valid . str_repeat('X', Psc_School_Calendar::ICS_MAX_BYTES))), 'téléversement trop gros accepté');
        $check($snapshot() === $before, 'calendrier modifié par un téléversement refusé');

        // 3. Import valide, y compris après une redirection publique.
        update_option('psc_school_calendar_ics_url', 'https://rebond.test/cal.ics');
        $responses = array(
            'https://rebond.test/cal.ics'     => array(301, '', 'https://calendrier.test/cal.ics'),
            'https://calendrier.test/cal.ics' => array(200, $valid),
        );
        $result = Psc_School_Calendar::import();
        $check(!is_wp_error($result) && $result === 15, 'import valide refusé ou incomplet : ' . (is_wp_error($result) ? $result->get_error_code() : $result));
        $check((bool) Psc_School_Calendar::is_closed($year . '-10-25'), 'import valide : jour de vacances non fermé');
    } finally {
        update_option('psc_school_calendar_ics_url', $old_url);
        $cleanup();
    }

    if ($failures) {
        foreach ($failures as $failure) WP_CLI::warning($failure);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Import du calendrier : $checks vérifications.");
});
