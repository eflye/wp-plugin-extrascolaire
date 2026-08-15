<?php
if (!defined('ABSPATH')) exit;

class Psc_Installer {

    const DB_VERSION = '2.16.0';

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
        $t_assur = psc_table('child_assurances');

        $sql = "CREATE TABLE $t_trim (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            label VARCHAR(191) NOT NULL,
            date_debut DATE NOT NULL,
            date_fin DATE NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY active (active)
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
            classe VARCHAR(100) NULL,
            date_naissance DATE NULL,
            classe_annee INT NULL,
            sans_porc TINYINT(1) NOT NULL DEFAULT 0,
            vegan TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY parent_id (parent_id)
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

CREATE TABLE $t_assur (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id BIGINT UNSIGNED NOT NULL,
            rentree_year INT NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            original_filename VARCHAR(191) NOT NULL,
            uploaded_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY child_year (child_id, rentree_year),
            KEY child_id (child_id)
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
