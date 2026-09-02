<?php
/**
 * Vérification bloquante de la migration v4.0 (modèle « rythme + exceptions »).
 *
 * Usage :
 *   wp --require=bin/verify-planning-migration.php verify-planning-migration
 *
 * TEST BLOQUANT (facturation en jeu, pas de contrôle visuel) :
 * psc_is_declared() doit renvoyer exactement le même résultat que l'ancienne
 * table wp_psc_registrations sur toutes les lignes historiques. L'ancienne
 * table est conservée en lecture seule le temps d'un cycle de facturation —
 * ce script en est la seule utilisation légitime après la migration.
 *
 * Le script est idempotent et ne modifie RIEN en base. Code de sortie :
 *   0  tout est conforme (ou ancienne table absente : rien à vérifier) ;
 *   1  au moins une divergence — NE PAS GÉNÉRER DE FACTURES avant correction.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-planning-migration', function () {
    if (!class_exists('Psc_Planning')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_reg = psc_table('registrations');

    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t_reg))) {
        WP_CLI::success("Aucune ancienne table wp_psc_registrations (installation neuve) : rien à vérifier.");
        return;
    }

    $year = Psc_School_Year::active();
    if (!$year) {
        WP_CLI::error("Aucune configuration d'année scolaire : lancez la mise à jour du plugin (migrate_4_0_0) d'abord.");
    }

    $report = Psc_Planning::verify_against_registrations();

    WP_CLI::log('');
    WP_CLI::log("Vérification psc_is_declared() vs wp_psc_registrations");
    WP_CLI::log('  lignes historiques évaluées .......... ' . (int) $report['checked']);
    WP_CLI::log('  jours déclarés par le rythme sans ligne historique (attendu, seuil 60 %) : ' . (int) $report['extra']);
    WP_CLI::log('  anomalies (jours non scolaires / prestations fermées) : ' . count($report['anomalies']));
    WP_CLI::log('  DIVERGENCES (bloquant) ............... ' . count($report['mismatches']));

    if ($report['anomalies']) {
        WP_CLI::log('');
        WP_CLI::log('Anomalies — lignes historiques posées sur un jour non scolaire ou une prestation aujourd\'hui fermée. La résolution ne peut jamais les reproduire (règle 1) : à corriger à la main ou à archiver.');
        foreach (array_slice($report['anomalies'], 0, 20) as $line) {
            WP_CLI::log('  ' . $line);
        }
        if (count($report['anomalies']) > 20) {
            WP_CLI::log('  … ' . (count($report['anomalies']) - 20) . ' autre(s)');
        }
    }

    if ($report['mismatches']) {
        WP_CLI::log('');
        WP_CLI::log('Divergences — l\'ancienne table déclare ces jours, la résolution les renvoie à false :');
        foreach (array_slice($report['mismatches'], 0, 20) as $line) {
            WP_CLI::log('  enfant | date | service : ' . $line);
        }
        if (count($report['mismatches']) > 20) {
            WP_CLI::log('  … ' . (count($report['mismatches']) - 20) . ' autre(s)');
        }
        WP_CLI::error('ÉCHEC : la migration ne reproduit pas exactement l\'historique. Ne générez PAS de factures avant correction (relancer la migration est sûr : idempotente).');
    }

    WP_CLI::success('Migration conforme : psc_is_declared() renvoie le même résultat que l\'ancienne table sur toutes les lignes historiques.');
});
