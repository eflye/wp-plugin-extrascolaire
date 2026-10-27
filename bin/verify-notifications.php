<?php
/**
 * Script de vérification autonome — notifications par e-mail : modèles
 * éditables du Mailer et envois suivis différés (Psc_Envois), en
 * conditions WP-CLI réelles. Aucun e-mail ne part (pre_wp_mail). Une
 * section par constat ; chaque section crée puis supprime ses données.
 *
 * Origine : audit du 14/10/2026, lot 3 (ex-bin/verify-audit-lot3.php,
 * scindé par domaine avec verify-school-calendar.php et
 * verify-performance.php). Les références renvoient à AUDIT.md.
 *
 * Vérifie :
 *  - SEC-02/L3 : avis à l'ancienne adresse lors d'un changement, modèle
 *    « email_change_old » éditable, adresse invalide ignorée ;
 *  - PERF-02 : quelques envois en ligne, le reste par la tâche
 *    psc_envois_lot, sans doublon, bilan exact, relance des échecs ;
 *  - PRA-10 : chaque notification passe par un modèle éditable ; la
 *    facture passe par le Mailer ;
 *  - RGPD-10/L3 : l'e-mail « nouvelle demande » ne transmet plus le
 *    détail des allergies ;
 *  - PERF-12/L3 : le suivi des envois est purgé après 13 mois.
 *
 * Usage :
 *   wp --require=bin/verify-notifications.php verify-notifications
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-notifications', function () {

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

    /* PERF-02 : réservation, quelques envois en ligne, le reste par psc_envois_lot. */
    $sections['PERF-02'] = function () use ($check) {
        global $wpdb;
        $t_par = psc_table('parents');
        $t_env = psc_table('envois');
        $type  = 'verify_notifications';
        $prefix = 'verify-notifications-envoi-';
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
                $wpdb->insert($t_par, array('email' => $prefix . $i . '@example.invalid', 'nom' => 'VerifyNotifications', 'active' => 1, 'created_at' => current_time('mysql')));
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

    /* PRA-10 : notifications en modèles éditables ; la facture passe par le Mailer. */
    $sections['PRA-10'] = function () use ($check, &$mails) {
        $option = Psc_Email_Templates::OPTION;
        $backup = get_option($option, null);
        $keys = array('email_change', 'sepa_enabled', 'recap_mairie', 'admin_correction', 'service_closed', 'forfait_downgraded', 'cantine_cancelled', 'absence_cancelled', 'day_closed');
        try {
            $defaults = Psc_Email_Templates::defaults();
            $custom = array();
            foreach ($keys as $k) {
                $check(isset($defaults[$k]), "PRA-10 : modèle « $k » absent");
                $custom[$k] = array('subject' => "PERSO-$k", 'body' => "CORPS-$k");
            }
            update_option($option, $custom);
            $fam = array('email' => 'pra10@example.invalid', 'nom' => 'X', 'items' => array((object) array(
                'child_id' => 1, 'child_prenom' => 'A', 'child_nom' => 'B', 'jour_date' => '2030-01-07', 'service' => 'GM', 'remaining' => array('CANT'),
            )));
            $parent = (object) array('id' => 1, 'email' => 'pra10@example.invalid', 'nom' => 'X');
            $calls = array(
                'email_change'       => function () use ($parent) { Psc_Mailer::send_email_change_confirmation($parent, 'new@example.invalid', 'https://example.invalid/'); },
                'sepa_enabled'       => function () use ($parent) { Psc_Mailer::send_sepa_enabled($parent); },
                'admin_correction'   => function () use ($parent) { Psc_Mailer::send_admin_correction($parent, '2029-2030', array(), psc_services()); },
                'service_closed'     => function () use ($fam) { Psc_Mailer::send_service_closed($fam, '2030-01-07', 'Garderie', 'Grève'); },
                'forfait_downgraded' => function () use ($fam) { Psc_Mailer::send_forfait_downgraded($fam, '2030-01-07', 'Garderie'); },
                'cantine_cancelled'  => function () use ($fam) { Psc_Mailer::send_cantine_cancelled($fam, '2030-01-07', 'CE1', 'Sortie'); },
                'absence_cancelled'  => function () use ($parent) { Psc_Mailer::notify_absence_cancelled($parent, (object) array('prenom' => 'A', 'nom' => 'B'), '2030-01-07', array('GM')); },
                'day_closed'         => function () use ($fam) { Psc_Mailer::send_closure_recap($fam, '2030-01-07', '2030-01-08', 'Grève'); },
            );
            foreach ($calls as $k => $call) {
                $mails = array();
                $call();
                $check(count($mails) === 1, "PRA-10 : $k — " . count($mails) . ' e-mail(s)');
                $check($mails && $mails[0]['subject'] === "PERSO-$k", "PRA-10 : $k — sujet personnalisé ignoré");
                $check($mails && strpos((string) $mails[0]['message'], "CORPS-$k") !== false, "PRA-10 : $k — corps personnalisé ignoré");
            }
            $src = (string) file_get_contents(PSC_PATH . 'includes/class-psc-invoices.php');
            $check(strpos($src, 'Psc_Mailer::send_html(') !== false && strpos($src, "'From: '") === false && strpos($src, 'wp_mail(') === false,
                'PRA-10 : la facture contourne encore le Mailer');
        } finally {
            if ($backup === null) delete_option($option); else update_option($option, $backup);
        }
    };

    /* RGPD-10/L3 : l'e-mail « nouvelle demande » ne transmet plus le détail des allergies. */
    $sections['RGPD-10/L3'] = function () use ($check, &$mails) {
        $req = (object) array('email' => 'rgpd10@example.invalid', 'prenom' => 'P', 'nom' => 'N', 'telephone' => '', 'message' => '');
        $mails = array();
        Psc_Mailer::notify_mairie_new_request($req, array(
            array('prenom' => 'A', 'nom' => 'B', 'classe' => 'CP', 'food_allergies' => 'Arachide-SECRET-PAI', 'food_allergy_signal' => 1),
            array('prenom' => 'C', 'nom' => 'D', 'classe' => 'CP', 'food_allergy_signal' => 1),
            array('prenom' => 'E', 'nom' => 'F', 'classe' => 'CP'),
        ));
        $msg = $mails ? (string) $mails[0]['message'] : '';
        $check($msg !== '', 'RGPD-10 : e-mail « nouvelle demande » non envoyé');
        $check(strpos($msg, 'Arachide-SECRET-PAI') === false, 'RGPD-10 : détail des allergies encore transmis par e-mail');
        $check(substr_count($msg, esc_html__('échange alimentation à prévoir', 'periscolaire-registration')) === 2, 'RGPD-10 : badge « échange alimentation à prévoir » absent ou en trop');
    };

    /* PERF-12/L3 : le suivi des envois est purgé après 13 mois. */
    $sections['PERF-12/L3'] = function () use ($check) {
        global $wpdb;
        $t = psc_table('envois');
        $type = 'verify_notifications_purge';
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE objet_type = %s", $type));
        $old = gmdate('Y-m-d H:i:s', strtotime('-14 months'));
        $recent = gmdate('Y-m-d H:i:s', strtotime('-12 months'));
        foreach (array('vieux' => $old, 'recent' => $recent) as $k => $date) {
            $wpdb->insert($t, array('objet_type' => $type, 'objet_id' => 1, 'lot' => $k, 'famille_id' => null, 'cle' => "$type:$k",
                'statut' => Psc_Envois::ACCEPTE, 'tentatives' => 1, 'created_at' => $date, 'updated_at' => $date));
        }
        try {
            Psc_Envois::purger(true);
            $left = $wpdb->get_col($wpdb->prepare("SELECT lot FROM $t WHERE objet_type = %s", $type));
            $check($left === array('recent'), 'PERF-12 : purge des envois inexacte (' . implode(',', $left) . ')');
            $check(Psc_Envois::purger() === 0, 'PERF-12 : purge rejouée dans la journée');
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE objet_type = %s", $type));
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
