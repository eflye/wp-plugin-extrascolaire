<?php
if (!defined('ABSPATH')) exit;

class Psc_Installer {

    const DB_VERSION = '4.18.0';
    const ROLES_VERSION = '1.5.0';

    public static function activate() {
        self::create_tables();
        // Mêmes étapes finales qu'une montée de version (idempotentes) :
        // chaînage v2 du journal d'audit et grille de tarifs initiale.
        self::ensure_audit_chain_v2();
        self::migrate_4_17_0();
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
     *
     * Les étapes antérieures au schéma 4.15.0 (version 5.28.0, mise en place
     * de la clé de chiffrement externe) ont été retirées le 26/09/2026 :
     * l'unique installation est au-delà (cf. MIN_UPGRADE_FROM).
     */
    const STEPS = array(
        '4.17.0' => 'migrate_4_17_0',
    );

    /**
     * Plus ancien schéma dont la montée est prise en charge. En deçà, la
     * mise à jour est refusée avec une alerte explicite plutôt que de
     * produire un schéma incomplet : il faut passer d'abord par une version
     * qui portait encore ces migrations (5.32.1 au plus tard).
     */
    const MIN_UPGRADE_FROM = '4.15.0';

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
            || (!psc_running_as_root() && (string) get_option('psc_private_dir_path', '') !== psc_private_dir());
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
        if ($current && version_compare($current, self::MIN_UPGRADE_FROM, '<')) {
            self::$last_step_errors = array();
            self::$step_reason = sprintf(
                /* translators: 1: version du schéma en base, 2: version minimale prise en charge */
                __('schéma %1$s trop ancien : mettez d’abord à jour vers la version 5.32.1 de l’extension (schéma %2$s ou plus), puis vers celle-ci', 'periscolaire-registration'),
                $current, self::MIN_UPGRADE_FROM
            );
            self::record_migration_failure($current, 'version');
            return false;
        }

        // dbDelta() est additif (ajoute tables/colonnes manquantes,
        // ne supprime jamais) : l'exécuter en premier garantit que les
        // migrations ci-dessous trouvent les tables dont elles ont
        // besoin.
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

        // Deuxième passe dbDelta, après les migrations : elle vise le
        // schéma cible, sans les colonnes d'époque que les migrations
        // lisaient encore (cf. upgrade_before()). La première passe, avant
        // elles, les a gardées ; celle-ci aligne le résultat sur une
        // installation neuve (bin/verify-migrations.php le vérifie).
        self::$final_schema = true;
        $final_ok = self::run_step('create_tables');
        self::$final_schema = false;
        // migrate_4_17_0 est idempotente : rejouée à chaque passe, elle
        // donne aussi sa grille de tarifs à une installation neuve.
        if (!$final_ok || !self::run_step('ensure_audit_chain_v2') || !self::run_step('migrate_4_17_0')) {
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
     * Garde-fous posés par psc_ensure_private_dir() à la
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

    /**
     * 4.17.0 (P1-16) — tarifs et statut « cantine sans repas » datés.
     *
     *  1. La grille en vigueur (option psc_service_prices, sinon les tarifs
     *     par défaut) devient la première ligne de chaque code de
     *     psc_tarifs, à compter de la première rentrée connue : les
     *     factures déjà calculées retrouvent exactement leurs prix.
     *  2. Chaque enfant flagué devient une période sans terme, depuis la
     *     même date ; puis la colonne children.cantine_sans_repas est
     *     supprimée.
     *
     * Idempotente et rejouée à chaque passe de schéma (installation neuve
     * comprise) : un code qui a déjà un tarif n'en reçoit pas d'autre, une
     * période n'est créée que pour un enfant qui n'en a aucune.
     */
    private static function migrate_4_17_0() {
        global $wpdb;
        $t_tarifs = psc_table('tarifs');
        $t_sr = psc_table('sans_repas');
        $t_child = psc_table('children');
        $t_years = psc_table('school_years');
        $now = current_time('mysql');

        $debut = (string) $wpdb->get_var("SELECT MIN(date_debut) FROM $t_years");
        if (!psc_valid_date($debut)) $debut = psc_rentree_year() . '-09-01';

        $prices = psc_default_service_prices();
        foreach ((array) get_option('psc_service_prices', array()) as $code => $price) {
            if (isset($prices[$code])) $prices[$code] = max(0, (float) $price);
        }
        // Seules les prestations sans aucun tarif reçoivent leur première
        // ligne : une grille déjà datée n'est jamais complétée dans le passé.
        $known = (array) $wpdb->get_col("SELECT DISTINCT code FROM $t_tarifs");
        foreach ($prices as $code => $price) {
            if (in_array($code, $known, true)) continue;
            $done = $wpdb->insert($t_tarifs, array(
                'code' => $code, 'prix_centimes' => (int) round($price * 100), 'debut' => $debut, 'fin' => null, 'created_at' => $now, 'created_by' => null,
            ));
            if ($done === false) return false;
        }

        if (self::column_exists($t_child, 'cantine_sans_repas')) {
            $done = $wpdb->query($wpdb->prepare(
                "INSERT INTO $t_sr (child_id, debut, fin, created_at, created_by)
                 SELECT c.id, %s, NULL, %s, NULL FROM $t_child c
                 WHERE c.cantine_sans_repas = 1 AND NOT EXISTS (SELECT 1 FROM $t_sr s WHERE s.child_id = c.id)",
                $debut, $now
            ));
            if ($done === false) return false;
            if (false === $wpdb->query("ALTER TABLE $t_child DROP COLUMN cantine_sans_repas")) return false;
        }
        delete_option('psc_service_prices');
        if (class_exists('Psc_Tarifs')) Psc_Tarifs::flush_cache();
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
            // Ancienne table d'avant 4.0 : elle n'existe que sur un site
            // monté depuis une telle version (ensure_foreign_keys() ignore
            // une table absente). Tant qu'elle existe, la cascade purge son
            // historique à la suppression d'un enfant.
            array('registrations',      'child_id',       'children',     'CASCADE'),
            array('child_school_years', 'child_id',       'children',     'CASCADE'),
            array('pickup_persons',     'child_id',       'children',     'CASCADE'),
            array('pickup_history',     'child_id',       'children',     'CASCADE'),
            array('pattern',            'child_id',       'children',     'CASCADE'),
            array('exception',          'child_id',       'children',     'CASCADE'),
            array('child_school_years', 'school_year_id', 'school_years', 'CASCADE'),
            array('envois',             'famille_id',     'parents',      'CASCADE'),
            array('sans_repas',         'child_id',       'children',     'CASCADE'),
            // Version de règlement acceptée (P2-14) : une version citée par
            // une acceptation ne peut pas disparaître.
            array('parents',            'reglement_version_id',      'document_versions', 'RESTRICT'),
            array('parents',            'sepa_reglement_version_id', 'document_versions', 'RESTRICT'),
            array('requests',           'reglement_version_id',      'document_versions', 'RESTRICT'),
            array('requests',           'sepa_reglement_version_id', 'document_versions', 'RESTRICT'),
            array('child_school_years', 'reglement_version_id',      'document_versions', 'RESTRICT'),
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
                self::ensure_foreign_keys(), self::ensure_second_parent_email_unique(), self::ensure_envois_status_constraint(),
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
     * CHECK plutôt qu'ENUM,
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
        $t_tarifs = psc_table('tarifs');
        $t_sans_repas = psc_table('sans_repas');
        $t_doc_versions = psc_table('document_versions');
        // v4.0 — rythme & exceptions ; l'année scolaire est une seule table
        // depuis 4.15.0 (school_years porte aussi le calendrier).
        $t_hol  = psc_table('holidays');
        $t_pat  = psc_table('pattern');
        $t_exc  = psc_table('exception');

        // Avant 4.17.0, le statut « cantine sans repas » était un booléen de
        // l'enfant : migrate_4_17_0() le convertit en période datée puis
        // supprime la colonne.
        $child_sans_repas_legacy = self::upgrade_before('4.17.0') ? "cantine_sans_repas TINYINT(1) NOT NULL DEFAULT 0,\n            " : '';

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
            {$child_sans_repas_legacy}food_allergies TEXT NULL,
            food_allergy_signal TINYINT(1) NOT NULL DEFAULT 0,
            food_allergy_consent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
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

        dbDelta($sql);
    }

    /** Vrai pendant une montée depuis une version de schéma antérieure à $version. */
    private static function upgrade_before($version) {
        if (self::$final_schema) return false;
        $current = get_option('psc_db_version');
        return $current !== '' && $current !== false && version_compare((string) $current, $version, '<');
    }
}
