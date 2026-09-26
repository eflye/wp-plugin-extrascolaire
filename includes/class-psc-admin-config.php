<?php
if (!defined('ABSPATH')) exit;

/**
 * Réglages de l'extension et modèles d'e-mails.
 */
class Psc_Admin_Config extends Psc_Admin_Base {

    public static function init() {
        add_action('admin_post_psc_save_settings', array(__CLASS__, 'handle_save_settings'));
        add_action('admin_post_psc_save_tarif', array(__CLASS__, 'handle_save_tarif'));
        add_action('admin_post_psc_delete_tarif', array(__CLASS__, 'handle_delete_tarif'));
        add_action('admin_post_psc_save_email_templates', array(__CLASS__, 'handle_save_email_templates'));
        add_action('admin_post_psc_reset_email_template', array(__CLASS__, 'handle_reset_email_template'));
        add_action('admin_post_psc_reset_email_templates', array(__CLASS__, 'handle_reset_email_templates'));
    }

    /**
     * Nouveau tarif daté (P1-16) : un prix à partir d'une date, sans effet
     * sur les jours précédents (cf. Psc_Tarifs).
     */
    public static function handle_save_tarif() {
        self::guard('psc_save_tarif');
        $code = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));
        $raw = str_replace(',', '.', sanitize_text_field(wp_unslash($_POST['prix'] ?? '')));
        $debut = psc_valid_date(psc_post('debut'));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw) || (float) $raw > 1000 || !$debut) {
            wp_safe_redirect(admin_url('admin.php?page=psc_settings&psc_msg=tarif_invalid#psc-tarifs'));
            exit;
        }
        $avant = psc_billing_tariffs($debut)[$code]['price'] ?? null;
        $result = Psc_Tarifs::set($code, (int) round((float) $raw * 100), $debut);
        if (is_wp_error($result)) {
            wp_safe_redirect(admin_url('admin.php?page=psc_settings&psc_msg=tarif_invalid#psc-tarifs'));
            exit;
        }
        Psc_Audit::log('reglage.tarif', array(
            'objet_type' => 'reglage',
            'meta' => array('code' => $code, 'a_partir_du' => $debut, 'avant' => $avant, 'apres' => round((float) $raw, 2)),
            'resume' => sprintf(__('Tarif %1$s : %2$s € à partir du %3$s.', 'periscolaire-registration'), $code, number_format_i18n((float) $raw, 2), date_i18n('d/m/Y', strtotime($debut))),
        ));
        wp_safe_redirect(admin_url('admin.php?page=psc_settings&psc_msg=tarif_saved#psc-tarifs'));
        exit;
    }

    /** Suppression d'un tarif pas encore entré en vigueur. */
    public static function handle_delete_tarif() {
        self::guard('psc_delete_tarif');
        $id = psc_post_int('id');
        $result = Psc_Tarifs::delete($id);
        if (is_wp_error($result)) {
            wp_safe_redirect(admin_url('admin.php?page=psc_settings&psc_msg=' . ($result->get_error_code() === 'past' ? 'tarif_refused' : 'tarif_invalid') . '#psc-tarifs'));
            exit;
        }
        Psc_Audit::log('reglage.tarif_suppression', array(
            'objet_type' => 'reglage', 'meta' => array('id' => $id),
            'resume' => __('Tarif à venir supprimé.', 'periscolaire-registration'),
        ));
        wp_safe_redirect(admin_url('admin.php?page=psc_settings&psc_msg=tarif_deleted#psc-tarifs'));
        exit;
    }

    public static function handle_save_settings() {
        self::guard('psc_save_settings');

        // Valider les coordonnées avant toute sauvegarde des réglages.
        $org_iban_raw = wp_unslash($_POST['org_iban'] ?? '');
        $org_bic_raw = wp_unslash($_POST['org_bic'] ?? '');
        $org_ics_raw = wp_unslash($_POST['org_ics'] ?? '');
        $org_iban = $org_iban_raw === '' ? '' : psc_valid_iban($org_iban_raw);
        $org_bic = $org_bic_raw === '' ? '' : psc_valid_bic($org_bic_raw);
        $org_ics = $org_ics_raw === '' ? '' : Psc_Sepa_Export::valid_ics($org_ics_raw);
        if ($org_iban === false || $org_bic === false || $org_ics === false) {
            wp_die(esc_html__('Coordonnées du créancier invalides : vérifiez l’IBAN, le BIC et l’ICS. Aucun réglage n’a été modifié.', 'periscolaire-registration'), '', array('response' => 400, 'back_link' => true));
        }
        $org_iban_encrypted = psc_encrypt($org_iban === '' ? null : $org_iban);
        if (is_wp_error($org_iban_encrypted)) wp_die(esc_html($org_iban_encrypted->get_error_message()), '', array('response' => 500, 'back_link' => true));

        // Délai de prévenance, borné pour éviter une valeur absurde.
        $hours = psc_post_int('lock_hours', 48);
        update_option('psc_lock_hours', max(0, min(720, $hours)));

        update_option('psc_notify_mairie', isset($_POST['notify_mairie']) ? 1 : 0);
        update_option('psc_impersonation_visible_famille', isset($_POST['impersonation_visible_famille']) ? 1 : 0);
        update_option('psc_auto_approve_requests', isset($_POST['auto_approve_requests']) ? 1 : 0);
        update_option('psc_assurance_review_mode', psc_post('assurance_review_mode') === 'manual' ? 'manual' : 'auto');

        // Durées de validité des liens envoyés par e-mail, bornées pour
        // éviter une valeur absurde (lien de connexion : entre 5 min et
        // 24h ; lien de confirmation : entre 1 et 30 jours).
        $login_ttl_minutes = psc_post_int('login_link_ttl_minutes', 30);
        update_option('psc_login_link_ttl_minutes', max(5, min(1440, $login_ttl_minutes)));
        $email_ttl_days = psc_post_int('email_confirmation_ttl_days', 3);
        update_option('psc_email_confirmation_ttl_days', max(1, min(30, $email_ttl_days)));

        $sidscm_code = isset($_POST['sidscm_access_code']) ? sanitize_text_field(wp_unslash($_POST['sidscm_access_code'])) : '';
        update_option('psc_sidscm_access_code', mb_substr(trim($sidscm_code), 0, 40));
        if (class_exists('Psc_Sidscm')) {
            $rows = isset($_POST['sidscm_intervenants']) && is_array($_POST['sidscm_intervenants']) ? wp_unslash($_POST['sidscm_intervenants']) : array();
            update_option('psc_sidscm_intervenants', Psc_Sidscm::sanitize_intervenants($rows));
        }
        update_option('psc_sidscm_page_id', psc_post_int('sidscm_page_id', 0));

        $mairie_mail = isset($_POST['mairie_email']) ? sanitize_email(wp_unslash($_POST['mairie_email'])) : '';
        update_option('psc_mairie_email', is_email($mairie_mail) ? $mairie_mail : '');

        $conversations_mail = isset($_POST['conversations_email']) ? sanitize_email(wp_unslash($_POST['conversations_email'])) : '';
        update_option('psc_conversations_email', is_email($conversations_mail) ? $conversations_mail : '');

        $conv_delai = psc_post_int('conversations_delai_heures', 48);
        update_option('psc_conversations_delai_heures', max(1, min(240, $conv_delai)));
        $conv_tel = isset($_POST['conversations_telephone_urgence']) ? sanitize_text_field(wp_unslash($_POST['conversations_telephone_urgence'])) : '';
        update_option('psc_conversations_telephone_urgence', mb_substr(trim($conv_tel), 0, 40));

        // psc_supplier_email : déplacé vers Commande fournisseur > Réglages
        // (réorganisation du menu, §6) — cf.
        // Psc_Admin_Cantine::handle_save_supplier_settings(), qui seul
        // écrit désormais cette option.

        // Confidentialité : notice des familles (cf. Psc_Privacy). Une valeur
        // invalide n'écrase pas la précédente et l'écran le signale.
        $privacy_refused = false;
        $municipality = isset($_POST['privacy_municipality']) ? sanitize_text_field(wp_unslash($_POST['privacy_municipality'])) : '';
        update_option('psc_privacy_municipality', mb_substr(trim($municipality), 0, 190));
        foreach (array('privacy_dpo_email' => 'psc_privacy_dpo_email', 'privacy_rights_email' => 'psc_privacy_rights_email') as $field => $option) {
            $raw = isset($_POST[$field]) ? trim((string) wp_unslash($_POST[$field])) : '';
            if ($raw === '') {
                update_option($option, '');
            } elseif (is_email($raw)) {
                update_option($option, sanitize_email($raw));
            } else {
                $privacy_refused = true;
            }
        }
        $policy_raw = isset($_POST['privacy_policy_url']) ? trim((string) wp_unslash($_POST['privacy_policy_url'])) : '';
        $policy_url = $policy_raw === '' ? '' : esc_url_raw($policy_raw, array('http', 'https'));
        if ($policy_raw !== '' && !preg_match('#^https?://[^\s/]+#i', $policy_url)) {
            $privacy_refused = true;
        } else {
            update_option('psc_privacy_policy_url', $policy_url);
        }

        // Adresse du calendrier : refusée (et l'ancienne conservée) si elle
        // n'est pas une adresse web publique — cf. validate_ics_url().
        $ics_url = isset($_POST['school_calendar_ics_url']) ? esc_url_raw(wp_unslash($_POST['school_calendar_ics_url'])) : '';
        $ics_refused = false;
        if ($ics_url === '' || !is_wp_error(Psc_School_Calendar::validate_ics_url($ics_url))) {
            update_option('psc_school_calendar_ics_url', $ics_url);
        } else {
            $ics_refused = true;
        }

        // Billing / invoice settings
        $billing_fields = array(
            'psc_billing_org_intro'   => 'sanitize_text_field',
            'psc_billing_org_name'    => 'sanitize_text_field',
            'psc_billing_org_address' => 'sanitize_text_field',
            'psc_billing_org_phone'   => 'sanitize_text_field',
            'psc_billing_org_fax'     => 'sanitize_text_field',
            'psc_billing_org_email'   => 'sanitize_email',
            'psc_billing_org_city'    => 'sanitize_text_field',
            'psc_billing_footer'      => 'sanitize_text_field',
        );
        foreach ($billing_fields as $option => $sanitizer) {
            $post_key = str_replace('psc_billing_', '', $option);
            $val = isset($_POST[$post_key]) ? call_user_func($sanitizer, wp_unslash($_POST[$post_key])) : '';
            update_option($option, $val);
        }
        update_option('psc_billing_org_ics', $org_ics);
        update_option('psc_billing_org_iban', $org_iban_encrypted, false);
        update_option('psc_billing_org_bic', $org_bic, false);
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

        // La liste des clés modifiées (noms de champs, jamais leur valeur —
        // certaines portent des coordonnées bancaires ou un code d'accès)
        // suffit à documenter QUOI a changé sans y exposer de secret.
        Psc_Audit::log('reglage.modification', array(
            'objet_type' => 'reglage',
            'meta' => array('champs' => array_values(array_diff(array_keys($_POST), array('action', '_wpnonce', '_wp_http_referer')))),
        ));

        self::redirect('psc_settings', $ics_refused ? 'ics_url_refused' : ($privacy_refused ? 'privacy_refused' : 'saved'));
    }

    public static function page_settings() {
        if (!current_user_can('psc_manage_config')) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        $services = psc_billing_tariffs();
        $psc_classe_progression = Psc_School_Years::classe_progression();
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-settings.php';
    }

    public static function handle_save_email_templates() {
        self::guard('psc_save_email_templates');
        $input = isset($_POST['templates']) ? wp_unslash($_POST['templates']) : array();
        Psc_Email_Templates::save(is_array($input) ? $input : array());
        Psc_Audit::log('reglage.modele_email', array(
            'objet_type' => 'reglage', 'meta' => array('modeles' => is_array($input) ? array_keys($input) : array()),
            'resume' => __('Modèles d’e-mails modifiés.', 'periscolaire-registration'),
        ));
        self::redirect('psc_email_templates', 'saved');
    }

    public static function handle_reset_email_template() {
        if (!current_user_can('psc_manage_config')) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        $key = isset($_GET['key']) ? sanitize_key(wp_unslash($_GET['key'])) : '';
        check_admin_referer('psc_reset_email_template_' . $key);
        if ($key) {
            Psc_Email_Templates::reset($key);
            Psc_Audit::log('reglage.modele_email', array(
                'objet_type' => 'reglage', 'meta' => array('modele_reinitialise' => $key),
                'resume' => sprintf(__('Modèle d’e-mail « %s » réinitialisé.', 'periscolaire-registration'), $key),
            ));
        }
        self::redirect('psc_email_templates', 'reset_one');
    }

    public static function handle_reset_email_templates() {
        if (!current_user_can('psc_manage_config')) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        check_admin_referer('psc_reset_email_templates');
        Psc_Email_Templates::reset();
        Psc_Audit::log('reglage.modele_email', array(
            'objet_type' => 'reglage', 'meta' => array('modeles_reinitialises' => 'tous'),
            'resume' => __('Tous les modèles d’e-mails réinitialisés.', 'periscolaire-registration'),
        ));
        self::redirect('psc_email_templates', 'reset_all');
    }

    public static function page_email_templates() {
        if (!current_user_can('psc_manage_config')) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        $templates = Psc_Email_Templates::get_all();
        $psc_msg   = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-email-templates.php';
    }
}
