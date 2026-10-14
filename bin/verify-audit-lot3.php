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

    /* PERF-01 : fermeture d'une plage en transaction, un récapitulatif par famille, hors requête. */
    $sections['PERF-01'] = function () use ($check, &$mails) {
        global $wpdb;
        $t_par = psc_table('parents');
        $t_ch  = psc_table('children');
        $t_cal = psc_table('school_calendar');
        $t_env = psc_table('envois');
        $emails = array('verify-lot3-ferm-a@example.invalid', 'verify-lot3-ferm-b@example.invalid');
        $year_key = '2085-2086';
        $purge = function () use ($wpdb, $emails, $t_par, $t_ch, $t_cal, $t_env, $year_key) {
            foreach ($emails as $email) {
                $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_par WHERE email = %s", $email));
                if (!$pid) continue;
                foreach ($wpdb->get_col($wpdb->prepare("SELECT DISTINCT objet_id FROM $t_env WHERE famille_id = %d AND objet_type IN ('fermeture','fermeture_service','forfait_declasse')", $pid)) as $oid) {
                    delete_option('psc_fermeture_' . (int) $oid);
                }
                foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_ch WHERE parent_id = %d", $pid)) as $cid) {
                    Psc_Planning::delete_for_child((int) $cid);
                    $wpdb->delete(psc_table('child_school_years'), array('child_id' => (int) $cid));
                }
                $wpdb->delete($t_ch, array('parent_id' => $pid));
                $wpdb->delete($t_par, array('id' => $pid));
            }
            $wpdb->query("DELETE FROM $t_cal WHERE jour_date BETWEEN '2085-09-01' AND '2086-07-31'");
            $year = Psc_School_Years::get_by_key($year_key);
            if ($year && $year->statut !== 'active') Psc_School_Years::delete((int) $year->id);
            Psc_School_Calendar::flush_closed_cache();
            Psc_Planning::flush_cache();
        };
        $purge();
        try {
            $y = Psc_School_Years::ensure('2085-09-03', '2086-07-03');
            Psc_School_Year::save($year_key, '2085-09-03', '2086-07-03', '[]', 48);
            $pids = array();
            foreach ($emails as $i => $email) {
                $pid = (int) Psc_Parents::create($email, 'Fermeture');
                $wpdb->insert($t_ch, array('parent_id' => $pid, 'nom' => 'Fermeture', 'prenom' => 'Enfant' . $i, 'created_at' => current_time('mysql')));
                $cid = (int) $wpdb->insert_id;
                Psc_School_Years::enroll($cid, $y, 'CE1');
                Psc_Planning::toggle_pattern($cid, $year_key, 1, 'GM', true); // lundi
                Psc_Planning::toggle_pattern($cid, $year_key, 2, 'CANT', true); // mardi
                $pids[$email] = $pid;
            }
            Psc_Planning::flush_cache();
            Psc_School_Year::flush_cache();

            // Deux semaines : 2 lundis + 2 mardis par famille.
            $debut = '2085-10-08';
            $fin   = '2085-10-19';
            $check(date('N', strtotime($debut)) === '1', 'PERF-01 : jeu de dates à revoir (le début doit être un lundi)');

            // Échec d'écriture : rien n'est fermé, personne n'est prévenu.
            $sabotage = function ($sql) use ($t_cal) { return (strpos($sql, "INSERT INTO $t_cal") === 0) ? 'SELECT * FROM psc_table_inexistante' : $sql; };
            add_filter('query', $sabotage);
            $suppress = $wpdb->suppress_errors(true);
            $mails = array();
            $r = Psc_School_Calendar::close_range($debut, $fin, 'Vérif');
            $wpdb->suppress_errors($suppress);
            remove_filter('query', $sabotage);
            $check(is_wp_error($r), 'PERF-01 : échec d\'écriture non signalé');
            $check((int) $wpdb->get_var("SELECT COUNT(*) FROM $t_cal WHERE jour_date BETWEEN '$debut' AND '$fin'") === 0, 'PERF-01 : jours fermés malgré l\'échec');
            $check(!$mails, 'PERF-01 : familles prévenues d\'une fermeture non enregistrée');

            // Fermeture réelle.
            $mails = array();
            $queries = $wpdb->num_queries;
            $r = Psc_School_Calendar::close_range($debut, $fin, 'Vérif fermeture');
            $check(!is_wp_error($r) && $r['days'] === 12, 'PERF-01 : ' . (is_wp_error($r) ? $r->get_error_code() : $r['days']) . ' jours au lieu de 12');
            $closed = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_cal WHERE jour_date BETWEEN '$debut' AND '$fin' AND is_closed = 1 AND source = 'manual'");
            $check($closed === 12, "PERF-01 : $closed jours marqués fermés au lieu de 12");
            $check(Psc_School_Calendar::is_closed($debut), 'PERF-01 : jour non vu fermé (cache)');
            $check($wpdb->num_queries - $queries < 60, 'PERF-01 : ' . ($wpdb->num_queries - $queries) . ' requêtes pour fermer 12 jours');

            foreach ($emails as $email) {
                $mine = array_values(array_filter($mails, function ($m) use ($email) { return in_array($email, (array) $m['to'], true); }));
                $check(count($mine) === 1, "PERF-01 : $email reçoit " . count($mine) . ' e-mail(s) au lieu d\'un récapitulatif');
                if ($mine) {
                    $i = array_search($email, $emails, true);
                    $rows = substr_count((string) $mine[0]['message'], 'Enfant' . $i . ' Fermeture</td>');
                    $check($rows === 4, "PERF-01 : récapitulatif de $email avec $rows ligne(s) au lieu de 4");
                    $check(strpos((string) $mine[0]['subject'], date_i18n('d/m/Y', strtotime($debut))) !== false, 'PERF-01 : période absente du sujet');
                }
            }
            $bilan = $r['bilan'];
            $check($bilan && $bilan['total'] === 2 && $bilan[Psc_Envois::ACCEPTE] === 2, 'PERF-01 : bilan des envois ' . wp_json_encode($bilan));
            $oid = (int) $wpdb->get_var($wpdb->prepare("SELECT objet_id FROM $t_env WHERE objet_type = 'fermeture' AND lot = %s LIMIT 1", $bilan ? $bilan['lot'] : ''));
            $stored = Psc_School_Calendar::get_closure($oid);
            $check($stored && $stored['debut'] === $debut && $stored['fin'] === $fin, 'PERF-01 : fermeture à notifier non conservée');
            $check($stored && strpos(wp_json_encode($stored), 'example.invalid') === false && strpos(wp_json_encode($stored), 'Fermeture') === false,
                'PERF-01 : la fermeture conservée contient une adresse ou un nom');
            $autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'psc_fermeture_' . $oid));
            $check(in_array($autoload, array('no', 'off'), true), 'PERF-01 : fermeture conservée en option chargée d\'office');

            // Correction manuelle prioritaire : la plage fermée est marquée manual.
            // Un renvoi via Psc_Envois (relance) relit la fermeture conservée.
            $wpdb->update($t_env, array('statut' => Psc_Envois::ECHEC), array('objet_type' => 'fermeture', 'objet_id' => $oid));
            $mails = array();
            $b2 = Psc_Envois::relancer_echecs('fermeture', $oid);
            $check(count($mails) === 2 && $b2[Psc_Envois::ACCEPTE] === 2, 'PERF-01 : relance d\'une fermeture inopérante');
        } finally {
            $purge();
        }
    };

    /* PERF-03 : le chemin admin complet de maybe_upgrade() réservé aux écrans de la mairie. */
    $sections['PERF-03'] = function () use ($check) {
        $m = new ReflectionMethod('Psc_Installer', 'is_admin_screen');
        $m->setAccessible(true);
        $prev_screen = isset($GLOBALS['current_screen']) ? $GLOBALS['current_screen'] : null;
        $prev_user = get_current_user_id();
        // is_admin() suit $GLOBALS['current_screen']->in_admin() quand il existe.
        $GLOBALS['current_screen'] = new class { public function in_admin($admin = null) { return true; } };
        $admins = get_users(array('capability' => psc_manage_cap(), 'number' => 1, 'fields' => 'ID'));
        try {
            wp_set_current_user(0);
            $check($m->invoke(null) === false, 'PERF-03 : visiteur sans compte (admin-post famille) traité comme écran mairie');
            if ($admins) {
                wp_set_current_user((int) $admins[0]);
                $check($m->invoke(null) === true, 'PERF-03 : écran admin de la mairie non reconnu');
                add_filter('wp_doing_ajax', '__return_true');
                $check($m->invoke(null) === false, 'PERF-03 : appel admin-ajax traité comme écran mairie');
                remove_filter('wp_doing_ajax', '__return_true');
                add_filter('wp_doing_cron', '__return_true');
                $check($m->invoke(null) === false, 'PERF-03 : cron traité comme écran mairie');
                remove_filter('wp_doing_cron', '__return_true');
            } else {
                $check(false, 'PERF-03 : aucun compte avec la capacité du backoffice pour le test');
            }
            $src = '';
            foreach (token_get_all((string) file_get_contents(PSC_PATH . 'includes/class-psc-installer.php')) as $tok) {
                if (is_array($tok) && in_array($tok[0], array(T_COMMENT, T_DOC_COMMENT), true)) continue;
                $src .= is_array($tok) ? $tok[1] : $tok;
            }
            $check(preg_match('/\bis_admin\(\)/', preg_replace('/protected static function is_admin_screen\(\).*?\n    }\n/s', '', $src)) === 0,
                'PERF-03 : is_admin() encore utilisé hors de is_admin_screen()');
        } finally {
            remove_filter('wp_doing_ajax', '__return_true');
            remove_filter('wp_doing_cron', '__return_true');
            if ($prev_screen === null) unset($GLOBALS['current_screen']); else $GLOBALS['current_screen'] = $prev_screen;
            wp_set_current_user($prev_user);
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
