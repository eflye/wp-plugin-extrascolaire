<?php
if (!defined('ABSPATH')) exit;

/**
 * Onglet « Notes du fournisseur » de la page Menus : dépôt d'une note de
 * changement de menus, relecture du menu corrigé proposé et
 * enregistrement. La logique métier vit dans Psc_Menu_Notes.
 */
class Psc_Admin_Menu_Notes extends Psc_Admin_Base {

    public static function init() {
        add_action('admin_post_psc_import_menu_note', array(__CLASS__, 'handle_import'));
        add_action('admin_post_psc_set_menu_note_day', array(__CLASS__, 'handle_set_day'));
        add_action('admin_post_psc_apply_menu_note', array(__CLASS__, 'handle_apply'));
        add_action('admin_post_psc_ignore_menu_note', array(__CLASS__, 'handle_ignore'));
        add_action('admin_post_psc_delete_menu_note', array(__CLASS__, 'handle_delete'));
    }

    /** Retour sur l'onglet, sur la note si elle est connue. */
    private static function back($msg, $id = 0) {
        $args = array('tab' => 'notes');
        if ($id) $args['note'] = (int) $id;
        self::redirect('psc_menus', $msg, $args);
    }

    /** Retour après une erreur métier : sur la note, sauf si elle n'existe plus. */
    private static function back_error($error, $id) {
        $code  = $error->get_error_code();
        $known = array_keys(self::notices());
        self::back(in_array($code, $known, true) ? $code : 'note_erreur', $code === 'psc_note_introuvable' ? 0 : $id);
    }

    public static function handle_import() {
        self::guard('psc_import_menu_note');

        $file = isset($_FILES['fichier']) && is_array($_FILES['fichier']) ? $_FILES['fichier'] : null;
        if (!$file || !isset($file['tmp_name'], $file['name'], $file['error']) || is_array($file['tmp_name'])
            || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            self::back('note_fichier_manquant');
        }
        if (in_array((int) $file['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
            self::back('psc_docx_taille');
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            self::back('note_erreur');
        }

        $name   = sanitize_text_field(wp_unslash($file['name']));
        $result = Psc_Menu_Notes::import($file['tmp_name'], $name !== '' ? $name : 'note.docx');
        if (is_wp_error($result)) self::back_error($result, 0);
        self::back('note_importee', (int) $result);
    }

    public static function handle_set_day() {
        self::guard('psc_set_menu_note_day');

        $id     = psc_post_int('id');
        $result = Psc_Menu_Notes::set_day($id, psc_post('jour_date'));
        if (is_wp_error($result)) self::back_error($result, $id);
        self::back('note_jour_modifie', $id);
    }

    public static function handle_apply() {
        self::guard('psc_apply_menu_note');

        $id = psc_post_int('id');
        // Zone de texte multi-lignes : assainie ligne par ligne par
        // Psc_Menu_Notes::apply() (sanitize_text_field() ici écraserait les
        // retours à la ligne).
        $texte  = isset($_POST['menu_jour']) && is_string($_POST['menu_jour']) ? wp_unslash($_POST['menu_jour']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $result = Psc_Menu_Notes::apply($id, $texte);
        if (is_wp_error($result)) self::back_error($result, $id);
        self::back('note_appliquee', $id);
    }

    public static function handle_ignore() {
        self::guard('psc_ignore_menu_note');

        $id     = psc_post_int('id');
        $result = Psc_Menu_Notes::ignore($id);
        if (is_wp_error($result)) self::back_error($result, $id);
        self::back('note_ignoree', $id);
    }

    public static function handle_delete() {
        self::guard('psc_delete_menu_note');
        Psc_Menu_Notes::delete(psc_post_int('id'));
        self::back('note_supprimee');
    }

    /** Messages de l'onglet : code de retour => array(type, texte). */
    public static function notices() {
        /* translators: %s: taille maximale acceptée, par exemple « 10 Mo ». */
        $taille = sprintf(__('Le fichier est vide ou dépasse la taille maximale acceptée (%s).', 'periscolaire-registration'), size_format(Psc_Admin_Menu_Propositions::max_upload()));
        return array(
            'note_importee'         => array('success', __('Note lue. Vérifiez le jour concerné, puis relisez le menu corrigé proposé : rien n’est encore modifié.', 'periscolaire-registration')),
            'note_jour_modifie'     => array('success', __('Jour concerné modifié.', 'periscolaire-registration')),
            'note_appliquee'        => array('success', __('Menu corrigé. Aucun e-mail n’a été envoyé aux familles.', 'periscolaire-registration')),
            'note_ignoree'          => array('success', __('Note classée sans modifier le menu.', 'periscolaire-registration')),
            'note_supprimee'        => array('success', __('Note supprimée. Le menu n’a pas été modifié.', 'periscolaire-registration')),
            'note_fichier_manquant' => array('error', __('Choisissez la note .docx reçue du fournisseur avant de valider.', 'periscolaire-registration')),
            'note_erreur'           => array('error', __('Une erreur est survenue.', 'periscolaire-registration')),
            'psc_docx_format'       => array('error', __('Ce fichier n’est pas un document Word (.docx). Si la note est dans un autre format, enregistrez-la en .docx depuis Word, ou corrigez le menu à la main.', 'periscolaire-registration')),
            'psc_docx_taille'       => array('error', $taille),
            'psc_docx_illisible'    => array('error', __('Le fichier n’a pas pu être lu.', 'periscolaire-registration')),
            'psc_docx_extension'    => array('error', __('Les extensions PHP Zip et DOM sont requises pour lire ce fichier.', 'periscolaire-registration')),
            'psc_docx_vide'         => array('error', __('Aucun texte n’a été trouvé dans ce document.', 'periscolaire-registration')),
            'psc_note_jour'         => array('error', __('Indiquez le jour de repas concerné par la note.', 'periscolaire-registration')),
            'psc_note_jour_ferme'   => array('error', __('Ce jour n’est pas un jour de cantine (mercredi, week-end, vacances ou fermeture).', 'periscolaire-registration')),
            'psc_note_sans_menu'    => array('error', __('Aucun menu n’est enregistré pour ce jour : il n’y a rien à corriger.', 'periscolaire-registration')),
            'psc_note_rien'         => array('error', __('Le menu corrigé est identique au menu actuel : rien n’a été modifié.', 'periscolaire-registration')),
            'psc_note_vide'         => array('error', __('Le menu du jour ne peut pas être vide.', 'periscolaire-registration')),
            'psc_note_stockage'     => array('error', __('L’enregistrement a échoué : rien n’a été modifié.', 'periscolaire-registration')),
            'psc_note_verrouillee'  => array('error', __('Cette note a déjà été traitée.', 'periscolaire-registration')),
            'psc_note_introuvable'  => array('error', __('Cette note n’existe plus.', 'periscolaire-registration')),
        );
    }

    /**
     * Rendu de l'onglet (cf. Psc_Admin_Cantine::page_menus(), qui fournit
     * le wrap, le titre et la barre d'onglets).
     */
    public static function render_tab() {
        $psc_msg     = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        $psc_notices = self::notices();
        $id          = psc_get_int('note');
        $note        = $id ? Psc_Menu_Notes::get($id) : null;
        if ($id && !$note && $psc_msg === '') $psc_msg = 'psc_note_introuvable';

        if (!$note) {
            $recent = Psc_Menu_Notes::recent(30);
            include PSC_PATH . 'templates/admin-menu-notes.php';
            return;
        }

        $ctx          = Psc_Menu_Notes::contexte($note);
        $changements  = Psc_Menu_Notes::changements($note);
        $a_confirmer  = $note->statut === Psc_Menu_Notes::A_CONFIRMER;
        $propositions = $a_confirmer ? Psc_Menu_Notes::propositions($note, $ctx) : array();
        $menu_propose = $a_confirmer ? Psc_Menu_Notes::menu_propose($note, $ctx) : array();

        include PSC_PATH . 'templates/admin-menu-note-detail.php';
    }
}
