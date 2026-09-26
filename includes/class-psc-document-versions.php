<?php
if (!defined('ABSPATH')) exit;

/**
 * Versions des règlements approuvés par les familles (P2-14, schéma
 * 4.18.0) — table psc_document_versions.
 *
 * Une version réunit ce que la famille a eu sous les yeux au moment de
 * cocher la case : le texte exact affiché par le formulaire
 * (psc_reglement_html) et, s'il existe, le PDF complémentaire en ligne ce
 * jour-là (Périscolaire › Réglages › Documents), dont une copie est gardée
 * dans le répertoire privé. Son empreinte couvre les deux : une mise à jour
 * de l'extension qui change le texte, ou un nouveau PDF, crée d'elle-même
 * une nouvelle version ; tant que rien ne change, toutes les acceptations
 * pointent la même.
 *
 * Chaque acceptation (demande d'inscription, réinscription, activation du
 * prélèvement) enregistre l'identifiant de la version en vigueur ; la clé
 * étrangère (RESTRICT) empêche de supprimer une version citée.
 */
class Psc_Document_Versions {

    /** @var array<string, int> Version courante par type, cache par requête. */
    private static $current = array();

    public static function flush_cache() {
        self::$current = array();
    }

    /**
     * Identifiant de la version EN VIGUEUR d'un règlement — créée si le
     * texte ou le PDF a changé depuis la dernière. null si la table n'existe
     * pas encore (mise à jour 4.18.0 pas passée) ou en cas d'échec : une
     * acceptation n'est jamais refusée pour cette raison.
     */
    public static function current_id($type) {
        if (!isset(psc_reglement_types()[$type])) return null;
        if (isset(self::$current[$type])) return self::$current[$type];
        if (version_compare((string) get_option('psc_db_version', '0'), '4.18.0', '<')) return null;

        global $wpdb;
        $t = psc_table('document_versions');
        $texte = psc_reglement_html($type);
        $pdf = self::pdf_source($type);
        $pdf_sha = $pdf ? hash_file('sha256', $pdf) : null;
        $empreinte = psc_document_version_hash($texte, $pdf_sha);

        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE type = %s AND empreinte = %s", $type, $empreinte));
        if (!$id) {
            $rel = $pdf ? self::keep_pdf_copy($type, $pdf, $pdf_sha) : null;
            $ok = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $t (type, empreinte, texte, pdf_sha256, pdf_fichier, pdf_nom, cree_le) VALUES (%s, %s, %s, %s, %s, %s, %s)",
                $type, $empreinte, $texte, $pdf_sha, $rel, $pdf ? mb_substr(basename($pdf), 0, 190) : null, current_time('mysql')
            ));
            if ($ok === false) return null;
            // INSERT IGNORE : une requête concurrente a pu créer la même
            // version entre-temps — on relit.
            $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE type = %s AND empreinte = %s", $type, $empreinte));
            if ($id && class_exists('Psc_Audit')) {
                Psc_Audit::log('reglage.version_reglement', array(
                    'objet_type' => 'reglage',
                    'meta'       => array('type' => $type, 'version' => $id, 'pdf' => $pdf_sha !== null),
                    'resume'     => sprintf(__('Nouvelle version du %1$s (n° %2$d).', 'periscolaire-registration'), mb_strtolower(psc_reglement_types()[$type]['label']), $id),
                    'acteur'     => array('type' => 'systeme', 'id' => null, 'libelle' => 'versions-reglements', 'pour_le_compte_de' => null),
                ));
            }
        }
        if ($id) self::$current[$type] = $id;
        return $id ?: null;
    }

    public static function get($id) {
        global $wpdb;
        return $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('document_versions') . ' WHERE id = %d', (int) $id)) : null;
    }

    /** Toutes les versions d'un type, de la plus récente à la plus ancienne. */
    public static function all($type) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, type, empreinte, pdf_sha256, pdf_nom, cree_le FROM ' . psc_table('document_versions') . ' WHERE type = %s ORDER BY id DESC', $type
        ));
    }

    /** Libellé court d'une version acceptée, pour les écrans et l'export. */
    public static function label($id) {
        $v = self::get($id);
        if (!$v) return __('version antérieure au suivi des versions', 'periscolaire-registration');
        return sprintf(__('version n° %1$d du %2$s', 'periscolaire-registration'), (int) $v->id, date_i18n('d/m/Y', strtotime($v->cree_le)));
    }

    /** Lien de consultation d'une version (écran d'administration). */
    public static function admin_url($id) {
        return add_query_arg(array('page' => 'psc_reglement_version', 'id' => (int) $id), admin_url('admin.php'));
    }

    /** Fichier du PDF complémentaire en ligne pour ce type, s'il existe. */
    private static function pdf_source($type) {
        $attachment = (int) get_option(psc_reglement_types()[$type]['option'], 0);
        if (!$attachment) return null;
        $file = get_attached_file($attachment);
        return ($file && is_readable($file)) ? $file : null;
    }

    /** Copie privée du PDF : la médiathèque peut perdre ou remplacer le fichier. */
    private static function keep_pdf_copy($type, $source, $sha) {
        $rel = 'periscolaire/reglements/' . $type . '-' . substr($sha, 0, 16) . '.pdf';
        $dest = psc_private_path($rel);
        if (!$dest) return null;
        if (!file_exists($dest)) {
            if (!wp_mkdir_p(dirname($dest)) || !@copy($source, $dest)) return null; // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        return $rel;
    }
}
