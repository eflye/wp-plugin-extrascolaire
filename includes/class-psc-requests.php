<?php
if (!defined('ABSPATH')) exit;

/**
 * Demandes d'inscription des familles non encore connues de la mairie.
 *
 * Parcours en deux temps, volontairement :
 *
 *   1. Le parent remplit le formulaire public. La demande est créée avec
 *      le statut "unverified", visible dans Familles en attente de confirmation.
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
        // Une mise à jour par zip ne rejoue pas l'activation : la purge se
        // reprogramme ici si elle a disparu.
        self::schedule_cleanup();
    }

    public static function schedule_cleanup() {
        if (!wp_next_scheduled('psc_cleanup_requests')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'psc_cleanup_requests');
        }
    }

    /** Durée de conservation d'une demande jamais traitée par la mairie (RGPD-01). */
    const PENDING_RETENTION_DAYS = 180;
    /** Durée de conservation d'une demande dont l'adresse n'a pas été confirmée. */
    const UNVERIFIED_RETENTION_DAYS = 7;
    /** Durée de conservation d'une demande validée ou refusée, après la décision. */
    const DECIDED_RETENTION_DAYS = 90;

    /**
     * Supprime les demandes non vérifiées de plus de 7 jours, les
     * demandes traitées de plus de 90 jours et les demandes restées en
     * attente de la mairie plus de 180 jours — et, avec elles, tout
     * justificatif d'assurance resté en zone d'attente (jamais promu si la
     * demande n'a jamais été approuvée).
     */
    public static function cleanup() {
        global $wpdb;
        $t = psc_table('requests');

        // created_at et decided_at sont écrits en heure du site
        // (current_time('mysql')) : les seuils sont calculés dans la même
        // horloge (PRA-14), sinon la purge se décalait de l'écart UTC.
        $unverified_cutoff = wp_date('Y-m-d H:i:s', time() - self::UNVERIFIED_RETENTION_DAYS * DAY_IN_SECONDS);
        $stale_cutoff      = wp_date('Y-m-d H:i:s', time() - self::DECIDED_RETENTION_DAYS * DAY_IN_SECONDS);
        $unverified_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $t WHERE status = 'unverified' AND created_at < %s",
            $unverified_cutoff
        ));
        $stale_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $t WHERE status IN ('approved','rejected') AND decided_at < %s",
            $stale_cutoff
        ));
        // Demande confirmée mais jamais traitée (RGPD-01) : elle n'est pas
        // gardée indéfiniment.
        $pending_cutoff = wp_date('Y-m-d H:i:s', time() - self::PENDING_RETENTION_DAYS * DAY_IN_SECONDS);
        $pending_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $t WHERE status = 'pending' AND created_at < %s",
            $pending_cutoff
        ));
        // Les rattachements en échec sont repris avant la purge ; une zone
        // d'attente qui en garde encore survit à sa demande (P1-13).
        Psc_Assurances::retry_failed_promotions();
        foreach (array_merge($unverified_ids, $stale_ids, $pending_ids) as $id) {
            if (!Psc_Assurances::has_failed_promotions($id)) {
                Psc_Assurances::delete_pending_files($id);
            }
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM $t WHERE status = 'unverified' AND created_at < %s",
            $unverified_cutoff
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $t WHERE status IN ('approved','rejected') AND decided_at < %s",
            $stale_cutoff
        ));
        foreach ($pending_ids as $id) {
            $wpdb->delete($t, array('id' => (int) $id, 'status' => 'pending'), array('%d', '%s'));
        }

        $total = count($unverified_ids) + count($stale_ids) + count($pending_ids);
        if ($total > 0) {
            Psc_Audit::log('systeme.purge', array(
                'objet_type' => 'demande',
                'meta' => array('portee' => 'demandes', 'non_verifiees' => count($unverified_ids), 'traitees' => count($stale_ids), 'en_attente' => count($pending_ids)),
                'resume' => sprintf(__('%d demande(s) d’inscription purgée(s).', 'periscolaire-registration'), $total),
            ));
        }
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

    /** Demandes non confirmées, affichées en lecture seule dans Familles. */
    public static function awaiting_confirmation() {
        global $wpdb;
        $table = psc_table('requests');
        return $wpdb->get_results("SELECT id, nom, prenom, email, adresse, code_postal, ville, payment_mode, created_at
            FROM $table WHERE status = 'unverified' AND verified = 0 ORDER BY nom, email, created_at");
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
            $nom       = isset($c['nom']) ? sanitize_text_field($c['nom']) : '';
            $prenom    = isset($c['prenom']) ? sanitize_text_field($c['prenom']) : '';
            $classe    = isset($c['classe']) ? sanitize_text_field($c['classe']) : '';
            $naissance = isset($c['date_naissance']) ? psc_valid_date($c['date_naissance']) : false;
            if ($nom === '' || $prenom === '') continue;
            $out[] = array(
                'nom'                          => $nom,
                'prenom'                       => $prenom,
                'classe'                       => $classe,
                'date_naissance'               => $naissance ?: '',
                'sans_porc'                    => !empty($c['sans_porc']) ? 1 : 0,
                'vegan'                        => !empty($c['vegan']) ? 1 : 0,
                // Signalement alimentaire : seule information de santé du
                // parcours (le texte libre d'allergies a été supprimé en
                // 4.20.0, RGPD-10). Une ancienne demande qui portait encore
                // un texte vaut signalement.
                'food_allergy_signal'          => (!empty($c['food_allergy_signal']) || trim((string) ($c['food_allergies'] ?? '')) !== '') ? 1 : 0,
                'assurance_rel_path'           => isset($c['assurance_rel_path']) ? sanitize_text_field($c['assurance_rel_path']) : '',
                'assurance_original_filename'  => isset($c['assurance_original_filename']) ? sanitize_text_field($c['assurance_original_filename']) : '',
                'personnes_autorisees'         => self::pickup_persons_of($c),
            );
            if (count($out) >= self::MAX_CHILDREN) break;
        }
        return $out;
    }

    /**
     * Revalide une description d'allergie issue d'une source stockée
     * (children_json) — même pipeline que la saisie publique : nettoyage,
     * troncature à la longueur acceptée à l'inscription. Une valeur vide
     * reste vide (enfant sans allergie déclarée).
     */

    /**
     * Décode et revalide le sous-tableau personnes_autorisees d'un enfant
     * (issu de children_json, donc d'une saisie publique) — même
     * principe que children_of() : jamais fait confiance au contenu
     * stocké. Une ligne sans nom/prénom/téléphone est silencieusement
     * ignorée (la liste est facultative), contrairement à un enfant
     * incomplet qui fait échouer toute la soumission (cf. handle_submit()).
     */
    protected static function pickup_persons_of($child) {
        if (empty($child['personnes_autorisees']) || !is_array($child['personnes_autorisees'])) {
            return array();
        }
        $out = array();
        foreach ($child['personnes_autorisees'] as $p) {
            if (!is_array($p)) continue;
            $nom       = isset($p['nom']) ? sanitize_text_field($p['nom']) : '';
            $prenom    = isset($p['prenom']) ? sanitize_text_field($p['prenom']) : '';
            $telephone = isset($p['telephone']) ? sanitize_text_field($p['telephone']) : '';
            if ($nom === '' || $prenom === '' || $telephone === '') continue;
            $out[] = array(
                'nom'            => $nom,
                'prenom'         => $prenom,
                'telephone'      => $telephone,
                'lien'           => isset($p['lien']) ? sanitize_text_field($p['lien']) : '',
            );
            if (count($out) >= psc_max_pickup_persons_per_child()) break;
        }
        return $out;
    }

    /* ---------------- Soumission publique ---------------- */

    /**
     * Soumission du formulaire public, en quatre temps : acceptations
     * silencieuses (champ piège, limitation de débit, famille connue,
     * demande déjà en attente), lecture et validation des champs
     * (coordonnées, second parent, enfants, règlement, paiement),
     * construction de l'enregistrement, puis persistance, e-mail de
     * confirmation et redirection.
     *
     * L'ordre des contrôles est celui des messages affichés : le premier
     * défaut rencontré décide du message (psc_msg), chaque méthode de
     * validation renvoyant un WP_Error dont le code est ce message.
     */
    public static function handle_submit() {
        check_admin_referer('psc_submit_request');

        $back = Psc_Mailer::form_page_url();

        // Champ piège : invisible pour un humain, souvent rempli par les
        // robots. S'il est rempli, on simule un succès sans rien créer.
        if (!empty($_POST['psc_website'])) {
            self::submit_redirect('request_sent', $back);
        }

        $email = self::submit_email();
        if ($email === null) {
            self::submit_redirect('bad_email', $back);
        }

        if (self::submit_silently_accepted($email)) {
            self::submit_redirect('request_sent', $back);
        }

        $contact = self::submit_contact();
        if (is_wp_error($contact)) {
            self::submit_redirect($contact->get_error_code(), $back);
        }

        $second_parent = self::submit_second_parent();
        if (is_wp_error($second_parent)) {
            self::submit_redirect($second_parent->get_error_code(), $back);
        }

        $children = self::submit_children();
        if (is_wp_error($children)) {
            self::submit_redirect($children->get_error_code(), $back);
        }

        // Règlement intérieur : acceptation obligatoire pour toute demande.
        if (empty($_POST['reglement_accepted'])) {
            self::submit_redirect('reglement_required', $back);
        }

        $payment = self::submit_payment();
        if (is_wp_error($payment)) {
            self::submit_redirect($payment->get_error_code(), $back);
        }

        $token = bin2hex(random_bytes(32));
        $data = self::submit_record($email, $contact, $second_parent, $children, $payment, $token);
        if (is_wp_error($data)) {
            self::submit_redirect($data->get_error_code(), $back);
        }

        self::submit_store_and_notify($data, $payment, $token);

        self::submit_redirect('request_sent', $back);
    }

    /**
     * Retour au formulaire avec le message demandé, puis arrêt de la
     * requête : issue de chaque contrôle de handle_submit().
     *
     * @return never
     */
    private static function submit_redirect($code, $back) {
        wp_safe_redirect(add_query_arg('psc_msg', $code, $back));
        exit;
    }

    /**
     * Adresse du demandeur, assainie et en minuscules.
     *
     * @return string|null Null si l'adresse est absente ou invalide.
     */
    private static function submit_email() {
        $email = isset($_POST['req_email']) ? sanitize_email(wp_unslash($_POST['req_email'])) : '';
        if (!is_email($email)) return null;
        return strtolower($email);
    }

    /**
     * Cas où la demande est acceptée en apparence (« request_sent ») sans
     * rien créer, réponse identique à un succès pour ne rien révéler à un
     * tiers : limitation de débit atteinte, famille déjà enregistrée (qui
     * reçoit directement son lien de connexion), demande confirmée déjà
     * en attente de la mairie.
     *
     * @return bool Vrai : répondre « envoyé » sans créer de demande.
     */
    private static function submit_silently_accepted($email) {
        global $wpdb;

        // Limitation stricte : ce formulaire est public.
        $ok_ip = psc_rate_limit_by_ip('req_ip_', 5, HOUR_IN_SECONDS);
        $ok_mail = psc_rate_limit('req_mail_' . $email, 3, DAY_IN_SECONDS);
        if (!$ok_ip || !$ok_mail) {
            // Réponse identique : ne pas révéler que la limite est atteinte.
            return true;
        }

        // Si la famille est déjà enregistrée, on ne crée pas de demande :
        // on lui envoie directement son lien de connexion. Le message
        // affiché reste le même, pour ne rien révéler à un tiers.
        if (Psc_Parents::get_by_email($email)) {
            Psc_Parents::send_login_link($email);
            return true;
        }

        // Une demande confirmée, en attente de la mairie, n'est JAMAIS
        // modifiée depuis ce formulaire public (SEC-01) : n'importe qui
        // connaissant l'adresse pourrait sinon la réécrire. Réponse
        // identique, rien n'est changé ; pour corriger, la famille
        // contacte la mairie.
        $t = psc_table('requests');
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t WHERE email = %s AND status = 'pending' LIMIT 1", $email
        ));
    }

    /**
     * Coordonnées du demandeur (étape 1) : le "required" HTML5 empêche
     * déjà de passer à l'étape suivante côté client, mais reste
     * contournable (POST direct) — on revalide donc côté serveur, formats
     * compris (téléphone français, code postal à 5 chiffres).
     *
     * @return array|WP_Error Champs assainis, ou 'coordonnees_incomplete'.
     */
    private static function submit_contact() {
        $nom       = psc_post('req_nom');
        $prenom    = psc_post('req_prenom');
        $telephone = psc_post('req_telephone');
        $adresse     = psc_post('req_adresse');
        $code_postal = psc_post('req_code_postal');
        $ville       = psc_post('req_ville');
        $message   = isset($_POST['req_message'])
            ? sanitize_textarea_field(wp_unslash($_POST['req_message'])) : '';

        if ($nom === '' || $prenom === '' || $telephone === '' || $adresse === '' || $code_postal === '' || $ville === '') {
            return new WP_Error('coordonnees_incomplete');
        }
        if (!psc_valid_phone($telephone) || !psc_valid_postcode($code_postal)) {
            return new WP_Error('coordonnees_incomplete');
        }

        return array(
            'nom'         => $nom,
            'prenom'      => $prenom,
            'telephone'   => $telephone,
            'adresse'     => $adresse,
            'code_postal' => $code_postal,
            'ville'       => $ville,
            'message'     => $message,
        );
    }

    /**
     * Second parent (facultatif) : chaque champ reste indépendamment
     * optionnel — contrairement aux personnes autorisées, aucune règle de
     * "tout ou rien" n'est exigée. Seul un format invalide
     * (e-mail/téléphone), s'il est renseigné, fait échouer la soumission
     * plutôt que d'enregistrer une donnée invalide.
     *
     * @return array|WP_Error 'second_parent_bad_email',
     *                        'second_parent_email_taken' ou 'second_parent_bad_phone'.
     */
    private static function submit_second_parent() {
        $prenom = psc_post('second_parent_prenom');
        $nom    = psc_post('second_parent_nom');

        $email = '';
        $email_raw = psc_post('second_parent_email');
        if ($email_raw !== '') {
            if (!is_email($email_raw)) {
                return new WP_Error('second_parent_bad_email');
            }
            $email = strtolower(sanitize_email($email_raw));
            // Le second parent se connectera avec cette adresse (cf.
            // Psc_Parents::get_by_email()) : elle doit être libre, sinon
            // pointerait vers deux foyers différents selon qui se connecte.
            if (Psc_Parents::get_by_email($email)) {
                return new WP_Error('second_parent_email_taken');
            }
        }

        $telephone = '';
        $tel_raw = psc_post('second_parent_telephone');
        if ($tel_raw !== '') {
            $telephone = psc_valid_phone($tel_raw);
            if (!$telephone) {
                return new WP_Error('second_parent_bad_phone');
            }
        }

        return array(
            'prenom'    => $prenom,
            'nom'       => $nom,
            'email'     => $email,
            'telephone' => $telephone,
        );
    }

    /**
     * Enfants déclarés. Tous les champs (prénom, nom, classe, date de
     * naissance) sont obligatoires pour chaque enfant réellement nommé :
     * contrairement à une ligne entièrement vide (ligne inutilisée,
     * simplement ignorée — cf. le "+ Ajouter un enfant" qui laisse des
     * lignes en trop), un enfant explicitement nommé mais incomplet fait
     * échouer TOUTE la soumission — le disparaître silencieusement de la
     * demande serait trompeur pour le parent qui a rempli sa ligne.
     *
     * @return array|WP_Error Enfants (forme de children_json), ou
     *                        'child_incomplete', 'child_bad_birthdate', 'need_child'.
     */
    private static function submit_children() {
        $children = array();
        for ($i = 0; $i < self::MAX_CHILDREN; $i++) {
            $cn = psc_post('child_nom_' . $i);
            $cp = psc_post('child_prenom_' . $i);
            $cc = psc_post('child_classe_' . $i);
            $cb_raw = psc_post('child_naissance_' . $i);
            $cb = psc_valid_date($cb_raw);
            if ($cn === '' && $cp === '' && $cc === '' && $cb_raw === '') continue;
            if ($cn === '' || $cp === '' || $cc === '' || !$cb) {
                return new WP_Error('child_incomplete');
            }
            // Date bien formée mais incohérente (dans le futur, moins de
            // 3 ans au 1er septembre de l'année en cours) : message dédié,
            // distinct du simple champ manquant ci-dessus.
            if (!psc_valid_child_birthdate($cb)) {
                return new WP_Error('child_bad_birthdate');
            }

            // Signalement minimal : aucun détail médical n'est collecté.
            // La mairie échange oralement avec la famille puis décide, sur
            // la fiche enfant, d'activer « cantine sans repas ».
            $food_signal = !empty($_POST['child_food_signal_' . $i]) ? 1 : 0;

            $children[] = array(
                'nom'                     => mb_substr($cn, 0, 190),
                'prenom'                  => mb_substr($cp, 0, 190),
                'classe'                  => mb_substr($cc, 0, 100),
                'date_naissance'          => $cb ?: '',
                'sans_porc'               => isset($_POST['child_sans_porc_' . $i]) ? 1 : 0,
                'vegan'                   => isset($_POST['child_vegan_' . $i]) ? 1 : 0,
                'food_allergy_signal'     => $food_signal,
                'personnes_autorisees'    => array(),
            );
        }

        if (empty($children)) {
            return new WP_Error('need_child');
        }
        return $children;
    }

    /**
     * Mode de paiement : si prélèvement, le mandat SEPA et le règlement
     * dédié sont obligatoires eux aussi.
     *
     * @return array|WP_Error Clés mode, sepa_reglement_accepted_at,
     *                        titulaire, adresse, code_postal, ville, iban, bic ;
     *                        ou 'sepa_reglement_required', 'sepa_missing',
     *                        'bad_iban', 'bad_bic', 'bad_code_postal'.
     */
    private static function submit_payment() {
        $payment = array(
            'mode'                       => (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'prelevement')
                ? 'prelevement' : 'autre',
            'sepa_reglement_accepted_at' => null,
            'titulaire'                  => '',
            'adresse'                    => '',
            'code_postal'                => '',
            'ville'                      => '',
            'iban'                       => null,
            'bic'                        => null,
        );
        if ($payment['mode'] !== 'prelevement') {
            return $payment;
        }

        if (empty($_POST['sepa_reglement_accepted'])) {
            return new WP_Error('sepa_reglement_required');
        }

        $payment['titulaire']   = psc_post('sepa_titulaire');
        $payment['adresse']     = psc_post('sepa_adresse');
        $payment['code_postal'] = psc_post('sepa_code_postal');
        $payment['ville']       = psc_post('sepa_ville');
        if ($payment['titulaire'] === '') {
            return new WP_Error('sepa_missing');
        }

        $payment['iban'] = psc_valid_iban(wp_unslash($_POST['sepa_iban'] ?? ''));
        if (!$payment['iban']) {
            return new WP_Error('bad_iban');
        }
        $payment['bic'] = psc_valid_bic(psc_post('sepa_bic'));
        if (!$payment['bic']) {
            return new WP_Error('bad_bic');
        }

        // Facultatif mais contrôlé s'il est renseigné : même convention
        // que le téléphone du second parent.
        if ($payment['code_postal'] !== '' && !psc_valid_postcode($payment['code_postal'])) {
            return new WP_Error('bad_code_postal');
        }

        $payment['sepa_reglement_accepted_at'] = current_time('mysql');
        return $payment;
    }

    /**
     * Ligne à insérer dans psc_requests, à partir des champs validés.
     *
     * @return array|WP_Error 'crypto_unavailable' si l'IBAN ne peut pas être chiffré.
     */
    private static function submit_record($email, array $contact, array $second_parent, array $children, array $payment, $token) {
        // Chiffrement AVANT insertion : sans primitive disponible (ou en
        // cas d'échec), la demande est refusée avec un message lisible
        // plutôt que d'enregistrer l'IBAN en clair (cf. psc_encrypt()).
        $sepa_iban_enc = psc_encrypt($payment['iban']);
        if (is_wp_error($sepa_iban_enc)) {
            return new WP_Error('crypto_unavailable');
        }
        return array(
            'email'                      => $email,
            'nom'                        => mb_substr($contact['nom'], 0, 190),
            'prenom'                     => mb_substr($contact['prenom'], 0, 190),
            'telephone'                  => mb_substr($contact['telephone'], 0, 40),
            'adresse'                    => mb_substr($contact['adresse'], 0, 255),
            'code_postal'                => mb_substr($contact['code_postal'], 0, 10),
            'ville'                      => mb_substr($contact['ville'], 0, 100),
            'children_json'              => wp_json_encode($children),
            'message'                    => mb_substr($contact['message'], 0, 1000),
            'verify_hash'                => psc_hash_token($token),
            'verify_expires'             => gmdate('Y-m-d H:i:s', time() + psc_email_confirmation_ttl()),
            'verified'                   => 0,
            'status'                     => 'unverified',
            'reglement_accepted_at'      => current_time('mysql'),
            // Versions des règlements affichés au moment de cocher (P2-14).
            'reglement_version_id'       => Psc_Document_Versions::current_id('reglement_interieur'),
            'payment_mode'               => $payment['mode'],
            'sepa_reglement_accepted_at' => $payment['sepa_reglement_accepted_at'],
            'sepa_reglement_version_id'  => $payment['sepa_reglement_accepted_at'] ? Psc_Document_Versions::current_id('reglement_prelevement') : null,
            'sepa_iban'                  => $sepa_iban_enc,
            'sepa_bic'                   => $payment['bic'],
            'sepa_titulaire'             => mb_substr($payment['titulaire'], 0, 190) ?: null,
            'sepa_adresse'               => mb_substr($payment['adresse'], 0, 255) ?: null,
            'sepa_code_postal'           => mb_substr($payment['code_postal'], 0, 10) ?: null,
            'sepa_ville'                 => mb_substr($payment['ville'], 0, 100) ?: null,
            'second_parent_prenom'       => mb_substr($second_parent['prenom'], 0, 190) ?: null,
            'second_parent_nom'          => mb_substr($second_parent['nom'], 0, 190) ?: null,
            'second_parent_email'        => $second_parent['email'] ?: null,
            'second_parent_telephone'    => $second_parent['telephone'] ?: null,
            'created_at'                 => current_time('mysql'),
        );
    }

    /**
     * Insère la demande, puis envoie l'e-mail de confirmation — avec le
     * mandat SEPA en pièce jointe pour un prélèvement.
     */
    private static function submit_store_and_notify(array $data, array $payment, $token) {
        global $wpdb;

        // Toujours une nouvelle ligne (SEC-01) : une demande non confirmée
        // de la même adresse n'est ni réécrite ni supprimée ici — son lien
        // de confirmation reste valable. Les anciennes ne disparaissent
        // qu'à la confirmation de l'une d'elles (maybe_verify()), c'est-à-
        // dire une fois l'adresse prouvée.
        $wpdb->insert(psc_table('requests'), $data);
        $request_id = (int) $wpdb->insert_id;

        // Prélèvement : le mandat SEPA est généré tout de suite (la RUM ne
        // dépend que de l'id de la demande, déjà connu) et joint à l'e-mail
        // de confirmation — jamais persisté sur le serveur, il contient un
        // IBAN en clair. Un échec de génération ne doit jamais bloquer
        // l'inscription : l'e-mail part alors simplement sans pièce jointe.
        $mandate_attachments = array();
        if ($payment['mode'] === 'prelevement') {
            $tmp = Psc_Sepa_Mandate::build_temp_pdf(psc_sepa_mandate_ref($request_id), array(
                'titulaire'   => $payment['titulaire'],
                'adresse'     => $payment['adresse'],
                'code_postal' => $payment['code_postal'],
                'ville'       => $payment['ville'],
                'iban'        => $payment['iban'],
                'bic'         => $payment['bic'],
            ));
            if ($tmp) $mandate_attachments[] = $tmp;
        }

        $url = add_query_arg(
            array('psc_req' => $request_id, 'psc_vtoken' => $token),
            Psc_Mailer::form_page_url()
        );
        Psc_Mailer::send_request_verification($data['email'], $url, $mandate_attachments);

        foreach ($mandate_attachments as $f) { @unlink($f); }
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

        // L'adresse est désormais prouvée : les autres demandes non
        // confirmées de la même adresse (soumissions précédentes, ou d'un
        // tiers qui la connaissait) sont remplacées par celle-ci, fichiers
        // compris (SEC-01).
        self::delete_superseded_unverified($req);

        // Validation automatique (Réglages) : la famille accède directement
        // à son espace, sans relecture par la mairie — mêmes écritures que
        // handle_approve(), déclenchées ici au lieu d'un clic mairie. On est
        // déjà dans le navigateur du parent (c'est lui qui vient de cliquer
        // le lien de confirmation) : on ouvre donc sa session tout de suite
        // et on le redirige directement dans son espace connecté, plutôt
        // que de lui faire attendre un second e-mail avec un lien d'accès
        // qu'il devrait encore aller chercher. Le mail "compte activé" est
        // donc inutile ici (send_login_email=false) : la session vient
        // d'être ouverte via le lien qu'il vient de prouver, pas besoin
        // d'un second lien d'accès dans sa boîte mail. Un échec (aucun
        // enfant valide, essentiellement) retombe simplement sur le
        // parcours normal : la demande reste "pending" pour la mairie,
        // rien n'est perdu.
        if (psc_auto_approve_requests_enabled()) {
            $req = self::get($req->id);
            $children = self::children_of($req);
            if (!empty($children)) {
                $result = self::approve_request($req, $children, false);
                if (!is_wp_error($result)) {
                    // La demande vient d'être confirmée depuis l'adresse du
                    // titulaire : la session porte cette identité (SEC-02).
                    Psc_Parents::open_session($result, (string) $req->email);
                    wp_safe_redirect(add_query_arg('psc_msg', 'welcome', $redirect));
                    exit;
                }
            }
        }

        Psc_Mailer::notify_mairie_new_request($req, self::children_of($req));

        wp_safe_redirect(add_query_arg('psc_msg', 'verified', $redirect));
        exit;
    }

    /**
     * Supprime les demandes non confirmées de la même adresse que $req,
     * qui vient d'être confirmée, et leurs justificatifs en attente.
     */
    private static function delete_superseded_unverified($req) {
        global $wpdb;
        $t = psc_table('requests');
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $t WHERE email = %s AND status = 'unverified' AND id <> %d",
            strtolower((string) $req->email), (int) $req->id
        ));
        foreach ($ids as $id) {
            Psc_Assurances::delete_pending_files((int) $id);
            $wpdb->delete($t, array('id' => (int) $id), array('%d'));
        }
        if ($ids) {
            Psc_Audit::log('demande.suppression', array(
                'objet_type' => 'demande', 'objet_id' => (int) $req->id,
                'meta' => array('supprimees' => count($ids)),
                'resume' => sprintf(
                    /* translators: 1: nombre de demandes supprimées, 2: identifiant de la demande confirmée */
                    _n('%1$d demande non confirmée remplacée par la demande d’inscription #%2$d.', '%1$d demandes non confirmées remplacées par la demande d’inscription #%2$d.', count($ids), 'periscolaire-registration'),
                    count($ids), (int) $req->id
                ),
            ));
        }
    }

    /**
     * Coordonnées bancaires d'une demande à effacer une fois la décision
     * prise (RGPD-03) : reportées sur le compte famille à la validation,
     * sans objet après un refus.
     *
     * @return array<string,null>
     */
    private static function cleared_sepa_fields() {
        return array(
            'sepa_iban'        => null,
            'sepa_bic'         => null,
            'sepa_titulaire'   => null,
            'sepa_adresse'     => null,
            'sepa_code_postal' => null,
            'sepa_ville'       => null,
        );
    }

    /* ---------------- Modération (backoffice) ---------------- */

    public static function handle_approve() {
        if (!current_user_can('psc_manage_families')) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        check_admin_referer('psc_approve_request');

        $req = self::get(psc_post_int('id'));
        if (!$req || $req->status !== 'pending') {
            Psc_Admin::redirect_public('psc_requests', 'invalid');
        }

        // La mairie peut avoir corrigé les noms/classes avant validation.
        // Le justificatif d'assurance, lui, n'est jamais éditable depuis ce
        // formulaire : toujours re-dérivé de children_json par index
        // d'origine, jamais d'un champ POST (évite qu'un chemin de fichier
        // arbitraire puisse être soumis).
        $req_children = self::children_of($req);
        $children = array();
        for ($i = 0; $i < self::MAX_CHILDREN; $i++) {
            $cn = psc_post('child_nom_' . $i);
            $cp = psc_post('child_prenom_' . $i);
            $cc = psc_post('child_classe_' . $i);
            $cb = psc_valid_date(psc_post('child_naissance_' . $i));
            if ($cn === '' || $cp === '') continue;
            // La mairie corrige avant validation : une date de naissance
            // rendue incohérente par l'édition (futur, moins de 3 ans au
            // 1er septembre) est refusée, comme à l'inscription.
            if ($cb && !psc_valid_child_birthdate($cb)) {
                Psc_Admin::redirect_public('psc_requests', 'child_bad_birthdate');
            }
            $children[] = array(
                'nom'                          => mb_substr($cn, 0, 190),
                'prenom'                       => mb_substr($cp, 0, 190),
                'classe'                       => mb_substr($cc, 0, 100),
                'date_naissance'               => $cb ?: '',
                'sans_porc'                    => isset($_POST['child_sans_porc_' . $i]) ? 1 : 0,
                'vegan'                        => isset($_POST['child_vegan_' . $i]) ? 1 : 0,
                'assurance_rel_path'           => $req_children[$i]['assurance_rel_path'] ?? '',
                'assurance_original_filename'  => $req_children[$i]['assurance_original_filename'] ?? '',
                // Comme le justificatif d'assurance : jamais re-lu depuis un
                // champ POST (ce formulaire n'en propose pas l'édition),
                // toujours re-dérivé de la demande d'origine par index —
                // sinon l'édition mairie des autres champs effacerait
                // le signalement déclaré par la famille.
                'food_allergy_signal'          => !empty($req_children[$i]['food_allergy_signal']) ? 1 : 0,
                'personnes_autorisees'         => $req_children[$i]['personnes_autorisees'] ?? array(),
            );
        }
        if (empty($children)) {
            $children = $req_children;
        }
        if (empty($children)) {
            Psc_Admin::redirect_public('psc_requests', 'need_child');
        }

        $result = self::approve_request($req, $children);
        if (is_wp_error($result)) {
            Psc_Admin::redirect_public('psc_requests', 'invalid');
        }

        Psc_Audit::log('demande.validation', array(
            'objet_type' => 'demande', 'objet_id' => (int) $req->id, 'famille_id' => (int) $result,
            'meta' => array('enfants' => count($children)),
            /* translators: 1: identifiant de la demande, 2: identifiant de la famille */
            'resume' => sprintf(__('Demande d’inscription #%1$d validée (famille #%2$d).', 'periscolaire-registration'), (int) $req->id, (int) $result),
        ));

        Psc_Admin::redirect_public('psc_requests', 'approved');
    }


    /**
     * Cœur de l'approbation d'une demande : crée ou retrouve la famille,
     * crée les enfants, les inscrit dans l'année active, matérialise les
     * personnes autorisées déclarées, rattache le justificatif d'assurance
     * en attente, clôt la demande, envoie le lien d'accès. Partagé par
     * handle_approve() (validation manuelle par la mairie) et
     * maybe_verify() (validation automatique, cf. Réglages > Demandes
     * d'inscription) — aucun des deux appelants ne doit dupliquer cette
     * logique. $children est déjà résolu par l'appelant (édité par la
     * mairie ou tel quel depuis children_of($req)) : cette méthode ne lit
     * jamais $_POST elle-même. Renvoie l'id du parent créé/retrouvé, ou
     * WP_Error.
     *
     * $send_login_email : à false quand l'appelant vient d'ouvrir la
     * session du parent lui-même dans la même requête (validation
     * automatique juste après confirmation d'adresse) — un e-mail "compte
     * activé" serait alors garanti inutile, le parent étant déjà connecté.
     * Toujours true pour une validation manuelle par la mairie : c'est
     * alors la seule façon pour le parent de recevoir son accès.
     */
    protected static function approve_request($req, $children, $send_login_email = true) {
        global $wpdb;

        $parent_extra = self::approve_parent_fields($req);

        // Toute l'approbation — foyer, enfants, inscriptions, clôture de
        // la demande — se déroule dans une transaction : un échec
        // d'écriture à mi-course laissait auparavant une famille sans
        // ses enfants (l'insert râté n'interrompait pas la boucle), et
        // une ré-approbation dupliquait ce que le premier passage avait
        // créé. Les tables sont sur le moteur par défaut de la stack
        // cible (MySQL 8 : InnoDB). Les opérations non transactionnelles
        // (déplacement des justificatifs, e-mail) attendent le commit
        // (approve_after_commit()).
        $rollback = static function ($cause) use ($wpdb) {
            $wpdb->query('ROLLBACK');
            // Une cause déjà typée (validation du foyer, e-mail…) est
            // propagée telle quelle pour le diagnostic ; sinon fallback
            // générique.
            return is_wp_error($cause) ? $cause : new WP_Error('psc_approve_failed', $cause);
        };

        $wpdb->query('START TRANSACTION');

        // Réclamation de la demande : la ligne est verrouillée jusqu'au
        // commit, et relue sous ce verrou. Deux validations simultanées
        // (double clic, deux agents, validation automatique et manuelle)
        // lisaient toutes deux « pending » avant d'écrire ; pour une famille
        // déjà connue, chacune créait ses propres enfants. La seconde attend
        // désormais la première, puis constate que la demande est traitée.
        $status = $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM ' . psc_table('requests') . ' WHERE id = %d FOR UPDATE', $req->id
        ));
        if ($status !== 'pending') {
            return $rollback(new WP_Error('psc_request_already_decided', __('Demande déjà traitée.', 'periscolaire-registration')));
        }

        $parent_id = self::approve_parent($req, $parent_extra);
        if (!is_int($parent_id)) {
            return $rollback($parent_id);
        }

        $created = self::approve_children($req, $children, $parent_id);
        if (!is_array($created)) {
            return $rollback($created);
        }

        if (!self::approve_close_request($req)) {
            return $rollback(__('Clôture de la demande impossible.', 'periscolaire-registration'));
        }

        if (false === $wpdb->query('COMMIT')) {
            return $rollback(__('Validation de la demande impossible.', 'periscolaire-registration'));
        }

        self::approve_after_commit($req, $parent_id, $created['food_alerts'], $created['promotions'], $send_login_email);

        return $parent_id;
    }

    /**
     * Règlement, mode de paiement et mandat SEPA déclarés dans la
     * demande : reportés tels quels sur le compte famille créé.
     * L'IBAN est transmis sous sa forme déjà chiffrée : psc_encrypt()
     * est idempotent (préfixe "psc1:"), Psc_Parents::create() ne le
     * chiffrera donc pas une seconde fois.
     *
     * @return array Champs pour Psc_Parents::create() / update().
     */
    private static function approve_parent_fields($req) {
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
            'reglement_version_id'       => $req->reglement_version_id ?? null,
            'sepa_reglement_accepted_at' => $req->sepa_reglement_accepted_at ?? null,
            'sepa_reglement_version_id'  => $req->sepa_reglement_version_id ?? null,
            'second_parent_prenom'       => $req->second_parent_prenom ?? null,
            'second_parent_nom'          => $req->second_parent_nom ?? null,
            'second_parent_email'        => $req->second_parent_email ?? null,
            'second_parent_telephone'    => $req->second_parent_telephone ?? null,
        );
        if (($req->payment_mode ?? 'autre') === 'prelevement') {
            $parent_extra['sepa_mandate_ref'] = psc_sepa_mandate_ref($req->id);
        }
        return $parent_extra;
    }

    /**
     * Création de la famille (ou récupération si elle existe déjà), dans
     * la transaction ouverte par approve_request().
     *
     * @return int|string|WP_Error Id du foyer, ou la cause à passer au
     *                             rollback (WP_Error typé, sinon message).
     */
    private static function approve_parent($req, array $parent_extra) {
        $parent = Psc_Parents::get_by_email($req->email);
        if ($parent) {
            $parent_id = (int) $parent->id;
            // 0 = valeurs inchangées (succès) ; false/WP_Error = échec.
            $updated = Psc_Parents::update($parent_id, $parent_extra);
            if (is_wp_error($updated) || false === $updated) {
                return is_wp_error($updated) ? $updated : __('Mise à jour du foyer impossible.', 'periscolaire-registration');
            }
            return $parent_id;
        }
        $parent_id = Psc_Parents::create($req->email, $req->nom, array_merge($parent_extra, array('prenom' => $req->prenom ?? '')));
        if (is_wp_error($parent_id) || !$parent_id) {
            return is_wp_error($parent_id) ? $parent_id : __('Création du foyer impossible.', 'periscolaire-registration');
        }
        return $parent_id;
    }

    /**
     * Enfants de la demande : fiche, inscription à l'année active,
     * personnes autorisées ; dans la transaction. Ce qui ne suit pas un
     * rollback (justificatifs à déplacer, alertes alimentation) est
     * rendu à l'appelant pour après le commit.
     *
     * @return array|string Clés promotions et food_alerts, ou le message
     *                      d'échec à passer au rollback.
     */
    private static function approve_children($req, array $children, $parent_id) {
        global $wpdb;

        $active_year_id = Psc_School_Years::active_id();

        // Justificatifs d'assurance à déplacer hors de la zone d'attente :
        // le rename() ne suit pas un rollback, le déplacement attend donc
        // le commit (cf. $promotions), les lignes enfants d'abord.
        $promotions = array();
        // Alertes alimentation : envoyées après le commit seulement — un
        // e-mail ne suit pas un rollback, et annoncerait un enfant jamais créé.
        $food_alerts = array();

        foreach ($children as $c) {
            $inserted = $wpdb->insert(psc_table('children'), array(
                'parent_id'               => $parent_id,
                'nom'                     => $c['nom'],
                'prenom'                  => $c['prenom'],
                'date_naissance'          => !empty($c['date_naissance']) ? $c['date_naissance'] : null,
                'sans_porc'               => !empty($c['sans_porc']) ? 1 : 0,
                'vegan'                   => !empty($c['vegan']) ? 1 : 0,
                'food_allergy_signal'     => !empty($c['food_allergy_signal']) ? 1 : 0,
                'created_at'              => current_time('mysql'),
            ), array('%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s'));
            if (false === $inserted) {
                return __('Création de l\'enfant impossible.', 'periscolaire-registration');
            }
            $child_id = (int) $wpdb->insert_id;

            if ($active_year_id) {
                if (!Psc_School_Years::enroll($child_id, $active_year_id, $c['classe'], 'inscrit', $req->reglement_accepted_at ?? current_time('mysql'), $req->reglement_version_id ?? null)) {
                    return __('Inscription de l\'enfant impossible.', 'periscolaire-registration');
                }
            }

            // Allergie alimentaire déclarée à l'inscription : le service
            // périscolaire doit déclencher la prise de contact PAI.
            if (!empty($c['food_allergy_signal'])) {
                $food_alerts[] = $child_id;
            }

            // Personnes autorisées déclarées à l'onboarding : l'auteur réel
            // de l'information est la famille (source='parent'), même si
            // c'est ce code (déclenché par un clic mairie ou par la
            // validation automatique) qui effectue l'écriture — aucune
            // session parent n'existe à ce moment-là. Best-effort assumé :
            // un échec (ex. maximum atteint) ne doit pas bloquer
            // l'approbation, ces personnes restant modifiables par la
            // mairie sur la fiche famille.
            if (!empty($c['personnes_autorisees']) && is_array($c['personnes_autorisees'])) {
                foreach ($c['personnes_autorisees'] as $p) {
                    Psc_Pickup_Persons::add($child_id, $p, 'parent', $parent_id);
                }
            }

            // Rattache le justificatif déposé en zone d'attente au nouvel
            // enfant. Pas de blocage dur si le fichier est introuvable
            // (cas limite) : la demande doit toujours pouvoir être
            // approuvée, l'enfant reste rattrapable via « Mes enfants ».
            if (!empty($c['assurance_rel_path'])) {
                $abs = psc_private_path($c['assurance_rel_path']);
                if (file_exists($abs)) {
                    $promotions[] = array($child_id, $abs, $c['assurance_original_filename'] ?? '', $c['assurance_rel_path']);
                }
            }
        }

        return array('promotions' => $promotions, 'food_alerts' => $food_alerts);
    }

    /**
     * Clôture de la demande, dans la transaction.
     *
     * Les coordonnées bancaires viennent d'être reportées sur le compte
     * famille : les conserver en double dans la demande ne sert plus à
     * rien et doublerait inutilement la surface d'exposition de l'IBAN.
     * La demande garde le mode de paiement et l'acceptation du règlement,
     * qui documentent le consentement. Titulaire et adresse du compte
     * suivent l'IBAN (RGPD-03) : ils sont sur le compte famille.
     *
     * @return bool Faux si l'écriture a échoué.
     */
    private static function approve_close_request($req) {
        global $wpdb;
        $updated = $wpdb->update(
            psc_table('requests'),
            array_merge(array(
                'status'     => 'approved',
                'decided_at' => current_time('mysql'),
            ), self::cleared_sepa_fields()),
            array('id' => $req->id),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );
        return false !== $updated;
    }

    /**
     * Approbation acquise : les opérations non transactionnelles
     * reprennent. Un échec ici ne laisse qu'un état rattrapable
     * manuellement (justificatif encore en zone d'attente), jamais
     * une famille amputée de ses enfants.
     */
    private static function approve_after_commit($req, $parent_id, array $food_alerts, array $promotions, $send_login_email) {
        global $wpdb;

        if ($food_alerts) {
            $parent_row = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . psc_table('parents') . ' WHERE id = %d', $parent_id
            ));
            if ($parent_row) {
                foreach ($food_alerts as $child_id) {
                    Psc_Mailer::notify_food_allergy($parent_row, $child_id, '', null);
                }
            }
        }

        $failed_promotions = array();
        foreach ($promotions as $promotion) {
            if (!Psc_Assurances::promote_pending($promotion[0], $promotion[1], $promotion[2])) {
                $failed_promotions[] = array($promotion[0], $promotion[3], $promotion[2]);
            }
        }
        // Ne jamais supprimer la zone d'attente si un rattachement a
        // échoué : son manifeste est la source de la reprise quotidienne
        // (Psc_Assurances::retry_failed_promotions()). Les fichiers déjà
        // promus sont traités normalement.
        if (!$failed_promotions) {
            Psc_Assurances::delete_pending_files($req->id);
        } else {
            Psc_Assurances::record_failed_promotions($req->id, $failed_promotions);
            Psc_Audit::log('assurance.promotion_echec', array('objet_type' => 'demande', 'objet_id' => (int) $req->id, 'resume' => __('Un justificatif n’a pas pu être rattaché ; la zone d’attente est conservée pour reprise.', 'periscolaire-registration')));
        }

        // Le parent reçoit directement son lien d'accès — sauf s'il est
        // déjà connecté (validation automatique, cf. maybe_verify()).
        if ($send_login_email) {
            Psc_Parents::send_login_link($req->email, 'approved');
        }
    }

    public static function handle_reject() {
        if (!current_user_can('psc_manage_families')) {
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

        // Transition conditionnelle : si une validation est passée entre la
        // lecture ci-dessus et cette écriture, la demande n'est plus en
        // attente et le refus ne doit ni l'écraser ni prévenir la famille.
        // Coordonnées bancaires effacées dans la même écriture (RGPD-03) :
        // une demande refusée n'a plus aucune raison de garder un IBAN,
        // même chiffré, pendant les 90 jours qui précèdent sa purge.
        $decided = $wpdb->query($wpdb->prepare(
            'UPDATE ' . psc_table('requests') . " SET status = 'rejected', note = %s, decided_at = %s,
                sepa_iban = NULL, sepa_bic = NULL, sepa_titulaire = NULL, sepa_adresse = NULL, sepa_code_postal = NULL, sepa_ville = NULL
             WHERE id = %d AND status = 'pending'",
            mb_substr($note, 0, 1000), current_time('mysql'), $req->id
        ));
        if (!$decided) {
            Psc_Admin::redirect_public('psc_requests', 'invalid');
        }

        if ($notify) {
            Psc_Mailer::send_request_rejected($req->email, $note);
        }

        Psc_Audit::log('demande.refus', array(
            'objet_type' => 'demande', 'objet_id' => (int) $req->id,
            /* translators: %d: identifiant de la demande */
            'resume' => sprintf(__('Demande d’inscription #%d refusée.', 'periscolaire-registration'), (int) $req->id),
        ));

        Psc_Admin::redirect_public('psc_requests', 'rejected');
    }

    public static function handle_delete() {
        if (!current_user_can('psc_manage_families')) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        check_admin_referer('psc_delete_request');

        global $wpdb;
        $id = psc_post_int('id');
        $from_families = psc_post('source') === 'families';
        if ($from_families) {
            $request = self::get($id);
            if (!$request || $request->status !== 'unverified' || (int) $request->verified !== 0) {
                Psc_Admin::redirect_public('psc_parents', 'request_not_unverified');
                return;
            }
        }
        if ($id) {
            Psc_Assurances::delete_pending_files($id);
            $deleted = $wpdb->delete(psc_table('requests'), array('id' => $id), array('%d'));
            if ($from_families && !$deleted) {
                Psc_Admin::redirect_public('psc_parents', 'request_delete_failed');
                return;
            }
            if ($deleted) {
                Psc_Audit::log('demande.suppression', array(
                    'objet_type' => 'demande', 'objet_id' => $id,
                    /* translators: %d: identifiant de la demande */
                    'resume' => sprintf(__('Demande d’inscription #%d supprimée.', 'periscolaire-registration'), (int) $id),
                ));
            }
        }
        Psc_Admin::redirect_public($from_families ? 'psc_parents' : 'psc_requests', $from_families ? 'request_deleted' : 'deleted');
    }
}
