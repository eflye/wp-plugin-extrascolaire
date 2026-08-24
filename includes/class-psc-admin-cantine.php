<?php
if (!defined('ABSPATH')) exit;

/**
 * Menus de cantine et commande au fournisseur.
 */
class Psc_Admin_Cantine extends Psc_Admin_Base {

    public static function init() {
        add_action('admin_post_psc_save_menu', array(__CLASS__, 'handle_save_menu'));
        add_action('admin_post_psc_send_menu', array(__CLASS__, 'handle_send_menu'));
        add_action('admin_post_psc_delete_menu', array(__CLASS__, 'handle_delete_menu'));
        add_action('admin_post_psc_send_supplier_order', array(__CLASS__, 'handle_send_supplier_order'));
        add_action('admin_post_psc_cancel_class_meals', array(__CLASS__, 'handle_cancel_class_meals'));
        add_action('admin_post_psc_dismiss_cancel_class_meals', array(__CLASS__, 'handle_dismiss_cancel_class_meals'));
    }

    public static function handle_save_menu() {
        self::guard('psc_save_menu');

        $id      = psc_post_int('id');
        $semaine = psc_post('semaine_debut');
        $jours   = array();
        foreach (Psc_Menus::JOURS as $jour) {
            // Pas psc_post() ici : sanitize_text_field() collapse les
            // retours à la ligne (conçu pour du texte sur une ligne). Ces
            // champs sont des textarea multi-lignes ; Psc_Menus::save()
            // applique déjà sanitize_textarea_field(), qui les préserve —
            // un seul point de sanitization, sur la bonne fonction.
            $jours[$jour] = isset($_POST[$jour]) ? wp_unslash($_POST[$jour]) : '';
        }

        $result = Psc_Menus::save($id, $semaine, $jours);
        if (is_wp_error($result)) {
            self::redirect('psc_menus', 'invalid');
        }
        self::redirect_to_menu(psc_week_start($semaine), 'saved');
    }

    public static function handle_send_menu() {
        self::guard('psc_send_menu');

        $id   = psc_post_int('id');
        $menu = Psc_Menus::get($id);
        if (!$menu) self::redirect('psc_menus', 'invalid');

        $count = Psc_Menus::send($menu);
        self::redirect_to_menu($menu->semaine_debut, $count > 0 ? 'sent' : 'sent_zero');
    }

    public static function handle_delete_menu() {
        self::guard('psc_delete_menu');
        $id = psc_post_int('id');
        if ($id) Psc_Menus::delete($id);
        self::redirect('psc_menus', 'deleted');
    }

    private static function redirect_to_menu($semaine, $msg) {
        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_menus', 'semaine_debut' => $semaine, 'psc_msg' => $msg),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function page_menus() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));

        // Une semaine = un menu (Psc_Menus::save() les fusionne déjà par
        // semaine) : le formulaire est ancré sur la semaine, pas sur un id.
        // Par défaut, la prochaine semaine ayant au moins un jour d'école
        // ouvert — ce formulaire sert à préparer le menu à venir, pas à
        // consulter l'historique (la liste ci-dessous s'en charge).
        $requested   = isset($_GET['semaine_debut']) ? psc_valid_date(wp_unslash($_GET['semaine_debut'])) : false;
        $target_week = $requested
            ? psc_week_start($requested)
            : Psc_Menus::next_open_week(gmdate('Y-m-d', strtotime('+7 days')));

        $open_days = Psc_Menus::open_days($target_week);
        $editing   = Psc_Menus::get_by_week($target_week);

        $recent  = Psc_Menus::recent(12);
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';

        include PSC_PATH . 'templates/admin-menus.php';
    }

    public static function handle_send_supplier_order() {
        self::guard('psc_send_supplier_order');

        $semaine = psc_post('semaine_debut');
        $result  = Psc_Supplier_Orders::send($semaine);

        if (is_wp_error($result)) {
            $known = array('psc_invalid_week', 'psc_no_supplier_email', 'psc_mail_failed');
            $msg   = in_array($result->get_error_code(), $known, true) ? $result->get_error_code() : 'error';
            wp_safe_redirect(add_query_arg(
                array('page' => 'psc_supplier_orders', 'semaine_debut' => $semaine, 'psc_msg' => $msg),
                admin_url('admin.php')
            ));
            exit;
        }

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_supplier_orders', 'semaine_debut' => $semaine, 'psc_msg' => 'sent'),
            admin_url('admin.php')
        ));
        exit;
    }

    protected static function pending_cantine_key() {
        return 'psc_pending_cantine_' . get_current_user_id();
    }

    /**
     * Annulation de la cantine pour une classe entière un jour donné
     * (sortie scolaire...). Même logique avertissement -> confirmation
     * que Psc_Admin::handle_close_school_day() : rien n'est supprimé au
     * premier passage si des inscriptions existent, l'admin doit
     * confirmer explicitement après avoir vu qui est concerné.
     */
    public static function handle_cancel_class_meals() {
        self::guard('psc_cancel_class_meals');

        $date    = psc_valid_date(psc_post('date'));
        $classe  = psc_post('classe');
        $reason  = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';
        $confirm = psc_post_int('confirm');

        if (!$date) {
            self::redirect('psc_supplier_orders', 'cantine_invalid');
        }
        if ($reason === '') {
            self::redirect('psc_supplier_orders', 'cantine_reason_required');
        }

        $affected = Psc_Supplier_Orders::cantine_registrations_for_class_day($date, $classe);
        if (empty($affected)) {
            self::redirect('psc_supplier_orders', 'cantine_none');
        }

        if (!$confirm) {
            set_transient(
                self::pending_cantine_key(),
                array('date' => $date, 'classe' => $classe, 'reason' => $reason),
                10 * MINUTE_IN_SECONDS
            );
            self::redirect('psc_supplier_orders', 'cantine_confirm_needed');
        }

        delete_transient(self::pending_cantine_key());
        $result = Psc_Supplier_Orders::cancel_class_meals($date, $classe, $reason);
        if (is_wp_error($result)) {
            self::redirect('psc_supplier_orders', 'cantine_invalid');
        }

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_supplier_orders', 'psc_msg' => 'cantine_cancelled', 'n' => (int) $result),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function handle_dismiss_cancel_class_meals() {
        self::guard('psc_dismiss_cancel_class_meals');
        delete_transient(self::pending_cantine_key());
        self::redirect('psc_supplier_orders', 'cantine_dismissed');
    }

    public static function page_supplier_orders() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));

        $requested     = isset($_GET['semaine_debut']) ? sanitize_text_field(wp_unslash($_GET['semaine_debut'])) : '';
        $semaine_debut = psc_week_start($requested) ?: psc_next_open_week(gmdate('Y-m-d', strtotime('+7 days')));

        $preview = Psc_Supplier_Orders::compute_counts($semaine_debut);
        $recent  = Psc_Supplier_Orders::recent(20);
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        $cantine_n = psc_get_int('n');

        $pending_cantine = get_transient(self::pending_cantine_key());
        $pending_cantine_affected = $pending_cantine
            ? Psc_Supplier_Orders::cantine_registrations_for_class_day($pending_cantine['date'], $pending_cantine['classe'])
            : array();

        include PSC_PATH . 'templates/admin-supplier-orders.php';
    }
}
