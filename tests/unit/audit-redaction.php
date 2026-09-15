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
