<?php
/**
 * wp eval-file tests/integration/p1-final-summary.php
 *
 * Dossier de preuves P1 — lecture seule, aucune écriture en base ni sur
 * disque. Elle répond à une seule question : parmi les exigences P1, quelles
 * sont celles qu'un contrat technique tient aujourd'hui, et lesquelles
 * restent suspendues à une décision ou à un constat humain.
 *
 * Elle ne conclut donc PAS que le service est conforme : la moitié de la
 * liste ci-dessous n'est pas dans le pouvoir du code.
 */
if (!defined('WP_CLI') || !WP_CLI) return;

global $wpdb;
$proved = array();
$failed = array();

$contract = function ($ref, $label, $ok) use (&$proved, &$failed) {
    if ($ok) $proved[] = $ref . ' — ' . $label;
    else $failed[] = $ref . ' — ' . $label;
};

$has_column = function ($table, $column) use ($wpdb) {
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
        $table, $column
    )) === 1;
};
$has_table = function ($table) use ($wpdb) {
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
        $table
    )) === 1;
};

/* ---- Accès et habilitations ---- */
$caps = psc_domain_capabilities();
$contract('P1-01', 'registre d’intervenants individuels disponible',
    method_exists('Psc_Sidscm', 'intervenants'));
$contract('P1-02', 'capacités métier séparées et cumulables',
    is_array($caps) && count($caps) >= 5);

/* ---- Stockage privé ---- */
$contract('P1-04', 'chemins traversants refusés par psc_private_path()',
    psc_private_path('../../wp-config.php') === '' && psc_private_path('a/../../b') === '');
$contract('P1-04', 'répertoire privé hors racine web, ou garde-fous serveur posés',
    psc_private_dir_url() === null || file_exists(psc_private_dir() . '/.htaccess'));

/* ---- Données sensibles ---- */
$contract('P1-06', 'signalement alimentaire minimal en base',
    $has_column(psc_table('children'), 'food_allergy_signal'));
$settings = Psc_Privacy::privacy_settings();
$contract('P1-07', 'réglages de confidentialité normalisés et paramétrables',
    is_array($settings) && array_key_exists('dpo_email', $settings) && array_key_exists('policy_url', $settings));

/* ---- Rétention ---- */
$policies = psc_retention_policies();
$unvalidated_enabled = array();
foreach ($policies as $key => $policy) {
    if (!empty($policy['enabled']) && empty($policy['validated'])) $unvalidated_enabled[] = $key;
}
$contract('P1-08', 'aucune catégorie de purge active sans validation',
    $unvalidated_enabled === array());
$report = Psc_Retention::run('simulation', time());
$contract('P1-08', 'simulation de rétention sans aucune suppression',
    is_array($report) && (int) $report['removed'] === 0);

/* ---- Droits des personnes ---- */
$fields = Psc_Privacy::anonymized_parent_fields(1);
$contract('P1-09', 'anonymisation partagée couvrant identité et coordonnées bancaires',
    array_key_exists('sepa_iban', $fields) && $fields['sepa_iban'] === null
    && array_key_exists('nom', $fields) && $fields['nom'] === null);
$contract('P1-09', 'suppression mairie exécutable hors requête HTTP (donc testable)',
    method_exists('Psc_Admin_Familles', 'delete_family'));

/* ---- Journalisation ---- */
$contract('P1-11', 'vérification d’intégrité du journal disponible',
    method_exists('Psc_Audit', 'verify_chain'));
$contract('P1-11', 'actions de confidentialité classées dans le registre',
    psc_audit_categorie_for_action('privacy.export') === 'donnees_famille'
    && psc_audit_niveau_for_action('privacy.effacement') === 'critique');
$contract('P1-11', 'rectification de facture tracée comme action critique',
    psc_audit_niveau_for_action('facture.rectification') === 'critique');

/* ---- Chiffrement ---- */
$encrypted = psc_encrypt('FR7630006000011234567890189');
$contract('P1-12', 'IBAN jamais écrit en clair (refus explicite si indisponible)',
    is_wp_error($encrypted) || strpos((string) $encrypted, 'psc1:') === 0);

/* ---- Intégrité facturation et migrations ---- */
$contract('P1-16', 'instantané de facture et table des versions présents',
    $has_column(psc_table('invoices'), 'lines_json')
    && $has_column(psc_table('invoices'), 'version')
    && $has_table(psc_table('invoice_versions')));
$contract('P1-16', 'historique des versions lisible',
    method_exists('Psc_Invoices', 'versions_for_invoice'));
$contract('P1-17', 'verrou de migration en place',
    method_exists('Psc_Installer', 'maybe_upgrade'));

/* ---- Rapport ---- */
foreach ($proved as $line) WP_CLI::log('  [prouvé]  ' . $line);
foreach ($failed as $line) WP_CLI::log('  [ÉCHEC]   ' . $line);

WP_CLI::log('');
WP_CLI::log('Décisions et constats hors du pouvoir du code — ouverts par défaut :');
foreach (array(
    'P1-03 — non-mise en cache des pages familles, derrière le cache réellement utilisé (hébergeur)',
    'P1-04/P1-18 — fichier témoin injoignable en HTTP anonyme depuis l’extérieur (hébergeur)',
    'P1-06 — qualification des anciennes données d’allergie et procédure d’échange oral (DPO, mairie)',
    'P1-07 — relecture de la notice, coordonnées réelles, information des tiers (DPO)',
    'P1-08 — durées de conservation, archivage intermédiaire et sort final (DPO, archives)',
    'P1-09 — vérification d’identité du demandeur et délai de réponse (mairie)',
    'P1-10 — registre, AIPD, contrats de sous-traitance, procédure de violation (mairie, DPO)',
    'P1-12 — sauvegarde et rotation de la clé de chiffrement (hébergeur)',
    'P1-16 — qualification comptable du PDF et procédure de correction (facturation, mairie)',
    'P1-18 — recette d’hébergement remplie et datée (hébergeur, administrateur)',
) as $line) {
    WP_CLI::log('  [manuel]  ' . $line);
}

WP_CLI::log('');
if ($failed) {
    WP_CLI::error(sprintf('%d contrat(s) technique(s) non tenu(s).', count($failed)));
}
WP_CLI::log(sprintf(
    'Dossier P1 : %d contrats techniques tenus, %d décisions manuelles ouvertes. Ce relevé n’établit aucune conformité.',
    count($proved), 10
));
