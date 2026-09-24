<?php
/**
 * Script de vérification autonome (P2-09) — budget de requêtes des
 * lectures en masse, mesuré sur un effectif synthétique.
 *
 * Chaque lecture est mesurée à N puis 2N enfants : le nombre de requêtes
 * ne doit pas grandir avec l'effectif (aucun N+1) et doit rester sous le
 * budget fixé ci-dessous. Le temps et la mémoire sont affichés à titre
 * indicatif, sans seuil : ils dépendent de la machine.
 *
 * Lectures mesurées :
 *  - SIDSCM, semaine d'un service (Psc_Sidscm::week_children()) ;
 *  - planning en lot, un mois pour tous les enfants (declared_map()) ;
 *  - personnes autorisées en lot (authorized_for_children()) ;
 *  - écran Enfants du backoffice (Psc_Admin_Familles::page_children()).
 *
 * Usage :
 *   wp --require=bin/verify-query-budget.php verify-query-budget [--n=60]
 *
 * Données synthétiques scopées (adresses verify-budget-*), purgées avant
 * et après.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-query-budget', function ($args, $assoc) {

    if (!class_exists('Psc_Sidscm') || !method_exists('Psc_Sidscm', 'week_children')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    // Budget : requêtes maximales par lecture, quel que soit l'effectif.
    $budget = array(
        'SIDSCM, semaine'         => 25,
        'planning en lot, un mois' => 12,
        'personnes autorisées'    => 5,
        'écran Enfants'           => 40,
    );
    // Tolérance d'un effectif au double (requêtes de cache différées).
    $tolerance = 2;

    global $wpdb;
    $n = max(10, (int) ($assoc['n'] ?? 60));
    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_pat    = psc_table('pattern');
    $t_pick   = psc_table('pickup_persons');

    $purge = function () use ($wpdb, $t_parent, $t_child, $t_pat, $t_pick) {
        $pids = $wpdb->get_col("SELECT id FROM $t_parent WHERE email LIKE 'verify-budget-%'");
        if (!$pids) return;
        $ph = implode(',', array_map('intval', $pids));
        $cids = $wpdb->get_col("SELECT id FROM $t_child WHERE parent_id IN ($ph)");
        if ($cids) {
            $cph = implode(',', array_map('intval', $cids));
            $wpdb->query("DELETE FROM $t_pat WHERE child_id IN ($cph)");
            $wpdb->query("DELETE FROM $t_pick WHERE child_id IN ($cph)");
            $wpdb->query('DELETE FROM ' . psc_table('child_school_years') . " WHERE child_id IN ($cph)");
            $wpdb->query("DELETE FROM $t_child WHERE id IN ($cph)");
        }
        $wpdb->query("DELETE FROM $t_parent WHERE id IN ($ph)");
    };
    $purge();

    $config = Psc_School_Year::active();
    if (!$config) WP_CLI::error('Aucune configuration d’année scolaire.');
    $year_id = Psc_School_Years::active_id();

    // Semaine d'école de l'année configurée.
    $week = null;
    $cursor = strtotime(psc_week_start($config->date_start));
    for ($i = 0; $i < 52 && $week === null; $i++) {
        $monday = date('Y-m-d', $cursor + $i * WEEK_IN_SECONDS);
        if (psc_open_days($monday)) $week = $monday;
    }
    if ($week === null) WP_CLI::error('Aucune semaine d’école dans l’année configurée.');
    $open_days = psc_open_days($week);
    $month_dates = Psc_School_Year::school_days_in_month(substr($week, 0, 7));

    $created = array();
    $grow = function ($target) use (&$created, $wpdb, $t_parent, $t_child, $t_pat, $t_pick, $config, $year_id) {
        $now = current_time('mysql');
        while (count($created) < $target) {
            $i = count($created);
            $wpdb->insert($t_parent, array('email' => "verify-budget-$i@example.invalid", 'nom' => "Budget$i", 'active' => 1, 'created_at' => $now));
            $wpdb->insert($t_child, array('parent_id' => (int) $wpdb->insert_id, 'nom' => "Budget$i", 'prenom' => 'Enfant', 'statut' => 'actif', 'created_at' => $now));
            $cid = (int) $wpdb->insert_id;
            foreach (array(1, 2, 4, 5) as $wd) {
                foreach (array('CANT', 'GS') as $svc) {
                    $wpdb->insert($t_pat, array('child_id' => $cid, 'school_year' => $config->year_key, 'weekday' => $wd, 'service_code' => $svc, 'created_at' => $now, 'updated_at' => $now));
                }
            }
            foreach (array('Mamie', 'Voisin') as $lien) {
                $wpdb->insert($t_pick, array('child_id' => $cid, 'nom' => "Budget$i", 'prenom' => $lien, 'lien' => $lien, 'telephone' => '0600000000', 'statut' => 'active', 'created_at' => $now, 'updated_at' => $now));
            }
            if ($year_id) {
                Psc_School_Years::enroll($cid, $year_id, 'CE1', 'inscrit');
                // Une assurance par enfant : l'écran Enfants lisait son statut enfant par enfant.
                Psc_Assurances::upsert_row($cid, 'verify-budget/assurance.pdf', 'assurance.pdf', $year_id);
            }
            $created[] = $cid;
        }
    };

    $admins = get_users(array('role' => 'administrator', 'number' => 1));
    $measure = function ($label, callable $fn) use ($wpdb) {
        Psc_Planning::flush_cache();
        Psc_School_Year::flush_cache();
        $before = $wpdb->num_queries;
        $t0 = microtime(true);
        $m0 = memory_get_usage();
        $fn();
        return array(
            'requetes' => $wpdb->num_queries - $before,
            'ms'       => (int) round((microtime(true) - $t0) * 1000),
            'ko'       => (int) round((memory_get_usage() - $m0) / 1024),
        );
    };
    $readings = function () use ($measure, &$created, $open_days, $month_dates, $admins) {
        return array(
            'SIDSCM, semaine' => $measure('sidscm', function () use ($open_days) { Psc_Sidscm::week_children($open_days); }),
            'planning en lot, un mois' => $measure('planning', function () use (&$created, $month_dates) { Psc_Planning::declared_map($created, $month_dates); }),
            'personnes autorisées' => $measure('pickup', function () use (&$created) { Psc_Pickup_Persons::authorized_for_children($created); }),
            'écran Enfants' => $measure('enfants', function () use ($admins) {
                wp_set_current_user((int) $admins[0]->ID);
                ob_start();
                Psc_Admin_Familles::page_children();
                ob_end_clean();
                wp_set_current_user(0);
            }),
        );
    };

    $failures = array();
    try {
        $grow($n);
        $small = $readings();
        $grow(2 * $n);
        $large = $readings();

        foreach ($budget as $label => $max) {
            $a = $small[$label];
            $b = $large[$label];
            WP_CLI::log(sprintf('%-26s %3d → %3d requêtes (N=%d → %d) · %d ms · %d Ko', $label, $a['requetes'], $b['requetes'], $n, 2 * $n, $b['ms'], $b['ko']));
            if ($b['requetes'] - $a['requetes'] > $tolerance) {
                $failures[] = "$label : $a[requetes] → $b[requetes] requêtes quand l'effectif double (N+1)";
            }
            if ($b['requetes'] > $max) {
                $failures[] = "$label : $b[requetes] requêtes, budget $max";
            }
        }
    } finally {
        $purge();
    }

    if ($failures) {
        foreach ($failures as $failure) WP_CLI::warning($failure);
        WP_CLI::error(count($failures) . ' dépassement(s) de budget.');
    }
    WP_CLI::success('Budget de requêtes respecté pour ' . count($budget) . ' lectures.');
});
