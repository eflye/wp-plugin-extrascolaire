<?php
if (!defined('ABSPATH')) exit;

/**
 * Logique métier des conversations privées famille ↔ mairie.
 *
 * Un concept séparé des diffusions descendantes (Psc_Messages) : une
 * conversation est un fil privé entre UN foyer et la mairie, jamais visible
 * par les autres familles — y compris quand elle est rattachée à une
 * diffusion (message_id).
 */
class Psc_Conversations {
    const STATUSES = array('ouverte', 'close');
    const SIDES = array('famille', 'mairie');

    /* ------------------------------------------------------------------ */
    /* Création                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Écriture d'une famille : soit une conversation libre, soit une
     * réponse à une diffusion qui l'autorise. Si une conversation existe
     * déjà pour cette diffusion et cette famille, le message y est ajouté
     * (reply()) au lieu de créer un doublon — la contrainte UNIQUE
     * (family_id, message_id) fait de ce cas normal, pas d'une erreur.
     */
    public static function create_by_family($family_id, $sujet, $corps, $message_id = null) {
        global $wpdb;
        $family_id = absint($family_id);
        if (!$family_id) return new WP_Error('psc_conversation_family', __('Famille introuvable.', 'periscolaire-registration'));

        $corps = self::sanitize_body($corps);
        if (is_wp_error($corps)) return $corps;

        if ($message_id) {
            $message_id = absint($message_id);
            $message = Psc_Messages::get($message_id);
            if (!$message || $message->statut !== 'envoye' || empty($message->reponses_autorisees)) {
                return new WP_Error('psc_conversation_not_allowed', __('Les réponses ne sont pas autorisées pour ce message.', 'periscolaire-registration'));
            }
            $is_recipient = (bool) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . psc_table('message_destinataires') . ' WHERE message_id=%d AND family_id=%d',
                $message_id,
                $family_id
            ));
            if (!$is_recipient) {
                return new WP_Error('psc_conversation_not_recipient', __('Ce message ne vous est pas destiné.', 'periscolaire-registration'));
            }
            $existing = self::find_by_family_message($family_id, $message_id);
            if ($existing) {
                $result = self::reply((int) $existing->id, 'famille', $corps);
                return is_wp_error($result) ? $result : (int) $existing->id;
            }
            // Le sujet d'une réponse à une diffusion n'est jamais saisi par
            // la famille : il est figé sur le titre de la diffusion.
            $sujet = sprintf(__('Re : %s', 'periscolaire-registration'), $message->titre);
        } else {
            $sujet = self::sanitize_sujet($sujet);
            if (is_wp_error($sujet)) return $sujet;
        }

        return self::insert_conversation($family_id, $message_id ?: null, $sujet, 'famille', $corps, null);
    }

    /** Écriture de la mairie : ouverture d'un échange vers un foyer actif. */
    public static function create_by_mairie($family_id, $sujet, $corps, $wp_user_id) {
        global $wpdb;
        $family_id = absint($family_id);
        $active = (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . psc_table('parents') . ' WHERE id=%d AND active=1', $family_id));
        if (!$active) return new WP_Error('psc_conversation_family', __('Famille introuvable ou inactive.', 'periscolaire-registration'));

        $sujet = self::sanitize_sujet($sujet);
        if (is_wp_error($sujet)) return $sujet;
        $corps = self::sanitize_body($corps);
        if (is_wp_error($corps)) return $corps;

        return self::insert_conversation($family_id, null, $sujet, 'mairie', $corps, absint($wp_user_id));
    }

    private static function insert_conversation($family_id, $message_id, $sujet, $auteur_type, $corps, $wp_user_id) {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->query('START TRANSACTION');

        $ok = $wpdb->insert(psc_table('conversations'), array(
            'family_id'             => $family_id,
            'message_id'            => $message_id,
            'sujet'                 => $sujet,
            'statut'                => 'ouverte',
            'initiee_par'           => $auteur_type,
            'dernier_message_at'    => $now,
            'dernier_auteur'        => $auteur_type,
            'famille_dernier_lu_id' => 0,
            'mairie_dernier_lu_id'  => 0,
            'created_at'            => $now,
            'updated_at'            => $now,
        ));
        if ($ok === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('psc_conversation_db', __('Impossible d’enregistrer la conversation.', 'periscolaire-registration'));
        }
        $conversation_id = (int) $wpdb->insert_id;

        $message_id_inserted = self::insert_message($conversation_id, $auteur_type, $corps, $wp_user_id);
        if (is_wp_error($message_id_inserted)) {
            $wpdb->query('ROLLBACK');
            return $message_id_inserted;
        }

        $pointer_column = $auteur_type === 'famille' ? 'famille_dernier_lu_id' : 'mairie_dernier_lu_id';
        $wpdb->update(psc_table('conversations'), array($pointer_column => $message_id_inserted), array('id' => $conversation_id));
        $wpdb->query('COMMIT');

        self::schedule_notify($conversation_id, $auteur_type === 'famille' ? 'mairie' : 'famille');
        return $conversation_id;
    }

    private static function insert_message($conversation_id, $auteur_type, $corps, $wp_user_id) {
        global $wpdb;
        $ok = $wpdb->insert(psc_table('conversation_messages'), array(
            'conversation_id' => $conversation_id,
            'auteur_type'     => $auteur_type,
            'auteur_user_id'  => $wp_user_id ?: null,
            'corps'           => $corps,
            'created_at'      => current_time('mysql'),
        ));
        if ($ok === false) {
            return new WP_Error('psc_conversation_db', __('Impossible d’enregistrer le message.', 'periscolaire-registration'));
        }
        return (int) $wpdb->insert_id;
    }

    /* ------------------------------------------------------------------ */
    /* Réponses et statut                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Ajoute un message à une conversation existante. Une réponse de la
     * famille sur une conversation close la rouvre automatiquement ; une
     * réponse de la mairie ne rouvre jamais d'elle-même (l'écran propose
     * « Répondre et rouvrir » explicitement).
     */
    public static function reply($conversation_id, $auteur_type, $corps, $wp_user_id = null) {
        global $wpdb;
        $conversation_id = absint($conversation_id);
        if (!in_array($auteur_type, self::SIDES, true)) {
            return new WP_Error('psc_conversation_auteur', __('Type d’auteur invalide.', 'periscolaire-registration'));
        }
        $corps = self::sanitize_body($corps);
        if (is_wp_error($corps)) return $corps;
        if (self::is_duplicate($conversation_id, $auteur_type, $corps)) {
            return new WP_Error('psc_conversation_duplicate', __('Ce message vient déjà d’être envoyé.', 'periscolaire-registration'));
        }

        $wpdb->query('START TRANSACTION');
        $conversation = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('conversations') . ' WHERE id=%d FOR UPDATE', $conversation_id));
        if (!$conversation) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('psc_conversation_missing', __('Conversation introuvable.', 'periscolaire-registration'));
        }

        $message_id = self::insert_message($conversation_id, $auteur_type, $corps, $wp_user_id);
        if (is_wp_error($message_id)) {
            $wpdb->query('ROLLBACK');
            return $message_id;
        }

        $now = current_time('mysql');
        $update = array('dernier_message_at' => $now, 'dernier_auteur' => $auteur_type, 'updated_at' => $now);
        $update[$auteur_type === 'famille' ? 'famille_dernier_lu_id' : 'mairie_dernier_lu_id'] = $message_id;
        if ($auteur_type === 'famille' && $conversation->statut === 'close') {
            $update['statut'] = 'ouverte';
            $update['close_le'] = null;
            $update['close_par'] = null;
        }
        $ok = $wpdb->update(psc_table('conversations'), $update, array('id' => $conversation_id));
        if ($ok === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('psc_conversation_db', __('Impossible d’enregistrer la réponse.', 'periscolaire-registration'));
        }
        $wpdb->query('COMMIT');

        self::schedule_notify($conversation_id, $auteur_type === 'famille' ? 'mairie' : 'famille');
        return $message_id;
    }

    public static function close($id, $wp_user_id) {
        global $wpdb;
        return $wpdb->update(psc_table('conversations'), array(
            'statut'     => 'close',
            'close_le'   => current_time('mysql'),
            'close_par'  => absint($wp_user_id),
            'updated_at' => current_time('mysql'),
        ), array('id' => absint($id))) !== false;
    }

    public static function reopen($id) {
        global $wpdb;
        return $wpdb->update(psc_table('conversations'), array(
            'statut'     => 'ouverte',
            'close_le'   => null,
            'close_par'  => null,
            'updated_at' => current_time('mysql'),
        ), array('id' => absint($id))) !== false;
    }

    /* ------------------------------------------------------------------ */
    /* Lecture                                                             */
    /* ------------------------------------------------------------------ */

    /** Chargement admin, sans vérification de propriétaire. */
    public static function get($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('conversations') . ' WHERE id=%d', $id));
    }

    /**
     * Seul point d'entrée autorisé côté famille : la propriété du foyer
     * fait partie de la clause WHERE elle-même, jamais d'une vérification
     * après coup.
     */
    public static function get_for_family($id, $family_id) {
        global $wpdb;
        $id = absint($id);
        $family_id = absint($family_id);
        if (!$id || !$family_id) return null;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('conversations') . ' WHERE id=%d AND family_id=%d',
            $id,
            $family_id
        ));
    }

    /** Conversation déjà ouverte pour cette famille et cette diffusion, s'il y en a une. */
    public static function find_by_family_message($family_id, $message_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('conversations') . ' WHERE family_id=%d AND message_id=%d',
            absint($family_id),
            absint($message_id)
        ));
    }

    public static function list_for_family($family_id) {
        global $wpdb;
        $family_id = absint($family_id);
        return $wpdb->get_results($wpdb->prepare(
            'SELECT c.*, EXISTS(
                SELECT 1 FROM ' . psc_table('conversation_messages') . ' m
                WHERE m.conversation_id = c.id AND m.auteur_type = %s AND m.id > c.famille_dernier_lu_id
            ) AS non_lu
            FROM ' . psc_table('conversations') . ' c
            WHERE c.family_id = %d
            ORDER BY c.dernier_message_at DESC',
            'mairie',
            $family_id
        ));
    }

    /**
     * Liste paginée pour l'administration. $args : filtre
     * (non_lues|ouvertes|closes|toutes), search (nom/e-mail/sujet),
     * message_id (0 = toutes diffusions), page, per_page (défaut 20).
     */
    public static function query_admin($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array('filtre' => 'toutes', 'search' => '', 'message_id' => 0, 'page' => 1, 'per_page' => 20));

        $where = array('1=1');
        $values = array('famille'); // pour la sous-requête non_lu, toujours en premier paramètre
        if ($args['filtre'] === 'ouvertes') {
            $where[] = "c.statut = 'ouverte'";
        } elseif ($args['filtre'] === 'closes') {
            $where[] = "c.statut = 'close'";
        }
        if ((int) $args['message_id']) {
            $where[] = 'c.message_id = %d';
            $values[] = (int) $args['message_id'];
        }
        if ($args['search'] !== '') {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(p.nom LIKE %s OR p.prenom LIKE %s OR p.email LIKE %s OR c.sujet LIKE %s)';
            array_push($values, $like, $like, $like, $like);
        }

        $base = 'FROM ' . psc_table('conversations') . ' c
            LEFT JOIN ' . psc_table('parents') . ' p ON p.id = c.family_id
            WHERE ' . implode(' AND ', $where);

        $select = 'SELECT c.*, p.nom, p.prenom, p.email, EXISTS(
                SELECT 1 FROM ' . psc_table('conversation_messages') . ' m
                WHERE m.conversation_id = c.id AND m.auteur_type = %s AND m.id > c.mairie_dernier_lu_id
            ) AS non_lu ' . $base;
        $having = $args['filtre'] === 'non_lues' ? ' HAVING non_lu = 1' : '';

        $per_page = max(1, min(100, (int) $args['per_page']));
        $page = max(1, (int) $args['page']);
        $offset = ($page - 1) * $per_page;

        $items = $wpdb->get_results($wpdb->prepare(
            $select . $having . " ORDER BY c.dernier_message_at DESC LIMIT $per_page OFFSET $offset",
            $values
        ));

        if ($args['filtre'] === 'non_lues') {
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ($select $having) x", $values));
        } else {
            $count_values = array_slice($values, 1);
            $count_sql = 'SELECT COUNT(*) ' . $base;
            $total = (int) $wpdb->get_var($count_values ? $wpdb->prepare($count_sql, $count_values) : $count_sql);
        }

        return array('items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page);
    }

    /** Nombre de conversations rattachées à une diffusion (écran de suivi). */
    public static function count_for_message($message_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . psc_table('conversations') . ' WHERE message_id=%d', absint($message_id)));
    }

    public static function messages($conversation_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . psc_table('conversation_messages') . ' WHERE conversation_id=%d ORDER BY id ASC',
            absint($conversation_id)
        ));
    }

    /**
     * Avance le pointeur de lecture du côté donné jusqu'au dernier message.
     * Ne fait rien côté famille pendant une consultation : la lecture d'un
     * agent en mode consultation ne doit jamais faire disparaître le
     * badge « non lu » de la vraie famille.
     */
    public static function mark_read($conversation_id, $side) {
        global $wpdb;
        if (!in_array($side, self::SIDES, true)) return false;
        if ($side === 'famille' && class_exists('Psc_Parents') && Psc_Parents::is_impersonated()) return false;

        $conversation_id = absint($conversation_id);
        $last_id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT MAX(id) FROM ' . psc_table('conversation_messages') . ' WHERE conversation_id=%d',
            $conversation_id
        ));
        if (!$last_id) return false;

        $column = $side === 'famille' ? 'famille_dernier_lu_id' : 'mairie_dernier_lu_id';
        return $wpdb->query($wpdb->prepare(
            'UPDATE ' . psc_table('conversations') . " SET $column = GREATEST($column, %d) WHERE id=%d",
            $last_id,
            $conversation_id
        )) !== false;
    }

    public static function unread_count_for_family($family_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . psc_table('conversations') . ' c WHERE c.family_id=%d AND EXISTS(
                SELECT 1 FROM ' . psc_table('conversation_messages') . ' m
                WHERE m.conversation_id = c.id AND m.auteur_type = %s AND m.id > c.famille_dernier_lu_id
            )',
            absint($family_id),
            'mairie'
        ));
    }

    public static function unread_count_for_mairie() {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . psc_table('conversations') . ' c WHERE EXISTS(
                SELECT 1 FROM ' . psc_table('conversation_messages') . ' m
                WHERE m.conversation_id = c.id AND m.auteur_type = %s AND m.id > c.mairie_dernier_lu_id
            )',
            'famille'
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Suppression et conservation                                        */
    /* ------------------------------------------------------------------ */

    /** Purge explicite avant suppression d'une famille (indépendante de la FK, tolérante à son absence). */
    public static function delete_for_family($family_id) {
        global $wpdb;
        $family_id = absint($family_id);
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . psc_table('conversations') . ' WHERE family_id=%d', $family_id));
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . psc_table('conversation_messages') . " WHERE conversation_id IN ($placeholders)", $ids));
        }
        return $wpdb->delete(psc_table('conversations'), array('family_id' => $family_id));
    }

    /**
     * Purge quotidienne : conversations dont le dernier message dépasse la
     * durée de conservation (filtre psc_conversations_retention_days).
     */
    public static function purge_old() {
        global $wpdb;
        $retention_days = max(1, (int) apply_filters('psc_conversations_retention_days', 395));
        $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - $retention_days * DAY_IN_SECONDS);
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . psc_table('conversations') . ' WHERE dernier_message_at < %s', $cutoff));
        if (!$ids) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . psc_table('conversation_messages') . " WHERE conversation_id IN ($placeholders)", $ids));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . psc_table('conversations') . " WHERE id IN ($placeholders)", $ids));

        Psc_Audit::log('systeme.purge', array(
            'objet_type' => 'conversation',
            'meta' => array('portee' => 'conversations', 'supprimees' => count($ids)),
            'resume' => sprintf(__('%d conversation(s) purgée(s) (rétention).', 'periscolaire-registration'), count($ids)),
        ));

        return count($ids);
    }

    public static function handle_purge_cron() {
        self::purge_old();
    }

    public static function ensure_crons() {
        if (!wp_next_scheduled('psc_purge_conversations')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'psc_purge_conversations');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Notifications (regroupement, cf. Psc_Conversations_Admin/_Frontend) */
    /* ------------------------------------------------------------------ */

    /** Planifie une notification groupée, sauf si une est déjà en attente pour ce côté. */
    private static function schedule_notify($conversation_id, $side) {
        if (!wp_next_scheduled('psc_conversation_notify', array($conversation_id, $side))) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'psc_conversation_notify', array($conversation_id, $side));
        }
    }

    /**
     * Handler de l'événement planifié ci-dessus. N'envoie que si le
     * destinataire a toujours du non lu et si sa dernière notification
     * date de plus de 15 minutes (filtre psc_conversation_notify_interval)
     * — ce qui regroupe plusieurs réponses rapprochées en un seul e-mail.
     * La date de dernière notification est mise à jour dans tous les cas
     * après une tentative d'envoi, pour ne jamais relancer en boucle une
     * adresse en échec (même doctrine que process_email_batch : un échec
     * wp_mail est journalisé sans bloquer).
     */
    public static function notify($conversation_id, $side) {
        global $wpdb;
        if (!in_array($side, self::SIDES, true)) return;
        $conversation = self::get($conversation_id);
        if (!$conversation) return;
        if (!self::has_unread($conversation, $side)) return;

        $notified_column = $side === 'famille' ? 'famille_notifie_le' : 'mairie_notifie_le';
        $notified_at = $conversation->$notified_column;
        $interval = max(0, (int) apply_filters('psc_conversation_notify_interval', 15 * MINUTE_IN_SECONDS));
        if ($notified_at && (current_time('timestamp') - strtotime($notified_at)) < $interval) return;

        Psc_Mailer::send_conversation_notification($conversation, $side);
        $wpdb->update(psc_table('conversations'), array($notified_column => current_time('mysql')), array('id' => (int) $conversation->id));
    }

    /** Vrai s'il existe, dans cette conversation, un message de l'autre côté que $side n'a pas encore lu. */
    private static function has_unread($conversation, $side) {
        global $wpdb;
        $source = $side === 'famille' ? 'mairie' : 'famille';
        $pointer = $side === 'famille' ? (int) $conversation->famille_dernier_lu_id : (int) $conversation->mairie_dernier_lu_id;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT EXISTS(SELECT 1 FROM ' . psc_table('conversation_messages') . ' WHERE conversation_id=%d AND auteur_type=%s AND id > %d)',
            (int) $conversation->id,
            $source,
            $pointer
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Validation                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Le texte brut est déjà déslashé par l'appelant (cf. conventions
     * Psc_Messages_Admin::posted_data()).
     *
     * Volontairement PAS sanitize_textarea_field() : celle-ci appelle
     * wp_strip_all_tags(), qui supprime intégralement un bloc
     * <script>…</script> ou <style>…</style> — contenu compris, pas
     * seulement les balises. Une famille tapant littéralement ce texte
     * (question sur un cours d'informatique, citation…) le verrait
     * disparaître silencieusement. Le principe retenu ici est celui d'un
     * champ texte brut affiché exclusivement via esc_html()/esc_textarea()
     * (jamais wp_kses_post ni echo direct) : la sécurité vient de
     * l'échappement à l'affichage, pas de la mutilation à l'écriture.
     */
    private static function sanitize_body($corps) {
        $corps = trim(wp_check_invalid_utf8((string) $corps));
        $corps = str_replace("\r\n", "\n", $corps);
        if (mb_strlen($corps) < 1 || mb_strlen($corps) > 2000) {
            return new WP_Error('psc_conversation_body', __('Le message doit contenir entre 1 et 2000 caractères.', 'periscolaire-registration'));
        }
        return $corps;
    }

    /** Même principe que sanitize_body() : texte brut préservé, sujet ramené sur une seule ligne. */
    private static function sanitize_sujet($sujet) {
        $sujet = trim(wp_check_invalid_utf8((string) $sujet));
        $sujet = preg_replace('/[\r\n]+/', ' ', $sujet);
        if (mb_strlen($sujet) < 1 || mb_strlen($sujet) > 160) {
            return new WP_Error('psc_conversation_sujet', __('Le sujet doit contenir entre 1 et 160 caractères.', 'periscolaire-registration'));
        }
        return $sujet;
    }

    /** Anti double-soumission : même corps, même côté, même conversation, moins de 30 secondes. */
    private static function is_duplicate($conversation_id, $auteur_type, $corps) {
        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', current_time('timestamp') - 30);
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . psc_table('conversation_messages') . '
             WHERE conversation_id=%d AND auteur_type=%s AND corps=%s AND created_at >= %s',
            absint($conversation_id),
            $auteur_type,
            $corps,
            $since
        )) > 0;
    }
}
