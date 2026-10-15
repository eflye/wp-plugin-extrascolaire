<?php
/**
 * Script de vérification autonome — conservation des factures (RGPD-08).
 * Même rôle que bin/verify-promotion-logic.php.
 *
 * Vérifie que la purge des factures expirées :
 *   - ne fait rien tant que la mairie ne l'a pas activée ;
 *   - compte les années de facturation révolues, 10 ans au minimum ;
 *   - supprime en entier une facture expirée (ligne, versions archivées,
 *     PDF) et laisse intactes les factures plus récentes ;
 *   - supprime une fiche anonymisée qui n'a plus ni facture ni enfant, et
 *     jamais une fiche active ;
 *   - est décrite par le registre de conservation et comptée par la
 *     simulation (Psc_Retention::run()).
 *
 * Usage :
 *   wp --require=bin/verify-invoice-retention.php verify-invoice-retention
 *
 * L'horloge est injectée ($now en 2001) : seules des factures antérieures
 * à 1991 seraient purgées, c'est-à-dire uniquement les factures de test.
 * Familles et mois de test dédiés, purgés avant et après ; les deux options
 * de conservation sont remises à leur état d'origine.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-invoice-retention', function () {

    if (!method_exists('Psc_Retention', 'purge_expired_invoices')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_inv = psc_table('invoices');
    $t_invv = psc_table('invoice_versions');
    $t_parent = psc_table('parents');
    $now = strtotime('2001-06-15 12:00:00');
    $old_month = '1989-05';
    $recent_month = '1995-03';
    $email_active = 'verify-invoice-retention@example.invalid';
    $opts = array('psc_invoice_retention_enabled', 'psc_invoice_retention_years');
    $saved = array();
    foreach ($opts as $o) $saved[$o] = get_option($o, null);
    $created_parents = array();

    $purge = function () use ($wpdb, $t_inv, $t_invv, $t_parent, $old_month, $recent_month, $email_active, &$created_parents) {
        foreach (array($old_month, $recent_month) as $m) {
            $paths = array_merge(
                $wpdb->get_col($wpdb->prepare("SELECT pdf_path FROM $t_inv WHERE mois = %s", $m)),
                $wpdb->get_col($wpdb->prepare("SELECT pdf_path FROM $t_invv WHERE mois = %s", $m))
            );
            foreach ($paths as $rel) {
                if ($rel) @unlink(psc_private_path($rel)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
            $wpdb->delete($t_invv, array('mois' => $m));
            $wpdb->delete($t_inv, array('mois' => $m));
            $dir = psc_private_path('periscolaire/factures/' . $m);
            if ($dir && is_dir($dir)) @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        $wpdb->delete($t_parent, array('email' => $email_active));
        foreach ($created_parents as $pid) $wpdb->delete($t_parent, array('id' => (int) $pid));
    };
    $purge();

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    $invoice = function ($pid, $mois, $tag) use ($wpdb, $t_inv) {
        $rel = 'periscolaire/factures/' . $mois . "/verify-retention-$tag.pdf";
        wp_mkdir_p(dirname(psc_private_path($rel)));
        file_put_contents(psc_private_path($rel), "%PDF-1.4\n%%EOF\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $wpdb->insert($t_inv, array('parent_id' => $pid, 'mois' => $mois, 'total' => 10, 'pdf_path' => $rel, 'sent_at' => current_time('mysql'), 'created_at' => current_time('mysql')));
        return array((int) $wpdb->insert_id, $rel);
    };
    $exists = function ($table, $id) use ($wpdb) {
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id = %d", $id));
    };

    try {
        // Fiche anonymisée (famille supprimée) gardée pour sa seule facture,
        // qui a une version archivée.
        $wpdb->insert($t_parent, array('email' => 'verify-retention-tmp@example.invalid', 'active' => 0, 'created_at' => current_time('mysql')));
        $anon = (int) $wpdb->insert_id;
        $created_parents[] = $anon;
        $wpdb->update($t_parent, Psc_Privacy::anonymized_parent_fields($anon), array('id' => $anon));
        list($anon_inv, $anon_pdf) = $invoice($anon, $old_month, 'anon');
        $archived_pdf = 'periscolaire/factures/' . $old_month . '/verify-retention-anon-v1.pdf';
        file_put_contents(psc_private_path($archived_pdf), "%PDF-1.4\n%%EOF\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $wpdb->insert($t_invv, array('invoice_id' => $anon_inv, 'parent_id' => $anon, 'mois' => $old_month, 'version' => 1, 'total' => 8, 'pdf_path' => $archived_pdf, 'sent_at' => current_time('mysql'), 'created_at' => current_time('mysql'), 'archived_at' => current_time('mysql')));

        // Famille active : une facture expirée, une facture récente.
        $wpdb->insert($t_parent, array('email' => $email_active, 'nom' => 'VerifyRetention', 'active' => 1, 'created_at' => current_time('mysql')));
        $active = (int) $wpdb->insert_id;
        $created_parents[] = $active;
        list($old_inv, $old_pdf) = $invoice($active, $old_month, 'old');
        list($recent_inv, $recent_pdf) = $invoice($active, $recent_month, 'recent');

        // Durée : 10 ans au minimum, années révolues.
        update_option('psc_invoice_retention_years', 5);
        $check(Psc_Retention::invoice_retention_years() === 10, 'durée inférieure à 10 ans acceptée');
        update_option('psc_invoice_retention_years', 10);
        $check(Psc_Retention::invoice_retention_cutoff_month($now) === '1991-01', 'premier mois conservé inexact : ' . Psc_Retention::invoice_retention_cutoff_month($now));

        // Désactivée (défaut) : rien n'est supprimé, registre inactif.
        delete_option('psc_invoice_retention_enabled');
        $result = Psc_Retention::purge_expired_invoices($now);
        $check($result === array('invoices' => 0, 'versions' => 0, 'families' => 0), 'désactivée : purge exécutée ' . wp_json_encode($result));
        $check($exists($t_inv, $old_inv) && file_exists(psc_private_path($old_pdf)), 'désactivée : facture expirée supprimée');
        $policies = psc_retention_policies();
        $check(empty($policies['invoices']['enabled']), 'désactivée : catégorie factures active au registre');

        // Activée.
        update_option('psc_invoice_retention_enabled', 1);
        $policies = psc_retention_policies();
        $check(!empty($policies['invoices']['enabled']) && (int) $policies['invoices']['days'] >= 3650 && $policies['invoices']['hook'] === 'psc_purge_departed_children', 'activée : registre inexact');
        $report = Psc_Retention::run('simulation', $now);
        $sim = 0;
        foreach ($report['categories'] as $cat) if ($cat['key'] === 'invoices') $sim = (int) $cat['examined'];
        $check($sim >= 2, "simulation : $sim facture(s) comptée(s) au lieu d'au moins 2");
        $check($exists($t_inv, $old_inv), 'simulation : une facture a été supprimée');

        $result = Psc_Retention::purge_expired_invoices($now);
        $check($result['invoices'] >= 2 && $result['versions'] >= 1 && $result['families'] >= 1, 'activée : bilan inexact ' . wp_json_encode($result));
        $check(!$exists($t_inv, $anon_inv) && !$exists($t_inv, $old_inv), 'activée : facture expirée conservée');
        $check(!file_exists(psc_private_path($anon_pdf)) && !file_exists(psc_private_path($old_pdf)), 'activée : PDF expiré conservé');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_invv WHERE invoice_id = %d", $anon_inv)) === 0 && !file_exists(psc_private_path($archived_pdf)), 'activée : version archivée conservée');
        $check($exists($t_inv, $recent_inv) && file_exists(psc_private_path($recent_pdf)), 'activée : facture récente supprimée');
        $check(!$exists($t_parent, $anon), 'activée : fiche anonymisée sans facture conservée');
        $check($exists($t_parent, $active), 'activée : famille active supprimée');

        // Rejouée : plus rien à faire, aucune erreur.
        $again = Psc_Retention::purge_expired_invoices($now);
        $check($again['invoices'] === 0, 'rejouée : nouvelle suppression ' . wp_json_encode($again));
    } finally {
        foreach ($saved as $o => $v) {
            if ($v === null) delete_option($o); else update_option($o, $v);
        }
        $purge();
    }

    if ($failures) {
        foreach ($failures as $failure) WP_CLI::warning($failure);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Conservation des factures : $checks vérifications.");
});
