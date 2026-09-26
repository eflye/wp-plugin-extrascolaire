<?php
/**
 * Script de vérification autonome (P2-14) — versions des règlements
 * approuvés (schéma 4.18.0), en conditions WP-CLI réelles :
 *  - la version en vigueur est stable tant que rien ne change, et conserve
 *    exactement le texte affiché ;
 *  - un PDF complémentaire mis en ligne crée une nouvelle version, avec une
 *    copie privée identique octet pour octet ; le même PDF reposé ne crée
 *    rien ; le retrait du PDF revient à la version « texte seul » ;
 *  - une version citée par une acceptation ne peut pas être supprimée ;
 *  - l'inscription d'un enfant à une année enregistre la version.
 *
 * Médias, options et versions créés sont retirés à la fin.
 *
 * Usage :
 *   wp --require=bin/verify-document-versions.php verify-document-versions
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-document-versions', function () {

    if (!class_exists('Psc_Document_Versions') || !Psc_Document_Versions::current_id('reglement_interieur')) {
        WP_CLI::error('Versions de règlements indisponibles : la mise à jour 4.18.0 n’a pas tourné.');
    }

    global $wpdb;
    $t = psc_table('document_versions');
    $option = 'psc_doc_reglement_interieur_id';
    $saved_option = get_option($option, 0);
    $max_before = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM $t");
    $email = 'verify-document-versions@example.invalid';
    $attachments = array();
    $parent_id = 0;

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };
    $current = function () {
        Psc_Document_Versions::flush_cache();
        return Psc_Document_Versions::current_id('reglement_interieur');
    };
    $attach = function ($content, $name) use (&$attachments) {
        $upload = wp_upload_bits($name, null, $content);
        $id = wp_insert_attachment(array('post_mime_type' => 'application/pdf', 'post_title' => $name, 'post_status' => 'inherit'), $upload['file']);
        $attachments[] = $id;
        return $id;
    };
    $pdf = "%PDF-1.4\n% verify-document-versions\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    try {
        update_option($option, 0);
        $texte_seul = $current();
        $check($texte_seul && $current() === $texte_seul, 'version stable : un nouveau calcul crée une autre version');
        $v = Psc_Document_Versions::get($texte_seul);
        $check($v && $v->texte === psc_reglement_interieur_html(), 'texte : la version ne conserve pas le texte affiché');
        $check($v && $v->empreinte === psc_document_version_hash(psc_reglement_interieur_html(), null), 'empreinte : calcul différent de psc_document_version_hash()');

        // PDF mis en ligne : nouvelle version, copie privée identique.
        update_option($option, $attach($pdf, 'reglement-verify.pdf'));
        $avec_pdf = $current();
        $check($avec_pdf && $avec_pdf !== $texte_seul, 'PDF : pas de nouvelle version');
        $vp = Psc_Document_Versions::get($avec_pdf);
        $copy = $vp && $vp->pdf_fichier ? psc_private_path($vp->pdf_fichier) : '';
        $check($copy && file_exists($copy) && file_get_contents($copy) === $pdf, 'PDF : copie privée absente ou différente');
        $check($vp && $vp->pdf_sha256 === hash('sha256', $pdf), 'PDF : empreinte du fichier incorrecte');

        // Même contenu, autre média : même version. Retrait : texte seul.
        update_option($option, $attach($pdf, 'reglement-verify-bis.pdf'));
        $check($current() === $avec_pdf, 'PDF identique reposé : nouvelle version créée');
        update_option($option, 0);
        $check($current() === $texte_seul, 'PDF retiré : pas de retour à la version texte seul');

        // Acceptation : l'inscription à une année enregistre la version ;
        // la version citée ne peut pas être supprimée.
        $parent_id = Psc_Parents::create($email, 'Versions', array('reglement_accepted_at' => current_time('mysql'), 'reglement_version_id' => $avec_pdf));
        $check((int) $wpdb->get_var($wpdb->prepare('SELECT reglement_version_id FROM ' . psc_table('parents') . ' WHERE id = %d', $parent_id)) === $avec_pdf, 'famille : version non enregistrée');
        $wpdb->insert(psc_table('children'), array('parent_id' => $parent_id, 'nom' => 'Version', 'prenom' => 'Ana', 'created_at' => current_time('mysql')));
        $cid = (int) $wpdb->insert_id;
        $year_id = Psc_School_Years::ensure('2093-09-02', '2094-07-04'); // année réservée : l'année active peut manquer
        $check(Psc_School_Years::enroll($cid, $year_id, 'CE1', 'inscrit', current_time('mysql'), $avec_pdf), 'année de l’enfant : inscription impossible');
        $check((int) $wpdb->get_var($wpdb->prepare('SELECT reglement_version_id FROM ' . psc_table('child_school_years') . ' WHERE child_id = %d', $cid)) === $avec_pdf, 'année de l’enfant : version non enregistrée');
        $check(Psc_Document_Versions::label($avec_pdf) !== Psc_Document_Versions::label(null), 'libellé : version inconnue');

        $fk = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'reglement_version_id' AND REFERENCED_TABLE_NAME = %s",
            psc_table('parents'), $t
        ));
        if ($fk) {
            $suppress = $wpdb->suppress_errors(true);
            $deleted = $wpdb->delete($t, array('id' => $avec_pdf));
            $wpdb->suppress_errors($suppress);
            $check($deleted === false && Psc_Document_Versions::get($avec_pdf), 'suppression : version citée supprimée');
        } else {
            WP_CLI::warning('Clé étrangère absente sur cet hébergement : contrôle de suppression ignoré.');
        }
    } finally {
        if ($parent_id) {
            foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . psc_table('children') . ' WHERE parent_id = %d', $parent_id)) as $c) {
                $wpdb->delete(psc_table('child_school_years'), array('child_id' => (int) $c));
            }
            $wpdb->delete(psc_table('parents'), array('id' => $parent_id));
        }
        $year = Psc_School_Years::get_by_key('2093-2094');
        if ($year && $year->statut !== 'active') Psc_School_Years::delete((int) $year->id);
        foreach ($attachments as $id) wp_delete_attachment($id, true);
        update_option($option, $saved_option);
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, pdf_fichier FROM $t WHERE id > %d", $max_before)) as $row) {
            if ($row->pdf_fichier) @unlink(psc_private_path($row->pdf_fichier)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $wpdb->delete($t, array('id' => (int) $row->id));
        }
        Psc_Document_Versions::flush_cache();
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Versions des règlements : $checks vérifications.");
});
