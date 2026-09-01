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

    /**
     * Cache des lectures de is_closed(), pour la durée de la requête PHP.
     * is_closed() est la lecture la plus chaude du plugin : chaque
     * psc_is_school_day() y passe, psc_open_days() l'appelle pour chaque
     * jour de la semaine visée, et psc_next_open_week() balaye les
     * semaines une par une — l'écran calendrier v2 documente déjà ce
     * coût par date (cf. classify_closed_day(), qui pré-charge sa propre
     * grille pour la même raison). L'état d'un jour ne peut pas changer
     * au fil d'une même requête sauf écriture explicite, qui vide le
     * cache (flush_closed_cache()).
     */
    private static $closed_cache = array();

    /** URL effectivement utilisée pour le chargement automatique (réglable dans Réglages). */
    public static function ics_url() {
        $custom = get_option('psc_school_calendar_ics_url', '');
        return $custom !== '' ? $custom : self::ICS_URL;
    }

    /** Tous les jours enregistrés (fermés ou réouverts manuellement), triés. */
    public static function all() {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . psc_table('school_calendar') . ' ORDER BY jour_date');
    }

    public static function is_closed($date_str) {
        if (!isset(self::$closed_cache[$date_str])) {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT is_closed FROM ' . psc_table('school_calendar') . ' WHERE jour_date = %s',
                $date_str
            ));
            self::$closed_cache[$date_str] = $row ? (bool) $row->is_closed : false;
        }
        return self::$closed_cache[$date_str];
    }

    /**
     * Vide le cache de is_closed(). Appelé après chaque écriture de la
     * table : un même traitement PHP ne doit jamais lire l'état périmé
     * d'un jour qu'il vient de fermer ou de rouvrir.
     */
    public static function flush_closed_cache() {
        self::$closed_cache = array();
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
        $response = wp_remote_get(self::ics_url(), array('timeout' => 20));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('psc_ics_http', sprintf(__('Le serveur du ministère a répondu %s.', 'periscolaire-registration'), $code));
        }
        $body = wp_remote_retrieve_body($response);
        if (!$body) {
            return new WP_Error('psc_ics_empty', __('Réponse vide.', 'periscolaire-registration'));
        }

        return self::import_ics_content($body);
    }

    /**
     * Importe un fichier .ics fourni manuellement par l'admin (contournement
     * en cas d'accès Internet sortant indisponible depuis le serveur).
     */
    public static function import_from_upload($body) {
        if (!$body) {
            return new WP_Error('psc_ics_empty', __('Fichier vide.', 'periscolaire-registration'));
        }
        return self::import_ics_content($body);
    }

    /** Logique d'import commune, que le contenu iCal vienne du réseau ou d'un upload. */
    private static function import_ics_content($body) {
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
        self::flush_closed_cache();

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
            return new WP_Error('psc_ics_parse', __('Aucun événement trouvé dans le flux.', 'periscolaire-registration'));
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

    /**
     * Années scolaires candidates déduites du calendrier importé : le flux
     * ICS du ministère ne contient aucune date explicite de rentrée/fin
     * d'année (seulement des périodes de vacances), donc une année scolaire
     * est ici définie comme l'intervalle entre deux étés consécutifs.
     * Ne crée rien : sert uniquement à pré-remplir le formulaire "Créer une
     * année scolaire" (l'admin garde la main sur la création elle-même).
     */
    public static function candidate_school_years() {
        global $wpdb;
        $t = psc_table('school_calendar');
        $rows = $wpdb->get_results(
            "SELECT jour_date, label FROM $t WHERE is_closed = 1 ORDER BY jour_date"
        );

        // Regroupe les jours consécutifs de même libellé en périodes
        // (même principe que Psc_Admin::group_closed_days(), réimplémenté
        // ici pour ne pas créer de dépendance entre les deux classes).
        $periods = array();
        $current = null;
        foreach ($rows as $r) {
            $is_next_day = $current && (strtotime($r->jour_date) - strtotime($current['end'])) === DAY_IN_SECONDS;
            if ($current && $is_next_day && $r->label === $current['label']) {
                $current['end'] = $r->jour_date;
            } else {
                if ($current) $periods[] = $current;
                $current = array('start' => $r->jour_date, 'end' => $r->jour_date, 'label' => $r->label);
            }
        }
        if ($current) $periods[] = $current;

        $summers = array_values(array_filter($periods, function ($p) {
            return strpos(remove_accents(mb_strtolower($p['label'])), 'ete') !== false;
        }));

        // Deux segments "vacances d'été" proches l'un de l'autre (quelques
        // jours à quelques semaines d'écart) sont presque certainement UN
        // SEUL été interrompu — par exemple des jours rouverts manuellement
        // au milieu de l'été, ou un import partiel — et non deux étés
        // d'années scolaires différentes (qui sont eux distants d'environ
        // 11 mois). On les fusionne avant de calculer les candidats, sous
        // peine de générer une fausse "année scolaire" de quelques jours.
        $merged_summers = array();
        foreach ($summers as $s) {
            $n = count($merged_summers);
            if ($n > 0 && (strtotime($s['start']) - strtotime($merged_summers[$n - 1]['end'])) <= 45 * DAY_IN_SECONDS) {
                $merged_summers[$n - 1]['end'] = $s['end'];
            } else {
                $merged_summers[] = $s;
            }
        }
        $summers = $merged_summers;

        $candidates = array();
        for ($i = 0; $i < count($summers) - 1; $i++) {
            $date_debut = gmdate('Y-m-d', strtotime($summers[$i]['end'] . ' +1 day'));
            $date_fin   = gmdate('Y-m-d', strtotime($summers[$i + 1]['start'] . ' -1 day'));

            // Garde-fou : une vraie année scolaire dure plusieurs mois. Si
            // l'écart calculé est bien plus court, ce n'est pas une année
            // mais un résidu de données (à ne pas proposer à l'admin).
            if ((strtotime($date_fin) - strtotime($date_debut)) < 150 * DAY_IN_SECONDS) {
                continue;
            }

            $candidates[] = array(
                'label'      => gmdate('Y', strtotime($date_debut)) . '-' . gmdate('Y', strtotime($date_fin)),
                'date_debut' => $date_debut,
                'date_fin'   => $date_fin,
            );
        }

        return $candidates;
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
     * Familles ayant une inscription déclarée sur une plage de dates —
     * même usage que affected_families() mais pour une fermeture de
     * plusieurs jours d'un coup (vacances, fermeture exceptionnelle...).
     */
    public static function affected_families_range($date_debut, $date_fin) {
        global $wpdb;
        $t_reg   = psc_table('registrations');
        $t_child = psc_table('children');
        $t_par   = psc_table('parents');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.service, r.jour_date, c.nom AS child_nom, c.prenom AS child_prenom, p.id AS parent_id, p.email, p.nom AS parent_nom
             FROM $t_reg r
             JOIN $t_child c ON c.id = r.child_id
             JOIN $t_par p ON p.id = c.parent_id
             WHERE r.jour_date BETWEEN %s AND %s
             ORDER BY p.email, r.jour_date, c.nom",
            $date_debut, $date_fin
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
        if (!$date_str) return new WP_Error('invalid_date', __('Date invalide.', 'periscolaire-registration'));
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

        self::flush_closed_cache();
        return $affected;
    }

    /**
     * Ferme manuellement une plage de dates (vacances scolaires, fermeture
     * exceptionnelle...), en appliquant close_day() jour par jour : chaque
     * jour est marqué fermé dans le calendrier scolaire et dans tous les
     * trimestres qui le couvrent, les inscriptions déjà déclarées sont
     * supprimées et les familles concernées notifiées par e-mail.
     */
    public static function close_range($date_debut, $date_fin, $label) {
        $date_debut = psc_valid_date($date_debut);
        $date_fin   = psc_valid_date($date_fin);
        if (!$date_debut || !$date_fin || strtotime($date_fin) < strtotime($date_debut)) {
            return new WP_Error('invalid_date', __('Dates invalides.', 'periscolaire-registration'));
        }

        $start = new DateTime($date_debut);
        $end   = new DateTime($date_fin);
        $end->modify('+1 day');
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);

        $count = 0;
        foreach ($period as $d) {
            if (++$count > psc_max_trimestre_days()) break;
            self::close_day($d->format('Y-m-d'), $label);
        }
        return $count;
    }

    /** Réouvre un jour (annule une fermeture, import ou manuelle). */
    public static function open_day($date_str) {
        $date_str = psc_valid_date($date_str);
        if (!$date_str) return new WP_Error('invalid_date', __('Date invalide.', 'periscolaire-registration'));

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

        self::flush_closed_cache();
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

    /* ------------------------------------------------------------------
     * Fermeture manuelle d'une seule prestation (garderie matin, cantine,
     * garderie soir) pour un jour donné, indépendamment de la fermeture du
     * jour entier — utilisée par le calendrier scolaire v2.
     * ------------------------------------------------------------------ */


    public static function is_service_closed($date_str, $service) {
        global $wpdb;
        $t = psc_table('service_closures');
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE jour_date = %s AND service = %s",
            $date_str, $service
        ));
    }

    /** Codes des prestations fermées ce jour-là (ex. ['GM']). */
    public static function closed_services_for_date($date_str) {
        global $wpdb;
        $t = psc_table('service_closures');
        return $wpdb->get_col($wpdb->prepare(
            "SELECT service FROM $t WHERE jour_date = %s",
            $date_str
        ));
    }

    /**
     * Familles concernées par la fermeture d'une seule prestation ce
     * jour-là, séparées en deux groupes : les inscriptions directes de
     * cette prestation (seront supprimées) et les inscriptions en Forfait
     * journée (seront converties vers les prestations restantes par
     * close_service()).
     */
    public static function affected_families_for_service($date_str, $service) {
        return array(
            'direct' => self::families_by_service($date_str, $service),
            'forf'   => self::families_by_service($date_str, 'FORF'),
        );
    }

    private static function families_by_service($date_str, $service) {
        global $wpdb;
        $t_reg   = psc_table('registrations');
        $t_child = psc_table('children');
        $t_par   = psc_table('parents');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.child_id, r.trimestre_id, r.service, c.nom AS child_nom, c.prenom AS child_prenom, p.id AS parent_id, p.email, p.nom AS parent_nom
             FROM $t_reg r
             JOIN $t_child c ON c.id = r.child_id
             JOIN $t_par p ON p.id = c.parent_id
             WHERE r.jour_date = %s AND r.service = %s
             ORDER BY p.email, c.nom",
            $date_str, $service
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
     * Ferme une seule prestation (GM/CANT/GS) pour un jour donné,
     * indépendamment du reste de la journée : supprime les inscriptions
     * directes de cette prestation (non facturées, familles notifiées) et
     * convertit chaque inscription en Forfait journée vers les prestations
     * restantes — le forfait n'est jamais facturé "moins un service"
     * (class-psc-invoices.php facture registrations.service tel quel), donc
     * le convertir en inscriptions individuelles au moment de la fermeture
     * est la seule façon de refléter correctement la fermeture en facturation.
     */
    public static function close_service($date_str, $service, $label) {
        $date_str = psc_valid_date($date_str);
        if (!$date_str) return new WP_Error('invalid_date', __('Date invalide.', 'periscolaire-registration'));
        if (!in_array($service, psc_unit_services(), true)) {
            return new WP_Error('invalid_service', __('Prestation invalide.', 'periscolaire-registration'));
        }
        $label = $label !== '' ? sanitize_text_field($label) : 'Fermeture exceptionnelle';

        $affected = self::affected_families_for_service($date_str, $service);

        global $wpdb;
        $t_svc = psc_table('service_closures');
        $now   = current_time('mysql');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $t_svc (jour_date, service, label, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE label = VALUES(label), updated_at = VALUES(updated_at)",
            $date_str, $service, $label, $now, $now
        ));

        $services      = psc_services();
        $service_label = isset($services[$service]) ? $services[$service]['label'] : $service;
        $t_reg         = psc_table('registrations');

        if (!empty($affected['direct']['families'])) {
            foreach ($affected['direct']['families'] as $fam) {
                Psc_Mailer::send_service_closed($fam, $date_str, $service_label, $label);
            }
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $t_reg WHERE jour_date = %s AND service = %s",
                $date_str, $service
            ));
        }

        if (!empty($affected['forf']['families'])) {
            $closed_now = self::closed_services_for_date($date_str);
            $remaining  = array_diff(psc_unit_services(), array($service), $closed_now);

            $remaining_labels = array();
            foreach ($remaining as $code) {
                $remaining_labels[] = isset($services[$code]) ? $services[$code]['label'] : $code;
            }

            foreach ($affected['forf']['families'] as $fam) {
                foreach ($fam['items'] as $item) {
                    $wpdb->query($wpdb->prepare(
                        "DELETE FROM $t_reg WHERE id = %d",
                        $item->id
                    ));
                    foreach ($remaining as $code) {
                        $wpdb->query($wpdb->prepare(
                            "INSERT INTO $t_reg (child_id, trimestre_id, jour_date, service, updated_at)
                             VALUES (%d, %d, %s, %s, %s)",
                            $item->child_id, $item->trimestre_id, $date_str, $code, $now
                        ));
                    }
                }
                Psc_Mailer::send_forfait_downgraded($fam, $date_str, $service_label, $remaining_labels);
            }
        }

        return $affected;
    }

    /** Réouvre une prestation (annule sa fermeture ; ne restaure pas les inscriptions supprimées, même logique que open_day()). */
    public static function open_service($date_str, $service) {
        $date_str = psc_valid_date($date_str);
        if (!$date_str) return new WP_Error('invalid_date', __('Date invalide.', 'periscolaire-registration'));

        global $wpdb;
        $t = psc_table('service_closures');
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $t WHERE jour_date = %s AND service = %s",
            $date_str, $service
        ));

        return true;
    }
}
