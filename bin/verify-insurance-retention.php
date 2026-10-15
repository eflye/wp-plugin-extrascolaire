<?php
/**
 * Script de vérification autonome — attestations d'assurance des années
 * passées (RGPD-11). Même rôle que bin/verify-promotion-logic.php.
 *
 * Vérifie que Psc_Retention::purge_past_year_insurance() :
 *   - conserve l'attestation jusqu'au 30 septembre qui suit la fin de son
 *     année scolaire, et la supprime (fichier et colonnes assurance_*) à
 *     partir du 1er octobre ;
 *   - conserve l'attestation d'une année pas encore terminée, et celle de
 *     l'année active même passé ce délai ;
 *   - conserve la ligne d'inscription elle-même ;
 *   - est comptée par la simulation (Psc_Retention::run()) et rejouable.
 *
 * Usage :
 *   wp --require=bin/verify-insurance-retention.php verify-insurance-retention
 *
 * Années (1990, 2090) et enfant de test dédiés, supprimés avant et après ;
 * l'année active réelle n'est jamais modifiée. La date du jour est fixée en
 * 1991 : aucune attestation réelle n'est jamais concernée.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-insurance-retention', function () {

    if (!method_exists('Psc_Retention', 'purge_past_year_insurance')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_years = psc_table('school_years');
    $t_cy = psc_table('child_school_years');
    $t_child = psc_table('children');
    $t_par = psc_table('parents');
    $email = 'verify-insurance-retention@example.invalid';
    $keys = array('1990-1991', '2090-2091');

    $purge = function () use ($wpdb, $t_years, $t_cy, $t_child, $t_par, $email, $keys) {
        foreach ($keys as $k) {
            $yid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_years WHERE year_key = %s", $k));
            if (!$yid) continue;
            foreach ($wpdb->get_col($wpdb->prepare("SELECT assurance_file_path FROM $t_cy WHERE school_year_id = %d", $yid)) as $rel) {
                if ($rel) @unlink(psc_private_path($rel)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
            $wpdb->delete($t_cy, array('school_year_id' => $yid));
            $wpdb->delete($t_years, array('id' => $yid));
        }
        $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_par WHERE email = %s", $email));
        if ($pid) {
            $wpdb->delete($t_child, array('parent_id' => $pid));
            $wpdb->delete($t_par, array('id' => $pid));
        }
    };
    $purge();

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    $year = function ($key, $debut, $fin) use ($wpdb, $t_years) {
        $wpdb->insert($t_years, array('year_key' => $key, 'date_debut' => $debut, 'date_fin' => $fin, 'statut' => 'archivee', 'created_at' => current_time('mysql')));
        return (int) $wpdb->insert_id;
    };
    $attest = function ($child, $year_id, $rentree) use ($wpdb, $t_cy) {
        $rel = Psc_Assurances::rel_path($child, $rentree, 'pdf');
        wp_mkdir_p(dirname(psc_private_path($rel)));
        file_put_contents(psc_private_path($rel), "%PDF-1.4\n%%EOF\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $wpdb->insert($t_cy, array(
            'child_id' => $child, 'school_year_id' => $year_id, 'classe' => 'CP', 'statut' => 'inscrit',
            'assurance_file_path' => $rel, 'assurance_original_filename' => 'attestation.pdf',
            'assurance_uploaded_at' => current_time('mysql'), 'assurance_status' => 'approved', 'assurance_revision' => 'rev-test',
        ));
        return array((int) $wpdb->insert_id, $rel);
    };
    $row = function ($id) use ($wpdb, $t_cy) {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_cy WHERE id = %d", $id));
    };

    $sept30 = strtotime('1991-09-30 12:00:00');
    $oct1 = strtotime('1991-10-01 12:00:00');

    try {
        $wpdb->insert($t_par, array('email' => $email, 'nom' => 'VerifyInsurance', 'active' => 1, 'created_at' => current_time('mysql')));
        $pid = (int) $wpdb->insert_id;
        $wpdb->insert($t_child, array('parent_id' => $pid, 'nom' => 'VerifyInsurance', 'prenom' => 'Test', 'created_at' => current_time('mysql')));
        $child = (int) $wpdb->insert_id;

        $past = $year('1990-1991', '1990-09-01', '1991-07-05');
        $future = $year('2090-2091', '2090-09-01', '2091-07-05');
        list($past_cy, $past_rel) = $attest($child, $past, 1990);
        list($future_cy, $future_rel) = $attest($child, $future, 2090);

        // Simulation : l'attestation de 1990 est comptée, rien n'est supprimé.
        $sim = 0;
        foreach (Psc_Retention::run()['categories'] as $cat) if ($cat['key'] === 'private_files') $sim = (int) $cat['examined'];
        $check($sim >= 1, "simulation : $sim attestation(s) comptée(s)");
        $check(file_exists(psc_private_path($past_rel)), 'simulation : un fichier a été supprimé');

        // Année active dont la date de fin serait passée (mairie pas encore
        // passée à l'année suivante) : jamais purgée. L'année active est
        // simulée par la mémoïsation de Psc_School_Years, jamais en base.
        $active_before = (int) Psc_School_Years::active_id();
        $memo = new ReflectionProperty('Psc_School_Years', 'active');
        $memo->setAccessible(true);
        $memo->setValue(null, $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_years WHERE id = %d", $past)));
        $check((int) Psc_School_Years::active_id() === $past, 'simulation de l’année active impossible');
        Psc_Retention::purge_past_year_insurance($oct1);
        $check(file_exists(psc_private_path($past_rel)) && $row($past_cy)->assurance_file_path !== null, 'année active : attestation supprimée');
        Psc_School_Years::flush_cache();
        $check((int) Psc_School_Years::active_id() === $active_before, 'année active réelle modifiée par le test');

        // Jusqu'au 30 septembre qui suit la fin de l'année : conservée.
        $kept = Psc_Retention::purge_past_year_insurance($sept30);
        $check($kept === 0 && file_exists(psc_private_path($past_rel)) && $row($past_cy)->assurance_file_path === $past_rel, '30 septembre : attestation déjà supprimée');

        // À partir du 1er octobre : supprimée.
        $done = Psc_Retention::purge_past_year_insurance($oct1);
        $after = $row($past_cy);
        $check($done >= 1, "purge : $done attestation(s) supprimée(s)");
        $check(!file_exists(psc_private_path($past_rel)), 'année terminée : fichier conservé');
        $check($after && $after->assurance_file_path === null && $after->assurance_original_filename === null && $after->assurance_uploaded_at === null && $after->assurance_revision === '', 'année terminée : colonnes assurance_* non vidées');
        $check($after && $after->statut === 'inscrit', 'année terminée : ligne d’inscription supprimée');
        $check(file_exists(psc_private_path($future_rel)) && $row($future_cy)->assurance_file_path === $future_rel, 'année non terminée : attestation supprimée');

        $again = Psc_Retention::purge_past_year_insurance($oct1);
        $check($row($past_cy)->assurance_file_path === null && file_exists(psc_private_path($future_rel)), 'rejouée : état modifié');
        unset($again);
    } finally {
        $purge();
    }

    if ($failures) {
        foreach ($failures as $failure) WP_CLI::warning($failure);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Attestations d'assurance des années passées : $checks vérifications.");
});
