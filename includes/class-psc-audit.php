<?php
if (!defined('ABSPATH')) exit;

/**
 * Moteur du journal d'audit — une table, une API, deux niveaux de capture
 * (générique à l'étape 3.1, sémantique à l'étape 3.2 — cette classe ne
 * porte que l'écriture et la lecture, pas encore l'instrumentation).
 *
 * Psc_Audit::log() n'échoue jamais visiblement : le journal ne doit
 * casser aucune action métier. Toute défaillance est absorbée, comptée
 * (option psc_audit_failures) et repliée sur le fichier journal-acces.log
 * existant.
 */
class Psc_Audit {

    private static $request_id = null;
    private static $pending_action = null;
    private static $pending_objet_type = null;
    private static $finalized = false;

    /**
     * Filet générique : garantit qu'aucune action POST/AJAX du plugin ne
     * passe sous le radar, même une action ajoutée plus tard et pas
     * encore instrumentée sémantiquement (étape 3.2).
     *
     * admin_init (priorité 0) tourne aussi bien pour admin-post.php que
     * pour admin-ajax.php (les deux l'appellent) : un seul point
     * d'observation couvre donc tous les points d'entrée POST du
     * portail comme du backoffice.
     */
    public static function init() {
        add_action('admin_init', array(__CLASS__, 'capture_generic'), 0);
        add_action('shutdown', array(__CLASS__, 'finalize_generic'));
        add_action('psc_purge_audit_log', array(__CLASS__, 'run_daily_purge'), 10, 0);
        self::ensure_crons();
    }

    public static function ensure_crons() {
        if (!wp_next_scheduled('psc_purge_audit_log')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'psc_purge_audit_log');
        }
    }

    /**
     * Ouvre une ligne « en attente » dès qu'une action psc_* connue arrive
     * en POST. Rien n'est écrit en base ici : seule finalize_generic()
     * (shutdown) écrit, et seulement si aucun appel sémantique n'a déjà
     * clos la ligne pour cette même action (cf. log_unsafe()).
     */
    public static function capture_generic() {
        if (empty($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') return;
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ($action === '' || strpos($action, 'psc_') !== 0) return;

        $registry = psc_audit_action_registry();
        if (isset($registry[$action])) {
            $entry = $registry[$action];
            if (isset($entry['niveau']) && $entry['niveau'] === 'ignore') return;
            self::$pending_action = isset($entry['action']) ? $entry['action'] : 'inconnu.action';
            self::$pending_objet_type = isset($entry['objet']) ? $entry['objet'] : null;
        } else {
            // Le journal signale son propre angle mort plutôt que de se
            // trouer silencieusement : cf. tests/integration/audit-registry.php,
            // qui échoue en CI dès qu'une action manque au registre — ceci
            // couvre le cas où ce test n'a pas encore tourné (déploiement direct).
            self::$pending_action = 'inconnu.action';
            self::$pending_objet_type = null;
            $unknown = get_option('psc_audit_unknown_actions', array());
            if (!is_array($unknown)) $unknown = array();
            if (!in_array($action, $unknown, true)) {
                $unknown[] = $action;
                update_option('psc_audit_unknown_actions', $unknown, false);
            }
        }
    }

    /**
     * Écrit la ligne « en attente » si elle n'a pas déjà été close par un
     * appel sémantique portant le même code d'action (cf. log_unsafe()).
     * Le résultat est déduit du code HTTP final — une approximation
     * assumée : ce plugin répond en 302 (PRG) aussi bien sur succès que
     * sur ré-affichage d'un formulaire en erreur, cf. note de classe.
     */
    public static function finalize_generic() {
        if (self::$pending_action === null || self::$finalized) return;

        $status = function_exists('http_response_code') ? http_response_code() : 200;
        if ($status === false) $status = 200;

        if ($status >= 500) {
            $resultat = 'erreur';
        } elseif ($status === 200 || $status === 302) {
            $resultat = 'succes';
        } elseif ($status >= 400) {
            // Tout le 4xx, pas seulement 401/403 : un 400 (validation
            // refusée, ex. Psc_Sidscm::require_day_service()) ou un 429
            // (limite de débit) sont des refus tout autant qu'un 403 —
            // aucun des trois ne laisse la moindre ambiguïté sur l'issue.
            $resultat = 'refus';
        } else {
            $resultat = 'tentative';
        }

        self::log(self::$pending_action, array(
            'resultat' => $resultat,
            'objet_type' => self::$pending_objet_type,
        ));
    }

    /**
     * Identifiant stable pour toute la durée de la requête HTTP courante —
     * regroupe les lignes qu'une même action produit (capture générique +
     * enrichissement sémantique). CHAR(13) : 12 caractères hexadécimaux
     * aléatoires + 1 caractère de canal.
     */
    public static function request_id() {
        if (self::$request_id === null) {
            self::$request_id = bin2hex(random_bytes(6)) . substr(self::detect_channel(), 0, 1);
        }
        return self::$request_id;
    }

    /**
     * Enregistre une ligne d'audit. N'émet jamais d'erreur visible : voir
     * la note de classe.
     *
     * $args : resultat (succes|refus|erreur|tentative, défaut succes),
     * objet_type, objet_id, famille_id, enfant_id, resume, avant, apres,
     * meta, acteur (tableau {type,id,libelle,pour_le_compte_de} pour
     * forcer l'acteur — cron et WP-CLI).
     */
    public static function log($action_code, $args = array()) {
        try {
            self::log_unsafe((string) $action_code, is_array($args) ? $args : array());
        } catch (\Throwable $e) {
            self::record_failure($action_code, $e->getMessage());
        }
    }

    private static function log_unsafe($action_code, $args) {
        global $wpdb;
        $args = wp_parse_args($args, array(
            'resultat'   => 'succes',
            'objet_type' => null,
            'objet_id'   => null,
            'famille_id' => null,
            'enfant_id'  => null,
            'resume'     => null,
            'avant'      => null,
            'apres'      => null,
            'meta'       => null,
            'acteur'     => null,
        ));

        $actor = self::resolve_actor($args['acteur']);

        $resume = ($args['resume'] !== null && $args['resume'] !== '')
            ? $args['resume']
            : psc_audit_default_resume($action_code);
        $resume = psc_audit_truncate_resume($resume);

        $diff = $args['objet_type'] ? psc_audit_redact_diff($args['objet_type'], $args['avant'], $args['apres']) : array();
        $details = psc_audit_build_details(
            isset($diff['avant']) ? $diff['avant'] : array(),
            isset($diff['apres']) ? $diff['apres'] : array(),
            $args['meta']
        );

        $resultat = in_array($args['resultat'], array('succes', 'refus', 'erreur', 'tentative'), true) ? $args['resultat'] : 'succes';

        $row = array(
            'requete_id'         => self::request_id(),
            'acteur_type'        => $actor['type'],
            'acteur_id'          => $actor['id'] !== null ? (int) $actor['id'] : null,
            'acteur_libelle'     => $actor['libelle'],
            'pour_le_compte_de'  => $actor['pour_le_compte_de'] !== null ? (int) $actor['pour_le_compte_de'] : null,
            'action'             => $action_code,
            'categorie'          => psc_audit_categorie_for_action($action_code),
            'resultat'           => $resultat,
            'objet_type'         => $args['objet_type'],
            'objet_id'           => $args['objet_id'] !== null ? (int) $args['objet_id'] : null,
            'famille_id'         => $args['famille_id'] !== null ? (int) $args['famille_id'] : null,
            'enfant_id'          => $args['enfant_id'] !== null ? (int) $args['enfant_id'] : null,
            'resume'             => $resume,
            'details'            => $details,
            'ip'                 => psc_client_ip() ?: null,
            'canal'              => self::detect_channel(),
        );

        // Lecture de la tête de chaîne -> insertion -> mise à jour, sous
        // verrou (SEC-06) : sans lui, deux requêtes simultanées chaînent sur
        // la même empreinte et la vérification signale une fausse rupture.
        // Verrou indisponible (délai de 2 s dépassé) : l'écriture a lieu
        // quand même — le journal ne bloque jamais une action — et l'échec
        // est compté (option psc_audit_lock_timeouts).
        $locked = (string) $wpdb->get_var("SELECT GET_LOCK('psc_audit_chain', 2)") === '1';
        if (!$locked) update_option('psc_audit_lock_timeouts', (int) get_option('psc_audit_lock_timeouts', 0) + 1, false);
        try {
            $horodatage = gmdate('Y-m-d H:i:s');
            // Lu en base, pas dans le cache d'options : une autre requête
            // a pu avancer la tête depuis le dernier appel de celle-ci.
            $previous_hash = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'psc_audit_last_hash'
            ));
            // Chaînage v2 (P1-11) dès que la mise à jour 4.16.0 a posé son
            // seuil : l'empreinte de contenu est gardée à part, pour qu'une
            // ligne expirée puisse être vidée sans casser la chaîne.
            $contenu = null;
            if (self::chain_v2_from() > 0) {
                $contenu = psc_audit_content_hash($horodatage, $action_code, $actor['type'], $actor['id'], $args['objet_type'], $args['objet_id'], $resume);
                $empreinte = psc_audit_chain_hash($previous_hash, $contenu);
            } else {
                $empreinte = psc_audit_compute_hash(
                    $previous_hash,
                    $horodatage,
                    $action_code,
                    $actor['type'],
                    $actor['id'],
                    $args['objet_type'],
                    $args['objet_id'],
                    $resume
                );
            }
            $row['horodatage'] = $horodatage;
            $row['empreinte'] = $empreinte;
            if ($contenu !== null) $row['empreinte_contenu'] = $contenu;

            $inserted = $wpdb->insert(psc_table('audit_log'), $row);
            if ($inserted === false) {
                self::record_failure($action_code, $wpdb->last_error);
                return;
            }
            update_option('psc_audit_last_hash', $empreinte, false);
        } finally {
            if ($locked) $wpdb->query("SELECT RELEASE_LOCK('psc_audit_chain')");
        }

        // Une action produit une seule ligne : un appel sémantique qui porte
        // le même code que la capture générique en attente la referme — le
        // filet de shutdown n'a alors plus rien à écrire pour cette requête.
        if (self::$pending_action !== null && $action_code === self::$pending_action) {
            self::$finalized = true;
        }
    }

    /**
     * Résolution de l'acteur, dans l'ordre documenté (cf. cahier des
     * charges) : système (cron/CLI), consultation d'espace famille active,
     * agent WordPress, famille, intervenant, public. Un $forced explicite
     * (tableau) court-circuite tout — cas des appels depuis un contexte
     * cron/CLI qui connaît déjà son acteur système.
     */
    private static function resolve_actor($forced) {
        if (is_array($forced)) {
            return wp_parse_args($forced, array(
                'type' => 'systeme', 'id' => null, 'libelle' => '', 'pour_le_compte_de' => null,
            ));
        }

        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return array('type' => 'systeme', 'id' => null, 'libelle' => 'cron', 'pour_le_compte_de' => null);
        }
        if (defined('WP_CLI') && WP_CLI) {
            return array('type' => 'systeme', 'id' => null, 'libelle' => 'wp-cli', 'pour_le_compte_de' => null);
        }

        // Consultation d'espace famille : l'acteur reste l'agent réel, JAMAIS
        // la famille consultée — c'est tout l'intérêt de cette traçabilité.
        if (class_exists('Psc_Impersonation') && method_exists('Psc_Impersonation', 'active')) {
            $impersonation = Psc_Impersonation::active();
            if ($impersonation) {
                $agent_id = (int) $impersonation->wp_user_id;
                $agent = get_userdata($agent_id);
                return array(
                    'type'              => 'agent',
                    'id'                => $agent_id,
                    'libelle'           => $agent ? $agent->user_login : ('#' . $agent_id),
                    'pour_le_compte_de' => (int) $impersonation->family_id,
                );
            }
        }

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            return array('type' => 'agent', 'id' => (int) $user->ID, 'libelle' => $user->user_login, 'pour_le_compte_de' => null);
        }

        // L'acteur est le foyer ; l'identité de connexion de la session
        // (SEC-02) dit lequel des deux responsables agit. Libellé sans nom
        // ni e-mail (RGPD-04) : l'écran du journal résout le nom du foyer à
        // l'affichage, tant que la fiche existe.
        if (class_exists('Psc_Parents')) {
            $parent = Psc_Parents::current();
            if ($parent) {
                $role = method_exists('Psc_Parents', 'session_login_role') ? Psc_Parents::session_login_role() : '';
                if ($role === 'titulaire') {
                    /* translators: %d: identifiant de la famille */
                    $libelle = sprintf(__('Famille #%d (titulaire)', 'periscolaire-registration'), (int) $parent->id);
                } elseif ($role === 'second') {
                    /* translators: %d: identifiant de la famille */
                    $libelle = sprintf(__('Famille #%d (second parent)', 'periscolaire-registration'), (int) $parent->id);
                } else {
                    /* translators: %d: identifiant de la famille */
                    $libelle = sprintf(__('Famille #%d', 'periscolaire-registration'), (int) $parent->id);
                }
                return array('type' => 'famille', 'id' => (int) $parent->id, 'libelle' => $libelle, 'pour_le_compte_de' => null);
            }
        }

        // Écran intervenants : l'identité est issue du registre serveur et
        // de la session éphémère (jamais d'un libellé fourni par le client).
        if (class_exists('Psc_Sidscm') && method_exists('Psc_Sidscm', 'is_authenticated_request') && Psc_Sidscm::is_authenticated_request()) {
            $intervenant = method_exists('Psc_Sidscm', 'current_intervenant') ? Psc_Sidscm::current_intervenant() : null;
            return array('type' => 'intervenant', 'id' => $intervenant['id'] ?? null, 'libelle' => $intervenant['nom'] ?? __('écran intervenants', 'periscolaire-registration'), 'pour_le_compte_de' => null);
        }

        return array('type' => 'public', 'id' => null, 'libelle' => __('visiteur non identifié', 'periscolaire-registration'), 'pour_le_compte_de' => null);
    }

    private static function detect_channel() {
        if (function_exists('wp_doing_cron') && wp_doing_cron()) return 'cron';
        if (defined('WP_CLI') && WP_CLI) return 'cli';
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return 'ajax';
        if (is_admin()) return 'admin';
        return 'portail';
    }

    /**
     * Repli quand l'écriture en base échoue : compteur (alerte de l'écran
     * d'administration) et une ligne dans journal-acces.log, du répertoire
     * privé. La ligne ne porte que l'horodatage UTC, le code d'action et
     * un message technique expurgé (psc_audit_technical_message) : jamais
     * les détails de l'action, qui peuvent être personnels. Au-delà de
     * psc_audit_fallback_max_bytes(), le fichier est archivé en
     * journal-acces.log.1 (une seule génération), supprimée par la purge
     * quotidienne une fois la durée de rétention « normal » écoulée.
     */
    private static function record_failure($action_code, $message) {
        $count = (int) get_option('psc_audit_failures', 0);
        update_option('psc_audit_failures', $count + 1, false);

        $entry = wp_json_encode(array(
            'horodatage' => gmdate('Y-m-d H:i:s'),
            'action'     => (string) $action_code,
            'erreur'     => psc_audit_technical_message($message),
        )) . "\n";
        $path = self::fallback_path();
        if (!$path) return;
        clearstatcache(true, $path);
        if (is_file($path) && filesize($path) + strlen($entry) > psc_audit_fallback_max_bytes()) {
            @rename($path, $path . '.1'); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }

    /** Chemin du fichier de repli, ou false si le répertoire privé est indisponible. */
    public static function fallback_path() {
        return function_exists('psc_private_path') ? psc_private_path('journal-acces.log') : false;
    }

    /**
     * Rétention du fichier de repli : chaque fichier (courant et archive)
     * dont la dernière écriture dépasse la durée « normal » est supprimé.
     *
     * @return int Nombre de fichiers supprimés.
     */
    public static function purge_fallback($now = null) {
        $path = self::fallback_path();
        if (!$path) return 0;
        $cutoff = ($now === null ? time() : (int) $now) - psc_audit_retention_days('normal') * DAY_IN_SECONDS;
        $removed = 0;
        foreach (array($path . '.1', $path) as $file) {
            clearstatcache(true, $file);
            if (is_file($file) && filemtime($file) < $cutoff && @unlink($file)) $removed++; // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        return $removed;
    }

    /* ------------------------------------------------------------------ */
    /* Lecture                                                             */
    /* ------------------------------------------------------------------ */

    private static function build_where($args) {
        global $wpdb;
        $where = array('1=1');
        $values = array();
        // Lignes purgées (contenu vidé, gardées pour le chaînage) : jamais
        // listées ni exportées.
        if (self::chain_v2_from() > 0) $where[] = 'purgee_le IS NULL';

        if (!empty($args['du'])) { $where[] = 'horodatage >= %s'; $values[] = $args['du'] . ' 00:00:00'; }
        if (!empty($args['au'])) { $where[] = 'horodatage <= %s'; $values[] = $args['au'] . ' 23:59:59'; }
        if (!empty($args['acteur_type'])) { $where[] = 'acteur_type = %s'; $values[] = $args['acteur_type']; }
        if (!empty($args['acteur_id'])) { $where[] = 'acteur_id = %d'; $values[] = (int) $args['acteur_id']; }
        if (!empty($args['famille_id'])) { $where[] = 'famille_id = %d'; $values[] = (int) $args['famille_id']; }
        if (!empty($args['enfant_id'])) { $where[] = 'enfant_id = %d'; $values[] = (int) $args['enfant_id']; }
        if (!empty($args['categorie'])) { $where[] = 'categorie = %s'; $values[] = $args['categorie']; }
        if (!empty($args['action'])) { $where[] = 'action = %s'; $values[] = $args['action']; }
        if (!empty($args['resultat'])) { $where[] = 'resultat = %s'; $values[] = $args['resultat']; }
        if (!empty($args['niveau'])) {
            $actions = psc_audit_actions_by_niveau($args['niveau']);
            if ($actions) {
                $where[] = 'action IN (' . implode(',', array_fill(0, count($actions), '%s')) . ')';
                $values = array_merge($values, $actions);
            } else {
                $where[] = '1=0';
            }
        }
        if (!empty($args['recherche'])) {
            $like = '%' . $wpdb->esc_like($args['recherche']) . '%';
            $where[] = '(resume LIKE %s OR acteur_libelle LIKE %s)';
            $values[] = $like;
            $values[] = $like;
        }

        return array($where, $values);
    }

    private static function default_filters() {
        return array(
            'du' => null, 'au' => null, 'acteur_type' => null, 'acteur_id' => null,
            'famille_id' => null, 'enfant_id' => null, 'categorie' => null, 'action' => null,
            'resultat' => null, 'recherche' => '', 'niveau' => null,
        );
    }

    /** Liste paginée, tri décroissant (les plus récentes en premier). */
    public static function query($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array_merge(self::default_filters(), array('page' => 1, 'per_page' => 50)));
        list($where, $values) = self::build_where($args);

        $per_page = max(1, min(500, (int) $args['per_page']));
        $page = max(1, (int) $args['page']);
        $offset = ($page - 1) * $per_page;

        $sql = 'SELECT * FROM ' . psc_table('audit_log') . ' WHERE ' . implode(' AND ', $where)
            . " ORDER BY id DESC LIMIT $per_page OFFSET $offset";
        return $wpdb->get_results($values ? $wpdb->prepare($sql, $values) : $sql);
    }

    public static function count($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, self::default_filters());
        list($where, $values) = self::build_where($args);

        $sql = 'SELECT COUNT(*) FROM ' . psc_table('audit_log') . ' WHERE ' . implode(' AND ', $where);
        return (int) $wpdb->get_var($values ? $wpdb->prepare($sql, $values) : $sql);
    }

    /**
     * Parcourt le résultat des filtres par lots de 500, par id croissant —
     * jamais OFFSET, qui s'effondre sur une table de plusieurs centaines
     * de milliers de lignes. Utilisé par l'export (étape 5).
     */
    public static function stream($args, $callback) {
        global $wpdb;
        $args = wp_parse_args($args, self::default_filters());
        list($where, $base_values) = self::build_where($args);

        $last_id = 0;
        do {
            $sql = 'SELECT * FROM ' . psc_table('audit_log') . ' WHERE ' . implode(' AND ', $where)
                . ' AND id > %d ORDER BY id ASC LIMIT 500';
            $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($base_values, array($last_id))));
            foreach ($rows as $row) {
                call_user_func($callback, $row);
                $last_id = (int) $row->id;
            }
        } while (count($rows) === 500);
    }

    /**
     * Revérifie le chaînage sur les $limit dernières lignes (cf.
     * psc_audit_verify_chain_rows() pour l'algorithme, pur et testé sans
     * base de données).
     *
     * Une ligne de contexte supplémentaire (limit + 1) est chargée : sans
     * elle, la plus ancienne ligne du lot sert d'ancre non vérifiée et une
     * altération portant exactement sur elle passerait inaperçue. Avec
     * cette ligne de contexte, seule la toute première ligne de
     * l'historique complet reste, par construction, invérifiable (aucune
     * ligne n'existe avant elle).
     *
     * @return int|null Id de la première ligne dont l'empreinte ne
     *         correspond plus, ou null si la chaîne est intacte.
     */
    public static function verify_chain($limit = 1000) {
        $report = self::verify_chain_report($limit);
        return $report['rupture'];
    }

    /**
     * Même vérification que verify_chain(), avec le nombre de lignes
     * anonymisées (oubli d'une famille ou d'un enfant) acceptées via leur
     * empreinte de contenu conservée — à afficher à part (PERF-05).
     *
     * @return array{rupture: ?int, anonymisees: int}
     */
    public static function verify_chain_report($limit = 1000) {
        global $wpdb;
        $limit = max(2, (int) $limit);
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM (SELECT * FROM ' . psc_table('audit_log') . ' ORDER BY id DESC LIMIT %d) x ORDER BY id ASC',
            $limit + 1
        ));
        return psc_audit_verify_chain_report($rows, self::chain_v2_from());
    }

    /**
     * Premier id chaîné en v2 (empreinte de contenu séparée), posé par la
     * mise à jour 4.16.0 (Psc_Installer::ensure_audit_chain_v2) ; 0 tant
     * qu'elle n'a pas tourné — le journal garde alors le chaînage v1 et
     * la purge historique.
     */
    public static function chain_v2_from() {
        return (int) get_option('psc_audit_chain_v2_from', 0);
    }

    /* ------------------------------------------------------------------ */
    /* Conservation, suppression, oubli (étape 6)                          */
    /* ------------------------------------------------------------------ */

    /**
     * Purge quotidienne (P1-11) : chaque ligne plus vieille que la durée de
     * rétention de SON niveau (options psc_audit_retention_{niveau}, cf.
     * psc_audit_retention_days()) est vidée de son contenu — acteur,
     * résumé, détails, IP, identifiants de famille, d'enfant et d'objet —
     * et marquée purgée (purgee_le). Elle garde son horodatage, son code
     * d'action et ses deux empreintes : la chaîne reste vérifiable sans
     * aucune donnée personnelle, même quand la ligne purgée est suivie de
     * lignes à conserver plus longtemps.
     *
     * Les lignes purgées qui forment le début de la table (aucune ligne
     * conservée avant elles) sont ensuite réellement supprimées : la plus
     * ancienne ligne survivante devient l'ancre de la vérification (cf.
     * verify_chain()).
     *
     * Lots de 1000 lignes par id croissant, avec une limite de temps par
     * exécution : ce qui n'est pas traité aujourd'hui l'est au passage
     * suivant. Avant la mise à jour 4.16.0 (chaînage v1), rien n'est fait :
     * vider une ligne v1 casserait la vérification.
     *
     * @return int Nombre de lignes purgées (vidées) par ce passage.
     */
    /** Tâche quotidienne psc_purge_audit_log (cf. purge_expired()). */
    public static function run_daily_purge() {
        self::purge_expired();
    }

    public static function purge_expired($time_limit_seconds = 20, $now = null) {
        self::purge_fallback($now);
        if (self::chain_v2_from() <= 0) return 0;

        global $wpdb;
        $started = microtime(true);
        $table = psc_table('audit_log');
        $now = $now === null ? time() : (int) $now;
        $cutoffs = array();
        foreach (array_keys(psc_audit_retention_defaults()) as $niveau) {
            $cutoffs[$niveau] = gmdate('Y-m-d H:i:s', $now - psc_audit_retention_days($niveau) * DAY_IN_SECONDS);
        }
        $oldest_cutoff = max($cutoffs); // la plus courte rétention : rien de plus récent n'expire

        $purged = 0;
        $cursor = 0;
        while ((microtime(true) - $started) <= $time_limit_seconds) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, action, horodatage FROM $table WHERE id > %d AND purgee_le IS NULL AND horodatage < %s ORDER BY id ASC LIMIT 1000",
                $cursor, $oldest_cutoff
            ));
            if (!$rows) break;
            $expired = array();
            foreach ($rows as $row) {
                $niveau = psc_audit_niveau_for_action($row->action);
                $cutoff = $cutoffs[$niveau] ?? $cutoffs['normal'];
                if ($row->horodatage < $cutoff) $expired[] = (int) $row->id;
                $cursor = (int) $row->id;
            }
            if ($expired) {
                $done = $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET acteur_id = NULL, acteur_libelle = '', pour_le_compte_de = NULL,
                            objet_id = NULL, famille_id = NULL, enfant_id = NULL, resume = '', details = NULL,
                            ip = NULL, purgee_le = %s
                     WHERE purgee_le IS NULL AND id IN (" . implode(',', $expired) . ')',
                    gmdate('Y-m-d H:i:s', $now)
                ));
                $purged += (int) $done;
            }
            if (count($rows) < 1000) break;
        }

        // Préfixe purgé : suppression réelle.
        $first_kept = $wpdb->get_var("SELECT MIN(id) FROM $table WHERE purgee_le IS NULL");
        $deleted = $first_kept === null
            ? $wpdb->query("DELETE FROM $table WHERE purgee_le IS NOT NULL")
            : $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id < %d AND purgee_le IS NOT NULL", (int) $first_kept));

        if ($purged > 0 || $deleted > 0) {
            self::log('audit.purge', array(
                'meta'   => array('lignes_purgees' => $purged, 'lignes_supprimees' => (int) $deleted),
                'resume' => sprintf(__('%d ligne(s) du journal d’audit purgée(s) (durée de rétention dépassée).', 'periscolaire-registration'), $purged),
            ));
        }

        return $purged;
    }

    /**
     * Anonymise (sans les supprimer) les lignes concernant une famille
     * supprimée ou effacée (RGPD-04) :
     *  - famille_id et enfant_id -> NULL, details effacés ;
     *  - pour_le_compte_de -> NULL quand il désigne cette famille ;
     *  - ip -> NULL quand l'acteur est la famille elle-même ou un visiteur
     *    (demande d'inscription) : c'est alors l'adresse de la famille ;
     *  - acteur_libelle -> « famille supprimée #id » pour les lignes dont la
     *    famille est l'acteur (connexion, etc.) ;
     *  - resume réécrit en version générique s'il nomme la famille.
     *
     * $needles (nom, prénom, e-mail de la famille, du second parent et des
     * enfants) est complété ici depuis les tables métier, si les lignes
     * existent encore : appeler cette méthode AVANT de supprimer ou
     * d'anonymiser la famille, ou fournir les aiguilles.
     *
     * @return int Nombre de lignes dont l'identité a été effacée.
     */
    public static function forget_family($family_id, array $needles = array()) {
        $family_id = (int) $family_id;
        if ($family_id <= 0) return 0;
        $needles = self::clean_needles(array_merge($needles, self::family_needles($family_id)));

        // Lignes historiques sans famille_id (demande.* antérieures à la
        // convention RGPD-04) qui portent l'e-mail de la famille dans le
        // résumé : retrouvées par l'adresse, puis anonymisées.
        global $wpdb;
        $where = "(famille_id = %d OR (acteur_type = 'famille' AND acteur_id = %d) OR pour_le_compte_de = %d";
        $args = array($family_id, $family_id, $family_id);
        $emails = array_values(array_filter($needles, 'is_email'));
        if ($emails) {
            $where .= ' OR (famille_id IS NULL AND (' . implode(' OR ', array_fill(0, count($emails), 'resume LIKE %s')) . '))';
            foreach ($emails as $email) $args[] = '%' . $wpdb->esc_like($email) . '%';
        }
        $where .= ')';

        return self::forget_rows(
            $where,
            $args,
            $needles,
            function ($row) use ($family_id) {
                $is_self = $row->acteur_type === 'famille' && (int) $row->acteur_id === $family_id;
                $changes = array();
                if ((int) $row->famille_id === $family_id) {
                    $changes['famille_id'] = null;
                    $changes['enfant_id'] = null;
                    $changes['details'] = null;
                }
                if ((int) $row->pour_le_compte_de === $family_id) $changes['pour_le_compte_de'] = null;
                if ($is_self) {
                    $changes['acteur_libelle'] = sprintf(__('famille supprimée #%d', 'periscolaire-registration'), $family_id);
                    $changes['details'] = null;
                }
                if (($is_self || $row->acteur_type === 'public') && $row->ip !== null && $row->ip !== '') $changes['ip'] = null;
                return $changes;
            }
        );
    }

    /**
     * Anonymise les lignes concernant un enfant purgé (RGPD-04) : enfant_id
     * -> NULL et details effacés sur les lignes qui le visent ; sur les
     * autres lignes de sa famille, résumé générique et details effacés
     * seulement s'ils le nomment. Aiguilles complétées depuis la fiche
     * enfant si elle existe encore : appeler AVANT sa suppression (cf.
     * Psc_Admin_Familles::purge_child()).
     *
     * @return int Nombre de lignes modifiées.
     */
    public static function forget_child($child_id, array $needles = array()) {
        global $wpdb;
        $child_id = (int) $child_id;
        if ($child_id <= 0) return 0;

        $child = $wpdb->get_row($wpdb->prepare(
            'SELECT parent_id, nom, prenom, date_naissance FROM ' . psc_table('children') . ' WHERE id = %d', $child_id
        ));
        $family_id = 0;
        if ($child) {
            $family_id = (int) $child->parent_id;
            $needles = array_merge($needles, self::child_needles($child));
        }

        $where = "(enfant_id = %d OR (objet_type = 'enfant' AND objet_id = %d)";
        $args = array($child_id, $child_id);
        if ($family_id > 0) {
            $where .= ' OR famille_id = %d';
            $args[] = $family_id;
        }
        $where .= ')';
        $needles = self::clean_needles($needles);

        return self::forget_rows($where, $args, $needles, function ($row) use ($child_id, $needles) {
            $changes = array();
            $targets_child = (int) $row->enfant_id === $child_id
                || ($row->objet_type === 'enfant' && (int) $row->objet_id === $child_id);
            if ((int) $row->enfant_id === $child_id) $changes['enfant_id'] = null;
            if ($row->details !== null && ($targets_child || psc_audit_resume_needs_redaction((string) $row->details, $needles))) {
                $changes['details'] = null;
            }
            return $changes;
        });
    }

    /**
     * Effacement du seul second parent (Psc_Privacy::erase_second_parent()) :
     * la famille reste suivie, ses lignes gardent famille_id. Seules les
     * lignes dont le résumé ou le détail nomme le second parent sont
     * neutralisées (résumé générique, details effacés). Aiguilles complétées
     * depuis la ligne parents : appeler AVANT de vider les champs.
     *
     * @return int Nombre de lignes modifiées.
     */
    public static function forget_second_parent($family_id, array $needles = array()) {
        global $wpdb;
        $family_id = (int) $family_id;
        if ($family_id <= 0) return 0;
        $parent = $wpdb->get_row($wpdb->prepare(
            'SELECT second_parent_email, second_parent_nom, second_parent_prenom FROM ' . psc_table('parents') . ' WHERE id = %d',
            $family_id
        ));
        if ($parent) {
            $needles = array_merge($needles, array(
                $parent->second_parent_email,
                trim($parent->second_parent_prenom . ' ' . $parent->second_parent_nom),
                trim($parent->second_parent_nom . ' ' . $parent->second_parent_prenom),
            ));
        }
        $needles = self::clean_needles($needles);
        if (!$needles) return 0;

        return self::forget_rows(
            "(famille_id = %d OR (acteur_type = 'famille' AND acteur_id = %d))",
            array($family_id, $family_id),
            $needles,
            function ($row) use ($needles, $family_id) {
                $changes = array();
                if ($row->details !== null && psc_audit_resume_needs_redaction((string) $row->details, $needles)) $changes['details'] = null;
                if (psc_audit_resume_needs_redaction((string) $row->acteur_libelle, $needles)) {
                    /* translators: %d: identifiant de la famille */
                    $changes['acteur_libelle'] = sprintf(__('Famille #%d', 'periscolaire-registration'), $family_id);
                }
                return $changes;
            }
        );
    }

    /** Aiguilles d'une famille encore en base (titulaire, second parent, enfants). */
    private static function family_needles($family_id) {
        global $wpdb;
        $needles = array();
        $parent = $wpdb->get_row($wpdb->prepare(
            'SELECT email, nom, prenom, second_parent_email, second_parent_nom, second_parent_prenom FROM ' . psc_table('parents') . ' WHERE id = %d',
            $family_id
        ));
        if ($parent) {
            $needles = array(
                $parent->email, $parent->nom, $parent->prenom,
                trim($parent->prenom . ' ' . $parent->nom), trim($parent->nom . ' ' . $parent->prenom),
                $parent->second_parent_email, $parent->second_parent_nom, $parent->second_parent_prenom,
            );
        }
        $children = $wpdb->get_results($wpdb->prepare(
            'SELECT nom, prenom, date_naissance FROM ' . psc_table('children') . ' WHERE parent_id = %d', $family_id
        ));
        foreach ($children as $child) {
            $needles = array_merge($needles, self::child_needles($child));
        }
        return $needles;
    }

    /** Aiguilles d'un enfant : nom, prénom, les deux ordres, date de naissance (ISO et jj/mm/aaaa). */
    private static function child_needles($child) {
        $needles = array(
            $child->nom, $child->prenom,
            trim($child->prenom . ' ' . $child->nom), trim($child->nom . ' ' . $child->prenom),
        );
        if (!empty($child->date_naissance) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $child->date_naissance, $m)) {
            $needles[] = $child->date_naissance;
            $needles[] = $m[3] . '/' . $m[2] . '/' . $m[1];
        }
        return $needles;
    }

    private static function clean_needles(array $needles) {
        $out = array();
        foreach ($needles as $needle) {
            $needle = trim((string) $needle);
            if ($needle !== '' && !in_array($needle, $out, true)) $out[] = $needle;
        }
        return $out;
    }

    /**
     * Applique $changes_for($row) aux lignes non purgées qui répondent à
     * $where, et remplace par le résumé générique celles dont le résumé ou
     * le libellé d'acteur contient une aiguille.
     *
     * Aucune empreinte n'est recalculée (PERF-05) : details, famille_id,
     * enfant_id, ip, pour_le_compte_de et acteur_libelle n'entrent pas dans
     * l'empreinte. Le résumé, lui, y entre : quand il doit être réécrit, la
     * ligne prend la forme « anonymisée » (résumé générique EXACT, details,
     * famille_id, enfant_id et ip vidés) que la vérification accepte via son
     * empreinte de contenu conservée (cf. psc_audit_row_is_anonymized_shape()).
     * Plus de rechaînage de la table : un coût proportionnel aux seules
     * lignes concernées, et une interruption ne peut plus casser la chaîne.
     */
    private static function forget_rows($where, array $where_args, array $needles, $changes_for) {
        global $wpdb;
        $table = psc_table('audit_log');
        $needles = self::clean_needles($needles);
        $anonymized = 0;
        $last_id = 0;

        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, action, acteur_type, acteur_id, acteur_libelle, pour_le_compte_de, objet_type, objet_id,
                        famille_id, enfant_id, resume, details, ip
                 FROM $table WHERE id > %d AND purgee_le IS NULL AND $where ORDER BY id ASC LIMIT 500",
                array_merge(array($last_id), $where_args)
            ));
            foreach ($rows as $row) {
                $last_id = (int) $row->id;
                $changes = (array) call_user_func($changes_for, $row);
                if (psc_audit_resume_needs_redaction($row->resume, $needles)) {
                    $changes['resume'] = psc_audit_default_resume($row->action);
                    $changes['details'] = null;
                    $changes['famille_id'] = null;
                    $changes['enfant_id'] = null;
                    $changes['ip'] = null;
                }
                if (!$changes) continue;
                $anonymized++;
                $wpdb->update($table, $changes, array('id' => $row->id));
            }
        } while (count($rows) === 500);

        return $anonymized;
    }
}
