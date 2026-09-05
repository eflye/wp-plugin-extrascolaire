<?php
if (!defined('ABSPATH')) exit;

/**
 * Moteur du planning : rythme habituel (psc_pattern) + exceptions
 * (psc_exception), résolution unique psc_is_declared(), verrou de 48 h sur
 * les deux écritures, et migration depuis l'ancienne table
 * wp_psc_registrations (une ligne par enfant × date × service).
 *
 * ARCHITECTURE — source de vérité unique. Facturation, listes intervenants,
 * effectifs cantine, exports mairie : tout passe par psc_is_declared() (à
 * l'unité) ou Psc_Planning::declared_map() (en lot, mêmes règles, deux
 * requêtes). Aucun autre code ne lit psc_pattern ou psc_exception.
 *
 * INVARIANT EN ÉCRITURE — jamais d'exception dont la valeur égale le rythme.
 * Un parent qui coche puis décoche un jour provoque la SUPPRESSION de la
 * ligne, pas sa mise à jour (psc_exception_write_decision()). Sans cela la
 * table se remplit de bruit et un futur changement de rythme ne se propage
 * plus à ce jour.
 *
 * VERROU 48 h SUR LES DEUX ÉCRITURES :
 *  - une exception à moins de 48 h est refusée côté serveur (pas seulement
 *    grisée côté client) ;
 *  - un changement de pattern ne repropage JAMAIS sur les jours déjà
 *    verrouillés : à la sauvegarde, chaque jour verrouillé concerné dont
 *    l'état effectif change est matérialisé en exception figée. Sans ce
 *    second point, un parent qui change son rythme un lundi soir
 *    modifierait rétroactivement le mardi déjà transmis à la cantine.
 */
class Psc_Planning {

    /** Cache par requête de la lecture unitaire (is_declared). */
    private static $single_cache = array();

    /**
     * Cache par requête des fermetures PAR PRESTATION (service_closures),
     * clé = date. open_map() est appelé par chaque résolution (declared_map,
     * month_state, month_explicit_map) sur des plages qui se recouvrent :
     * sans ce cache, les mêmes lignes relisaient trois fois par clic.
     */
    private static $svc_closed_cache = array();

    /** Cache par requête des jours d'école d'un (jour de semaine, année). */
    private static $weekday_days_cache = array();

    const WEEKDAYS = array(1, 2, 4, 5); // lundi, mardi, jeudi, vendredi — jamais le mercredi

    /* ================================================================
     * LECTURES — source de vérité
     * ================================================================ */

    /**
     * SOURCE DE VÉRITÉ — l'état déclaré d'un triplet (enfant, date, prestation).
     * Règle 1 : hors jour d'école / prestation fermée → toujours false.
     * Règle 2 : une exception sur le triplet gagne, quelle que soit sa valeur.
     * Règle 3 : sinon le pattern du jour de semaine, à défaut le forfait.
     */
    public static function is_declared($child_id, $date, $service_code) {
        $child_id = (int) $child_id;
        $date = psc_valid_date($date);
        if (!$child_id || !$date || !psc_is_valid_service($service_code)) return false;

        $key = $child_id . '|' . $date . '|' . $service_code;
        if (array_key_exists($key, self::$single_cache)) return self::$single_cache[$key];

        $year_key = Psc_School_Year::year_key_for_date($date);
        $weekday  = (int) date('N', strtotime($date));

        $patterns = self::load_patterns(array($child_id));
        $pats     = isset($patterns[$child_id][$year_key][$weekday]) ? $patterns[$child_id][$year_key][$weekday] : array();
        $excs     = self::load_exceptions(array($child_id), array($date));
        $exc      = isset($excs[$child_id][$date]) ? $excs[$child_id][$date] : array();
        $open     = self::day_open($date);

        // Enfant « cantine sans repas » : ses déclarations de cantine valent
        // midi sans repas (cf. psc_cantine_sans_repas_convert()).
        if (self::cantine_sans_repas_flag($child_id)) {
            list($pats, $exc) = psc_cantine_sans_repas_convert($pats, $exc);
        }

        $value = psc_resolve_declaration(
            $service_code === psc_forfait_code(),
            !empty($pats[$service_code]),
            array_key_exists($service_code, $exc) ? (bool) $exc[$service_code] : null,
            !empty($pats[psc_forfait_code()]),
            array_key_exists(psc_forfait_code(), $exc) ? (bool) $exc[psc_forfait_code()] : null,
            $open['day_open'],
            $service_code === psc_forfait_code() ? true : $open['services'][$service_code],
            $open['forf_open'],
            self::midi_slot($service_code, $pats, $exc)
        );

        self::$single_cache[$key] = $value;
        return $value;
    }

    /** Jour d'école + fermetures de prestations d'une date, avec cache statique. */
    protected static function day_open($date) {
        static $cache = array();
        if (isset($cache[$date])) return $cache[$date];

        $day_open = Psc_School_Year::is_school_day($date);
        $closed   = $day_open ? Psc_School_Calendar::closed_services_for_date($date) : array();
        $services = array();
        foreach (psc_unit_services() as $svc) {
            $services[$svc] = !in_array($svc, $closed, true);
        }
        // « Midi sans repas » suit le jour d'école mais pas les fermetures
        // de la cantine : l'enfant apporte son repas, la fermer parce que la
        // cantine ferme n'aurait pas de sens (et elle n'est pas fermable
        // depuis l'agenda pour l'instant).
        $services[psc_midi_sans_repas_code()] = $day_open;
        // Le forfait est indivisible : bloqué dès qu'une seule de ses
        // composantes est fermée, puisqu'on ne peut pas en facturer une partie.
        $forf_open = $day_open && !array_intersect(psc_unit_services(), $closed);

        $cache[$date] = array(
            'day_open'  => $day_open,
            'services'  => $services,
            'forf_open' => (bool) $forf_open,
        );
        return $cache[$date];
    }

    /**
     * Données du créneau du midi (pattern + exception de CANT et de MSR)
     * pour l'arbitrage du résolveur — cf. psc_resolve_declaration().
     * Vide pour toute prestation hors créneau du midi.
     */
    protected static function midi_slot($service_code, array $pats, array $exc) {
        $msr = psc_midi_sans_repas_code();
        if ($service_code !== 'CANT' && $service_code !== $msr) {
            return array();
        }
        return array(
            'request'        => $service_code,
            'cant_pattern'   => !empty($pats['CANT']),
            'cant_exception' => array_key_exists('CANT', $exc) ? (bool) $exc['CANT'] : null,
            'msr_pattern'    => !empty($pats[$msr]),
            'msr_exception'  => array_key_exists($msr, $exc) ? (bool) $exc[$msr] : null,
        );
    }

    /**
     * Patterns d'une liste d'enfants, en UNE requête.
     * Retour : [child_id][year_key][weekday][service_code] = true.
     */
    public static function load_patterns($child_ids) {
        global $wpdb;
        $child_ids = array_values(array_unique(array_map('intval', (array) $child_ids)));
        if (!$child_ids) return array();

        $t = psc_table('pattern');
        $placeholders = implode(',', array_fill(0, count($child_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT child_id, school_year, weekday, service_code FROM $t WHERE child_id IN ($placeholders)",
            $child_ids
        ));

        $map = array();
        if ($rows) {
            foreach ($rows as $r) {
                $map[(int) $r->child_id][$r->school_year][(int) $r->weekday][$r->service_code] = true;
            }
        }
        return $map;
    }

    /**
     * Exceptions d'une liste d'enfants (toutes, ou restreintes à des dates),
     * en UNE requête. Retour : [child_id][date][service_code] = valeur.
     */
    public static function load_exceptions($child_ids, $dates = null) {
        global $wpdb;
        $child_ids = array_values(array_unique(array_map('intval', (array) $child_ids)));
        if (!$child_ids) return array();

        $t = psc_table('exception');
        $placeholders = implode(',', array_fill(0, count($child_ids), '%d'));
        $params = $child_ids;
        $sql_dates = '';
        if ($dates !== null) {
            $dates = array_values(array_unique(array_filter(array_map(array(__CLASS__, 'clean_date'), (array) $dates))));
            if (!$dates) return array();
            $sql_dates = " AND jour_date IN (" . implode(',', array_fill(0, count($dates), '%s')) . ")";
            $params = array_merge($params, $dates);
        }

        // prepare() accepte un unique tableau de paramètres, ce qui évite
        // d'interpoler manuellement placeholders enfants + placeholders dates.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT child_id, jour_date, service_code, `value` FROM $t WHERE child_id IN ($placeholders)$sql_dates",
            $params
        ));

        $map = array();
        if ($rows) {
            foreach ($rows as $r) {
                $map[(int) $r->child_id][$r->jour_date][$r->service_code] = (bool) $r->value;
            }
        }
        return $map;
    }

    /** Nettoie une date entrante (retourne false si invalide) — callback de load_exceptions(). */
    protected static function clean_date($d) {
        return psc_valid_date($d) ? psc_valid_date($d) : false;
    }

    /**
     * Carte des déclarations effectives d'une liste d'enfants sur une liste
     * de dates — la version en lot de psc_is_declared(), mêmes règles, deux
     * requêtes de données quel que soit le volume. Utilisée par la
     * facturation, les listes intervenants, les effectifs cantine, les
     * exports mairie et les écrans Planning.
     * Retour : [child_id][date][service_code] = bool.
     */
    public static function declared_map($child_ids, $dates, $services = null) {
        $child_ids = array_values(array_unique(array_map('intval', (array) $child_ids)));
        $dates = array_values(array_unique(array_filter(array_map(array(__CLASS__, 'clean_date'), (array) $dates))));
        sort($dates);
        if (!$child_ids || !$dates) return array();

        $services = $services ?: psc_allowed_services();
        $forf = psc_forfait_code();

        $patterns   = self::load_patterns($child_ids);
        $exceptions = self::load_exceptions($child_ids, $dates);
        $open_map   = self::open_map($dates);
        $csr_flags  = self::cantine_sans_repas_flags($child_ids);

        // Calculs par DATE une seule fois (clé d'année et jour de semaine) :
        // les boucles ci-dessous parcourent enfant × date × prestation, les
        // refaire par triplet revenait à interroger la configuration d'année
        // plusieurs centaines de fois par clic (cf. caches Psc_School_Year).
        $year_keys = array();
        $weekdays  = array();
        foreach ($dates as $date) {
            $year_keys[$date] = Psc_School_Year::year_key_for_date($date);
            $weekdays[$date]  = (int) date('N', strtotime($date));
        }

        $map = array();
        foreach ($child_ids as $cid) {
            foreach ($dates as $date) {
                $open = $open_map[$date];
                $weekday = $weekdays[$date];
                $year_key = $year_keys[$date];
                $pats = isset($patterns[$cid][$year_key][$weekday]) ? $patterns[$cid][$year_key][$weekday] : array();
                $exc  = isset($exceptions[$cid][$date]) ? $exceptions[$cid][$date] : array();
                $forf_exc = array_key_exists($forf, $exc) ? (bool) $exc[$forf] : null;

                // Enfant « cantine sans repas » : cantine convertie en MSR
                // (cf. psc_cantine_sans_repas_convert()).
                if (!empty($csr_flags[$cid])) {
                    list($pats, $exc) = psc_cantine_sans_repas_convert($pats, $exc);
                    $forf_exc = array_key_exists($forf, $exc) ? (bool) $exc[$forf] : null;
                }

                foreach ($services as $svc) {
                    $map[$cid][$date][$svc] = psc_resolve_declaration(
                        $svc === $forf,
                        !empty($pats[$svc]),
                        array_key_exists($svc, $exc) ? (bool) $exc[$svc] : null,
                        !empty($pats[$forf]),
                        $forf_exc,
                        $open['day_open'],
                        $svc === $forf ? true : $open['services'][$svc],
                        $open['forf_open'],
                        self::midi_slot($svc, $pats, $exc)
                    );
                }
            }
        }
        return $map;
    }

    /** Fermetures (jour entier + prestations) d'une liste de dates, en 2 requêtes au plus par requête PHP. */
    protected static function open_map($dates) {
        global $wpdb;
        $map = array();
        foreach ($dates as $d) {
            $map[$d] = array(
                'day_open'  => false,
                'services'  => array_fill_keys(array_merge(psc_unit_services(), array(psc_midi_sans_repas_code())), true),
                'forf_open' => false,
            );
        }
        if (!$dates) return $map;

        // Fermeture manuelle du jour entier.
        Psc_School_Calendar::preload_closed($dates);
        // Fermeture par prestation : les passages successifs (declared_map,
        // month_state, month_explicit_map) se recouvrent largement — seules
        // les dates jamais vues déclenchent la requête.
        $missing = array_values(array_filter($dates, function ($d) {
            return !array_key_exists($d, self::$svc_closed_cache);
        }));
        if ($missing) {
            $t_svc = psc_table('service_closures');
            $placeholders = implode(',', array_fill(0, count($missing), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT jour_date, service FROM $t_svc WHERE jour_date IN ($placeholders)",
                $missing
            ));
            if ($rows) {
                foreach ($rows as $r) {
                    self::$svc_closed_cache[$r->jour_date][$r->service] = true;
                }
            }
            foreach ($missing as $d) {
                if (!isset(self::$svc_closed_cache[$d])) self::$svc_closed_cache[$d] = array();
            }
        }
        $closed_services = self::$svc_closed_cache;

        foreach ($dates as $d) {
            $day_open = Psc_School_Year::is_school_day($d);
            $closed = isset($closed_services[$d]) ? array_keys($closed_services[$d]) : array();
            $services = array();
            foreach (psc_unit_services() as $svc) {
                $services[$svc] = !in_array($svc, $closed, true);
            }
            // Indépendance des fermetures cantine, cf. day_open().
            $services[psc_midi_sans_repas_code()] = $day_open;
            $map[$d] = array(
                'day_open'  => $day_open,
                'services'  => $services,
                'forf_open' => $day_open && !array_intersect(psc_unit_services(), $closed),
            );
        }
        return $map;
    }

    /**
     * État détaillé d'un mois pour UN enfant (écran Planning - 2, zone
     * exceptions) : jours d'école du mois, état effectif par prestation,
     * origine lisible (pattern | exception_add | exception_remove | none)
     * et verrou. Seul le mois affiché est rendu — jamais l'année entière.
     */
    public static function month_state($child_id, $ym) {
        $child_id = (int) $child_id;
        $dates = Psc_School_Year::school_days_in_month($ym);
        if (!$child_id || !$dates) return array('dates' => array(), 'cells' => array());

        $first = $dates[0];
        $year_key = Psc_School_Year::year_key_for_date($first);
        $forf = psc_forfait_code();

        $patterns = self::load_patterns(array($child_id));
        $pats     = isset($patterns[$child_id][$year_key]) ? $patterns[$child_id][$year_key] : array();

        $month_start = $ym . '-01';
        $month_end   = gmdate('Y-m-t', strtotime($month_start));
        $exceptions  = self::load_exceptions(array($child_id), Psc_School_Year::school_days($month_start, $month_end));
        $excs        = isset($exceptions[$child_id]) ? $exceptions[$child_id] : array();
        $open_map    = self::open_map($dates);

        $cells = array();
        foreach ($dates as $date) {
            $weekday = (int) date('N', strtotime($date));
            $pats_wd = isset($pats[$weekday]) ? $pats[$weekday] : array();
            $exc_d   = isset($excs[$date]) ? $excs[$date] : array();
            $open    = $open_map[$date];
            $forf_exc = array_key_exists($forf, $exc_d) ? (bool) $exc_d[$forf] : null;
            $locked   = psc_is_locked($date);

            $per_service = array();
            foreach (psc_allowed_services() as $svc) {
                $exc = array_key_exists($svc, $exc_d) ? (bool) $exc_d[$svc] : null;
                $declared = psc_resolve_declaration(
                    $svc === $forf,
                    !empty($pats_wd[$svc]),
                    $exc,
                    !empty($pats_wd[$forf]),
                    $forf_exc,
                    $open['day_open'],
                    $svc === $forf ? true : $open['services'][$svc],
                    $open['forf_open'],
                    self::midi_slot($svc, $pats_wd, $exc_d)
                );
                if ($svc === $forf) {
                    // Origine lisible : l'EXCEPTION prime sur le pattern —
                    // un retrait exceptionnel sur un jour du rythme doit
                    // rester visible comme tel (glyphe –, bordure pointillée),
                    // sinon la distinction rythme / exception est illisible.
                    if ($exc !== null) {
                        $origin = 'exception';
                    } elseif (!empty($pats_wd[$forf])) {
                        $origin = 'pattern';
                    } else {
                        $origin = 'none';
                    }
                } else {
                    if ($exc !== null) {
                        $origin = 'exception';
                    } elseif (!empty($pats_wd[$svc])) {
                        $origin = 'pattern';
                    } else {
                        $origin = 'none';
                    }
                }

                $per_service[$svc] = array(
                    'declared' => (bool) $declared,
                    'origin'   => $origin,
                    'exception_value' => $exc,
                    'locked'   => $locked,
                    'closed'   => $svc === $forf ? !$open['forf_open'] : !$open['services'][$svc],
                    'price'    => (float) psc_services()[$svc]['price'],
                );
            }

            $cells[$date] = array(
                'weekday'  => $weekday,
                'locked'   => $locked,
                'services' => $per_service,
            );
        }

        return array('dates' => $dates, 'cells' => $cells);
    }

    /**
     * Nombre de jours déclarés par mois pour une fratrie (frise de
     * Planning - 2) : un (enfant, date) compte un jour dès qu'une prestation
     * y est déclarée. Une requête groupée par mois — jamais onze.
     * Retour : [ym => jours].
     */
    public static function month_counts($child_ids, $months) {
        $child_ids = array_values(array_unique(array_map('intval', (array) $child_ids)));
        $months = array_values(array_filter((array) $months, function ($m) {
            return is_string($m) && preg_match('/^\d{4}-\d{2}$/', $m);
        }));
        if (!$child_ids || !$months) return array_fill_keys($months, 0);

        $dates = array();
        foreach ($months as $ym) {
            foreach (Psc_School_Year::school_days_in_month($ym) as $d) {
                $dates[$d] = $ym;
            }
        }
        if (!$dates) return array_fill_keys($months, 0);

        $map = self::declared_map($child_ids, array_keys($dates));
        $counts = array_fill_keys($months, 0);
        foreach ($child_ids as $cid) {
            foreach ($dates as $date => $ym) {
                $declared = false;
                if (isset($map[$cid][$date])) {
                    foreach ($map[$cid][$date] as $v) {
                        if ($v) { $declared = true; break; }
                    }
                }
                if ($declared) $counts[$ym]++;
            }
        }
        return $counts;
    }

    /**
     * Récapitulatif fratrie d'un mois ET de l'année : jours + montant par
     * enfant (mois et année) et total famille du mois. Un forfait déclaré
     * (et réalisable) est facturé à lui seul — jamais cumulé avec ses
     * composantes.
     */
    public static function sibling_summary($children, $months) {
        $months = array_values(array_filter((array) $months, function ($m) {
            return is_string($m) && preg_match('/^\d{4}-\d{2}$/', $m);
        }));
        $services = psc_services();
        $forf = psc_forfait_code();

        $per_child = array();
        $month_days = 0;
        $month_total = 0.0;

        if ($months) {
            $dates = array();
            foreach ($months as $ym) {
                foreach (Psc_School_Year::school_days_in_month($ym) as $d) $dates[$d] = $ym;
            }
            $child_ids = array();
            foreach ($children as $c) $child_ids[] = (int) $c->id;

            $map = self::declared_map($child_ids, array_keys($dates));
            $child_names = array();
            foreach ($children as $c) $child_names[(int) $c->id] = trim($c->prenom . ' ' . $c->nom);

            foreach ($child_ids as $cid) {
                $per_child[$cid] = array(
                    'name' => isset($child_names[$cid]) ? $child_names[$cid] : '',
                    'month_days' => 0, 'month_total' => 0.0,
                    'year_days' => 0, 'year_total' => 0.0,
                );
            }

            foreach ($child_ids as $cid) {
                foreach ($dates as $date => $ym) {
                    // Même règle que psc_billing_services() : un forfait
                    // déclaré se facture seul, « midi sans repas » se facture
                    // à part des unités comme du forfait.
                    $declared_day = isset($map[$cid][$date]) ? $map[$cid][$date] : array();
                    $billed = psc_billing_services($declared_day);
                    $day_amount = 0.0;
                    foreach ($billed as $svc) {
                        $day_amount += (float) $services[$svc]['price'];
                    }
                    $day_declared = $billed !== array();
                    if (!$day_declared) continue;

                    $per_child[$cid]['year_days']++;
                    $per_child[$cid]['year_total'] += $day_amount;
                    if (in_array($ym, $months, true)) {
                        $per_child[$cid]['month_days']++;
                        $per_child[$cid]['month_total'] += $day_amount;
                    }
                }
            }

            foreach ($per_child as $row) {
                $month_days += $row['month_days'];
                $month_total += $row['month_total'];
            }
        }

        return array(
            'per_child'   => $per_child,
            'month_days'  => $month_days,
            'month_total' => $month_total,
        );
    }

    /**
     * Récapitulatif annuel de la fratrie, calculé d'UN lot de résolutions :
     * par enfant, jours et montant de chaque mois, plus les totaux année.
     * Alimente la frise de Planning - 2, les cartes de récapitulatif,
     * le bandeau de Planning - 1 et l'estimation annuelle de l'e-mail.
     *
     * Retour :
     *  'months'     => [ym => ['days' => n, 'amount' => f, 'per_child' => [cid => ['days','amount']]]]
     *  'year'       => ['days' => n, 'amount' => f, 'per_child' => [cid => ['days','amount']]]
     */
    public static function year_summary($children, $year_key = null) {
        $services = psc_services();
        $forf = psc_forfait_code();

        $year = $year_key !== null ? Psc_School_Year::get($year_key) : Psc_School_Year::active();
        if (!$year || empty($children)) {
            return array('months' => array(), 'year' => array('days' => 0, 'amount' => 0.0, 'per_child' => array()));
        }

        $child_ids = array();
        $child_names = array();
        foreach ($children as $c) {
            $cid = (int) $c->id;
            $child_ids[] = $cid;
            $child_names[$cid] = trim($c->prenom . ' ' . $c->nom);
        }

        $months = Psc_School_Year::months($year->year_key);
        $dates_by_month = array();
        $all_dates = array();
        foreach ($months as $m) {
            $dates_by_month[$m['key']] = Psc_School_Year::school_days_in_month($m['key']);
            foreach ($dates_by_month[$m['key']] as $d) $all_dates[$d] = $m['key'];
        }

        $map = self::declared_map($child_ids, array_keys($all_dates));

        $months_out = array();
        $year_out = array('days' => 0, 'amount' => 0.0, 'per_child' => array());
        foreach ($child_ids as $cid) {
            $year_out['per_child'][$cid] = array('name' => $child_names[$cid], 'days' => 0, 'amount' => 0.0);
        }

        foreach ($months as $m) {
            $ym = $m['key'];
            $months_out[$ym] = array('days' => 0, 'amount' => 0.0, 'per_child' => array());
            foreach ($child_ids as $cid) {
                $months_out[$ym]['per_child'][$cid] = array('days' => 0, 'amount' => 0.0);
            }

            foreach ($dates_by_month[$ym] as $date) {
                foreach ($child_ids as $cid) {
                    $declared = isset($map[$cid][$date]) ? $map[$cid][$date] : array();
                    if (!in_array(true, $declared, true)) continue;

                    $day_amount = 0.0;
                    $billed = psc_billing_services($declared);
                    foreach ($billed as $svc) {
                        $day_amount += (float) $services[$svc]['price'];
                    }

                    $months_out[$ym]['days']++;
                    $months_out[$ym]['amount'] += $day_amount;
                    $months_out[$ym]['per_child'][$cid]['days']++;
                    $months_out[$ym]['per_child'][$cid]['amount'] += $day_amount;

                    $year_out['days']++;
                    $year_out['amount'] += $day_amount;
                    $year_out['per_child'][$cid]['days']++;
                    $year_out['per_child'][$cid]['amount'] += $day_amount;
                }
            }
        }

        return array('months' => $months_out, 'year' => $year_out);
    }

    /**
     * État « case cochée » du mois pour PLUSIEURS enfants (Planning - 1,
     * tableau jour × service) : une case est cochée ssi une ligne explicite
     * la porte (pattern du jour de semaine, exception) — la couverture par
     * le forfait ne coche pas les cases des prestations élémentaires, elle
     * coche la colonne FORF (convention d'affichage de la variante 1, la
     * facturation comptant alors le forfait seul).
     * Retour : [child_id][date][service_code] = ['explicit','declared','locked','closed'].
     */
    public static function month_explicit_map($child_ids, $ym) {
        $child_ids = array_values(array_unique(array_map('intval', (array) $child_ids)));
        $dates = Psc_School_Year::school_days_in_month($ym);
        if (!$child_ids || !$dates) return array();

        $year_key = Psc_School_Year::year_key_for_date($dates[0]);
        $forf = psc_forfait_code();

        $patterns   = self::load_patterns($child_ids);
        $exceptions = self::load_exceptions($child_ids, $dates);
        $open_map   = self::open_map($dates);

        $map = array();
        foreach ($child_ids as $cid) {
            foreach ($dates as $date) {
                $weekday = (int) date('N', strtotime($date));
                $pats = isset($patterns[$cid][$year_key][$weekday]) ? $patterns[$cid][$year_key][$weekday] : array();
                $exc  = isset($exceptions[$cid][$date]) ? $exceptions[$cid][$date] : array();
                $open = $open_map[$date];

                foreach (psc_allowed_services() as $svc) {
                    $exc_val = array_key_exists($svc, $exc) ? (bool) $exc[$svc] : null;
                    // L'exception GAGNE dans l'affichage comme dans la
                    // résolution : un retrait exceptionnel sur un jour du
                    // rythme doit décocher la case, sinon l'écran montrerait
                    // « déclaré » ce que la facturation ne compte pas.
                    $explicit = $exc_val !== null ? $exc_val : !empty($pats[$svc]);
                    $declared = psc_resolve_declaration(
                        $svc === $forf,
                        !empty($pats[$svc]),
                        $exc_val,
                        !empty($pats[$forf]),
                        array_key_exists($forf, $exc) ? (bool) $exc[$forf] : null,
                        $open['day_open'],
                        $svc === $forf ? true : $open['services'][$svc],
                        $open['forf_open'],
                        self::midi_slot($svc, $pats, $exc)
                    );
                    $map[$cid][$date][$svc] = array(
                        'explicit' => (bool) $explicit,
                        'declared' => (bool) $declared,
                        'locked'   => psc_is_locked($date),
                        'closed'   => $svc === $forf ? !$open['forf_open'] : !$open['services'][$svc],
                    );
                }
            }
        }
        return $map;
    }

    /* ================================================================
     * ÉCRITURES
     * ================================================================ */

    /**
     * Écrit ou retire une exception (un clic sur une case du planning).
     * Le serveur calcule l'état effectif et écrit OU SUPPRIME selon
     * l'invariant : jamais d'exception dont la valeur égale le rythme.
     * Un parent qui coche puis décoche un jour ne laisse aucune ligne.
     *
     * $ignore_lock : réservé à la mairie (elle doit pouvoir corriger une
     * erreur de dernière minute, comme elle pouvait supprimer une ligne
     * de l'ancienne table). Jamais vrai côté famille.
     */
    public static function toggle_exception($child_id, $date, $service_code, $on, $ignore_lock = false) {
        $child_id = (int) $child_id;
        $date = psc_valid_date($date);
        if (!$child_id || !$date || !psc_is_valid_service($service_code)) {
            return array('status' => 'invalid');
        }

        // Règle 1 du modèle : hors jour d'école, toujours false — une
        // exception sur un jour fermé n'a pas de sens, on refuse l'écriture.
        if (Psc_School_Year::day_status($date) !== 'school') {
            return array('status' => 'day_closed');
        }

        // Verrou 48 h côté serveur : désactiver la case dans le navigateur
        // ne suffit pas, un utilisateur peut rejouer la requête.
        if (!$ignore_lock && psc_is_locked($date)) {
            return array('status' => 'locked');
        }

        $patterns = self::load_patterns(array($child_id));
        $year_key = Psc_School_Year::year_key_for_date($date);
        $weekday  = (int) date('N', strtotime($date));
        $pats     = isset($patterns[$child_id][$year_key][$weekday]) ? $patterns[$child_id][$year_key][$weekday] : array();
        $forf     = psc_forfait_code();

        $decision = psc_exception_write_decision(
            $service_code === $forf,
            !empty($pats[$service_code]),
            !empty($pats[$forf]),
            (bool) $on,
            array(
                'request'      => $service_code,
                'cant_pattern' => !empty($pats['CANT']),
                'msr_pattern'  => !empty($pats[psc_midi_sans_repas_code()]),
            )
        );

        global $wpdb;
        $t_exc = psc_table('exception');

        if ($decision === 'delete') {
            $wpdb->delete($t_exc,
                array('child_id' => $child_id, 'jour_date' => $date, 'service_code' => $service_code),
                array('%d', '%s', '%s')
            );
            $status = 'removed';
        } else {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $t_exc (child_id, jour_date, service_code, `value`, created_at)
                 VALUES (%d, %s, %s, %d, %s)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                $child_id, $date, $service_code, $on ? 1 : 0, current_time('mysql')
            ));
            $status = 'added';
        }

        // Un ajout rend caduques les exceptions positives conflictuelles du
        // même jour (forfait contre unités, cantine contre « midi sans
        // repas ») : les retirer ici évite de facturer deux fois le même
        // créneau si le navigateur n'a pas envoyé la cascade de décochage.
        if ($on && $decision === 'upsert') {
            foreach (psc_conflicting_services($service_code) as $conf) {
                $wpdb->delete($t_exc,
                    array('child_id' => $child_id, 'jour_date' => $date, 'service_code' => $conf, 'value' => 1),
                    array('%d', '%s', '%s', '%d')
                );
            }
        }

        self::flush_cache();
        return array('status' => $status, 'declared' => (bool) $on);
    }

    /**
     * Bouton « Tout / Aucun » par colonne : écrit les exceptions d'un lot de
     * dates. Les dates refusées (verrouillées, fermées, invalides) sont
     * ignorées silencieusement plutôt que de faire échouer tout le lot —
     * leur état a pu changer depuis le rendu de la page.
     */
    public static function toggle_exception_bulk($child_id, $dates, $service_code, $on) {
        $applied = array();
        foreach ((array) $dates as $date) {
            $r = self::toggle_exception($child_id, $date, $service_code, $on);
            if ($r['status'] === 'added' || $r['status'] === 'removed') {
                $applied[] = psc_valid_date($date);
            }
        }
        return array_values(array_filter($applied));
    }

    /**
     * Écrit (ou retire) une ligne de rythme habituel — un jour de semaine
     * entier de l'année scolaire. C'est le seul endroit qui touche
     * psc_pattern : il applique les exclusivités du forfait, gèle les jours
     * verrouillés et purge les exceptions devenues du bruit.
     */
    public static function toggle_pattern($child_id, $year_key, $weekday, $service_code, $on) {
        $child_id = (int) $child_id;
        $year_key = Psc_School_Year::sanitize_key($year_key);
        $weekday  = (int) $weekday;
        $on = (bool) $on;
        if (!$child_id || $year_key === '' || !in_array($weekday, self::WEEKDAYS, true) || !psc_is_valid_service($service_code)) {
            return array('status' => 'invalid');
        }
        if (!Psc_School_Year::get($year_key)) {
            return array('status' => 'invalid');
        }

        global $wpdb;
        $t_pat = psc_table('pattern');
        $t_exc = psc_table('exception');
        $forf  = psc_forfait_code();

        // Rien à faire si l'état demandé est déjà celui du rythme : pas de
        // gel ni de purge à déclencher pour un non-changement.
        $existing = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t_pat WHERE child_id = %d AND school_year = %s AND weekday = %d AND service_code = %s",
            $child_id, $year_key, $weekday, $service_code
        ));
        if ($existing === $on) {
            return array('status' => 'unchanged');
        }

        $days = self::weekday_days($year_key, $weekday);

        // État effectif AVANT le changement (pour figer les jours verrouillés).
        $before = $days ? self::declared_map(array($child_id), $days) : array();
        $before = isset($before[$child_id]) ? $before[$child_id] : array();

        // 1. Exclusivités (même règle que l'ancien modèle : déclarer le
        //    forfait retire les composantes, déclarer une composante retire
        //    le forfait ; déclarer la cantine ou « midi sans repas » retire
        //    l'autre bout du créneau). psc_conflicting_services() porte la
        //    liste pour chaque prestation — y compris le forfait, qui
        //    entre aussi en conflit avec MSR.
        if ($on) {
            foreach (psc_conflicting_services($service_code) as $conf) {
                $wpdb->delete($t_pat, array(
                    'child_id' => $child_id, 'school_year' => $year_key, 'weekday' => $weekday, 'service_code' => $conf,
                ), array('%d', '%s', '%d', '%s'));
            }
        }

        // 2. Écriture du pattern (une ligne de pattern ne porte que du vrai :
        //    retirer = supprimer la ligne, l'absence vaut false).
        if ($on) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $t_pat (child_id, school_year, weekday, service_code, created_at, updated_at)
                 VALUES (%d, %s, %d, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
                $child_id, $year_key, $weekday, $service_code, current_time('mysql'), current_time('mysql')
            ));
        } else {
            $wpdb->delete($t_pat, array(
                'child_id' => $child_id, 'school_year' => $year_key, 'weekday' => $weekday, 'service_code' => $service_code,
            ), array('%d', '%s', '%d', '%s'));
        }

        // 3. Gel des jours verrouillés + purge du bruit.
        self::flush_cache();
        $patterns_after = self::load_patterns(array($child_id));
        $pats_after     = isset($patterns_after[$child_id][$year_key][$weekday]) ? $patterns_after[$child_id][$year_key][$weekday] : array();
        $exceptions     = self::load_exceptions(array($child_id), $days);

        $frozen = 0;
        $purged = 0;
        foreach ($days as $day) {
            $locked = psc_is_locked($day);
            $exc_d  = isset($exceptions[$child_id][$day]) ? $exceptions[$child_id][$day] : array();
            $forf_exc = array_key_exists($forf, $exc_d) ? (bool) $exc_d[$forf] : null;

            foreach (psc_allowed_services() as $svc) {
                $exc = array_key_exists($svc, $exc_d) ? (bool) $exc_d[$svc] : null;
                // État qui prévaudrait SANS l'exception de ce triplet :
                // pattern propre, sinon couverture par le forfait — avec
                // l'arbitrage du créneau du midi (l'activité CANT/MSR de
                // l'autre service masque le rythme).
                $base = psc_resolve_declaration(
                    $svc === $forf,
                    !empty($pats_after[$svc]),
                    null,
                    !empty($pats_after[$forf]),
                    $forf_exc,
                    true,
                    true,
                    true,
                    array(
                        'request'        => $svc,
                        'cant_pattern'   => !empty($pats_after['CANT']),
                        'cant_exception' => array_key_exists('CANT', $exc_d) ? (bool) $exc_d['CANT'] : null,
                        'msr_pattern'    => !empty($pats_after[psc_midi_sans_repas_code()]),
                        'msr_exception'  => array_key_exists(psc_midi_sans_repas_code(), $exc_d) ? (bool) $exc_d[psc_midi_sans_repas_code()] : null,
                    )
                );

                if ($exc !== null) {
                    if ((bool) $exc === $base) {
                        // Invariant : cette exception est devenue du bruit —
                        // sa valeur égale le rythme. La supprimer ne change
                        // rien à l'état effectif, y compris sur un jour verrouillé.
                        $wpdb->delete($t_exc,
                            array('child_id' => $child_id, 'jour_date' => $day, 'service_code' => $svc),
                            array('%d', '%s', '%s')
                        );
                        $purged++;
                    }
                    // Sinon l'exception fige déjà son état : elle gagne sur
                    // le pattern, le jour verrouillé est préservé tel quel.
                } elseif ($locked) {
                    $was = isset($before[$day][$svc]) ? (bool) $before[$day][$svc] : false;
                    if ($was !== $base) {
                        // Verrou 48 h, écriture n°2 : le changement de rythme
                        // ne repropage pas sur ce jour déjà transmis — on
                        // matérialise son état d'avant en exception figée.
                        $wpdb->query($wpdb->prepare(
                            "INSERT INTO $t_exc (child_id, jour_date, service_code, `value`, created_at)
                             VALUES (%d, %s, %s, %d, %s)
                             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                            $child_id, $day, $svc, $was ? 1 : 0, current_time('mysql')
                        ));
                        $frozen++;
                    }
                }
            }
        }

        self::flush_cache();
        return array('status' => 'ok', 'frozen' => $frozen, 'purged' => $purged);
    }

    /**
     * « Appliquer ce rythme à toute la fratrie » : copie le pattern de
     * l'enfant source vers les autres. Les exceptions individuelles
     * préexistantes sont conservées — seuls les jours verrouillés dont
     * l'état change sont figés par toggle_pattern(), comme pour toute
     * écriture de rythme.
     */
    public static function apply_pattern_to_siblings($source_child_id, $target_ids) {
        $source_child_id = (int) $source_child_id;
        $year_key = Psc_School_Year::active();
        $year_key = $year_key ? $year_key->year_key : Psc_School_Year::current_key();

        $patterns = self::load_patterns(array($source_child_id));
        $source   = isset($patterns[$source_child_id][$year_key]) ? $patterns[$source_child_id][$year_key] : array();

        $results = array();
        foreach ((array) $target_ids as $tid) {
            $tid = (int) $tid;
            if (!$tid || $tid === $source_child_id) continue;

            $copied = 0;
            $removed = 0;
            foreach (self::WEEKDAYS as $weekday) {
                foreach (psc_allowed_services() as $svc) {
                    $want = !empty($source[$weekday][$svc]);
                    $r = self::toggle_pattern($tid, $year_key, $weekday, $svc, $want);
                    if ($r['status'] === 'ok') {
                        if ($want) $copied++;
                        else $removed++;
                    }
                }
            }
            $results[$tid] = array('copied' => $copied, 'removed' => $removed);
        }

        self::flush_cache();
        return $results;
    }

    /**
     * « N exception(s) ce mois-ci — revenir au rythme » : purge les
     * exceptions du mois pour l'enfant actif. Les jours verrouillés sont
     * exclus : leurs exceptions figent un état déjà transmis à la cantine,
     * les supprimer modifierait rétroactivement des données transmises.
     */
    public static function reset_month_exceptions($child_id, $ym) {
        global $wpdb;
        $child_id = (int) $child_id;
        if (!$child_id || !preg_match('/^\d{4}-\d{2}$/', $ym)) return 0;

        $start = $ym . '-01';
        $end   = gmdate('Y-m-t', strtotime($start));
        $t_exc = psc_table('exception');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, jour_date FROM $t_exc WHERE child_id = %d AND jour_date BETWEEN %s AND %s",
            $child_id, $start, $end
        ));
        if (!$rows) return 0;

        $deleted = 0;
        foreach ($rows as $r) {
            if (psc_is_locked($r->jour_date)) continue;
            $deleted += $wpdb->delete($t_exc, array('id' => $r->id), array('%d'));
        }

        self::flush_cache();
        return $deleted;
    }

    /**
     * Exceptions à venir (à partir de $from, jours non passés), pour le
     * récapitulatif annuel : le rythme ne suffit pas à le décrire — les
     * écarts déjà posés font partie de l'engagement de la famille.
     * Retour : [child_id => [[date, service_code, value], …]] trié par date.
     */
    public static function upcoming_exceptions($child_ids, $from) {
        global $wpdb;
        $child_ids = array_values(array_unique(array_map('intval', (array) $child_ids)));
        $from = psc_valid_date($from);
        if (!$child_ids || !$from) return array();

        $t = psc_table('exception');
        $placeholders = implode(',', array_fill(0, count($child_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT child_id, jour_date, service_code, `value` FROM $t
             WHERE child_id IN ($placeholders) AND jour_date >= %s
             ORDER BY jour_date, service_code",
            array_merge($child_ids, array($from))
        ));

        $out = array();
        if ($rows) {
            foreach ($rows as $r) {
                $out[(int) $r->child_id][] = array(
                    'date'    => $r->jour_date,
                    'service' => $r->service_code,
                    'value'   => (bool) $r->value,
                );
            }
        }
        return $out;
    }

    /** Purge toutes les données de planning d'un enfant (suppression d'enfant). */    public static function delete_for_child($child_id) {
        global $wpdb;
        $child_id = (int) $child_id;
        if (!$child_id) return;
        $wpdb->delete(psc_table('pattern'), array('child_id' => $child_id), array('%d'));
        $wpdb->delete(psc_table('exception'), array('child_id' => $child_id), array('%d'));
        self::flush_cache();
    }

    /* ================================================================
     * MIGRATION — depuis l'ancienne table wp_psc_registrations
     * (une ligne par enfant × date × service)
     * ================================================================ */

    /**
     * Migration idempotente du modèle « une ligne par jour » vers
     * « rythme + exceptions » :
     *
     *  1. pour chaque (enfant, prestation, jour de semaine), compter les
     *     occurrences déclarées sur l'étendue historique de l'enfant ;
     *  2. ≥ 60 % des jours d'école de ce jour de semaine → créer la ligne
     *     de pattern (une ligne = « cet enfant mange à la cantine tous les
     *     mardis de l'année ») ;
     *  3. créer une exception pour chaque jour divergent (ajout si une
     *     ligne historique existe sans pattern, retrait si un pattern
     *     couvre un jour sans ligne) ;
     *  4. le test bloquant vit dans verify_against_registrations()
     *     (bin/verify-planning-migration.php) : psc_is_declared() doit
     *     renvoir exactement le même résultat que l'ancienne table sur
     *     toutes les lignes historiques — la facturation est en jeu.
     *  5. l'ancienne table est conservée en lecture seule le temps d'un
     *     cycle de facturation (aucune écriture n'y passe plus).
     *
     * Rejouer la migration ne duplique rien : patterns et exceptions
     * s'appuient sur des clés UNIQUE et s'écrivent en INSERT IGNORE.
     */
    public static function migrate_from_registrations() {
        global $wpdb;
        $t_reg = psc_table('registrations');
        $t_pat = psc_table('pattern');
        $t_exc = psc_table('exception');
        $forf  = psc_forfait_code();

        // L'ancienne table a disparu (installations neuves) : rien à migrer.
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t_reg))) {
            return array('children' => 0, 'patterns' => 0, 'exceptions' => 0, 'rows' => 0, 'anomalies' => 0, 'years' => array());
        }

        $report = array('children' => 0, 'patterns' => 0, 'exceptions' => 0, 'rows' => 0, 'anomalies' => 0, 'years' => array());
        $child_ids = $wpdb->get_col("SELECT DISTINCT child_id FROM $t_reg ORDER BY child_id");
        if (!$child_ids) return $report;

        $total = count($child_ids);
        for ($offset = 0; $offset < $total; $offset += 50) {
            $batch = array_slice($child_ids, $offset, 50);
            $placeholders = implode(',', array_fill(0, count($batch), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT child_id, jour_date, service FROM $t_reg WHERE child_id IN ($placeholders) ORDER BY child_id, jour_date",
                $batch
            ));
            if (!$rows) continue;

            $by_child = array();
            foreach ($rows as $r) {
                $date = psc_valid_date($r->jour_date);
                if (!$date) continue;
                $by_child[(int) $r->child_id][$date][] = $r->service;
            }

            foreach ($by_child as $child_id => $dates_map) {
                $report['children']++;
                $report['rows'] += array_sum(array_map('count', $dates_map));

                // Regroupe les dates par année scolaire (une famille peut
                // avoir un historique sur plusieurs années).
                $by_year = array();
                foreach ($dates_map as $date => $svcs) {
                    $yk = Psc_School_Year::year_key_for_date($date);
                    $by_year[$yk][$date] = $svcs;
                }

                foreach ($by_year as $year_key => $year_dates) {
                    if (!isset($report['years'][$year_key])) $report['years'][$year_key] = 0;
                    $report['years'][$year_key]++;

                    $year = self::ensure_year_config($year_key);
                    if (!$year) continue;

                    $dates = array_keys($year_dates);
                    $min = min($dates);
                    $max = max($dates);

                    // Étendue déclarée de l'enfant : les jours d'école de
                    // cette plage servent de dénominateur au seuil de 60 %.
                    $school_days = Psc_School_Year::school_days($min, $max);
                    $denom = array_fill_keys(self::WEEKDAYS, 0);
                    foreach ($school_days as $d) {
                        $dow = (int) date('N', strtotime($d));
                        if (isset($denom[$dow])) $denom[$dow]++;
                    }

                    // 1-2. Occurrences par (prestation, jour de semaine) → pattern.
                    $patterns = array();
                    foreach (self::WEEKDAYS as $dow) {
                        if ($denom[$dow] === 0) continue;
                        foreach (psc_allowed_services() as $svc) {
                            $num = 0;
                            foreach ($year_dates as $date => $svcs) {
                                if ((int) date('N', strtotime($date)) === $dow && in_array($svc, $svcs, true)) $num++;
                            }
                            if ($num / $denom[$dow] >= 0.6) {
                                $patterns[$dow][$svc] = true;
                            }
                        }
                    }
                    foreach ($patterns as $dow => $svcs) {
                        foreach (array_keys($svcs) as $svc) {
                            $ok = $wpdb->query($wpdb->prepare(
                                "INSERT IGNORE INTO $t_pat (child_id, school_year, weekday, service_code, created_at, updated_at)
                                 VALUES (%d, %s, %d, %s, %s, %s)",
                                $child_id, $year_key, $dow, $svc, current_time('mysql'), current_time('mysql')
                            ));
                            $report['patterns'] += (int) $ok;
                        }
                    }

                    // 3. Exceptions pour chaque jour divergent.
                    $exceptions = array();
                    foreach ($school_days as $d) {
                        $dow = (int) date('N', strtotime($d));
                        $day_svcs = isset($year_dates[$d]) ? $year_dates[$d] : array();
                        $forf_row = in_array($forf, $day_svcs, true);

                        foreach (psc_allowed_services() as $svc) {
                            $old_effectif = in_array($svc, $day_svcs, true)
                                || ($svc !== $forf && $forf_row);
                            // État que prévaudrait le pattern seul (même
                            // règle que l'invariant d'écriture).
                            $base = !empty($patterns[$dow][$svc])
                                || ($svc !== $forf && !empty($patterns[$dow][$forf]));
                            if ($old_effectif === $base) continue; // conforme au rythme
                            $exceptions[$d][$svc] = $old_effectif;
                        }
                    }

                    // Lignes historiques posées sur un jour non scolaire
                    // (vacances, mercredi...) : la résolution retourne
                    // toujours false — comptées comme anomalies.
                    foreach ($year_dates as $date => $svcs) {
                        if (!Psc_School_Year::is_school_day($date)) {
                            $report['anomalies'] += count(array_intersect($svcs, psc_allowed_services()));
                        }
                    }

                    foreach ($exceptions as $date => $svcs) {
                        foreach ($svcs as $svc => $value) {
                            $ok = $wpdb->query($wpdb->prepare(
                                "INSERT IGNORE INTO $t_exc (child_id, jour_date, service_code, `value`, created_at)
                                 VALUES (%d, %s, %s, %d, %s)",
                                $child_id, $date, $svc, $value ? 1 : 0, current_time('mysql')
                            ));
                            $report['exceptions'] += (int) $ok;
                        }
                    }
                }
            }
        }

        self::flush_cache();
        return $report;
    }

    /**
     * Garantit une configuration psc_school_year pour une clé donnée :
     * créée par dérivation (bornes du dossier d'inscription homonyme,
     * sinon 1er septembre → 6 juillet) et fériés pré-remplis. La migration
     * d'historiques plus anciens que la première configuration ne doit pas
     * avorter faute d'année.
     */
    public static function ensure_year_config($year_key) {
        $year = Psc_School_Year::get($year_key);
        if ($year) return $year;

        global $wpdb;
        $enrolled = $wpdb->get_row($wpdb->prepare(
            'SELECT date_debut, date_fin FROM ' . psc_table('school_years') . ' WHERE label = %s ORDER BY id DESC LIMIT 1',
            $year_key
        ));
        $start = $enrolled && $enrolled->date_debut ? $enrolled->date_debut : sprintf('%d-09-01', (int) substr($year_key, 0, 4));
        $end   = $enrolled && $enrolled->date_fin   ? $enrolled->date_fin   : sprintf('%d-07-06', (int) substr($year_key, 5, 4));
        $ok = Psc_School_Year::save($year_key, $start, $end, '[]', psc_lock_hours());
        return $ok ? Psc_School_Year::get($year_key) : null;
    }

    /**
     * TEST BLOQUANT — psc_is_declared() doit renvoyer exactement le même
     * résultat que l'ancienne table sur toutes les lignes historiques
     * (facturation en jeu, pas de contrôle visuel).
     *
     * Retourne le rapport de vérification :
     *  - checked     : lignes historiques évaluées ;
     *  - mismatches  : lignes où l'ancienne table dit « déclaré » et la
     *                  nouvelle résolution dit false — BLOQUANT ;
     *  - anomalies   : lignes posées sur un jour non scolaire ou une
     *                  prestation aujourd'hui fermée — jamais
     *                  reproductibles par résolution, à corriger à la main ;
     *  - extra       : jours désormais déclarés sans ligne historique
     *                  (les jours du rythme couverts par le seuil de 60 %)
     *                  — attendu par construction, informatif seulement.
     */
    public static function verify_against_registrations() {
        global $wpdb;
        $t_reg = psc_table('registrations');
        $forf  = psc_forfait_code();

        $result = array('checked' => 0, 'mismatches' => array(), 'anomalies' => array(), 'extra' => 0);
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t_reg))) {
            return $result;
        }

        $rows = $wpdb->get_results("SELECT child_id, jour_date, service FROM $t_reg ORDER BY child_id, jour_date");
        if (!$rows) return $result;

        // Carte des lignes historiques par enfant, pour le décompte informatif.
        $by_child = array();
        foreach ($rows as $r) {
            $date = psc_valid_date($r->jour_date);
            if (!$date) continue;
            $by_child[(int) $r->child_id][$date][] = $r->service;
        }

        foreach ($rows as $r) {
            $date = psc_valid_date($r->jour_date);
            $child_id = (int) $r->child_id;
            if (!$date || !psc_is_valid_service($r->service)) continue;

            $day_open = Psc_School_Year::is_school_day($date);
            $svc_closed = false;
            if ($day_open) {
                $open = self::day_open($date);
                $svc_closed = $r->service === $forf ? !$open['forf_open'] : !$open['services'][$r->service];
            }
            if (!$day_open || $svc_closed) {
                $result['anomalies'][] = sprintf('%d | %s | %s (jour non scolaire ou prestation fermée)', $child_id, $date, $r->service);
                continue;
            }

            $result['checked']++;
            if (!self::is_declared($child_id, $date, $r->service)) {
                $result['mismatches'][] = sprintf('%d | %s | %s', $child_id, $date, $r->service);
            }
        }

        // Informatif : jours déclarés par le rythme sans ligne historique.
        foreach ($by_child as $child_id => $dates_map) {
            $dates = array_keys($dates_map);
            if (!$dates) continue;
            $span = Psc_School_Year::school_days(min($dates), max($dates));
            $pattern_map = self::declared_map(array($child_id), $span);
            foreach ($span as $d) {
                foreach (psc_allowed_services() as $svc) {
                    if (!empty($pattern_map[$child_id][$d][$svc]) && !in_array($svc, $dates_map[$d], true)) {
                        $result['extra']++;
                    }
                }
            }
        }

        return $result;
    }

    /* ================================================================
     * OUTILS
     * ================================================================ */

    /** Jours d'école d'un jour de semaine donné sur l'année (lundi=1, mardi=2, jeudi=4, vendredi=5). */
    protected static function weekday_days($year_key, $weekday) {
        $key = $year_key . '|' . $weekday;
        if (isset(self::$weekday_days_cache[$key])) return self::$weekday_days_cache[$key];

        $year = Psc_School_Year::get($year_key);
        $days = array();
        if ($year) {
            $all = Psc_School_Year::school_days($year->date_start, $year->date_end);
            foreach ($all as $d) {
                if ((int) date('N', strtotime($d)) === $weekday) $days[] = $d;
            }
        }
        self::$weekday_days_cache[$key] = $days;
        return $days;
    }

    public static function flush_cache() {
        self::$single_cache = array();
        self::$svc_closed_cache = array();
        self::$csr_flag_cache = array();
    }

    /**
     * Cache par requête des enfants flagués « cantine sans repas »
     * (children.cantine_sans_repas) — un booléen par enfant, lu en une
     * requête pour les résolutions en masse (declared_map).
     */
    private static $csr_flag_cache = array();

    /** Enfant flagué « cantine sans repas » ? (lut et mis en cache) */
    protected static function cantine_sans_repas_flag($child_id) {
        $child_id = (int) $child_id;
        if (!$child_id) return false;
        $flags = self::cantine_sans_repas_flags(array($child_id));
        return !empty($flags[$child_id]);
    }

    /**
     * Flags « cantine sans repas » d'une liste d'enfants, en UNE requête.
     * Colonne absente (base pas encore migrée) : tout le monde est
     * non-flagué — la lecture ne doit jamais casser la résolution.
     */
    protected static function cantine_sans_repas_flags(array $child_ids) {
        $child_ids = array_values(array_unique(array_filter(array_map('intval', $child_ids))));
        $missing = array();
        foreach ($child_ids as $cid) {
            if (!array_key_exists($cid, self::$csr_flag_cache)) $missing[] = $cid;
        }
        if ($missing) {
            global $wpdb;
            $t_child = psc_table('children');
            foreach ($missing as $cid) self::$csr_flag_cache[$cid] = false;
            $placeholders = implode(',', array_fill(0, count($missing), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, cantine_sans_repas FROM $t_child WHERE id IN ($placeholders)",
                $missing
            ));
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    self::$csr_flag_cache[(int) $r->id] = (int) $r->cantine_sans_repas === 1;
                }
            }
        }
        $out = array();
        foreach ($child_ids as $cid) {
            $out[$cid] = !empty(self::$csr_flag_cache[$cid]);
        }
        return $out;
    }
}
