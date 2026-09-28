<?php
if (!defined('ABSPATH')) exit;

/**
 * Jetons de connexion portés par un e-mail de notification (schéma
 * 4.19.0) — table psc_login_tokens.
 *
 * Un e-mail « nouveau message de la mairie » contient un bouton qui ouvre
 * la session de la famille et l'amène sur la conversation, sans passer par
 * la demande d'un lien. Chaque e-mail a son propre jeton : il n'écrase pas
 * un lien de connexion demandé entre-temps (parents.token_hash).
 *
 * Même doctrine que les liens de connexion (Psc_Parents::maybe_consume_token) :
 * jeton aléatoire stocké haché ; réutilisable jusqu'à expiration, parce
 * que les passerelles de sécurité des messageries suivent les liens avant
 * la famille ; la session ouverte reste le contrôle d'accès effectif. Il
 * disparaît quand la famille révoque ses sessions (psc_bump_session_epoch),
 * à l'expiration (purge quotidienne) et avec la famille (clé étrangère).
 *
 * La cible est un identifiant (« conversation:123 »), jamais une URL :
 * aucune redirection ne peut être détournée.
 */
class Psc_Login_Tokens {

    const MOTIF_ECHANGE = 'notification_echange';

    /** Durée de validité d'un jeton de notification (72 h, filtrable). */
    public static function ttl() {
        return (int) apply_filters('psc_notification_login_ttl', 72 * HOUR_IN_SECONDS);
    }

    public static function init() {
        add_action('psc_cleanup_requests', array(__CLASS__, 'purge_expired'));
    }

    /**
     * Crée un jeton et renvoie sa valeur en clair (à placer dans l'e-mail,
     * jamais stockée), ou null en cas d'échec.
     */
    public static function issue($parent_id, $email, $motif, $cible = null) {
        global $wpdb;
        $token = bin2hex(random_bytes(32));
        $ok = $wpdb->insert(psc_table('login_tokens'), array(
            'parent_id'  => (int) $parent_id,
            'email'      => mb_substr(strtolower((string) $email), 0, 191),
            'token_hash' => psc_hash_token($token),
            'motif'      => (string) $motif,
            'cible'      => $cible !== null ? mb_substr((string) $cible, 0, 64) : null,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::ttl()),
            'created_at' => current_time('mysql'),
        ));
        return $ok ? $token : null;
    }

    /**
     * Ligne valide pour ce jeton (non expiré, famille active), ou WP_Error
     * 'bad_token' / 'expired_token'. Marque l'usage.
     */
    public static function consume($token) {
        global $wpdb;
        $t = psc_table('login_tokens');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE token_hash = %s", psc_hash_token((string) $token)));
        if (!$row || !Psc_Parents::get_by_id((int) $row->parent_id)) {
            return new WP_Error('bad_token', __('Jeton inconnu.', 'periscolaire-registration'));
        }
        if (strtotime($row->expires_at . ' UTC') < time()) {
            return new WP_Error('expired_token', __('Jeton expiré.', 'periscolaire-registration'), $row);
        }
        $wpdb->update($t, array('last_used_at' => current_time('mysql')), array('id' => (int) $row->id));
        return $row;
    }

    /** « conversation:123 » → 123, sinon 0. */
    public static function conversation_of($cible) {
        return preg_match('/^conversation:(\d+)$/', (string) $cible, $m) ? (int) $m[1] : 0;
    }

    /**
     * Page d'arrivée après connexion par un jeton : la conversation visée si
     * elle appartient à la famille du jeton, sinon la liste de ses échanges.
     */
    public static function landing_args($row) {
        $args = array('psc_tab' => 'messages', 'psc_vue' => 'echanges');
        $conversation_id = self::conversation_of($row->cible);
        $conversation = $conversation_id && class_exists('Psc_Conversations') ? Psc_Conversations::get($conversation_id) : null;
        if ($conversation && (int) $conversation->family_id === (int) $row->parent_id) {
            $args['conversation_id'] = $conversation_id;
        }
        return $args;
    }

    /** Révocation des sessions d'une famille : ses jetons disparaissent aussi. */
    public static function forget_parent($parent_id) {
        global $wpdb;
        $wpdb->delete(psc_table('login_tokens'), array('parent_id' => (int) $parent_id));
    }

    /** Purge quotidienne des jetons expirés. */
    public static function purge_expired() {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . psc_table('login_tokens') . ' WHERE expires_at < %s', gmdate('Y-m-d H:i:s')
        ));
    }
}
