<?php
if (!defined('ABSPATH')) exit;

class Psc_Installer {

    const DB_VERSION = '2.5.0';

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
            if ($current && version_compare($current, '2.5.0', '<')) {
                self::migrate_2_5_0();
            }
            self::create_tables();
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
            adresse VARCHAR(255) NULL,
            code_postal VARCHAR(10) NULL,
            ville VARCHAR(100) NULL,
            token_hash VARCHAR(64) NULL,
            token_expires DATETIME NULL,
            last_login DATETIME NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
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
            created_at DATETIME NOT NULL,
            decided_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY email (email)
        ) $charset_collate;

CREATE TABLE $t_inv (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id BIGINT UNSIGNED NOT NULL,
            trimestre_id BIGINT UNSIGNED NOT NULL,
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            pdf_path VARCHAR(500) NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY parent_trimestre (parent_id, trimestre_id),
            KEY trimestre_id (trimestre_id),
            KEY parent_id (parent_id)
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
