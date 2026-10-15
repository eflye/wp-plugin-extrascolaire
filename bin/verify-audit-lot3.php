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

    /* PERF-04 : le portail n'est rendu qu'une fois par requête. */
    $sections['PERF-04'] = function () use ($check) {
        global $wpdb;
        $get = $_GET;
        try {
            $_GET = array('psc_verify_lot3' => '1');
            $first = Psc_Frontend::shortcode(array());
            $q = $wpdb->num_queries;
            $second = Psc_Frontend::shortcode(array());
            $check($second === $first && $first !== '', 'PERF-04 : second rendu différent du premier');
            $check($wpdb->num_queries - $q <= 2, 'PERF-04 : second appel du shortcode : ' . ($wpdb->num_queries - $q) . ' requêtes (rendu refait)');
            $_GET = array('psc_verify_lot3' => '2', 'psc_msg' => 'bad_email');
            $q = $wpdb->num_queries;
            $third = Psc_Frontend::shortcode(array());
            $check($third !== '' && $third !== $first, 'PERF-04 : paramètres d\'URL différents servis depuis le même rendu');
        } finally {
            $_GET = $get;
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
            array('prenom' => 'A', 'nom' => 'B', 'classe' => 'CP', 'food_allergies' => 'Arachide-SECRET-PAI'),
            array('prenom' => 'C', 'nom' => 'D', 'classe' => 'CP', 'food_allergy_signal' => 1),
            array('prenom' => 'E', 'nom' => 'F', 'classe' => 'CP'),
        ));
        $msg = $mails ? (string) $mails[0]['message'] : '';
        $check($msg !== '', 'RGPD-10 : e-mail « nouvelle demande » non envoyé');
        $check(strpos($msg, 'Arachide-SECRET-PAI') === false, 'RGPD-10 : détail des allergies encore transmis par e-mail');
        $check(substr_count($msg, esc_html__('échange alimentation à prévoir', 'periscolaire-registration')) === 2, 'RGPD-10 : badge « échange alimentation à prévoir » absent ou en trop');
    };

    /* PRA-07/L3 : marqueurs numérotés, pluriels, pas de msgid concaténé ni balisé. */
    $sections['PRA-07/L3'] = function () use ($check) {
        foreach (array('class-psc-frontend.php', 'class-psc-invoices.php', 'class-psc-mailer.php', 'class-psc-installer.php') as $file) {
            $src = (string) file_get_contents(PSC_PATH . 'includes/' . $file);
            preg_match_all("/(?:__|_e|esc_html__|esc_html_e|esc_attr__|_n)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m);
            foreach ($m[1] as $msgid) {
                $unnumbered = preg_match_all('/%[sd]/', $msgid);
                $check($unnumbered < 2, "PRA-07 : $file — marqueurs non numérotés : « $msgid »");
                $check(strpos($msgid, '<strong>') === false && strpos($msgid, '<br>') === false, "PRA-07 : $file — HTML dans un msgid : « $msgid »");
                $check(!preg_match('/^\\s|\\s$/u', $msgid) || $msgid === ' ', "PRA-07 : $file — msgid tronqué pour concaténation : « $msgid »");
            }
            $check(strpos($src, "jour%s") === false, "PRA-07 : $file — pluriel fait à la main");
        }
    };

    /* SEC-11 : l'adresse validée est épinglée pour la connexion (pas de seconde résolution). */
    $sections['SEC-11'] = function () use ($check) {
        $host = 'ics-pin.lot3.invalid';
        $resolve = function ($ips, $h) use ($host) { return $h === $host ? array('2001:4860:4860::8888', '8.8.8.8') : $ips; };
        add_filter('psc_ics_resolved_ips', $resolve, 10, 2);
        $old_url = get_option('psc_school_calendar_ics_url', null);
        $seen = array();
        $short = function ($pre, $args, $url) use (&$seen) {
            $seen[] = array($url, has_action('http_api_curl') !== false);
            return array('headers' => array(), 'body' => '', 'response' => array('code' => 500, 'message' => 'x'), 'cookies' => array(), 'filename' => null);
        };
        add_filter('pre_http_request', $short, 10, 3);
        try {
            $check(Psc_School_Calendar::validate_ics_url("https://$host/cal.ics") === true, 'SEC-11 : hôte public simulé refusé');
            $check(Psc_School_Calendar::curl_resolve_entry("https://$host/cal.ics") === "$host:443:8.8.8.8", 'SEC-11 : entrée CURLOPT_RESOLVE inattendue « ' . Psc_School_Calendar::curl_resolve_entry("https://$host/cal.ics") . ' »');
            $check(Psc_School_Calendar::curl_resolve_entry("http://$host/cal.ics") === "$host:80:8.8.8.8", 'SEC-11 : port http non pris en compte');
            $check(Psc_School_Calendar::curl_resolve_entry('https://8.8.8.8/cal.ics') === null, 'SEC-11 : adresse IP littérale épinglée inutilement');
            $check(Psc_School_Calendar::curl_resolve_entry('https://jamais-valide.invalid/cal.ics') === null, 'SEC-11 : hôte non validé épinglé');
            update_option('psc_school_calendar_ics_url', "https://$host/cal.ics");
            Psc_School_Calendar::import();
            $check(count($seen) === 1 && $seen[0][0] === "https://$host/cal.ics" && $seen[0][1] === true, 'SEC-11 : épinglage non branché pendant le téléchargement');
            $check(has_action('http_api_curl') === false || !$seen, 'SEC-11 : épinglage resté branché après le téléchargement');
        } finally {
            remove_filter('psc_ics_resolved_ips', $resolve, 10);
            remove_filter('pre_http_request', $short, 10);
            if ($old_url === null) delete_option('psc_school_calendar_ics_url'); else update_option('psc_school_calendar_ics_url', $old_url);
        }
    };

    /* PERF-06 : lectures répétées mémorisées, invalidées à la moindre écriture. */
    $sections['PERF-06'] = function () use ($check) {
        global $wpdb;
        Psc_School_Years::flush_cache();
        $a = Psc_School_Years::active();
        $q = $wpdb->num_queries;
        for ($i = 0; $i < 5; $i++) Psc_School_Years::active();
        $check($wpdb->num_queries === $q, 'PERF-06 : année active relue (' . ($wpdb->num_queries - $q) . ' requêtes)');
        if ($a) {
            // Une écriture sur la table (même en SQL direct) invalide la lecture.
            $wpdb->query($wpdb->prepare('UPDATE ' . psc_table('school_years') . ' SET date_fin = date_fin WHERE id = %d', (int) $a->id));
            $q = $wpdb->num_queries;
            Psc_School_Years::active();
            $check($wpdb->num_queries === $q + 1, 'PERF-06 : écriture sur school_years sans invalidation');
        }
        $q = $wpdb->num_queries;
        Psc_Menus::get_by_week('2091-01-01');
        Psc_Menus::get_by_week('2091-01-01');
        $check($wpdb->num_queries === $q + 1, 'PERF-06 : menu de la semaine relu');
        $wpdb->query("DELETE FROM " . psc_table('menus') . " WHERE semaine_debut = '2091-01-01'");
        $q = $wpdb->num_queries;
        Psc_Menus::get_by_week('2091-01-01');
        $check($wpdb->num_queries === $q + 1, 'PERF-06 : écriture sur menus sans invalidation');
    };

    /* PERF-09 : brouillon inchangé non reconstruit ; facture envoyée jamais recalculée. */
    $sections['PERF-09'] = function () use ($check) {
        global $wpdb;
        $t_par = psc_table('parents');
        $t_ch  = psc_table('children');
        $t_inv = psc_table('invoices');
        $email = 'verify-lot3-perf09@example.invalid';
        $year_key = '2083-2084';
        $mois = '2083-10';
        $purge = function () use ($wpdb, $email, $t_par, $t_ch, $t_inv, $year_key) {
            $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_par WHERE email = %s", $email));
            if ($pid) {
                foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, pdf_path FROM $t_inv WHERE parent_id = %d", $pid)) as $inv) {
                    if ($inv->pdf_path) @unlink(psc_private_path($inv->pdf_path)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                    foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT pdf_path FROM ' . psc_table('invoice_versions') . ' WHERE invoice_id = %d', $inv->id)) as $p) {
                        if ($p) @unlink(psc_private_path($p)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                    }
                    $wpdb->delete(psc_table('invoice_versions'), array('invoice_id' => (int) $inv->id));
                }
                $wpdb->delete($t_inv, array('parent_id' => $pid));
                foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_ch WHERE parent_id = %d", $pid)) as $cid) {
                    Psc_Planning::delete_for_child((int) $cid);
                    $wpdb->delete(psc_table('child_school_years'), array('child_id' => (int) $cid));
                }
                $wpdb->delete($t_ch, array('parent_id' => $pid));
                $wpdb->delete($t_par, array('id' => $pid));
            }
            $year = Psc_School_Years::get_by_key($year_key);
            if ($year && $year->statut !== 'active') Psc_School_Years::delete((int) $year->id);
            Psc_Planning::flush_cache();
        };
        $purge();
        try {
            $y = Psc_School_Years::ensure('2083-09-03', '2084-07-03');
            Psc_School_Year::save($year_key, '2083-09-03', '2084-07-03', '[]', 48);
            $pid = (int) Psc_Parents::create($email, 'Perf09');
            $wpdb->insert($t_ch, array('parent_id' => $pid, 'nom' => 'Perf09', 'prenom' => 'Enfant', 'created_at' => current_time('mysql')));
            $cid = (int) $wpdb->insert_id;
            Psc_School_Years::enroll($cid, $y, 'CE1');
            Psc_Planning::toggle_pattern($cid, $year_key, 1, 'GM', true);
            Psc_Planning::flush_cache();

            $id = Psc_Invoices::generate_one($pid, $mois);
            $check(!is_wp_error($id), 'PERF-09 : brouillon non généré ' . (is_wp_error($id) ? $id->get_error_code() : ''));
            if (is_wp_error($id)) return;
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_inv WHERE id = %d", $id));
            $pdf = psc_private_path($row->pdf_path);
            clearstatcache();
            $mtime = filemtime($pdf);
            touch($pdf, $mtime - 100);
            clearstatcache();

            // generate_month() : même résultat, sans reconstruire le PDF inchangé.
            $q = $wpdb->num_queries;
            Psc_Invoices::generate_month($mois);
            clearstatcache();
            $check(filemtime($pdf) === $mtime - 100, 'PERF-09 : PDF d\'un brouillon inchangé reconstruit');
            $row2 = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_inv WHERE id = %d", $id));
            $check($row2->lines_json === $row->lines_json && $row2->created_at === $row->created_at, 'PERF-09 : brouillon inchangé réécrit');

            // Destinataire modifié : le PDF du brouillon est reconstruit.
            $wpdb->update($t_par, array('adresse' => '1 rue du Test'), array('id' => $pid));
            Psc_Invoices::generate_one($pid, $mois);
            clearstatcache();
            $check(filemtime($pdf) !== $mtime - 100, 'PERF-09 : adresse modifiée, PDF du brouillon non reconstruit');

            // Facture envoyée : jamais réécrite si rien ne change.
            $wpdb->update($t_inv, array('sent_at' => current_time('mysql')), array('id' => $id));
            $sent = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_inv WHERE id = %d", $id));
            touch($pdf, $mtime - 200);
            $wpdb->update($t_par, array('adresse' => '2 rue du Test'), array('id' => $pid));
            Psc_Invoices::generate_one($pid, $mois);
            clearstatcache();
            $after = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_inv WHERE id = %d", $id));
            $check($after == $sent && filemtime($pdf) === $mtime - 200, 'PERF-09 : facture envoyée réécrite alors que le calcul est inchangé');

            // Préchargement du calendrier : une seconde fois sans requête.
            $days = Psc_School_Year::school_days_in_month($mois);
            Psc_School_Calendar::preload_closed($days);
            $q = $wpdb->num_queries;
            Psc_School_Calendar::preload_closed($days);
            $check($wpdb->num_queries === $q, 'PERF-09 : preload_closed relit des dates déjà en cache');
        } finally {
            $purge();
        }
    };

    /* PERF-10 : maybe_upgrade() sans requête ni appel système sur une page publique. */
    $sections['PERF-10'] = function () use ($check) {
        global $wpdb;
        if (get_option('psc_db_version') !== Psc_Installer::DB_VERSION) {
            $check(false, 'PERF-10 : schéma pas à jour, test impossible');
            return;
        }
        // Un passage « écran mairie » pose les options en autoload.
        $m = new ReflectionMethod('Psc_Installer', 'run_upgrade');
        $m->setAccessible(true);
        $m->invoke(null);
        $all = wp_load_alloptions(true);
        foreach (array('psc_db_version', 'psc_roles_version', 'psc_private_dir_path', 'psc_storage_move_failed') as $opt) {
            if (psc_running_as_root() && $opt === 'psc_private_dir_path') continue;
            $check(array_key_exists($opt, $all), "PERF-10 : option $opt non chargée d'office");
        }
        $check(!get_option('psc_storage_move_failed'), 'PERF-10 : marqueur d\'échec de déménagement non vide');
        wp_cache_delete('notoptions', 'options');
        $q = $wpdb->num_queries;
        Psc_Installer::maybe_upgrade(); // contexte WP-CLI = page publique
        $check($wpdb->num_queries === $q, 'PERF-10 : maybe_upgrade() public : ' . ($wpdb->num_queries - $q) . ' requête(s)');
        $src = (string) file_get_contents(PSC_PATH . 'includes/helpers/files.php');
        $check(strpos($src, "psc_private_path(\$rel_path) {\n    // Tout accès") !== false, 'PERF-10 : garde-fous non posés au premier accès à un document');
    };

    /* PERF-11 : import ICS en requêtes groupées, corrections manuelles prioritaires (règle n°7). */
    $sections['PERF-11'] = function () use ($check) {
        global $wpdb;
        $t = psc_table('school_calendar');
        $year = (int) current_time('Y') + 4;
        $label = 'Vacances verify-lot3';
        $cleanup = function () use ($wpdb, $t, $year) {
            $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE jour_date BETWEEN %s AND %s", "$year-02-01", "$year-03-31"));
            Psc_School_Calendar::flush_closed_cache();
        };
        $cleanup();
        try {
            // Correction manuelle : jour rouvert au milieu des vacances, et
            // jour fermé avec un autre libellé.
            $now = current_time('mysql');
            $wpdb->insert($t, array('jour_date' => "$year-02-20", 'label' => null, 'is_closed' => 0, 'source' => 'manual', 'created_at' => $now, 'updated_at' => $now));
            $wpdb->insert($t, array('jour_date' => "$year-02-21", 'label' => 'Manuel lot3', 'is_closed' => 1, 'source' => 'manual', 'created_at' => $now, 'updated_at' => $now));
            // Jour déjà importé : mis à jour.
            $wpdb->insert($t, array('jour_date' => "$year-02-22", 'label' => 'Ancien import', 'is_closed' => 1, 'source' => 'import', 'created_at' => $now, 'updated_at' => $now));
            $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:{$year}0214\r\nDTEND;VALUE=DATE:{$year}0302\r\nSUMMARY:$label - Zone C\r\nLOCATION:Zone C\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
            $expected = count(Psc_School_Calendar::parse_ics($ics));
            $q = $wpdb->num_queries;
            $n = Psc_School_Calendar::import_from_upload($ics);
            $used = $wpdb->num_queries - $q;
            $check($n === $expected - 2, 'PERF-11 : ' . (is_wp_error($n) ? $n->get_error_code() : $n) . " jours importés au lieu de " . ($expected - 2));
            $check($used <= 8, "PERF-11 : $used requêtes pour importer $expected jours");
            $row = function ($d) use ($wpdb, $t) { return $wpdb->get_row($wpdb->prepare("SELECT label, is_closed, source FROM $t WHERE jour_date = %s", $d)); };
            $r = $row("$year-02-20");
            $check($r && (int) $r->is_closed === 0 && $r->source === 'manual', 'PERF-11 : réouverture manuelle écrasée par l\'import');
            $r = $row("$year-02-21");
            $check($r && $r->label === 'Manuel lot3' && $r->source === 'manual', 'PERF-11 : fermeture manuelle écrasée par l\'import');
            $r = $row("$year-02-22");
            $check($r && $r->source === 'import' && strpos((string) $r->label, $label) === 0, 'PERF-11 : jour importé non mis à jour');
            $check(Psc_School_Calendar::is_closed("$year-02-16") && !Psc_School_Calendar::is_closed("$year-02-20"), 'PERF-11 : fermetures lues incohérentes');
        } finally {
            $cleanup();
        }
    };

    /* PERF-12/L3 : le suivi des envois est purgé après 13 mois. */
    $sections['PERF-12/L3'] = function () use ($check) {
        global $wpdb;
        $t = psc_table('envois');
        $type = 'verify_lot3_purge';
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

    /* PRA-17 (partiel) : progression des classes validée, journal limité aux options modifiées. */
    $sections['PRA-17'] = function () use ($check) {
        $classes = array_values(array_filter(array_keys(Psc_School_Years::classe_options()), 'strlen'));
        if (count($classes) < 2) { $check(false, 'PRA-17 : moins de deux classes configurées'); return; }
        $current = Psc_School_Years::classe_progression();
        $a = $classes[0];
        $b = $classes[1];
        $out = Psc_Admin_Config::sanitize_progression(array('progression_' . $a => '<script>x</script>', 'progression_' . $b => 'sortie'));
        $check($out[$a] === (isset($current[$a]) ? (string) $current[$a] : 'sortie'), 'PRA-17 : classe inconnue acceptée dans la progression');
        $check($out[$b] === 'sortie', 'PRA-17 : « sortie » refusée');
        $out = Psc_Admin_Config::sanitize_progression(array('progression_' . $a => $b));
        $check($out[$a] === $b, 'PRA-17 : classe connue refusée');
        $src = (string) file_get_contents(PSC_PATH . 'includes/class-psc-admin-config.php');
        $check(strpos($src, "'champs' => array_keys(\$changed)") !== false && strpos($src, 'array_keys($_POST)') === false,
            'PRA-17 : le journal liste encore tous les champs du formulaire');
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
