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
     * demandes traitées de plus de 90 jours — et, avec elles, tout
     * justificatif d'assurance resté en zone d'attente (jamais promu si la
     * demande n'a jamais été approuvée).
     */
    public static function cleanup() {
        global $wpdb;
        $t = psc_table('requests');

        $unverified_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $t WHERE status = 'unverified' AND created_at < %s",
            gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)
        ));
        $stale_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $t WHERE status IN ('approved','rejected') AND decided_at < %s",
            gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS)
        ));
        foreach (array_merge($unverified_ids, $stale_ids) as $id) {
            Psc_Assurances::delete_pending_files($id);
        }

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
                'assurance_rel_path'           => isset($c['assurance_rel_path']) ? sanitize_text_field($c['assurance_rel_path']) : '',
                'assurance_original_filename'  => isset($c['assurance_original_filename']) ? sanitize_text_field($c['assurance_original_filename']) : '',
                'personnes_autorisees'         => self::pickup_persons_of($c),
            );
            if (count($out) >= self::MAX_CHILDREN) break;
        }
        return $out;
    }

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
                'piece_identite' => !empty($p['piece_identite']) ? 1 : 0,
            );
            if (count($out) >= psc_max_pickup_persons_per_child()) break;
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
        $ok_ip = psc_rate_limit_by_ip('req_ip_', 5, HOUR_IN_SECONDS);
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
        $prenom    = psc_post('req_prenom');
        $telephone = psc_post('req_telephone');
        $adresse     = psc_post('req_adresse');
        $code_postal = psc_post('req_code_postal');
        $ville       = psc_post('req_ville');
        $message   = isset($_POST['req_message'])
            ? sanitize_textarea_field(wp_unslash($_POST['req_message'])) : '';

        // Coordonnées (étape 1) : le "required" HTML5 empêche déjà de
        // passer à l'étape suivante côté client, mais reste contournable
        // (POST direct) — on revalide donc côté serveur, formats compris
        // (téléphone français, code postal à 5 chiffres).
        if ($nom === '' || $prenom === '' || $telephone === '' || $adresse === '' || $code_postal === '' || $ville === '') {
            wp_safe_redirect(add_query_arg('psc_msg', 'coordonnees_incomplete', $back));
            exit;
        }
        if (!psc_valid_phone($telephone) || !psc_valid_postcode($code_postal)) {
            wp_safe_redirect(add_query_arg('psc_msg', 'coordonnees_incomplete', $back));
            exit;
        }

        // Second parent (facultatif) : chaque champ reste indépendamment
        // optionnel — contrairement aux personnes autorisées ci-dessous,
        // aucune règle de "tout ou rien" n'est exigée. Seul un format
        // invalide (e-mail/téléphone), s'il est renseigné, fait échouer la
        // soumission plutôt que d'enregistrer une donnée invalide.
        $second_parent_prenom = psc_post('second_parent_prenom');
        $second_parent_nom    = psc_post('second_parent_nom');

        $second_parent_email = '';
        $second_parent_email_raw = psc_post('second_parent_email');
        if ($second_parent_email_raw !== '') {
            if (!is_email($second_parent_email_raw)) {
                wp_safe_redirect(add_query_arg('psc_msg', 'second_parent_bad_email', $back));
                exit;
            }
            $second_parent_email = strtolower(sanitize_email($second_parent_email_raw));
            // Le second parent se connectera avec cette adresse (cf.
            // Psc_Parents::get_by_email()) : elle doit être libre, sinon
            // pointerait vers deux foyers différents selon qui se connecte.
            if (Psc_Parents::get_by_email($second_parent_email)) {
                wp_safe_redirect(add_query_arg('psc_msg', 'second_parent_email_taken', $back));
                exit;
            }
        }

        $second_parent_telephone = '';
        $second_parent_tel_raw = psc_post('second_parent_telephone');
        if ($second_parent_tel_raw !== '') {
            $second_parent_telephone = psc_valid_phone($second_parent_tel_raw);
            if (!$second_parent_telephone) {
                wp_safe_redirect(add_query_arg('psc_msg', 'second_parent_bad_phone', $back));
                exit;
            }
        }

        // Enfants déclarés. Tous les champs (prénom, nom, classe, naissance,
        // justificatif d'assurance) sont obligatoires pour chaque enfant
        // réellement nommé : contrairement à une ligne entièrement vide
        // (ligne inutilisée, simplement ignorée — cf. le "+ Ajouter un
        // enfant" qui laisse des lignes en trop), un enfant explicitement
        // nommé mais incomplet fait échouer TOUTE la soumission — le
        // disparaître silencieusement de la demande serait trompeur pour le
        // parent qui a rempli sa ligne.
        $children = array();
        $assurance_uploads = array(); // index dans $children => $_FILES entry validé
        for ($i = 0; $i < self::MAX_CHILDREN; $i++) {
            $cn = psc_post('child_nom_' . $i);
            $cp = psc_post('child_prenom_' . $i);
            $cc = psc_post('child_classe_' . $i);
            $cb_raw = psc_post('child_naissance_' . $i);
            $cb = psc_valid_date($cb_raw);
            if ($cn === '' && $cp === '' && $cc === '' && $cb_raw === '') continue;
            if ($cn === '' || $cp === '' || $cc === '' || !$cb) {
                wp_safe_redirect(add_query_arg('psc_msg', 'child_incomplete', $back));
                exit;
            }
            // Date bien formée mais incohérente (dans le futur, moins de
            // 3 ans au 1er septembre de l'année en cours) : message dédié,
            // distinct du simple champ manquant ci-dessus.
            if (!psc_valid_child_birthdate($cb)) {
                wp_safe_redirect(add_query_arg('psc_msg', 'child_bad_birthdate', $back));
                exit;
            }

            $file = isset($_FILES['child_assurance_' . $i]) ? $_FILES['child_assurance_' . $i] : null;
            $file_check = Psc_Assurances::validate_upload($file);
            if ($file_check !== true) {
                $codes = array('too_large' => 'assurance_too_large', 'invalid_type' => 'assurance_invalid_type');
                wp_safe_redirect(add_query_arg('psc_msg', isset($codes[$file_check]) ? $codes[$file_check] : 'assurance_required', $back));
                exit;
            }

            // Personnes autorisées à récupérer cet enfant : facultatif (une
            // ligne totalement vide est ignorée), mais une ligne où au
            // moins un champ est renseigné doit être complète — même
            // logique que pour un enfant explicitement nommé mais
            // incomplet ci-dessus : la faire disparaître silencieusement
            // serait trompeur pour le parent.
            $pickup_persons = array();
            $max_pickup = psc_max_pickup_persons_per_child();
            for ($j = 0; $j < $max_pickup; $j++) {
                $pp_prenom = psc_post("child_pickup_prenom_{$i}_{$j}");
                $pp_nom    = psc_post("child_pickup_nom_{$i}_{$j}");
                $pp_tel    = psc_post("child_pickup_telephone_{$i}_{$j}");
                $pp_lien   = psc_post("child_pickup_lien_{$i}_{$j}");
                if ($pp_prenom === '' && $pp_nom === '' && $pp_tel === '' && $pp_lien === '') continue;
                if ($pp_prenom === '' || $pp_nom === '' || $pp_tel === '' || psc_valid_phone($pp_tel) === false) {
                    wp_safe_redirect(add_query_arg('psc_msg', 'pickup_person_incomplete', $back));
                    exit;
                }
                $pickup_persons[] = array(
                    'prenom'         => mb_substr($pp_prenom, 0, 191),
                    'nom'            => mb_substr($pp_nom, 0, 191),
                    'telephone'      => mb_substr($pp_tel, 0, 40),
                    'lien'           => mb_substr($pp_lien, 0, 100),
                    'piece_identite' => isset($_POST["child_pickup_piece_identite_{$i}_{$j}"]) ? 1 : 0,
                );
            }

            // Allergies alimentaires : la case précède le champ — cochée,
            // le champ libre est requis (une allergie déclarée sans
            // description n'est pas exploitable par la restauration).
            $has_allergy = !empty($_POST['child_has_allergy_' . $i]);
            $allergies = null;
            if ($has_allergy) {
                $raw_allergies = isset($_POST['child_food_allergies_' . $i]) ? wp_unslash($_POST['child_food_allergies_' . $i]) : '';
                $raw_allergies = is_string($raw_allergies) ? trim(sanitize_textarea_field($raw_allergies)) : '';
                if ($raw_allergies === '') {
                    wp_safe_redirect(add_query_arg('psc_msg', 'child_allergy_required', $back));
                    exit;
                }
                $allergies = mb_substr($raw_allergies, 0, 1000);
            }

            // Rythme habituel prévu : {weekday => [service, …]}, arbitré au
            // profit du forfait quand coché (FORF remplace les composantes).
            $rhythm = array();
            foreach (array(1, 2, 4, 5) as $wd) {
                $svcs = array();
                foreach (psc_allowed_services() as $svc) {
                    if (!empty($_POST['child_rhythm_' . $i . '_' . $wd . '_' . $svc])) $svcs[] = $svc;
                }
                if (in_array(psc_forfait_code(), $svcs, true)) {
                    $svcs = array(psc_forfait_code());
                }
                if ($svcs) $rhythm[$wd] = $svcs;
            }

            $children[] = array(
                'nom'                  => mb_substr($cn, 0, 190),
                'prenom'               => mb_substr($cp, 0, 190),
                'classe'               => mb_substr($cc, 0, 100),
                'date_naissance'       => $cb ?: '',
                'sans_porc'            => isset($_POST['child_sans_porc_' . $i]) ? 1 : 0,
                'vegan'                => isset($_POST['child_vegan_' . $i]) ? 1 : 0,
                'food_allergies'       => $allergies,
                'rythme'               => $rhythm,
                'personnes_autorisees' => $pickup_persons,
            );
            $assurance_uploads[count($children) - 1] = $file;
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

            // Facultatif mais contrôlé s'il est renseigné : même convention
            // que le téléphone du second parent ci-dessus.
            if ($sepa_code_postal !== '' && !psc_valid_postcode($sepa_code_postal)) {
                wp_safe_redirect(add_query_arg('psc_msg', 'bad_code_postal', $back));
                exit;
            }

            $sepa_reglement_accepted_at = current_time('mysql');
        }

        $token = bin2hex(random_bytes(32));
        $data = array(
            'email'                      => $email,
            'nom'                        => mb_substr($nom, 0, 190),
            'prenom'                     => mb_substr($prenom, 0, 190),
            'telephone'                  => mb_substr($telephone, 0, 40),
            'adresse'                    => mb_substr($adresse, 0, 255),
            'code_postal'                => mb_substr($code_postal, 0, 10),
            'ville'                      => mb_substr($ville, 0, 100),
            'children_json'              => wp_json_encode($children),
            'message'                    => mb_substr($message, 0, 1000),
            'verify_hash'                => psc_hash_token($token),
            'verify_expires'             => gmdate('Y-m-d H:i:s', time() + psc_email_confirmation_ttl()),
            'verified'                   => 0,
            'status'                     => 'unverified',
            'reglement_accepted_at'      => current_time('mysql'),
            'payment_mode'               => $payment_mode,
            'sepa_reglement_accepted_at' => $sepa_reglement_accepted_at,
            'sepa_iban'                  => psc_encrypt($sepa_iban),
            'sepa_bic'                   => $sepa_bic,
            'sepa_titulaire'             => mb_substr($sepa_titulaire, 0, 190) ?: null,
            'sepa_adresse'               => mb_substr($sepa_adresse, 0, 255) ?: null,
            'sepa_code_postal'           => mb_substr($sepa_code_postal, 0, 10) ?: null,
            'sepa_ville'                 => mb_substr($sepa_ville, 0, 100) ?: null,
            'second_parent_prenom'       => mb_substr($second_parent_prenom, 0, 190) ?: null,
            'second_parent_nom'          => mb_substr($second_parent_nom, 0, 190) ?: null,
            'second_parent_email'        => $second_parent_email ?: null,
            'second_parent_telephone'    => $second_parent_telephone ?: null,
            'created_at'                 => current_time('mysql'),
        );

        if ($existing) {
            $wpdb->update($t, $data, array('id' => $existing->id));
            $request_id = $existing->id;
        } else {
            $wpdb->insert($t, $data);
            $request_id = (int) $wpdb->insert_id;
        }

        // Les chemins des justificatifs dépendent de $request_id, connu
        // seulement maintenant : on les déplace en zone d'attente puis on
        // recomplète children_json. En cas de resoumission (branche
        // $existing ci-dessus), on purge d'abord l'éventuelle zone
        // d'attente précédente pour éviter des fichiers orphelins si le
        // nombre d'enfants a changé entre les deux soumissions.
        Psc_Assurances::delete_pending_files($request_id);
        $pending_dir = Psc_Assurances::pending_dir($request_id);
        wp_mkdir_p($pending_dir);
        foreach ($assurance_uploads as $children_index => $file) {
            $filetype = wp_check_filetype($file['name'], array(
                'pdf'      => 'application/pdf',
                'jpg|jpeg' => 'image/jpeg',
                'png'      => 'image/png',
            ));
            $target = trailingslashit($pending_dir) . 'child-' . $children_index . '.' . $filetype['ext'];
            if (move_uploaded_file($file['tmp_name'], $target)) {
                $children[$children_index]['assurance_rel_path'] = Psc_Assurances::pending_rel_path($request_id, $children_index, $filetype['ext']);
                $children[$children_index]['assurance_original_filename'] = sanitize_file_name($file['name']);
            }
        }
        $wpdb->update($t, array('children_json' => wp_json_encode($children)), array('id' => $request_id));

        // Prélèvement : le mandat SEPA est généré tout de suite (la RUM ne
        // dépend que de l'id de la demande, déjà connu) et joint à l'e-mail
        // de confirmation — jamais persisté sur le serveur, il contient un
        // IBAN en clair. Un échec de génération ne doit jamais bloquer
        // l'inscription : l'e-mail part alors simplement sans pièce jointe.
        $mandate_attachments = array();
        if ($payment_mode === 'prelevement') {
            $tmp = Psc_Sepa_Mandate::build_temp_pdf(psc_sepa_mandate_ref($request_id), array(
                'titulaire'   => $sepa_titulaire,
                'adresse'     => $sepa_adresse,
                'code_postal' => $sepa_code_postal,
                'ville'       => $sepa_ville,
                'iban'        => $sepa_iban,
                'bic'         => $sepa_bic,
            ));
            if ($tmp) $mandate_attachments[] = $tmp;
        }

        $url = add_query_arg(
            array('psc_req' => $request_id, 'psc_vtoken' => $token),
            Psc_Mailer::form_page_url()
        );
        Psc_Mailer::send_request_verification($email, $url, $mandate_attachments);

        foreach ($mandate_attachments as $f) { @unlink($f); }

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
                    Psc_Parents::open_session($result);
                    wp_safe_redirect(add_query_arg('psc_msg', 'welcome', $redirect));
                    exit;
                }
            }
        }

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
                // toujours re-dérivé de la demande d'origine par index.
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

        // Règlement, mode de paiement et mandat SEPA déclarés dans la
        // demande : reportés tels quels sur le compte famille créé.
        // L'IBAN est transmis sous sa forme déjà chiffrée : psc_encrypt()
        // est idempotent (préfixe "psc1:"), Psc_Parents::create() ne le
        // chiffrera donc pas une seconde fois.
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
            'second_parent_prenom'       => $req->second_parent_prenom ?? null,
            'second_parent_nom'          => $req->second_parent_nom ?? null,
            'second_parent_email'        => $req->second_parent_email ?? null,
            'second_parent_telephone'    => $req->second_parent_telephone ?? null,
        );
        if (($req->payment_mode ?? 'autre') === 'prelevement') {
            $parent_extra['sepa_mandate_ref'] = psc_sepa_mandate_ref($req->id);
        }

        // Toute l'approbation — foyer, enfants, inscriptions, clôture de
        // la demande — se déroule dans une transaction : un échec
        // d'écriture à mi-course laissait auparavant une famille sans
        // ses enfants (l'insert râté n'interrompait pas la boucle), et
        // une ré-approbation dupliquait ce que le premier passage avait
        // créé. Les tables sont sur le moteur par défaut de la stack
        // cible (MySQL 8 : InnoDB). Les opérations non transactionnelles
        // (déplacement des justificatifs, e-mail) attendent le commit.
        $rollback = static function ($cause) use ($wpdb) {
            $wpdb->query('ROLLBACK');
            // Une cause déjà typée (validation du foyer, e-mail…) est
            // propagée telle quelle pour le diagnostic ; sinon fallback
            // générique.
            return is_wp_error($cause) ? $cause : new WP_Error('psc_approve_failed', $cause);
        };

        $wpdb->query('START TRANSACTION');

        // Création de la famille (ou récupération si elle existe déjà).
        $parent = Psc_Parents::get_by_email($req->email);
        if ($parent) {
            $parent_id = (int) $parent->id;
            // 0 = valeurs inchangées (succès) ; false/WP_Error = échec.
            $updated = Psc_Parents::update($parent_id, $parent_extra);
            if (is_wp_error($updated) || false === $updated) {
                return $rollback(is_wp_error($updated) ? $updated : __('Mise à jour du foyer impossible.', 'periscolaire-registration'));
            }
        } else {
            $parent_id = Psc_Parents::create($req->email, $req->nom, array_merge($parent_extra, array('prenom' => $req->prenom ?? '')));
            if (is_wp_error($parent_id) || !$parent_id) {
                return $rollback(is_wp_error($parent_id) ? $parent_id : __('Création du foyer impossible.', 'periscolaire-registration'));
            }
        }

        $active_year_id = Psc_School_Years::active_id();

        // Justificatifs d'assurance à déplacer hors de la zone d'attente :
        // le rename() ne suit pas un rollback, le déplacement attend donc
        // le commit (cf. $promotions), les lignes enfants d'abord.
        $promotions = array();

        foreach ($children as $c) {
            $inserted = $wpdb->insert(psc_table('children'), array(
                'parent_id'      => $parent_id,
                'nom'            => $c['nom'],
                'prenom'         => $c['prenom'],
                'date_naissance' => !empty($c['date_naissance']) ? $c['date_naissance'] : null,
                'sans_porc'      => !empty($c['sans_porc']) ? 1 : 0,
                'vegan'          => !empty($c['vegan']) ? 1 : 0,
                'food_allergies' => !empty($c['food_allergies']) ? $c['food_allergies'] : null,
                'statut'         => 'actif',
                'created_at'     => current_time('mysql'),
            ), array('%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s'));
            if (false === $inserted) {
                return $rollback(__('Création de l\'enfant impossible.', 'periscolaire-registration'));
            }
            $child_id = (int) $wpdb->insert_id;

            if ($active_year_id) {
                if (!Psc_School_Years::enroll($child_id, $active_year_id, $c['classe'], 'inscrit', $req->reglement_accepted_at ?? current_time('mysql'))) {
                    return $rollback(__('Inscription de l\'enfant impossible.', 'periscolaire-registration'));
                }
            }

            // Rythme habituel prévu à l'inscription : la famille arrive avec
            // une année pré-remplie. Le pattern est posé sur l'année scolaire
            // courante (dates du planning) ; les families ajustent ensuite.
            if (!empty($c['rythme']) && is_array($c['rythme'])) {
                Psc_Planning::seed_patterns_from_wizard($child_id, $c['rythme']);
            }

            // Allergie alimentaire déclarée à l'inscription : le service
            // périscolaire doit déclencher la prise de contact PAI.
            if (!empty($c['food_allergies'])) {
                $parent_row = $wpdb->get_row($wpdb->prepare(
                    'SELECT * FROM ' . psc_table('parents') . ' WHERE id = %d', $parent_id
                ));
                if ($parent_row) {
                    Psc_Mailer::notify_food_allergy($parent_row, $child_id, $c['food_allergies'], null);
                }
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
                    $promotions[] = array($child_id, $abs, $c['assurance_original_filename'] ?? '');
                }
            }
        }

        // Les coordonnées bancaires viennent d'être reportées sur le compte
        // famille : les conserver en double dans la demande ne sert plus à
        // rien et doublerait inutilement la surface d'exposition de l'IBAN.
        // La demande garde le mode de paiement et l'acceptation du règlement,
        // qui documentent le consentement.
        $updated = $wpdb->update(
            psc_table('requests'),
            array(
                'status'     => 'approved',
                'decided_at' => current_time('mysql'),
                'sepa_iban'  => null,
                'sepa_bic'   => null,
            ),
            array('id' => $req->id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );
        if (false === $updated) {
            return $rollback(__('Clôture de la demande impossible.', 'periscolaire-registration'));
        }

        $wpdb->query('COMMIT');

        // Approbation acquise : les opérations non transactionnelles
        // reprennent. Un échec ici ne laisse qu'un état rattrapable
        // manuellement (justificatif encore en zone d'attente), jamais
        // une famille amputée de ses enfants.
        foreach ($promotions as $promotion) {
            Psc_Assurances::promote_pending($promotion[0], $promotion[1], $promotion[2]);
        }
        Psc_Assurances::delete_pending_files($req->id);

        // Le parent reçoit directement son lien d'accès — sauf s'il est
        // déjà connecté (validation automatique, cf. maybe_verify()).
        if ($send_login_email) {
            Psc_Parents::send_login_link($req->email, 'approved');
        }

        return $parent_id;
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
            Psc_Assurances::delete_pending_files($id);
            $wpdb->delete(psc_table('requests'), array('id' => $id), array('%d'));
        }
        Psc_Admin::redirect_public('psc_requests', 'deleted');
    }
}
