<?php
if (!defined('ABSPATH')) exit;

/**
 * Calendrier scolaire officiel (zone C), chargé depuis le flux iCal du
 * ministère de l'Éducation nationale. Remplace toute logique de vacances
 * codée en dur : la mairie charge/actualise elle-même le calendrier, et
 * peut corriger un jour ponctuel (formation des enseignants, fermeture
 * exceptionnelle...) sans toucher au code.
 */
class Psc_School_Calendar {

    const ICS_URL = 'https://fr.ftp.opendatasoft.com/openscol/fr-en-calendrier-scolaire/Zone-A-B-C-Corse.ics';

    /** Tous les jours enregistrés (fermés ou réouverts manuellement), triés. */
    public static function all() {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . psc_table('school_calendar') . ' ORDER BY jour_date');
    }

    public static function is_closed($date_str) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT is_closed FROM ' . psc_table('school_calendar') . ' WHERE jour_date = %s',
            $date_str
        ));
        return $row ? (bool) $row->is_closed : false;
    }

    public static function label($date_str) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT is_closed, label FROM ' . psc_table('school_calendar') . ' WHERE jour_date = %s',
            $date_str
        ));
        return ($row && $row->is_closed) ? $row->label : false;
    }

    /**
     * Télécharge et importe le calendrier officiel zone C. N'écrase jamais
     * une correction manuelle (source = 'manual'). Retourne le nombre de
     * jours importés/mis à jour, ou WP_Error.
     */
    public static function import() {
        $response = wp_remote_get(self::ICS_URL, array('timeout' => 20));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('psc_ics_http', "Le serveur du ministère a répondu $code.");
        }
        $body = wp_remote_retrieve_body($response);
        if (!$body) {
            return new WP_Error('psc_ics_empty', 'Réponse vide.');
        }

        $closed_days = self::parse_ics($body);
        if (is_wp_error($closed_days)) {
            return $closed_days;
        }

        global $wpdb;
        $t     = psc_table('school_calendar');
        $count = 0;
        $now   = current_time('mysql');

        foreach ($closed_days as $date => $label) {
            $existing_source = $wpdb->get_var($wpdb->prepare("SELECT source FROM $t WHERE jour_date = %s", $date));
            if ($existing_source === 'manual') continue; // ne jamais écraser une correction manuelle

            $wpdb->query($wpdb->prepare(
                "INSERT INTO $t (jour_date, label, is_closed, source, created_at, updated_at)
                 VALUES (%s, %s, 1, 'import', %s, %s)
                 ON DUPLICATE KEY UPDATE label = VALUES(label), is_closed = 1, source = 'import', updated_at = VALUES(updated_at)",
                $date, $label, $now, $now
            ));
            $count++;
        }

        update_option('psc_school_calendar_imported_at', $now);

        return $count;
    }

    /**
     * Parse le flux iCal et renvoie un tableau [date Y-m-d => libellé] des
     * jours sans école pour la zone C.
     *
     * Convention du flux (vérifiée empiriquement : les événements
     * "Vacances d'Hiver"/"Printemps" s'enchaînent zone par zone sans
     * trou — le DTSTART d'un segment est exactement le DTEND du
     * précédent) : DTEND est EXCLUSIF (c'est le jour de reprise, un jour
     * d'école, pas un jour de vacances) pour les événements multi-jours.
     * Exception : les événements ponctuels où DTSTART = DTEND (ex. Pont
     * de l'Ascension) désignent un unique jour bien fermé.
     */
    public static function parse_ics($ics) {
        if (!preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $matches)) {
            return new WP_Error('psc_ics_parse', 'Aucun événement trouvé dans le flux.');
        }

        $closed = array();
        foreach ($matches[1] as $block) {
            if (!preg_match('/DTSTART;VALUE=DATE:(\d{8})/', $block, $m_start)) continue;
            if (!preg_match('/DTEND;VALUE=DATE:(\d{8})/', $block, $m_end)) continue;
            if (!preg_match('/SUMMARY:(.+)/', $block, $m_sum)) continue;
            if (!preg_match('/LOCATION:(.+)/', $block, $m_loc)) continue;

            $summary  = trim($m_sum[1]);
            $location = trim($m_loc[1]);

            // Marqueurs purement informatifs (doublon de l'événement plage
            // correspondant) et journées "prérentrée enseignants" (ne
            // concernent pas les élèves, donc pas le périscolaire).
            if (stripos($summary, 'prérentrée') !== false) continue;
            if (stripos($summary, 'Début des') === 0) continue;

            $zone_part = preg_replace('/ - Corse$/', '', $location);
            if ($zone_part === 'Corse' || $zone_part === '') continue;
            $zones = explode('/', preg_replace('/^Zones? /', '', $zone_part));
            if (!in_array('C', $zones, true)) continue;

            $start = self::ics_date_to_ymd($m_start[1]);
            $end   = self::ics_date_to_ymd($m_end[1]);
            if (!$start || !$end) continue;

            $label = trim(preg_replace('/ - Zones?.*/', '', $summary));

            if ($start === $end) {
                $closed[$start] = $label;
                continue;
            }

            $cursor = new DateTime($start);
            $end_dt = new DateTime($end);
            $end_dt->modify('-1 day'); // DTEND exclusif
            if ($cursor > $end_dt) continue;
            while ($cursor <= $end_dt) {
                $closed[$cursor->format('Y-m-d')] = $label;
                $cursor->modify('+1 day');
            }
        }

        return $closed;
    }

    private static function ics_date_to_ymd($ics_date) {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ics_date, $m)) return false;
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    /* ------------------------------------------------------------------
     * Bascule manuelle d'un jour (formation des enseignants, correction…)
     * ------------------------------------------------------------------ */

    /**
     * Familles ayant une inscription déclarée à cette date — utilisé pour
     * avertir l'admin avant une fermeture manuelle (elle supprime ces
     * inscriptions et notifie les familles).
     */
    public static function affected_families($date_str) {
        global $wpdb;
        $t_reg   = psc_table('registrations');
        $t_child = psc_table('children');
        $t_par   = psc_table('parents');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.service, c.nom AS child_nom, c.prenom AS child_prenom, p.id AS parent_id, p.email, p.nom AS parent_nom
             FROM $t_reg r
             JOIN $t_child c ON c.id = r.child_id
             JOIN $t_par p ON p.id = c.parent_id
             WHERE r.jour_date = %s
             ORDER BY p.email, c.nom",
            $date_str
        ));

        $by_family = array();
        foreach ($rows as $r) {
            if (!isset($by_family[$r->parent_id])) {
                $by_family[$r->parent_id] = array(
                    'email' => $r->email,
                    'nom'   => $r->parent_nom,
                    'items' => array(),
                );
            }
            $by_family[$r->parent_id]['items'][] = $r;
        }

        return array(
            'registrations' => count($rows),
            'families'      => $by_family,
        );
    }

    /**
     * Ferme manuellement un jour : marque le jour dans le calendrier
     * scolaire ET dans tous les trimestres qui le couvrent, supprime les
     * inscriptions déjà déclarées ce jour-là (elles ne doivent pas être
     * facturées) et notifie les familles concernées par e-mail.
     */
    public static function close_day($date_str, $label) {
        $date_str = psc_valid_date($date_str);
        if (!$date_str) return new WP_Error('invalid_date', 'Date invalide.');
        $label = $label !== '' ? sanitize_text_field($label) : 'Fermeture exceptionnelle';

        $affected = self::affected_families($date_str);

        global $wpdb;
        $t   = psc_table('school_calendar');
        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $t (jour_date, label, is_closed, source, created_at, updated_at)
             VALUES (%s, %s, 1, 'manual', %s, %s)
             ON DUPLICATE KEY UPDATE label = VALUES(label), is_closed = 1, source = 'manual', updated_at = VALUES(updated_at)",
            $date_str, $label, $now, $now
        ));

        self::apply_to_calendar_days($date_str, 0, $label);

        if (!empty($affected['families'])) {
            foreach ($affected['families'] as $fam) {
                Psc_Mailer::send_day_closed($fam, $date_str, $label);
            }
            $t_reg = psc_table('registrations');
            $wpdb->query($wpdb->prepare("DELETE FROM $t_reg WHERE jour_date = %s", $date_str));
        }

        return $affected;
    }

    /** Réouvre un jour (annule une fermeture, import ou manuelle). */
    public static function open_day($date_str) {
        $date_str = psc_valid_date($date_str);
        if (!$date_str) return new WP_Error('invalid_date', 'Date invalide.');

        global $wpdb;
        $t   = psc_table('school_calendar');
        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $t (jour_date, label, is_closed, source, created_at, updated_at)
             VALUES (%s, NULL, 0, 'manual', %s, %s)
             ON DUPLICATE KEY UPDATE label = NULL, is_closed = 0, source = 'manual', updated_at = VALUES(updated_at)",
            $date_str, $now, $now
        ));

        // Ne réouvre le calendrier des trimestres que si ce n'est ni un
        // week-end, ni un mercredi, ni un jour férié.
        if (!psc_is_weekend($date_str) && !psc_is_wednesday($date_str) && !psc_is_holiday($date_str)) {
            self::apply_to_calendar_days($date_str, 1, null);
        }

        return true;
    }

    private static function apply_to_calendar_days($date_str, $is_open, $label) {
        global $wpdb;
        $t_days = psc_table('calendar_days');
        $wpdb->query($wpdb->prepare(
            "UPDATE $t_days SET is_open = %d, label = %s WHERE jour_date = %s",
            $is_open, $label, $date_str
        ));
    }
}
