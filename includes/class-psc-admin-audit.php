<?php
if (!defined('ABSPATH')) exit;

/**
 * Écran de consultation du journal d'audit — réservé à psc_view_audit
 * (administrateurs seulement, cf. Psc_Installer::sync_roles()) : le
 * journal agrège l'activité de toutes les familles et de tous les
 * agents, une capacité plus large que psc_manage_periscolaire.
 */
class Psc_Admin_Audit {
    const PER_PAGE = 50;

    /** Au-delà, l'ODS (construit en mémoire avant d'être zippé) refuse plutôt que d'épuiser la mémoire PHP. */
    const ODS_MAX_ROWS = 20000;

    public static function init() {
        add_action('admin_post_psc_audit_verify', array(__CLASS__, 'handle_verify'));
        add_action('admin_post_psc_audit_reset_failures', array(__CLASS__, 'handle_reset_failures'));
        add_action('admin_post_psc_audit_export_csv', array(__CLASS__, 'handle_export_csv'));
        add_action('admin_post_psc_audit_export_ods', array(__CLASS__, 'handle_export_ods'));
        add_action('admin_post_psc_audit_save_retention', array(__CLASS__, 'handle_save_retention'));
    }

    private static function guard_audit($nonce_action = null) {
        if (!current_user_can('psc_view_audit')) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        if ($nonce_action) check_admin_referer($nonce_action);
    }

    /**
     * Filtres de l'écran, revalidés côté serveur — jamais fait confiance
     * aux paramètres GET pour construire la requête (Psc_Audit::query()
     * les prépare de toute façon, mais les bornes ci-dessous évitent par
     * exemple une catégorie ou un résultat hors liste connue).
     */
    public static function filters_from_request() {
        $du = isset($_GET['du']) ? sanitize_text_field(wp_unslash($_GET['du'])) : gmdate('Y-m-d', strtotime('-30 days'));
        $au = isset($_GET['au']) ? sanitize_text_field(wp_unslash($_GET['au'])) : gmdate('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du)) $du = gmdate('Y-m-d', strtotime('-30 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $au)) $au = gmdate('Y-m-d');

        $acteur_type = isset($_GET['acteur_type']) ? sanitize_key(wp_unslash($_GET['acteur_type'])) : '';
        if (!in_array($acteur_type, array_keys(psc_audit_acteur_labels()), true)) $acteur_type = '';

        $categorie = isset($_GET['categorie']) ? sanitize_key(wp_unslash($_GET['categorie'])) : '';
        if (!in_array($categorie, array_keys(psc_audit_categorie_labels()), true)) $categorie = '';

        $resultat = isset($_GET['resultat']) ? sanitize_key(wp_unslash($_GET['resultat'])) : '';
        if (!in_array($resultat, array_keys(psc_audit_resultat_labels()), true)) $resultat = '';

        $niveau = isset($_GET['niveau']) ? sanitize_key(wp_unslash($_GET['niveau'])) : '';
        if (!in_array($niveau, array_keys(psc_audit_niveau_labels()), true)) $niveau = '';

        return array(
            'du'          => $du,
            'au'          => $au,
            'acteur_type' => $acteur_type,
            'acteur_id'   => psc_get_int('acteur_id') ?: null,
            'famille_id'  => psc_get_int('famille_id') ?: null,
            'enfant_id'   => psc_get_int('enfant_id') ?: null,
            'categorie'   => $categorie,
            'action'      => isset($_GET['action_code']) ? sanitize_text_field(wp_unslash($_GET['action_code'])) : '',
            'resultat'    => $resultat,
            'niveau'      => $niveau,
            'recherche'   => isset($_GET['recherche']) ? sanitize_text_field(wp_unslash($_GET['recherche'])) : '',
            'page'        => max(1, psc_get_int('paged') ?: 1),
        );
    }

    public static function page_list() {
        self::guard_audit();
        global $wpdb;

        $filters = self::filters_from_request();
        $items = Psc_Audit::query(array_merge($filters, array('per_page' => self::PER_PAGE)));
        $total = Psc_Audit::count($filters);

        $families = $wpdb->get_results('SELECT id, nom, prenom, email FROM ' . psc_table('parents') . ' ORDER BY nom, prenom, email');

        $failures = (int) get_option('psc_audit_failures', 0);
        $unknown_actions = get_option('psc_audit_unknown_actions', array());
        if (!is_array($unknown_actions)) $unknown_actions = array();

        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        $chain_break_id = isset($_GET['rupture_id']) ? absint($_GET['rupture_id']) : 0;

        // Consulter l'écran est aussi une action journalisée — une seule
        // fois par requête, sans les résultats affichés (les filtres
        // suffisent à documenter ce qui a été consulté).
        Psc_Audit::log('audit.consultation', array(
            'meta'   => array('filtres' => array_diff_key($filters, array('page' => true))),
            'resume' => __('Consultation du journal d’audit.', 'periscolaire-registration'),
        ));

        include PSC_PATH . 'templates/admin-audit.php';
    }

    /**
     * Lien vers la fiche d'un objet quand elle existe encore — l'objet
     * d'une vieille ligne peut avoir disparu depuis (famille supprimée,
     * diffusion effacée) : dans ce cas, pas de lien, juste l'identifiant.
     */
    public static function objet_url($objet_type, $objet_id) {
        if (!$objet_id) return null;
        switch ($objet_type) {
            case 'famille':
                return add_query_arg(array('page' => 'psc_parents', 'edit' => (int) $objet_id), admin_url('admin.php'));
            case 'message':
                return add_query_arg(array('page' => 'psc_message_stats', 'id' => (int) $objet_id), admin_url('admin.php'));
            case 'conversation':
                return add_query_arg(array('page' => 'psc_conversation', 'id' => (int) $objet_id), admin_url('admin.php'));
            default:
                return null;
        }
    }

    public static function handle_verify() {
        self::guard_audit('psc_audit_verify');

        $limit = 1000;
        $rupture_id = Psc_Audit::verify_chain($limit);

        Psc_Audit::log('audit.verification', array(
            'resultat' => $rupture_id ? 'erreur' : 'succes',
            'meta'     => array('limite' => $limit, 'rupture_id' => $rupture_id),
            'resume'   => $rupture_id
                ? sprintf(__('Rupture de chaîne détectée à la ligne #%d.', 'periscolaire-registration'), $rupture_id)
                : sprintf(__('Chaîne d’intégrité vérifiée sur les %d dernières lignes : aucune rupture.', 'periscolaire-registration'), $limit),
        ));

        $args = array('page' => 'psc_audit', 'psc_msg' => $rupture_id ? 'chain_broken' : 'chain_ok');
        if ($rupture_id) $args['rupture_id'] = $rupture_id;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public static function handle_reset_failures() {
        self::guard_audit('psc_audit_reset_failures');
        $previous_failures = (int) get_option('psc_audit_failures', 0);
        update_option('psc_audit_failures', 0);
        delete_option('psc_audit_unknown_actions');

        Psc_Audit::log('audit.sante_reinitialisee', array(
            'meta'   => array('compteur_precedent' => $previous_failures),
            'resume' => __('Compteur de défaillances du journal remis à zéro.', 'periscolaire-registration'),
        ));

        wp_safe_redirect(add_query_arg(array('page' => 'psc_audit', 'psc_msg' => 'health_reset'), admin_url('admin.php')));
        exit;
    }

    /**
     * Durées de rétention : réglables, plafonnées à [30, 3650] jours par
     * psc_audit_retention_days() lui-même — les bornes ci-dessous ne sont
     * qu'un premier filtrage de la saisie, pas la seule protection.
     */
    public static function handle_save_retention() {
        self::guard_audit('psc_audit_save_retention');

        $before = array();
        foreach (array('critique', 'normal', 'volumineux') as $niveau) {
            $before[$niveau] = psc_audit_retention_days($niveau);
            $days = isset($_POST['retention_' . $niveau]) ? absint(wp_unslash($_POST['retention_' . $niveau])) : $before[$niveau];
            update_option('psc_audit_retention_' . $niveau, max(30, min(3650, $days)));
        }

        Psc_Audit::log('reglage.modification', array(
            'objet_type' => 'reglage',
            'meta'       => array(
                'champ' => 'retention_journal_audit',
                'avant' => $before,
                'apres' => array(
                    'critique'   => psc_audit_retention_days('critique'),
                    'normal'     => psc_audit_retention_days('normal'),
                    'volumineux' => psc_audit_retention_days('volumineux'),
                ),
            ),
            'resume' => __('Durées de rétention du journal d’audit modifiées.', 'periscolaire-registration'),
        ));

        wp_safe_redirect(add_query_arg(array('page' => 'psc_audit', 'psc_msg' => 'retention_saved'), admin_url('admin.php')));
        exit;
    }

    /** Colonnes communes aux deux formats d'export, dans cet ordre. */
    private static function export_headers() {
        return array(
            __('Horodatage (UTC)', 'periscolaire-registration'),
            __('Horodatage (local)', 'periscolaire-registration'),
            __('Requête', 'periscolaire-registration'),
            __('Type d’acteur', 'periscolaire-registration'),
            __('Acteur', 'periscolaire-registration'),
            __('Pour le compte de (famille)', 'periscolaire-registration'),
            __('Code action', 'periscolaire-registration'),
            __('Action', 'periscolaire-registration'),
            __('Catégorie', 'periscolaire-registration'),
            __('Résultat', 'periscolaire-registration'),
            __('Type d’objet', 'periscolaire-registration'),
            __('Objet', 'periscolaire-registration'),
            __('Famille', 'periscolaire-registration'),
            __('Enfant', 'periscolaire-registration'),
            __('Résumé', 'periscolaire-registration'),
            __('Détails (JSON)', 'periscolaire-registration'),
            __('IP', 'periscolaire-registration'),
            __('Canal', 'periscolaire-registration'),
        );
    }

    /**
     * Une ligne exportée, dans l'ordre d'export_headers(). "Acteur" exporte
     * le libellé figé (acteur_libelle) plutôt qu'un identifiant brut : il
     * reste lisible même si le compte a disparu depuis. "Pour le compte de"
     * reste en revanche l'identifiant brut de famille, cohérent avec les
     * colonnes famille_id/enfant_id et sans jointure supplémentaire pendant
     * le flux.
     */
    private static function export_row($row) {
        return array(
            $row->horodatage,
            get_date_from_gmt($row->horodatage, 'Y-m-d H:i:s'),
            $row->requete_id,
            $row->acteur_type,
            $row->acteur_libelle,
            $row->pour_le_compte_de,
            $row->action,
            psc_audit_action_label($row->action),
            $row->categorie,
            $row->resultat,
            $row->objet_type,
            $row->objet_id,
            $row->famille_id,
            $row->enfant_id,
            $row->resume,
            $row->details,
            $row->ip,
            $row->canal,
        );
    }

    /**
     * Un export est journalisé avant l'envoi du flux (et non après) : s'il
     * échoue en cours de route (mémoire, client qui coupe), la trace de sa
     * tentative reste — cohérent avec le reste du journal, qui privilégie
     * la trace de l'intention à la confirmation de son achèvement complet.
     */
    private static function log_export($format, array $filters, $count) {
        Psc_Audit::log('audit.export', array(
            'meta'   => array(
                'format'  => $format,
                'lignes'  => $count,
                'du'      => $filters['du'],
                'au'      => $filters['au'],
                'filtres' => array_diff_key($filters, array('page' => true, 'du' => true, 'au' => true)),
            ),
            'resume' => sprintf(
                /* translators: 1: format (csv/ods), 2: number of rows */
                __('Export %1$s du journal d’audit (%2$d lignes).', 'periscolaire-registration'),
                strtoupper($format),
                $count
            ),
        ));
    }

    private static function guard_export_rate_limit() {
        if (!psc_rate_limit('audit_export_' . get_current_user_id(), 5, HOUR_IN_SECONDS)) {
            wp_die(
                esc_html__('Trop d’exports du journal d’audit ont été demandés récemment. Réessayez dans quelques minutes.', 'periscolaire-registration'),
                '',
                array('response' => 429)
            );
        }
    }

    public static function handle_export_csv() {
        self::guard_audit('psc_audit_export_csv');
        self::guard_export_rate_limit();

        $filters = self::filters_from_request();
        $count = Psc_Audit::count($filters);
        self::log_export('csv', $filters, $count);

        $filename = 'journal-audit-' . $filters['du'] . '_' . $filters['au'] . '-' . gmdate('Ymd-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
        fputcsv($out, self::export_headers(), ';');

        $flush_counter = 0;
        Psc_Audit::stream($filters, function ($row) use ($out, &$flush_counter) {
            fputcsv($out, array_map('psc_csv_escape', self::export_row($row)), ';');
            $flush_counter++;
            if ($flush_counter % 500 === 0) {
                flush();
            }
        });

        fclose($out);
        exit;
    }

    public static function handle_export_ods() {
        self::guard_audit('psc_audit_export_ods');
        self::guard_export_rate_limit();

        $filters = self::filters_from_request();
        $count = Psc_Audit::count($filters);

        if ($count > self::ODS_MAX_ROWS) {
            wp_die(
                esc_html(sprintf(
                    /* translators: %d: maximum number of rows for the ODS export */
                    __('Trop de lignes (%1$d) pour un export ODS, limité à %2$d pour préserver la mémoire du serveur. Réduisez la période filtrée ou utilisez l’export CSV, qui n’a pas cette limite.', 'periscolaire-registration'),
                    $count,
                    self::ODS_MAX_ROWS
                )),
                '',
                array('response' => 400, 'back_link' => true)
            );
        }

        if (!class_exists('ZipArchive')) {
            wp_die(esc_html__('L’extension PHP ZipArchive est requise pour générer l’export ODS.', 'periscolaire-registration'));
        }

        // Contrairement au CSV en flux, l'ODS est nécessairement construit
        // en mémoire avant d'être zippé (cf. ODS_MAX_ROWS) : c'est le prix
        // accepté d'un format tableur plutôt qu'un flux de texte brut.
        $data = array();
        Psc_Audit::stream($filters, function ($row) use (&$data) {
            $data[] = self::export_row($row);
        });

        self::log_export('ods', $filters, $count);

        $tmp = tempnam(get_temp_dir(), 'psc-audit-');
        if (!$tmp) {
            wp_die(esc_html__('Création du fichier d’export impossible.', 'periscolaire-registration'));
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            wp_die(esc_html__('Création du fichier d’export impossible.', 'periscolaire-registration'));
        }
        $zip->addFromString('mimetype', psc_ods_mimetype());
        if (method_exists($zip, 'setCompressionIndex')) {
            $zip->setCompressionIndex(0, ZipArchive::CM_STORE); // mimetype non compressé (convention ODF)
        }
        $zip->addFromString('META-INF/manifest.xml', psc_ods_manifest_xml());
        $zip->addFromString('content.xml', psc_ods_content_xml(
            __('Journal d’audit', 'periscolaire-registration'),
            self::export_headers(),
            $data
        ));
        $zip->close();

        $filename = 'journal-audit-' . $filters['du'] . '_' . $filters['au'] . '-' . gmdate('Ymd-His') . '.ods';

        nocache_headers();
        header('Content-Type: application/vnd.oasis.opendocument.spreadsheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) filesize($tmp));
        readfile($tmp);
        @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        exit;
    }
}
