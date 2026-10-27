<?php
/**
 * Script de vérification autonome — performance et garde-fous
 * d'exécution : caches et rendus non refaits, chemin de mise à jour
 * réservé aux écrans de la mairie, brouillons de facture non
 * reconstruits ; plus trois garde-fous de qualité (chaînes traduisibles,
 * progression des classes, horloges). Conditions WP-CLI réelles, aucun
 * e-mail ne part (pre_wp_mail). Chaque section crée puis supprime ses
 * données.
 *
 * Origine : audit du 14/10/2026, lot 3 (ex-bin/verify-audit-lot3.php,
 * scindé par domaine avec verify-notifications.php et
 * verify-school-calendar.php). Les références renvoient à AUDIT.md.
 *
 * Vérifie :
 *  - PRA-12 : détection commune des pages à shortcode, cache invalidé à
 *    la publication, option de Réglages prioritaire ;
 *  - PERF-03 : le chemin admin complet de maybe_upgrade() est réservé aux
 *    écrans de la mairie (ni admin-post famille, ni ajax, ni cron) ;
 *  - PERF-04 : le portail n'est rendu qu'une fois par requête ;
 *  - PRA-07/L3 : marqueurs numérotés, pluriels, pas de msgid concaténé
 *    ni balisé ;
 *  - PERF-06 : lectures répétées mémorisées (année active, menus),
 *    invalidées à la moindre écriture ;
 *  - PERF-09 : brouillon de facture inchangé non reconstruit ; facture
 *    envoyée jamais recalculée ; calendrier préchargé sans relecture ;
 *  - PERF-10 : maybe_upgrade() sans requête ni appel système sur une
 *    page publique ;
 *  - PRA-17 (partiel) : progression des classes validée, journal limité
 *    aux options modifiées ;
 *  - PRA-14/L3 : horloges cohérentes (plus de current_time('timestamp')).
 *
 * Usage :
 *   wp --require=bin/verify-performance.php verify-performance
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-performance', function () {

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

    /* PRA-12 : détection commune des pages à shortcode, cache invalidé. */
    $sections['PRA-12'] = function () use ($check) {
        $sc = 'psc_verify_performance_sc';
        $opt = 'psc_verify_performance_page_id';
        add_shortcode($sc, '__return_empty_string'); // has_shortcode() exige un shortcode déclaré
        delete_option($opt);
        delete_transient('psc_page_lookup_' . $sc);
        $page = 0;
        try {
            $check(psc_shortcode_page_id($sc, $opt) === 0, 'PRA-12 : page trouvée alors qu\'aucune ne porte le shortcode');
            $check(get_transient('psc_page_lookup_' . $sc) !== false, 'PRA-12 : absence non mise en cache');

            // Page publiée après coup : save_post_page vide le cache.
            $page = (int) wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Verify performance', 'post_content' => '[' . $sc . ']'));
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
            $other = (int) wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Verify performance bis', 'post_content' => 'x'));
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
            $_GET = array('psc_verify_performance' => '1');
            $first = Psc_Frontend::shortcode(array());
            $q = $wpdb->num_queries;
            $second = Psc_Frontend::shortcode(array());
            $check($second === $first && $first !== '', 'PERF-04 : second rendu différent du premier');
            $check($wpdb->num_queries - $q <= 2, 'PERF-04 : second appel du shortcode : ' . ($wpdb->num_queries - $q) . ' requêtes (rendu refait)');
            $_GET = array('psc_verify_performance' => '2', 'psc_msg' => 'bad_email');
            $q = $wpdb->num_queries;
            $third = Psc_Frontend::shortcode(array());
            $check($third !== '' && $third !== $first, 'PERF-04 : paramètres d\'URL différents servis depuis le même rendu');
        } finally {
            $_GET = $get;
        }
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
        $email = 'verify-performance-perf09@example.invalid';
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

    /* PRA-14/L3 : horloges cohérentes (plus de current_time('timestamp')). */
    $sections['PRA-14/L3'] = function () use ($check) {
        $tz = get_option('timezone_string');
        $off = get_option('gmt_offset');
        update_option('timezone_string', 'Europe/Paris');
        try {
            // 31 août 2026 23:30 à Paris = 21:30 UTC : encore l'année 2025-2026.
            $ts = (new DateTime('2026-08-31 23:30:00', new DateTimeZone('Europe/Paris')))->getTimestamp();
            $check(psc_rentree_year($ts) === 2025, 'PRA-14 : rentrée calculée hors fuseau du site (31/08 23:30)');
            // 1er septembre 00:30 à Paris = 31 août 22:30 UTC : déjà 2026-2027.
            $ts = (new DateTime('2026-09-01 00:30:00', new DateTimeZone('Europe/Paris')))->getTimestamp();
            $check(psc_rentree_year($ts) === 2026, 'PRA-14 : rentrée calculée hors fuseau du site (01/09 00:30)');
            $m = new ReflectionMethod('Psc_Invoices', 'french_full_date');
            $m->setAccessible(true);
            $check(strpos((string) $m->invoke(null, $ts), '1 septembre 2026') === 0, 'PRA-14 : date de facture décalée (' . $m->invoke(null, $ts) . ')');
            foreach (array('includes/class-psc-invoices.php', 'includes/helpers/school-calendar.php') as $f) {
                $code = '';
                foreach (token_get_all((string) file_get_contents(PSC_PATH . $f)) as $tok) {
                    if (is_array($tok) && in_array($tok[0], array(T_COMMENT, T_DOC_COMMENT), true)) continue;
                    $code .= is_array($tok) ? $tok[1] : $tok;
                }
                $check(strpos($code, "current_time('timestamp')") === false, "PRA-14 : current_time('timestamp') encore utilisé dans $f");
            }
        } finally {
            update_option('timezone_string', $tz);
            update_option('gmt_offset', $off);
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
