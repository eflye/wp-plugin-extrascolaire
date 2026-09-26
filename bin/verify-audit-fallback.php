<?php
/**
 * Script de vérification autonome (P1-11) — fichier de repli du journal
 * d'audit (journal-acces.log), en conditions WP-CLI réelles :
 *  - une vraie insertion ratée (table absente) incrémente le compteur et
 *    écrit une ligne, sans les détails de l'action ni valeur personnelle ;
 *  - au-delà de la taille maximale, le fichier est archivé en .1 (une
 *    seule génération) ;
 *  - la purge quotidienne supprime les fichiers plus vieux que la durée
 *    de rétention « normal », et garde les récents.
 *
 * Le fichier existant, son archive et le compteur sont sauvegardés puis
 * restaurés.
 *
 * Usage :
 *   wp --require=bin/verify-audit-fallback.php verify-audit-fallback
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-audit-fallback', function () {

    if (!class_exists('Psc_Audit') || !function_exists('psc_audit_technical_message')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $path = Psc_Audit::fallback_path();
    if (!$path) WP_CLI::error('Répertoire privé indisponible.');

    $saved = array();
    foreach (array($path, $path . '.1') as $file) {
        $saved[$file] = is_file($file) ? file_get_contents($file) : null;
        if (is_file($file)) unlink($file);
    }
    $failures_before = get_option('psc_audit_failures', 0);

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    $table = psc_table('audit_log');
    $hidden = $table . '_verify_hidden';
    $small = function () { return 400; };

    try {
        // 1. Vraie panne d'écriture : la table du journal est absente.
        $wpdb->query("RENAME TABLE $table TO $hidden");
        $suppress = $wpdb->suppress_errors(true);
        Psc_Audit::log('famille.modification', array(
            'meta'   => array('email' => 'jeanne.verify@example.invalid', 'iban' => 'FR7630006000011234567890189'),
            'resume' => 'Jeanne Verify a modifié son profil.',
        ));
        $wpdb->suppress_errors($suppress);
        $wpdb->query("RENAME TABLE $hidden TO $table");

        $content = is_file($path) ? (string) file_get_contents($path) : '';
        $line = json_decode(trim($content), true);
        $check((int) get_option('psc_audit_failures', 0) === (int) $failures_before + 1, 'panne : compteur non incrémenté');
        $check(is_array($line) && ($line['action'] ?? '') === 'famille.modification', 'panne : ligne de repli absente ou illisible');
        $check(is_array($line) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($line['horodatage'] ?? '')), 'panne : horodatage absent');
        foreach (array('jeanne', 'Verify', 'example.invalid', 'FR76', $wpdb->prefix . 'psc_audit_log') as $needle) {
            $check(stripos($content, $needle) === false, "panne : « $needle » présent dans le fichier de repli");
        }

        // 2. Rotation : au-delà de la taille maximale, archive .1.
        add_filter('psc_audit_fallback_max_bytes', $small);
        $record = new ReflectionMethod('Psc_Audit', 'record_failure');
        $record->setAccessible(true);
        for ($i = 0; $i < 6; $i++) $record->invoke(null, 'verify.rotation', 'Erreur technique de test numéro ' . $i);
        remove_filter('psc_audit_fallback_max_bytes', $small);
        clearstatcache();
        $check(is_file($path . '.1'), 'rotation : pas d’archive .1');
        $check(is_file($path) && filesize($path) <= 400, 'rotation : fichier courant au-delà de la taille maximale');
        $check(!is_file($path . '.2'), 'rotation : plus d’une génération conservée');

        // 3. Rétention : l'archive vieillie est supprimée, le courant gardé.
        touch($path . '.1', time() - (psc_audit_retention_days('normal') + 1) * DAY_IN_SECONDS);
        $removed = Psc_Audit::purge_fallback();
        clearstatcache();
        $check($removed === 1 && !is_file($path . '.1'), 'rétention : archive expirée conservée');
        $check(is_file($path), 'rétention : fichier récent supprimé');
        Psc_Audit::purge_expired(5);
        $check(is_file($path), 'purge quotidienne : fichier récent supprimé');
        touch($path, time() - (psc_audit_retention_days('normal') + 1) * DAY_IN_SECONDS);
        Psc_Audit::purge_expired(5);
        clearstatcache();
        $check(!is_file($path), 'purge quotidienne : fichier expiré conservé');
    } finally {
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hidden))) {
            $wpdb->query("RENAME TABLE $hidden TO $table");
        }
        remove_filter('psc_audit_fallback_max_bytes', $small);
        foreach ($saved as $file => $data) {
            if (is_file($file)) unlink($file);
            if ($data !== null) file_put_contents($file, $data);
        }
        update_option('psc_audit_failures', $failures_before, false);
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Fichier de repli du journal : $checks vérifications.");
});
