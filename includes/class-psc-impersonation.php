<?php
if (!defined('ABSPATH')) exit;

/**
 * Consultation temporaire d'un espace famille par un agent WordPress.
 *
 * Son cookie ne contient aucun secret famille et reste lié à la session
 * WordPress qui l'a ouvert. La ligne en base fait autorité afin qu'une
 * consultation puisse être révoquée immédiatement côté serveur.
 */
class Psc_Impersonation {

    const COOKIE_NAME = 'psc_impersonation';

    /** @var object|null Résultat positif mis en cache pour la requête. */
    private static $active_cache = null;

    /** @var bool Un résultat négatif n'est mémorisé qu'à partir de init. */
    private static $active_cache_set = false;

    public static function init() {
        add_action('admin_post_psc_impersonate_start', array(__CLASS__, 'handle_start'));
        add_action('admin_post_psc_impersonate_stop', array(__CLASS__, 'handle_stop'));
        add_action('wp_logout', array(__CLASS__, 'handle_wp_logout'), 10, 1);
    }

    /** Durée fixe d'une consultation : elle n'est jamais prolongée. */
    private static function ttl() {
        return max(60, (int) apply_filters('psc_impersonation_ttl', 30 * MINUTE_IN_SECONDS));
    }

    /** Empreinte courte de la session WordPress courante. */
    private static function session_hash() {
        return substr(hash('sha256', (string) wp_get_session_token()), 0, 16);
    }

    /** Pose ou retire le cookie distinct de toute session famille. */
    private static function set_cookie($value, $expires) {
        setcookie(
            self::COOKIE_NAME,
            $value,
            array(
                'expires'  => $expires,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );

        if ($value === '') {
            unset($_COOKIE[self::COOKIE_NAME]);
        } else {
            $_COOKIE[self::COOKIE_NAME] = $value;
        }
    }

    public static function clear_cookie() {
        self::set_cookie('', time() - HOUR_IN_SECONDS);
        self::$active_cache = null;
        self::$active_cache_set = true;
    }

    /** Efface un cookie rejeté sans figer un résultat calculé avant init. */
    private static function discard_cookie($cache_negative) {
        self::set_cookie('', time() - HOUR_IN_SECONDS);
        self::$active_cache = null;
        self::$active_cache_set = (bool) $cache_negative;
    }

    /**
     * Décode le cookie et vérifie sa signature avant d'en utiliser les IDs.
     *
     * @return array<string,int|string>|null
     */
    private static function read_cookie() {
        if (empty($_COOKIE[self::COOKIE_NAME])) return null;

        $raw = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME]));
        $parts = explode('|', $raw);
        if (count($parts) !== 6) return null;

        list($id, $wp_user_id, $family_id, $expires, $session_hash, $signature) = $parts;
        if (!ctype_digit($id) || !ctype_digit($wp_user_id) || !ctype_digit($family_id) || !ctype_digit($expires)) {
            return null;
        }

        $payload = $id . '|' . $wp_user_id . '|' . $family_id . '|' . $expires . '|' . $session_hash;
        if (!hash_equals(psc_sign($payload), $signature)) return null;

        return array(
            'id'           => (int) $id,
            'wp_user_id'   => (int) $wp_user_id,
            'family_id'    => (int) $family_id,
            'expires'      => (int) $expires,
            'session_hash' => $session_hash,
        );
    }

    /** Consultation valide pour la session WordPress courante, ou null. */
    public static function active() {
        if (self::$active_cache_set) return self::$active_cache;

        // Avant init, WordPress peut ne pas avoir encore déterminé
        // l'utilisateur courant. Un échec aussi précoce ne doit donc jamais
        // empoisonner le cache statique pour le reste de la requête.
        $cache_negative = did_action('init') || doing_action('init');
        $cookie_present = !empty($_COOKIE[self::COOKIE_NAME]);
        $cookie = self::read_cookie();
        if (!$cookie) {
            if ($cookie_present) self::discard_cookie($cache_negative);
            if ($cache_negative) self::$active_cache_set = true;
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('impersonations') . ' WHERE id = %d',
            $cookie['id']
        ));

        $now_mysql = current_time('mysql');
        $expired = $cookie['expires'] <= time()
            || ($row && $row->expires_at <= $now_mysql);
        if ($expired) {
            if ($row && !$row->ended_at) self::end((int) $row->id, 'expiration');
            self::discard_cookie($cache_negative);
            return null;
        }

        $current_user_id = get_current_user_id();
        $same_user = is_user_logged_in() && $current_user_id === $cookie['wp_user_id'];
        $valid = $same_user
            && current_user_can('psc_impersonate_family')
            && hash_equals((string) $cookie['session_hash'], self::session_hash())
            && $row
            && !$row->ended_at
            && $row->expires_at > $now_mysql
            && (int) $row->wp_user_id === $cookie['wp_user_id']
            && (int) $row->family_id === $cookie['family_id']
            && Psc_Parents::get_by_id($cookie['family_id']);

        if (!$valid) {
            // Un cookie copié hors de la session WordPress d'origine ne doit
            // pas permettre à un tiers de clore la consultation légitime.
            if ($same_user && $row && !$row->ended_at) self::end((int) $row->id, 'revoquee');
            self::discard_cookie($cache_negative);
            return null;
        }

        self::$active_cache = $row;
        self::$active_cache_set = true;
        return self::$active_cache;
    }

    /** Clôt une ligne encore ouverte avec une raison reconnue. */
    public static function end($id, $reason) {
        $reasons = array('manuel', 'expiration', 'deconnexion', 'remplacee', 'revoquee');
        if (!in_array($reason, $reasons, true)) return false;

        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . psc_table('impersonations') . ' SET ended_at = %s, ended_reason = %s WHERE id = %d AND ended_at IS NULL',
            current_time('mysql'),
            $reason,
            absint($id)
        ));
        self::$active_cache = null;
        self::$active_cache_set = true;
        return $updated !== false;
    }

    /** Clôt la consultation courante et renvoie l'identifiant du foyer. */
    public static function stop_current($reason) {
        $active = self::active();
        $family_id = $active ? (int) $active->family_id : 0;
        if ($active) self::end((int) $active->id, $reason);
        self::clear_cookie();
        return $family_id;
    }

    /** Redirection PRG vers le futur écran de confirmation. */
    private static function confirmation_error($family_id, $code) {
        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_impersonate', 'family_id' => absint($family_id), 'psc_msg' => sanitize_key($code)),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function handle_start() {
        if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '') !== 'POST') {
            wp_die(esc_html__('Méthode non autorisée.', 'periscolaire-registration'), '', array('response' => 405));
        }
        check_admin_referer('psc_impersonate_start');
        if (!current_user_can('psc_impersonate_family')) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }

        $family_id = psc_post_int('family_id');
        $family = Psc_Parents::get_by_id($family_id);
        if (!$family) self::confirmation_error($family_id, 'family_invalid');

        $motif_type = sanitize_key(psc_post('motif_type'));
        if (!in_array($motif_type, array('reclamation', 'verification', 'autre'), true)) {
            self::confirmation_error($family_id, 'motif_invalid');
        }

        $motif_detail = psc_post('motif_detail');
        if ($motif_type === 'autre' && (mb_strlen($motif_detail) < 5 || mb_strlen($motif_detail) > 255)) {
            self::confirmation_error($family_id, 'motif_detail_required');
        }
        $motif_detail = $motif_type === 'autre'
            ? mb_substr(sanitize_text_field($motif_detail), 0, 255)
            : null;

        global $wpdb;
        $table = psc_table('impersonations');
        $wp_user_id = get_current_user_id();
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET ended_at = %s, ended_reason = 'remplacee' WHERE wp_user_id = %d AND ended_at IS NULL",
            current_time('mysql'),
            $wp_user_id
        ));

        $ttl = self::ttl();
        $expires = time() + $ttl;
        $inserted = $wpdb->insert($table, array(
            'wp_user_id'  => $wp_user_id,
            'family_id'   => $family_id,
            'motif_type'  => $motif_type,
            'motif_detail'=> $motif_detail,
            'started_at'  => current_time('mysql'),
            'expires_at'  => gmdate('Y-m-d H:i:s', current_time('timestamp') + $ttl),
            'ip'          => psc_client_ip() ?: null,
        ), array('%d', '%d', '%s', '%s', '%s', '%s', '%s'));
        if (!$inserted) self::confirmation_error($family_id, 'impersonation_failed');

        $id = (int) $wpdb->insert_id;
        $session_hash = self::session_hash();
        $payload = $id . '|' . $wp_user_id . '|' . $family_id . '|' . $expires . '|' . $session_hash;
        self::set_cookie($payload . '|' . psc_sign($payload), $expires);
        self::$active_cache = null;
        self::$active_cache_set = false;

        wp_safe_redirect(Psc_Mailer::form_page_url());
        exit;
    }

    public static function handle_stop() {
        if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '') !== 'POST') {
            wp_die(esc_html__('Méthode non autorisée.', 'periscolaire-registration'), '', array('response' => 405));
        }
        check_admin_referer('psc_impersonate_stop');
        if (!current_user_can('psc_impersonate_family')) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }

        $family_id = self::stop_current('manuel');
        if (!$family_id) $family_id = psc_post_int('family_id');

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_parents', 'edit' => $family_id, 'psc_msg' => 'impersonation_stopped'),
            admin_url('admin.php')
        ));
        exit;
    }

    /** La déconnexion WordPress clôt toute ligne ouverte de cet agent. */
    public static function handle_wp_logout($user_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . psc_table('impersonations') . " SET ended_at = %s, ended_reason = 'deconnexion' WHERE wp_user_id = %d AND ended_at IS NULL",
            current_time('mysql'),
            absint($user_id)
        ));
        self::clear_cookie();
    }
}
