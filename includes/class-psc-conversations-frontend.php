<?php
if (!defined('ABSPATH')) exit;

/**
 * Portail famille : vue « Mes échanges » de l'onglet Messages, et écriture
 * (nouvelle conversation, réponse) — cf. Psc_Conversations pour la logique.
 *
 * Cette classe prépare aussi l'affichage (statut dérivé, dates relatives,
 * extraits) : les gabarits (templates/frontend-conversations.php) restent
 * de la pure mise en forme, sans logique de lecture.
 */
class Psc_Conversations_Frontend extends Psc_Frontend_Base {
    public static function init() {
        add_action('admin_post_nopriv_psc_parent_conversation_create', array(__CLASS__, 'handle_create'));
        add_action('admin_post_psc_parent_conversation_create', array(__CLASS__, 'handle_create'));
        add_action('admin_post_nopriv_psc_parent_conversation_reply', array(__CLASS__, 'handle_reply'));
        add_action('admin_post_psc_parent_conversation_reply', array(__CLASS__, 'handle_reply'));
        add_action('admin_post_nopriv_psc_parent_download_conversation_attachment', array(__CLASS__, 'handle_download_attachment'));
        add_action('admin_post_psc_parent_download_conversation_attachment', array(__CLASS__, 'handle_download_attachment'));
    }

    /**
     * Données de la vue « Mes échanges ». $on_echanges_vue : vrai
     * seulement quand l'onglet Messages est actif ET cette sous-vue
     * sélectionnée — sinon un ?conversation_id= présent par accident dans
     * l'URL d'un autre onglet ne doit ni s'ouvrir, ni en marquer la lecture.
     */
    public static function data_for_family($family_id, $on_echanges_vue) {
        global $wpdb;
        $family_id = (int) $family_id;
        $conversations = Psc_Conversations::list_for_family($family_id);
        $unread = Psc_Conversations::unread_count_for_family($family_id);
        $selected = null;
        $messages = array();
        $reply_target = null;
        $reply_draft = null;
        $filtre = self::current_filter();
        $draft = get_transient('psc_conv_draft_' . $family_id);
        if ($draft) delete_transient('psc_conv_draft_' . $family_id);

        $children_map = self::children_label_map($family_id);
        $conversations = self::prepare_conversations($conversations, $children_map, $filtre);

        if ($on_echanges_vue) {
            $requested = isset($_GET['conversation_id']) ? absint($_GET['conversation_id']) : 0;
            if ($requested) {
                $selected = Psc_Conversations::get_for_family($requested, $family_id);
                if (!$selected) {
                    wp_die(esc_html__('Cette conversation ne vous est pas destinée.', 'periscolaire-registration'), '', array('response' => 403));
                }
                $messages = Psc_Conversations::messages($requested);
                self::prepare_thread($selected, $messages, $children_map);
                Psc_Conversations::mark_read($requested, 'famille');
                $reply_draft_key = 'psc_conv_reply_draft_' . $family_id . '_' . $requested;
                $reply_draft = get_transient($reply_draft_key);
                if ($reply_draft) delete_transient($reply_draft_key);
            } else {
                $reply_message_id = isset($_GET['reply_message_id']) ? absint($_GET['reply_message_id']) : 0;
                if ($reply_message_id) {
                    $message = Psc_Messages::get($reply_message_id);
                    $is_recipient = $message && (bool) $wpdb->get_var($wpdb->prepare(
                        'SELECT id FROM ' . psc_table('message_destinataires') . ' WHERE message_id=%d AND family_id=%d',
                        $reply_message_id,
                        $family_id
                    ));
                    if ($message && $message->statut === 'envoye' && !empty($message->reponses_autorisees) && $is_recipient) {
                        $existing = Psc_Conversations::find_by_family_message($family_id, $reply_message_id);
                        if ($existing) {
                            wp_safe_redirect(add_query_arg(array(
                                'psc_tab' => 'messages', 'psc_vue' => 'echanges', 'conversation_id' => (int) $existing->id,
                            ), Psc_Mailer::form_page_url()));
                            exit;
                        }
                        $reply_target = $message;
                    }
                }
            }
        }

        return array(
            'conversations' => $conversations,
            'unread'        => $unread,
            'filtre'        => $filtre,
            'selected'      => $selected,
            'messages'      => $messages,
            'reply_target'  => $reply_target,
            'draft'         => is_array($draft) ? $draft : null,
            'reply_draft'   => is_string($reply_draft) ? $reply_draft : null,
            'enfants'       => self::children_options($family_id),
            'delai_note'    => psc_conversations_delai_note(),
            'objets'        => self::objets_options(),
        );
    }

    private static function current_filter() {
        $filtre = isset($_GET['psc_conv_filtre']) ? sanitize_key(wp_unslash($_GET['psc_conv_filtre'])) : 'tous';
        return in_array($filtre, array('tous', 'encours', 'clos'), true) ? $filtre : 'tous';
    }

    /**
     * Enrichit chaque ligne d'un statut affichable dérivé (aucune colonne
     * de statut détaillé en base : « lu par la mairie » et « en attente »
     * se distinguent en comparant l'id du dernier message à
     * mairie_dernier_lu_id), d'une date relative courte, d'un extrait du
     * dernier message et des libellés objet/enfant — puis filtre et trie
     * (non closes d'abord, tri de la requête conservé au sein de chaque
     * groupe : un array_merge après partition suffit, la requête est déjà
     * triée par date décroissante).
     */
    private static function prepare_conversations($conversations, $children_map, $filtre) {
        if (!$conversations) return array();

        $ids = wp_list_pluck($conversations, 'id');
        $last_ids = self::last_message_ids($ids);

        $filtered = array();
        foreach ($conversations as $c) {
            if ($filtre === 'encours' && $c->statut === 'close') continue;
            if ($filtre === 'clos' && $c->statut !== 'close') continue;

            $last_id = isset($last_ids[(int) $c->id]) ? $last_ids[(int) $c->id] : 0;
            $status = self::status_for($c, $last_id);
            $last_message = self::last_message_row($c->id);

            $c->display_status_key   = $status['key'];
            $c->display_status_label = $status['label'];
            $c->display_date         = self::relative_date($c->dernier_message_at);
            $c->display_objet_label  = $c->objet ? self::objet_label($c->objet) : '';
            $c->display_enfant_label = isset($children_map[(int) $c->enfant_id]) ? $children_map[(int) $c->enfant_id] : '';
            $c->display_count        = self::message_count($c->id);
            $c->display_count_label  = sprintf(
                _n('%d message', '%d messages', $c->display_count, 'periscolaire-registration'),
                $c->display_count
            );
            if ($last_message) {
                $c->display_author_prefix = $last_message->auteur_type === 'famille'
                    ? __('Vous :', 'periscolaire-registration')
                    : __('Mairie :', 'periscolaire-registration');
                $c->display_excerpt = self::excerpt($last_message->corps, 120);
            } else {
                $c->display_author_prefix = '';
                $c->display_excerpt = '';
            }

            $filtered[] = $c;
        }

        $open = array_values(array_filter($filtered, function ($c) { return $c->statut !== 'close'; }));
        $closed = array_values(array_filter($filtered, function ($c) { return $c->statut === 'close'; }));
        return array_merge($open, $closed);
    }

    /** Statut : close si close ; sinon selon dernier auteur et lecture mairie. */
    private static function status_for($conversation, $last_message_id) {
        if ($conversation->statut === 'close') {
            return array('key' => 'close', 'label' => __('Clos', 'periscolaire-registration'));
        }
        if ($conversation->dernier_auteur === 'mairie') {
            return array('key' => 'recue', 'label' => __('Réponse reçue', 'periscolaire-registration'));
        }
        if ($last_message_id && (int) $conversation->mairie_dernier_lu_id >= $last_message_id) {
            return array('key' => 'lue', 'label' => __('Lu par la mairie', 'periscolaire-registration'));
        }
        return array('key' => 'attente', 'label' => __('En attente de réponse', 'periscolaire-registration'));
    }

    /** Prépare le fil ouvert : statut de l'en-tête + wrapper d'affichage par message. */
    private static function prepare_thread($conversation, $messages, $children_map) {
        $last_id = 0;
        foreach ($messages as $m) $last_id = max($last_id, (int) $m->id);
        $status = self::status_for($conversation, $last_id);

        $conversation->display_status_key   = $status['key'];
        $conversation->display_status_label = $status['label'];
        $conversation->display_objet_label  = $conversation->objet ? self::objet_label($conversation->objet) : '';
        $conversation->display_enfant_label = isset($children_map[(int) $conversation->enfant_id]) ? $children_map[(int) $conversation->enfant_id] : '';
        $conversation->display_ouvert_le    = date_i18n('j F Y', strtotime($conversation->created_at));
        // Aucune donnée de service/interlocuteur nommé en base : le seul
        // interlocuteur réel de cet échange est « le service périscolaire »
        // (cf. Points à confirmer de la tâche de refonte messagerie).
        $conversation->display_interlocuteur = __('Service périscolaire', 'periscolaire-registration');

        foreach ($messages as $m) {
            $m->display_mine = $m->auteur_type === 'famille';
            $m->display_auteur = $m->auteur_type === 'famille'
                ? __('Vous', 'periscolaire-registration')
                : __('Mairie', 'periscolaire-registration');
            $m->display_horodatage = self::relative_date($m->created_at);
            if (!empty($m->piece_jointe_path)) {
                $m->display_attachment_url = wp_nonce_url(
                    add_query_arg(array('action' => 'psc_parent_download_conversation_attachment', 'message_id' => (int) $m->id), admin_url('admin-post.php')),
                    'psc_parent_download_conversation_attachment_' . (int) $m->id
                );
                $m->display_attachment_label = $m->piece_jointe_nom . ($m->piece_jointe_taille ? ' · ' . size_format((int) $m->piece_jointe_taille) : '');
            }
        }
    }

    private static function last_message_ids($conversation_ids) {
        global $wpdb;
        $ids = array_map('absint', $conversation_ids);
        if (!$ids) return array();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT conversation_id, MAX(id) AS last_id FROM ' . psc_table('conversation_messages') . " WHERE conversation_id IN ($placeholders) GROUP BY conversation_id",
            $ids
        ));
        $map = array();
        foreach ($rows as $r) $map[(int) $r->conversation_id] = (int) $r->last_id;
        return $map;
    }

    private static function last_message_row($conversation_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('conversation_messages') . ' WHERE conversation_id=%d ORDER BY id DESC LIMIT 1',
            absint($conversation_id)
        ));
    }

    private static function message_count($conversation_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . psc_table('conversation_messages') . ' WHERE conversation_id=%d',
            absint($conversation_id)
        ));
    }

    /** Date relative courte : « aujourd'hui, 16 h 12 » / « hier, 16 h 12 » / « 11 sept., 20 h 33 » (+ année si différente). */
    private static function relative_date($mysql_datetime) {
        $ts = strtotime((string) $mysql_datetime);
        if (!$ts) return '';
        $now = current_time('timestamp');
        $time = date_i18n('H \h i', $ts);
        $date_str = date_i18n('Y-m-d', $ts);
        if ($date_str === date_i18n('Y-m-d', $now)) {
            return sprintf(__('aujourd’hui, %s', 'periscolaire-registration'), $time);
        }
        if ($date_str === date_i18n('Y-m-d', $now - DAY_IN_SECONDS)) {
            return sprintf(__('hier, %s', 'periscolaire-registration'), $time);
        }
        $same_year = date_i18n('Y', $ts) === date_i18n('Y', $now);
        return ($same_year ? date_i18n('j M', $ts) : date_i18n('j M Y', $ts)) . ', ' . $time;
    }

    /** Aperçu sur une ligne : espaces normalisés, coupé à $chars (texte brut, cf. Psc_Conversations::sanitize_body()). */
    private static function excerpt($text, $chars) {
        $text = preg_replace('/\s+/u', ' ', trim((string) $text));
        return mb_substr((string) $text, 0, $chars);
    }

    private static function objet_labels() {
        return array(
            'cantine'       => __('Cantine', 'periscolaire-registration'),
            'planning'      => __('Planning', 'periscolaire-registration'),
            'facturation'   => __('Facturation', 'periscolaire-registration'),
            'habilitations' => __('Habilitations', 'periscolaire-registration'),
            'autre'         => __('Autre', 'periscolaire-registration'),
        );
    }

    public static function objet_label($key) {
        $labels = self::objet_labels();
        return isset($labels[$key]) ? $labels[$key] : $labels['autre'];
    }

    /** Options de l'écran « Écrire à la mairie » (valeur, libellé) — ordre imposé par la maquette. */
    public static function objets_options() {
        $labels = self::objet_labels();
        $options = array();
        foreach (Psc_Conversations::OBJETS as $key) {
            $options[] = array('value' => $key, 'label' => $labels[$key]);
        }
        return $options;
    }

    /**
     * Enfants actifs du foyer pour le sélecteur « Enfant concerné » du
     * formulaire, avec le bouton générique adapté au nombre d'enfants (cf.
     * tâche de refonte messagerie, §6.1).
     */
    public static function children_options($family_id) {
        $children = self::children_of($family_id, true);
        if (!$children) return array();

        $options = array();
        foreach ($children as $child) {
            $classe = Psc_School_Years::classe_for($child->id);
            $label = $classe !== '' ? sprintf('%s (%s)', $child->prenom, $classe) : $child->prenom;
            $options[] = array('value' => (int) $child->id, 'label' => $label);
        }

        $catch_all = count($children) === 1
            ? __('Aucun', 'periscolaire-registration')
            : (count($children) === 2
                ? __('Les deux / aucun', 'periscolaire-registration')
                : __('Aucun / plusieurs', 'periscolaire-registration'));
        $options[] = array('value' => 0, 'label' => $catch_all);

        return $options;
    }

    /** Libellés « Prénom (classe) » de TOUS les enfants du foyer (actifs ou non), pour l'affichage d'un enfant déjà rattaché à un échange existant. */
    private static function children_label_map($family_id) {
        $map = array();
        foreach (self::children_of($family_id) as $child) {
            $classe = Psc_School_Years::classe_for($child->id);
            $map[(int) $child->id] = $classe !== '' ? sprintf('%s (%s)', $child->prenom, $classe) : $child->prenom;
        }
        return $map;
    }

    /**
     * URL du bouton « Répondre à la mairie » sous une diffusion : vers la
     * conversation existante si la famille a déjà répondu à ce message,
     * sinon vers le formulaire de création pré-rempli.
     */
    public static function reply_url($message, $family_id) {
        $existing = Psc_Conversations::find_by_family_message($family_id, (int) $message->id);
        $args = array('psc_tab' => 'messages', 'psc_vue' => 'echanges');
        $args[$existing ? 'conversation_id' : 'reply_message_id'] = $existing ? (int) $existing->id : (int) $message->id;
        return add_query_arg($args, Psc_Mailer::form_page_url());
    }

    /** Objet déduit de la catégorie de la diffusion (cf. tâche de refonte messagerie, §7). */
    public static function objet_from_categorie($categorie) {
        if ($categorie === 'cantine') return 'cantine';
        if ($categorie === 'periscolaire') return 'planning';
        return 'autre';
    }

    public static function handle_create() {
        $parent = self::authed_parent('psc_parent_conversation_create');
        if (!$parent) self::parent_form_redirect('conversation_auth');

        $message_id = isset($_POST['message_id']) ? absint($_POST['message_id']) : 0;
        $sujet = isset($_POST['sujet']) ? wp_unslash($_POST['sujet']) : '';
        $corps = isset($_POST['corps']) ? wp_unslash($_POST['corps']) : '';
        $objet = isset($_POST['objet']) ? sanitize_key(wp_unslash($_POST['objet'])) : '';
        $enfant_id = isset($_POST['enfant_id']) ? absint($_POST['enfant_id']) : 0;
        if ($enfant_id && !self::owned_child($enfant_id, $parent->id)) $enfant_id = 0;

        $attachment_check = Psc_Conversations::validate_attachment($_FILES['piece_jointe'] ?? null);
        if ($attachment_check !== true) {
            self::redirect_conversation_error($parent->id, $message_id, $sujet, $corps, 'conversation_attachment_' . $attachment_check, $objet, $enfant_id);
        }

        if (!psc_rate_limit('conv_new_' . $parent->id, 5, DAY_IN_SECONDS)) {
            self::redirect_conversation_error($parent->id, $message_id, $sujet, $corps, 'conversation_rate_limited', $objet, $enfant_id);
        }

        $attachment = null;
        if (!empty($_FILES['piece_jointe']['name'])) {
            $stored = Psc_Conversations::store_attachment($_FILES['piece_jointe']);
            if (is_array($stored)) $attachment = $stored;
        }

        $result = Psc_Conversations::create_by_family((int) $parent->id, $sujet, $corps, $message_id ?: null, $objet, $enfant_id ?: null, $attachment);
        if (is_wp_error($result)) {
            self::redirect_conversation_error($parent->id, $message_id, $sujet, $corps, $result->get_error_code(), $objet, $enfant_id);
        }

        delete_transient('psc_conv_draft_' . $parent->id);
        wp_safe_redirect(add_query_arg(array(
            'psc_tab' => 'messages', 'psc_vue' => 'echanges', 'conversation_id' => (int) $result, 'psc_msg' => 'conversation_sent',
        ), Psc_Mailer::form_page_url()) . '#conversation-dernier-message');
        exit;
    }

    public static function handle_reply() {
        $parent = self::authed_parent('psc_parent_conversation_reply');
        if (!$parent) self::parent_form_redirect('conversation_auth');

        $conversation_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        $conversation = Psc_Conversations::get_for_family($conversation_id, (int) $parent->id);
        if (!$conversation) {
            wp_die(esc_html__('Cette conversation ne vous est pas destinée.', 'periscolaire-registration'), '', array('response' => 403));
        }

        $corps = isset($_POST['corps']) ? wp_unslash($_POST['corps']) : '';

        if (!psc_rate_limit('conv_msg_' . $parent->id, 10, HOUR_IN_SECONDS)) {
            wp_safe_redirect(add_query_arg(array(
                'psc_tab' => 'messages', 'psc_vue' => 'echanges', 'conversation_id' => $conversation_id, 'psc_msg' => 'conversation_rate_limited',
            ), Psc_Mailer::form_page_url()));
            exit;
        }

        $attachment = null;
        if (!empty($_FILES['piece_jointe']['name'])) {
            $check = Psc_Conversations::validate_attachment($_FILES['piece_jointe']);
            if ($check === true) {
                $stored = Psc_Conversations::store_attachment($_FILES['piece_jointe']);
                if (is_array($stored)) $attachment = $stored;
            }
        }

        $result = Psc_Conversations::reply($conversation_id, 'famille', $corps, null, $attachment);
        if (is_wp_error($result)) {
            set_transient('psc_conv_reply_draft_' . $parent->id . '_' . $conversation_id, $corps, 5 * MINUTE_IN_SECONDS);
        }
        $msg = is_wp_error($result) ? $result->get_error_code() : 'conversation_sent';
        wp_safe_redirect(add_query_arg(array(
            'psc_tab' => 'messages', 'psc_vue' => 'echanges', 'conversation_id' => $conversation_id, 'psc_msg' => $msg,
        ), Psc_Mailer::form_page_url()) . '#conversation-dernier-message');
        exit;
    }

    /**
     * Sert la pièce jointe d'un message : la propriété de la conversation
     * (famille connectée = destinataire OU auteur du foyer) fait partie de
     * la vérification elle-même, jamais d'un contrôle après coup — même
     * doctrine que get_for_family().
     */
    public static function handle_download_attachment() {
        $parent = Psc_Parents::current();
        if (!$parent) {
            wp_die(esc_html__('Vous devez être connecté pour accéder à ce document.', 'periscolaire-registration'), '', array('response' => 403));
        }

        $message_id = isset($_GET['message_id']) ? absint($_GET['message_id']) : 0;
        check_admin_referer('psc_parent_download_conversation_attachment_' . $message_id);

        global $wpdb;
        $message = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('conversation_messages') . ' WHERE id=%d', $message_id));
        if (!$message || empty($message->piece_jointe_path)) {
            wp_die(esc_html__('Fichier introuvable.', 'periscolaire-registration'), '', array('response' => 404));
        }
        $conversation = Psc_Conversations::get_for_family((int) $message->conversation_id, (int) $parent->id);
        if (!$conversation) {
            wp_die(esc_html__('Ce document ne vous est pas destiné.', 'periscolaire-registration'), '', array('response' => 403));
        }

        Psc_Conversations::stream_attachment($message->piece_jointe_path, $message->piece_jointe_nom);
    }

    private static function redirect_conversation_error($family_id, $message_id, $sujet, $corps, $error_code, $objet = '', $enfant_id = 0) {
        set_transient('psc_conv_draft_' . $family_id, array(
            'sujet' => $sujet, 'corps' => $corps, 'message_id' => $message_id, 'error' => $error_code,
            'objet' => $objet, 'enfant_id' => $enfant_id,
        ), 5 * MINUTE_IN_SECONDS);
        $args = array('psc_tab' => 'messages', 'psc_vue' => 'echanges', 'psc_msg' => $error_code);
        if ($message_id) $args['reply_message_id'] = $message_id;
        else $args['psc_new'] = 1;
        wp_safe_redirect(add_query_arg($args, Psc_Mailer::form_page_url()));
        exit;
    }
}
