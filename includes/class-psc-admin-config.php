<?php
if (!defined('ABSPATH')) exit;

/**
 * Réglages de l'extension et modèles d'e-mails.
 */
class Psc_Admin_Config extends Psc_Admin_Base {

    public static function init() {
        add_action('admin_post_psc_save_settings', array(__CLASS__, 'handle_save_settings'));
        add_action('admin_post_psc_save_email_templates', array(__CLASS__, 'handle_save_email_templates'));
        add_action('admin_post_psc_reset_email_template', array(__CLASS__, 'handle_reset_email_template'));
        add_action('admin_post_psc_reset_email_templates', array(__CLASS__, 'handle_reset_email_templates'));
    }

    public static function handle_save_settings() {
        self::guard('psc_save_settings');

        $prices = array();
        foreach (psc_allowed_services() as $code) {
            $raw = isset($_POST['price_' . $code]) ? wp_unslash($_POST['price_' . $code]) : '0';
            $val = floatval(str_replace(',', '.', sanitize_text_field($raw)));
            $prices[$code] = max(0, min(1000, $val));
        }
        update_option('psc_service_prices', $prices);

        // Délai de prévenance, borné pour éviter une valeur absurde.
        $hours = psc_post_int('lock_hours', 48);
        update_option('psc_lock_hours', max(0, min(720, $hours)));

        // Variante de l'écran Planning exposée aux familles (les deux sont
        // livrées en parallèle ; retirer l'écran non retenu sans redéploiement).
        $variant = psc_post('planning_variant', 'both');
        update_option('psc_planning_variant', in_array($variant, array('both', '1', '2'), true) ? $variant : 'both');

        update_option('psc_notify_mairie', isset($_POST['notify_mairie']) ? 1 : 0);
        update_option('psc_auto_approve_requests', isset($_POST['auto_approve_requests']) ? 1 : 0);

        // Durées de validité des liens envoyés par e-mail, bornées pour
        // éviter une valeur absurde (lien de connexion : entre 5 min et
        // 24h ; lien de confirmation : entre 1 et 30 jours).
        $login_ttl_minutes = psc_post_int('login_link_ttl_minutes', 30);
        update_option('psc_login_link_ttl_minutes', max(5, min(1440, $login_ttl_minutes)));
        $email_ttl_days = psc_post_int('email_confirmation_ttl_days', 3);
        update_option('psc_email_confirmation_ttl_days', max(1, min(30, $email_ttl_days)));

        $sidscm_code = isset($_POST['sidscm_access_code']) ? sanitize_text_field(wp_unslash($_POST['sidscm_access_code'])) : '';
        update_option('psc_sidscm_access_code', mb_substr(trim($sidscm_code), 0, 40));
        update_option('psc_sidscm_page_id', psc_post_int('sidscm_page_id', 0));

        $mairie_mail = isset($_POST['mairie_email']) ? sanitize_email(wp_unslash($_POST['mairie_email'])) : '';
        update_option('psc_mairie_email', is_email($mairie_mail) ? $mairie_mail : '');

        $supplier_mail = isset($_POST['supplier_email']) ? sanitize_email(wp_unslash($_POST['supplier_email'])) : '';
        update_option('psc_supplier_email', is_email($supplier_mail) ? $supplier_mail : '');

        $ics_url = isset($_POST['school_calendar_ics_url']) ? esc_url_raw(wp_unslash($_POST['school_calendar_ics_url'])) : '';
        update_option('psc_school_calendar_ics_url', $ics_url);

        // Billing / invoice settings
        $billing_fields = array(
            'psc_billing_org_intro'   => 'sanitize_text_field',
            'psc_billing_org_name'    => 'sanitize_text_field',
            'psc_billing_org_address' => 'sanitize_text_field',
            'psc_billing_org_phone'   => 'sanitize_text_field',
            'psc_billing_org_fax'     => 'sanitize_text_field',
            'psc_billing_org_email'   => 'sanitize_email',
            'psc_billing_org_city'    => 'sanitize_text_field',
            'psc_billing_org_ics'     => 'sanitize_text_field',
            'psc_billing_footer'      => 'sanitize_text_field',
        );
        foreach ($billing_fields as $option => $sanitizer) {
            $post_key = str_replace('psc_billing_', '', $option);
            $val = isset($_POST[$post_key]) ? call_user_func($sanitizer, wp_unslash($_POST[$post_key])) : '';
            update_option($option, $val);
        }
        update_option('psc_billing_logo_left_id',  absint(isset($_POST['logo_left_id'])  ? $_POST['logo_left_id']  : 0));
        update_option('psc_billing_logo_right_id', absint(isset($_POST['logo_right_id']) ? $_POST['logo_right_id'] : 0));

        update_option('psc_doc_reglement_interieur_id',   absint(isset($_POST['doc_reglement_interieur_id'])   ? $_POST['doc_reglement_interieur_id']   : 0));
        update_option('psc_doc_reglement_prelevement_id', absint(isset($_POST['doc_reglement_prelevement_id']) ? $_POST['doc_reglement_prelevement_id'] : 0));

        // Table de correspondance des classes (passage d'année) : un select
        // par classe existante vers sa classe suivante, ou "sortie".
        $progression = array();
        foreach (array_keys(Psc_School_Years::classe_options()) as $code) {
            if ($code === '') continue;
            $next = isset($_POST['progression_' . $code]) ? sanitize_text_field(wp_unslash($_POST['progression_' . $code])) : 'sortie';
            $progression[$code] = $next;
        }
        update_option('psc_classe_progression', $progression);

        $reins_debut = psc_valid_date(psc_post('reinscription_debut'));
        $reins_fin   = psc_valid_date(psc_post('reinscription_fin'));
        update_option('psc_reinscription_debut', $reins_debut ?: '');
        update_option('psc_reinscription_fin', $reins_fin ?: '');

        self::redirect('psc_settings', 'saved');
    }

    public static function page_settings() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        $services = psc_services();
        $psc_classe_progression = Psc_School_Years::classe_progression();
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-settings.php';
    }

    public static function handle_save_email_templates() {
        self::guard('psc_save_email_templates');
        $input = isset($_POST['templates']) ? wp_unslash($_POST['templates']) : array();
        Psc_Email_Templates::save(is_array($input) ? $input : array());
        self::redirect('psc_email_templates', 'saved');
    }

    public static function handle_reset_email_template() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        $key = isset($_GET['key']) ? sanitize_key(wp_unslash($_GET['key'])) : '';
        check_admin_referer('psc_reset_email_template_' . $key);
        if ($key) {
            Psc_Email_Templates::reset($key);
        }
        self::redirect('psc_email_templates', 'reset_one');
    }

    public static function handle_reset_email_templates() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        check_admin_referer('psc_reset_email_templates');
        Psc_Email_Templates::reset();
        self::redirect('psc_email_templates', 'reset_all');
    }

    public static function page_email_templates() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        $templates = Psc_Email_Templates::get_all();
        $psc_msg   = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-email-templates.php';
    }
}
