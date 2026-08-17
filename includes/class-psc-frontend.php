<?php
if (!defined('ABSPATH')) exit;

class Psc_Frontend {

    public static function init() {
        add_shortcode('periscolaire_form', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));

        // Une fois connectée, la famille n'a plus besoin du texte de
        // présentation rédigé autour du shortcode dans l'éditeur (utile
        // uniquement avant connexion, pour donner envie/expliquer) : le
        // portail occupe alors toute la page, dès le haut.
        add_filter('the_content', array(__CLASS__, 'hide_page_chrome_when_connected'), 20);

        // Le titre de la page (H1 "wp-block-post-title") vient du modèle
        // de page, pas de post_content : le filtre the_content ci-dessus
        // ne peut pas l'atteindre. On ajoute une classe sur <body> pour le
        // masquer en CSS uniquement quand le portail est affiché.
        add_filter('body_class', array(__CLASS__, 'add_portal_body_class'));

        // Les parents ne sont PAS des utilisateurs WordPress : les actions
        // AJAX doivent donc être exposées en "nopriv". L'autorisation est
        // vérifiée dans chaque handler via la session du plugin.
        add_action('wp_ajax_nopriv_psc_toggle', array(__CLASS__, 'ajax_toggle'));
        add_action('wp_ajax_psc_toggle', array(__CLASS__, 'ajax_toggle'));
        add_action('wp_ajax_nopriv_psc_toggle_bulk', array(__CLASS__, 'ajax_toggle_bulk'));
        add_action('wp_ajax_psc_toggle_bulk', array(__CLASS__, 'ajax_toggle_bulk'));
        add_action('wp_ajax_nopriv_psc_menu_week', array(__CLASS__, 'ajax_menu_week'));
        add_action('wp_ajax_psc_menu_week', array(__CLASS__, 'ajax_menu_week'));
        add_action('wp_ajax_nopriv_psc_confirm', array(__CLASS__, 'ajax_confirm'));
        add_action('wp_ajax_psc_confirm', array(__CLASS__, 'ajax_confirm'));

        // Gestion des enfants par le parent (formulaires POST classiques).
        add_action('admin_post_nopriv_psc_parent_update_child_identity', array(__CLASS__, 'handle_parent_update_child_identity'));
        add_action('admin_post_psc_parent_update_child_identity', array(__CLASS__, 'handle_parent_update_child_identity'));
        add_action('admin_post_nopriv_psc_parent_add_child', array(__CLASS__, 'handle_parent_add_child'));
        add_action('admin_post_psc_parent_add_child', array(__CLASS__, 'handle_parent_add_child'));

        add_action('admin_post_nopriv_psc_parent_update_profile', array(__CLASS__, 'handle_parent_update_profile'));
        add_action('admin_post_psc_parent_update_profile', array(__CLASS__, 'handle_parent_update_profile'));

        add_action('admin_post_nopriv_psc_parent_download_invoice', array(__CLASS__, 'handle_parent_download_invoice'));
        add_action('admin_post_psc_parent_download_invoice', array(__CLASS__, 'handle_parent_download_invoice'));

        add_action('admin_post_nopriv_psc_cancel_absence', array(__CLASS__, 'handle_cancel_absence'));
        add_action('admin_post_psc_cancel_absence', array(__CLASS__, 'handle_cancel_absence'));

        add_action('admin_post_nopriv_psc_parent_upload_assurance', array(__CLASS__, 'handle_parent_upload_assurance'));
        add_action('admin_post_psc_parent_upload_assurance', array(__CLASS__, 'handle_parent_upload_assurance'));
        add_action('admin_post_nopriv_psc_parent_download_assurance', array(__CLASS__, 'handle_parent_download_assurance'));
        add_action('admin_post_psc_parent_download_assurance', array(__CLASS__, 'handle_parent_download_assurance'));

        add_action('admin_post_nopriv_psc_parent_reinscription', array(__CLASS__, 'handle_parent_reinscription'));
        add_action('admin_post_psc_parent_reinscription', array(__CLASS__, 'handle_parent_reinscription'));

        add_action('admin_post_nopriv_psc_parent_add_pickup_person', array(__CLASS__, 'handle_parent_add_pickup_person'));
        add_action('admin_post_psc_parent_add_pickup_person', array(__CLASS__, 'handle_parent_add_pickup_person'));
        add_action('admin_post_nopriv_psc_parent_update_pickup_person', array(__CLASS__, 'handle_parent_update_pickup_person'));
        add_action('admin_post_psc_parent_update_pickup_person', array(__CLASS__, 'handle_parent_update_pickup_person'));
        add_action('admin_post_nopriv_psc_parent_remove_pickup_person', array(__CLASS__, 'handle_parent_remove_pickup_person'));
        add_action('admin_post_psc_parent_remove_pickup_person', array(__CLASS__, 'handle_parent_remove_pickup_person'));
    }

    /**
     * Remplace tout le contenu de la page par le seul rendu du shortcode
     * quand une famille est connectée — le texte de présentation autour
     * (rédigé dans l'éditeur) n'a de sens qu'avant connexion. On ignore
     * volontairement $content : on ré-exécute le shortcode nous-mêmes
     * plutôt que d'essayer d'en extraire le rendu déjà mélangé au reste
     * de la page, ce qui évite toute dépendance à l'ordre des filtres
     * `the_content`.
     */
    public static function hide_page_chrome_when_connected($content) {
        if (!self::portal_takes_over_page()) return $content;
        return self::shortcode(array());
    }

    /** Ajoute une classe CSS sur <body> pour masquer le titre de page (venu
     *  du modèle, hors de portée de the_content) quand le portail s'affiche. */
    public static function add_portal_body_class($classes) {
        if (self::portal_takes_over_page()) {
            $classes[] = 'psc-portal-active';
        }
        return $classes;
    }

    /** Vrai si la page courante affiche le portail famille connecté (donc
     *  doit céder toute la page — titre et texte d'intro compris). */
    protected static function portal_takes_over_page() {
        if (!is_singular() || !Psc_Parents::current()) return false;
        $post = get_post();
        return $post && has_shortcode($post->post_content, 'periscolaire_form');
    }

    public static function assets() {
        if (!is_singular()) return;
        $post = get_post();
        if (!$post || !has_shortcode($post->post_content, 'periscolaire_form')) return;

        wp_enqueue_style('psc-frontend', PSC_URL . 'assets/css/frontend.css', array(), PSC_VERSION);
        wp_enqueue_script('psc-frontend', PSC_URL . 'assets/js/frontend.js', array(), PSC_VERSION, true);
        wp_localize_script('psc-frontend', 'PSC', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('psc_front'),
        ));

        // Design v2 : commun au portail connecté et à la vue invité —
        // psc-frontend reste le socle partagé par les deux (bascule de
        // connexion, cases à cocher du calendrier, popins, sauvegarde de
        // défilement).
        wp_enqueue_style('psc-portal', PSC_URL . 'assets/css/portal.css', array('psc-frontend'), PSC_VERSION);

        if (Psc_Parents::current()) {
            wp_enqueue_script('psc-portal', PSC_URL . 'assets/js/portal.js', array(), PSC_VERSION, true);
        } else {
            wp_enqueue_script('psc-guest', PSC_URL . 'assets/js/guest.js', array(), PSC_VERSION, true);
        }
    }

    protected static function active_trimestre() {
        global $wpdb;
        $t_trim = psc_table('trimestres');
        return $wpdb->get_row("SELECT * FROM $t_trim WHERE active = 1 ORDER BY id DESC LIMIT 1");
    }

    protected static function children_of($parent_id, $active_only = false) {
        global $wpdb;
        $t_child = psc_table('children');
        $where   = $active_only ? "AND statut = 'actif'" : '';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t_child WHERE parent_id = %d $where ORDER BY prenom", $parent_id
        ));
    }

    /**
     * Construit la table des inscriptions existantes pour une liste d'enfants.
     * Clé : childId|date|service
     */
    protected static function reg_map($trimestre_id, $children) {
        global $wpdb;
        if (empty($children)) return array();

        $ids = array_map('intval', wp_list_pluck($children, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $t_reg = psc_table('registrations');
        $params = array_merge(array($trimestre_id), $ids);

        $regs = $wpdb->get_results($wpdb->prepare(
            "SELECT child_id, jour_date, service FROM $t_reg
             WHERE trimestre_id = %d AND child_id IN ($placeholders)",
            $params
        ));

        $map = array();
        foreach ($regs as $r) {
            $map[$r->child_id . '|' . $r->jour_date . '|' . $r->service] = 1;
        }
        return $map;
    }

    /* ---------------- Assurance scolaire (espace famille) ---------------- */

    /**
     * Un enfant a-t-il fourni son assurance scolaire pour l'année scolaire
     * active ? Un document fourni l'an dernier ne compte plus une fois
     * l'année suivante activée par la mairie : pas de tâche cron
     * nécessaire, la vérification se fait à la volée à chaque tentative de
     * déclaration d'un jour (cf. ajax_toggle()).
     */
    protected static function has_valid_assurance($child_id) {
        global $wpdb;
        $year_id = Psc_School_Years::active_id();
        if (!$year_id) return false;
        $t_cy = psc_table('child_school_years');
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_cy WHERE child_id = %d AND school_year_id = %d AND assurance_file_path IS NOT NULL",
            $child_id, $year_id
        ));
    }

    /**
     * Statut d'assurance scolaire (année active) pour une liste d'enfants,
     * en une seule requête groupée — même principe que reg_map() ci-dessus.
     * Clé : child_id. Renvoie des objets avec les mêmes propriétés que
     * l'ancienne table child_assurances (file_path, original_filename,
     * uploaded_at), pour ne pas changer les templates qui les lisent.
     */
    protected static function assurance_map($children) {
        global $wpdb;
        if (empty($children)) return array();
        $year_id = Psc_School_Years::active_id();
        if (!$year_id) return array();

        $ids = array_map('intval', wp_list_pluck($children, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $t_cy = psc_table('child_school_years');
        $params = array_merge(array($year_id), $ids);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT child_id,
                    assurance_file_path AS file_path,
                    assurance_original_filename AS original_filename,
                    assurance_uploaded_at AS uploaded_at
             FROM $t_cy
             WHERE school_year_id = %d AND child_id IN ($placeholders) AND assurance_file_path IS NOT NULL",
            $params
        ));

        $map = array();
        foreach ($rows as $r) {
            $map[$r->child_id] = $r;
        }
        return $map;
    }

    /**
     * Chemin relatif (à wp_upload_dir()['basedir']) du fichier d'assurance
     * d'un enfant pour une année de rentrée donnée. Hors du dossier public
     * standard des médias : le fichier n'est jamais lié par une URL directe,
     * seulement streamé via handle_parent_download_assurance() /
     * Psc_Admin::handle_download_assurance() après contrôle d'accès.
     */
    protected static function assurance_rel_path($child_id, $rentree_year, $ext) {
        return 'periscolaire/assurances/' . $rentree_year . '/child-' . (int) $child_id . '.' . $ext;
    }

    /**
     * Streame un document d'assurance scolaire. Partagé par le
     * téléchargement côté parent (avec contrôle d'appartenance) et côté
     * admin (avec contrôle de capacité) — même principe que
     * Psc_Invoices::download().
     */
    public static function stream_assurance_file($rel_path, $filename) {
        $upload_dir = wp_upload_dir();
        $path = trailingslashit($upload_dir['basedir']) . $rel_path;

        if (!file_exists($path)) {
            wp_die(esc_html__('Fichier introuvable.', 'periscolaire-registration'));
        }

        $filetype = wp_check_filetype($path);
        nocache_headers();
        header('Content-Type: ' . ($filetype['type'] ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path); // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }

    /**
     * Validation pure d'un fichier d'assurance scolaire (présence, taille,
     * type), sans aucun effet de bord — utilisable en pré-contrôle avant de
     * créer quoi que ce soit en base (ex : ajout d'un enfant, où l'on ne
     * veut pas insérer la fiche si le justificatif obligatoire est absent).
     * Retourne true, ou un code : 'required'|'too_large'|'invalid_type'.
     */
    public static function validate_assurance_file($file) {
        if (empty($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return 'required';
        }
        if ($file['size'] > MB_IN_BYTES) {
            return 'too_large';
        }
        $filetype = wp_check_filetype($file['name'], array(
            'pdf'      => 'application/pdf',
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
        ));
        if (!$filetype['ext']) {
            return 'invalid_type';
        }
        return true;
    }

    /**
     * Enregistre le justificatif d'assurance scolaire d'un enfant déjà
     * existant en base, pour l'année scolaire donnée (l'année active par
     * défaut ; la réinscription passe explicitement l'année en
     * préparation, pas encore active). Auto-validé : aucune étape de
     * vérification manuelle par la mairie pour l'instant (cf. Psc_Admin
     * qui expose seulement une consultation en lecture seule). $file doit
     * être un upload de LA REQUÊTE EN COURS (move_uploaded_file() échoue
     * sinon) — cf. promote_pending_assurance() pour le cas d'un fichier
     * déplacé lors d'une requête précédente.
     * Retourne true, ou un code : 'required'|'too_large'|'invalid_type'|'failed'.
     */
    public static function store_assurance_upload($child_id, $file, $school_year_id = null) {
        $check = self::validate_assurance_file($file);
        if ($check !== true) return $check;

        $year_id = $school_year_id ? absint($school_year_id) : Psc_School_Years::active_id();
        $year = $year_id ? Psc_School_Years::get($year_id) : null;
        if (!$year) return 'failed';
        $rentree_year = (int) date('Y', strtotime($year->date_debut));

        $filetype = wp_check_filetype($file['name'], array(
            'pdf'      => 'application/pdf',
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
        ));

        $rel_dir = 'periscolaire/assurances/' . $rentree_year;
        $upload_dir = wp_upload_dir();
        $dir = trailingslashit($upload_dir['basedir']) . $rel_dir;
        if (!wp_mkdir_p($dir)) {
            return 'failed';
        }

        // Nettoie un fichier d'une extension différente laissé par un
        // précédent upload la même année (ex : remplacement JPG → PDF).
        foreach (array('pdf', 'jpg', 'jpeg', 'png') as $ext) {
            $stale = trailingslashit($dir) . 'child-' . $child_id . '.' . $ext;
            if ($ext !== $filetype['ext'] && file_exists($stale)) {
                @unlink($stale); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }

        $rel_path = self::assurance_rel_path($child_id, $rentree_year, $filetype['ext']);
        $target   = trailingslashit($upload_dir['basedir']) . $rel_path;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return 'failed';
        }

        self::upsert_assurance_row($child_id, $rel_path, $file['name'], $year_id);
        return true;
    }

    /**
     * Rattache à un enfant un justificatif déjà présent sur le disque mais
     * NE PROVENANT PAS de l'upload de la requête en cours (ex : fichier
     * déposé en zone d'attente lors de la soumission du wizard public,
     * promu ici seulement après approbation de la mairie, potentiellement
     * plusieurs jours plus tard). move_uploaded_file() échouerait sur un tel
     * fichier ; rename() est la bonne primitive.
     */
    public static function promote_pending_assurance($child_id, $abs_source_path, $original_filename) {
        $ext = strtolower(pathinfo($abs_source_path, PATHINFO_EXTENSION));
        if (!in_array($ext, array('pdf', 'jpg', 'jpeg', 'png'), true)) return false;

        $rentree_year = psc_rentree_year();
        $upload_dir = wp_upload_dir();
        $rel_dir = 'periscolaire/assurances/' . $rentree_year;
        $dir = trailingslashit($upload_dir['basedir']) . $rel_dir;
        if (!wp_mkdir_p($dir)) return false;

        $rel_path = self::assurance_rel_path($child_id, $rentree_year, $ext);
        $target   = trailingslashit($upload_dir['basedir']) . $rel_path;

        if (!rename($abs_source_path, $target)) return false;

        self::upsert_assurance_row($child_id, $rel_path, $original_filename ?: basename($abs_source_path));
        return true;
    }

    /**
     * N'écrit QUE les colonnes assurance_* de la ligne enfant x année
     * active — ne touche jamais classe/statut/règlement, déjà posés par
     * ailleurs (approbation de demande, passage d'année) ou à venir (Mes
     * enfants ne gère que l'assurance, jamais la classe).
     */
    protected static function upsert_assurance_row($child_id, $rel_path, $original_filename, $school_year_id = null) {
        global $wpdb;
        $year_id = $school_year_id ? absint($school_year_id) : Psc_School_Years::active_id();
        if (!$year_id) return false;

        $t_cy = psc_table('child_school_years');
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_cy WHERE child_id = %d AND school_year_id = %d", $child_id, $year_id
        ));

        $data = array(
            'assurance_file_path'         => $rel_path,
            'assurance_original_filename' => sanitize_file_name($original_filename),
            'assurance_uploaded_at'       => current_time('mysql'),
        );

        if ($existing) {
            $wpdb->update($t_cy, $data, array('id' => $existing), array('%s', '%s', '%s'), array('%d'));
        } else {
            $data['child_id'] = $child_id;
            $data['school_year_id'] = $year_id;
            $data['statut'] = 'inscrit';
            $data['date_inscription'] = current_time('mysql');
            $wpdb->insert($t_cy, $data, array('%s', '%s', '%s', '%d', '%d', '%s', '%s'));
        }
        return true;
    }

    /**
     * Upload par le parent du justificatif d'assurance scolaire d'un
     * enfant déjà existant (remplacement depuis « Mes enfants »).
     */
    public static function handle_parent_upload_assurance() {
        check_admin_referer('psc_parent_upload_assurance');

        $parent = Psc_Parents::current();
        if (!$parent) self::parent_form_redirect('auth');

        global $wpdb;
        $child_id = psc_post_int('child_id');
        $t_child  = psc_table('children');
        $owned = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_child WHERE id = %d AND parent_id = %d", $child_id, $parent->id
        ));
        if (!$owned) self::parent_form_redirect('assurance_invalid');

        $result = self::store_assurance_upload($child_id, isset($_FILES['assurance_file']) ? $_FILES['assurance_file'] : null);
        if ($result !== true) {
            $codes = array('too_large' => 'assurance_too_large', 'invalid_type' => 'assurance_invalid_type');
            self::parent_form_redirect(isset($codes[$result]) ? $codes[$result] : 'assurance_upload_failed');
        }

        self::parent_form_redirect('assurance_uploaded');
    }

    /**
     * Téléchargement du justificatif d'assurance par la famille elle-même.
     * Même logique de contrôle d'appartenance que
     * handle_parent_download_invoice() : le nonce prouve l'intention, mais
     * c'est la vérification ci-dessous qui empêche une famille connectée de
     * consulter le document d'un enfant qui n'est pas le sien.
     */
    public static function handle_parent_download_assurance() {
        $parent = Psc_Parents::current();
        if (!$parent) {
            wp_die(esc_html__('Vous devez être connecté pour accéder à ce document.', 'periscolaire-registration'), '', array('response' => 403));
        }

        $child_id = psc_get_int('child_id');
        check_admin_referer('psc_parent_download_assurance_' . $child_id);

        global $wpdb;
        $t_child = psc_table('children');
        $child = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t_child WHERE id = %d AND parent_id = %d", $child_id, $parent->id
        ));
        if (!$child) {
            wp_die(esc_html__('Enfant introuvable.', 'periscolaire-registration'), '', array('response' => 404));
        }

        $year_id = Psc_School_Years::active_id();
        $doc = $year_id ? Psc_School_Years::enrollment($child_id, $year_id) : null;
        if (!$doc || !$doc->assurance_file_path) {
            wp_die(esc_html__('Aucun document pour cette année.', 'periscolaire-registration'), '', array('response' => 404));
        }

        self::stream_assurance_file($doc->assurance_file_path, $doc->assurance_original_filename);
    }

    /* ---------------- AJAX : cocher / décocher ---------------- */

    public static function ajax_toggle() {
        check_ajax_referer('psc_front', 'nonce');

        $parent = Psc_Parents::current();
        if (!$parent) {
            wp_send_json_error(array('code' => 'auth'), 403);
        }

        global $wpdb;

        $child_id = psc_post_int('child_id');
        $service  = psc_post('service');
        $date     = psc_valid_date(psc_post('date'));
        $checked  = psc_post('checked') === '1';

        if (!$child_id || !$date) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }
        if (!in_array($service, psc_allowed_services(), true)) {
            wp_send_json_error(array('code' => 'service'), 400);
        }

        // L'enfant doit appartenir au parent de la session en cours.
        $t_child = psc_table('children');
        $child = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_child WHERE id = %d", $child_id));
        if (!$child) {
            wp_send_json_error(array('code' => 'notfound'), 404);
        }
        if ((int) $child->parent_id !== (int) $parent->id) {
            wp_send_json_error(array('code' => 'forbidden'), 403);
        }

        // L'assurance scolaire de l'année en cours ne bloque que l'AJOUT
        // d'un jour : un enfant déjà déclaré peut toujours être décoché
        // même sans document à jour (pas de blocage rétroactif).
        if ($checked && !self::has_valid_assurance($child_id)) {
            wp_send_json_error(array(
                'code'    => 'assurance_missing',
                'message' => 'L\'assurance scolaire de cet enfant n\'a pas été fournie pour l\'année en cours. Ajoutez-la depuis « Mes enfants » pour pouvoir déclarer des jours.',
            ), 403);
        }

        $trimestre = self::active_trimestre();
        if (!$trimestre) {
            wp_send_json_error(array('code' => 'closed'), 403);
        }

        $t_days = psc_table('calendar_days');
        $day = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t_days WHERE trimestre_id = %d AND jour_date = %s AND is_open = 1",
            $trimestre->id, $date
        ));
        if (!$day) {
            wp_send_json_error(array('code' => 'day_closed'), 403);
        }

        // Délai de prévenance : vérifié CÔTÉ SERVEUR. Désactiver la case
        // dans le navigateur ne suffit pas, un utilisateur peut réactiver
        // le champ ou rejouer la requête.
        if (psc_is_locked($date)) {
            wp_send_json_error(array(
                'code'    => 'locked',
                'message' => sprintf(
                    'Ce jour n\'est plus modifiable en ligne (délai de %d h dépassé). Contactez la mairie.',
                    psc_lock_hours()
                ),
            ), 403);
        }

        $t_reg = psc_table('registrations');
        if ($checked) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $t_reg (child_id, trimestre_id, jour_date, service, updated_at)
                 VALUES (%d, %d, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
                $child_id, $trimestre->id, $date, $service, current_time('mysql')
            ));
            // FORF inclut GM+CANT+GS : retirer les prestations individuelles si elles existent
            if ($service === 'FORF') {
                foreach (array('GM', 'CANT', 'GS') as $svc) {
                    $wpdb->delete($t_reg,
                        array('child_id' => $child_id, 'jour_date' => $date, 'service' => $svc),
                        array('%d', '%s', '%s')
                    );
                }
            }
            // Une prestation individuelle est incompatible avec FORF
            if (in_array($service, array('GM', 'CANT', 'GS'), true)) {
                $wpdb->delete($t_reg,
                    array('child_id' => $child_id, 'jour_date' => $date, 'service' => 'FORF'),
                    array('%d', '%s', '%s')
                );
            }
        } else {
            $wpdb->delete(
                $t_reg,
                array('child_id' => $child_id, 'jour_date' => $date, 'service' => $service),
                array('%d', '%s', '%s')
            );
        }

        wp_send_json_success();
    }

    /**
     * Bouton "Tout" par colonne de service (Cantine & Garderie) : coche ou
     * décoche en une fois tous les jours déclarables d'un mois pour un
     * enfant/service donnés. Reçoit la liste exacte des dates depuis le
     * client (celles rendues comme déclarables — non verrouillées — au
     * chargement de la page), mais revalide chacune côté serveur (jour
     * ouvert, non verrouillé) plutôt que de faire confiance à cette liste :
     * son état a pu changer depuis le rendu de la page. Les dates rejetées
     * sont ignorées silencieusement plutôt que d'échouer tout le lot — même
     * principe de résilience que handle_cancel_absence(). Ne réutilise pas
     * ajax_toggle() (même logique dupliquée par date) pour ne rien changer
     * au comportement déjà en place du pointage case par case.
     */
    public static function ajax_toggle_bulk() {
        check_ajax_referer('psc_front', 'nonce');

        $parent = Psc_Parents::current();
        if (!$parent) {
            wp_send_json_error(array('code' => 'auth'), 403);
        }

        global $wpdb;

        $child_id  = psc_post_int('child_id');
        $service   = psc_post('service');
        $checked   = psc_post('checked') === '1';
        $raw_dates = isset($_POST['dates']) ? wp_unslash($_POST['dates']) : '';
        $raw_dates = is_array($raw_dates) ? $raw_dates : explode(',', (string) $raw_dates);

        if (!$child_id || !in_array($service, psc_allowed_services(), true)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        $dates = array();
        foreach ($raw_dates as $raw) {
            $d = psc_valid_date($raw);
            if ($d) $dates[$d] = $d; // dédoublonnage
        }
        if (empty($dates)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        $t_child = psc_table('children');
        $child = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_child WHERE id = %d", $child_id));
        if (!$child) {
            wp_send_json_error(array('code' => 'notfound'), 404);
        }
        if ((int) $child->parent_id !== (int) $parent->id) {
            wp_send_json_error(array('code' => 'forbidden'), 403);
        }

        if ($checked && !self::has_valid_assurance($child_id)) {
            wp_send_json_error(array(
                'code'    => 'assurance_missing',
                'message' => 'L\'assurance scolaire de cet enfant n\'a pas été fournie pour l\'année en cours. Ajoutez-la depuis « Mes enfants » pour pouvoir déclarer des jours.',
            ), 403);
        }

        $trimestre = self::active_trimestre();
        if (!$trimestre) {
            wp_send_json_error(array('code' => 'closed'), 403);
        }

        $t_days = psc_table('calendar_days');
        $t_reg  = psc_table('registrations');
        $applied = array();

        foreach ($dates as $date) {
            $day = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $t_days WHERE trimestre_id = %d AND jour_date = %s AND is_open = 1",
                $trimestre->id, $date
            ));
            if (!$day) continue;

            // Délai de prévenance revérifié par date : l'état a pu changer
            // depuis le chargement de la page, on ignore silencieusement
            // plutôt que d'échouer tout le lot.
            if (psc_is_locked($date)) continue;

            if ($checked) {
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO $t_reg (child_id, trimestre_id, jour_date, service, updated_at)
                     VALUES (%d, %d, %s, %s, %s)
                     ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
                    $child_id, $trimestre->id, $date, $service, current_time('mysql')
                ));
                if ($service === 'FORF') {
                    foreach (array('GM', 'CANT', 'GS') as $svc) {
                        $wpdb->delete($t_reg,
                            array('child_id' => $child_id, 'jour_date' => $date, 'service' => $svc),
                            array('%d', '%s', '%s')
                        );
                    }
                }
                if (in_array($service, array('GM', 'CANT', 'GS'), true)) {
                    $wpdb->delete($t_reg,
                        array('child_id' => $child_id, 'jour_date' => $date, 'service' => 'FORF'),
                        array('%d', '%s', '%s')
                    );
                }
            } else {
                $wpdb->delete(
                    $t_reg,
                    array('child_id' => $child_id, 'jour_date' => $date, 'service' => $service),
                    array('%d', '%s', '%s')
                );
            }

            $applied[] = $date;
        }

        wp_send_json_success(array('dates' => $applied));
    }

    /* ---------------- AJAX : valider et recevoir le récapitulatif ---------------- */

    public static function ajax_confirm() {
        check_ajax_referer('psc_front', 'nonce');

        $parent = Psc_Parents::current();
        if (!$parent) {
            wp_send_json_error(array('code' => 'auth'), 403);
        }

        // Évite l'envoi répété de récapitulatifs (clics multiples).
        if (!psc_rate_limit('recap_' . $parent->id, 5, 10 * MINUTE_IN_SECONDS)) {
            wp_send_json_error(array(
                'code'    => 'rate',
                'message' => 'Plusieurs récapitulatifs viennent d\'être envoyés. Merci de patienter quelques minutes.',
            ), 429);
        }

        $trimestre = self::active_trimestre();
        if (!$trimestre) {
            wp_send_json_error(array('code' => 'closed'), 403);
        }

        $children = self::children_of($parent->id);
        if (empty($children)) {
            wp_send_json_error(array('code' => 'nochild'), 400);
        }

        $reg_map = self::reg_map($trimestre->id, $children);

        // Calcul du diff par rapport au dernier récapitulatif envoyé
        $snapshot_key = 'psc_recap_snap_' . $parent->id . '_' . $trimestre->id;
        $prev_map     = get_transient($snapshot_key);
        if (!is_array($prev_map)) $prev_map = array();

        $diff_added   = array_keys(array_diff_key($reg_map, $prev_map));
        $diff_removed = array_keys(array_diff_key($prev_map, $reg_map));

        set_transient($snapshot_key, $reg_map, 180 * DAY_IN_SECONDS);

        $sent = Psc_Mailer::send_recap($parent, $trimestre, $children, $reg_map, psc_services(), $diff_added, $diff_removed);

        if (!$sent) {
            wp_send_json_error(array(
                'code'    => 'mail',
                'message' => 'L\'envoi de l\'e-mail a échoué. Vos inscriptions sont bien enregistrées ; contactez la mairie si besoin.',
            ), 500);
        }

        wp_send_json_success(array(
            'message' => sprintf('Récapitulatif envoyé à %s.', $parent->email),
        ));
    }

    /* ---------------- Gestion des enfants par le parent ---------------- */

    protected static function parent_form_redirect($msg) {
        wp_safe_redirect(add_query_arg('psc_msg', $msg, Psc_Mailer::form_page_url()));
        exit;
    }

    /**
     * Correction par le parent d'une faute de frappe sur l'état civil
     * (prénom / nom / date de naissance) d'un enfant déjà onboardé. La
     * classe (désormais par année scolaire, cf. wp_psc_child_school_years)
     * et le statut actif/sorti ne sont plus modifiables par la famille :
     * la classe se pose à l'inscription / au passage d'année, le statut
     * relève de la mairie.
     */
    public static function handle_parent_update_child_identity() {
        check_admin_referer('psc_parent_update_child_identity');

        $parent = Psc_Parents::current();
        if (!$parent) self::parent_form_redirect('auth');

        $child_id  = psc_post_int('child_id');
        $prenom    = psc_post('prenom');
        $nom       = psc_post('nom');
        $naissance = psc_valid_date(psc_post('naissance'));

        if ($prenom === '' || $nom === '') self::parent_form_redirect('child_invalid');

        global $wpdb;
        $t_child = psc_table('children');
        $owned = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_child WHERE id = %d AND parent_id = %d", $child_id, $parent->id
        ));
        if (!$owned) self::parent_form_redirect('invalid');

        $wpdb->update(
            $t_child,
            array(
                'prenom'         => mb_substr($prenom, 0, 190),
                'nom'            => mb_substr($nom, 0, 190),
                'date_naissance' => $naissance ?: null,
            ),
            array('id' => $child_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        self::parent_form_redirect('child_updated');
    }

    public static function handle_parent_add_child() {
        check_admin_referer('psc_parent_add_child');

        $parent = Psc_Parents::current();
        if (!$parent) self::parent_form_redirect('auth');

        $prenom    = psc_post('new_prenom');
        $nom       = psc_post('new_nom');
        $classe    = psc_post('new_classe');
        $naissance = psc_valid_date(psc_post('new_naissance'));
        $sans_porc = isset($_POST['new_sans_porc']) ? 1 : 0;
        $vegan     = isset($_POST['new_vegan']) ? 1 : 0;

        if ($prenom === '' || $nom === '') self::parent_form_redirect('child_invalid');

        // Le justificatif d'assurance scolaire est obligatoire dès la
        // création de la fiche enfant, quel que soit le point d'entrée
        // (ici le portail connecté ; cf. Psc_Requests::handle_submit()
        // pour le wizard public). Validé AVANT toute écriture en base : pas
        // de fiche enfant orpheline si le fichier est absent/invalide.
        $file_check = self::validate_assurance_file(isset($_FILES['new_assurance_file']) ? $_FILES['new_assurance_file'] : null);
        if ($file_check !== true) {
            $codes = array('too_large' => 'assurance_too_large', 'invalid_type' => 'assurance_invalid_type');
            self::parent_form_redirect(isset($codes[$file_check]) ? $codes[$file_check] : 'assurance_required');
        }

        $allowed = array_keys(psc_classe_options());
        if (!in_array($classe, $allowed, true)) $classe = '';

        $year_id = Psc_School_Years::active_id();
        if (!$year_id) self::parent_form_redirect('invalid');

        global $wpdb;
        $t_child = psc_table('children');
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t_child WHERE parent_id = %d", $parent->id
        ));
        if ($count >= psc_max_children_per_user()) self::parent_form_redirect('child_limit');

        $wpdb->insert($t_child, array(
            'parent_id'      => $parent->id,
            'nom'            => mb_substr($nom, 0, 190),
            'prenom'         => mb_substr($prenom, 0, 190),
            'date_naissance' => $naissance ?: null,
            'sans_porc'      => $sans_porc,
            'vegan'          => $vegan,
            'statut'         => 'actif',
            'created_at'     => current_time('mysql'),
        ), array('%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s'));
        $child_id = (int) $wpdb->insert_id;

        Psc_School_Years::enroll($child_id, $year_id, $classe, 'inscrit', current_time('mysql'));
        self::store_assurance_upload($child_id, $_FILES['new_assurance_file']);

        self::parent_form_redirect('child_added');
    }

    /**
     * Mise à jour de l'état civil / coordonnées / adresse du foyer depuis
     * "Mon profil". Le changement d'e-mail suit un chemin séparé
     * (Psc_Parents::request_email_change) : il ne prend effet qu'après
     * confirmation par lien, jamais immédiatement.
     */
    public static function handle_parent_update_profile() {
        check_admin_referer('psc_parent_update_profile');

        $parent = Psc_Parents::current();
        if (!$parent) self::parent_form_redirect('auth');

        $result = Psc_Parents::update($parent->id, array(
            'nom'              => psc_post('profil_nom'),
            'prenom'           => psc_post('profil_prenom'),
            'telephone_mobile' => psc_post('profil_tel_mobile'),
            'telephone_fixe'   => psc_post('profil_tel_fixe'),
            'adresse'          => psc_post('profil_adresse'),
            'code_postal'      => psc_post('profil_code_postal'),
            'ville'            => psc_post('profil_ville'),
        ));
        if (is_wp_error($result)) self::parent_form_redirect('profil_error');

        $new_email = strtolower(sanitize_email(psc_post('profil_email')));
        if ($new_email && $new_email !== $parent->email) {
            $r = Psc_Parents::request_email_change($parent->id, $new_email);
            if (is_wp_error($r)) self::parent_form_redirect('email_taken');
            self::parent_form_redirect('profil_updated_email_pending');
        }

        self::parent_form_redirect('profil_updated');
    }

    /**
     * "Annulation prestations" depuis le tableau de bord : annule, pour un
     * enfant donné, une sélection de prestations individuelles (pas
     * forcément toute une journée). $_POST['items'] est un tableau de
     * chaînes "YYYY-MM-DD|SERVICE" (une par case cochée) — cf.
     * absence_candidates() pour la construction de la liste proposée et
     * assets/js/portal.js pour le remplissage du formulaire. Un forfait
     * (FORF) est indivisible : les 3 lignes GM/CANT/GS qui le représentent
     * dans l'UI portent toutes service=FORF, donc cocher n'importe laquelle
     * (ou plusieurs) revient à annuler le même unique forfait — dédoublonné
     * ci-dessous par date+service avant toute suppression. Même ordre de
     * vérification que ajax_toggle() par prestation : appartenance de
     * l'enfant, trimestre actif, jour ouvert, délai de préavis
     * (psc_lock_hours) non dépassé — une prestation qui ne passe plus ces
     * contrôles (entre le chargement de la popin et la soumission) est
     * silencieusement ignorée plutôt que de faire échouer tout le lot.
     */
    public static function handle_cancel_absence() {
        check_admin_referer('psc_cancel_absence');

        $parent = Psc_Parents::current();
        if (!$parent) self::parent_form_redirect('auth');

        global $wpdb;
        $child_id  = psc_post_int('child_id');
        $raw_items = isset($_POST['items']) && is_array($_POST['items']) ? wp_unslash($_POST['items']) : array();

        $pairs = array();
        foreach ($raw_items as $raw) {
            $parts = explode('|', (string) $raw, 2);
            if (count($parts) !== 2) continue;
            $date    = psc_valid_date($parts[0]);
            $service = $parts[1];
            if (!$date || !in_array($service, psc_allowed_services(), true)) continue;
            $pairs[$date . '|' . $service] = array('date' => $date, 'service' => $service);
        }
        if (!$child_id || !$pairs) self::parent_form_redirect('absence_invalid');

        $t_child = psc_table('children');
        $owned = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_child WHERE id = %d AND parent_id = %d", $child_id, $parent->id
        ));
        if (!$owned) self::parent_form_redirect('absence_invalid');

        $trimestre = self::active_trimestre();
        if (!$trimestre) self::parent_form_redirect('absence_invalid');

        $t_days = psc_table('calendar_days');
        $t_reg  = psc_table('registrations');

        $cancelled_by_date = array(); // date => [services annulés]
        foreach ($pairs as $pair) {
            $date    = $pair['date'];
            $service = $pair['service'];

            $day = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $t_days WHERE trimestre_id = %d AND jour_date = %s AND is_open = 1",
                $trimestre->id, $date
            ));
            if (!$day || psc_is_locked($date)) continue;

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $t_reg WHERE child_id = %d AND jour_date = %s AND service = %s",
                $child_id, $date, $service
            ));
            if (!$exists) continue; // déjà annulé entre-temps

            $wpdb->delete($t_reg,
                array('child_id' => $child_id, 'jour_date' => $date, 'service' => $service),
                array('%d', '%s', '%s')
            );
            $cancelled_by_date[$date][] = $service;
        }

        if (!$cancelled_by_date) self::parent_form_redirect('absence_invalid');

        $child = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_child WHERE id = %d", $child_id));
        foreach ($cancelled_by_date as $date => $services) {
            Psc_Mailer::notify_absence_cancelled($parent, $child, $date, $services);
        }

        self::parent_form_redirect('absence_cancelled');
    }

    /* ---------------- Réinscription annuelle (espace famille) ---------------- */

    /** Année scolaire "en préparation" la plus récente — cible de la réinscription. */
    protected static function reinscription_target_year() {
        global $wpdb;
        return $wpdb->get_row(
            "SELECT * FROM " . psc_table('school_years') . " WHERE statut = 'preparation' ORDER BY id DESC LIMIT 1"
        );
    }

    /**
     * Réinscription d'une famille pour l'année en préparation : par
     * enfant actif, confirmation ou retrait, avec règlement intérieur
     * (accepté une fois pour la famille) et nouveau justificatif
     * d'assurance obligatoires pour chaque enfant confirmé. Un enfant
     * décoché n'est pas sorti pour autant — cf. absence de ligne
     * child_school_years pour l'année cible, lu comme "non_reinscrit" par
     * le backoffice (Psc_Admin) une fois la fenêtre refermée.
     */
    public static function handle_parent_reinscription() {
        check_admin_referer('psc_parent_reinscription');

        $parent = Psc_Parents::current();
        if (!$parent) self::parent_form_redirect('auth');

        if (!self::reinscription_window_open()) self::parent_form_redirect('reinscription_invalid');

        $target_year = self::reinscription_target_year();
        if (!$target_year) self::parent_form_redirect('reinscription_invalid');

        if (empty($_POST['reglement_accepted'])) self::parent_form_redirect('reinscription_required');

        $children = self::children_of($parent->id, true);
        if (!$children) self::parent_form_redirect('reinscription_invalid');

        $reglement_accepted_at = current_time('mysql');
        $confirmed_count = 0;

        foreach ($children as $child) {
            if (empty($_POST['confirm_' . $child->id])) continue; // enfant retiré pour la nouvelle année

            $classe_actuelle = Psc_School_Years::classe_for($child->id); // année en cours (active)
            $classe_proposee = $classe_actuelle !== '' ? psc_classe_superieure($classe_actuelle) : null;
            if (!$classe_proposee || $classe_proposee === 'sortie') continue; // fin de cycle : rien à réinscrire

            $file = isset($_FILES['assurance_' . $child->id]) ? $_FILES['assurance_' . $child->id] : null;
            $file_check = self::validate_assurance_file($file);
            if ($file_check !== true) {
                self::parent_form_redirect('reinscription_required');
            }

            Psc_School_Years::enroll($child->id, $target_year->id, $classe_proposee, 'inscrit', $reglement_accepted_at);
            self::store_assurance_upload($child->id, $file, $target_year->id);
            $confirmed_count++;
        }

        if (!$confirmed_count) self::parent_form_redirect('reinscription_required');

        self::parent_form_redirect('reinscription_confirmee');
    }

    /* ---------------- Personnes autorisées à récupérer (espace famille) ---------------- */

    /** Vrai si l'enfant de $person appartient bien à $parent_id. */
    protected static function pickup_person_owned_by($person, $parent_id) {
        global $wpdb;
        $t_child = psc_table('children');
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_child WHERE id = %d AND parent_id = %d", (int) $person->child_id, $parent_id
        ));
    }

    protected static function pickup_fields_from_post() {
        return array(
            'prenom'         => psc_post('prenom'),
            'nom'            => psc_post('nom'),
            'telephone'      => psc_post('telephone'),
            'lien'           => psc_post('lien'),
            'piece_identite' => isset($_POST['piece_identite']) ? 1 : 0,
        );
    }

    /**
     * Ajout d'une personne autorisée à récupérer un enfant depuis "Mes
     * enfants". Le nonce prouve l'intention, mais c'est la vérification
     * d'appartenance ci-dessous qui empêche une famille connectée
     * d'ajouter une entrée sur un enfant qui n'est pas le sien.
     */
    public static function handle_parent_add_pickup_person() {
        check_admin_referer('psc_parent_pickup_person');

        $parent = Psc_Parents::current();
        if (!$parent) self::parent_form_redirect('auth');

        global $wpdb;
        $child_id = psc_post_int('child_id');
        $t_child  = psc_table('children');
        $owned = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_child WHERE id = %d AND parent_id = %d", $child_id, $parent->id
        ));
        if (!$owned) self::parent_form_redirect('pickup_invalid');

        $result = Psc_Pickup_Persons::add($child_id, self::pickup_fields_from_post(), 'parent');
        if (is_wp_error($result)) self::parent_form_redirect('pickup_invalid');

        self::parent_form_redirect('pickup_added');
    }

    /**
     * Modification d'une personne autorisée existante. Un pickup_id
     * n'appartient à un parent qu'à travers l'enfant qu'il concerne —
     * c'est cet enfant, pas seulement la ligne, qui est revérifié.
     */
    public static function handle_parent_update_pickup_person() {
        check_admin_referer('psc_parent_pickup_person');

        $parent = Psc_Parents::current();
        if (!$parent) self::parent_form_redirect('auth');

        $pickup_id = psc_post_int('pickup_id');
        $person = Psc_Pickup_Persons::get($pickup_id);
        if (!$person || !self::pickup_person_owned_by($person, $parent->id)) {
            self::parent_form_redirect('pickup_invalid');
        }

        $result = Psc_Pickup_Persons::update($pickup_id, self::pickup_fields_from_post(), 'parent');
        if (is_wp_error($result)) self::parent_form_redirect('pickup_invalid');

        self::parent_form_redirect('pickup_updated');
    }

    /** Retrait (soft-delete) d'une personne autorisée. */
    public static function handle_parent_remove_pickup_person() {
        check_admin_referer('psc_parent_remove_pickup_person');

        $parent = Psc_Parents::current();
        if (!$parent) self::parent_form_redirect('auth');

        $pickup_id = psc_post_int('pickup_id');
        $person = Psc_Pickup_Persons::get($pickup_id);
        if (!$person || !self::pickup_person_owned_by($person, $parent->id)) {
            self::parent_form_redirect('pickup_invalid');
        }

        $result = Psc_Pickup_Persons::remove($pickup_id, 'parent');
        if (is_wp_error($result)) self::parent_form_redirect('pickup_invalid');

        self::parent_form_redirect('pickup_removed');
    }

    /* ---------------- Factures (espace famille) ---------------- */

    /**
     * Téléchargement d'une facture par la famille elle-même. Le nonce
     * prouve l'intention, mais le contrôle qui compte vraiment est la
     * vérification d'appartenance ci-dessous : sans elle, une famille
     * connectée pourrait télécharger la facture d'une autre en devinant
     * un identifiant dans l'URL.
     */
    public static function handle_parent_download_invoice() {
        $parent = Psc_Parents::current();
        if (!$parent) {
            wp_die(esc_html__('Vous devez être connecté pour accéder à cette facture.', 'periscolaire-registration'), '', array('response' => 403));
        }

        $invoice_id = psc_get_int('invoice_id');
        check_admin_referer('psc_parent_download_invoice_' . $invoice_id);

        $invoice = Psc_Invoices::get($invoice_id);
        if (!$invoice || (int) $invoice->parent_id !== (int) $parent->id) {
            wp_die(esc_html__('Facture introuvable.', 'periscolaire-registration'), '', array('response' => 404));
        }

        Psc_Invoices::download($invoice_id);
    }

    /* ---------------- Espace famille v2 ("Family Portal") ---------------- */

    protected static function portal_tab_defs() {
        $tabs = array(
            'dashboard' => array(
                'label' => 'Tableau de bord',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9h13v-9"/><path d="M10 19v-6h4v6"/></svg>',
            ),
            'cantine' => array(
                'label' => 'Cantine & Garderie',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3.5" y="5" width="17" height="15" rx="1"/><path d="M3.5 9.5h17"/><path d="M8 3v3M16 3v3"/></svg>',
            ),
            'menu' => array(
                'label' => 'Menu de la semaine',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 3v8a2 2 0 0 0 4 0V3M6 6h4"/><path d="M14 3c-1.2 1.3-1.2 6 0 8M14 3v18M17 3v6a2 2 0 0 0 2 2v10"/></svg>',
            ),
            'enfants' => array(
                'label' => 'Mes enfants',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="7" r="3.2"/><path d="M5 20c0-3.5 3.2-6 7-6s7 2.5 7 6"/></svg>',
            ),
            'factures' => array(
                'label' => 'Mes factures',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2Z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg>',
            ),
            'profil' => array(
                'label' => 'Mon profil',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-4 3.1-7 7-7s7 3 7 7"/></svg>',
            ),
            'documents' => array(
                'label' => 'Documents',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M7 3h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/></svg>',
            ),
        );
        // Onglet supplémentaire, visible seulement pendant la fenêtre de
        // réinscription ouverte par la mairie (Réglages) — pas une gestion
        // courante comme les autres onglets, une action annuelle ponctuelle.
        if (self::reinscription_window_open()) {
            $tabs['reinscription'] = array(
                'label' => 'Réinscription',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 12a8 8 0 1 1 2.34 5.66"/><path d="M4 21v-5h5"/></svg>',
            );
        }
        return $tabs;
    }

    /** Fenêtre de réinscription (Réglages) : ouverte aujourd'hui ? */
    public static function reinscription_window_open() {
        $debut = get_option('psc_reinscription_debut', '');
        $fin   = get_option('psc_reinscription_fin', '');
        if (!$debut || !$fin) return false;
        $today = current_time('Y-m-d');
        return $today >= $debut && $today <= $fin;
    }

    /**
     * Onglet actif : ?psc_tab= si connu, sinon le tableau de bord. Un
     * message lié à la gestion des enfants (retour d'un POST classique,
     * cf. handle_parent_*) ramène toujours sur l'onglet "Mes enfants",
     * quel que soit l'onglet d'où le formulaire a été soumis — même chose
     * pour "Mon profil" avec les messages liés à sa propre mise à jour.
     */
    protected static function resolve_active_tab($psc_msg) {
        $known = array_keys(self::portal_tab_defs());
        $requested = isset($_GET['psc_tab']) ? sanitize_key(wp_unslash($_GET['psc_tab'])) : '';
        $tab = in_array($requested, $known, true) ? $requested : 'dashboard';

        if (in_array($psc_msg, array(
            'child_updated', 'child_added', 'child_invalid', 'child_limit',
            'assurance_uploaded', 'assurance_invalid', 'assurance_upload_failed',
            'assurance_too_large', 'assurance_invalid_type', 'assurance_required',
            'pickup_added', 'pickup_updated', 'pickup_removed', 'pickup_invalid',
        ), true)) {
            $tab = 'enfants';
        }
        if (in_array($psc_msg, array(
            'profil_updated', 'profil_updated_email_pending', 'profil_error',
            'email_taken', 'email_changed', 'email_change_cancelled',
            'bad_email_token', 'expired_email_token',
        ), true)) {
            $tab = 'profil';
        }
        if (in_array($psc_msg, array(
            'reinscription_confirmee', 'reinscription_invalid', 'reinscription_required',
        ), true)) {
            $tab = 'reinscription';
        }
        return $tab;
    }

    protected static function portal_tabs_data() {
        $base = remove_query_arg(array('psc_tab', 'psc_semaine', 'psc_msg'));
        $tabs = array();
        foreach (self::portal_tab_defs() as $key => $def) {
            $tabs[$key] = array(
                'label' => $def['label'],
                'icon'  => $def['icon'],
                'url'   => add_query_arg('psc_tab', $key, $base),
            );
        }
        return $tabs;
    }

    /**
     * Vrai si la semaine (lundi donné) contient au moins un jour d'école
     * réel — distingue "vacances scolaires" de "semaine d'école dont le
     * menu n'a pas encore été saisi", deux états à ne pas confondre dans
     * l'affichage (une famille ne doit pas croire qu'un menu manque alors
     * que l'école est simplement fermée).
     */
    protected static function week_has_school_day($monday) {
        foreach (Psc_Menus::JOUR_OFFSETS as $offset) {
            $day_date = gmdate('Y-m-d', strtotime($monday . " +{$offset} days"));
            if (psc_is_school_day($day_date)) return true;
        }
        return false;
    }

    /**
     * Jours du menu (parmi lundi/mardi/jeudi/vendredi) pour la semaine
     * donnée. Tableau vide si l'école est fermée toute la semaine, ou si
     * la semaine est scolaire mais que le menu n'a pas encore été saisi
     * (cf. week_has_school_day() pour distinguer les deux côté affichage).
     */
    protected static function menu_days_for_week($monday) {
        if (!self::week_has_school_day($monday)) return array();

        $menu = Psc_Menus::get_by_week($monday);
        if (!$menu) return array();

        $days_out = array();
        foreach (Psc_Menus::jour_labels() as $key => $label) {
            $d_offset = Psc_Menus::JOUR_OFFSETS[$key];
            $d_date = gmdate('Y-m-d', strtotime($monday . " +{$d_offset} days"));
            if (!psc_is_school_day($d_date)) continue;
            $content = trim((string) $menu->$key);
            if ($content === '') continue;
            $days_out[] = array('day' => $label, 'dish' => $content);
        }
        return $days_out;
    }

    /**
     * Libellé "Semaine du 8 au 12 juin 2026" (jours d'école réels,
     * lundi à vendredi) — distinct du format du widget public (une seule
     * date), copie exacte de la formulation du handoff.
     */
    protected static function week_range_label($monday) {
        $friday = gmdate('Y-m-d', strtotime($monday . ' +4 days'));
        $start_day = date_i18n('j', strtotime($monday));
        $end_day   = date_i18n('j', strtotime($friday));
        $end_month = date_i18n('F', strtotime($friday));
        $end_year  = date_i18n('Y', strtotime($friday));

        if (date('Y-m', strtotime($monday)) === date('Y-m', strtotime($friday))) {
            return sprintf('Semaine du %s au %s %s %s', $start_day, $end_day, $end_month, $end_year);
        }
        $start_month = date_i18n('F', strtotime($monday));
        $start_year  = date_i18n('Y', strtotime($monday));
        if ($start_year !== $end_year) {
            return sprintf('Semaine du %s %s %s au %s %s %s', $start_day, $start_month, $start_year, $end_day, $end_month, $end_year);
        }
        return sprintf('Semaine du %s %s au %s %s %s', $start_day, $start_month, $end_day, $end_month, $end_year);
    }

    /**
     * Données de navigation du menu de cantine par semaine — partagées
     * entre l'onglet "Menu de la semaine" du portail connecté ($extra_args
     * = psc_tab=menu) et le widget public de la vue invité (aucun argument
     * supplémentaire). $week_override permet à l'appel AJAX (ajax_menu_week)
     * de demander une semaine précise sans passer par $_GET — la requête
     * initiale (page complète) continue de lire ?psc_semaine dans l'URL.
     * $base_url_override : nécessaire pour ce même appel AJAX — sans lui,
     * add_query_arg()/remove_query_arg() prendraient par défaut l'URL de la
     * requête en cours (admin-ajax.php), et les liens ←/→ renvoyés
     * pointeraient vers admin-ajax.php au lieu de la page famille.
     */
    protected static function menu_nav_data($extra_args = array(), $week_override = null, $base_url_override = null) {
        if ($week_override !== null) {
            $requested = $week_override;
        } else {
            $requested = isset($_GET['psc_semaine']) ? sanitize_text_field(wp_unslash($_GET['psc_semaine'])) : '';
        }
        $menu_week = $requested ? psc_week_start($requested) : false;
        if (!$menu_week) {
            $menu_week = psc_week_start(current_time('Y-m-d'));
        }

        $days = self::menu_days_for_week($menu_week);

        $prev_week = gmdate('Y-m-d', strtotime($menu_week . ' -7 days'));
        $next_week = gmdate('Y-m-d', strtotime($menu_week . ' +7 days'));
        $base = $base_url_override !== null
            ? remove_query_arg(array('psc_semaine', 'psc_msg'), $base_url_override)
            : remove_query_arg(array('psc_semaine', 'psc_msg'));

        return array(
            'week_label'      => self::week_range_label($menu_week),
            'is_current_week' => ($menu_week === psc_week_start(current_time('Y-m-d'))),
            'has_content'     => !empty($days),
            'no_school_week'  => !self::week_has_school_day($menu_week),
            'days'            => $days,
            'prev_url'        => add_query_arg(array_merge($extra_args, array('psc_semaine' => $prev_week)), $base),
            'next_url'        => add_query_arg(array_merge($extra_args, array('psc_semaine' => $next_week)), $base),
            'reset_url'       => $extra_args ? add_query_arg($extra_args, $base) : $base,
        );
    }

    protected static function portal_menu_data($week_override = null, $base_url_override = null) {
        return self::menu_nav_data(array('psc_tab' => 'menu'), $week_override, $base_url_override);
    }

    protected static function guest_menu_data() {
        return self::menu_nav_data();
    }

    /**
     * Navigation par semaine du menu (onglet "Menu de la semaine", portail
     * connecté) sans rechargement de page : ne renvoie que le HTML du bloc
     * (nav + tableau/message), identique à templates/portal-menu-block.php
     * inclus lors du rendu complet de la page — même données, même charte,
     * seul le mécanisme de chargement change.
     */
    public static function ajax_menu_week() {
        check_ajax_referer('psc_front', 'nonce');

        $semaine = psc_post('semaine');
        $psc_portal_menu = self::portal_menu_data($semaine !== '' ? $semaine : false, Psc_Mailer::form_page_url());

        ob_start();
        include PSC_PATH . 'templates/portal-menu-block.php';
        $html = ob_get_clean();

        wp_send_json_success(array('html' => $html));
    }

    /**
     * Statistiques du tableau de bord : cumul période (tous enfants
     * actifs confondus), prochaine facture non envoyée, menu de la
     * semaine réelle en cours, résumé par enfant.
     */
    protected static function dashboard_data($parent, $children, $days_by_month, $reg_map, $services, $invoices) {
        $days_count = 0;
        $amount = 0.0;
        $children_summaries = array();

        foreach ($children as $child) {
            $child_days = 0;
            $child_amount = 0.0;
            foreach ($days_by_month as $days) {
                foreach ($days as $d) {
                    $has_reg = false;
                    foreach (psc_allowed_services() as $s) {
                        if (isset($reg_map[$child->id . '|' . $d->jour_date . '|' . $s])) {
                            $has_reg = true;
                            $child_amount += (float) $services[$s]['price'];
                        }
                    }
                    if ($has_reg) $child_days++;
                }
            }
            $days_count += $child_days;
            $amount += $child_amount;

            $diet_bits = array();
            if ((int) $child->sans_porc) $diet_bits[] = 'Sans porc';
            if ((int) $child->vegan) $diet_bits[] = 'Sans viande';
            $meta = Psc_School_Years::classe_for($child->id);
            if ($diet_bits) $meta .= ($meta !== '' ? ' · ' : '') . implode(', ', $diet_bits);
            if ($meta === '') $meta = '—';

            $children_summaries[] = array(
                'name'    => trim($child->prenom . ' ' . $child->nom),
                'meta'    => $meta,
                'summary' => sprintf(
                    '%d jour%s déclaré%s · %s €',
                    $child_days, $child_days > 1 ? 's' : '', $child_days > 1 ? 's' : '',
                    number_format_i18n($child_amount, 2)
                ),
            );
        }

        $next_invoice = null;
        $pending = array_values(array_filter($invoices, function ($i) { return empty($i->sent_at); }));
        if ($pending) {
            usort($pending, function ($a, $b) { return strcmp($a->mois, $b->mois); });
            $next_invoice = array(
                'mois_label'   => Psc_Invoices::month_label($pending[0]->mois),
                'status_label' => 'En attente',
            );
        }

        $current_week = psc_week_start(current_time('Y-m-d'));

        return array(
            'title'          => $parent->nom !== '' ? 'Famille ' . $parent->nom : 'Bienvenue',
            'days_label'     => $days_count . ($days_count > 1 ? ' jours' : ' jour'),
            'amount_label'   => number_format_i18n($amount, 2),
            'next_invoice'   => $next_invoice,
            'menu'           => self::menu_days_for_week($current_week),
            'menu_no_school' => !self::week_has_school_day($current_week),
            'children'       => $children_summaries,
        );
    }

    /**
     * Prestations à venir, non verrouillées, déjà cochées pour chaque
     * enfant — sert à peupler la popin "Annulation prestations" du tableau
     * de bord. Un forfait journée (FORF) couvre GM+CANT+GS pour un prix
     * unique et indivisible : il est listé comme 3 prestations séparées
     * pour la lisibilité, mais les 3 pointent vers la même inscription —
     * en cocher une seule annule le forfait en entier (cf.
     * handle_cancel_absence(), qui dédoublonne par date+service).
     * Un enfant sans prestation annulable n'apparaît pas dans le résultat.
     */
    protected static function absence_candidates($children) {
        global $wpdb;
        $t_reg  = psc_table('registrations');
        $t_days = psc_table('calendar_days');
        $today  = current_time('Y-m-d');
        $svc_labels = psc_services();

        $out = array();
        foreach ($children as $child) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT r.jour_date, r.service FROM $t_reg r
                 INNER JOIN $t_days d ON d.jour_date = r.jour_date AND d.is_open = 1
                 WHERE r.child_id = %d AND r.jour_date >= %s
                 ORDER BY r.jour_date ASC",
                $child->id, $today
            ));
            $items = array();
            foreach ($rows as $row) {
                if (psc_is_locked($row->jour_date)) continue;
                $day_label = psc_day_label($row->jour_date) . ' ' . date_i18n('d/m/Y', strtotime($row->jour_date));
                $sub_services = $row->service === 'FORF' ? array('GM', 'CANT', 'GS') : array($row->service);
                foreach ($sub_services as $sub) {
                    $items[] = array(
                        'date'    => $row->jour_date,
                        'service' => $row->service, // valeur réellement annulée (FORF si forfait)
                        'label'   => $day_label . ' — ' . ($svc_labels[$sub]['label'] ?? $sub),
                    );
                }
            }
            if ($items) {
                $out[$child->id] = array(
                    'name'  => trim($child->prenom . ' ' . $child->nom),
                    'items' => $items,
                );
            }
        }
        return $out;
    }

    /* ---------------- Vue invité v2 ("Vue visiteur") ---------------- */

    /**
     * Un message d'erreur retourné après une soumission de demande ratée
     * (validation serveur inchangée, cf. Psc_Requests::handle_submit) doit
     * rouvrir le stepper sur l'étape concernée — sinon l'erreur affichée
     * ne correspond à aucun champ visible. Le rechargement complet de la
     * page (redirection) efface aussi tout ce que le parent avait saisi,
     * y compris le mode de paiement choisi : pour une erreur liée au
     * prélèvement, on rouvre donc le panneau SEPA plutôt que de retomber
     * sur "Chèque ou espèces" (qui masquerait le champ à corriger).
     */
    protected static function wizard_error_context($psc_msg) {
        $map = array(
            'coordonnees_incomplete'   => array('step' => 0, 'sepa' => false),
            'need_child'               => array('step' => 1, 'sepa' => false),
            'child_incomplete'         => array('step' => 1, 'sepa' => false),
            'assurance_required'       => array('step' => 1, 'sepa' => false),
            'assurance_too_large'      => array('step' => 1, 'sepa' => false),
            'assurance_invalid_type'   => array('step' => 1, 'sepa' => false),
            'sepa_reglement_required'  => array('step' => 2, 'sepa' => true),
            'sepa_missing'             => array('step' => 2, 'sepa' => true),
            'bad_iban'                 => array('step' => 2, 'sepa' => true),
            'bad_bic'                  => array('step' => 2, 'sepa' => true),
            'reglement_required'       => array('step' => 3, 'sepa' => false),
        );
        if (isset($map[$psc_msg])) {
            return array(
                'step'         => $map[$psc_msg]['step'],
                'payment_mode' => $map[$psc_msg]['sepa'] ? 'prelevement' : 'autre',
                'has_error'    => true,
            );
        }
        return array('step' => 0, 'payment_mode' => 'autre', 'has_error' => false);
    }

    /* ---------------- Affichage ---------------- */

    public static function shortcode($atts) {
        ob_start();

        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        $parent = Psc_Parents::current();

        if (!$parent) {
            $psc_guest_menu = self::guest_menu_data();
            $psc_wizard = self::wizard_error_context($psc_msg);
            include PSC_PATH . 'templates/frontend-guest.php';
            return ob_get_clean();
        }

        $trimestre    = self::active_trimestre();
        $all_children = self::children_of($parent->id);               // pour la section "Mes enfants"
        $children     = self::children_of($parent->id, true);         // uniquement actifs → calendrier
        $days_by_month = array();
        $reg_map = array();

        if ($trimestre) {
            global $wpdb;
            $t_days = psc_table('calendar_days');
            $days = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $t_days WHERE trimestre_id = %d AND is_open = 1 ORDER BY jour_date",
                $trimestre->id
            ));
            foreach ($days as $d) {
                $days_by_month[date_i18n('F Y', strtotime($d->jour_date))][] = $d;
            }
            $reg_map = self::reg_map($trimestre->id, $children);
        }

        $services = psc_services();
        $invoices = Psc_Invoices::get_for_parent($parent->id);

        $active_tab       = self::resolve_active_tab($psc_msg);
        $psc_portal_tabs  = self::portal_tabs_data();
        $psc_portal_menu  = self::portal_menu_data();
        $psc_portal_dashboard = self::dashboard_data($parent, $children, $days_by_month, $reg_map, $services, $invoices);
        $psc_portal_absence_days = self::absence_candidates($children);
        $psc_assurance_map = self::assurance_map($all_children);

        // Uniquement les enfants actifs : un enfant sorti disparaît du
        // planning (cf. $children plus haut), la liste des personnes
        // autorisées le suit pour la même raison.
        $psc_pickup_map = array();
        foreach ($children as $child) {
            $psc_pickup_map[$child->id] = Psc_Pickup_Persons::for_child($child->id);
        }

        $psc_portal_reinscription = null;
        if (isset($psc_portal_tabs['reinscription'])) {
            $psc_portal_reinscription = array(
                'target_year' => self::reinscription_target_year(),
                'children'    => array_map(function ($child) {
                    $classe_actuelle = Psc_School_Years::classe_for($child->id);
                    $classe_proposee = $classe_actuelle !== '' ? psc_classe_superieure($classe_actuelle) : null;
                    return array(
                        'id'              => $child->id,
                        'name'            => trim($child->prenom . ' ' . $child->nom),
                        'classe_actuelle' => $classe_actuelle,
                        'classe_proposee' => $classe_proposee, // null|code classe|'sortie'
                    );
                }, $children),
            );
        }

        include PSC_PATH . 'templates/frontend-portal.php';
        return ob_get_clean();
    }
}
