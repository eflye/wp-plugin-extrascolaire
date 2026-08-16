<?php
if (!defined('ABSPATH')) exit;

class Psc_Installer {

    const DB_VERSION = '3.3.0';

    public static function activate() {
        self::create_tables();
        update_option('psc_db_version', self::DB_VERSION);
    }

    /**
     * Vérifie à chaque chargement si le schéma doit être mis à jour.
     * Évite les erreurs après une mise à jour du plugin par simple copie
     * de fichiers (cas fréquent : le hook d'activation n'est pas rejoué).
     */
    public static function maybe_upgrade() {
        $current = get_option('psc_db_version');
        if ($current !== self::DB_VERSION) {
            // dbDelta() est additif (ajoute tables/colonnes manquantes,
            // ne supprime jamais) : l'exécuter en premier garantit que les
            // migrations ci-dessous trouvent les tables dont elles ont
            // besoin (ex : migrate_2_10_0 a besoin de wp_psc_school_calendar).
            self::create_tables();

            if ($current && version_compare($current, '2.5.0', '<')) {
                self::migrate_2_5_0();
            }
            if ($current && version_compare($current, '2.7.0', '<')) {
                self::migrate_2_7_0();
            }
            if ($current && version_compare($current, '2.8.0', '<')) {
                self::migrate_2_8_0();
            }
            if ($current && version_compare($current, '2.9.0', '<')) {
                self::migrate_2_9_0();
            }
            if ($current && version_compare($current, '2.10.0', '<')) {
                self::migrate_2_10_0();
            }
            if ($current && version_compare($current, '3.0.0', '<')) {
                self::migrate_3_0_0();
            }
            update_option('psc_db_version', self::DB_VERSION);
        }
    }

    /**
     * Passe la facturation mensuelle (colonne 'mois') au modèle par trimestre.
     * Les anciennes factures sont supprimées : elles devront être regénérées
     * avec le nouveau modèle. La colonne 'mois' est retirée de la table.
     */
    private static function migrate_2_5_0() {
        global $wpdb;
        $t_inv = psc_table('invoices');

        $has_mois = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$t_inv}' AND COLUMN_NAME = 'mois'"
        );
        if (!$has_mois) return;

        $wpdb->query("DELETE FROM {$t_inv}");

        foreach (array('parent_mois', 'mois') as $idx) {
            $exists = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = '{$t_inv}' AND INDEX_NAME = '{$idx}'"
            );
            if ($exists) {
                $wpdb->query("ALTER TABLE {$t_inv} DROP INDEX `{$idx}`");
            }
        }
        $wpdb->query("ALTER TABLE {$t_inv} DROP COLUMN `mois`");
    }

    /**
     * Revient à la facturation mensuelle : retire 'trimestre_id', restaure
     * 'mois'. Les factures trimestrielles sont supprimées : elles devront
     * être regénérées avec le modèle mensuel.
     *
     * Ferme aussi rétroactivement les mercredis déjà ouverts dans le
     * calendrier : il n'y a jamais eu de service (périscolaire/cantine) ce
     * jour-là, c'était un oubli du générateur de calendrier.
     */
    private static function migrate_2_7_0() {
        global $wpdb;
        $t_inv = psc_table('invoices');

        $has_trimestre = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$t_inv}' AND COLUMN_NAME = 'trimestre_id'"
        );
        if ($has_trimestre) {
            $wpdb->query("DELETE FROM {$t_inv}");

            foreach (array('parent_trimestre', 'trimestre_id') as $idx) {
                $exists = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = '{$t_inv}' AND INDEX_NAME = '{$idx}'"
                );
                if ($exists) {
                    $wpdb->query("ALTER TABLE {$t_inv} DROP INDEX `{$idx}`");
                }
            }
            $wpdb->query("ALTER TABLE {$t_inv} DROP COLUMN `trimestre_id`");
        }

        $t_days = psc_table('calendar_days');
        $wpdb->query(
            "UPDATE {$t_days} SET is_open = 0, label = 'Mercredi'
             WHERE DAYOFWEEK(jour_date) = 4 AND is_open = 1"
        );
    }

    /**
     * Il n'y a pas de service le mercredi (cf. migrate_2_7_0) : la colonne
     * 'mercredi' de la table des menus de cantine n'a donc plus d'usage.
     */
    private static function migrate_2_8_0() {
        global $wpdb;
        $t_menu = psc_table('menus');

        $has_mercredi = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$t_menu}' AND COLUMN_NAME = 'mercredi'"
        );
        if ($has_mercredi) {
            $wpdb->query("ALTER TABLE {$t_menu} DROP COLUMN `mercredi`");
        }
    }

    /**
     * Fermait rétroactivement les jours de vacances scolaires (zone C)
     * d'après un tableau codé en dur. Entièrement remplacée et corrigée
     * par migrate_2_10_0() (le tableau codé en dur fermait aussi, par
     * erreur, le jour de reprise) — no-op conservé pour l'historique des
     * migrations.
     */
    private static function migrate_2_9_0() {
    }

    /**
     * Remplace le tableau de vacances codé en dur par le vrai calendrier
     * scolaire officiel (zone C), chargé depuis le flux iCal du ministère.
     *
     * L'ancien tableau fermait aussi le jour de reprise (bug : DTEND dans
     * le flux officiel est exclusif — ce jour est un jour d'école, pas de
     * vacances). On rouvre donc d'abord tout ce que migrate_2_9_0 avait
     * fermé, puis l'import référme uniquement les bons jours.
     */
    private static function migrate_2_10_0() {
        global $wpdb;
        $t_days = psc_table('calendar_days');
        $t_reg  = psc_table('registrations');
        $t_sch  = psc_table('school_calendar');

        $old_labels = array(
            'Vacances de la Toussaint', 'Vacances de Noël',
            "Vacances d'hiver", 'Vacances de printemps', "Vacances d'été",
        );
        $placeholders = implode(',', array_fill(0, count($old_labels), '%s'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$t_days} SET is_open = 1, label = NULL WHERE label IN ($placeholders)",
            $old_labels
        ));

        $result = Psc_School_Calendar::import();
        if (is_wp_error($result)) {
            // Pas de réseau au moment de la migration : l'admin pourra
            // charger le calendrier manuellement depuis Périscolaire >
            // Calendrier scolaire.
            return;
        }

        $wpdb->query(
            "UPDATE {$t_days} d INNER JOIN {$t_sch} s ON s.jour_date = d.jour_date
             SET d.is_open = 0, d.label = s.label
             WHERE s.is_closed = 1 AND d.is_open = 1"
        );
        // Il n'y a jamais eu de service ces jours-là : les inscriptions
        // déjà enregistrées dessus n'ont pas lieu d'être (et ne doivent
        // pas être facturées).
        $wpdb->query(
            "DELETE r FROM {$t_reg} r INNER JOIN {$t_sch} s ON s.jour_date = r.jour_date
             WHERE s.is_closed = 1"
        );
    }

    /**
     * Introduit l'entité "année scolaire" au-dessus des trimestres :
     * - rattache chaque trimestre existant à une année scolaire (une
     *   année par défaut est créée si aucun trimestre n'en a déjà une,
     *   à partir des dates du plus ancien/plus récent trimestre) ;
     * - migre child_assurances (enfant + année civile de rentrée) et
     *   children.classe/classe_annee vers wp_psc_child_school_years
     *   (enfant + année scolaire), qui remplace les deux ;
     * - children.active devient children.statut ('actif'|'sorti'),
     *   children.classe et children.classe_annee sont supprimées.
     * Le plugin n'étant déployé nulle part au moment de cette version,
     * cette migration privilégie la cohérence du schéma final à
     * l'exactitude historique d'éventuelles données de test.
     */
    private static function migrate_3_0_0() {
        global $wpdb;
        $t_trim  = psc_table('trimestres');
        $t_child = psc_table('children');
        $t_years = psc_table('school_years');
        $t_cy    = psc_table('child_school_years');
        $t_assur = psc_table('child_assurances');

        // 1) Année scolaire par défaut pour les trimestres qui n'en ont pas
        // encore (colonne tout juste ajoutée par create_tables() ci-dessus,
        // donc toujours NULL à ce stade sur une instance existante).
        $orphan_trimestres = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t_trim} WHERE school_year_id IS NULL");
        if ($orphan_trimestres > 0) {
            $bounds = $wpdb->get_row("SELECT MIN(date_debut) AS debut, MAX(date_fin) AS fin FROM {$t_trim}");
            $debut = $bounds && $bounds->debut ? $bounds->debut : current_time('Y-m-d');
            $fin   = $bounds && $bounds->fin   ? $bounds->fin   : gmdate('Y-m-d', strtotime($debut . ' +1 year'));
            $rentree_year = (int) date('Y', strtotime($debut));
            $has_active_trimestre = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t_trim} WHERE active = 1");

            $wpdb->insert($t_years, array(
                'label'      => $rentree_year . '-' . ($rentree_year + 1),
                'date_debut' => $debut,
                'date_fin'   => $fin,
                'statut'     => $has_active_trimestre ? 'active' : 'preparation',
                'created_at' => current_time('mysql'),
            ), array('%s', '%s', '%s', '%s', '%s'));
            $default_year_id = (int) $wpdb->insert_id;

            $wpdb->query($wpdb->prepare(
                "UPDATE {$t_trim} SET school_year_id = %d WHERE school_year_id IS NULL",
                $default_year_id
            ));
        } else {
            $default_year_id = (int) $wpdb->get_var(
                "SELECT id FROM {$t_years} WHERE statut = 'active' ORDER BY id DESC LIMIT 1"
            );
        }

        // 2) children.classe / classe_annee -> child_school_years.classe,
        // uniquement si les anciennes colonnes existent encore (idempotent :
        // ne fait rien si cette migration a déjà tourné).
        $has_classe = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t_child}' AND COLUMN_NAME = 'classe'"
        );
        if ($has_classe && $default_year_id) {
            $children_with_classe = $wpdb->get_results(
                "SELECT id, classe FROM {$t_child} WHERE classe IS NOT NULL AND classe != ''"
            );
            foreach ($children_with_classe as $c) {
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$t_cy} (child_id, school_year_id, classe, statut, date_inscription)
                     VALUES (%d, %d, %s, 'inscrit', %s)
                     ON DUPLICATE KEY UPDATE classe = VALUES(classe)",
                    $c->id, $default_year_id, $c->classe, current_time('mysql')
                ));
            }
        }

        // 3) child_assurances -> child_school_years (colonnes assurance_*),
        // rapprochée par année civile de rentrée si possible, sinon
        // l'année scolaire par défaut.
        $assur_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $t_assur
        ));
        if ($assur_exists) {
            $rows = $wpdb->get_results("SELECT * FROM {$t_assur}");
            foreach ($rows as $row) {
                $year_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$t_years} WHERE YEAR(date_debut) = %d ORDER BY id DESC LIMIT 1",
                    $row->rentree_year
                ));
                if (!$year_id) $year_id = $default_year_id;
                if (!$year_id) continue;

                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$t_cy} (child_id, school_year_id, statut, assurance_file_path, assurance_original_filename, assurance_uploaded_at)
                     VALUES (%d, %d, 'inscrit', %s, %s, %s)
                     ON DUPLICATE KEY UPDATE
                        assurance_file_path = VALUES(assurance_file_path),
                        assurance_original_filename = VALUES(assurance_original_filename),
                        assurance_uploaded_at = VALUES(assurance_uploaded_at)",
                    $row->child_id, $year_id, $row->file_path, $row->original_filename, $row->uploaded_at
                ));
            }
            $wpdb->query("DROP TABLE {$t_assur}");
        }

        // 4) children.active -> children.statut, puis suppression des
        // colonnes devenues obsolètes.
        $has_active = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t_child}' AND COLUMN_NAME = 'active'"
        );
        if ($has_active) {
            $wpdb->query("UPDATE {$t_child} SET statut = 'sorti' WHERE active = 0");
            $wpdb->query("ALTER TABLE {$t_child} DROP COLUMN `active`");
        }
        if ($has_classe) {
            $wpdb->query("ALTER TABLE {$t_child} DROP COLUMN `classe`");
        }
        $has_classe_annee = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t_child}' AND COLUMN_NAME = 'classe_annee'"
        );
        if ($has_classe_annee) {
            $wpdb->query("ALTER TABLE {$t_child} DROP COLUMN `classe_annee`");
        }
    }

    protected static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $t_trim  = psc_table('trimestres');
        $t_parent = psc_table('parents');
        $t_child = psc_table('children');
        $t_days  = psc_table('calendar_days');
        $t_reg   = psc_table('registrations');
        $t_req   = psc_table('requests');
        $t_inv   = psc_table('invoices');
        $t_menu  = psc_table('menus');
        $t_sch   = psc_table('school_calendar');
        $t_sup   = psc_table('supplier_orders');
        $t_years  = psc_table('school_years');
        $t_cy     = psc_table('child_school_years');
        $t_pickup = psc_table('pickup_persons');
        $t_pkhist = psc_table('pickup_history');
        $t_att    = psc_table('attendance');

        $sql = "CREATE TABLE $t_years (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            label VARCHAR(20) NOT NULL,
            date_debut DATE NOT NULL,
            date_fin DATE NOT NULL,
            statut VARCHAR(20) NOT NULL DEFAULT 'preparation',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY statut (statut)
        ) $charset_collate;

CREATE TABLE $t_trim (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_year_id BIGINT UNSIGNED NULL,
            label VARCHAR(191) NOT NULL,
            date_debut DATE NOT NULL,
            date_fin DATE NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY active (active),
            KEY school_year_id (school_year_id)
        ) $charset_collate;

CREATE TABLE $t_parent (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(191) NOT NULL,
            nom VARCHAR(191) NULL,
            prenom VARCHAR(191) NULL,
            telephone_mobile VARCHAR(40) NULL,
            telephone_fixe VARCHAR(40) NULL,
            adresse VARCHAR(255) NULL,
            code_postal VARCHAR(10) NULL,
            ville VARCHAR(100) NULL,
            pending_email VARCHAR(191) NULL,
            pending_email_token_hash VARCHAR(64) NULL,
            pending_email_token_expires DATETIME NULL,
            token_hash VARCHAR(64) NULL,
            token_expires DATETIME NULL,
            last_login DATETIME NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            payment_mode VARCHAR(20) NOT NULL DEFAULT 'autre',
            sepa_iban VARCHAR(34) NULL,
            sepa_bic VARCHAR(11) NULL,
            sepa_titulaire VARCHAR(191) NULL,
            sepa_adresse VARCHAR(255) NULL,
            sepa_code_postal VARCHAR(10) NULL,
            sepa_ville VARCHAR(100) NULL,
            sepa_mandate_ref VARCHAR(35) NULL,
            reglement_accepted_at DATETIME NULL,
            sepa_reglement_accepted_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email),
            KEY active (active)
        ) $charset_collate;

CREATE TABLE $t_child (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id BIGINT UNSIGNED NOT NULL,
            nom VARCHAR(191) NOT NULL,
            prenom VARCHAR(191) NOT NULL,
            date_naissance DATE NULL,
            sans_porc TINYINT(1) NOT NULL DEFAULT 0,
            vegan TINYINT(1) NOT NULL DEFAULT 0,
            statut VARCHAR(20) NOT NULL DEFAULT 'actif',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY parent_id (parent_id),
            KEY statut (statut)
        ) $charset_collate;

CREATE TABLE $t_cy (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id BIGINT UNSIGNED NOT NULL,
            school_year_id BIGINT UNSIGNED NOT NULL,
            classe VARCHAR(100) NULL,
            statut VARCHAR(20) NOT NULL DEFAULT 'inscrit',
            date_inscription DATETIME NULL,
            reglement_accepted_at DATETIME NULL,
            assurance_file_path VARCHAR(255) NULL,
            assurance_original_filename VARCHAR(191) NULL,
            assurance_uploaded_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY child_year (child_id, school_year_id),
            KEY school_year_id (school_year_id)
        ) $charset_collate;

CREATE TABLE $t_days (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            trimestre_id BIGINT UNSIGNED NOT NULL,
            jour_date DATE NOT NULL,
            is_open TINYINT(1) NOT NULL DEFAULT 1,
            label VARCHAR(100) NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY trim_date (trimestre_id, jour_date),
            KEY trim_open (trimestre_id, is_open)
        ) $charset_collate;

CREATE TABLE $t_reg (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id BIGINT UNSIGNED NOT NULL,
            trimestre_id BIGINT UNSIGNED NOT NULL,
            jour_date DATE NOT NULL,
            service VARCHAR(10) NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY child_date_service (child_id, jour_date, service),
            KEY trim_child (trimestre_id, child_id)
        ) $charset_collate;

CREATE TABLE $t_req (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(191) NOT NULL,
            nom VARCHAR(191) NULL,
            prenom VARCHAR(191) NULL,
            telephone VARCHAR(40) NULL,
            adresse VARCHAR(255) NULL,
            code_postal VARCHAR(10) NULL,
            ville VARCHAR(100) NULL,
            children_json TEXT NULL,
            message TEXT NULL,
            verify_hash VARCHAR(64) NULL,
            verify_expires DATETIME NULL,
            verified TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'unverified',
            note TEXT NULL,
            reglement_accepted_at DATETIME NULL,
            payment_mode VARCHAR(20) NOT NULL DEFAULT 'autre',
            sepa_reglement_accepted_at DATETIME NULL,
            sepa_iban VARCHAR(34) NULL,
            sepa_bic VARCHAR(11) NULL,
            sepa_titulaire VARCHAR(191) NULL,
            sepa_adresse VARCHAR(255) NULL,
            sepa_code_postal VARCHAR(10) NULL,
            sepa_ville VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            decided_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY email (email)
        ) $charset_collate;

CREATE TABLE $t_inv (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id BIGINT UNSIGNED NOT NULL,
            mois CHAR(7) NOT NULL,
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            pdf_path VARCHAR(500) NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY parent_mois (parent_id, mois),
            KEY mois (mois),
            KEY parent_id (parent_id)
        ) $charset_collate;

CREATE TABLE $t_menu (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            semaine_debut DATE NOT NULL,
            lundi TEXT NULL,
            mardi TEXT NULL,
            jeudi TEXT NULL,
            vendredi TEXT NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY semaine (semaine_debut)
        ) $charset_collate;

CREATE TABLE $t_sch (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            jour_date DATE NOT NULL,
            label VARCHAR(191) NULL,
            is_closed TINYINT(1) NOT NULL DEFAULT 1,
            source VARCHAR(10) NOT NULL DEFAULT 'import',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY jour_date (jour_date)
        ) $charset_collate;

CREATE TABLE $t_sup (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            semaine_debut DATE NOT NULL,
            counts_json TEXT NOT NULL,
            total_repas INT UNSIGNED NOT NULL DEFAULT 0,
            supplier_email VARCHAR(191) NOT NULL,
            email_subject VARCHAR(255) NOT NULL,
            email_body LONGTEXT NOT NULL,
            sent_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY semaine (semaine_debut)
        ) $charset_collate;

CREATE TABLE $t_pickup (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id BIGINT UNSIGNED NOT NULL,
            nom VARCHAR(191) NOT NULL,
            prenom VARCHAR(191) NOT NULL,
            lien VARCHAR(100) NULL,
            telephone VARCHAR(40) NOT NULL,
            piece_identite TINYINT(1) NOT NULL DEFAULT 0,
            statut VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            retiree_le DATETIME NULL,
            retiree_par VARCHAR(191) NULL,
            PRIMARY KEY  (id),
            KEY child_id (child_id),
            KEY statut (statut)
        ) $charset_collate;

CREATE TABLE $t_pkhist (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id BIGINT UNSIGNED NOT NULL,
            pickup_person_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(20) NOT NULL,
            person_snapshot TEXT NOT NULL,
            source VARCHAR(20) NOT NULL,
            acteur_parent_id BIGINT UNSIGNED NULL,
            acteur_wp_user_id BIGINT UNSIGNED NULL,
            acteur_label VARCHAR(191) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY child_id (child_id),
            KEY pickup_person_id (pickup_person_id)
        ) $charset_collate;

CREATE TABLE $t_att (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id BIGINT UNSIGNED NOT NULL,
            jour_date DATE NOT NULL,
            service VARCHAR(10) NOT NULL,
            present TINYINT(1) NOT NULL DEFAULT 1,
            departure_time TIME NULL,
            pointed_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY child_date_service (child_id, jour_date, service),
            KEY jour_date (jour_date)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Génère les lignes de calendrier pour un trimestre : ouvert par défaut,
     * fermé automatiquement les week-ends et jours fériés.
     *
     * Les dates sont validées en amont par l'appelant, mais on revalide ici :
     * cette méthode est publique et pourrait être appelée depuis ailleurs.
     */
    public static function generate_calendar_days($trimestre_id, $date_debut, $date_fin) {
        global $wpdb;

        $trimestre_id = absint($trimestre_id);
        $date_debut = psc_valid_date($date_debut);
        $date_fin = psc_valid_date($date_fin);
        if (!$trimestre_id || !$date_debut || !$date_fin) return false;

        $t_days = psc_table('calendar_days');

        $start = new DateTime($date_debut);
        $end = new DateTime($date_fin);
        $end->modify('+1 day');
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);

        $count = 0;
        foreach ($period as $d) {
            if (++$count > psc_max_trimestre_days()) break;

            $date_str = $d->format('Y-m-d');
            $is_open = 1;
            $label = null;
            if (psc_is_weekend($date_str)) {
                $is_open = 0;
                $label = 'Week-end';
            } elseif (psc_is_wednesday($date_str)) {
                $is_open = 0;
                $label = 'Mercredi';
            } elseif (psc_is_school_vacation($date_str)) {
                $is_open = 0;
                $label = psc_school_vacation_label($date_str);
            } elseif (psc_is_holiday($date_str)) {
                $is_open = 0;
                $label = 'Férié';
            }
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $t_days (trimestre_id, jour_date, is_open, label) VALUES (%d, %s, %d, %s)
                 ON DUPLICATE KEY UPDATE is_open = VALUES(is_open), label = VALUES(label)",
                $trimestre_id, $date_str, $is_open, $label
            ));
        }
        return true;
    }

    /**
     * Marque une plage de dates comme fermée (vacances, fermeture exceptionnelle).
     */
    public static function set_range_closed($trimestre_id, $date_debut, $date_fin, $label) {
        global $wpdb;

        $trimestre_id = absint($trimestre_id);
        $date_debut = psc_valid_date($date_debut);
        $date_fin = psc_valid_date($date_fin);
        if (!$trimestre_id || !$date_debut || !$date_fin) return false;

        $label = sanitize_text_field($label);
        $t_days = psc_table('calendar_days');

        $start = new DateTime($date_debut);
        $end = new DateTime($date_fin);
        $end->modify('+1 day');
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);

        $count = 0;
        foreach ($period as $d) {
            if (++$count > psc_max_trimestre_days()) break;
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $t_days (trimestre_id, jour_date, is_open, label) VALUES (%d, %s, 0, %s)
                 ON DUPLICATE KEY UPDATE is_open = 0, label = VALUES(label)",
                $trimestre_id, $d->format('Y-m-d'), $label
            ));
        }
        return true;
    }
}
