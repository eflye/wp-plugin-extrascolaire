<?php
/**
 * Script de vérification autonome — suppression des factures d'un mois.
 * Même rôle que bin/verify-promotion-logic.php.
 *
 * Mode production (défaut) : seule la facture jamais envoyée est
 * supprimée ; la facture envoyée, la facture rectifiée après envoi et sa
 * version archivée (PDF compris) sont conservées.
 * Mode debug (option psc_invoice_debug_delete, activée par WP-CLI) : tout
 * le mois disparaît, versions archivées et PDF compris.
 *
 * Usage :
 *   wp --require=bin/verify-invoice-deletion.php verify-invoice-deletion
 *
 * Mois de test lointain et famille dédiée, purgés avant et après ; le
 * mode debug est toujours remis à son état d'origine.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-invoice-deletion', function () {

    if (!method_exists('Psc_Invoices', 'debug_delete_enabled')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $mois = '2091-02';
    $email = 'verify-invoice-deletion@example.invalid';
    $t_inv = psc_table('invoices');
    $t_invv = psc_table('invoice_versions');
    $t_parent = psc_table('parents');
    $debug_before = get_option('psc_invoice_debug_delete', null);

    $purge = function () use ($wpdb, $mois, $email, $t_inv, $t_invv, $t_parent) {
        foreach (array_merge($wpdb->get_col($wpdb->prepare("SELECT pdf_path FROM $t_inv WHERE mois = %s", $mois)), $wpdb->get_col($wpdb->prepare("SELECT pdf_path FROM $t_invv WHERE mois = %s", $mois))) as $rel) {
            if ($rel) @unlink(psc_private_path($rel)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        $wpdb->delete($t_invv, array('mois' => $mois));
        $wpdb->delete($t_inv, array('mois' => $mois));
        $wpdb->query($wpdb->prepare("DELETE FROM $t_parent WHERE email LIKE %s", str_replace('@', '%@', $email)));
    };
    $purge();

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    $create = function ($tag, $sent) use ($wpdb, $mois, $email, $t_inv, $t_parent) {
        $wpdb->insert($t_parent, array('email' => str_replace('@', "-$tag@", $email), 'nom' => "Verify$tag", 'active' => 1, 'created_at' => current_time('mysql')));
        $pid = (int) $wpdb->insert_id;
        $rel = 'periscolaire/factures/' . $mois . "/verify-$tag.pdf";
        wp_mkdir_p(dirname(psc_private_path($rel)));
        file_put_contents(psc_private_path($rel), "%PDF-1.4\n%%EOF\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $wpdb->insert($t_inv, array('parent_id' => $pid, 'mois' => $mois, 'total' => 10, 'pdf_path' => $rel, 'sent_at' => $sent ? current_time('mysql') : null, 'created_at' => current_time('mysql')));
        return array((int) $wpdb->insert_id, $rel, $pid);
    };

    try {
        list($unsent, $unsent_pdf) = $create('a', false);
        list($sent, $sent_pdf) = $create('b', true);
        // Rectifiée après envoi : ligne « à envoyer », version reçue archivée.
        list($rectified, $rect_pdf, $rect_pid) = $create('c', false);
        $archived_pdf = 'periscolaire/factures/' . $mois . '/verify-c-v1.pdf';
        file_put_contents(psc_private_path($archived_pdf), "%PDF-1.4\n%%EOF\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $wpdb->insert($t_invv, array('invoice_id' => $rectified, 'parent_id' => $rect_pid, 'mois' => $mois, 'version' => 1, 'total' => 8, 'pdf_path' => $archived_pdf, 'sent_at' => current_time('mysql'), 'created_at' => current_time('mysql'), 'archived_at' => current_time('mysql')));

        // Mode production.
        delete_option('psc_invoice_debug_delete');
        $result = Psc_Invoices::delete_month($mois);
        $check(!is_wp_error($result) && $result['deleted'] === 1 && $result['kept'] === 2 && !$result['debug'], 'production : bilan inexact ' . wp_json_encode($result));
        $check(!$wpdb->get_var($wpdb->prepare("SELECT id FROM $t_inv WHERE id = %d", $unsent)), 'production : facture non envoyée conservée');
        $check(!file_exists(psc_private_path($unsent_pdf)), 'production : PDF de la facture non envoyée conservé');
        $check((bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_inv WHERE id = %d", $sent)) && file_exists(psc_private_path($sent_pdf)), 'production : facture envoyée supprimée');
        $check((bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_inv WHERE id = %d", $rectified)), 'production : facture rectifiée après envoi supprimée');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_invv WHERE mois = %s", $mois)) === 1 && file_exists(psc_private_path($archived_pdf)), 'production : version archivée supprimée');

        // Mode debug.
        update_option('psc_invoice_debug_delete', 1);
        $result = Psc_Invoices::delete_month($mois);
        $check(!is_wp_error($result) && $result['deleted'] === 2 && $result['kept'] === 0 && $result['debug'], 'debug : bilan inexact ' . wp_json_encode($result));
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_inv WHERE mois = %s", $mois)) === 0, 'debug : factures restantes');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_invv WHERE mois = %s", $mois)) === 0, 'debug : versions archivées restantes');
        $check(!file_exists(psc_private_path($sent_pdf)) && !file_exists(psc_private_path($archived_pdf)), 'debug : PDF restants');
    } finally {
        if ($debug_before === null) delete_option('psc_invoice_debug_delete'); else update_option('psc_invoice_debug_delete', $debug_before);
        $purge();
    }

    if ($failures) {
        foreach ($failures as $failure) WP_CLI::warning($failure);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Suppression des factures : $checks vérifications.");
});
