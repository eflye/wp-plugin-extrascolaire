<?php
/**
 * Script de vérification du scénario de migration — pas de harnais
 * PHPUnit dans ce projet (Playwright seul pour les tests E2E), même rôle
 * que bin/verify-pickup-history.php : celui du test unitaire, en
 * conditions WP-CLI réelles.
 *
 * ⚠ DÉSTRUCTIF — à réserver à un site jetable (l'intégration continue
 * l'exécute juste après l'installation, avant le peuplement des
 * scénarios). Contrairement aux autres verify-*, il ne se contente pas
 * d'ajouter des lignes scopées : il DÉTRUIT le schéma périscolaire du
 * site, le reconstruit à un état hérité (version 2.4.x), rejoue la
 * montée de version complète (Psc_Installer::maybe_upgrade, exactement
 * le chemin qu'une mise à jour par copie de fichiers emprunte), vérifie
 * le résultat, puis reconstruit un schéma vierge
 * (Psc_Installer::activate) — le site ressort avec des tables vides
 * mais valides.
 *
 * Ce que le scénario verrouille : une montée de version « par bonds »
 * (2.4.9 → 3.9.0, sans passer par les releases intermédiaires) doit
 * produire le même schéma et les mêmes données qu'une montée pas à pas.
 * C'est le cas d'usage réel d'une mise à jour par copie de fichiers, et
 * le seul que ce script puisse rejouer de façon déterministe :
 *
 *   - 2.5.0 / 2.7.0 : les colonnes de facturation abandonnées (mois,
 *     trimestre_id) disparaissent — ET la définition finale les attend
 *     (mois est revenu) : sans re-passe de create_tables() après les
 *     migrations, un bond aboutissait à une table sans la colonne que
 *     le code courant écrit ;
 *   - 2.7.0 / 2.10.0 : mercredis fermés, libellés de vacances hérités
 *     rouverts (la re-fermeture par l'import iCal, réseau, n'est PAS
 *     assertée — non déterministe) ;
 *   - 2.8.0 : la colonne menus.mercredi disparaît ;
 *   - 3.0.0 : l'entité année scolaire émerge (année par défaut créée
 *     depuis le trimestre hérité, classe et assurance de l'enfant
 *     transférées dans child_school_years, table child_assurances
 *     supprimée, children.active → statut) ;
 *   - 3.7.0 : le répertoire historique uploads/periscolaire est
 *     déplacé vers le répertoire privé ;
 *   - 3.8.0 : les IBAN hérités en clair sont chiffrés au repos
 *     (déchiffrables à l'identique) et les demandes déjà arbitrées
 *     perdent leur IBAN/BIC.
 *
 * Usage :
 *   wp --require=bin/verify-migrations.php verify-migrations
 *
 * Nettoyage : le fichier de test déplacé dans le répertoire privé est
 * supprimé (et lui seul) ; les tables sont reconstruites vierges.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-migrations', function () {

    if (!class_exists('Psc_Installer')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $failures = array();
    $checks   = 0;

    $assert = function ($label, $actual, $expected) use (&$failures, &$checks) {
        $checks++;
        if ($actual !== $expected) {
            $failures[] = sprintf(
                "%s : attendu %s, obtenu %s",
                $label,
                var_export($expected, true),
                var_export($actual, true)
            );
        }
    };

    $column_exists = function ($table, $column) use ($wpdb) {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            psc_table($table), $column
        )) > 0;
    };

    $table_exists = function ($table) use ($wpdb) {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            psc_table($table)
        )) > 0;
    };

    $cell = function ($sql) use ($wpdb) {
        return $wpdb->get_var($sql);
    };

    $drop_all_psc_tables = function () use ($wpdb) {
        $tables = $wpdb->get_col($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $wpdb->esc_like($wpdb->prefix) . 'psc_%'
        ));
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ((array) $tables as $t) {
            $wpdb->query("DROP TABLE IF EXISTS `$t`");
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        return count((array) $tables);
    };

    /* ---------------------------------------------------------------- */
    /* 1. Destruction du schéma courant                                   */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Destruction du schéma périscolaire courant…');
    $drop_all_psc_tables();

    /* ---------------------------------------------------------------- */
    /* 2. Reconstruction d'un schéma hérité (2.4.x) minimal               */
    /*    Seules les tables porteuses de données que les migrations       */
    /*    transforment sont créées ici : dbDelta, première passe de       */
    /*    maybe_upgrade(), complète le reste (tables nouvelles et         */
    /*    colonnes ajoutées depuis) — c'est exactement ce qu'il fait      */
    /*    pour une mise à jour réelle.                                    */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Reconstruction du schéma hérité 2.4.x…');

    $charset_collate = $wpdb->get_charset_collate();

    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_trim   = psc_table('trimestres');
    $t_days   = psc_table('calendar_days');
    $t_req    = psc_table('requests');
    $t_inv    = psc_table('invoices');
    $t_menu   = psc_table('menus');
    $t_assur  = psc_table('child_assurances');

    // children d'avant 3.0.0 : active (pas statut), classe + classe_annee.
    $wpdb->query("CREATE TABLE $t_child (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        parent_id BIGINT UNSIGNED NOT NULL,
        nom VARCHAR(191) NOT NULL,
        prenom VARCHAR(191) NOT NULL,
        date_naissance DATE NULL,
        sans_porc TINYINT(1) NOT NULL DEFAULT 0,
        vegan TINYINT(1) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        classe VARCHAR(100) NULL,
        classe_annee VARCHAR(20) NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate");

    // trimestres d'avant 3.0.0 : pas de rattachement année scolaire.
    $wpdb->query("CREATE TABLE $t_trim (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        label VARCHAR(191) NOT NULL,
        date_debut DATE NOT NULL,
        date_fin DATE NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id)
    ) $charset_collate");

    // invoices d'avant 2.7.0 : les deux générations de colonnes cohabitent
    // (mois devait disparaître en 2.5.0, trimestre_id en 2.7.0).
    $wpdb->query("CREATE TABLE $t_inv (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        parent_id BIGINT UNSIGNED NOT NULL,
        mois CHAR(7) NOT NULL,
        trimestre_id BIGINT UNSIGNED NULL,
        total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        pdf_path VARCHAR(500) NULL,
        sent_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY parent_mois (parent_id, mois),
        KEY mois (mois),
        KEY parent_trimestre (parent_id, trimestre_id),
        KEY trimestre_id (trimestre_id)
    ) $charset_collate");

    // menus d'avant 2.8.0 : la colonne mercredi (plus de service ce jour).
    $wpdb->query("CREATE TABLE $t_menu (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        semaine_debut DATE NOT NULL,
        lundi TEXT NULL,
        mardi TEXT NULL,
        mercredi TEXT NULL,
        jeudi TEXT NULL,
        vendredi TEXT NULL,
        sent_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY semaine (semaine_debut)
    ) $charset_collate");

    // Les autres tables héritées (parents, requests, calendar_days) portent
    // la définition courante : seule leur DONNÉE est historique.
    foreach (array('parents', 'requests', 'calendar_days') as $name) {
        $t = psc_table($name);
        if ($name === 'parents') {
            $wpdb->query("CREATE TABLE $t (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(191) NOT NULL,
                nom VARCHAR(191) NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                payment_mode VARCHAR(20) NOT NULL DEFAULT 'autre',
                sepa_iban VARCHAR(255) NULL,
                sepa_bic VARCHAR(11) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY email (email)
            ) $charset_collate");
        } elseif ($name === 'requests') {
            $wpdb->query("CREATE TABLE $t (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(191) NOT NULL,
                nom VARCHAR(191) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'unverified',
                payment_mode VARCHAR(20) NOT NULL DEFAULT 'autre',
                sepa_iban VARCHAR(255) NULL,
                sepa_bic VARCHAR(11) NULL,
                created_at DATETIME NOT NULL,
                decided_at DATETIME NULL,
                PRIMARY KEY  (id),
                KEY status (status)
            ) $charset_collate");
        } else {
            $wpdb->query("CREATE TABLE $t (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                trimestre_id BIGINT UNSIGNED NOT NULL,
                jour_date DATE NOT NULL,
                is_open TINYINT(1) NOT NULL DEFAULT 1,
                label VARCHAR(100) NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY trim_date (trimestre_id, jour_date)
            ) $charset_collate");
        }
    }

    // child_assurances, table remplacée en 3.0.0 par child_school_years.
    $wpdb->query("CREATE TABLE $t_assur (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id BIGINT UNSIGNED NOT NULL,
        rentree_year INT NOT NULL,
        file_path VARCHAR(255) NULL,
        original_filename VARCHAR(191) NULL,
        uploaded_at DATETIME NULL,
        PRIMARY KEY  (id)
    ) $charset_collate");

    /* ---------------- Données héritées ---------------- */

    $iban_clair = 'FR7630006000011234567890189'; // IBAN de test valide (mod-97)

    $wpdb->insert($t_parent, array(
        'email'      => 'verif.migrations@example.test',
        'nom'        => 'Vérification',
        'sepa_iban'  => $iban_clair,
        'sepa_bic'   => 'AGRIFRPP',
        'created_at' => current_time('mysql'),
    ), array('%s', '%s', '%s', '%s', '%s'));
    $parent_id = (int) $wpdb->insert_id;

    $wpdb->insert($t_child, array(
        'parent_id'     => $parent_id,
        'nom'           => 'Test',
        'prenom'        => 'Verif',
        'active'        => 1,
        'classe'        => 'CE2',
        'classe_annee'  => '2025-2026',
        'created_at'    => current_time('mysql'),
    ), array('%d', '%s', '%s', '%d', '%s', '%s', '%s'));
    $child_id = (int) $wpdb->insert_id;

    $wpdb->insert($t_trim, array(
        'label'      => 'Verif migrations',
        'date_debut' => '2026-09-01',
        'date_fin'   => '2026-12-18',
        'active'     => 1,
    ), array('%s', '%s', '%s', '%d'));
    $trim_id = (int) $wpdb->insert_id;

    // Un mercredi ouvert (2.7.0 doit le fermer) et un libellé d'vacances
    // hérité posé sur un jour de période scolaire (2.10.0 doit le rouvrir ;
    // l'import iCal ne peut pas re-fermer un jour d'école).
    $wpdb->insert($t_days, array(
        'trimestre_id' => $trim_id, 'jour_date' => '2026-09-02', 'is_open' => 1, 'label' => null,
    ), array('%d', '%s', '%d', '%s'));
    $wpdb->insert($t_days, array(
        'trimestre_id' => $trim_id, 'jour_date' => '2027-01-13', 'is_open' => 0, 'label' => 'Vacances de Noël',
    ), array('%d', '%s', '%d', '%s'));

    $wpdb->insert($t_inv, array(
        'parent_id'    => $parent_id,
        'mois'         => '2026-09',
        'trimestre_id' => $trim_id,
        'total'        => 42.00,
        'created_at'   => current_time('mysql'),
    ), array('%d', '%s', '%d', '%f', '%s'));

    $wpdb->insert($t_menu, array(
        'semaine_debut' => '2026-09-07',
        'lundi'         => 'Menu hérité',
        'mercredi'      => 'Colonne condamnée',
        'created_at'    => current_time('mysql'),
        'updated_at'    => current_time('mysql'),
    ), array('%s', '%s', '%s', '%s', '%s'));

    $wpdb->insert($t_req, array(
        'email'      => 'verif.migrations@example.test',
        'nom'        => 'Vérification',
        'status'     => 'approved',
        'sepa_iban'  => $iban_clair,
        'sepa_bic'   => 'AGRIFRPP',
        'created_at' => current_time('mysql'),
        'decided_at' => current_time('mysql'),
    ), array('%s', '%s', '%s', '%s', '%s', '%s', '%s'));

    $wpdb->insert($t_assur, array(
        'child_id'           => $child_id,
        'rentree_year'       => 2026,
        'file_path'          => '/heritage/assurance-verif.pdf',
        'original_filename'  => 'assurance-verif.pdf',
        'uploaded_at'        => current_time('mysql'),
    ), array('%d', '%d', '%s', '%s', '%s'));

    /* ---------------- 3.7.0 : répertoire hérité d'uploads ---------------- */

    $upload     = wp_upload_dir();
    $legacy_dir = trailingslashit($upload['basedir']) . 'periscolaire';
    $legacy_file = $legacy_dir . '/verif-migrations-e2e.pdf';
    wp_mkdir_p($legacy_dir);
    file_put_contents($legacy_file, '%PDF-1.4 verification migration 3.7.0'); // phpcs:ignore WordPress.WP.AlternativeFunctions

    /* ---------------------------------------------------------------- */
    /* 3. Montée de version complète — le chemin d'une mise à jour réelle */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Montée de version 2.4.9 → ' . Psc_Installer::DB_VERSION . ' (maybe_upgrade)…');
    update_option('psc_db_version', '2.4.9');
    Psc_Installer::maybe_upgrade();

    /* ---------------------------------------------------------------- */
    /* 4. Assertions sur le schéma et les données obtenus                 */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Vérification du résultat…');

    $assert('psc_db_version portée à la version courante', get_option('psc_db_version'), Psc_Installer::DB_VERSION);

    // 2.5.0 + 2.7.0 : facturation. dbDelta ne sachant que AJOUTER, les
    // seules preuves que les migrations ont tourné sont : la table vidée
    // (jamais le fait de dbDelta) et les colonnes/indices abandonnés qui
    // ne font plus partie d'aucune définition.
    $assert('invoices.trimestre_id supprimée par 2.7.0', $column_exists('invoices', 'trimestre_id'), false);
    $assert('invoices vidée par les migrations de facturation', (int) $cell("SELECT COUNT(*) FROM " . psc_table('invoices')), 0);
    $index_exists = function ($table, $index) use ($wpdb) {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            psc_table($table), $index
        )) > 0;
    };
    $assert('index parent_trimestre supprimé par 2.7.0', $index_exists('invoices', 'parent_trimestre'), false);
    // … et la définition finale réintroduit mois : une montée « par bonds »
    // doit aboutir au même schéma qu'une montée pas à pas (create_tables()
    // rejoué après les migrations — sans cette seconde passe, le bond
    // aboutissait à une table sans la colonne que le code courant écrit).
    $assert('invoices.mois restaurée par la passe finale de create_tables()', $column_exists('invoices', 'mois'), true);

    // 2.7.0 + 2.10.0 : calendrier
    $t_days = psc_table('calendar_days');
    $mercredi = $wpdb->get_row("SELECT is_open, label FROM $t_days WHERE jour_date = '2026-09-02'");
    $assert('mercredi ouvert hérité fermé par 2.7.0', $mercredi ? array((int) $mercredi->is_open, $mercredi->label) : null, array(0, 'Mercredi'));
    $noel = $wpdb->get_row("SELECT is_open, label FROM $t_days WHERE jour_date = '2027-01-13'");
    $assert('libellé de vacances hérité rouvert par 2.10.0', $noel ? array((int) $noel->is_open, $noel->label) : null, array(1, null));

    // 2.8.0 : menus
    $assert('menus.mercredi supprimée par 2.8.0', $column_exists('menus', 'mercredi'), false);

    // 3.0.0 : année scolaire
    $t_years = psc_table('school_years');
    $t_cy    = psc_table('child_school_years');
    $year_id = (int) $cell("SELECT id FROM $t_years");
    $assert('une seule année scolaire créée par 3.0.0', (int) $cell("SELECT COUNT(*) FROM $t_years"), 1);
    $assert('année scolaire active (le trimestre hérité était actif)', (string) $cell("SELECT statut FROM $t_years WHERE id = $year_id"), 'active');
    $assert('trimestre hérité rattaché à l\'année créée', (int) $cell("SELECT school_year_id FROM $t_trim WHERE id = $trim_id"), $year_id);

    $cy = $wpdb->get_row("SELECT * FROM $t_cy WHERE child_id = $child_id");
    $assert('une seule ligne enfant×année (classe et assurance fusionnées)', (int) $cell("SELECT COUNT(*) FROM $t_cy WHERE child_id = $child_id"), 1);
    $assert('classe transférée dans child_school_years', $cy ? $cy->classe : null, 'CE2');
    $assert('assurance transférée : chemin de fichier', $cy ? $cy->assurance_file_path : null, '/heritage/assurance-verif.pdf');
    $assert('assurance transférée : nom original', $cy ? $cy->assurance_original_filename : null, 'assurance-verif.pdf');
    $assert('table child_assurances supprimée par 3.0.0', $table_exists('child_assurances'), false);

    $assert('children.active → statut actif', (string) $cell("SELECT statut FROM $t_child WHERE id = $child_id"), 'actif');
    $assert('children.active supprimée', $column_exists('children', 'active'), false);
    $assert('children.classe supprimée', $column_exists('children', 'classe'), false);
    $assert('children.classe_annee supprimée', $column_exists('children', 'classe_annee'), false);

    // 3.7.0 : répertoire privé
    $assert('fichier hérité déplacé vers le répertoire privé (3.7.0)', file_exists(psc_private_path('periscolaire/verif-migrations-e2e.pdf')), true); // phpcs:ignore WordPress.WP.AlternativeFunctions
    $assert('fichier hérité disparu d\'uploads/periscolaire', file_exists($legacy_file), false); // phpcs:ignore WordPress.WP.AlternativeFunctions

    // 3.8.0 : IBAN au repos
    $parent_iban = (string) $cell("SELECT sepa_iban FROM $t_parent WHERE id = $parent_id");
    $assert('IBAN du foyer chiffré (préfixe psc1:)', strpos($parent_iban, 'psc1:') === 0, true);
    $assert('IBAN du foyer déchiffrable à l\'identique', psc_decrypt($parent_iban), $iban_clair);
    $req = $wpdb->get_row("SELECT sepa_iban, sepa_bic FROM " . psc_table('requests') . " WHERE status = 'approved'");
    $assert('demande approuvée : IBAN purgé', $req ? $req->sepa_iban : 'ligne introuvable', null);
    $assert('demande approuvée : BIC purgé', $req ? $req->sepa_bic : 'ligne introuvable', null);

    /* ---------------------------------------------------------------- */
    /* 5. Nettoyage : fichier migré + schéma reconstruit vierge           */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Nettoyage (fichier migré, reconstruction du schéma)…');

    @unlink(psc_private_path('periscolaire/verif-migrations-e2e.pdf')); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    // rmdir ne réussit que sur un répertoire vide : jamais de perte de
    // données réelles, dans le répertoire privé comme dans uploads.
    @rmdir($legacy_dir);                                       // phpcs:ignore WordPress.PHP.NoSilencedErrors
    @rmdir(psc_private_path('periscolaire'));                  // phpcs:ignore WordPress.PHP.NoSilencedErrors

    $drop_all_psc_tables();
    Psc_Installer::activate();

    WP_CLI::log('');
    WP_CLI::log(sprintf('%d vérification(s) effectuée(s), %d échec(s).', $checks, count($failures)));
    if ($failures) {
        foreach ($failures as $f) {
            WP_CLI::log('  ÉCHEC — ' . $f);
        }
        WP_CLI::error('Le scénario de migration ne produit pas le schéma / les données attendus.');
    }

    WP_CLI::success('Scénario de migration conforme (schéma reconstruit vierge).');
});
