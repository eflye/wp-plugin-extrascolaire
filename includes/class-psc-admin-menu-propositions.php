<?php
if (!defined('ABSPATH')) exit;

/**
 * Onglet « Choix fournisseur » de la page Menus : dépôt du fichier du
 * fournisseur, choix des plats, réponse et remplissage des menus. La
 * logique métier vit dans Psc_Menu_Propositions.
 */
class Psc_Admin_Menu_Propositions extends Psc_Admin_Base {

    public static function init() {
        add_action('admin_post_psc_import_menu_proposition', array(__CLASS__, 'handle_import'));
        add_action('admin_post_psc_save_menu_choix', array(__CLASS__, 'handle_save'));
        add_action('admin_post_psc_send_menu_choix', array(__CLASS__, 'handle_send'));
        add_action('admin_post_psc_retry_menu_choix', array(__CLASS__, 'handle_retry'));
        add_action('admin_post_psc_fill_menus_from_choix', array(__CLASS__, 'handle_fill'));
        add_action('admin_post_psc_download_menu_choix', array(__CLASS__, 'handle_download'));
        add_action('admin_post_psc_delete_menu_proposition', array(__CLASS__, 'handle_delete'));
    }

    /** Retour sur l'onglet, sur la proposition si elle est connue. */
    private static function back($msg, $id = 0, array $extra = array()) {
        $args = array('tab' => 'choix');
        if ($id) $args['proposition'] = (int) $id;
        self::redirect('psc_menus', $msg, array_merge($args, $extra));
    }

    /** Taille maximale d'un dépôt : la limite du plugin, bornée par celle du serveur. */
    public static function max_upload() {
        $server = (int) wp_max_upload_size();
        return $server > 0 ? min(Psc_Menu_Xlsx::MAX_FILE_SIZE, $server) : Psc_Menu_Xlsx::MAX_FILE_SIZE;
    }

    /** Code de notice d'une erreur métier (cf. notices()). */
    private static function error_msg($error) {
        $code  = $error->get_error_code();
        $known = array(
            'psc_xlsx_format', 'psc_xlsx_taille', 'psc_xlsx_aucune_semaine', 'psc_xlsx_extension',
            'psc_no_supplier_email', 'psc_aucun_choix', 'psc_fichier_absent', 'psc_mail_failed',
            'psc_proposition_verrouillee', 'psc_proposition_introuvable',
        );
        return in_array($code, $known, true) ? $code : 'choix_erreur';
    }

    public static function handle_import() {
        self::guard('psc_import_menu_proposition');

        $file = isset($_FILES['fichier']) && is_array($_FILES['fichier']) ? $_FILES['fichier'] : null;
        if (!$file || !isset($file['tmp_name'], $file['name'], $file['error']) || is_array($file['tmp_name'])
            || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            self::back('choix_fichier_manquant');
        }
        if (in_array((int) $file['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
            self::back('psc_xlsx_taille');
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            self::back('choix_erreur');
        }

        $name   = sanitize_text_field(wp_unslash($file['name']));
        $result = Psc_Menu_Propositions::import($file['tmp_name'], $name !== '' ? $name : 'menus.xlsx');
        if (is_wp_error($result)) {
            self::back(self::error_msg($result));
        }
        self::back('choix_importe', (int) $result);
    }

    public static function handle_save() {
        self::guard('psc_save_menu_choix');

        $id = psc_post_int('id');
        // Tableau imbriqué semaine > jour > catégorie : chaque valeur est
        // validée contre les plats proposés par Psc_Menu_Propositions::save_choix().
        $choix  = isset($_POST['choix']) && is_array($_POST['choix']) ? wp_unslash($_POST['choix']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $result = Psc_Menu_Propositions::save_choix($id, $choix);
        if (is_wp_error($result)) {
            self::back(self::error_msg($result), $result->get_error_code() === 'psc_proposition_introuvable' ? 0 : $id);
        }
        if (psc_post('suite') === 'envoi') {
            self::back('', $id, array('etape' => 'envoi'));
        }
        self::back('choix_enregistres', $id);
    }

    public static function handle_send() {
        self::guard('psc_send_menu_choix');

        $id     = psc_post_int('id');
        $result = Psc_Menu_Propositions::send($id, psc_post('lot'));
        if (is_wp_error($result)) {
            self::back(self::error_msg($result), $result->get_error_code() === 'psc_proposition_introuvable' ? 0 : $id);
        }
        self::back(self::sent_msg($id), $id);
    }

    /** Message après un envoi accepté : fidèle à ce qui est arrivé aux menus. */
    private static function sent_msg($id) {
        $row = Psc_Menu_Propositions::get($id);
        return $row && $row->statut === Psc_Menu_Propositions::MENUS_REMPLIS ? 'choix_envoyes' : 'choix_envoyes_menus_a_remplir';
    }

    public static function handle_retry() {
        self::guard('psc_retry_menu_choix');

        $row = Psc_Menu_Propositions::get(psc_post_int('id'));
        if (!$row) self::back('psc_proposition_introuvable');

        $result = Psc_Menu_Propositions::relancer((int) $row->id);
        self::back(is_wp_error($result) ? 'psc_mail_failed' : self::sent_msg((int) $row->id), (int) $row->id);
    }

    /**
     * Remplit les menus après l'envoi : reprise d'un remplissage
     * incomplet, ou remplacement confirmé d'un menu déjà envoyé aux
     * familles (une semaine à la fois).
     */
    public static function handle_fill() {
        self::guard('psc_fill_menus_from_choix');

        $row = Psc_Menu_Propositions::get(psc_post_int('id'));
        if (!$row) self::back('psc_proposition_introuvable');
        // Les menus ne se remplissent qu'une fois la réponse partie.
        if ($row->statut === Psc_Menu_Propositions::BROUILLON) self::back('choix_non_envoyes', (int) $row->id);

        $semaine = psc_week_start(psc_post('semaine'));
        $result  = Psc_Menu_Propositions::remplir_menus($row, $semaine ? array($semaine) : array());
        self::back(in_array('erreur', $result, true) ? 'choix_menus_erreur' : 'choix_menus_remplis', (int) $row->id);
    }

    /** Sert le fichier du fournisseur rempli avec les choix en cours. */
    public static function handle_download() {
        self::guard('psc_download_menu_choix');

        $row = Psc_Menu_Propositions::get(psc_post_int('id'));
        if (!$row) self::back('psc_proposition_introuvable');

        $file = Psc_Menu_Propositions::build_file($row);
        if (is_wp_error($file)) self::back(self::error_msg($file), (int) $row->id);

        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . Psc_Menu_Propositions::response_name($row) . '"');
        header('Content-Length: ' . (int) filesize($file));
        readfile($file); // phpcs:ignore WordPress.WP.AlternativeFunctions
        Psc_Menu_Propositions::discard_file($file);
        exit;
    }

    public static function handle_delete() {
        self::guard('psc_delete_menu_proposition');
        Psc_Menu_Propositions::delete(psc_post_int('id'));
        self::back('choix_supprime');
    }

    /** Messages de l'onglet : code de retour => array(type, texte). */
    public static function notices() {
        $no_email = current_user_can('psc_manage_config')
            ? __("Aucune adresse e-mail fournisseur n'est configurée. Renseignez-la dans Commande fournisseur › Réglages.", 'periscolaire-registration')
            : __("Aucune adresse e-mail fournisseur n'est configurée. Demandez à une personne habilitée aux réglages de la renseigner (Commande fournisseur › Réglages).", 'periscolaire-registration');
        return array(
            'choix_importe'           => array('success', __('Fichier lu. Vérifiez les choix proposés, semaine par semaine.', 'periscolaire-registration')),
            'choix_enregistres'       => array('success', __('Choix enregistrés. Rien n’a été envoyé au fournisseur.', 'periscolaire-registration')),
            'choix_envoyes'           => array('success', __('Réponse envoyée au fournisseur. Les menus des semaines choisies ont été remplis ; leur envoi aux familles reste à faire depuis l’onglet Menus.', 'periscolaire-registration')),
            'choix_envoyes_menus_a_remplir' => array('warning', __('Réponse envoyée au fournisseur, mais les menus n’ont pas tous pu être remplis : utilisez « Remplir les menus avec ces choix ».', 'periscolaire-registration')),
            'choix_menus_remplis'     => array('success', __('Menus remplis.', 'periscolaire-registration')),
            'choix_supprime'          => array('success', __('Fichier supprimé. Les menus déjà remplis sont conservés.', 'periscolaire-registration')),
            'choix_fichier_manquant'  => array('error', __('Choisissez le fichier .xlsx reçu du fournisseur avant de valider.', 'periscolaire-registration')),
            'choix_non_envoyes'       => array('error', __('Les menus se remplissent après l’envoi de la réponse au fournisseur.', 'periscolaire-registration')),
            'choix_menus_erreur'      => array('error', __('Un menu au moins n’a pas pu être rempli. Réessayez.', 'periscolaire-registration')),
            'choix_erreur'            => array('error', __('Une erreur est survenue.', 'periscolaire-registration')),
            'psc_xlsx_format'         => array('error', __('Ce fichier n’est pas un classeur Excel (.xlsx). Déposez le fichier reçu du fournisseur, sans le convertir.', 'periscolaire-registration')),
            /* translators: %s: taille maximale acceptée, par exemple « 10 Mo ». */
            'psc_xlsx_taille'         => array('error', sprintf(__('Le fichier est vide ou dépasse la taille maximale acceptée (%s).', 'periscolaire-registration'), size_format(self::max_upload()))),
            'psc_xlsx_extension'      => array('error', __('Les extensions PHP Zip et DOM sont requises pour lire ce fichier.', 'periscolaire-registration')),
            'psc_xlsx_aucune_semaine' => array('error', __('Aucune semaine de menus n’a été reconnue dans ce fichier. Vérifiez qu’il s’agit bien du fichier de choix des menus du fournisseur.', 'periscolaire-registration')),
            'psc_no_supplier_email'   => array('error', $no_email),
            'psc_aucun_choix'         => array('error', __('Aucun plat n’est retenu : il n’y a rien à répondre au fournisseur.', 'periscolaire-registration')),
            'psc_fichier_absent'      => array('error', __('Le fichier du fournisseur est introuvable : déposez-le de nouveau.', 'periscolaire-registration')),
            'psc_mail_failed'         => array('error', __("L'envoi du mail a échoué. Vos choix sont conservés : relancez l'envoi une fois la configuration e-mail vérifiée. Les menus ne sont pas remplis tant que la réponse n'est pas partie.", 'periscolaire-registration')),
            'psc_proposition_verrouillee' => array('error', __('La réponse est déjà partie : les choix ne sont plus modifiables.', 'periscolaire-registration')),
            'psc_proposition_introuvable' => array('error', __('Ce fichier n’existe plus.', 'periscolaire-registration')),
        );
    }

    /** Motif d'une feuille écartée à la lecture, en clair. */
    public static function motif_label($motif) {
        $labels = array(
            'jours_introuvables'      => __('ligne des jours (LUNDI…) introuvable', 'periscolaire-registration'),
            'colonne_enf_introuvable' => __('colonne « Enf » introuvable', 'periscolaire-registration'),
            'aucun_plat'              => __('aucun plat reconnu', 'periscolaire-registration'),
            'semaine_inconnue'        => __('dates de la semaine non reconnues', 'periscolaire-registration'),
            'semaine_en_double'       => __('semaine déjà présente dans une autre feuille', 'periscolaire-registration'),
            'illisible'               => __('feuille illisible', 'periscolaire-registration'),
        );
        return isset($labels[$motif]) ? $labels[$motif] : $labels['illisible'];
    }

    /**
     * Rendu de l'onglet (cf. Psc_Admin_Cantine::page_menus(), qui fournit
     * le wrap, le titre et la barre d'onglets).
     */
    public static function render_tab() {
        $psc_msg     = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        $psc_notices = self::notices();
        $id          = psc_get_int('proposition');
        $proposition = $id ? Psc_Menu_Propositions::get($id) : null;
        if ($id && !$proposition && $psc_msg === '') $psc_msg = 'psc_proposition_introuvable';

        if (!$proposition) {
            $recent = Psc_Menu_Propositions::recent(20);
            $bilans = Psc_Envois::bilans(Psc_Menu_Propositions::ENVOI, wp_list_pluck((array) $recent, 'id'));
            include PSC_PATH . 'templates/admin-menu-choix.php';
            return;
        }

        $brouillon = $proposition->statut === Psc_Menu_Propositions::BROUILLON;
        // 'choix' : formulaire ; 'envoi' : vérification avant envoi ; 'suivi' : réponse partie.
        $etape = !$brouillon ? 'suivi' : (psc_get('etape') === 'envoi' ? 'envoi' : 'choix');

        $semaines   = Psc_Menu_Propositions::semaines($proposition);
        $choix      = Psc_Menu_Propositions::choix($proposition, $semaines);
        $retenus    = Psc_Menu_Propositions::plats_retenus($proposition);
        $etat_menus = Psc_Menu_Propositions::etat_menus($proposition);
        $ignorees   = Psc_Menu_Propositions::ignorees($proposition);
        $marks      = Psc_Menu_Propositions::marks($proposition);
        $bilans     = Psc_Envois::bilans(Psc_Menu_Propositions::ENVOI, array((int) $proposition->id));
        $bilan      = isset($bilans[(int) $proposition->id]) ? $bilans[(int) $proposition->id] : null;
        $supplier_email = (string) get_option('psc_supplier_email', '');
        // Rendu de l'e-mail qui partira : visualisé avant tout envoi.
        $email_preview = $etape === 'envoi' ? Psc_Mailer::build_menu_choices($proposition) : null;

        include PSC_PATH . 'templates/admin-menu-choix-detail.php';
    }
}
