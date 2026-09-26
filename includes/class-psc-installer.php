<?php
if (!defined('ABSPATH')) exit;

class Psc_Installer {

    const DB_VERSION = '4.16.0';
    const ROLES_VERSION = '1.5.0';

    public static function activate() {
        self::create_tables();
        if (!self::remove_pickup_identity_data()) return;
        self::store_constraints_state();
        update_option('psc_db_version', self::DB_VERSION);
        self::sync_roles();
        update_option('psc_roles_version', self::ROLES_VERSION);
        // Configuration du planning de l'année en cours (dates, fériés) :
        // une installation neuve doit pouvoir afficher son planning sans
        // attendre une intervention de la mairie.
        Psc_School_Year::ensure_default();
    }

    /**
     * Répartition des capacités par défaut. Un administrateur WordPress n'a
     * PAS automatiquement les capacités personnalisées d'une extension :
     * sans cet ajout explicite, personne n'aurait accès au backoffice
     * périscolaire, puisque la capacité vérifiée n'est pas manage_options.
     *
     * Les rôles de psc_manage_default_roles() (administrateur seul par
     * défaut) reçoivent la capacité globale, toutes les capacités métier et
     * la consultation d'un espace famille. Le rôle éditeur, qui les
     * recevait avant la 1.5.0 des rôles, les perd : éditer le contenu du
     * site ne donne aucun droit sur les dossiers d'enfants. Un agent de la
     * mairie reçoit ses habilitations une à une sur son profil
     * (Psc_Admin::user_capabilities_fields()). Un site qui tient à
     * l'ancien comportement remet 'editor' par le filtre.
     */
    protected static function sync_roles() {
        $grant = array_merge(
            array(psc_manage_cap(), 'psc_impersonate_family'),
            psc_domain_capability_keys()
        );
        $default_roles = psc_manage_default_roles();
        foreach ($default_roles as $role_name) {
            $role = get_role($role_name);
            if (!$role) continue;
            foreach ($grant as $cap) {
                if (!$role->has_cap($cap)) $role->add_cap($cap);
            }
        }
        if (!in_array('editor', $default_roles, true)) {
            $editor = get_role('editor');
            if ($editor) {
                foreach ($grant as $cap) $editor->remove_cap($cap);
            }
        }
    }

    /**
     * Étapes de migration, dans l'ordre. Chacune est idempotente et
     * n'est franchie (psc_db_version portée à son numéro) qu'une fois
     * terminée sans erreur SQL : une montée interrompue — processus tué,
     * ALTER refusé, délai dépassé — reprend à l'étape en échec, sans
     * rejouer celles déjà franchies.
     */
    const STEPS = array(
        '2.5.0'  => 'migrate_2_5_0',
        '2.7.0'  => 'migrate_2_7_0',
        '2.8.0'  => 'migrate_2_8_0',
        '2.9.0'  => 'migrate_2_9_0',
        '2.10.0' => 'migrate_2_10_0',
        '3.0.0'  => 'migrate_3_0_0',
        '3.7.0'  => 'migrate_3_7_0',
        '3.8.0'  => 'migrate_3_8_0',
        '4.0.0'  => 'migrate_4_0_0',
        '4.14.0' => 'migrate_4_14_0',
        '4.15.0' => 'migrate_4_15_0',
    );

    /** Un verrou plus ancien est réputé abandonné (processus tué). */
    const LOCK_TTL = 600;

    /** Délai entre deux reprises d'une montée en échec, hors backoffice. */
    const RETRY_DELAY = 300;

    /**
     * Vérifie à chaque chargement si le schéma doit être mis à jour.
     * Évite les erreurs après une mise à jour du plugin par simple copie
     * de fichiers (cas fréquent : le hook d'activation n'est pas rejoué).
     */
    public static function maybe_upgrade() {
        // Chemin courant, sans écriture : rien à migrer ni à déménager. Le
        // répertoire privé doit tout de même exister (et porter ses
        // garde-fous) à chaque chargement, même si un administrateur l'a
        // supprimé à la main ou si l'hébergeur a réinitialisé le disque.
        if (!self::has_pending_work()) {
            psc_ensure_private_dir();
            return;
        }

        // Verrou inter-processus : deux requêtes simultanées (ou cron +
        // admin) ne doivent pas déplacer les mêmes fichiers ni exécuter les
        // DDL en concurrence.
        $lock = self::acquire_lock();
        if (!$lock) return;

        try {
            self::run_upgrade();
        } finally {
            self::release_lock($lock);
        }
    }

    protected static function has_pending_work() {
        return is_admin()
            || get_option('psc_db_version') !== self::DB_VERSION
            || get_option('psc_roles_version') !== self::ROLES_VERSION
            || get_option('psc_storage_move_failed')
            || (!psc_running_as_root() && (string) get_option('psc_private_dir_path', '') !== psc_private_dir())
            || is_dir(self::legacy_upload_dir());
    }

    protected static function run_upgrade() {
        $roles_current = get_option('psc_roles_version');
        if ($roles_current !== self::ROLES_VERSION) {
            self::sync_roles();
            update_option('psc_roles_version', self::ROLES_VERSION);
        }

        $current = get_option('psc_db_version');
        if ($current !== self::DB_VERSION && self::may_retry_migration()) {
            self::run_migrations($current);
        }

        psc_ensure_private_dir();
        self::sync_private_dir();
        self::sync_legacy_uploads();

        // Hors bloc de version également : les contraintes sont idempotentes
        // et bon marché quand tout est en place (trois SELECT). Un ALTER
        // refusé par l'hébergeur est donc retenté à chaque écran admin —
        // plus besoin d'attendre la prochaine montée de version pour que la
        // pose se rejoue — et l'état publié alimente l'alerte admin
        // (Psc_Admin::notice_db_constraints). Hors admin : la mairie
        // n'écrit pas, et le coût des requêtes sur information_schema ne
        // doit pas peser sur le portail public.
        if (is_admin()) {
            self::store_constraints_state();
        }
    }

    /**
     * Une montée en échec est retentée à chaque écran d'administration,
     * mais au plus toutes les RETRY_DELAY secondes côté public : des DDL
     * rejoués à chaque visite de famille pèseraient sur tout le site.
     */
    protected static function may_retry_migration() {
        $failed = get_option('psc_migration_failed');
        return !is_array($failed) || is_admin() || (time() - (int) ($failed['ts'] ?? 0)) >= self::RETRY_DELAY;
    }

    protected static function run_migrations($current) {
        // dbDelta() est additif (ajoute tables/colonnes manquantes,
        // ne supprime jamais) : l'exécuter en premier garantit que les
        // migrations ci-dessous trouvent les tables dont elles ont
        // besoin (ex : migrate_2_10_0 a besoin de wp_psc_school_calendar).
        if (!self::run_step('create_tables')) {
            self::record_migration_failure($current, 'schema');
            return false;
        }

        foreach (self::STEPS as $version => $method) {
            if (!$current || version_compare($current, $version, '>=')) continue;
            if (!self::run_step($method)) {
                self::record_migration_failure($current, $version);
                return false;
            }
            // Étape franchie : une interruption ne la rejouera pas. La
            // dernière n'est retenue qu'avec la passe finale ci-dessous :
            // portée seule à DB_VERSION, une passe finale en échec ne
            // serait plus jamais retentée.
            if ($version !== self::DB_VERSION) update_option('psc_db_version', $version);
        }

        // Deuxième passe dbDelta, après les migrations. Celles-ci
        // suppriment des colonnes et des indices (invoices.mois,
        // children.classe…) que la définition finale réintroduit ou
        // conserve : sur une montée de version « par bonds » (2.4 →
        // 3.9 sans passer par les releases intermédiaires, le cas
        // réel d'une mise à jour par copie de fichiers), personne
        // d'autre ne les recrée — la première passe, avant les
        // migrations, aligne sur la définition finale AVANT que les
        // migrations ne suppriment quoi que ce soit. Sans cette
        // seconde passe, le bond aboutit à un schéma que la montée
        // pas à pas, elle, ne produit pas (bin/verify-migrations.php
        // verrouille ce cas en intégration continue).
        // La passe finale vise toujours le schéma cible : les colonnes
        // héritées ne servaient qu'aux migrations, désormais faites.
        self::$final_schema = true;
        $final_ok = self::run_step('create_tables');
        self::$final_schema = false;
        if (!$final_ok || !self::run_step('remove_pickup_identity_data') || !self::run_step('ensure_audit_chain_v2')) {
            self::record_migration_failure($current, 'schema');
            return false;
        }

        update_option('psc_db_version', self::DB_VERSION);
        delete_option('psc_migration_failed');

        if (class_exists('Psc_Audit')) {
            Psc_Audit::log('systeme.montee_de_version', array(
            'objet_type' => 'reglage',
            'meta' => array('ancienne_version' => $current ?: null, 'nouvelle_version' => self::DB_VERSION),
            'resume' => sprintf(__('Schéma de la base mis à jour (%s → %s).', 'periscolaire-registration'), $current ?: '—', self::DB_VERSION),
            'acteur' => array('type' => 'systeme', 'id' => null, 'libelle' => 'mise-a-jour-plugin', 'pour_le_compte_de' => null),
            ));
        }
        return true;
    }

    /**
     * Exécute une étape et juge sa réussite sur les erreurs SQL qu'elle a
     * réellement produites : les migrations enchaînent des requêtes dont
     * elles ne testent pas toutes le retour. $EZSQL_ERROR reçoit chaque
     * erreur de wpdb, même quand leur affichage est supprimé. Les DESCRIBE
     * de dbDelta() sur une table encore absente sont des sondes attendues,
     * pas des échecs.
     */
    protected static function run_step($method) {
        global $EZSQL_ERROR;
        self::$step_reason = '';
        $mark = count((array) $EZSQL_ERROR);
        $result = call_user_func(array(__CLASS__, $method));
        $errors = array_filter(array_slice((array) $EZSQL_ERROR, $mark), function ($e) {
            return stripos(ltrim((string) ($e['query'] ?? '')), 'DESCRIBE ') !== 0;
        });
        self::$last_step_errors = array_values($errors);
        return false !== $result && !$errors;
    }

    /** @var array Erreurs SQL de la dernière étape exécutée. */
    protected static $last_step_errors = array();

    /** @var bool Passe finale de dbDelta : schéma cible, sans colonne héritée. */
    protected static $final_schema = false;

    /** @var string Raison métier d'un refus d'étape, sans donnée personnelle. */
    protected static $step_reason = '';

    /**
     * Mémorise l'étape en échec pour l'alerte d'administration. Seuls la
     * nature de la requête et sa table sont retenus : une requête ou un
     * message d'erreur MySQL peuvent citer des valeurs (adresse, nom).
     */
    protected static function record_migration_failure($from, $step) {
        $first = self::$last_step_errors[0]['query'] ?? '';
        $kind = preg_match('/^\s*(\w+)/', $first, $m) ? strtoupper($m[1]) : '';
        $table = preg_match('/\b(\w*psc_\w+)/', $first, $t) ? $t[1] : '';
        $previous = get_option('psc_migration_failed');
        update_option('psc_migration_failed', array(
            'etape'   => $step,
            'depuis'  => (string) $from,
            'requete' => self::$step_reason !== '' ? self::$step_reason : trim($kind . ' ' . $table),
            'erreurs' => count(self::$last_step_errors),
            'ts'      => time(),
        ), false);

        // Journalisée une fois par étape en échec, pas à chaque reprise.
        if (class_exists('Psc_Audit') && (!is_array($previous) || ($previous['etape'] ?? '') !== $step)) {
            Psc_Audit::log('systeme.montee_de_version_echec', array(
                'objet_type' => 'reglage',
                'meta'       => array('etape' => $step, 'depuis' => (string) $from, 'requete' => trim($kind . ' ' . $table)),
                'resume'     => sprintf(__('Mise à jour du schéma arrêtée à l’étape %s ; elle reprendra à cette étape.', 'periscolaire-registration'), $step),
                'acteur'     => array('type' => 'systeme', 'id' => null, 'libelle' => 'mise-a-jour-plugin', 'pour_le_compte_de' => null),
            ));
        }
    }

    /**
     * Verrou atomique : INSERT IGNORE sur la clé unique option_name, puis
     * reprise d'un verrou abandonné par UPDATE conditionnel. Deux
     * processus ne peuvent pas le prendre ensemble (add_option() n'offre
     * pas cette garantie : il écrit en « ON DUPLICATE KEY UPDATE »).
     *
     * @return string|false Jeton à rendre à release_lock(), ou false.
     */
    public static function acquire_lock() {
        global $wpdb;
        $token = sprintf('%.6F', microtime(true));
        $taken = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('psc_migration_lock', %s, 'no')",
            $token
        ));
        if (!$taken) {
            $taken = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s
                 WHERE option_name = 'psc_migration_lock' AND CAST(option_value AS DECIMAL(20,6)) < %f",
                $token, microtime(true) - self::LOCK_TTL
            ));
        }
        wp_cache_delete('psc_migration_lock', 'options');
        return $taken ? $token : false;
    }

    /** Ne rend que son propre verrou, jamais celui qui l'aurait repris. */
    public static function release_lock($token) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = 'psc_migration_lock' AND option_value = %s",
            $token
        ));
        wp_cache_delete('psc_migration_lock', 'options');
    }

    /**
     * Suit les documents lorsque l'emplacement du répertoire privé change.
     *
     * Le déclencheur n'est pas une version de base mais le chemin lui-même :
     * déclarer PSC_PRIVATE_DIR dans wp-config.php (le seul correctif possible
     * quand l'hébergement mutualisé ignore les .htaccess) modifie la
     * destination sans rien changer au numéro de version, donc aucune
     * migration classique ne se rejouerait. On mémorise le dernier chemin
     * utilisé et on déménage dès qu'il diffère.
     *
     * Sans cela, les fichiers déjà déposés resteraient à l'ancien
     * emplacement — c'est-à-dire exposés — pendant que le code n'écrirait
     * plus que dans le nouveau : la correction n'aurait protégé que les
     * dépôts à venir.
     *
     * Le nouveau chemin n'est retenu qu'une fois le déménagement complet :
     * un fichier en conflit ou non déplaçable laisse l'ancien chemin en
     * mémoire, donc une nouvelle tentative au chargement suivant, et
     * l'alerte d'administration le signale.
     */
    private static function sync_private_dir() {
        // Le déménagement revient au serveur web : les fichiers déplacés par
        // root lui appartiendraient, et il ne pourrait plus les remplacer.
        if (psc_running_as_root()) {
            return;
        }

        $current = psc_private_dir();
        $known   = (string) get_option('psc_private_dir_path', '');

        if ($known === $current) {
            self::storage_move_result('private_dir', true);
            return;
        }

        // Premier enregistrement, ou ancien dossier disparu : rien à déplacer.
        if ($known !== '' && is_dir($known) && is_dir($current)) {
            $moved = self::move_tree($known, $current, self::GUARD_FILES);
            self::storage_move_result('private_dir', $moved, $known, $current);
            if (!$moved) return;
        }

        if (is_dir($current)) {
            update_option('psc_private_dir_path', $current, false);
        }
    }

    /**
     * Garde-fous posés par psc_ensure_private_dir() ou migrate_3_7_0() à la
     * racine d'un dossier de documents. Propres à chaque emplacement (le
     * témoin est aléatoire) : jamais déplacés, retirés de la source
     * seulement quand tout le reste en est sorti.
     */
    const GUARD_FILES = array('.htaccess', 'web.config', 'index.php', 'psc-probe.txt');

    /**
     * Mémorise l'issue d'un déménagement de documents pour l'alerte
     * d'administration (Psc_Admin::notice_storage_move_failed).
     */
    protected static function storage_move_result($source, $ok, $from = '', $to = '') {
        $failed = get_option('psc_storage_move_failed');
        $failed = is_array($failed) ? $failed : array();
        if ($ok) {
            if (!isset($failed[$source])) return;
            unset($failed[$source]);
        } else {
            $failed[$source] = array('depuis' => $from, 'vers' => $to, 'restants' => self::count_files($from), 'ts' => time());
        }
        if ($failed) {
            update_option('psc_storage_move_failed', $failed, false);
        } else {
            delete_option('psc_storage_move_failed');
        }
    }

    protected static function count_files($dir) {
        if (!is_dir($dir)) return 0;
        $n = 0;
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = trailingslashit($dir) . $entry;
            $n += is_dir($path) ? self::count_files($path) : (in_array($entry, self::GUARD_FILES, true) && dirname($path) === rtrim($dir, '/') ? 0 : 1);
        }
        return $n;
    }

    protected static function legacy_upload_dir() {
        $upload = wp_upload_dir(null, false);
        return trailingslashit($upload['basedir']) . 'periscolaire';
    }

    /**
     * Reprend à chaque chargement le déménagement de uploads/periscolaire
     * (migration 3.7.0) tant que des fichiers y restent : l'étape de schéma
     * est franchie, mais des documents exposés ne doivent pas y être
     * oubliés.
     */
    private static function sync_legacy_uploads() {
        if (psc_running_as_root() || !is_dir(self::legacy_upload_dir())) {
            self::storage_move_result('uploads', true);
            return;
        }
        self::migrate_3_7_0();
    }

    /**
     * Sort les documents des familles de wp-content/uploads/.
     *
     * Ces fichiers (justificatifs d'assurance nominatifs concernant des
     * mineurs, factures) étaient écrits sous uploads/periscolaire/, servi
     * publiquement par le serveur web, sous des noms séquentiels devinables
     * (child-12.pdf, facture-7.pdf) : ils étaient donc téléchargeables sans
     * aucune authentification par simple énumération d'URL.
     *
     * Les chemins enregistrés en base sont relatifs ("periscolaire/…") : les
     * déplacer en bloc sous psc_private_dir() suffit, aucune écriture SQL
     * n'est nécessaire. L'ancien dossier est neutralisé s'il subsiste.
     */
    private static function migrate_3_7_0() {
        if (!psc_ensure_private_dir()) {
            return false;
        }

        $legacy = self::legacy_upload_dir();
        $target = psc_private_path('periscolaire');

        if (!is_dir($legacy)) {
            return true;
        }

        // rename() est atomique tant qu'on ne franchit pas de périphérique ;
        // sinon on recopie fichier par fichier avant de purger la source.
        if (!is_dir($target) && @rename($legacy, $target)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            self::storage_move_result('uploads', true);
            return true;
        }

        // Si des fichiers résistent au déplacement (permissions, conflit),
        // au moins interdire leur accès direct là où ils sont restés — le
        // garde-fou est posé AVANT le déplacement, et move_tree() ne le
        // retire qu'une fois le dossier vidé.
        $guard = trailingslashit($legacy) . '.htaccess';
        if (!file_exists($guard)) {
            @file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
                $guard,
                "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
            );
        }

        // L'étape de schéma reste franchie même si des fichiers résistent :
        // bloquer toute montée de version pour un fichier en conflit
        // laisserait le code courant face à un schéma ancien. La reprise
        // passe par sync_legacy_uploads(), à chaque chargement.
        $moved = self::move_tree($legacy, $target, self::GUARD_FILES);
        self::storage_move_result('uploads', $moved, $legacy, $target);
        return true;
    }

    /**
     * Chiffre au repos les IBAN déjà enregistrés, et purge ceux des demandes
     * déjà traitées.
     *
     * Un IBAN en clair en base est directement exploitable par quiconque
     * obtient une copie de celle-ci (sauvegarde égarée, export SQL, lecture
     * via une autre vulnérabilité). psc_encrypt() est idempotent : relancer
     * la migration ne double jamais le chiffrement.
     *
     * Les demandes déjà approuvées ou rejetées n'ont plus besoin de l'IBAN
     * — il a été reporté sur le compte famille à l'approbation.
     */
    private static function migrate_3_8_0() {
        global $wpdb;
        $t_parent = psc_table('parents');
        $t_req    = psc_table('requests');

        foreach (array($t_parent, $t_req) as $table) {
            $rows = $wpdb->get_results(
                "SELECT id, sepa_iban FROM $table WHERE sepa_iban IS NOT NULL AND sepa_iban <> ''"
            );
            foreach ($rows as $row) {
                if (strpos((string) $row->sepa_iban, 'psc1:') === 0) continue; // déjà chiffré
                // Chiffrement impossible (primitives indisponibles) : on
                // laisse la ligne EN L'ÉTAT — la donnée existait déjà en
                // clair, la migration ne doit ni l'écraser avec un
                // WP_Error ni la faire disparaître. Relancer la migration
                // (prochain chargement) réessaiera.
                $enc = psc_encrypt($row->sepa_iban);
                if (is_wp_error($enc)) continue;
                $wpdb->update(
                    $table,
                    array('sepa_iban' => $enc),
                    array('id' => $row->id),
                    array('%s'),
                    array('%d')
                );
            }
        }

        $wpdb->query(
            "UPDATE $t_req SET sepa_iban = NULL, sepa_bic = NULL
             WHERE status IN ('approved','rejected')"
        );
    }

    /**
     * Passage au modèle « année scolaire + rythme & exceptions » (v4.0) :
     *
     *  - nouvelles tables psc_school_year (configuration administrable :
     *    dates, plages de vacances JSON, verrou), psc_holidays (jours fériés
     *    à exclure, pré-remplis), psc_pattern (rythme habituel, ≤ 16 lignes
     *    par enfant et par année) et psc_exception (écarts ponctuels,
     *    value true = ajout, false = retrait) ;
     *  - les jours d'école sont CALCULÉS (lundi, mardi, jeudi, vendredi,
     *    moins vacances et fériés) : la table calendar_days — une ligne par
     *    jour ET par trimestre — n'a plus d'usage ;
     *  - colonne children.food_allergies (TEXT, nullable) : champ libre
     *    strictement alimentaire, migration à NULL pour l'existant ;
     *  - migration idempotente des inscriptions historiques vers
     *    psc_pattern + psc_exception (Psc_Planning::migrate_from_registrations,
     *    seuil ≥ 60 %) ;
     *  - l'ancienne table wp_psc_registrations est conservée en lecture
     *    seule le temps d'un cycle de facturation (aucune écriture n'y
     *    passe plus) ; la vérification bloquante est
     *    Psc_Planning::verify_against_registrations() via
     *    bin/verify-planning-migration.php.
     *
     * Les tables trimestres / calendar_days sont DÉTACHÉES mais conservées :
     * elles portent l'historique des inscriptions et la FK
     * registrations.trimestre_id empêche leur suppression tant que l'ancienne
     * table existe. Un nettoyage (DROP des trois tables) pourra être proposé
     * après un cycle de facturation sans anomalies.
     */
    /**
     * 4.14.0 — une commande fournisseur est enregistrée AVANT son envoi
     * (cf. Psc_Supplier_Orders::send()) : sent_at reste NULL tant que le
     * mail n'est pas accepté. dbDelta() ne sait pas relâcher un NOT NULL,
     * d'où cet ALTER explicite, idempotent.
     */
    private static function migrate_4_14_0() {
        global $wpdb;
        $t = psc_table('supplier_orders');
        if (!self::table_exists($t)) return;
        $nullable = $wpdb->get_var($wpdb->prepare(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'sent_at'",
            $t
        ));
        if ($nullable === 'NO') {
            $wpdb->query("ALTER TABLE $t MODIFY sent_at DATETIME NULL");
        }
    }

    /**
     * 4.15.0 — une seule table d'année scolaire, statut de l'enfant par année.
     *
     * - school_years reçoit la clé d'année ('2026-2027', unique, déduite
     *   du libellé s'il la respecte, sinon de la date de rentrée) et la
     *   configuration du calendrier de l'ancienne table school_year, dont
     *   les dates l'emportent : ce sont elles qui ont servi au planning,
     *   donc à la facturation ;
     * - toute année citée par le planning (rythmes, fériés) sans ligne
     *   reçoit la sienne, pour que les clés étrangères se posent sans rien
     *   supprimer ;
     * - le statut global de l'enfant passe sur ses lignes d'année : un
     *   enfant actif est inscrit à l'année active, un enfant sorti l'est
     *   sur sa dernière année, avec sa date de sortie (délai de
     *   conservation RGPD) ;
     * - puis le libellé libre, le statut global et l'ancienne table
     *   disparaissent.
     *
     * Idempotente : chaque partie teste l'état avant d'écrire. Renvoie
     * false (étape non franchie, cf. run_step()) si deux années porteuses
     * d'inscriptions tombent sur la même clé : c'est une décision humaine.
     */
    /**
     * Chaînage v2 du journal d'audit (4.16.0, P1-11) : l'empreinte du
     * contenu de chaque ligne est gardée à part, pour qu'une ligne expirée
     * puisse être vidée par la purge sans casser la chaîne.
     *
     *  1. Seuil : premier id chaîné en v2 = id suivant la dernière ligne ;
     *     les lignes antérieures gardent leur chaînage v1, jamais réécrit.
     *  2. Empreinte de contenu des lignes v1, calculée en SQL par lots —
     *     même formule que psc_audit_content_hash().
     *
     * Idempotente : le seuil n'est posé qu'une fois, le calcul ne vise que
     * les lignes antérieures qui n'ont pas encore d'empreinte de contenu.
     */
    private static function ensure_audit_chain_v2() {
        global $wpdb;
        $table = psc_table('audit_log');
        $seuil = (int) get_option('psc_audit_chain_v2_from', 0);
        if ($seuil <= 0) {
            $seuil = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) + 1 FROM $table");
            if ($wpdb->last_error) return false;
            update_option('psc_audit_chain_v2_from', $seuil, false);
        }
        do {
            $done = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET empreinte_contenu = SHA2(CONCAT(
                    DATE_FORMAT(horodatage, '%%Y-%%m-%%d %%H:%%i:%%s'), '|', action, '|',
                    acteur_type, ':', COALESCE(acteur_id, 0), '|',
                    COALESCE(objet_type, ''), ':', COALESCE(objet_id, 0), '|', resume), 256)
                 WHERE id < %d AND empreinte_contenu IS NULL AND purgee_le IS NULL
                 LIMIT 5000",
                $seuil
            ));
            if ($done === false) return false;
        } while ($done === 5000);
        return true;
    }

    private static function migrate_4_15_0() {
        global $wpdb;
        $t_years = psc_table('school_years');
        $t_sy    = psc_table('school_year');
        $t_cy    = psc_table('child_school_years');
        $t_child = psc_table('children');
        $now     = current_time('mysql');
        $today   = current_time('Y-m-d');
        $status_for = function ($date_fin) use ($today) {
            return $date_fin < $today ? 'archivee' : 'preparation';
        };

        // 1. Clé d'année des lignes existantes.
        $has_label = self::column_exists($t_years, 'label');
        foreach ((array) $wpdb->get_results("SELECT * FROM $t_years WHERE year_key IS NULL OR year_key = ''") as $row) {
            $key = $has_label ? Psc_School_Year::sanitize_key((string) $row->label) : '';
            if ($key === '') $key = Psc_School_Years::key_for_start($row->date_debut);
            if (false === $wpdb->update($t_years, array('year_key' => $key), array('id' => (int) $row->id))) return false;
        }

        // 2. Deux lignes pour une même clé : on garde l'active, sinon celle
        //    qui porte des inscriptions, sinon la plus récente ; une ligne
        //    retirée cède sa configuration si la gardée n'en a pas.
        $dupes = $wpdb->get_col("SELECT year_key FROM $t_years GROUP BY year_key HAVING COUNT(*) > 1");
        foreach ((array) $dupes as $key) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT y.*, (SELECT COUNT(*) FROM $t_cy cy WHERE cy.school_year_id = y.id) AS inscriptions
                 FROM $t_years y WHERE y.year_key = %s
                 ORDER BY (y.statut = 'active') DESC, inscriptions DESC, y.id DESC",
                $key
            ));
            $keep = array_shift($rows);
            foreach ($rows as $other) {
                if ((int) $other->inscriptions > 0) {
                    self::$step_reason = sprintf(
                        /* translators: %s: clé d'année, ex. 2026-2027 */
                        __('plusieurs années %s ont des inscriptions : supprimez celle qui est en trop dans Année scolaire', 'periscolaire-registration'),
                        $key
                    );
                    return false;
                }
                $merge = array();
                if ($keep->vacation_ranges === null && $other->vacation_ranges !== null) $merge['vacation_ranges'] = $other->vacation_ranges;
                if ($keep->lock_hours === null && $other->lock_hours !== null) $merge['lock_hours'] = $other->lock_hours;
                if ($merge && false === $wpdb->update($t_years, $merge, array('id' => (int) $keep->id))) return false;
                if (false === $wpdb->delete($t_years, array('id' => (int) $other->id))) return false;
            }
        }

        // 3. Configuration du calendrier de l'ancienne table.
        if (self::table_exists($t_sy)) {
            foreach ((array) $wpdb->get_results("SELECT * FROM $t_sy") as $cfg) {
                $data = array(
                    'date_debut'      => $cfg->date_start,
                    'date_fin'        => $cfg->date_end,
                    'vacation_ranges' => $cfg->vacation_ranges,
                    'lock_hours'      => $cfg->lock_hours,
                    'updated_at'      => $now,
                );
                $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_years WHERE year_key = %s", $cfg->year_key));
                $ok = $id
                    ? $wpdb->update($t_years, $data, array('id' => (int) $id))
                    : $wpdb->insert($t_years, array_merge($data, array('year_key' => $cfg->year_key, 'statut' => $status_for($cfg->date_end), 'created_at' => $now)));
                if (false === $ok) return false;
            }
        }

        // 4. Années citées par le planning sans ligne.
        $t_hol = psc_table('holidays');
        $t_pat = psc_table('pattern');
        $cited = $wpdb->get_col(
            "SELECT year_key FROM $t_hol UNION SELECT school_year FROM $t_pat"
        );
        foreach ((array) $cited as $key) {
            $key = Psc_School_Year::sanitize_key((string) $key);
            if ($key === '' || $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_years WHERE year_key = %s", $key))) continue;
            $fin = sprintf('%d-07-06', (int) substr($key, 5, 4));
            if (false === $wpdb->insert($t_years, array(
                'year_key' => $key, 'date_debut' => sprintf('%d-09-01', (int) substr($key, 0, 4)), 'date_fin' => $fin,
                'statut' => $status_for($fin), 'created_at' => $now,
            ))) return false;
        }

        // 5. Statut de l'enfant, porté par ses lignes d'année.
        if (self::column_exists($t_child, 'statut')) {
            $active = (int) $wpdb->get_var("SELECT id FROM $t_years WHERE statut = 'active' ORDER BY id DESC LIMIT 1");
            if ($active && false === $wpdb->query($wpdb->prepare(
                "INSERT INTO $t_cy (child_id, school_year_id, statut, date_inscription)
                 SELECT c.id, %d, 'inscrit', c.created_at FROM $t_child c
                 WHERE c.statut = 'actif'
                   AND NOT EXISTS (SELECT 1 FROM $t_cy cy WHERE cy.child_id = c.id AND cy.school_year_id = %d)",
                $active, $active
            ))) return false;

            foreach ((array) $wpdb->get_results("SELECT id, sorti_le FROM $t_child WHERE statut = 'sorti'") as $c) {
                $sorti_le = $c->sorti_le ?: $now;
                $last = $wpdb->get_var($wpdb->prepare(
                    "SELECT cy.id FROM $t_cy cy INNER JOIN $t_years y ON y.id = cy.school_year_id
                     WHERE cy.child_id = %d ORDER BY y.date_debut DESC LIMIT 1",
                    (int) $c->id
                ));
                if ($last) {
                    $ok = $wpdb->update($t_cy, array('statut' => 'sorti', 'sorti_le' => $sorti_le), array('id' => (int) $last));
                } elseif ($active) {
                    $ok = $wpdb->insert($t_cy, array('child_id' => (int) $c->id, 'school_year_id' => $active, 'statut' => 'sorti', 'sorti_le' => $sorti_le, 'date_inscription' => $sorti_le));
                } else {
                    $ok = true;
                }
                if (false === $ok) return false;
            }
        }
        if (false === $wpdb->query("UPDATE $t_cy SET statut = 'inscrit' WHERE statut NOT IN ('inscrit', 'sorti')")) return false;

        // 6. Ce qui ne sert plus.
        if (self::column_exists($t_child, 'statut')) {
            if (self::index_exists($t_child, 'statut') && false === $wpdb->query("ALTER TABLE $t_child DROP INDEX statut")) return false;
            if (false === $wpdb->query("ALTER TABLE $t_child DROP COLUMN statut")) return false;
        }
        if (self::column_exists($t_child, 'sorti_le') && false === $wpdb->query("ALTER TABLE $t_child DROP COLUMN sorti_le")) return false;
        if ($has_label && false === $wpdb->query("ALTER TABLE $t_years DROP COLUMN label")) return false;
        if (false === $wpdb->query("ALTER TABLE $t_years MODIFY year_key VARCHAR(9) NOT NULL")) return false;
        if (!self::index_exists($t_years, 'year_key') && false === $wpdb->query("ALTER TABLE $t_years ADD UNIQUE KEY year_key (year_key)")) return false;
        if (self::table_exists($t_sy) && false === $wpdb->query("DROP TABLE $t_sy")) return false;

        Psc_School_Year::flush_cache();
        return true;
    }

    private static function column_exists($table, $column) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $table, $column
        ));
    }

    private static function index_exists($table, $index) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            $table, $index
        ));
    }

    private static function migrate_4_0_0() {
        global $wpdb;

        // 1) Colonne allergies alimentaires (nullable : migration à NULL
        //    pour l'existant — rien à backporter).
        $t_child = psc_table('children');
        $has_allergies = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$t_child}' AND COLUMN_NAME = 'food_allergies'"
        );
        if (!$has_allergies) {
            $wpdb->suppress_errors(true);
            $wpdb->query("ALTER TABLE {$t_child} ADD COLUMN food_allergies TEXT NULL");
            $wpdb->suppress_errors(false);
        }

        // 2) Configuration de l'année scolaire du planning (et fériés) :
        //    garantit au moins l'année courante avant la migration.
        Psc_School_Year::ensure_default();

        // 3) Migration des inscriptions historiques vers rythme + exceptions.
        Psc_Planning::migrate_from_registrations();

        // 4) L'année d'inscription (dossier) courante doit exister : les
        //    installations créées avant v3.0 en ont déjà une via
        //    migrate_3_0_0 ; les installations neuves passent par
        //    create_tables() + la même dérivation.
        $t_years = psc_table('school_years');
        if (!(int) $wpdb->get_var("SELECT COUNT(*) FROM {$t_years}")) {
            $y = psc_rentree_year();
            $wpdb->insert($t_years, array(
                'label'      => $y . '-' . ($y + 1),
                'date_debut' => sprintf('%d-09-01', $y),
                'date_fin'   => sprintf('%d-07-06', $y + 1),
                'statut'     => 'active',
                'created_at' => current_time('mysql'),
            ), array('%s', '%s', '%s', '%s', '%s'));
        }
    }

    /**
     * Liens entre tables, et ce que la base doit faire quand la ligne
     * référencée disparaît.
     *
     * L'action déclarée reproduit exactement ce que le code applicatif fait
     * déjà : la contrainte est un filet, pas une nouvelle règle métier.
     *
     * L'ordre compte : les enfants orphelins sont retirés avant les lignes
     * qui les référencent, pour que le nettoyage se propage de proche en
     * proche.
     */
    private static function foreign_key_map() {
        return array(
            array('children',           'parent_id',      'parents',      'CASCADE'),
            array('impersonations',      'family_id',      'parents',      'CASCADE'),
            array('conversations',      'family_id',      'parents',      'CASCADE'),
            array('conversations',      'message_id',     'messages',     'SET NULL'),
            array('conversations',      'enfant_id',      'children',     'SET NULL'),
            array('conversation_messages', 'conversation_id', 'conversations', 'CASCADE'),
            // Ancienne table conservée en lecture seule le temps d'un cycle
            // de facturation : la cascade enfant continue de purger son
            // historique à la suppression d'un enfant.
            array('registrations',      'child_id',       'children',     'CASCADE'),
            array('child_school_years', 'child_id',       'children',     'CASCADE'),
            array('pickup_persons',     'child_id',       'children',     'CASCADE'),
            array('pickup_history',     'child_id',       'children',     'CASCADE'),
            array('pattern',            'child_id',       'children',     'CASCADE'),
            array('exception',          'child_id',       'children',     'CASCADE'),
            array('child_school_years', 'school_year_id', 'school_years', 'CASCADE'),
            array('envois',             'famille_id',     'parents',      'CASCADE'),
            // Le planning désigne l'année par sa clé ('2026-2027') : un
            // rythme ou un férié ne peut citer qu'une année existante, et
            // suit sa clé si les dates de l'année changent de rentrée.
            array('holidays',           'year_key',       'school_years', 'CASCADE', 'year_key'),
            array('pattern',            'school_year',    'school_years', 'CASCADE', 'year_key'),
        );
    }

    /**
     * Re-constate l'état des contraintes et le publie pour l'alerte admin.
     *
     * Idempotent et sans effet de bord quand tout est en place. Un refus
     * d'ALTER n'y perd plus son silence : la liste des contraintes non
     * posées part dans l'option psc_constraints_missing, lue par
     * Psc_Admin::notice_db_constraints() — et, à chaque écran admin, la
     * pose est retentée.
     */
    private static function store_constraints_state() {
        update_option(
            'psc_constraints_missing',
            array_merge(
                self::ensure_foreign_keys(), self::ensure_service_constraint(), self::ensure_second_parent_email_unique(), self::ensure_envois_status_constraint(),
                self::ensure_status_constraint('school_years', "'preparation','active','archivee'"),
                self::ensure_status_constraint('child_school_years', "'inscrit','sorti'")
            ),
            false
        );
    }

    /**
     * Déclare les contraintes référentielles manquantes.
     *
     * Les tables sont en InnoDB depuis toujours, mais ne déclaraient aucun
     * lien : le nettoyage reposait entièrement sur le code applicatif, qui
     * le fait bien — mais rien ne protégeait les écritures passant à côté
     * (script de peuplement, correction SQL manuelle, code futur). La base
     * de test contenait déjà un enfant sans famille et neuf lignes
     * pointant un enfant inexistant.
     *
     * Volontairement hors de dbDelta() : celui-ci ne sait pas lire une
     * déclaration FOREIGN KEY et tenterait de la reposer à chaque passage.
     *
     * Tolérant à l'échec : sur un hébergement mutualisé, un ALTER TABLE
     * peut être refusé. Mieux vaut un site qui fonctionne sans contrainte
     * qu'une mise à jour qui s'interrompt. Le refus n'est plus muet pour
     * autant : chaque contrainte non posée est renvoyée à l'appelant,
     * qui la publie et la retente à l'écran admin suivant.
     */
    private static function ensure_foreign_keys() {
        global $wpdb;

        $missing = array();

        $existing = $wpdb->get_col(
            "SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME)
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL"
        );
        if (!is_array($existing)) $existing = array();

        foreach (self::foreign_key_map() as $fk) {
            list($table, $column, $ref, $action) = $fk;
            $ref_column = isset($fk[4]) ? $fk[4] : 'id';
            $t = psc_table($table);
            $r = psc_table($ref);

            if (in_array($t . '.' . $column, $existing, true)) continue;
            if (!self::table_exists($t) || !self::table_exists($r)) continue;

            self::clear_orphans($t, $column, $r, $ref_column);

            $name = substr($t . '_' . $column . '_fk', -64);
            $on_update = $ref_column === 'id' ? '' : ' ON UPDATE CASCADE';
            $wpdb->suppress_errors(true);
            $altered = $wpdb->query(
                "ALTER TABLE $t ADD CONSTRAINT `$name`
                 FOREIGN KEY ($column) REFERENCES $r ($ref_column) ON DELETE $action$on_update"
            );
            $wpdb->suppress_errors(false);
            if ($altered === false) {
                $missing[] = array('type' => 'fk', 'table' => $table, 'column' => $column, 'ref' => $ref, 'action' => $action);
            }
        }

        return $missing;
    }

    /**
     * Restreint envois.statut aux états du cycle d'envoi (cf. Psc_Envois).
     * Même raison que ensure_service_constraint() : CHECK plutôt qu'ENUM,
     * que le mode SQL de WordPress remplacerait silencieusement par ''.
     */
    /**
     * Restreint la colonne statut d'une table à ses valeurs connues (cf.
     * ensure_envois_status_constraint(), même principe et même tolérance).
     *
     * @param string $table  Table sans préfixe.
     * @param string $values Liste SQL des valeurs autorisées.
     */
    private static function ensure_status_constraint($table, $values) {
        global $wpdb;

        $t = psc_table($table);
        if (!self::table_exists($t)) return array();
        $name = substr($t . '_statut_chk', -64);

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = %s",
            $name
        ));
        if ((int) $exists > 0) return array();

        $wpdb->suppress_errors(true);
        $altered = $wpdb->query("ALTER TABLE $t ADD CONSTRAINT `$name` CHECK (statut IN ($values))");
        $wpdb->suppress_errors(false);
        if ($altered === false) {
            return array(array('type' => 'check', 'table' => $table, 'column' => 'statut', 'reason' => 'refused'));
        }
        return array();
    }

    private static function ensure_envois_status_constraint() {
        global $wpdb;

        $t = psc_table('envois');
        if (!self::table_exists($t)) return array();
        $name = substr($t . '_statut_chk', -64);

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = %s",
            $name
        ));
        if ((int) $exists > 0) return array();

        $wpdb->suppress_errors(true);
        $altered = $wpdb->query("ALTER TABLE $t ADD CONSTRAINT `$name` CHECK (statut IN ('a_envoyer','accepte','echec'))");
        $wpdb->suppress_errors(false);
        if ($altered === false) {
            return array(array('type' => 'check', 'table' => 'envois', 'column' => 'statut', 'reason' => 'refused'));
        }
        return array();
    }

    /**
     * Rend l'adresse du second parent unique entre foyers.
     *
     * Le second parent se connecte avec cette adresse (cf.
     * Psc_Parents::get_by_email()) : partagée par deux foyers, elle
     * ouvrirait l'un ou l'autre selon l'ordre de lecture. L'unicité ne
     * reposait que sur une lecture préalable, qu'une écriture concurrente
     * pouvait devancer. L'index ne couvre qu'une colonne ; le croisement
     * avec l'adresse du titulaire d'un AUTRE foyer reste garanti par le
     * verrou d'identité de Psc_Parents, qui sérialise vérification et
     * écriture.
     *
     * Hors dbDelta(), comme les clés étrangères : sur une base qui porte
     * déjà un doublon, l'index ne peut pas être posé ; l'alerte admin le
     * signale et la pose est retentée à chaque écran admin. La collation
     * de la table est insensible à la casse : l'index l'est aussi.
     */
    private static function ensure_second_parent_email_unique() {
        global $wpdb;

        $t = psc_table('parents');
        if (!self::table_exists($t)) return array();

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'second_parent_email'",
            $t
        ));
        if ((int) $exists > 0) return array();

        // Une adresse vide n'est pas une adresse : NULL, que l'index
        // autorise en plusieurs exemplaires.
        $wpdb->query("UPDATE $t SET second_parent_email = NULL WHERE second_parent_email IS NOT NULL AND TRIM(second_parent_email) = ''");

        $duplicates = $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                 SELECT second_parent_email FROM $t
                 WHERE second_parent_email IS NOT NULL
                 GROUP BY second_parent_email HAVING COUNT(*) > 1
             ) d"
        );
        if ((int) $duplicates > 0) {
            return array(array('type' => 'unique', 'table' => 'parents', 'column' => 'second_parent_email', 'reason' => 'dirty'));
        }

        $wpdb->suppress_errors(true);
        $altered = $wpdb->query("ALTER TABLE $t ADD UNIQUE KEY second_parent_email (second_parent_email)");
        $wpdb->suppress_errors(false);
        if ($altered === false) {
            return array(array('type' => 'unique', 'table' => 'parents', 'column' => 'second_parent_email'));
        }
        return array();
    }

    /**
     * Contraint la colonne `service` aux seuls codes reconnus.
     *
     * Elle est un VARCHAR libre : seul le code applicatif garantissait son
     * contenu, et il le vérifiait en quatre endroits distincts. Un chemin
     * d'écriture ajouté plus tard pouvait y déposer n'importe quoi. Même
     * intention que les clés étrangères : ce que le code promet, la base le
     * garantit.
     *
     * Une contrainte CHECK, et non un type ENUM. L'ENUM semblait le choix
     * naturel, mais WordPress retire STRICT_TRANS_TABLES du mode SQL de la
     * session : une valeur hors liste n'y est pas refusée, elle est
     * silencieusement remplacée par une chaîne vide. La contrainte aurait
     * donc corrompu la ligne au lieu de rejeter l'écriture — pire que pas
     * de contrainte du tout. Vérifié sur cette base : l'insertion d'un code
     * inconnu passait et enregistrait ''. Une contrainte CHECK, elle,
     * s'applique quel que soit le mode SQL, et l'écriture échoue.
     *
     * La liste vient de psc_allowed_services() : ajouter une prestation
     * là-bas et incrémenter DB_VERSION suffit à propager la contrainte.
     *
     * Hors dbDelta(), qui ne sait pas lire une déclaration CHECK. Les
     * serveurs antérieurs à MySQL 8.0.16 et MariaDB 10.2 acceptent la
     * syntaxe sans l'appliquer ; un échec réel (hébergeur, donnée hors
     * liste) est renvoyé à l'appelant comme les clés étrangères. La
     * validation applicative (psc_is_valid_service()) reste dans tous
     * les cas la première barrière.
     */
    private static function ensure_service_constraint() {
        global $wpdb;

        $t = psc_table('registrations');
        if (!self::table_exists($t)) return array();

        $allowed = psc_allowed_services();
        sort($allowed);
        $name = substr($t . '_service_chk', -64);

        $clause = $wpdb->get_var($wpdb->prepare(
            "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = %s",
            $name
        ));

        if ($clause !== null) {
            // Comparaison sur les codes extraits, pas sur le texte : le
            // serveur réécrit la clause à sa façon. MySQL 8 la restitue
            // sous la forme (`service` in (_utf8mb4\'GM\',…)) — préfixe de
            // jeu de caractères et apostrophes échappées comprises. Sans
            // retirer ces barres obliques, l'extraction rendrait « GM\ »,
            // jamais égal à la liste attendue : la contrainte serait
            // supprimée puis reposée à chaque passage, avec le risque de la
            // perdre si l'ajout échouait.
            preg_match_all("/'([^']*)'/", str_replace('\\', '', $clause), $m);
            $found = $m[1];
            sort($found);
            if ($found === $allowed) return array(); // déjà conforme

            $wpdb->suppress_errors(true);
            if ($wpdb->query("ALTER TABLE $t DROP CHECK `$name`") === false) {
                $wpdb->query("ALTER TABLE $t DROP CONSTRAINT `$name`"); // MariaDB
            }
            $wpdb->suppress_errors(false);
        }

        // Une donnée déjà hors liste ferait échouer l'ALTER : on renonce
        // plutôt que de laisser croire que la contrainte est en place —
        // et c'est signalé : ces lignes demandent une correction, pas une
        // simple nouvelle tentative.
        $placeholders = implode(',', array_fill(0, count($allowed), '%s'));
        $outliers = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE service NOT IN ($placeholders)",
            $allowed
        ));
        if ($outliers > 0) {
            return array(array('type' => 'check', 'table' => 'registrations', 'column' => 'service', 'reason' => 'dirty'));
        }

        $list = "'" . implode("','", $allowed) . "'";
        $wpdb->suppress_errors(true);
        $altered = $wpdb->query("ALTER TABLE $t ADD CONSTRAINT `$name` CHECK (service IN ($list))");
        $wpdb->suppress_errors(false);
        if ($altered === false) {
            return array(array('type' => 'check', 'table' => 'registrations', 'column' => 'service', 'reason' => 'refused'));
        }

        return array();
    }

    private static function table_exists($table) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    /**
     * Rend le lien cohérent avant de le contraindre : sans cela, l'ALTER
     * TABLE échoue et la contrainte n'est jamais posée.
     *
     * Les lignes visées désignent une ligne qui n'existe pas : elles sont
     * déjà invisibles pour l'application, qui joint systématiquement sur la
     * table référencée. Les supprimer ne retire donc rien de consultable.
     *
     * Le zéro est traité comme une absence : c'est une valeur sentinelle
     * laissée par une ancienne migration exécutée sans année scolaire par
     * défaut — ni un identifiant valide, ni NULL.
     *
     * Quand la colonne accepte NULL, on neutralise le lien plutôt que de
     * supprimer la ligne : une inscription à l'année portant une classe et
     * un justificatif reste ainsi consultable, alors qu'un simple zéro
     * hérité d'une migration ne justifie pas d'en perdre le contenu.
     */
    private static function clear_orphans($table, $column, $ref, $ref_column = 'id') {
        global $wpdb;

        $broken = $ref_column === 'id'
            ? "a.$column = 0 OR (a.$column IS NOT NULL AND b.id IS NULL)"
            : "a.$column IS NOT NULL AND b.id IS NULL";

        $nullable = $wpdb->get_var($wpdb->prepare(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table,
            $column
        ));

        if ($nullable === 'YES') {
            $wpdb->query(
                "UPDATE $table a LEFT JOIN $ref b ON a.$column = b.$ref_column
                 SET a.$column = NULL WHERE $broken"
            );
            return;
        }

        // Colonne NOT NULL : la valeur ne peut pas être neutralisée, seule
        // la suppression de la ligne rétablit la cohérence. Les
        // justificatifs d'assurance qu'elle référençait n'auraient alors
        // plus rien qui les désigne : les laisser sur le disque
        // conserverait des documents nominatifs devenus inatteignables.
        if (psc_table('child_school_years') === $table) {
            $paths = $wpdb->get_col(
                "SELECT a.assurance_file_path FROM $table a
                 LEFT JOIN $ref b ON a.$column = b.$ref_column
                 WHERE a.assurance_file_path IS NOT NULL AND ($broken)"
            );
            foreach ((array) $paths as $rel) {
                $abs = $rel ? psc_private_path($rel) : '';
                if ($abs && file_exists($abs)) {
                    @unlink($abs); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }
            }
        }

        $wpdb->query(
            "DELETE a FROM $table a LEFT JOIN $ref b ON a.$column = b.$ref_column WHERE $broken"
        );
    }

    /**
     * Déplace récursivement le contenu de $src vers $dst, sans perdre un
     * conflit : un fichier déjà présent à destination n'est retiré de la
     * source que s'il est identique octet pour octet. Les noms de $keep
     * (garde-fous de la racine) ne sont jamais déplacés ; ils quittent la
     * source en dernier, et seulement si tout le reste en est sorti.
     *
     * @return bool true si tous les documents ont quitté la source.
     */
    private static function move_tree($src, $dst, array $keep = array()) {
        if (!is_dir($src)) return true;
        if (!is_dir($dst) && !wp_mkdir_p($dst)) return false;
        $ok = true;

        foreach (scandir($src) as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $keep, true)) continue;
            $from = trailingslashit($src) . $entry;
            $to   = trailingslashit($dst) . $entry;

            if (is_dir($from)) {
                if (!self::move_tree($from, $to)) $ok = false;
                continue;
            }
            if (!file_exists($to)) {
                if (!@rename($from, $to)) $ok = false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
            } else {
                $same = is_file($to) && hash_file('sha256', $from) === hash_file('sha256', $to);
                if (!$same || !@unlink($from)) $ok = false; // phpcs:ignore WordPress.PHP.NoSilencedErrors — déjà migré
            }
        }
        if (!$ok) return false;

        foreach ($keep as $guard) {
            if (file_exists(trailingslashit($src) . $guard)) @unlink(trailingslashit($src) . $guard); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        // Tous les documents sont sortis : un dossier vide qui résiste à
        // rmdir() (droits du parent) n'expose plus rien.
        if (count(scandir($src)) === 2) @rmdir($src); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        return true;
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
        psc_record_legacy_usage('calendar_days_migration_read');
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
        psc_record_legacy_usage('calendar_days_migration_read');
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
        psc_record_legacy_usage('trimestres_migration_read');
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
            $has_active_trimestre = (bool) $wpdb->get_var("SELECT COUNT(*) FROM {$t_trim} WHERE active = 1");

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

    /** Retire l'ancien indicateur des habilitations et des instantanés historiques. */
    protected static function remove_pickup_identity_data() {
        global $wpdb;
        $table = psc_table('pickup_persons');
        if ($wpdb->get_var("SHOW COLUMNS FROM $table LIKE 'piece_identite'")) {
            if ($wpdb->query("ALTER TABLE $table DROP COLUMN piece_identite") === false) return false;
        }
        $strip = static function ($value) use (&$strip) {
            if (!is_array($value)) return $value;
            unset($value['piece_identite']);
            foreach ($value as $key => $item) $value[$key] = $strip($item);
            return $value;
        };
        foreach (array('pickup_history' => 'person_snapshot', 'requests' => 'children_json') as $name => $column) {
            $table = psc_table($name);
            $cursor = 0;
            do {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT id, $column AS payload FROM $table WHERE id > %d AND $column LIKE %s ORDER BY id LIMIT 100", $cursor, '%' . $wpdb->esc_like('"piece_identite"') . '%'));
                if ($wpdb->last_error) return false;
                foreach ($rows as $row) {
                    $cursor = (int) $row->id;
                    $data = json_decode($row->payload, true);
                    if (!is_array($data)) continue;
                    if ($wpdb->update($table, array($column => wp_json_encode($strip($data))), array('id' => $cursor)) === false) return false;
                }
            } while (count($rows) === 100);
        }
        return true;
    }

    protected static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

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
        // v4.0 — rythme & exceptions ; l'année scolaire est une seule table
        // depuis 4.15.0 (school_years porte aussi le calendrier).
        $t_hol  = psc_table('holidays');
        $t_pat  = psc_table('pattern');
        $t_exc  = psc_table('exception');

        // Une montée depuis une version antérieure à 4.15.0 garde, le temps
        // des migrations, les colonnes que celles-ci lisent ou écrivent
        // encore (libellé libre de l'année, statut global de l'enfant) :
        // migrate_4_15_0() les convertit puis les supprime, et pose la clé
        // d'année unique. Une installation neuve n'en reçoit aucune.
        $before_4_15 = self::upgrade_before('4.15.0');
        $year_legacy = $before_4_15
            ? "label VARCHAR(20) NULL,\n            year_key VARCHAR(9) NULL,"
            : 'year_key VARCHAR(9) NOT NULL,';
        $year_key_index = $before_4_15 ? '' : "UNIQUE KEY year_key (year_key),\n            ";
        // Sans ligne vide : dbDelta() lirait une ligne blanche comme un index.
        $child_legacy = $before_4_15
            ? "statut VARCHAR(20) NOT NULL DEFAULT 'actif',\n            sorti_le DATETIME NULL,\n            "
            : '';
        $child_legacy_index = $before_4_15 ? ",\n            KEY statut (statut)" : '';

        $sql = "CREATE TABLE $t_years (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            $year_legacy
            date_debut DATE NOT NULL,
            date_fin DATE NOT NULL,
            statut VARCHAR(20) NOT NULL DEFAULT 'preparation',
            vacation_ranges LONGTEXT NULL,
            lock_hours SMALLINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            {$year_key_index}KEY statut (statut)
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
            sepa_reglement_accepted_at DATETIME NULL,
            second_parent_prenom VARCHAR(191) NULL,
            second_parent_nom VARCHAR(191) NULL,
            second_parent_email VARCHAR(191) NULL,
            second_parent_telephone VARCHAR(40) NULL,
            onboarding_seen_at DATETIME NULL,
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
            cantine_sans_repas TINYINT(1) NOT NULL DEFAULT 0,
            food_allergies TEXT NULL,
            food_allergy_signal TINYINT(1) NOT NULL DEFAULT 0,
            food_allergy_consent_at DATETIME NULL,
            {$child_legacy}created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY parent_id (parent_id)$child_legacy_index
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
            payment_mode VARCHAR(20) NOT NULL DEFAULT 'autre',
            sepa_reglement_accepted_at DATETIME NULL,
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
            present TINYINT(1) NOT NULL DEFAULT 1,
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
            KEY acteur (acteur_type, acteur_id, horodatage),
            KEY action_horodatage (action, horodatage),
            KEY categorie_horodatage (categorie, horodatage),
            KEY requete_id (requete_id)
        ) $charset_collate;";

        // Tables LÉGACY (trimestres, calendar_days, registrations) : leur
        // définition ne concerne que les MONTÉES DE VERSION antérieures à
        // 4.0 — dbDelta doit alors compléter les schémas hérités (ajout de
        // school_year_id sur trimestres, etc.) pour que migrate_3_0_0 et
        // suivantes trouvent leurs colonnes, exactement comme avant. Sur
        // une installation neuve (aucune version connue) elles ne sont plus
        // créées : rien n'écrit plus dedans. Sur une installation mise à
        // jour, elles sont conservées en lecture seule le temps d'un cycle
        // de facturation (cf. migrate_4_0_0()).
        if (self::upgrade_includes_legacy_tables()) {
            $t_trim = psc_table('trimestres');
            $t_days = psc_table('calendar_days');
            $t_reg  = psc_table('registrations');

            $sql .= "CREATE TABLE $t_trim (
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

CREATE TABLE $t_days (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            trimestre_id BIGINT UNSIGNED NOT NULL,
            jour_date DATE NOT NULL,
            is_open TINYINT(1) NOT NULL DEFAULT 1,
            label VARCHAR(100) NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY trim_date (trimestre_id, jour_date),
            KEY trim_open (trimestre_id, is_open),
            KEY jour_date (jour_date)
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
            KEY trim_child (trimestre_id, child_id),
            KEY jour_date (jour_date)
        ) $charset_collate;";
        }

        dbDelta($sql);
    }

    /**
     * Une montée de version depuis un schéma antérieur à 4.0 est-elle en
     * cours ? La version en base est lue AVANT que maybe_upgrade() ne la
     * mette à jour : les deux passes dbDelta d'une montée voient donc la
     * même réponse, et une installation neuve (option vide) n'obtient
     * jamais les tables legacy.
     */
    private static function upgrade_includes_legacy_tables() {
        return self::upgrade_before('4.0.0');
    }

    /** Vrai pendant une montée depuis une version de schéma antérieure à $version. */
    private static function upgrade_before($version) {
        if (self::$final_schema) return false;
        $current = get_option('psc_db_version');
        return $current !== '' && $current !== false && version_compare((string) $current, $version, '<');
    }
}
