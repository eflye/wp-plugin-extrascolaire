<?php
/**
 * Politique de rédaction du journal d'audit — fonctions pures de
 * includes/helpers/audit.php, sans base de données ni WordPress amorcé
 * (cf. tests/unit/run.php). $assert est défini par l'appelant.
 */

$audit_cases = json_decode(file_get_contents(__DIR__ . '/audit-redaction-cases.json'), true);

/* ---- Objet famille complet : aucune chaîne interdite ne fuit ---- */
$famille = $audit_cases['famille_complete'];
$diff = psc_audit_redact_diff('famille', $famille['avant'], $famille['apres']);
$details = psc_audit_build_details(
    isset($diff['avant']) ? $diff['avant'] : array(),
    isset($diff['apres']) ? $diff['apres'] : array(),
    null
);

$assert('audit famille : jeton -> "modifié", jamais sa valeur', $diff['apres']['token_hash'], 'modifié');
$assert('audit famille : BIC -> "modifié", jamais sa valeur', $diff['apres']['sepa_bic'], 'modifié');
$assert('audit famille : titulaire SEPA -> "modifié", jamais sa valeur', $diff['apres']['sepa_titulaire'], 'modifié');
$assert('audit famille : IBAN masqué (4 derniers caractères)', $diff['apres']['sepa_iban'], 'FR76 •••• •••• 0189');
$assert('audit famille : champ inchangé absent du détail', array_key_exists('email', isset($diff['apres']) ? $diff['apres'] : array()), false);

foreach (array(
    $famille['apres']['token_hash'],
    $famille['apres']['sepa_iban'],
    $famille['apres']['sepa_bic'],
    $famille['apres']['sepa_titulaire'],
    $famille['apres']['pending_email_token_hash'],
) as $secret) {
    $assert('audit famille : "' . substr($secret, 0, 10) . '…" absent du JSON produit', strpos($details, $secret) === false, true);
}

/* ---- Enfant : donnée de santé jamais enregistrée en clair ---- */
$enfant = $audit_cases['enfant_allergies'];
$diff_enfant = psc_audit_redact_diff('enfant', $enfant['avant'], $enfant['apres']);
$assert('audit enfant : allergies -> "modifié", jamais le texte saisi', $diff_enfant['apres']['allergies'], 'modifié');
$details_enfant = psc_audit_build_details(
    isset($diff_enfant['avant']) ? $diff_enfant['avant'] : array(),
    isset($diff_enfant['apres']) ? $diff_enfant['apres'] : array(),
    null
);
$assert('audit enfant : texte de l\'allergie absent du JSON', strpos($details_enfant, 'arachides') === false, true);
$assert('audit enfant : champ non déclaré (nom, inchangé) absent', array_key_exists('nom', $diff_enfant), false);

/* ---- Type d'objet inconnu : liste blanche fail-closed ---- */
$diff_inconnu = psc_audit_redact_diff('type_totalement_inconnu', array('x' => 1), array('x' => 2));
$assert('audit : type d\'objet inconnu -> aucun détail (fail-closed)', $diff_inconnu, array());

/* ---- Valeur surdimensionnée : détail remplacé par le marqueur, pas de JSON invalide ---- */
$valeur_20ko = str_repeat('A', 20000);
$diff_long = psc_audit_redact_diff('enfant', array('nom' => 'x'), array('nom' => $valeur_20ko));
$details_long = psc_audit_build_details(
    isset($diff_long['avant']) ? $diff_long['avant'] : array(),
    isset($diff_long['apres']) ? $diff_long['apres'] : array(),
    null
);
$decoded_long = json_decode($details_long, true);
$assert('audit : détail surdimensionné remplacé par le marqueur tronqué', is_array($decoded_long) && !empty($decoded_long['tronque']), true);
$assert('audit : détail tronqué tient sous 8 Ko', strlen($details_long) < 8192, true);

/* ---- Résumé toujours borné à 255 caractères ---- */
$assert('audit : résumé tronqué à 255 caractères', mb_strlen(psc_audit_truncate_resume(str_repeat('x', 500))), 255);

/* ---- Chaînage d'intégrité : trois lignes saines, puis rupture détectée au bon id ---- */
$rows = array();
$previous_hash = '';
foreach (array(
    array('id' => 1, 'horodatage' => '2026-01-01 10:00:00', 'action' => 'famille.creation',     'acteur_type' => 'agent', 'acteur_id' => 1, 'objet_type' => 'famille', 'objet_id' => 10, 'resume' => 'Création'),
    array('id' => 2, 'horodatage' => '2026-01-01 10:05:00', 'action' => 'famille.modification', 'acteur_type' => 'agent', 'acteur_id' => 1, 'objet_type' => 'famille', 'objet_id' => 10, 'resume' => 'Modification'),
    array('id' => 3, 'horodatage' => '2026-01-01 10:10:00', 'action' => 'famille.suppression',  'acteur_type' => 'agent', 'acteur_id' => 1, 'objet_type' => 'famille', 'objet_id' => 10, 'resume' => 'Suppression'),
) as $line) {
    $line['empreinte'] = psc_audit_compute_hash(
        $previous_hash, $line['horodatage'], $line['action'], $line['acteur_type'],
        $line['acteur_id'], $line['objet_type'], $line['objet_id'], $line['resume']
    );
    $previous_hash = $line['empreinte'];
    $rows[] = $line;
}
$assert('audit : chaîne intacte -> aucune rupture détectée', psc_audit_verify_chain_rows($rows), null);

$rows[1]['empreinte'] = str_repeat('0', 64); // altération directe en base, simulée
$assert('audit : rupture de chaîne détectée au bon id (2)', psc_audit_verify_chain_rows($rows), 2);

/* ---- Durées de rétention (étape 6) : valeurs par défaut, bornées, réglables ---- */
$assert('audit : rétention critique par défaut = 1095 jours', psc_audit_retention_days('critique'), 1095);
$assert('audit : rétention normale par défaut = 365 jours', psc_audit_retention_days('normal'), 365);
$assert('audit : rétention volumineux par défaut = 180 jours', psc_audit_retention_days('volumineux'), 180);
$assert('audit : niveau inconnu -> repli à 365 jours', psc_audit_retention_days('inconnu'), 365);

$GLOBALS['psc_test_options']['psc_audit_retention_critique'] = 30;
$assert('audit : rétention réglée par option', psc_audit_retention_days('critique'), 30);
$GLOBALS['psc_test_options']['psc_audit_retention_critique'] = 5; // sous le plancher
$assert('audit : rétention bornée au plancher (30 jours)', psc_audit_retention_days('critique'), 30);
$GLOBALS['psc_test_options']['psc_audit_retention_critique'] = 999999; // au-delà du plafond
$assert('audit : rétention bornée au plafond (3650 jours)', psc_audit_retention_days('critique'), 3650);
unset($GLOBALS['psc_test_options']['psc_audit_retention_critique']);

/* ---- Niveau par code d'action : registre et codes purement sémantiques ---- */
$assert('audit : niveau d\'une action du registre', psc_audit_niveau_for_action('famille.suppression'), 'critique');
$assert('audit : niveau d\'un code sémantique sans hook', psc_audit_niveau_for_action('audit.export'), 'critique');
$assert('audit : niveau inconnu -> repli normal', psc_audit_niveau_for_action('inexistant.action'), 'normal');
$assert('audit : code sémantique inclus dans son niveau', in_array('audit.export', psc_audit_actions_by_niveau('critique'), true), true);

/* ---- Rédaction du résumé à l'oubli d'une famille (Psc_Audit::forget_family()) ---- */
$needles = array('Dupont', 'Alice', 'famille.dupont@example.com');
$assert('audit oubli : résumé neutre -> pas de réécriture', psc_audit_resume_needs_redaction('Facture — génération', $needles), false);
$assert('audit oubli : nom présent -> réécriture requise', psc_audit_resume_needs_redaction('Famille Dupont créée.', $needles), true);
$assert('audit oubli : e-mail présent -> réécriture requise', psc_audit_resume_needs_redaction('Connexion de famille.dupont@example.com.', $needles), true);
$assert('audit oubli : casse différente toujours détectée', psc_audit_resume_needs_redaction('connexion de FAMILLE.DUPONT@EXAMPLE.COM', $needles), true);
$assert('audit oubli : aiguille vide ignorée sans faux positif', psc_audit_resume_needs_redaction('Résumé quelconque', array('', ' ', 'x')), false);

/* ---- Fichier de repli journal-acces.log : message technique seul (P1-11) ---- */
$msg = psc_audit_technical_message("Duplicate entry 'jean.dupont@example.com' for key 'email'");
$assert('audit repli : valeur SQL entre guillemets masquée', strpos($msg, 'jean') === false && strpos($msg, 'Duplicate entry') === 0, true);
$assert('audit repli : e-mail nu masqué', strpos(psc_audit_technical_message('Refus pour marie@example.org'), 'marie') === false, true);
$assert('audit repli : IBAN et identifiants masqués', preg_match('/\d{4,}/', psc_audit_technical_message('Echec FR7630006000011234567890189 ligne 123456')), 0);
$assert('audit repli : guillemets doubles masqués', psc_audit_technical_message('Data too long for column "Lou Martin"'), 'Data too long for column "…"');
$assert('audit repli : message tronqué à 200 caractères', mb_strlen(psc_audit_technical_message(str_repeat('erreur ', 100))), 200);
$assert('audit repli : message technique conservé', psc_audit_technical_message("Table 'wp_psc_audit_log' doesn't exist"), "Table '…' doesn't exist");

/* ---- Tâches planifiées en retard (P1-11, alerte de panne) ---- */
$now = 1_800_000_000;
$next = array('a' => $now - 7 * 3600, 'b' => $now - 5 * 3600, 'c' => $now + 3600, 'd' => false);
$assert('cron : seule la tâche en retard de plus de 6 h est signalée', psc_late_cron_hooks($next, $now), array('a'));
$assert('cron : délai de grâce réglable', psc_late_cron_hooks($next, $now, 3600), array('a', 'b'));
$assert('cron : échéance absente, pas un retard', psc_late_cron_hooks(array('d' => false), $now), array());
$assert('cron : les huit tâches récurrentes sont libellées', count(array_filter(psc_recurring_cron_hooks())), 8);

/* ---- Chaînage v2 et lignes purgées (P1-11, schéma 4.16.0) ---- */
$mk = function ($id, $resume) {
    return array('id' => $id, 'horodatage' => '2026-09-26 10:00:0' . $id, 'action' => 'menu.enregistrement',
        'acteur_type' => 'admin', 'acteur_id' => 1, 'objet_type' => 'menu', 'objet_id' => $id, 'resume' => $resume,
        'empreinte' => null, 'empreinte_contenu' => null, 'purgee_le' => null);
};
$chain = function (array $rows, $seuil) {
    $prev = '';
    foreach ($rows as $i => $r) {
        if ($seuil > 0 && $r['id'] >= $seuil) $r['empreinte_contenu'] = 'x'; // marque v2 avant calcul
        $e = psc_audit_expected_hashes($prev, $r, $seuil);
        $rows[$i]['empreinte'] = $e['empreinte'];
        $rows[$i]['empreinte_contenu'] = ($seuil > 0 && $r['id'] >= $seuil) ? $e['contenu'] : psc_audit_content_hash($r['horodatage'], $r['action'], $r['acteur_type'], $r['acteur_id'], $r['objet_type'], $r['objet_id'], $r['resume']);
        $prev = $e['empreinte'];
    }
    return $rows;
};
$purge = function (array $row) {
    return array_merge($row, array('acteur_id' => null, 'objet_id' => null, 'resume' => '', 'purgee_le' => '2027-01-01 00:00:00'));
};
// Lignes 1-2 en v1 (antérieures à la mise à jour), 3-6 en v2.
$rows = $chain(array($mk(1, 'a'), $mk(2, 'b'), $mk(3, 'c'), $mk(4, 'd'), $mk(5, 'e'), $mk(6, 'f')), 3);
$assert('chaînage mixte v1/v2 : intact', psc_audit_verify_chain_rows($rows, 3), null);
$assert('chaînage v2 : empreinte = précédente + contenu', $rows[3]['empreinte'], psc_audit_chain_hash($rows[2]['empreinte'], $rows[3]['empreinte_contenu']));
$purged = $rows; $purged[3] = $purge($rows[3]); $purged[1] = $purge($rows[1]);
$assert('lignes purgées (v1 et v2) au milieu : chaîne toujours vérifiable', psc_audit_verify_chain_rows($purged, 3), null);
$bad = $purged; $bad[3]['empreinte_contenu'] = str_repeat('0', 64);
$assert('ligne purgée v2 falsifiée : détectée', psc_audit_verify_chain_rows($bad, 3), 4);
$bad = $rows; $bad[4]['resume'] = 'altéré';
$assert('ligne v2 altérée : détectée', psc_audit_verify_chain_rows($bad, 3), 5);
$bad = $rows; $bad[4]['empreinte_contenu'] = str_repeat('1', 64);
$assert('empreinte de contenu v2 altérée seule : détectée', psc_audit_verify_chain_rows($bad, 3), 5);
$bad = $rows; $bad[1]['resume'] = 'altéré';
$assert('ligne v1 altérée : toujours détectée', psc_audit_verify_chain_rows($bad, 3), 2);
$assert('sans seuil (avant mise à jour) : tout en v1', psc_audit_verify_chain_rows($chain(array($mk(1, 'a'), $mk(2, 'b')), 0), 0), null);
$assert('ligne sans empreinte de contenu après le seuil : v1', psc_audit_row_chain_version(array('id' => 9, 'empreinte_contenu' => null), 3), 1);
