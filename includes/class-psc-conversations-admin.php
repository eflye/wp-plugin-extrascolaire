<?php
if (!defined('ABSPATH')) exit;

/** Administration des conversations privées famille ↔ mairie. */
class Psc_Conversations_Admin extends Psc_Admin_Base {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_psc_conversation_reply', array(__CLASS__, 'handle_reply'));
        add_action('admin_post_psc_conversation_close', array(__CLASS__, 'handle_close'));
        add_action('admin_post_psc_conversation_reopen', array(__CLASS__, 'handle_reopen'));
        add_action('admin_post_psc_conversation_create', array(__CLASS__, 'handle_create'));
        add_action('psc_conversation_notify', array('Psc_Conversations', 'notify'), 10, 2);
        add_action('psc_purge_conversations', array('Psc_Conversations', 'handle_purge_cron'));
    }

    private static function guard_conversations($nonce_action) {
        if (!current_user_can('psc_manage_messages')) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        check_admin_referer($nonce_action);
    }

    /** Écran de détail, sans lien de menu visible (même pattern que Psc_Messages_Admin::menu()). */
    public static function menu() {
        add_submenu_page('psc_dashboard', __('Conversation', 'periscolaire-registration'), null, 'psc_manage_messages', 'psc_conversation', array(__CLASS__, 'page_detail'));
    }

    public static function page_list() {
        if (!current_user_can('psc_manage_messages')) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;
        $filtre = isset($_GET['filtre']) ? sanitize_key($_GET['filtre']) : 'toutes';
        if (!in_array($filtre, array('non_lues', 'ouvertes', 'closes', 'toutes'), true)) $filtre = 'toutes';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $message_id = isset($_GET['message_id']) ? absint($_GET['message_id']) : 0;
        $result = Psc_Conversations::query_admin(array('filtre' => $filtre, 'search' => $search, 'message_id' => $message_id, 'page' => $page));
        $source_message = $message_id ? Psc_Messages::get($message_id) : null;
        $new_family_id = isset($_GET['psc_new_family']) ? absint($_GET['psc_new_family']) : 0;
        $families = $wpdb->get_results('SELECT id,nom,prenom,email FROM ' . psc_table('parents') . ' WHERE active=1 ORDER BY nom,prenom,email');
        include PSC_PATH . 'templates/admin-conversations-list.php';
    }

    public static function page_detail() {
        if (!current_user_can('psc_manage_messages')) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $conversation = Psc_Conversations::get($id);
        if (!$conversation) wp_die(esc_html__('Conversation introuvable.', 'periscolaire-registration'), '', array('response' => 404));
        $family = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('parents') . ' WHERE id=%d', (int) $conversation->family_id));
        $source_message = $conversation->message_id ? Psc_Messages::get($conversation->message_id) : null;
        $messages = Psc_Conversations::messages($id);
        Psc_Conversations::mark_read($id, 'mairie');
        include PSC_PATH . 'templates/admin-conversation.php';
    }

    public static function handle_reply() {
        self::guard_conversations('psc_conversation_reply');
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $conversation = Psc_Conversations::get($id);
        if (!$conversation) wp_die(esc_html__('Conversation introuvable.', 'periscolaire-registration'), '', array('response' => 404));

        $corps = isset($_POST['corps']) ? wp_unslash($_POST['corps']) : '';
        $variant = isset($_POST['submit_action']) ? sanitize_key($_POST['submit_action']) : 'reply';
        $result = Psc_Conversations::reply($id, 'mairie', $corps, get_current_user_id());
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg(array('page' => 'psc_conversation', 'id' => $id, 'psc_msg' => $result->get_error_code()), admin_url('admin.php')));
            exit;
        }
        if ($variant === 'reply_close') Psc_Conversations::close($id, get_current_user_id());
        if ($variant === 'reply_reopen') Psc_Conversations::reopen($id);

        wp_safe_redirect(add_query_arg(array('page' => 'psc_conversation', 'id' => $id, 'psc_msg' => 'conversation_sent'), admin_url('admin.php')) . '#conversation-dernier-message');
        exit;
    }

    public static function handle_close() {
        self::guard_conversations('psc_conversation_close');
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        Psc_Conversations::close($id, get_current_user_id());
        wp_safe_redirect(add_query_arg(array('page' => 'psc_conversation', 'id' => $id, 'psc_msg' => 'conversation_closed'), admin_url('admin.php')));
        exit;
    }

    public static function handle_reopen() {
        self::guard_conversations('psc_conversation_reopen');
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        Psc_Conversations::reopen($id);
        wp_safe_redirect(add_query_arg(array('page' => 'psc_conversation', 'id' => $id, 'psc_msg' => 'conversation_reopened'), admin_url('admin.php')));
        exit;
    }

    public static function handle_create() {
        self::guard_conversations('psc_conversation_create');
        $family_id = isset($_POST['family_id']) ? absint($_POST['family_id']) : 0;
        $sujet = isset($_POST['sujet']) ? wp_unslash($_POST['sujet']) : '';
        $corps = isset($_POST['corps']) ? wp_unslash($_POST['corps']) : '';
        $result = Psc_Conversations::create_by_mairie($family_id, $sujet, $corps, get_current_user_id());
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg(array('page' => 'psc_conversations', 'psc_new_family' => $family_id, 'psc_msg' => $result->get_error_code()), admin_url('admin.php')) . '#psc-conversation-new');
            exit;
        }
        wp_safe_redirect(add_query_arg(array('page' => 'psc_conversation', 'id' => (int) $result, 'psc_msg' => 'conversation_sent'), admin_url('admin.php')));
        exit;
    }
}
