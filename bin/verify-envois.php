<?php
/**
 * Script de vérification autonome (P2-04) — envois suivis par
 * destinataire, sans succès fictif ni doublon. Même rôle que
 * bin/verify-promotion-logic.php : ce script joue celui du test unitaire,
 * en conditions WP-CLI réelles.
 *
 * Le serveur d'envoi est simulé par le filtre pre_wp_mail : aucun e-mail
 * ne part, chaque tentative est comptée et peut être refusée (coupure,
 * adresse refusée).
 *
 * Vérifie :
 *  - menus : échec partiel → bilan exact, menu non marqué envoyé ; double
 *    soumission du même formulaire → aucun renvoi ; relance → seuls les
 *    échecs repartent ; coupure totale → aucun succès ; envoi interrompu
 *    → repris par la tâche planifiée, qui complète le lot ;
 *  - factures : double soumission → un seul mail ; renvoi volontaire →
 *    nouveau mail ; PDF manquant → échec « no_file » ; datation de l'envoi
 *    en échec → pas de renvoi en double ;
 *  - commande fournisseur : enregistrement impossible → rien n'est
 *    envoyé ; mail refusé → commande enregistrée, en échec, relançable
 *    sans nouvelle commande ; double soumission → une seule commande ;
 *  - aucune adresse dans les causes d'échec enregistrées.
 *
 * Usage :
 *   wp --require=bin/verify-envois.php verify-envois
 *
 * Données scopées (adresses dédiées, semaines et mois lointains),
 * purgées avant et après.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

class Psc_Verify_Envois_Stop extends Exception {}

WP_CLI::add_command('verify-envois', function () {

    if (!class_exists('Psc_Envois') || !class_exists('Psc_Menus')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_env    = psc_table('envois');
    $t_menu   = psc_table('menus');
    $t_inv    = psc_table('invoices');
    $t_sup    = psc_table('supplier_orders');
    $emails   = array('verify-envois-1@example.invalid', 'verify-envois-2@example.invalid', 'verify-envois-3@example.invalid');
    $week     = '2090-01-02';
    $mois     = '2090-01';
    $supplier = 'verify-envois-fournisseur@example.invalid';

    // Destinataires d'un menu : familles d'un enfant inscrit à une année
    // ouverte. Année de la semaine de test (rentrée 2089), en préparation,
    // propre à ce script.
    $year_key = Psc_School_Years::key_for_start($week);
    $purge = function () use ($wpdb, $emails, $t_parent, $t_child, $t_env, $t_menu, $t_inv, $t_sup, $week, $supplier, $year_key) {
        $year = Psc_School_Years::get_by_key($year_key);
        if ($year && $year->statut !== 'active') Psc_School_Years::delete((int) $year->id);
        foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_menu WHERE semaine_debut = %s", $week)) as $id) {
            $wpdb->delete($t_env, array('objet_type' => 'menu', 'objet_id' => (int) $id));
            $wpdb->delete($t_menu, array('id' => (int) $id));
        }
        foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_sup WHERE supplier_email = %s", $supplier)) as $id) {
            $wpdb->delete($t_env, array('objet_type' => 'commande_fournisseur', 'objet_id' => (int) $id));
            $wpdb->delete($t_sup, array('id' => (int) $id));
        }
        foreach ($emails as $email) {
            $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_parent WHERE email = %s", $email));
            if (!$pid) continue;
            foreach ($wpdb->get_results($wpdb->prepare("SELECT id, pdf_path FROM $t_inv WHERE parent_id = %d", $pid)) as $inv) {
                if ($inv->pdf_path) @unlink(psc_private_path($inv->pdf_path)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                $wpdb->delete($t_env, array('objet_type' => 'facture', 'objet_id' => (int) $inv->id));
                $wpdb->delete($t_inv, array('id' => (int) $inv->id));
            }
            $wpdb->delete($t_env, array('famille_id' => $pid));
            $wpdb->delete($t_child, array('parent_id' => $pid));
            $wpdb->delete($t_parent, array('id' => $pid));
        }
    };
    $purge();

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    // Serveur d'envoi simulé : refuse les adresses listées, ou tout en cas de coupure.
    $sent = array();
    $refuse = array();
    $coupure = false;
    add_filter('pre_wp_mail', function ($null, $atts) use (&$sent, &$refuse, &$coupure) {
        $to = is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to'];
        if ($coupure || in_array($to, $refuse, true)) return false;
        $sent[] = $to;
        return true;
    }, 99, 2);
    $count = function ($to) use (&$sent) {
        return count(array_filter($sent, function ($s) use ($to) { return $s === $to; }));
    };

    $year_id = Psc_School_Years::create('2089-09-01', '2090-07-03');
    if (is_wp_error($year_id)) WP_CLI::error('Année de test : ' . $year_id->get_error_message());
    $pids = array();
    foreach ($emails as $i => $email) {
        $wpdb->insert($t_parent, array('email' => $email, 'nom' => 'VerifyEnvois' . $i, 'active' => 1, 'created_at' => current_time('mysql')));
        $pids[$email] = (int) $wpdb->insert_id;
        $wpdb->insert($t_child, array('parent_id' => $pids[$email], 'nom' => 'VerifyEnvois', 'prenom' => 'Enfant' . $i, 'created_at' => current_time('mysql')));
        Psc_School_Years::enroll((int) $wpdb->insert_id, $year_id, 'CP');
    }
    $row_statut = function ($type, $objet_id, $pid, $lot) use ($wpdb, $t_env) {
        return $wpdb->get_var($wpdb->prepare("SELECT statut FROM $t_env WHERE objet_type = %s AND objet_id = %d AND famille_id = %d AND lot = %s", $type, $objet_id, $pid, $lot));
    };

    try {
        // ---------------- Menus ----------------
        $wpdb->insert($t_menu, array('semaine_debut' => $week, 'lundi' => 'Soupe', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')));
        $menu = Psc_Menus::get((int) $wpdb->insert_id);
        list($e1, $e2, $e3) = $emails;

        // 1. Échec partiel : la 2e famille est refusée.
        $refuse = array($e2);
        $bilan = Psc_Menus::send($menu, 'verif-menu-a');
        $check($row_statut('menu', $menu->id, $pids[$e1], 'verif-menu-a') === 'accepte', 'menu : famille 1 non acceptée');
        $check($row_statut('menu', $menu->id, $pids[$e2], 'verif-menu-a') === 'echec', 'menu : échec de la famille 2 non enregistré');
        $check($bilan[Psc_Envois::ECHEC] >= 1 && $bilan[Psc_Envois::ACCEPTE] === $bilan['total'] - $bilan[Psc_Envois::ECHEC], 'menu : bilan inexact ' . wp_json_encode($bilan));
        $check(!$wpdb->get_var($wpdb->prepare("SELECT sent_at FROM $t_menu WHERE id = %d", $menu->id)), 'menu : marqué envoyé malgré un échec');

        // 2. Double soumission du même formulaire : aucun renvoi.
        $before = $count($e1);
        Psc_Menus::send($menu, 'verif-menu-a');
        $check($count($e1) === $before, 'menu : double soumission renvoyée à une famille déjà servie');

        // 3. Relance : seuls les échecs repartent, puis le menu est envoyé.
        $refuse = array();
        $before1 = $count($e1);
        $bilan = Psc_Menus::relancer($menu);
        $check($count($e1) === $before1 && $count($e2) === 1, 'menu : la relance a renvoyé autre chose que les échecs');
        $check($bilan[Psc_Envois::ECHEC] === 0 && $bilan[Psc_Envois::ACCEPTE] === $bilan['total'], 'menu : relance incomplète ' . wp_json_encode($bilan));
        $check((bool) $wpdb->get_var($wpdb->prepare("SELECT sent_at FROM $t_menu WHERE id = %d", $menu->id)), 'menu : non marqué envoyé après relance complète');

        // 4. Coupure totale : aucun succès annoncé.
        $coupure = true;
        $bilan = Psc_Menus::send($menu, 'verif-menu-b');
        $coupure = false;
        $check($bilan[Psc_Envois::ACCEPTE] === 0 && $bilan[Psc_Envois::ECHEC] === $bilan['total'], 'menu : coupure SMTP, succès annoncé ' . wp_json_encode($bilan));

        // 5. Envoi interrompu (requête coupée avant l'envoi) : repris par la tâche planifiée.
        $wpdb->update($t_menu, array('sent_at' => null), array('id' => (int) $menu->id));
        $wpdb->query($wpdb->prepare("UPDATE $t_env SET statut = 'a_envoyer', tentatives = 0, updated_at = %s WHERE objet_type = 'menu' AND objet_id = %d AND lot = 'verif-menu-b'", gmdate('Y-m-d H:i:s', time() - 3600), $menu->id));
        $before3 = $count($e3);
        do {
            $done = Psc_Envois::reprendre();
        } while ($done > 0);
        $check($count($e3) === $before3 + 1, 'reprise : envoi interrompu non repris');
        $check(Psc_Envois::bilan('menu', (int) $menu->id, 'verif-menu-b')[Psc_Envois::A_ENVOYER] === 0, 'reprise : envois restés en attente');
        $check((bool) $wpdb->get_var($wpdb->prepare("SELECT sent_at FROM $t_menu WHERE id = %d", $menu->id)), 'reprise : menu non marqué envoyé une fois le lot complet');

        // ---------------- Factures ----------------
        require_once PSC_PATH . 'includes/fpdf/fpdf.php';
        $pdf = new FPDF(); $pdf->AddPage();
        $rel = 'factures/verify-envois-' . $pids[$e1] . '.pdf';
        wp_mkdir_p(dirname(psc_private_path($rel)));
        file_put_contents(psc_private_path($rel), $pdf->Output('S')); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $wpdb->insert($t_inv, array('parent_id' => $pids[$e1], 'mois' => $mois, 'total' => 12.5, 'pdf_path' => $rel, 'created_at' => current_time('mysql')));
        $inv_id = (int) $wpdb->insert_id;

        // 6. Double soumission : un seul mail.
        $before1 = $count($e1);
        $check(Psc_Invoices::send($inv_id, 'verif-facture-a') === true, 'facture : envoi refusé');
        $check(Psc_Invoices::send($inv_id, 'verif-facture-a') === true, 'facture : double soumission en échec');
        $check($count($e1) === $before1 + 1, 'facture : double soumission envoyée deux fois');
        $check((bool) $wpdb->get_var($wpdb->prepare("SELECT sent_at FROM $t_inv WHERE id = %d", $inv_id)), 'facture : non datée après envoi');

        // 7. Renvoi volontaire : nouveau lot, nouveau mail.
        Psc_Invoices::send($inv_id, 'verif-facture-b');
        $check($count($e1) === $before1 + 2, 'facture : renvoi volontaire non envoyé');

        // 8. Datation en échec après un envoi accepté : pas de renvoi en double.
        $wpdb->update($t_inv, array('sent_at' => null), array('id' => $inv_id));
        $sabotage = function ($sql) use ($t_inv) { return (strpos($sql, "UPDATE `$t_inv` SET `sent_at`") === 0) ? 'SELECT * FROM psc_table_inexistante' : $sql; };
        add_filter('query', $sabotage);
        $suppress = $wpdb->suppress_errors(true);
        Psc_Invoices::send($inv_id, 'verif-facture-c');
        $wpdb->suppress_errors($suppress);
        remove_filter('query', $sabotage);
        $after = $count($e1);
        Psc_Invoices::send($inv_id, 'verif-facture-c');
        $check($count($e1) === $after, 'facture : datation en échec, facture renvoyée en double');

        // 9. PDF manquant : échec identifié, aucun succès annoncé.
        @unlink(psc_private_path($rel)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $result = Psc_Invoices::send($inv_id, 'verif-facture-d');
        $check(is_wp_error($result) && $result->get_error_code() === 'no_file', 'facture : PDF manquant non signalé');

        // ---------------- Commande fournisseur ----------------
        $old_supplier = get_option('psc_supplier_email', '');
        update_option('psc_supplier_email', $supplier);
        $counts = function () use ($wpdb, $t_sup, $supplier) {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_sup WHERE supplier_email = %s", $supplier));
        };
        $monday = Psc_School_Year::school_days_in_month(substr(Psc_School_Year::active()->date_start, 0, 7));
        $semaine = psc_week_start($monday ? $monday[0] : $week);

        // 10. Enregistrement impossible : rien ne part.
        $sabotage_sup = function ($sql) use ($t_sup) { return (strpos($sql, "INSERT INTO `$t_sup`") === 0) ? 'SELECT * FROM psc_table_inexistante' : $sql; };
        add_filter('query', $sabotage_sup);
        $suppress = $wpdb->suppress_errors(true);
        $result = Psc_Supplier_Orders::send($semaine, 'verif-fournisseur-a');
        $wpdb->suppress_errors($suppress);
        remove_filter('query', $sabotage_sup);
        $check(is_wp_error($result) && $result->get_error_code() === 'psc_order_not_saved', 'fournisseur : échec d’enregistrement non signalé');
        $check($count($supplier) === 0, 'fournisseur : commande envoyée sans être enregistrée');

        // 11. Mail refusé : commande enregistrée, en échec ; double soumission sans seconde commande.
        $refuse = array($supplier);
        $result = Psc_Supplier_Orders::send($semaine, 'verif-fournisseur-b');
        $check(is_wp_error($result) && $result->get_error_code() === 'psc_mail_failed', 'fournisseur : échec du mail non signalé');
        $check($counts() === 1, 'fournisseur : commande en échec non enregistrée');
        Psc_Supplier_Orders::send($semaine, 'verif-fournisseur-b');
        $check($counts() === 1, 'fournisseur : double soumission, seconde commande créée');

        // 12. Relance : même commande, envoyée une fois, datée.
        $refuse = array();
        $order_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_sup WHERE supplier_email = %s", $supplier));
        $check(Psc_Supplier_Orders::relancer($order_id) === $order_id, 'fournisseur : relance en échec');
        $check($count($supplier) === 1 && $counts() === 1, 'fournisseur : relance a créé ou renvoyé en double');
        $check((bool) $wpdb->get_var($wpdb->prepare("SELECT sent_at FROM $t_sup WHERE id = %d", $order_id)), 'fournisseur : commande non datée après relance');
        update_option('psc_supplier_email', $old_supplier);

        // 13. Aucune adresse dans les causes d'échec.
        $leak = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_env WHERE erreur LIKE '%@%'");
        $check($leak === 0, 'causes d’échec : une adresse e-mail est enregistrée');
    } finally {
        $purge();
    }

    if ($failures) {
        foreach ($failures as $failure) WP_CLI::warning($failure);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Envois : $checks vérifications.");
});
