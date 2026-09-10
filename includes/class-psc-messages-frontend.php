<?php
if (!defined('ABSPATH')) exit;

class Psc_Messages_Frontend {
    public static function init() {
        add_action('template_redirect', array(__CLASS__, 'handle_email_link'), 4);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('wp_ajax_nopriv_psc_message_notifications', array(__CLASS__, 'ajax_notifications'));
        add_action('wp_ajax_psc_message_notifications', array(__CLASS__, 'ajax_notifications'));
        add_action('admin_post_nopriv_psc_message_ack', array(__CLASS__, 'handle_ack'));
        add_action('admin_post_psc_message_ack', array(__CLASS__, 'handle_ack'));
    }

    public static function assets() {
        $email_view = isset($_GET['psc_msg'], $_GET['t']);
        $post = is_singular() ? get_post() : null;
        $portal = $post && has_shortcode($post->post_content, 'periscolaire_form') && Psc_Parents::current();
        if (!$email_view && !$portal) return;
        wp_enqueue_style('psc-messages', PSC_URL . 'assets/css/psc-messages.css', $email_view ? array() : array('psc-portal'), PSC_VERSION);
        if ($portal) {
            $parent = Psc_Parents::current();
            $messages = Psc_Messages::for_family((int) $parent->id);
            $known_ids = array();
            foreach ($messages as $message) {
                $channels = json_decode((string) $message->canaux, true);
                if (!empty($channels['push'])) $known_ids[] = (int) $message->id;
            }
            wp_enqueue_script('psc-message-notifications', PSC_URL . 'assets/js/psc-message-notifications.js', array(), PSC_VERSION, true);
            wp_localize_script('psc-message-notifications', 'PSC_MESSAGE_NOTIFICATIONS', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('psc_message_notifications'),
                'parentNonce' => psc_parent_nonce('psc_message_notifications', (int) $parent->id),
                'knownIds' => $known_ids,
                'pollInterval' => 30000,
                'labels' => array(
                    'enable' => __('Activer les notifications', 'periscolaire-registration'),
                    'enabled' => __('Notifications activées', 'periscolaire-registration'),
                    'denied' => __('Notifications bloquées', 'periscolaire-registration'),
                    'source' => __('Nouveau message de la mairie', 'periscolaire-registration'),
                ),
            ));
        }
    }

    /** Messages non lus destinés à la famille de la session courante. */
    public static function ajax_notifications() {
        check_ajax_referer('psc_message_notifications', 'nonce');
        $parent = Psc_Parents::current();
        $parent_nonce = isset($_POST['parent_nonce']) ? sanitize_text_field(wp_unslash($_POST['parent_nonce'])) : '';
        if (!$parent || !psc_verify_parent_nonce('psc_message_notifications', (int) $parent->id, $parent_nonce)) {
            wp_send_json_error(array('code' => 'auth'), 403);
        }
        $items = array();
        foreach (Psc_Messages::for_family((int) $parent->id) as $message) {
            if ($message->vu_le) continue;
            $channels = json_decode((string) $message->canaux, true);
            if (empty($channels['push'])) continue;
            $items[] = array(
                'id' => (int) $message->id,
                'title' => sanitize_text_field($message->titre),
                'body' => wp_trim_words(wp_strip_all_tags($message->corps), 18, '…'),
                'url' => add_query_arg(array('psc_tab' => 'messages', 'message_id' => (int) $message->id), Psc_Mailer::form_page_url()),
            );
            if (count($items) >= 10) break;
        }
        nocache_headers();
        wp_send_json_success(array('messages' => $items));
    }

    public static function data_for_family($family_id, $open_first = true) {
        $messages = Psc_Messages::for_family($family_id);
        $requested = isset($_GET['message_id']) ? absint($_GET['message_id']) : 0;
        $selected = null;
        foreach ($messages as $message) if ((int) $message->id === $requested) { $selected = $message; break; }
        if ($requested && !$selected) {
            wp_die(esc_html__('Ce message ne vous est pas destiné.', 'periscolaire-registration'), '', array('response' => 403));
        }
        if (!$selected && $messages && $open_first) $selected = $messages[0];
        if ($selected) {
            Psc_Messages::mark_seen((int) $selected->id, $family_id, 'portail');
            foreach ($messages as $message) if ((int) $message->id === (int) $selected->id && !$message->vu_le) { $message->vu_le = current_time('mysql'); $message->vu_canal = 'portail'; }
        }
        return array('messages' => $messages, 'selected' => $selected, 'unread' => Psc_Messages::unread_count_for_family($family_id), 'standalone' => false);
    }

    public static function urgent_for_family($family_id) {
        $urgent = null;
        foreach (Psc_Messages::for_family($family_id) as $message) {
            if ($message->categorie !== 'urgent' || $message->vu_le) continue;
            if (!$urgent || strtotime($message->date_envoi) > strtotime($urgent->date_envoi)) $urgent = $message;
        }
        return $urgent;
    }

    public static function handle_email_link() {
        if (!isset($_GET['psc_msg'], $_GET['t']) || !ctype_digit((string) $_GET['psc_msg'])) return;
        $id = absint($_GET['psc_msg']); $token = sanitize_text_field(wp_unslash($_GET['t']));
        $recipient = Psc_Messages::recipient($id, $token);
        if (!$recipient) { wp_safe_redirect(Psc_Mailer::form_page_url()); exit; }
        Psc_Messages::mark_seen($id, (int) $recipient->family_id, 'email');
        $parent = Psc_Parents::current();
        if ($parent && (int) $parent->id === (int) $recipient->family_id) {
            wp_safe_redirect(add_query_arg(array('psc_tab' => 'messages', 'message_id' => $id), Psc_Mailer::form_page_url())); exit;
        }
        $message = Psc_Messages::get($id);
        if (!$message) { wp_safe_redirect(Psc_Mailer::form_page_url()); exit; }
        nocache_headers();
        $psc_messages_data = array('messages' => array($message), 'selected' => $message, 'unread' => 0, 'standalone' => true, 'recipient' => $recipient);
        status_header(200); get_header(); include PSC_PATH . 'templates/frontend-messages.php'; get_footer(); exit;
    }

    public static function handle_ack() {
        check_admin_referer('psc_message_ack');
        $parent = Psc_Parents::current();
        if (!$parent) { wp_safe_redirect(Psc_Mailer::form_page_url()); exit; }
        $id = isset($_POST['message_id']) ? absint($_POST['message_id']) : 0;
        $owned = false;
        foreach (Psc_Messages::for_family($parent->id) as $message) if ((int) $message->id === $id) { $owned = true; break; }
        if (!$owned) wp_die(esc_html__('Ce message ne vous est pas destiné.', 'periscolaire-registration'), '', array('response' => 403));
        Psc_Messages::mark_ack($id, (int) $parent->id);
        wp_safe_redirect(add_query_arg(array('psc_tab' => 'messages', 'message_id' => $id), Psc_Mailer::form_page_url())); exit;
    }
}
