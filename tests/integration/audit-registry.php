<?php
/**
 * wp eval-file tests/integration/audit-registry.php
 *
 * Verrou anti-trou : toute action admin_post_psc_… ou wp_ajax_psc_… du
 * plugin doit être classée dans psc_audit_action_registry(), sous peine de faire
 * échouer ce test. C'est ce test, pas seulement le filet générique en
 * production, qui empêche le journal de se trouer silencieusement au fil
 * des évolutions — le filet, lui, se contente de journaliser l'angle mort
 * (code 'inconnu.action') sans jamais bloquer l'action elle-même.
 */
if (!defined('WP_CLI') || !WP_CLI) return;

$registered = array();
foreach (array_keys($GLOBALS['wp_filter']) as $hook) {
    if (preg_match('/^(?:admin_post_(?:nopriv_)?|wp_ajax_(?:nopriv_)?)(psc_.+)$/', $hook, $matches)) {
        $registered[$matches[1]] = true;
    }
}

$registry = psc_audit_action_registry();
$allowed_niveaux = array('critique', 'normal', 'volumineux', 'ignore');

foreach ($registry as $hook => $entry) {
    if (!isset($entry['niveau']) || !in_array($entry['niveau'], $allowed_niveaux, true)) {
        throw new RuntimeException(sprintf('Niveau invalide pour %s.', $hook));
    }
    if ($entry['niveau'] === 'ignore') {
        if (!empty($entry['action']) || !empty($entry['categorie']) || !empty($entry['objet'])) {
            throw new RuntimeException(sprintf('%s : une entrée "ignore" ne doit porter ni action, ni catégorie, ni objet.', $hook));
        }
        continue;
    }
    if (empty($entry['action']) || empty($entry['categorie'])) {
        throw new RuntimeException(sprintf('%s : action et catégorie sont obligatoires hors "ignore".', $hook));
    }
}

$missing = array_diff_key($registered, $registry);
if ($missing) {
    throw new RuntimeException(
        'Actions psc_* publiques absentes du registre d\'audit : ' . implode(', ', array_keys($missing)) . '.'
    );
}

// Chaque code d'action non-ignore doit résoudre une catégorie connue —
// sans quoi Psc_Audit::log() retomberait silencieusement sur 'systeme'.
$known_categories = array(
    'authentification', 'donnees_famille', 'planning', 'facturation', 'bancaire',
    'documents', 'communication', 'presences', 'configuration', 'securite', 'systeme',
);
foreach ($registry as $hook => $entry) {
    if ($entry['niveau'] === 'ignore') continue;
    $categorie = psc_audit_categorie_for_action($entry['action']);
    if (!in_array($categorie, $known_categories, true)) {
        throw new RuntimeException(sprintf('%s : catégorie "%s" inconnue pour l\'action %s.', $hook, $categorie, $entry['action']));
    }
    if ($categorie !== $entry['categorie']) {
        throw new RuntimeException(sprintf(
            '%s : incohérence entre la catégorie déclarée dans le registre (%s) et psc_audit_categorie_for_action() (%s) pour %s.',
            $hook, $entry['categorie'], $categorie, $entry['action']
        ));
    }
    $niveau = psc_audit_niveau_for_action($entry['action']);
    if ($niveau !== $entry['niveau']) {
        throw new RuntimeException(sprintf(
            '%s : incohérence entre le niveau déclaré dans le registre (%s) et psc_audit_niveau_for_action() (%s) pour %s.',
            $hook, $entry['niveau'], $niveau, $entry['action']
        ));
    }
}

WP_CLI::log(sprintf(
    'OK : %d actions publiques classées, %d entrées de registre validées (catégories, niveaux).',
    count($registered),
    count($registry)
));
