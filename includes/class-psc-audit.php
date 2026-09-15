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
        add_action('psc_purge_audit_log', array(__CLASS__, 'purge_expired'), 10, 0);
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

        $horodatage = gmdate('Y-m-d H:i:s');
        $previous_hash = (string) get_option('psc_audit_last_hash', '');
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

        $resultat = in_array($args['resultat'], array('succes', 'refus', 'erreur', 'tentative'), true) ? $args['resultat'] : 'succes';

        $row = array(
            'horodatage'         => $horodatage,
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
            'empreinte'          => $empreinte,
        );

        $inserted = $wpdb->insert(psc_table('audit_log'), $row);
        if ($inserted === false) {
            self::record_failure($action_code, $wpdb->last_error);
            return;
        }
        update_option('psc_audit_last_hash', $empreinte, false);

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

        // La session famille ne distingue pas titulaire et second parent :
        // l'acteur est le foyer, on ne devine jamais lequel des deux agit.
        if (class_exists('Psc_Parents')) {
            $parent = Psc_Parents::current();
            if ($parent) {
                $libelle = trim((string) $parent->nom) !== ''
                    ? sprintf('%s (%s)', $parent->nom, $parent->email)
                    : $parent->email;
                return array('type' => 'famille', 'id' => (int) $parent->id, 'libelle' => $libelle, 'pour_le_compte_de' => null);
            }
        }

        // Écran intervenants : code partagé, aucune identité individuelle
        // n'est disponible — ne pas en inventer une.
        if (class_exists('Psc_Sidscm') && method_exists('Psc_Sidscm', 'is_authenticated_request') && Psc_Sidscm::is_authenticated_request()) {
            return array('type' => 'intervenant', 'id' => null, 'libelle' => __('écran intervenants', 'periscolaire-registration'), 'pour_le_compte_de' => null);
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

    private static function record_failure($action_code, $message) {
        $count = (int) get_option('psc_audit_failures', 0);
        update_option('psc_audit_failures', $count + 1, false);

        $entry = wp_json_encode(array(
            'horodatage' => gmdate('Y-m-d H:i:s'),
            'action'     => (string) $action_code,
            'erreur'     => (string) $message,
        )) . "\n";
        $path = function_exists('psc_private_path') ? psc_private_path('journal-acces.log') : false;
        if ($path) @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }

    /* ------------------------------------------------------------------ */
    /* Lecture                                                             */
    /* ------------------------------------------------------------------ */

    private static function build_where($args) {
        global $wpdb;
        $where = array('1=1');
        $values = array();

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
        global $wpdb;
        $limit = max(2, (int) $limit);
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM (SELECT * FROM ' . psc_table('audit_log') . ' ORDER BY id DESC LIMIT %d) x ORDER BY id ASC',
            $limit + 1
        ));
        return psc_audit_verify_chain_rows($rows);
    }

    /* ------------------------------------------------------------------ */
    /* Conservation, suppression, oubli (étape 6)                          */
    /* ------------------------------------------------------------------ */

    /**
     * Purge quotidienne : les lignes plus vieilles que la durée de
     * rétention de leur niveau (options psc_audit_retention_{niveau}, cf.
     * psc_audit_retention_days()) sont supprimées par lots de 1000, avec
     * une limite de temps par exécution — une table de plusieurs centaines
     * de milliers de lignes ne doit jamais bloquer le cron ; ce qui n'est
     * pas traité aujourd'hui le sera au passage suivant.
     *
     * Une ligne purgée est réellement supprimée, pas anonymisée : sa durée
     * de rétention a expiré, il n'y a plus de raison de la garder même
     * sous forme anonyme (contrairement à forget_family(), déclenchée par
     * la suppression d'une famille avant l'expiration normale).
     *
     * Supprimer les lignes les plus anciennes ne casse pas la chaîne
     * d'intégrité : verify_chain() traite par construction la plus
     * ancienne ligne d'une fenêtre comme une ancre non vérifiable (cf. sa
     * note) — après purge, la nouvelle plus ancienne ligne survivante
     * devient simplement cette ancre, sans déclencher de fausse alerte.
     *
     * Mais ceci suppose de ne JAMAIS créer de trou au milieu de la
     * chaîne : les niveaux ayant des rétentions différentes, une ligne
     * "volumineux" (180 jours) peut expirer alors qu'une ligne "critique"
     * (1095 jours) qui la précède ou la suit de peu ne l'est pas encore —
     * la supprimer isolément casserait le chaînage de tout ce qui la
     * suit, définitivement. C'est pourquoi la purge n'avance que par
     * préfixe contigu, par id croissant : elle s'arrête dès la première
     * ligne encore valide, même si des lignes expirées existent plus
     * loin dans la table — elles seront purgées au(x) passage(s)
     * suivant(s), une fois que la ligne qui les bloque aura elle-même expiré.
     */
    public static function purge_expired($time_limit_seconds = 20) {
        global $wpdb;
        $started = microtime(true);
        $table = psc_table('audit_log');
        $total_deleted = 0;
        $now = time();

        while ((microtime(true) - $started) <= $time_limit_seconds) {
            $rows = $wpdb->get_results("SELECT id, action, horodatage FROM $table ORDER BY id ASC LIMIT 1000");
            if (!$rows) break;

            $expired_ids = array();
            foreach ($rows as $row) {
                $days = psc_audit_retention_days(psc_audit_niveau_for_action($row->action));
                $cutoff = gmdate('Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS);
                if ($row->horodatage >= $cutoff) break; // première ligne encore valide : arrêt du préfixe
                $expired_ids[] = (int) $row->id;
            }
            if (!$expired_ids) break;

            $wpdb->query('DELETE FROM ' . $table . ' WHERE id IN (' . implode(',', $expired_ids) . ')');
            $total_deleted += count($expired_ids);

            if (count($expired_ids) < count($rows)) break; // le lot contenait une ligne encore valide : rien de plus à faire
        }

        if ($total_deleted > 0) {
            self::log('audit.purge', array(
                'meta'   => array('lignes_supprimees' => $total_deleted),
                'resume' => sprintf(__('%d ligne(s) du journal d’audit supprimée(s) (durée de rétention dépassée).', 'periscolaire-registration'), $total_deleted),
            ));
        }

        return $total_deleted;
    }

    /**
     * Anonymise (sans les supprimer) les lignes concernant une famille
     * supprimée : famille_id/enfant_id -> NULL, details effacés,
     * acteur_libelle -> « famille supprimée #id » pour les lignes où la
     * famille est elle-même l'acteur (connexion, etc.), et resume réécrit
     * en version générique s'il nomme la famille ($needles : nom, prénom,
     * e-mail, et ceux de ses enfants — à fournir par l'appelant, AVANT que
     * les lignes correspondantes des tables métier ne soient supprimées).
     *
     * Aucune de ces colonnes n'entre dans le calcul de l'empreinte (cf.
     * psc_audit_compute_hash()) SAUF resume : le réécrire quand il nomme
     * la famille impose de recalculer l'empreinte de cette ligne, ce qui
     * invalide à son tour l'empreinte de toutes les lignes suivantes
     * (chacune est chaînée à la précédente, sans distinction de famille) —
     * cette méthode rejoue donc le calcul jusqu'à la ligne la plus
     * récente de la table entière, pas seulement celles de cette famille.
     * Une opération rare (suppression de famille), jamais partielle : pas
     * de limite de temps ici, contrairement à purge_expired().
     *
     * À appeler avant la suppression effective de la famille et de ses
     * enfants (Psc_Admin_Familles::handle_delete_family()), pendant que
     * leurs noms sont encore disponibles pour $needles.
     *
     * @return int Nombre de lignes dont l'identité a été effacée.
     */
    public static function forget_family($family_id, array $needles = array()) {
        global $wpdb;
        $family_id = (int) $family_id;
        if ($family_id <= 0) return 0;
        $table = psc_table('audit_log');

        $first_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(id) FROM $table WHERE famille_id = %d OR (acteur_type = 'famille' AND acteur_id = %d)",
            $family_id, $family_id
        ));
        if (!$first_id) return 0;

        $previous = $wpdb->get_row($wpdb->prepare(
            "SELECT empreinte FROM $table WHERE id < %d ORDER BY id DESC LIMIT 1", $first_id
        ));
        $previous_hash = $previous ? (string) $previous->empreinte : '';
        $anonymized = 0;
        $last_id = $first_id - 1;
        $rows = array();

        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE id > %d ORDER BY id ASC LIMIT 500", $last_id
            ));
            foreach ($rows as $row) {
                $is_self = $row->acteur_type === 'famille' && (int) $row->acteur_id === $family_id;
                $is_touched = ((int) $row->famille_id === $family_id) || $is_self;
                $resume = $row->resume;

                if ($is_touched) {
                    $anonymized++;
                    if (psc_audit_resume_needs_redaction($resume, $needles)) {
                        $resume = psc_audit_default_resume($row->action);
                    }
                    $wpdb->update($table, array(
                        'famille_id'     => null,
                        'enfant_id'      => null,
                        'acteur_libelle' => $is_self
                            ? sprintf(__('famille supprimée #%d', 'periscolaire-registration'), $family_id)
                            : $row->acteur_libelle,
                        'details'        => null,
                        'resume'         => $resume,
                    ), array('id' => $row->id));
                }

                $empreinte = psc_audit_compute_hash(
                    $previous_hash, $row->horodatage, $row->action, $row->acteur_type, $row->acteur_id,
                    $row->objet_type, $row->objet_id, $resume
                );
                if ($empreinte !== $row->empreinte) {
                    $wpdb->update($table, array('empreinte' => $empreinte), array('id' => $row->id));
                }
                $previous_hash = $empreinte;
                $last_id = (int) $row->id;
            }
        } while (count($rows) === 500);

        update_option('psc_audit_last_hash', $previous_hash, false);

        return $anonymized;
    }
}
