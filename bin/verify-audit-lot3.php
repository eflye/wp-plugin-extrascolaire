<?php
/**
 * Script de vérification autonome — corrections d'audit du lot 3
 * (envois, calendrier, facturation, performance, administration), en
 * conditions WP-CLI réelles. Une section par constat ; chaque section
 * crée puis supprime ses propres données.
 *
 * Usage :
 *   wp --require=bin/verify-audit-lot3.php verify-audit-lot3
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-audit-lot3', function () {

    if (!class_exists('Psc_Mailer')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    // Capture des e-mails : aucun envoi réel.
    $mails = array();
    $capture = function ($null, $atts) use (&$mails) { $mails[] = $atts; return true; };
    add_filter('pre_wp_mail', $capture, 10, 2);

    $sections = array();

    /* SEC-02/L3 : avis à l'ancienne adresse, modèle éditable. */
    $sections['SEC-02/L3'] = function () use ($check, &$mails) {
        $option = Psc_Email_Templates::OPTION;
        $backup = get_option($option, null);
        try {
            $defaults = Psc_Email_Templates::defaults();
            $check(isset($defaults['email_change_old']), 'SEC-02 : modèle « email_change_old » absent');
            $check(in_array('{{nouvelle_adresse}}', $defaults['email_change_old']['vars'], true), 'SEC-02 : variable {{nouvelle_adresse}} non déclarée');

            delete_option($option);
            $mails = array();
            $ok = Psc_Mailer::send_email_change_notice_old('ancienne@example.invalid', 'nouvelle@example.invalid', 'https://example.invalid/?psc_cancel=abc&x=1');
            $check($ok === true, 'SEC-02 : envoi de l\'avis en échec');
            $check(count($mails) === 1 && $mails[0]['to'] === 'ancienne@example.invalid', 'SEC-02 : l\'avis ne part pas vers l\'ancienne adresse');
            $msg = $mails ? (string) $mails[0]['message'] : '';
            $check(strpos($msg, 'nouvelle@example.invalid') !== false, 'SEC-02 : la nouvelle adresse n\'apparaît pas dans l\'avis');
            $check(strpos($msg, esc_url('https://example.invalid/?psc_cancel=abc&x=1')) !== false, 'SEC-02 : lien d\'annulation absent de l\'avis');

            // Personnalisation prise en compte.
            update_option($option, array('email_change_old' => array('subject' => 'Sujet perso {{site}}', 'body' => 'Corps perso {{nouvelle_adresse}}')));
            $mails = array();
            Psc_Mailer::send_email_change_notice_old('ancienne@example.invalid', 'nouvelle@example.invalid', 'https://example.invalid/');
            $check($mails && strpos((string) $mails[0]['subject'], 'Sujet perso') === 0, 'SEC-02 : sujet personnalisé ignoré');
            $check($mails && strpos((string) $mails[0]['message'], 'Corps perso nouvelle@example.invalid') !== false, 'SEC-02 : corps personnalisé ignoré');

            // Adresse invalide : rien n'est envoyé.
            $mails = array();
            $check(Psc_Mailer::send_email_change_notice_old('pas-une-adresse', 'nouvelle@example.invalid', 'https://example.invalid/') === false && !$mails,
                'SEC-02 : avis envoyé à une adresse invalide');
        } finally {
            if ($backup === null) delete_option($option); else update_option($option, $backup);
        }
    };

    /* PRA-12 : détection commune des pages à shortcode, cache invalidé. */
    $sections['PRA-12'] = function () use ($check) {
        $sc = 'psc_verify_lot3_sc';
        $opt = 'psc_verify_lot3_page_id';
        add_shortcode($sc, '__return_empty_string'); // has_shortcode() exige un shortcode déclaré
        delete_option($opt);
        delete_transient('psc_page_lookup_' . $sc);
        $page = 0;
        try {
            $check(psc_shortcode_page_id($sc, $opt) === 0, 'PRA-12 : page trouvée alors qu\'aucune ne porte le shortcode');
            $check(get_transient('psc_page_lookup_' . $sc) !== false, 'PRA-12 : absence non mise en cache');

            // Page publiée après coup : save_post_page vide le cache.
            $page = (int) wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Verify lot3', 'post_content' => '[' . $sc . ']'));
            $check(has_action('save_post_page', 'psc_shortcode_page_id_flush') !== false, 'PRA-12 : invalidation non branchée sur save_post_page');
            // psc_shortcode_page_id_flush ne vide que les shortcodes connus : on simule pour le shortcode de test.
            delete_transient('psc_page_lookup_' . $sc);
            $check(psc_shortcode_page_id($sc, $opt) === $page, 'PRA-12 : page publiée non détectée');

            // Page dépubliée : le cache est revalidé, pas servi tel quel.
            wp_update_post(array('ID' => $page, 'post_status' => 'draft'));
            set_transient('psc_page_lookup_' . $sc, $page, DAY_IN_SECONDS);
            $check(psc_shortcode_page_id($sc, $opt) === 0, 'PRA-12 : page dépubliée encore servie depuis le cache');

            // Option explicite prioritaire.
            wp_update_post(array('ID' => $page, 'post_status' => 'publish'));
            $other = (int) wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Verify lot3 bis', 'post_content' => 'x'));
            update_option($opt, $other);
            $check(psc_shortcode_page_id($sc, $opt) === $other, 'PRA-12 : option de Réglages ignorée');
            wp_delete_post($other, true);

            // Flush réel sur les shortcodes du plugin.
            set_transient('psc_page_lookup_periscolaire_form', 999999, DAY_IN_SECONDS);
            do_action('save_post_page', $page, get_post($page), true);
            $check(get_transient('psc_page_lookup_periscolaire_form') === false, 'PRA-12 : save_post_page ne vide pas le cache du portail');
            $check(Psc_Mailer::form_page_url() !== '', 'PRA-12 : form_page_url vide');
        } finally {
            if ($page) wp_delete_post($page, true);
            delete_option($opt);
            delete_transient('psc_page_lookup_' . $sc);
            remove_shortcode($sc);
            psc_shortcode_page_id_flush();
        }
    };

    /* PERF-02 : réservation, quelques envois en ligne, le reste par psc_envois_lot. */
    $sections['PERF-02'] = function () use ($check) {
        global $wpdb;
        $t_par = psc_table('parents');
        $t_env = psc_table('envois');
        $type  = 'verify_lot3';
        $prefix = 'verify-lot3-envoi-';
        $purge = function () use ($wpdb, $t_par, $t_env, $type, $prefix) {
            $wpdb->query($wpdb->prepare("DELETE FROM $t_env WHERE objet_type = %s", $type));
            $wpdb->query($wpdb->prepare("DELETE FROM $t_par WHERE email LIKE %s", $wpdb->esc_like($prefix) . '%'));
        };
        $purge();
        $calls = array();
        $refuse = array();
        $sender = function ($objet_id, $famille_id) use (&$calls, &$refuse) {
            $calls[] = (int) $famille_id;
            return in_array((int) $famille_id, $refuse, true) ? new WP_Error('mail_refuse', 'refus') : true;
        };
        $senders = function ($s) use ($type, $sender) { $s[$type] = $sender; return $s; };
        add_filter('psc_envoi_senders', $senders);
        $lot = Psc_Envois::lot(); // UUID : la colonne lot est bornée à 40 caractères
        $n = 2 * Psc_Envois::INLINE_MAX + Psc_Envois::LOT_TAILLE + 3; // deux passages de tâche après deux soumissions
        try {
            $ids = array();
            for ($i = 0; $i < $n; $i++) {
                $wpdb->insert($t_par, array('email' => $prefix . $i . '@example.invalid', 'nom' => 'VerifyLot3', 'active' => 1, 'created_at' => current_time('mysql')));
                $ids[] = (int) $wpdb->insert_id;
            }
            $refuse = array($ids[$n - 1]);

            $bilan = Psc_Envois::lancer($type, 1, $lot, $ids);
            $check(count($calls) === Psc_Envois::INLINE_MAX, 'PERF-02 : ' . count($calls) . ' envois en ligne au lieu de ' . Psc_Envois::INLINE_MAX);
            $check($bilan[Psc_Envois::A_ENVOYER] === $n - Psc_Envois::INLINE_MAX && $bilan['total'] === $n, 'PERF-02 : bilan « en cours » inexact ' . wp_json_encode($bilan));
            $check((bool) wp_next_scheduled('psc_envois_lot', array($type, $lot)), 'PERF-02 : tâche psc_envois_lot non programmée');
            $check(has_action('psc_envois_lot', array('Psc_Envois', 'cron_lot')) !== false, 'PERF-02 : psc_envois_lot sans gestionnaire');

            // Double soumission du même lot : rien de neuf en ligne, pas de seconde tâche.
            Psc_Envois::lancer($type, 1, $lot, $ids);
            // (les envois encore jamais tentés du lot sont légitimement repris en ligne)
            $check(count($calls) === Psc_Envois::INLINE_MAX * 2, 'PERF-02 : double soumission inattendue (' . count($calls) . ')');
            $before = count($calls);

            // Tâche planifiée jouée comme WP-Cron : un paquet par passage, reprogrammée.
            wp_clear_scheduled_hook('psc_envois_lot', array($type, $lot));
            do_action('psc_envois_lot', $type, $lot);
            $check(count($calls) - $before === Psc_Envois::LOT_TAILLE,
                'PERF-02 : premier passage de ' . (count($calls) - $before) . ' envois');
            $check((bool) wp_next_scheduled('psc_envois_lot', array($type, $lot)), 'PERF-02 : tâche non reprogrammée alors qu\'il reste des envois');
            while (Psc_Envois::traiter_lot($type, $lot) > 0) {}
            wp_clear_scheduled_hook('psc_envois_lot', array($type, $lot));

            $per = array_count_values($calls);
            $check(count($per) === $n, 'PERF-02 : ' . count($per) . ' familles servies sur ' . $n);
            $check(max($per) === 1, 'PERF-02 : une famille a reçu deux envois');
            $bilan = Psc_Envois::bilan($type, 1, $lot);
            $check($bilan[Psc_Envois::ACCEPTE] === $n - 1 && $bilan[Psc_Envois::ECHEC] === 1 && $bilan[Psc_Envois::A_ENVOYER] === 0,
                'PERF-02 : bilan final inexact ' . wp_json_encode($bilan));
            $check(Psc_Envois::bilan_lot($type, $lot) == $bilan, 'PERF-02 : bilan_lot diffère du bilan de l\'objet');

            // Une ligne tentée puis restée a_envoyer (processus tué) n'est pas rejouée par la tâche.
            $wpdb->query($wpdb->prepare("UPDATE $t_env SET statut = %s WHERE objet_type = %s AND famille_id = %d", Psc_Envois::A_ENVOYER, $type, $ids[0]));
            $before = count($calls);
            Psc_Envois::traiter_lot($type, $lot);
            $check(count($calls) === $before, 'PERF-02 : la tâche rejoue une ligne déjà tentée (relève de la reprise)');

            // Relance des échecs : l'échec repart, rien d'autre.
            $refuse = array();
            $wpdb->query($wpdb->prepare("UPDATE $t_env SET statut = %s WHERE objet_type = %s AND famille_id = %d", Psc_Envois::ACCEPTE, $type, $ids[0]));
            $before = count($calls);
            $bilan = Psc_Envois::relancer_echecs($type, 1);
            $check(count($calls) === $before + 1 && $bilan[Psc_Envois::ACCEPTE] === $n, 'PERF-02 : relance des échecs inexacte ' . wp_json_encode($bilan));
        } finally {
            remove_filter('psc_envoi_senders', $senders);
            wp_clear_scheduled_hook('psc_envois_lot', array($type, $lot));
            $purge();
        }
    };

    try {
        foreach ($sections as $name => $run) {
            $run();
        }
    } finally {
        remove_filter('pre_wp_mail', $capture, 10);
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(sprintf('%d/%d vérifications en échec.', count($failures), $checks));
    }
    WP_CLI::success(sprintf('%d vérifications OK (%s).', $checks, implode(', ', array_keys($sections))));
});
