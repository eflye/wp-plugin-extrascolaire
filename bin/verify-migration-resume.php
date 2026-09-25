<?php
/**
 * Script de vérification autonome (P1-17) — une montée de version ou un
 * déménagement de documents ne se déclare réussi qu'une fois complet, et
 * reprend là où il s'est arrêté. Même rôle que bin/verify-write-integrity.php :
 * celui du test unitaire, en conditions WP-CLI réelles.
 *
 * À lancer sous l'utilisateur du serveur web (www-data), comme en
 * intégration continue : un processus root ne déménage jamais les
 * documents (cf. Psc_Installer::sync_private_dir()), et chmod ne l'arrête
 * pas.
 *
 * Vérifie :
 *  - verrou : atomique, rendu par son seul détenteur, repris une fois
 *    abandonné ; une montée ne passe jamais outre un verrou tenu ;
 *  - migrations : une erreur SQL arrête la montée à l'étape en échec
 *    (version non avancée, alerte sans donnée personnelle), les étapes
 *    franchies ne sont pas rejouées, la reprise est espacée côté public
 *    puis aboutit ;
 *  - répertoire privé déplacé : conflit de contenu et droit refusé
 *    laissent la source intacte et l'ancien chemin retenu ; la reprise
 *    aboutit une fois la cause levée ; garde-fous jamais déplacés ;
 *  - uploads/periscolaire (3.7.0) : même garanties, garde-fou posé tant
 *    que des fichiers y restent.
 *
 * Usage :
 *   wp --require=bin/verify-migration-resume.php verify-migration-resume
 *
 * Rejoue les étapes 4.0.0 et 4.14.0 (idempotentes) ; les tables héritées
 * que la reprise depuis 3.8.0 recrée sont supprimées si elles n'existaient
 * pas. Les documents de test vivent dans des dossiers temporaires, derrière
 * le filtre psc_private_dir : les documents réels ne sont jamais déplacés.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-migration-resume', function () {

    if (!class_exists('Psc_Installer')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }
    if (psc_running_as_root()) {
        WP_CLI::error('À lancer sous l’utilisateur du serveur web (ex. docker exec -u www-data).');
    }

    global $wpdb;
    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    $reflect = new ReflectionMethod('Psc_Installer', 'legacy_upload_dir');
    $reflect->setAccessible(true);
    $legacy = $reflect->invoke(null);
    if (is_dir($legacy)) {
        WP_CLI::error("$legacy existe déjà sur ce site : vérification refusée pour ne pas toucher de vrais documents.");
    }

    $legacy_tables = array(psc_table('trimestres'), psc_table('calendar_days'), psc_table('registrations'));
    $table_exists = function ($t) use ($wpdb) {
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t));
    };
    $had_legacy = array_filter($legacy_tables, $table_exists);
    $saved_path = get_option('psc_private_dir_path', '');

    $root = trailingslashit(get_temp_dir()) . 'psc-verify-p117-' . wp_generate_password(8, false);
    $new  = $root . '/actuel';
    wp_mkdir_p($new);
    $current_dir = $new;
    $filter = function () use (&$current_dir) { return $current_dir; };
    add_filter('psc_private_dir', $filter);

    $put = function ($path, $content) {
        wp_mkdir_p(dirname($path));
        file_put_contents($path, $content); // phpcs:ignore WordPress.WP.AlternativeFunctions
    };
    $read = function ($path) {
        return is_file($path) ? (string) file_get_contents($path) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
    };
    $failed = function ($source) {
        $f = get_option('psc_storage_move_failed');
        return is_array($f) && isset($f[$source]) ? $f[$source] : null;
    };
    $sabotage_sql = null;
    $sabotage = function ($sql) use (&$sabotage_sql) {
        return ($sabotage_sql && strpos($sql, $sabotage_sql) !== false) ? 'SELECT * FROM verif_table_inexistante' : $sql;
    };
    add_filter('query', $sabotage);
    $wpdb->suppress_errors(true);
    $age_failure = function () {
        $f = get_option('psc_migration_failed');
        if (is_array($f)) { $f['ts'] = time() - Psc_Installer::RETRY_DELAY - 1; update_option('psc_migration_failed', $f, false); }
    };
    $rm = function ($dir) use (&$rm) {
        if (!is_dir($dir)) return;
        @chmod($dir, 0755); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        foreach (array_diff(scandir($dir), array('.', '..')) as $e) {
            is_dir("$dir/$e") ? $rm("$dir/$e") : @unlink("$dir/$e"); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    };

    try {
        // Point de départ : chemin retenu = dossier actuel de test.
        update_option('psc_private_dir_path', $new, false);
        delete_option('psc_storage_move_failed');
        delete_option('psc_migration_failed');

        // 1. Verrou.
        $a = Psc_Installer::acquire_lock();
        $check($a !== false, 'verrou : premier preneur refusé');
        $check(Psc_Installer::acquire_lock() === false, 'verrou : pris deux fois');
        Psc_Installer::release_lock('0.000000');
        $check(Psc_Installer::acquire_lock() === false, 'verrou : rendu par un autre que son détenteur');
        Psc_Installer::release_lock($a);
        $b = Psc_Installer::acquire_lock();
        $check($b !== false, 'verrou : non repris après restitution');
        $wpdb->update($wpdb->options, array('option_value' => sprintf('%.6F', microtime(true) - Psc_Installer::LOCK_TTL - 5)), array('option_name' => 'psc_migration_lock'));
        $c = Psc_Installer::acquire_lock();
        $check($c !== false, 'verrou : abandonné mais jamais repris');
        update_option('psc_db_version', '4.13.0');
        Psc_Installer::maybe_upgrade();
        $check(get_option('psc_db_version') === '4.13.0', 'verrou : montée passée outre un verrou tenu');
        Psc_Installer::release_lock($c);
        Psc_Installer::maybe_upgrade();
        $check(get_option('psc_db_version') === Psc_Installer::DB_VERSION, 'verrou : montée non faite une fois le verrou rendu');

        // 2. Erreur SQL : la montée s'arrête à l'étape en échec.
        update_option('psc_db_version', '3.8.0');
        $sabotage_sql = "COLUMN_NAME = 'sent_at'"; // première requête de l'étape 4.14.0
        Psc_Installer::maybe_upgrade();
        $f = get_option('psc_migration_failed');
        $check(get_option('psc_db_version') === '4.0.0', 'échec : version ' . get_option('psc_db_version') . ' au lieu de 4.0.0 (dernière étape franchie)');
        $check(is_array($f) && $f['etape'] === '4.14.0', 'échec : étape en échec non consignée');
        $check(is_array($f) && $f['requete'] === 'SELECT', 'échec : requête consignée « ' . ($f['requete'] ?? '') . ' »');
        $check(is_array($f) && strpos(wp_json_encode($f), 'verif_table_inexistante') === false && strpos(wp_json_encode($f), 'sent_at') === false, 'échec : texte de requête recopié dans l’alerte');

        // Reprise espacée côté public, puis complète, sans rejouer 4.0.0.
        $sabotage_sql = null;
        Psc_Installer::maybe_upgrade();
        $check(get_option('psc_db_version') === '4.0.0', 'reprise : relancée avant le délai côté public');
        $age_failure();
        $replayed = 0;
        $spy = function ($sql) use (&$replayed) { if (strpos($sql, "COLUMN_NAME = 'food_allergies'") !== false) $replayed++; return $sql; };
        add_filter('query', $spy);
        Psc_Installer::maybe_upgrade();
        remove_filter('query', $spy);
        $check(get_option('psc_db_version') === Psc_Installer::DB_VERSION, 'reprise : version ' . get_option('psc_db_version'));
        $check($replayed === 0, 'reprise : étape 4.0.0 déjà franchie rejouée');
        $check(get_option('psc_migration_failed') === false, 'reprise : alerte conservée après succès');

        // Échec de dbDelta : aucune étape franchie.
        update_option('psc_db_version', '4.13.0');
        $wpdb->query('ALTER TABLE ' . psc_table('envois') . ' DROP INDEX objet_lot'); // dbDelta le recrée
        $sabotage_sql = 'ADD KEY `objet_lot`';
        Psc_Installer::maybe_upgrade();
        $sabotage_sql = null;
        $f = get_option('psc_migration_failed');
        $check(get_option('psc_db_version') === '4.13.0', 'dbDelta en échec : version avancée');
        $check(is_array($f) && $f['etape'] === 'schema', 'dbDelta en échec : étape « schema » non consignée');
        $age_failure();
        Psc_Installer::maybe_upgrade();
        $check(get_option('psc_db_version') === Psc_Installer::DB_VERSION, 'dbDelta repris : version ' . get_option('psc_db_version'));
        $check((bool) $wpdb->get_var("SHOW INDEX FROM " . psc_table('envois') . " WHERE Key_name = 'objet_lot'"), 'dbDelta repris : index non recréé');

        // 3. Répertoire privé déplacé.
        $old = $root . '/ancien';
        $put("$old/periscolaire/assurances/2090/child-1.pdf", 'doc-1');
        $put("$old/psc-probe.txt", 'témoin de l’ancien emplacement');
        $put("$old/.htaccess", 'Require all denied');
        psc_ensure_private_dir(); // garde-fous et témoin propres au nouveau dossier
        update_option('psc_private_dir_path', $old, false);
        Psc_Installer::maybe_upgrade();
        $check($read("$new/periscolaire/assurances/2090/child-1.pdf") === 'doc-1', 'déplacement : document absent du nouveau dossier');
        $check(!is_dir($old), 'déplacement : ancien dossier conservé');
        $check(get_option('psc_private_dir_path') === $new, 'déplacement : nouveau chemin non retenu');
        $check($failed('private_dir') === null, 'déplacement : alerte levée sans échec');
        $check($read("$new/psc-probe.txt") !== 'témoin de l’ancien emplacement', 'déplacement : témoin de l’ancien dossier recopié');

        // Conflit de contenu.
        $old = $root . '/ancien-conflit';
        $put("$old/periscolaire/assurances/2090/child-2.pdf", 'version A');
        $put("$new/periscolaire/assurances/2090/child-2.pdf", 'version B');
        $put("$old/periscolaire/assurances/2090/child-3.pdf", 'doc-3');
        $put("$old/.htaccess", 'Require all denied');
        update_option('psc_private_dir_path', $old, false);
        Psc_Installer::maybe_upgrade();
        $check($read("$old/periscolaire/assurances/2090/child-2.pdf") === 'version A', 'conflit : source supprimée');
        $check($read("$new/periscolaire/assurances/2090/child-2.pdf") === 'version B', 'conflit : destination écrasée');
        $check($read("$new/periscolaire/assurances/2090/child-3.pdf") === 'doc-3', 'conflit : les autres documents ne sont pas déplacés');
        $check(get_option('psc_private_dir_path') === $old, 'conflit : nouveau chemin retenu malgré l’échec');
        $check(($failed('private_dir')['restants'] ?? null) === 1, 'conflit : alerte absente ou mauvais décompte');
        $check(file_exists("$old/.htaccess"), 'conflit : garde-fou retiré alors que des documents restent');
        unlink("$new/periscolaire/assurances/2090/child-2.pdf"); // la mairie tranche
        Psc_Installer::maybe_upgrade();
        $check($read("$new/periscolaire/assurances/2090/child-2.pdf") === 'version A', 'conflit résolu : document non déplacé');
        $check(get_option('psc_private_dir_path') === $new && $failed('private_dir') === null && !is_dir($old), 'conflit résolu : reprise incomplète');

        // Droit refusé.
        $old = $root . '/ancien-verrouille';
        $put("$old/periscolaire/verrou/child-4.pdf", 'doc-4');
        chmod("$old/periscolaire/verrou", 0555);
        update_option('psc_private_dir_path', $old, false);
        Psc_Installer::maybe_upgrade();
        $check($read("$old/periscolaire/verrou/child-4.pdf") === 'doc-4', 'droit refusé : source perdue');
        $check(get_option('psc_private_dir_path') === $old && $failed('private_dir') !== null, 'droit refusé : échec non signalé');
        chmod("$old/periscolaire/verrou", 0755);
        Psc_Installer::maybe_upgrade();
        $check($read("$new/periscolaire/verrou/child-4.pdf") === 'doc-4' && get_option('psc_private_dir_path') === $new && $failed('private_dir') === null, 'droit rétabli : reprise incomplète');

        // 4. uploads/periscolaire (3.7.0).
        $put("$legacy/verify-p117/child-5.pdf", 'doc-5');
        $put("$legacy/verify-p117/child-6.pdf", 'version A');
        $put("$new/periscolaire/verify-p117/child-6.pdf", 'version B');
        Psc_Installer::maybe_upgrade();
        $check($read("$new/periscolaire/verify-p117/child-5.pdf") === 'doc-5', 'uploads : document non déplacé');
        $check($read("$legacy/verify-p117/child-6.pdf") === 'version A', 'uploads, conflit : source perdue');
        $check(file_exists("$legacy/.htaccess"), 'uploads, conflit : documents laissés sans garde-fou');
        $check(($failed('uploads')['restants'] ?? null) === 1, 'uploads, conflit : alerte absente ou mauvais décompte');
        unlink("$new/periscolaire/verify-p117/child-6.pdf");
        Psc_Installer::maybe_upgrade();
        $check($read("$new/periscolaire/verify-p117/child-6.pdf") === 'version A' && !is_dir($legacy), 'uploads, conflit résolu : reprise incomplète');
        $check($failed('uploads') === null, 'uploads, conflit résolu : alerte conservée');
        $check(!file_exists("$new/periscolaire/.htaccess"), 'uploads : garde-fou recopié parmi les documents');
    } finally {
        remove_filter('query', $sabotage);
        $wpdb->suppress_errors(false);
        remove_filter('psc_private_dir', $filter);
        $wpdb->delete($wpdb->options, array('option_name' => 'psc_migration_lock'));
        wp_cache_delete('psc_migration_lock', 'options');
        update_option('psc_db_version', Psc_Installer::DB_VERSION);
        update_option('psc_private_dir_path', $saved_path, false);
        delete_option('psc_storage_move_failed');
        delete_option('psc_migration_failed');
        foreach (array_diff($legacy_tables, $had_legacy) as $t) $wpdb->query("DROP TABLE IF EXISTS $t");
        $rm($root);
        $rm($legacy);
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Migrations et déménagements : $checks vérifications.");
});
