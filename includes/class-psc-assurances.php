<?php
if (!defined('ABSPATH')) exit;

/**
 * Justificatifs d'assurance scolaire, du dépôt à la suppression.
 *
 * Ces documents nominatifs concernent des mineurs ; leur manipulation est
 * un sujet en soi — validation du type, écriture en zone privée hors
 * racine web, diffusion sous contrôle d'accès. Elle vivait pourtant dans
 * Psc_Frontend, la classe du portail famille, où l'administration et les
 * demandes d'inscription venaient la chercher : trois couches
 * dépendaient d'un écran pour lire un fichier.
 *
 * L'emplacement des fichiers se décidait par ailleurs à deux endroits —
 * ici pour un enfant inscrit, dans Psc_Requests pour une demande en
 * attente. Une seule classe en décide désormais, ce qui compte pour des
 * documents qu'il faut savoir retrouver et effacer.
 */
class Psc_Assurances {

    /**
     * Racine de tous les justificatifs, sous le dossier privé. Un seul
     * endroit décide de l'arborescence : c'est ce qui permet de les
     * retrouver — et de les effacer — sans en oublier.
     */
    const BASE = 'periscolaire/assurances';

    /**
     * Un enfant a-t-il fourni son assurance scolaire pour l'année scolaire
     * active ? Un document fourni l'an dernier ne compte plus une fois
     * l'année suivante activée par la mairie : pas de tâche cron
     * nécessaire, la vérification se fait à la volée à chaque tentative de
     * déclaration d'un jour (cf. ajax_toggle()).
     */
    public static function has_valid($child_id) {
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
    public static function map_for($children) {
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
    public static function rel_path($child_id, $rentree_year, $ext) {
        return self::BASE . '/' . $rentree_year . '/child-' . (int) $child_id . '.' . $ext;
    }

    /**
     * Chemin relatif d'un justificatif encore rattaché à une demande, avant
     * qu'un enfant n'existe. Exposé parce que ce chemin est enregistré dans
     * la demande elle-même : le reconstruire à la main ailleurs ferait de
     * nouveau dépendre l'emplacement des fichiers de deux endroits.
     */
    public static function pending_rel_path($request_id, $child_index, $ext) {
        return self::BASE . '/pending/' . (int) $request_id . '/child-' . (int) $child_index . '.' . $ext;
    }

    /**
     * Zone d'attente pour les justificatifs d'assurance scolaire uploadés
     * avec le wizard public : aucun child_id n'existe encore à ce stade
     * (la demande doit d'abord être vérifiée par e-mail PUIS approuvée par
     * la mairie). Les fichiers y restent jusqu'à ce que handle_approve()
     * les rattache à un vrai enfant (Psc_Frontend::promote_pending_assurance())
     * ou que la demande soit purgée sans jamais avoir été approuvée.
     */
    public static function pending_dir($request_id) {
        return psc_private_path(self::BASE . '/pending/' . (int) $request_id);
    }

    /**
     * Validation pure d'un fichier d'assurance scolaire (présence, taille,
     * type), sans aucun effet de bord — utilisable en pré-contrôle avant de
     * créer quoi que ce soit en base (ex : ajout d'un enfant, où l'on ne
     * veut pas insérer la fiche si le justificatif obligatoire est absent).
     * Retourne true, ou un code : 'required'|'too_large'|'invalid_type'.
     */
    public static function validate_upload($file) {
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
    public static function store_upload($child_id, $file, $school_year_id = null) {
        $check = self::validate_upload($file);
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

        $rel_dir = self::BASE . '/' . $rentree_year;
        $dir = psc_private_path($rel_dir);
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

        $rel_path = self::rel_path($child_id, $rentree_year, $filetype['ext']);
        $target   = psc_private_path($rel_path);

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return 'failed';
        }

        self::upsert_row($child_id, $rel_path, $file['name'], $year_id);
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
    public static function promote_pending($child_id, $abs_source_path, $original_filename) {
        $ext = strtolower(pathinfo($abs_source_path, PATHINFO_EXTENSION));
        if (!in_array($ext, array('pdf', 'jpg', 'jpeg', 'png'), true)) return false;

        $rentree_year = psc_rentree_year();
        $rel_dir = self::BASE . '/' . $rentree_year;
        $dir = psc_private_path($rel_dir);
        if (!wp_mkdir_p($dir)) return false;

        $rel_path = self::rel_path($child_id, $rentree_year, $ext);
        $target   = psc_private_path($rel_path);

        if (!rename($abs_source_path, $target)) return false;

        self::upsert_row($child_id, $rel_path, $original_filename ?: basename($abs_source_path));
        return true;
    }

    /**
     * N'écrit QUE les colonnes assurance_* de la ligne enfant x année
     * active — ne touche jamais classe/statut/règlement, déjà posés par
     * ailleurs (approbation de demande, passage d'année) ou à venir (Mes
     * enfants ne gère que l'assurance, jamais la classe).
     */
    public static function upsert_row($child_id, $rel_path, $original_filename, $school_year_id = null) {
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
     * Streame un document d'assurance scolaire. Partagé par le
     * téléchargement côté parent (avec contrôle d'appartenance) et côté
     * admin (avec contrôle de capacité) — même principe que
     * Psc_Invoices::download().
     */
    public static function stream($rel_path, $filename) {
        $path = psc_private_path($rel_path);

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

    public static function delete_pending_files($request_id) {
        $dir = self::pending_dir($request_id);
        if (!is_dir($dir)) return;
        foreach (glob(trailingslashit($dir) . '*') as $file) {
            @unlink($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }
}
