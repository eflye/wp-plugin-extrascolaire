<?php
if (!defined('ABSPATH')) exit;

/**
 * Portail famille : vue « Mes échanges » de l'onglet Messages, et écriture
 * (nouvelle conversation, réponse) — cf. Psc_Conversations pour la logique.
 */
class Psc_Conversations_Frontend extends Psc_Frontend_Base {
    public static function init() {
        add_action('admin_post_nopriv_psc_parent_conversation_create', array(__CLASS__, 'handle_create'));
        add_action('admin_post_psc_parent_conversation_create', array(__CLASS__, 'handle_create'));
        add_action('admin_post_nopriv_psc_parent_conversation_reply', array(__CLASS__, 'handle_reply'));
        add_action('admin_post_psc_parent_conversation_reply', array(__CLASS__, 'handle_reply'));
    }

    /**
     * Données de la vue « Mes échanges ». $on_echanges_vue : vrai
     * seulement quand l'onglet Messages est actif ET cette sous-vue
     * sélectionnée — sinon un ?conversation_id= présent par accident dans
     * l'URL d'un autre onglet ne doit ni s'ouvrir, ni en marquer la lecture.
     */
    public static function data_for_family($family_id, $on_echanges_vue) {
        global $wpdb;
        $conversations = Psc_Conversations::list_for_family($family_id);
        $unread = Psc_Conversations::unread_count_for_family($family_id);
        $selected = null;
        $messages = array();
        $reply_target = null;
        $reply_draft = null;
        $draft = get_transient('psc_conv_draft_' . (int) $family_id);
        if ($draft) delete_transient('psc_conv_draft_' . (int) $family_id);

        if ($on_echanges_vue) {
            $requested = isset($_GET['conversation_id']) ? absint($_GET['conversation_id']) : 0;
            if ($requested) {
                $selected = Psc_Conversations::get_for_family($requested, $family_id);
                if (!$selected) {
                    wp_die(esc_html__('Cette conversation ne vous est pas destinée.', 'periscolaire-registration'), '', array('response' => 403));
                }
                $messages = Psc_Conversations::messages($requested);
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
            'selected'      => $selected,
            'messages'      => $messages,
            'reply_target'  => $reply_target,
            'draft'         => is_array($draft) ? $draft : null,
            'reply_draft'   => is_string($reply_draft) ? $reply_draft : null,
        );
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

    public static function handle_create() {
        $parent = self::authed_parent('psc_parent_conversation_create');
        if (!$parent) self::parent_form_redirect('conversation_auth');

        $message_id = isset($_POST['message_id']) ? absint($_POST['message_id']) : 0;
        $sujet = isset($_POST['sujet']) ? wp_unslash($_POST['sujet']) : '';
        $corps = isset($_POST['corps']) ? wp_unslash($_POST['corps']) : '';

        if (!psc_rate_limit('conv_new_' . $parent->id, 5, DAY_IN_SECONDS)) {
            self::redirect_conversation_error($parent->id, $message_id, $sujet, $corps, 'conversation_rate_limited');
        }

        $result = Psc_Conversations::create_by_family((int) $parent->id, $sujet, $corps, $message_id ?: null);
        if (is_wp_error($result)) {
            self::redirect_conversation_error($parent->id, $message_id, $sujet, $corps, $result->get_error_code());
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

        $result = Psc_Conversations::reply($conversation_id, 'famille', $corps);
        if (is_wp_error($result)) {
            set_transient('psc_conv_reply_draft_' . $parent->id . '_' . $conversation_id, $corps, 5 * MINUTE_IN_SECONDS);
        }
        $msg = is_wp_error($result) ? $result->get_error_code() : 'conversation_sent';
        wp_safe_redirect(add_query_arg(array(
            'psc_tab' => 'messages', 'psc_vue' => 'echanges', 'conversation_id' => $conversation_id, 'psc_msg' => $msg,
        ), Psc_Mailer::form_page_url()) . '#conversation-dernier-message');
        exit;
    }

    private static function redirect_conversation_error($family_id, $message_id, $sujet, $corps, $error_code) {
        set_transient('psc_conv_draft_' . $family_id, array(
            'sujet' => $sujet, 'corps' => $corps, 'message_id' => $message_id, 'error' => $error_code,
        ), 5 * MINUTE_IN_SECONDS);
        $args = array('psc_tab' => 'messages', 'psc_vue' => 'echanges', 'psc_msg' => $error_code);
        if ($message_id) $args['reply_message_id'] = $message_id;
        else $args['psc_new'] = 1;
        wp_safe_redirect(add_query_arg($args, Psc_Mailer::form_page_url()));
        exit;
    }
}
