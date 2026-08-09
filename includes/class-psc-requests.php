<?php
if (!defined('ABSPATH')) exit;

/**
 * Demandes d'inscription des familles non encore connues de la mairie.
 *
 * Parcours en deux temps, volontairement :
 *
 *   1. Le parent remplit le formulaire public. La demande est créée avec
 *      le statut "unverified" et N'APPARAÎT PAS dans le backoffice.
 *      Un e-mail de vérification est envoyé.
 *   2. Le parent clique sur le lien reçu : la demande passe en "pending"
 *      et rejoint la file de modération de la mairie.
 *
 * Sans cette étape, un robot pourrait remplir la file de demandes avec
 * des adresses inventées, et la mairie passerait son temps à trier du
 * bruit. Ici, seules des adresses réelles atteignent le backoffice.
 */
class Psc_Requests {

    const MAX_CHILDREN = 5;

    public static function init() {
        add_action('admin_post_nopriv_psc_submit_request', array(__CLASS__, 'handle_submit'));
        add_action('admin_post_psc_submit_request', array(__CLASS__, 'handle_submit'));
        add_action('init', array(__CLASS__, 'maybe_verify'), 6);

        add_action('admin_post_psc_approve_request', array(__CLASS__, 'handle_approve'));
        add_action('admin_post_psc_reject_request', array(__CLASS__, 'handle_reject'));
        add_action('admin_post_psc_delete_request', array(__CLASS__, 'handle_delete'));

        // Purge automatique des demandes anciennes (RGPD : on ne conserve
        // pas indéfiniment les données de familles jamais inscrites).
        add_action('psc_cleanup_requests', array(__CLASS__, 'cleanup'));
    }

    public static function schedule_cleanup() {
        if (!wp_next_scheduled('psc_cleanup_requests')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'psc_cleanup_requests');
        }
    }

    public static function unschedule_cleanup() {
        $ts = wp_next_scheduled('psc_cleanup_requests');
        if ($ts) wp_unschedule_event($ts, 'psc_cleanup_requests');
    }

    /**
     * Supprime les demandes non vérifiées de plus de 7 jours et les
     * demandes traitées de plus de 90 jours.
     */
    public static function cleanup() {
        global $wpdb;
        $t = psc_table('requests');

        $wpdb->query($wpdb->prepare(
            "DELETE FROM $t WHERE status = 'unverified' AND created_at < %s",
            gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $t WHERE status IN ('approved','rejected') AND decided_at < %s",
            gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS)
        ));
    }

    /* ---------------- Lecture ---------------- */

    public static function get($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return null;
        $t = psc_table('requests');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id));
    }

    /**
     * Demandes visibles par la mairie : uniquement celles dont l'adresse
     * e-mail a été vérifiée.
     */
    public static function by_status($status = 'pending') {
        global $wpdb;
        $t = psc_table('requests');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE status = %s AND verified = 1 ORDER BY created_at ASC",
            $status
        ));
    }

    public static function pending_count() {
        global $wpdb;
        $t = psc_table('requests');
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $t WHERE status = 'pending' AND verified = 1"
        );
    }

    /**
     * Décode la liste d'enfants stockée en JSON.
     * Les données proviennent d'une saisie publique : on revalide la
     * structure au lieu de faire confiance au contenu stocké.
     */
    public static function children_of($request) {
        if (empty($request->children_json)) return array();
        $data = json_decode($request->children_json, true);
        if (!is_array($data)) return array();

        $out = array();
        foreach ($data as $c) {
            if (!is_array($c)) continue;
            $nom    = isset($c['nom']) ? sanitize_text_field($c['nom']) : '';
            $prenom = isset($c['prenom']) ? sanitize_text_field($c['prenom']) : '';
            $classe = isset($c['classe']) ? sanitize_text_field($c['classe']) : '';
            if ($nom === '' || $prenom === '') continue;
            $out[] = array(
                'nom'       => $nom,
                'prenom'    => $prenom,
                'classe'    => $classe,
                'sans_porc' => !empty($c['sans_porc']) ? 1 : 0,
                'vegan'     => !empty($c['vegan']) ? 1 : 0,
            );
            if (count($out) >= self::MAX_CHILDREN) break;
        }
        return $out;
    }

    /* ---------------- Soumission publique ---------------- */

    public static function handle_submit() {
        check_admin_referer('psc_submit_request');

        global $wpdb;
        $back = Psc_Mailer::form_page_url();

        // Champ piège : invisible pour un humain, souvent rempli par les
        // robots. S'il est rempli, on simule un succès sans rien créer.
        if (!empty($_POST['psc_website'])) {
            wp_safe_redirect(add_query_arg('psc_msg', 'request_sent', $back));
            exit;
        }

        $email = isset($_POST['req_email']) ? sanitize_email(wp_unslash($_POST['req_email'])) : '';
        if (!is_email($email)) {
            wp_safe_redirect(add_query_arg('psc_msg', 'bad_email', $back));
            exit;
        }
        $email = strtolower($email);

        // Limitation stricte : ce formulaire est public.
        $ok_ip = psc_rate_limit('req_ip_' . psc_client_ip(), 5, HOUR_IN_SECONDS);
        $ok_mail = psc_rate_limit('req_mail_' . $email, 3, DAY_IN_SECONDS);
        if (!$ok_ip || !$ok_mail) {
            // Réponse identique : ne pas révéler que la limite est atteinte.
            wp_safe_redirect(add_query_arg('psc_msg', 'request_sent', $back));
            exit;
        }

        // Si la famille est déjà enregistrée, on ne crée pas de demande :
        // on lui envoie directement son lien de connexion. Le message
        // affiché reste le même, pour ne rien révéler à un tiers.
        if (Psc_Parents::get_by_email($email)) {
            Psc_Parents::send_login_link($email);
            wp_safe_redirect(add_query_arg('psc_msg', 'request_sent', $back));
            exit;
        }

        // Une demande déjà en cours ne doit pas être dupliquée.
        $t = psc_table('requests');
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t WHERE email = %s AND status IN ('unverified','pending')", $email
        ));

        $nom       = psc_post('req_nom');
        $telephone = psc_post('req_telephone');
        $adresse     = psc_post('req_adresse');
        $code_postal = psc_post('req_code_postal');
        $ville       = psc_post('req_ville');
        $message   = isset($_POST['req_message'])
            ? sanitize_textarea_field(wp_unslash($_POST['req_message'])) : '';

        // Enfants déclarés
        $children = array();
        for ($i = 0; $i < self::MAX_CHILDREN; $i++) {
            $cn = psc_post('child_nom_' . $i);
            $cp = psc_post('child_prenom_' . $i);
            $cc = psc_post('child_classe_' . $i);
            if ($cn === '' && $cp === '') continue;
            if ($cn === '' || $cp === '') continue;
            $children[] = array(
                'nom'       => mb_substr($cn, 0, 190),
                'prenom'    => mb_substr($cp, 0, 190),
                'classe'    => mb_substr($cc, 0, 100),
                'sans_porc' => isset($_POST['child_sans_porc_' . $i]) ? 1 : 0,
                'vegan'     => isset($_POST['child_vegan_' . $i]) ? 1 : 0,
            );
        }

        if (empty($children)) {
            wp_safe_redirect(add_query_arg('psc_msg', 'need_child', $back));
            exit;
        }

        // Règlement intérieur : acceptation obligatoire pour toute demande.
        if (empty($_POST['reglement_accepted'])) {
            wp_safe_redirect(add_query_arg('psc_msg', 'reglement_required', $back));
            exit;
        }

        // Mode de paiement : si prélèvement, le mandat SEPA et le règlement
        // dédié sont obligatoires eux aussi.
        $payment_mode = (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'prelevement')
            ? 'prelevement' : 'autre';

        $sepa_reglement_accepted_at = null;
        $sepa_titulaire = $sepa_adresse = $sepa_code_postal = $sepa_ville = '';
        $sepa_iban = $sepa_bic = null;

        if ($payment_mode === 'prelevement') {
            if (empty($_POST['sepa_reglement_accepted'])) {
                wp_safe_redirect(add_query_arg('psc_msg', 'sepa_reglement_required', $back));
                exit;
            }

            $sepa_titulaire   = psc_post('sepa_titulaire');
            $sepa_adresse     = psc_post('sepa_adresse');
            $sepa_code_postal = psc_post('sepa_code_postal');
            $sepa_ville       = psc_post('sepa_ville');
            if ($sepa_titulaire === '') {
                wp_safe_redirect(add_query_arg('psc_msg', 'sepa_missing', $back));
                exit;
            }

            $sepa_iban = psc_valid_iban(psc_post('sepa_iban'));
            if (!$sepa_iban) {
                wp_safe_redirect(add_query_arg('psc_msg', 'bad_iban', $back));
                exit;
            }
            $sepa_bic = psc_valid_bic(psc_post('sepa_bic'));
            if (!$sepa_bic) {
                wp_safe_redirect(add_query_arg('psc_msg', 'bad_bic', $back));
                exit;
            }

            $sepa_reglement_accepted_at = current_time('mysql');
        }

        $token = bin2hex(random_bytes(32));
        $data = array(
            'email'                      => $email,
            'nom'                        => mb_substr($nom, 0, 190),
            'telephone'                  => mb_substr($telephone, 0, 40),
            'adresse'                    => mb_substr($adresse, 0, 255),
            'code_postal'                => mb_substr($code_postal, 0, 10),
            'ville'                      => mb_substr($ville, 0, 100),
            'children_json'              => wp_json_encode($children),
            'message'                    => mb_substr($message, 0, 1000),
            'verify_hash'                => psc_hash_token($token),
            'verify_expires'             => gmdate('Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS),
            'verified'                   => 0,
            'status'                     => 'unverified',
            'reglement_accepted_at'      => current_time('mysql'),
            'payment_mode'               => $payment_mode,
            'sepa_reglement_accepted_at' => $sepa_reglement_accepted_at,
            'sepa_iban'                  => $sepa_iban,
            'sepa_bic'                   => $sepa_bic,
            'sepa_titulaire'             => mb_substr($sepa_titulaire, 0, 190) ?: null,
            'sepa_adresse'               => mb_substr($sepa_adresse, 0, 255) ?: null,
            'sepa_code_postal'           => mb_substr($sepa_code_postal, 0, 10) ?: null,
            'sepa_ville'                 => mb_substr($sepa_ville, 0, 100) ?: null,
            'created_at'                 => current_time('mysql'),
        );

        if ($existing) {
            $wpdb->update($t, $data, array('id' => $existing->id));
            $request_id = $existing->id;
        } else {
            $wpdb->insert($t, $data);
            $request_id = (int) $wpdb->insert_id;
        }

        $url = add_query_arg(
            array('psc_req' => $request_id, 'psc_vtoken' => $token),
            Psc_Mailer::form_page_url()
        );
        Psc_Mailer::send_request_verification($email, $url);

        wp_safe_redirect(add_query_arg('psc_msg', 'request_sent', $back));
        exit;
    }

    /* ---------------- Vérification de l'adresse ---------------- */

    public static function maybe_verify() {
        if (empty($_GET['psc_vtoken']) || empty($_GET['psc_req'])) return;

        global $wpdb;

        $id = absint($_GET['psc_req']);
        $token = sanitize_text_field(wp_unslash($_GET['psc_vtoken']));
        $redirect = remove_query_arg(array('psc_vtoken', 'psc_req'));

        $req = self::get($id);

        if (!$req || $req->status !== 'unverified' || empty($req->verify_hash)) {
            wp_safe_redirect(add_query_arg('psc_msg', 'bad_verify', $redirect));
            exit;
        }
        if (strtotime($req->verify_expires . ' UTC') < time()) {
            wp_safe_redirect(add_query_arg('psc_msg', 'expired_verify', $redirect));
            exit;
        }
        if (!hash_equals($req->verify_hash, psc_hash_token($token))) {
            wp_safe_redirect(add_query_arg('psc_msg', 'bad_verify', $redirect));
            exit;
        }

        $wpdb->update(
            psc_table('requests'),
            array(
                'verified'       => 1,
                'status'         => 'pending',
                'verify_hash'    => null,
                'verify_expires' => null,
            ),
            array('id' => $req->id),
            array('%d', '%s', '%s', '%s'),
            array('%d')
        );

        Psc_Mailer::notify_mairie_new_request($req, self::children_of($req));

        wp_safe_redirect(add_query_arg('psc_msg', 'verified', $redirect));
        exit;
    }

    /* ---------------- Modération (backoffice) ---------------- */

    public static function handle_approve() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        check_admin_referer('psc_approve_request');

        global $wpdb;
        $req = self::get(psc_post_int('id'));
        if (!$req || $req->status !== 'pending') {
            Psc_Admin::redirect_public('psc_requests', 'invalid');
        }

        // La mairie peut avoir corrigé les noms/classes avant validation.
        $children = array();
        for ($i = 0; $i < self::MAX_CHILDREN; $i++) {
            $cn = psc_post('child_nom_' . $i);
            $cp = psc_post('child_prenom_' . $i);
            $cc = psc_post('child_classe_' . $i);
            if ($cn === '' || $cp === '') continue;
            $children[] = array(
                'nom'       => mb_substr($cn, 0, 190),
                'prenom'    => mb_substr($cp, 0, 190),
                'classe'    => mb_substr($cc, 0, 100),
                'sans_porc' => isset($_POST['child_sans_porc_' . $i]) ? 1 : 0,
                'vegan'     => isset($_POST['child_vegan_' . $i]) ? 1 : 0,
            );
        }
        if (empty($children)) {
            $children = self::children_of($req);
        }
        if (empty($children)) {
            Psc_Admin::redirect_public('psc_requests', 'need_child');
        }

        // Règlement, mode de paiement et mandat SEPA déclarés dans la
        // demande : reportés tels quels sur le compte famille créé.
        $parent_extra = array(
            'adresse'                    => $req->adresse ?? '',
            'code_postal'                => $req->code_postal ?? '',
            'ville'                      => $req->ville ?? '',
            'payment_mode'               => $req->payment_mode ?? 'autre',
            'sepa_iban'                  => $req->sepa_iban ?? null,
            'sepa_bic'                   => $req->sepa_bic ?? null,
            'sepa_titulaire'             => $req->sepa_titulaire ?? null,
            'sepa_adresse'               => $req->sepa_adresse ?? null,
            'sepa_code_postal'           => $req->sepa_code_postal ?? null,
            'sepa_ville'                 => $req->sepa_ville ?? null,
            'reglement_accepted_at'      => $req->reglement_accepted_at ?? null,
            'sepa_reglement_accepted_at' => $req->sepa_reglement_accepted_at ?? null,
        );
        if (($req->payment_mode ?? 'autre') === 'prelevement') {
            // Référence unique de mandat (RUM) : dérivée de l'id de la
            // demande, stable et unique sans écriture supplémentaire.
            $parent_extra['sepa_mandate_ref'] = 'RUM' . str_pad($req->id, 8, '0', STR_PAD_LEFT);
        }

        // Création de la famille (ou récupération si elle existe déjà).
        $parent = Psc_Parents::get_by_email($req->email);
        if ($parent) {
            $parent_id = (int) $parent->id;
            Psc_Parents::update($parent_id, $parent_extra);
        } else {
            $parent_id = Psc_Parents::create($req->email, $req->nom, $parent_extra);
            if (is_wp_error($parent_id)) {
                Psc_Admin::redirect_public('psc_requests', 'invalid');
            }
        }

        foreach ($children as $c) {
            $wpdb->insert(psc_table('children'), array(
                'parent_id'  => $parent_id,
                'nom'        => $c['nom'],
                'prenom'     => $c['prenom'],
                'classe'     => $c['classe'],
                'sans_porc'  => !empty($c['sans_porc']) ? 1 : 0,
                'vegan'      => !empty($c['vegan']) ? 1 : 0,
                'created_at' => current_time('mysql'),
            ), array('%d', '%s', '%s', '%s', '%d', '%d', '%s'));
        }

        $wpdb->update(
            psc_table('requests'),
            array('status' => 'approved', 'decided_at' => current_time('mysql')),
            array('id' => $req->id),
            array('%s', '%s'),
            array('%d')
        );

        // Le parent reçoit directement son lien d'accès (contexte approbation).
        Psc_Parents::send_login_link($req->email, 'approved');

        Psc_Admin::redirect_public('psc_requests', 'approved');
    }

    public static function handle_reject() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        check_admin_referer('psc_reject_request');

        global $wpdb;
        $req = self::get(psc_post_int('id'));
        if (!$req || $req->status !== 'pending') {
            Psc_Admin::redirect_public('psc_requests', 'invalid');
        }

        $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';
        $notify = !empty($_POST['notify']);

        $wpdb->update(
            psc_table('requests'),
            array(
                'status'     => 'rejected',
                'note'       => mb_substr($note, 0, 1000),
                'decided_at' => current_time('mysql'),
            ),
            array('id' => $req->id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        if ($notify) {
            Psc_Mailer::send_request_rejected($req->email, $note);
        }

        Psc_Admin::redirect_public('psc_requests', 'rejected');
    }

    public static function handle_delete() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        check_admin_referer('psc_delete_request');

        global $wpdb;
        $id = psc_post_int('id');
        if ($id) {
            $wpdb->delete(psc_table('requests'), array('id' => $id), array('%d'));
        }
        Psc_Admin::redirect_public('psc_requests', 'deleted');
    }
}
