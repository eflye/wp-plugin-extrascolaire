<?php
/**
 * Script de vérification autonome du délai de modification (P1-14) —
 * même rôle que bin/verify-promotion-logic.php : ce script joue celui du
 * test unitaire, en conditions WP-CLI réelles.
 *
 * Vérifie que psc_is_locked() compare des timestamps Unix réels, et que
 * psc_lock_message() affiche exactement l'instant contrôlé : juste avant,
 * à et après l'échéance, à Paris en hiver et en été, aux deux changements
 * d'heure, sur un site en UTC, et avec un délai nul (verrou désactivé).
 *
 * Usage :
 *   wp --require=bin/verify-lock-clock.php verify-lock-clock
 *
 * Ne touche qu'à l'option timezone_string, restaurée en fin de run, et
 * passe par les filtres psc_now_ts / psc_lock_hours : aucune donnée écrite.
 * Les filtres retirés (horloge figée comprise) ne le sont que dans ce
 * processus WP-CLI.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-lock-clock', function () {

    if (!function_exists('psc_is_locked')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    $old_tz = get_option('timezone_string');
    $old_offset = get_option('gmt_offset');
    $now = null;
    $hours = 48;
    // L'environnement de test fige l'horloge (mu-plugins/psc-frozen-clock.php) :
    // on la retire pour mesurer l'horloge réelle, puis on injecte la nôtre.
    remove_all_filters('psc_now_ts');
    add_filter('psc_now_ts', function ($ts) use (&$now) { return $now === null ? $ts : $now; }, 99);
    add_filter('psc_lock_hours', function () use (&$hours) { return $hours; }, 99);

    // psc_now_ts() est un vrai timestamp Unix, quel que soit le fuseau.
    update_option('timezone_string', 'Europe/Paris');
    $check(abs(psc_now_ts() - time()) <= 5, 'psc_now_ts() décalé de time() à Paris');

    // [fuseau, jour de service, échéance attendue en heure locale, en UTC]
    $cases = array(
        'Paris, hiver'                   => array('Europe/Paris', '2026-01-15', '2026-01-13 00:00', '2026-01-12 23:00'),
        'Paris, été'                     => array('Europe/Paris', '2026-07-15', '2026-07-13 00:00', '2026-07-12 22:00'),
        // Passage à l'heure d'été le 29 mars 2026 : 47 h de calendrier.
        'Paris, passage à l’heure d’été' => array('Europe/Paris', '2026-03-30', '2026-03-27 23:00', '2026-03-27 22:00'),
        // Passage à l'heure d'hiver le 25 octobre 2026 : 49 h de calendrier.
        'Paris, passage à l’heure d’hiver' => array('Europe/Paris', '2026-10-26', '2026-10-24 01:00', '2026-10-23 23:00'),
        'site en UTC'                    => array('UTC', '2026-01-15', '2026-01-13 00:00', '2026-01-13 00:00'),
    );

    foreach ($cases as $label => list($tz, $day, $local, $utc)) {
        update_option('timezone_string', $tz);
        $hours = 48;
        $now = null;
        $deadline = psc_lock_deadline_ts($day);

        $check(gmdate('Y-m-d H:i', $deadline) === $utc, "$label : échéance UTC " . gmdate('Y-m-d H:i', $deadline) . " au lieu de $utc");
        $check(wp_date('Y-m-d H:i', $deadline) === $local, "$label : échéance locale " . wp_date('Y-m-d H:i', $deadline) . " au lieu de $local");

        $now = $deadline - 1;
        $check(!psc_is_locked($day), "$label : verrouillé une seconde avant l’échéance");
        $now = $deadline;
        $check(psc_is_locked($day), "$label : non verrouillé à l’échéance");
        $now = $deadline + 1;
        $check(psc_is_locked($day), "$label : non verrouillé après l’échéance");

        // Le message annonce l'instant contrôlé, en heure locale du site.
        $message = psc_lock_message($day);
        $expected_time = substr($local, 11);
        $check(strpos($message, $expected_time) !== false, "$label : message « $message » sans l’heure $expected_time");
        $check(strpos($message, wp_date('Y', $deadline)) !== false, "$label : message « $message » sans l’année de l’échéance");
    }

    // Délai nul : le verrou est désactivé, même le jour même.
    update_option('timezone_string', 'Europe/Paris');
    $hours = 0;
    $now = psc_lock_deadline_ts('2026-01-15') + 12 * HOUR_IN_SECONDS;
    $check(!psc_is_locked('2026-01-15'), 'délai nul : jour verrouillé');

    remove_all_filters('psc_now_ts');
    remove_all_filters('psc_lock_hours');
    update_option('timezone_string', $old_tz);
    update_option('gmt_offset', $old_offset);

    if ($failures) {
        foreach ($failures as $failure) WP_CLI::warning($failure);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Délai de modification : $checks vérifications.");
});
