<?php
/**
 * Script de vérification autonome (P2-03) — les justificatifs et pièces
 * jointes sont jugés sur leur contenu, pas sur leur nom. Même rôle que
 * bin/verify-promotion-logic.php : ce script joue celui du test unitaire,
 * en conditions WP-CLI réelles, sur de vrais fichiers.
 *
 * Vérifie :
 *  - PDF, JPEG et PNG valides acceptés ;
 *  - faux PDF, image renommée, fichier vide, dépassement, erreurs
 *    d'upload, fichier tronqué, image indécodable, PDF porteur de
 *    JavaScript ou de fichiers embarqués : refusés ;
 *  - un dépôt refusé ne détruit pas l'ancien justificatif ;
 *  - un justificatif en attente invalide n'est pas rattaché, et reste en
 *    zone d'attente ;
 *  - les pièces jointes des conversations suivent la même règle.
 *
 * Usage :
 *   wp --require=bin/verify-document-validation.php verify-document-validation
 *
 * Fichiers créés dans un dossier temporaire dédié ; famille de test
 * scopée par une adresse dédiée, purgées avant et après.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-document-validation', function () {

    if (!function_exists('psc_validate_document_file') || !class_exists('Psc_Assurances')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $email = 'verify-documents@example.invalid';
    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_cy     = psc_table('child_school_years');

    $purge = function () use ($wpdb, $email, $t_parent, $t_child, $t_cy) {
        $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_parent WHERE email = %s", $email));
        if (!$pid) return;
        foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_child WHERE parent_id = %d", $pid)) as $cid) {
            foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT assurance_file_path FROM $t_cy WHERE child_id = %d", $cid)) as $rel) {
                if ($rel) @unlink(psc_private_path($rel)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
            $wpdb->delete($t_cy, array('child_id' => (int) $cid));
            $wpdb->delete($t_child, array('id' => (int) $cid));
        }
        $wpdb->delete($t_parent, array('id' => $pid));
    };
    $purge();

    $dir = trailingslashit(get_temp_dir()) . 'psc-verify-documents-' . wp_generate_password(8, false);
    wp_mkdir_p($dir);
    $put = function ($name, $content) use ($dir) {
        $path = $dir . '/' . $name;
        file_put_contents($path, $content); // phpcs:ignore WordPress.WP.AlternativeFunctions
        return $path;
    };

    // Documents réels.
    require_once PSC_PATH . 'includes/fpdf/fpdf.php';
    $fpdf = new FPDF();
    $fpdf->AddPage();
    $fpdf->SetFont('Arial', '', 14);
    $fpdf->Cell(0, 10, 'Attestation d assurance - verification');
    $pdf = $fpdf->Output('S');
    $img = imagecreatetruecolor(40, 30);
    ob_start(); imagejpeg($img); $jpeg = ob_get_clean();
    ob_start(); imagepng($img); $png = ob_get_clean();
    imagedestroy($img);

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };
    $upload = function ($name, $path, $error = UPLOAD_ERR_OK) {
        return array('name' => $name, 'tmp_name' => $path, 'error' => $error, 'size' => is_file($path) ? filesize($path) : 0);
    };

    try {
        // 1. Contenus valides.
        $cases_ok = array(
            'PDF'  => $upload('attestation.pdf', $put('ok.pdf', $pdf)),
            'JPEG' => $upload('attestation.JPG', $put('ok.jpg', $jpeg)),
            'PNG'  => $upload('attestation.png', $put('ok.png', $png)),
        );
        foreach ($cases_ok as $label => $file) {
            $check(Psc_Assurances::validate_upload($file) === true, "$label valide refusé (" . var_export(Psc_Assurances::validate_upload($file), true) . ')');
        }

        // 2. Contenus refusés : [fichier, code attendu].
        $big = $pdf . str_repeat(' ', MB_IN_BYTES) . "\n%%EOF\n";
        $js_pdf = str_replace('/Type /Catalog', '/Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert(1)) >>', $pdf);
        $embedded_pdf = str_replace('/Type /Catalog', '/Type /Catalog /Names << /EmbeddedFiles 9 0 R >> /EmbeddedFile', $pdf);
        $refused = array(
            'faux PDF (texte renommé)'       => array($upload('attestation.pdf', $put('faux.pdf', "Ceci n'est pas un PDF.\n%%EOF")), 'invalid_type'),
            'PNG renommé en .pdf'            => array($upload('attestation.pdf', $put('png.pdf', $png)), 'invalid_type'),
            'PDF renommé en .jpg'            => array($upload('attestation.jpg', $put('pdf.jpg', $pdf)), 'invalid_type'),
            'PDF tronqué'                    => array($upload('attestation.pdf', $put('tronque.pdf', substr($pdf, 0, (int) (strlen($pdf) / 2)))), 'invalid_type'),
            'PNG tronqué'                    => array($upload('attestation.png', $put('tronque.png', substr($png, 0, 40))), 'invalid_type'),
            'JPEG indécodable'               => array($upload('attestation.jpg', $put('casse.jpg', "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 200))), 'invalid_type'),
            'PDF avec JavaScript'            => array($upload('attestation.pdf', $put('js.pdf', $js_pdf)), 'invalid_type'),
            'PDF avec fichier embarqué'      => array($upload('attestation.pdf', $put('embarque.pdf', $embedded_pdf)), 'invalid_type'),
            'extension non acceptée'         => array($upload('attestation.docx', $put('doc.docx', $pdf)), 'invalid_type'),
            'fichier vide'                   => array($upload('attestation.pdf', $put('vide.pdf', '')), 'partial'),
            'dépassement réel'               => array($upload('attestation.pdf', $put('gros.pdf', $big)), 'too_large'),
            'taille déclarée mensongère'     => array(array('name' => 'attestation.pdf', 'tmp_name' => $put('menteur.pdf', $big), 'error' => UPLOAD_ERR_OK, 'size' => 1000), 'too_large'),
            'fichier temporaire absent'      => array(array('name' => 'attestation.pdf', 'tmp_name' => $dir . '/absent.pdf', 'error' => UPLOAD_ERR_OK, 'size' => 1000), 'failed'),
            'erreur d’upload partielle'      => array($upload('attestation.pdf', $put('p.pdf', $pdf), UPLOAD_ERR_PARTIAL), 'partial'),
            'erreur d’upload taille'         => array($upload('attestation.pdf', $put('i.pdf', $pdf), UPLOAD_ERR_INI_SIZE), 'too_large'),
            'erreur d’upload serveur'        => array($upload('attestation.pdf', $put('s.pdf', $pdf), UPLOAD_ERR_CANT_WRITE), 'failed'),
        );
        if ($js_pdf === $pdf || $embedded_pdf === $pdf) WP_CLI::error('Gabarit FPDF inattendu : /Type /Catalog introuvable.');
        foreach ($refused as $label => list($file, $expected)) {
            $actual = Psc_Assurances::validate_upload($file);
            $check($actual === $expected, "$label : " . var_export($actual, true) . " au lieu de '$expected'");
        }

        // 3. Pièces jointes des conversations : même règle.
        $check(Psc_Conversations::validate_attachment($cases_ok['PDF']) === true, 'pièce jointe PDF valide refusée');
        $check(Psc_Conversations::validate_attachment($refused['faux PDF (texte renommé)'][0]) === 'invalid_type', 'pièce jointe : faux PDF accepté');

        // 4. Un dépôt refusé ne détruit pas l'ancien justificatif.
        $year_id = Psc_School_Years::active_id();
        if (!$year_id) WP_CLI::error('Aucune année scolaire active.');
        $wpdb->insert($t_parent, array('email' => $email, 'nom' => 'VerifyDocuments', 'active' => 1, 'created_at' => current_time('mysql')));
        $pid = (int) $wpdb->insert_id;
        $wpdb->insert($t_child, array('parent_id' => $pid, 'nom' => 'VerifyDocuments', 'prenom' => 'Noa', 'statut' => 'actif', 'created_at' => current_time('mysql')));
        $cid = (int) $wpdb->insert_id;
        Psc_School_Years::enroll($cid, $year_id, 'CE1', 'inscrit', current_time('mysql'));
        $old_rel = Psc_Assurances::BASE . '/verify/child-' . $cid . '.pdf';
        wp_mkdir_p(dirname(psc_private_path($old_rel)));
        file_put_contents(psc_private_path($old_rel), $pdf); // phpcs:ignore WordPress.WP.AlternativeFunctions
        Psc_Assurances::upsert_row($cid, $old_rel, 'ancienne-attestation.pdf', $year_id);

        $result = Psc_Assurances::store_upload($cid, $refused['faux PDF (texte renommé)'][0], $year_id);
        $check($result === 'invalid_type', 'dépôt d’un faux PDF : ' . var_export($result, true));
        $row = $wpdb->get_row($wpdb->prepare("SELECT assurance_file_path, assurance_original_filename FROM $t_cy WHERE child_id = %d AND school_year_id = %d", $cid, $year_id));
        $check($row && $row->assurance_file_path === $old_rel, 'dépôt refusé : ancien justificatif remplacé en base');
        $check(file_exists(psc_private_path($old_rel)) && file_get_contents(psc_private_path($old_rel)) === $pdf, 'dépôt refusé : ancien fichier détruit');

        // 5. Justificatif en attente invalide : pas de rattachement, zone conservée.
        $pending = $put('child-0.pdf', "Pas un PDF\n%%EOF");
        $check(Psc_Assurances::promote_pending($cid, $pending, 'attente.pdf') === false, 'attente : faux PDF rattaché');
        $check(file_exists($pending), 'attente : fichier en attente supprimé malgré le refus');
        $row = $wpdb->get_row($wpdb->prepare("SELECT assurance_file_path FROM $t_cy WHERE child_id = %d AND school_year_id = %d", $cid, $year_id));
        $check($row && $row->assurance_file_path === $old_rel, 'attente : ancien justificatif remplacé');

        // 6. Analyse antivirus éventuelle de l'hébergement.
        $scan = function () { return false; };
        add_filter('psc_document_scan', $scan);
        $check(Psc_Assurances::validate_upload($cases_ok['PDF']) === 'invalid_type', 'analyse : refus de l’antivirus ignoré');
        remove_filter('psc_document_scan', $scan);
    } finally {
        foreach ((array) glob($dir . '/*') as $f) @unlink($f); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $purge();
    }

    if ($failures) {
        foreach ($failures as $failure) WP_CLI::warning($failure);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Justificatifs : $checks vérifications.");
});
