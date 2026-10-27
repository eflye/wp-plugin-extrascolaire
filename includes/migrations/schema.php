<?php
/**
 * Schéma cible de la base : le DDL que Psc_Installer::create_tables()
 * confie à dbDelta() (additif : ajoute tables, colonnes et index
 * manquants, ne supprime jamais). Chargé à la demande, pendant une
 * activation ou une montée de version seulement — jamais à chaque
 * requête.
 *
 * Mise en forme à respecter (dbDelta() la lit mot à mot) : deux espaces
 * après PRIMARY KEY, un champ ou une clé par ligne, pas d'apostrophe
 * autour des noms, chaque CREATE TABLE terminé par le charset.
 */
if (!defined('ABSPATH')) exit;

class Psc_Schema {

    /**
     * @param string $charset_collate Issu de $wpdb->get_charset_collate().
     * @param bool   $before_4_17_0   Montée depuis un schéma < 4.17.0 : children
     *                                garde cantine_sans_repas, que la migration lit.
     * @param bool   $before_4_20_0   Montée depuis un schéma < 4.20.0 : children
     *                                garde food_allergies et food_allergy_consent_at.
     * @return string
     */
    public static function sql($charset_collate, $before_4_17_0, $before_4_20_0) {
        $t_parent = psc_table('parents');
        $t_child = psc_table('children');
        $t_req   = psc_table('requests');
        $t_inv   = psc_table('invoices');
        $t_invv  = psc_table('invoice_versions');
        $t_menu  = psc_table('menus');
        $t_sch   = psc_table('school_calendar');
        $t_sup   = psc_table('supplier_orders');
        $t_env   = psc_table('envois');
        $t_years  = psc_table('school_years');
        $t_cy     = psc_table('child_school_years');
        $t_pickup = psc_table('pickup_persons');
        $t_pkhist = psc_table('pickup_history');
        $t_att    = psc_table('attendance');
        $t_svc    = psc_table('service_closures');
        $t_messages = psc_table('messages');
        $t_message_destinataires = psc_table('message_destinataires');
        $t_impersonations = psc_table('impersonations');
        $t_conversations = psc_table('conversations');
        $t_conv_messages = psc_table('conversation_messages');
        $t_audit_log = psc_table('audit_log');
        $t_tarifs = psc_table('tarifs');
        $t_sans_repas = psc_table('sans_repas');
        $t_doc_versions = psc_table('document_versions');
        $t_login_tokens = psc_table('login_tokens');
        // v4.0 — rythme & exceptions ; l'année scolaire est une seule table
        // depuis 4.15.0 (school_years porte aussi le calendrier).
        $t_hol  = psc_table('holidays');
        $t_pat  = psc_table('pattern');
        $t_exc  = psc_table('exception');

        // Avant 4.17.0, le statut « cantine sans repas » était un booléen de
        // l'enfant : migrate_4_17_0() le convertit en période datée puis
        // supprime la colonne.
        $child_sans_repas_legacy = $before_4_17_0 ? "cantine_sans_repas TINYINT(1) NOT NULL DEFAULT 0,\n            " : '';
        // Avant 4.20.0, l'enfant portait un texte libre d'allergies :
        // migrate_4_20_0() le convertit en signalement puis supprime la colonne.
        $child_allergies_legacy = $before_4_20_0 ? "food_allergies TEXT NULL,\n            " : '';
        $child_consent_legacy = $before_4_20_0 ? "food_allergy_consent_at DATETIME NULL,\n            " : '';

        $sql = "CREATE TABLE $t_years (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            year_key VARCHAR(9) NOT NULL,
            date_debut DATE NOT NULL,
            date_fin DATE NOT NULL,
            statut VARCHAR(20) NOT NULL DEFAULT 'preparation',
            vacation_ranges LONGTEXT NULL,
            lock_hours SMALLINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY year_key (year_key),
            KEY statut (statut)
        ) $charset_collate;

CREATE TABLE $t_hol (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            year_key VARCHAR(9) NOT NULL,
            jour_date DATE NOT NULL,
            label VARCHAR(191) NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY year_date (year_key, jour_date)
        ) $charset_collate;

CREATE TABLE $t_pat (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id BIGINT UNSIGNED NOT NULL,
            school_year VARCHAR(9) NOT NULL,
            weekday TINYINT UNSIGNED NOT NULL,
            service_code VARCHAR(10) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY child_year_day_service (child_id, school_year, weekday, service_code),
            KEY school_year (school_year)
        ) $charset_collate;

CREATE TABLE $t_exc (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id BIGINT UNSIGNED NOT NULL,
            jour_date DATE NOT NULL,
            service_code VARCHAR(10) NOT NULL,
            `value` TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY child_date_service (child_id, jour_date, service_code),
            KEY jour_date (jour_date)
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
            sepa_iban VARCHAR(255) NULL,
            sepa_bic VARCHAR(11) NULL,
            sepa_titulaire VARCHAR(191) NULL,
            sepa_adresse VARCHAR(255) NULL,
            sepa_code_postal VARCHAR(10) NULL,
            sepa_ville VARCHAR(100) NULL,
            sepa_mandate_ref VARCHAR(35) NULL,
            sepa_country CHAR(2) NOT NULL DEFAULT 'FR',
            reglement_accepted_at DATETIME NULL,
            reglement_version_id BIGINT UNSIGNED NULL,
            sepa_reglement_accepted_at DATETIME NULL,
            sepa_reglement_version_id BIGINT UNSIGNED NULL,
            second_parent_prenom VARCHAR(191) NULL,
            second_parent_nom VARCHAR(191) NULL,
            second_parent_email VARCHAR(191) NULL,
            second_parent_telephone VARCHAR(40) NULL,
            onboarding_seen_at DATETIME NULL,
            menu_emails TINYINT(1) NOT NULL DEFAULT 1,
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
            {$child_sans_repas_legacy}{$child_allergies_legacy}food_allergy_signal TINYINT(1) NOT NULL DEFAULT 0,
            {$child_consent_legacy}created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY parent_id (parent_id)
        ) $charset_collate;

CREATE TABLE $t_cy (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id BIGINT UNSIGNED NOT NULL,
            school_year_id BIGINT UNSIGNED NULL,
            classe VARCHAR(100) NULL,
            statut VARCHAR(20) NOT NULL DEFAULT 'inscrit',
            sorti_le DATETIME NULL,
            date_inscription DATETIME NULL,
            reglement_accepted_at DATETIME NULL,
            reglement_version_id BIGINT UNSIGNED NULL,
            assurance_file_path VARCHAR(255) NULL,
            assurance_original_filename VARCHAR(191) NULL,
            assurance_uploaded_at DATETIME NULL,
            assurance_status VARCHAR(20) NOT NULL DEFAULT 'approved',
            assurance_revision VARCHAR(36) NOT NULL DEFAULT '',
            assurance_reviewed_at DATETIME NULL,
            assurance_reviewed_by BIGINT UNSIGNED NULL,
            assurance_review_note TEXT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY child_year (child_id, school_year_id),
            KEY school_year_id (school_year_id)
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
            reglement_version_id BIGINT UNSIGNED NULL,
            payment_mode VARCHAR(20) NOT NULL DEFAULT 'autre',
            sepa_reglement_accepted_at DATETIME NULL,
            sepa_reglement_version_id BIGINT UNSIGNED NULL,
            sepa_iban VARCHAR(255) NULL,
            sepa_bic VARCHAR(11) NULL,
            sepa_titulaire VARCHAR(191) NULL,
            sepa_adresse VARCHAR(255) NULL,
            sepa_code_postal VARCHAR(10) NULL,
            sepa_ville VARCHAR(100) NULL,
            second_parent_prenom VARCHAR(191) NULL,
            second_parent_nom VARCHAR(191) NULL,
            second_parent_email VARCHAR(191) NULL,
            second_parent_telephone VARCHAR(40) NULL,
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
            version INT UNSIGNED NOT NULL DEFAULT 1,
            lines_json LONGTEXT NULL,
            payment_received_at DATETIME NULL,
            pdf_path VARCHAR(500) NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY parent_mois (parent_id, mois),
            KEY mois (mois),
            KEY parent_id (parent_id)
        ) $charset_collate;

CREATE TABLE $t_invv (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NOT NULL,
            mois CHAR(7) NOT NULL,
            version INT UNSIGNED NOT NULL,
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            lines_json LONGTEXT NULL,
            pdf_path VARCHAR(500) NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            archived_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY invoice_version (invoice_id, version),
            KEY parent_mois (parent_id, mois)
        ) $charset_collate;

CREATE TABLE $t_menu (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            semaine_debut DATE NOT NULL,
            lundi TEXT NULL,
            mardi TEXT NULL,
            jeudi TEXT NULL,
            vendredi TEXT NULL,
            origine_viande TEXT NULL,
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
            sent_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY semaine (semaine_debut)
        ) $charset_collate;

CREATE TABLE $t_env (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            objet_type VARCHAR(30) NOT NULL,
            objet_id BIGINT UNSIGNED NOT NULL,
            lot VARCHAR(40) NOT NULL,
            famille_id BIGINT UNSIGNED NULL,
            cle VARCHAR(191) NOT NULL,
            statut VARCHAR(20) NOT NULL DEFAULT 'a_envoyer',
            tentatives SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            erreur VARCHAR(191) NULL,
            accepte_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY cle (cle),
            KEY objet_statut (objet_type, objet_id, statut),
            KEY objet_lot (objet_type, objet_id, lot),
            KEY famille_id (famille_id)
        ) $charset_collate;

CREATE TABLE $t_pickup (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id BIGINT UNSIGNED NOT NULL,
            nom VARCHAR(191) NOT NULL,
            prenom VARCHAR(191) NOT NULL,
            lien VARCHAR(100) NULL,
            telephone VARCHAR(40) NOT NULL,
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
            present TINYINT(1) NULL DEFAULT NULL,
            arrival_time TIME NULL,
            departure_time TIME NULL,
            pointed_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY child_date_service (child_id, jour_date, service),
            KEY jour_date (jour_date)
        ) $charset_collate;

CREATE TABLE $t_svc (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            jour_date DATE NOT NULL,
            service VARCHAR(10) NOT NULL,
            label VARCHAR(191) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY jour_date_service (jour_date, service)
        ) $charset_collate;

CREATE TABLE $t_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            titre VARCHAR(160) NOT NULL,
            corps LONGTEXT NOT NULL,
            categorie VARCHAR(32) NOT NULL DEFAULT 'information',
            statut VARCHAR(16) NOT NULL DEFAULT 'brouillon',
            cible_type VARCHAR(16) NOT NULL DEFAULT 'all',
            cible_valeur TEXT NULL,
            canaux TEXT NULL,
            piece_jointe_id BIGINT UNSIGNED NULL,
            epingle TINYINT(1) NOT NULL DEFAULT 0,
            accuse_requis TINYINT(1) NOT NULL DEFAULT 0,
            reponses_autorisees TINYINT(1) NOT NULL DEFAULT 0,
            date_envoi_prevue DATETIME NULL,
            date_envoi DATETIME NULL,
            auteur_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY statut (statut),
            KEY date_envoi (date_envoi)
        ) $charset_collate;

CREATE TABLE $t_message_destinataires (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            family_id BIGINT UNSIGNED NOT NULL,
            email_statut VARCHAR(16) NOT NULL DEFAULT 'non_envoye',
            email_erreur VARCHAR(255) NULL,
            vu_le DATETIME NULL,
            vu_canal VARCHAR(16) NULL,
            accuse_le DATETIME NULL,
            token CHAR(32) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY msg_fam (message_id, family_id),
            KEY vu_le (vu_le),
            KEY token (token)
        ) $charset_collate;

CREATE TABLE $t_impersonations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            family_id BIGINT UNSIGNED NOT NULL,
            motif_type VARCHAR(32) NOT NULL,
            motif_detail VARCHAR(255) NULL,
            started_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            ended_reason VARCHAR(16) NULL,
            ip VARCHAR(45) NULL,
            PRIMARY KEY  (id),
            KEY family_started (family_id, started_at),
            KEY user_ended (wp_user_id, ended_at)
        ) $charset_collate;

CREATE TABLE $t_conversations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NULL,
            sujet VARCHAR(160) NOT NULL,
            objet VARCHAR(20) NULL,
            enfant_id BIGINT UNSIGNED NULL,
            statut VARCHAR(16) NOT NULL DEFAULT 'ouverte',
            initiee_par VARCHAR(16) NOT NULL,
            dernier_message_at DATETIME NOT NULL,
            dernier_auteur VARCHAR(16) NOT NULL,
            famille_dernier_lu_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            mairie_dernier_lu_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            famille_notifie_le DATETIME NULL,
            mairie_notifie_le DATETIME NULL,
            close_le DATETIME NULL,
            close_par BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY fam_msg (family_id, message_id),
            KEY family_date (family_id, dernier_message_at),
            KEY statut_date (statut, dernier_message_at)
        ) $charset_collate;

CREATE TABLE $t_conv_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            auteur_type VARCHAR(16) NOT NULL,
            auteur_user_id BIGINT UNSIGNED NULL,
            corps TEXT NOT NULL,
            piece_jointe_path VARCHAR(255) NULL,
            piece_jointe_nom VARCHAR(191) NULL,
            piece_jointe_taille BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY conv_id (conversation_id, id)
        ) $charset_collate;

CREATE TABLE $t_audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            horodatage DATETIME NOT NULL,
            requete_id CHAR(13) NOT NULL,
            acteur_type VARCHAR(16) NOT NULL,
            acteur_id BIGINT UNSIGNED NULL,
            acteur_libelle VARCHAR(191) NOT NULL,
            pour_le_compte_de BIGINT UNSIGNED NULL,
            action VARCHAR(64) NOT NULL,
            categorie VARCHAR(24) NOT NULL,
            resultat VARCHAR(16) NOT NULL,
            objet_type VARCHAR(32) NULL,
            objet_id BIGINT UNSIGNED NULL,
            famille_id BIGINT UNSIGNED NULL,
            enfant_id BIGINT UNSIGNED NULL,
            resume VARCHAR(255) NOT NULL,
            details TEXT NULL,
            ip VARCHAR(45) NULL,
            canal VARCHAR(16) NOT NULL,
            empreinte CHAR(64) NULL,
            empreinte_contenu CHAR(64) NULL,
            purgee_le DATETIME NULL,
            PRIMARY KEY  (id),
            KEY horodatage (horodatage),
            KEY famille_horodatage (famille_id, horodatage),
            KEY enfant_horodatage (enfant_id, horodatage),
            KEY acteur (acteur_type, acteur_id, horodatage),
            KEY action_horodatage (action, horodatage),
            KEY categorie_horodatage (categorie, horodatage),
            KEY requete_id (requete_id)
        ) $charset_collate;

CREATE TABLE $t_tarifs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(8) NOT NULL,
            prix_centimes INT UNSIGNED NOT NULL,
            debut DATE NOT NULL,
            fin DATE NULL,
            created_at DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code_debut (code, debut)
        ) $charset_collate;

CREATE TABLE $t_doc_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(24) NOT NULL,
            empreinte CHAR(64) NOT NULL,
            texte LONGTEXT NOT NULL,
            pdf_sha256 CHAR(64) NULL,
            pdf_fichier VARCHAR(255) NULL,
            pdf_nom VARCHAR(191) NULL,
            cree_le DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY type_empreinte (type, empreinte)
        ) $charset_collate;

CREATE TABLE $t_login_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(191) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            motif VARCHAR(24) NOT NULL,
            cible VARCHAR(64) NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY parent_expires (parent_id, expires_at),
            KEY expires_at (expires_at)
        ) $charset_collate;

CREATE TABLE $t_sans_repas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id BIGINT UNSIGNED NOT NULL,
            debut DATE NOT NULL,
            fin DATE NULL,
            created_at DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY  (id),
            KEY child_debut (child_id, debut)
        ) $charset_collate;";

        return $sql;
    }
}
