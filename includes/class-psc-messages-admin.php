<?php
if (!defined('ABSPATH')) exit;

class Psc_Messages_Admin extends Psc_Admin_Base {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_post_psc_save_message', array(__CLASS__, 'handle_save'));
        add_action('admin_post_psc_send_message', array(__CLASS__, 'handle_send'));
        add_action('admin_post_psc_test_message', array(__CLASS__, 'handle_test'));
        add_action('admin_post_psc_delete_message', array(__CLASS__, 'handle_delete'));
        add_action('admin_post_psc_resend_message', array(__CLASS__, 'handle_resend'));
        add_action('admin_post_psc_export_message', array(__CLASS__, 'handle_export'));
        add_action('wp_ajax_psc_message_count_targets', array(__CLASS__, 'ajax_count_targets'));
        add_action('psc_send_message_emails', array('Psc_Messages', 'process_email_batch'));
        add_action('psc_send_scheduled_messages', array('Psc_Messages', 'send_scheduled'));
        add_action('psc_cleanup_message_receipts', array('Psc_Messages', 'cleanup_receipts'));
    }

    private static function guard_messages($nonce_action) {
        if (!current_user_can('psc_manage_messages')) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        check_admin_referer($nonce_action);
    }

    public static function menu() {
        // Le lien visible est enregistré par Psc_Admin::menu() afin que sa
        // position reste déterministe juste sous le tableau de bord.
        add_submenu_page('psc_dashboard', __('Nouveau message', 'periscolaire-registration'), null, 'psc_manage_messages', 'psc_message_edit', array(__CLASS__, 'page_edit'));
        add_submenu_page('psc_dashboard', __('Suivi du message', 'periscolaire-registration'), null, 'psc_manage_messages', 'psc_message_stats', array(__CLASS__, 'page_stats'));
    }

    public static function assets($hook) {
        if (strpos($hook, 'psc_message') === false) return;
        wp_enqueue_style('psc-messages', PSC_URL . 'assets/css/psc-messages.css', array('psc-admin'), PSC_VERSION);
        wp_enqueue_script('psc-messages-admin', PSC_URL . 'assets/js/psc-messages-admin.js', array('jquery'), PSC_VERSION, true);
        wp_enqueue_media();
        wp_localize_script('psc-messages-admin', 'PSC_MESSAGES', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'countNonce' => wp_create_nonce('psc_message_count_targets'),
            'confirm' => __('Confirmer l’envoi de ce message ? Le ciblage sera figé et le message ne pourra plus être modifié.', 'periscolaire-registration'),
        ));
    }

    private static function posted_data($status) {
        $type = isset($_POST['cible_type']) ? sanitize_key(wp_unslash($_POST['cible_type'])) : 'all';
        $value = null;
        if ($type === 'ecole') $value = array('ecole' => isset($_POST['cible_ecole']) ? sanitize_key(wp_unslash($_POST['cible_ecole'])) : 'elementaire');
        if ($type === 'service') $value = array('service' => isset($_POST['cible_service']) ? sanitize_key(wp_unslash($_POST['cible_service'])) : 'cantine');
        if ($type === 'familles') $value = array('family_ids' => isset($_POST['family_ids']) ? array_map('absint', (array) $_POST['family_ids']) : array());
        return array(
            'id' => isset($_POST['id']) ? absint($_POST['id']) : 0,
            'titre' => isset($_POST['titre']) ? wp_unslash($_POST['titre']) : '',
            'corps' => isset($_POST['corps']) ? wp_unslash($_POST['corps']) : '',
            'categorie' => isset($_POST['categorie']) ? sanitize_key(wp_unslash($_POST['categorie'])) : 'information',
            'statut' => $status, 'cible_type' => $type, 'cible_valeur' => $value,
            'canaux' => array('portail' => true, 'email' => !empty($_POST['canal_email']), 'push' => false),
            'piece_jointe_id' => isset($_POST['piece_jointe_id']) ? absint($_POST['piece_jointe_id']) : 0,
            'epingle' => !empty($_POST['epingle']), 'accuse_requis' => !empty($_POST['accuse_requis']),
            'date_envoi_prevue' => isset($_POST['date_envoi_prevue']) ? sanitize_text_field(wp_unslash($_POST['date_envoi_prevue'])) : null,
        );
    }

    public static function handle_save() {
        self::guard_messages('psc_save_message');
        $existing = Psc_Messages::get(isset($_POST['id']) ? absint($_POST['id']) : 0);
        if ($existing && $existing->statut === 'envoye') {
            $result = Psc_Messages::save(array('id' => (int) $existing->id, 'epingle' => !empty($_POST['epingle'])));
            self::finish_save($result, 'saved');
        }
        $status = isset($_POST['submit_action']) && $_POST['submit_action'] === 'schedule' ? 'programme' : 'brouillon';
        $result = Psc_Messages::save(self::posted_data($status));
        self::finish_save($result, $status === 'programme' ? 'scheduled' : 'saved');
    }

    public static function handle_send() {
        self::guard_messages('psc_send_message');
        // Le nonce WordPress est partagé par plusieurs formulaires pendant sa
        // fenêtre de validité : il ne peut pas identifier un clic précis. Un
        // jeton propre au formulaire bloque le double envoi sans empêcher le
        // message suivant.
        $send_token = isset($_POST['send_token']) ? sanitize_text_field(wp_unslash($_POST['send_token'])) : '';
        if (!$send_token) $send_token = wp_generate_uuid4();
        $key = 'psc_message_send_used_' . hash('sha256', $send_token);
        if (get_transient($key)) self::redirect_edit(0, 'duplicate');
        set_transient($key, 1, DAY_IN_SECONDS);
        $data = self::posted_data('brouillon');
        $result = Psc_Messages::save($data);
        if (!is_wp_error($result)) $result = Psc_Messages::send($result);
        if (is_wp_error($result)) self::redirect_edit($data['id'], $result->get_error_code());
        wp_safe_redirect(add_query_arg(array('page' => 'psc_messages', 'psc_msg' => 'sent'), admin_url('admin.php'))); exit;
    }

    public static function handle_test() {
        self::guard_messages('psc_test_message');
        $data = self::posted_data('brouillon');
        $message = (object) $data;
        $message->id = $data['id'];
        $recipient = (object) array('email' => wp_get_current_user()->user_email, 'token' => '', 'family_id' => 0);
        $ok = Psc_Mailer::send_family_message($message, $recipient, true);
        self::redirect_edit($data['id'], $ok ? 'test_sent' : 'test_failed');
    }

    public static function handle_delete() {
        self::guard_messages('psc_delete_message');
        Psc_Messages::delete(isset($_POST['id']) ? absint($_POST['id']) : 0);
        wp_safe_redirect(add_query_arg(array('page' => 'psc_messages', 'psc_msg' => 'deleted'), admin_url('admin.php'))); exit;
    }

    public static function handle_resend() {
        self::guard_messages('psc_resend_message');
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        Psc_Messages::resend_unread($id);
        wp_safe_redirect(add_query_arg(array('page' => 'psc_message_stats', 'id' => $id, 'psc_msg' => 'resent'), admin_url('admin.php'))); exit;
    }

    private static function finish_save($result, $message) {
        if (is_wp_error($result)) self::redirect_edit(isset($_POST['id']) ? absint($_POST['id']) : 0, $result->get_error_code());
        self::redirect_edit($result, $message);
    }

    private static function redirect_edit($id, $message) {
        wp_safe_redirect(add_query_arg(array('page' => 'psc_message_edit', 'id' => absint($id), 'psc_msg' => sanitize_key($message)), admin_url('admin.php'))); exit;
    }

    public static function ajax_count_targets() {
        if (!current_user_can('psc_manage_messages')) wp_send_json_error(null, 403);
        check_ajax_referer('psc_message_count_targets', 'nonce');
        $type = isset($_POST['cible_type']) ? sanitize_key(wp_unslash($_POST['cible_type'])) : 'all';
        $value = null;
        if ($type === 'ecole') $value = array('ecole' => sanitize_key(wp_unslash($_POST['cible_ecole'] ?? 'elementaire')));
        if ($type === 'service') $value = array('service' => sanitize_key(wp_unslash($_POST['cible_service'] ?? 'cantine')));
        if ($type === 'familles') $value = array('family_ids' => array_map('absint', (array) ($_POST['family_ids'] ?? array())));
        wp_send_json_success(Psc_Messages::count_targets($type, $value));
    }

    public static function page_list() {
        if (!current_user_can('psc_manage_messages')) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;
        $status = isset($_GET['statut']) ? sanitize_key(wp_unslash($_GET['statut'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $messages = Psc_Messages::query(array('statut' => $status, 'search' => $search, 'limit' => 100));
        $year = Psc_School_Years::active();
        $summary = array('sent' => 0, 'rate' => 0, 'families' => 0, 'invalid' => 0);
        if ($year) {
            $start = $year->date_debut . ' 00:00:00';
            $end = $year->date_fin . ' 23:59:59';
            $summary['sent'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . psc_table('messages') . " WHERE statut='envoye' AND date_envoi BETWEEN %s AND %s", $start, $end));
            $summary['families'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT d.family_id) FROM ' . psc_table('message_destinataires') . ' d INNER JOIN ' . psc_table('messages') . " m ON m.id=d.message_id WHERE m.statut='envoye' AND m.date_envoi BETWEEN %s AND %s", $start, $end));
            $summary['rate'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(ROUND(AVG(x.rate)),0) FROM (SELECT 100*SUM(d.vu_le IS NOT NULL)/COUNT(*) rate FROM ' . psc_table('message_destinataires') . ' d INNER JOIN ' . psc_table('messages') . " m ON m.id=d.message_id WHERE m.statut='envoye' AND m.date_envoi BETWEEN %s AND %s GROUP BY d.message_id) x", $start, $end));
            $emails = $wpdb->get_col('SELECT email FROM ' . psc_table('parents') . ' WHERE active=1');
            foreach ($emails as $email) if (!is_email($email)) $summary['invalid']++;
        }
        include PSC_PATH . 'templates/admin-messages-list.php';
    }

    public static function page_edit() {
        if (!current_user_can('psc_manage_messages')) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;
        $message = Psc_Messages::get(isset($_GET['id']) ? absint($_GET['id']) : 0);
        $families = $wpdb->get_results('SELECT id,nom,prenom,email FROM ' . psc_table('parents') . ' WHERE active=1 ORDER BY nom,prenom,email');
        $categories = Psc_Messages::get_categories();
        $target_count = $message ? Psc_Messages::count_targets($message->cible_type, json_decode((string) $message->cible_valeur, true)) : Psc_Messages::count_targets('all');
        include PSC_PATH . 'templates/admin-messages-edit.php';
    }

    public static function page_stats() {
        if (!current_user_can('psc_manage_messages')) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        $message = Psc_Messages::get(isset($_GET['id']) ? absint($_GET['id']) : 0);
        if (!$message || $message->statut !== 'envoye') wp_die(esc_html__('Message introuvable.', 'periscolaire-registration'), '', array('response' => 404));
        $filter = isset($_GET['filter']) ? sanitize_key(wp_unslash($_GET['filter'])) : 'tous';
        $stats = Psc_Messages::get_stats($message->id);
        $recipients = Psc_Messages::get_recipients($message->id, $filter);
        $author = get_userdata($message->auteur_id);
        include PSC_PATH . 'templates/admin-messages-stats.php';
    }

    public static function handle_export() {
        self::guard_messages('psc_export_message');
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $rows = Psc_Messages::get_recipients($id);
        nocache_headers(); header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename=suivi-message-' . $id . '.csv');
        $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array('famille', 'e-mail', 'enfants', 'statut', 'date de lecture', 'canal'), ';');
        foreach ($rows as $row) fputcsv($out, array(psc_csv_escape(trim($row->prenom . ' ' . $row->nom)), psc_csv_escape($row->email), psc_csv_escape(self::children_label($row->family_id)), $row->vu_le ? 'A vu' : 'Non lu', $row->vu_le ?: '', $row->vu_canal ?: ''), ';');
        fclose($out); exit;
    }

    public static function children_label($family_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT c.prenom,cy.classe FROM ' . psc_table('children') . ' c LEFT JOIN ' . psc_table('child_school_years') . ' cy ON cy.child_id=c.id AND cy.school_year_id=%d WHERE c.parent_id=%d AND c.statut=%s ORDER BY c.prenom', Psc_School_Years::active_id(), $family_id, 'actif'));
        return implode(', ', array_map(function ($r) { return $r->prenom . ($r->classe ? ' (' . $r->classe . ')' : ''); }, $rows));
    }
}
