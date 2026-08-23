<?php
/**
 * Désinstallation du plugin.
 *
 * Exécuté uniquement lorsque l'administrateur SUPPRIME le plugin
 * (pas lors d'une simple désactivation).
 *
 * RGPD : ce plugin stocke des données concernant des mineurs (nom, prénom,
 * classe, présences, justificatifs d'assurance) et des coordonnées bancaires.
 * La suppression du plugin doit donc effacer réellement ces données — base
 * ET fichiers déposés — et non les laisser indéfiniment sur le serveur.
 *
 * Sécurité : la suppression est conditionnée à une constante explicite, pour
 * éviter une perte de données irréversible sur une désinstallation faite par
 * erreur. Pour effacer les données, ajouter dans wp-config.php :
 *     define('PSC_REMOVE_DATA_ON_UNINSTALL', true);
 *
 * Rien n'est énuméré à la main ici : tables, options et transients sont
 * découverts par leur préfixe. Une liste maintenue manuellement dérive —
 * c'est ce qui s'était produit, laissant 10 tables sur 16 et 24 options sur
 * 30 en place après désinstallation.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

// Retire la capacité accordée par Psc_Installer::sync_roles() (pas de
// donnée personnelle, aucun risque à le faire systématiquement — même
// sans PSC_REMOVE_DATA_ON_UNINSTALL — pour ne pas laisser une capacité
// orpheline sur les rôles une fois le plugin supprimé).
foreach (array('administrator', 'editor') as $role_name) {
    $role = get_role($role_name);
    if ($role) $role->remove_cap('psc_manage_periscolaire');
}
delete_option('psc_roles_version');

// Tâche planifiée (purge RGPD des demandes) : retirée dans tous les cas,
// sinon WordPress continuerait d'appeler un hook dont le code a disparu.
// Normalement déjà fait à la désactivation, mais la répéter ici est sans
// risque et couvre le cas d'une suppression de fichiers "à la main".
wp_clear_scheduled_hook('psc_cleanup_requests');

if (!defined('PSC_REMOVE_DATA_ON_UNINSTALL') || !PSC_REMOVE_DATA_ON_UNINSTALL) {
    return;
}

// Lu AVANT la purge des options : le répertoire privé peut avoir été déplacé
// hors de l'arborescence WordPress (constante PSC_PRIVATE_DIR), et cette
// option est alors la seule trace de son emplacement réel. La supprimer
// d'abord reviendrait à perdre l'adresse des fichiers à effacer, et donc à
// laisser des données personnelles derrière soi.
$psc_recorded_private_dir = (string) get_option('psc_private_dir_path', '');

global $wpdb;

/* ---------------- Tables ---------------- */

// Découverte par préfixe : capture aussi les tables ajoutées après l'écriture
// de ce fichier, sans qu'il faille y penser.
$like   = $wpdb->esc_like($wpdb->prefix . 'psc_') . '%';
$tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS `$table`"); // nom issu de SHOW TABLES, pas d'une entrée utilisateur
}

/* ---------------- Options et transients ---------------- */

// '_' est un joker SQL : esc_like() l'échappe, sans quoi 'psc_%' matcherait
// aussi des options d'autres extensions commençant par "psc" + un caractère.
$opt_like = $wpdb->esc_like('psc_') . '%';
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $opt_like));

foreach (array('_transient_psc_', '_transient_timeout_psc_', '_site_transient_psc_', '_site_transient_timeout_psc_') as $prefix) {
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like($prefix) . '%'
    ));
}

wp_cache_flush(); // les options supprimées en SQL direct restent sinon en cache

/* ---------------- Fichiers déposés ---------------- */

/**
 * Supprime récursivement un répertoire et son contenu.
 * Ne suit pas les liens symboliques (ils sont retirés, jamais parcourus).
 */
function psc_uninstall_rmdir($dir) {
    if (!is_dir($dir) || is_link($dir)) {
        if (is_link($dir) || is_file($dir)) @unlink($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            psc_uninstall_rmdir($path);
        } else {
            @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
    }
    @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

// Emplacement actuel des justificatifs d'assurance et des factures, et son
// emplacement historique sous uploads/ (antérieur à la version 4.28.0) —
// les deux sont purgés, une installation ancienne pouvant avoir laissé des
// fichiers derrière elle.
$upload  = wp_upload_dir();
$targets = array(
    WP_CONTENT_DIR . '/psc-private',
    trailingslashit($upload['basedir']) . 'psc-private',
    trailingslashit($upload['basedir']) . 'periscolaire',
);

// Emplacement déclaré dans wp-config.php, et dernier emplacement réellement
// utilisé : hors de l'arborescence WordPress, aucun des chemins ci-dessus ne
// les couvre.
if (defined('PSC_PRIVATE_DIR') && PSC_PRIVATE_DIR) {
    $targets[] = rtrim(PSC_PRIVATE_DIR, '/\\');
}
if ($psc_recorded_private_dir !== '') {
    $targets[] = $psc_recorded_private_dir;
}

foreach (array_unique($targets) as $dir) {
    psc_uninstall_rmdir($dir);
}
