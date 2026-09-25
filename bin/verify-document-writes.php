<?php
/**
 * Script de vérification autonome (P1-13) — aucun justificatif perdu en
 * silence. Même rôle que bin/verify-write-integrity.php : ce script joue
 * celui du test unitaire, en conditions WP-CLI réelles.
 *
 * Les pannes sont provoquées sans droits particuliers (le conteneur tourne
 * en root, que chmod n'arrête pas) :
 *  - disque plein / accès refusé : filtre psc_pre_move_uploaded_file qui
 *    répond « échec » ;
 *  - échec SQL : requête précise sabotée par le filtre « query ».
 *
 * Vérifie :
 *  - dépôt : une panne disque ou SQL garde l'ancien justificatif (fichier
 *    et base), sans fichier temporaire ; un nouveau format remplace l'ancien ;
 *  - ajout d'un enfant : tout ou rien (ni enfant sans justificatif, ni
 *    fichier orphelin) ;
 *  - réinscription : un deuxième enfant invalide n'écrit rien ; une panne
 *    au deuxième enfant se rattrape en renvoyant le formulaire, sans doublon ;
 *  - rattachement après validation : l'échec est consigné, repris par la
 *    tâche quotidienne, survit à la purge de la demande, et n'écrase jamais
 *    un justificatif déposé depuis par la famille.
 *
 * Usage :
 *   wp --require=bin/verify-document-writes.php verify-document-writes
 *
 * Crée deux années de test (2093-2094, 2094-2095), active la première le
 * temps du test puis réactive l'année d'origine ; données scopées par une
 * adresse e-mail dédiée, purgées avant et après.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-document-writes', function () {

    if (!class_exists('Psc_Assurances') || !class_exists('Psc_Frontend_Enfants') || !class_exists('Psc_Frontend_Reinscription')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $email    = 'verify-document-writes@example.invalid';
    $labels   = array('2093-2094', '2094-2095'); // clés des années de test
    $t_years  = psc_table('school_years');
    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_cy     = psc_table('child_school_years');
    $t_req    = psc_table('requests');
    $original = Psc_School_Years::active_id();
    $base     = psc_private_path(Psc_Assurances::BASE);
    $req_ids  = array();

    $purge = function () use ($wpdb, $email, $labels, $t_years, $t_parent, $t_child, $t_cy, $t_req, $original, $base, &$req_ids) {
        if ($original) Psc_School_Years::activate($original);
        $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_parent WHERE email = %s", $email));
        if ($pid) {
            foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_child WHERE parent_id = %d", $pid)) as $cid) {
                foreach ((array) glob($base . '/*/child-' . (int) $cid . '.*') as $f) {
                    is_dir($f) ? @rmdir($f) : @unlink($f); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }
                $wpdb->delete($t_cy, array('child_id' => (int) $cid));
            }
            $wpdb->delete($t_child, array('parent_id' => $pid));
            $wpdb->delete($t_parent, array('id' => $pid));
        }
        foreach ($labels as $label) {
            foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_years WHERE year_key = %s", $label)) as $id) {
                if ((int) $id === (int) Psc_School_Years::active_id()) {
                    $wpdb->update($t_years, array('statut' => 'archivee'), array('id' => (int) $id));
                }
                $wpdb->delete($t_cy, array('school_year_id' => (int) $id));
                $wpdb->delete($t_years, array('id' => (int) $id));
            }
        }
        // Répertoires des années de test (rentrées 2093 et 2094) : entièrement
        // à ce script, fichiers cachés compris (.upload-*, *.previous).
        foreach (array('2093', '2094') as $rentree) {
            foreach ((array) glob($base . '/' . $rentree . '/{,.}[!.]*', GLOB_BRACE) as $f) @unlink($f); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            @rmdir($base . '/' . $rentree); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        $wpdb->query($wpdb->prepare("DELETE FROM $t_req WHERE email = %s", $email));
        foreach ($req_ids as $rid) Psc_Assurances::delete_pending_files($rid);
        Psc_School_Year::flush_cache();
    };
    $purge();

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    // Fichiers reçus : copiés au lieu de move_uploaded_file(), qui refuse
    // tout fichier ne provenant pas d'un envoi HTTP.
    $tmp = trailingslashit(get_temp_dir()) . 'psc-verify-writes-' . wp_generate_password(8, false);
    wp_mkdir_p($tmp);
    require_once PSC_PATH . 'includes/fpdf/fpdf.php';
    $pdf_of = function ($text) {
        $fpdf = new FPDF();
        $fpdf->SetCompression(false); // marqueur lisible dans les octets
        $fpdf->AddPage();
        $fpdf->SetFont('Arial', '', 14);
        $fpdf->Cell(0, 10, $text);
        return $fpdf->Output('S');
    };
    $upload = function ($text, $name = 'attestation.pdf') use ($tmp, $pdf_of) {
        $path = $tmp . '/' . wp_generate_password(8, false) . '.pdf';
        file_put_contents($path, $pdf_of($text)); // phpcs:ignore WordPress.WP.AlternativeFunctions
        return array('name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path));
    };
    $invalid = function () use ($tmp) {
        $path = $tmp . '/faux.pdf';
        file_put_contents($path, "Ceci n'est pas un PDF.\n%%EOF"); // phpcs:ignore WordPress.WP.AlternativeFunctions
        return array('name' => 'attestation.pdf', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path));
    };
    $copy_mover = function ($moved, $src, $dst) { return copy($src, $dst); };
    $disk_full = function () { return false; };
    add_filter('psc_pre_move_uploaded_file', $copy_mover, 10, 3);

    $sabotage_table = null;
    $sabotage = function ($sql) use (&$sabotage_table) {
        return ($sabotage_table && preg_match('/^(INSERT INTO|UPDATE) `' . preg_quote($sabotage_table, '/') . '`/', $sql))
            ? 'SELECT * FROM psc_table_inexistante' : $sql;
    };
    add_filter('query', $sabotage);
    $wpdb->suppress_errors(true);
    $wpdb->show_errors(false);

    $row = function ($cid, $year_id) use ($wpdb, $t_cy) {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_cy WHERE child_id = %d AND school_year_id = %d", $cid, $year_id));
    };
    $file_text = function ($rel) {
        $path = $rel ? psc_private_path($rel) : '';
        return ($path && is_file($path)) ? (string) file_get_contents($path) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
    };
    $leftovers = function ($dir) {
        return count(array_merge((array) glob($dir . '/.upload-*'), (array) glob($dir . '/*.previous')));
    };

    try {
        $y1 = Psc_School_Years::create('2093-09-01', '2094-07-04');
        $y2 = Psc_School_Years::create('2094-09-01', '2095-07-04');
        if (is_wp_error($y1) || is_wp_error($y2) || !$y1 || !$y2) WP_CLI::error('Années scolaires de test impossibles à créer.');
        Psc_School_Years::activate($y1);

        // Une classe qui a une suite : la réinscription en a besoin.
        $classe = '';
        foreach (Psc_School_Years::classe_progression() as $from => $to) {
            if ($to && $to !== 'sortie') { $classe = $from; break; }
        }
        if ($classe === '') WP_CLI::error('Aucune progression de classe configurée.');

        $pid = Psc_Parents::create($email, 'Vérification', array('prenom' => 'Justificatifs'));
        if (is_wp_error($pid) || !$pid) WP_CLI::error('Foyer de test impossible à créer.');
        $new_child = function ($prenom) use ($wpdb, $t_child, $pid, $y1, $classe) {
            $wpdb->insert($t_child, array('parent_id' => $pid, 'nom' => 'Verif', 'prenom' => $prenom, 'created_at' => current_time('mysql')));
            $cid = (int) $wpdb->insert_id;
            Psc_School_Years::enroll($cid, $y1, $classe, 'inscrit', current_time('mysql'));
            return $cid;
        };
        $dir_2093 = $base . '/2093';

        // 1. Dépôt : pannes disque et SQL, l'ancien justificatif reste.
        $c1 = $new_child('Alix');
        $check(Psc_Assurances::store_upload($c1, $upload('ANCIEN-C1'), $y1) === true, 'dépôt : premier dépôt refusé');
        $before = $row($c1, $y1);

        add_filter('psc_pre_move_uploaded_file', $disk_full, 20);
        $check(Psc_Assurances::store_upload($c1, $upload('NOUVEAU-C1'), $y1) === 'failed', 'disque plein : dépôt annoncé réussi');
        remove_filter('psc_pre_move_uploaded_file', $disk_full, 20);
        $check(strpos($file_text($before->assurance_file_path), 'ANCIEN-C1') !== false, 'disque plein : ancien justificatif perdu');
        $check($row($c1, $y1)->assurance_revision === $before->assurance_revision, 'disque plein : base modifiée');

        $sabotage_table = $t_cy;
        $check(Psc_Assurances::store_upload($c1, $upload('NOUVEAU-C1'), $y1) === 'failed', 'échec SQL : dépôt annoncé réussi');
        $sabotage_table = null;
        $check(strpos($file_text($before->assurance_file_path), 'ANCIEN-C1') !== false, 'échec SQL : ancien justificatif perdu');
        $check($row($c1, $y1)->assurance_revision === $before->assurance_revision, 'échec SQL : base modifiée');
        $check($leftovers($dir_2093) === 0, 'pannes : fichier temporaire ou de sauvegarde laissé sur le disque');

        // Nouveau format : l'ancien fichier d'un autre format disparaît.
        $jpg = $dir_2093 . '/child-' . $c1 . '.jpg';
        file_put_contents($jpg, 'ancien format'); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $check(Psc_Assurances::store_upload($c1, $upload('NOUVEAU-C1'), $y1) === true, 'remplacement : dépôt refusé');
        $check(strpos($file_text($row($c1, $y1)->assurance_file_path), 'NOUVEAU-C1') !== false, 'remplacement : nouveau justificatif absent');
        $check(!file_exists($jpg), 'remplacement : ancien format laissé sur le disque');

        // 2. Ajout d'un enfant : tout ou rien.
        $fields = function ($prenom) { return array('nom' => 'Verif', 'prenom' => $prenom, 'date_naissance' => null, 'sans_porc' => 0, 'vegan' => 0, 'food_allergy_signal' => 0); };
        $count_named = function ($prenom) use ($wpdb, $t_child, $pid) {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_child WHERE parent_id = %d AND prenom = %s", $pid, $prenom));
        };
        $sabotage_table = $t_cy;
        $got = Psc_Frontend_Enfants::create_child_with_document($pid, $fields('Sql'), $classe, $y1, $upload('AJOUT'));
        $sabotage_table = null;
        $check($got === 0, 'ajout, échec SQL : ajout annoncé réussi');
        $check($count_named('Sql') === 0, 'ajout, échec SQL : enfant créé sans inscription ni justificatif');

        add_filter('psc_pre_move_uploaded_file', $disk_full, 20);
        $got = Psc_Frontend_Enfants::create_child_with_document($pid, $fields('Disque'), $classe, $y1, $upload('AJOUT'));
        remove_filter('psc_pre_move_uploaded_file', $disk_full, 20);
        $check($got === 0, 'ajout, disque plein : ajout annoncé réussi');
        $check($count_named('Disque') === 0, 'ajout, disque plein : enfant créé sans justificatif');

        $c2 = Psc_Frontend_Enfants::create_child_with_document($pid, $fields('Nominal'), $classe, $y1, $upload('AJOUT-C2'));
        $check($c2 > 0 && $count_named('Nominal') === 1, 'ajout nominal : enfant absent');
        $check($c2 && strpos($file_text($row($c2, $y1)->assurance_file_path ?? ''), 'AJOUT-C2') !== false, 'ajout nominal : justificatif absent');

        // 3. Réinscription de deux enfants vers l'année suivante.
        $children = array((object) array('id' => $c1), (object) array('id' => $c2));
        $count_target = function () use ($wpdb, $t_cy, $y2, $c1, $c2) {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_cy WHERE school_year_id = %d AND child_id IN (%d, %d)", $y2, $c1, $c2));
        };
        $result = Psc_Frontend_Reinscription::apply_reinscription($children, array($c1 => $upload('REINS-C1'), $c2 => $invalid()), $y2, current_time('mysql'));
        $check($result === 'required', 'réinscription, 2e enfant invalide : résultat ' . $result);
        $check($count_target() === 0, 'réinscription, 2e enfant invalide : 1er enfant réinscrit à moitié');

        $calls = 0;
        $second_fails = function ($moved) use (&$calls) { return ++$calls === 2 ? false : $moved; };
        add_filter('psc_pre_move_uploaded_file', $second_fails, 20);
        $result = Psc_Frontend_Reinscription::apply_reinscription($children, array($c1 => $upload('REINS-C1'), $c2 => $upload('REINS-C2')), $y2, current_time('mysql'));
        remove_filter('psc_pre_move_uploaded_file', $second_fails, 20);
        $check($result === 'failed', 'réinscription, panne au 2e enfant : résultat ' . $result);

        $result = Psc_Frontend_Reinscription::apply_reinscription($children, array($c1 => $upload('REINS-C1'), $c2 => $upload('REINS-C2')), $y2, current_time('mysql'));
        $check($result === 'ok', 'réinscription renvoyée : résultat ' . $result);
        $check($count_target() === 2, 'réinscription renvoyée : ' . $count_target() . ' inscription(s) au lieu de 2');
        $check(strpos($file_text($row($c2, $y2)->assurance_file_path ?? ''), 'REINS-C2') !== false, 'réinscription renvoyée : justificatif du 2e enfant absent');

        // 4. Rattachement après validation, reprise et purge.
        $wpdb->insert($t_req, array('email' => $email, 'nom' => 'Verif', 'status' => 'approved', 'created_at' => '2000-01-01 00:00:00', 'decided_at' => '2000-01-01 00:00:00'));
        $rid = (int) $wpdb->insert_id;
        $req_ids[] = $rid;
        $pending_rel = Psc_Assurances::pending_rel_path($rid, 0, 'pdf');
        wp_mkdir_p(Psc_Assurances::pending_dir($rid));
        file_put_contents(psc_private_path($pending_rel), $pdf_of('ATTENTE-C1')); // phpcs:ignore WordPress.WP.AlternativeFunctions

        // Rattachement en échec (écriture SQL refusée) : échec franc.
        $sabotage_table = $t_cy;
        $check(!Psc_Assurances::promote_pending($c1, psc_private_path($pending_rel), 'attente.pdf'), 'rattachement bloqué : annoncé réussi');
        $check(file_exists(psc_private_path($pending_rel)), 'rattachement bloqué : fichier en attente perdu');
        Psc_Assurances::record_failed_promotions($rid, array(array($c1, $pending_rel, 'attente.pdf')));

        // Purge de la demande (traitée il y a plus de 90 jours) : la zone
        // d'attente et son manifeste survivent tant que la reprise échoue.
        Psc_Requests::cleanup();
        $check(!$wpdb->get_var($wpdb->prepare("SELECT id FROM $t_req WHERE id = %d", $rid)), 'purge : demande de test non purgée');
        $check(file_exists(psc_private_path($pending_rel)) && Psc_Assurances::has_failed_promotions($rid), 'purge : justificatif en attente supprimé avant rattachement');

        $sabotage_table = null;
        // Ancien justificatif d'un autre format : remplacé par le rattachement.
        $stale_jpg = psc_private_path(Psc_Assurances::rel_path($c1, psc_rentree_year(), 'jpg'));
        file_put_contents($stale_jpg, 'ancien format'); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $out = Psc_Assurances::retry_failed_promotions();
        $check($out['rattaches'] === 1 && $out['restants'] === 0, 'reprise : ' . wp_json_encode($out));
        $check(strpos($file_text($row($c1, $y1)->assurance_file_path), 'ATTENTE-C1') !== false, 'reprise : justificatif non rattaché');
        $check(!file_exists($stale_jpg), 'reprise : ancien format laissé sur le disque');
        $check(!is_dir(Psc_Assurances::pending_dir($rid)), 'reprise : zone d’attente conservée après succès');
        $check(Psc_Assurances::retry_failed_promotions()['rattaches'] === 0, 'reprise rejouée : rattachement en double');

        // Document déposé par la famille après l'échec : jamais écrasé.
        $rid2 = $rid + 1000000;
        $req_ids[] = $rid2;
        $pending2 = Psc_Assurances::pending_rel_path($rid2, 0, 'pdf');
        wp_mkdir_p(Psc_Assurances::pending_dir($rid2));
        file_put_contents(psc_private_path($pending2), $pdf_of('ATTENTE-PERIMEE')); // phpcs:ignore WordPress.WP.AlternativeFunctions
        // Échec consigné AVANT le dépôt « AJOUT-C2 » de la famille.
        file_put_contents(Psc_Assurances::pending_dir($rid2) . '/promotions.json', wp_json_encode(array( // phpcs:ignore WordPress.WP.AlternativeFunctions
            array('child_id' => $c2, 'rel_path' => $pending2, 'filename' => 'attente.pdf', 'failed_at' => '2000-01-01 00:00:00'),
        )));
        $out = Psc_Assurances::retry_failed_promotions();
        $check($out['abandonnes'] === 1 && $out['rattaches'] === 0, 'document plus récent : ' . wp_json_encode($out));
        $check(strpos($file_text($row($c2, $y1)->assurance_file_path), 'AJOUT-C2') !== false, 'document plus récent : écrasé par l’ancien');
    } finally {
        remove_filter('query', $sabotage);
        remove_all_filters('psc_pre_move_uploaded_file');
        $purge();
        foreach ((array) glob($tmp . '/*') as $f) @unlink($f); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        @rmdir($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Justificatifs : $checks vérifications.");
});
