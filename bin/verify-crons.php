<?php
/**
 * Script de vérification autonome des tâches planifiées — même rôle que
 * bin/verify-promotion-logic.php : ce script joue celui du test unitaire,
 * en conditions WP-CLI réelles.
 *
 * Une mise à jour par zip (le seul mode de déploiement en production) ne
 * rejoue pas register_activation_hook() : une tâche qui n'est programmée
 * qu'à l'activation disparaît pour de bon dès qu'elle est perdue. Vérifie
 * que chaque tâche récurrente du plugin (wp_schedule_event() dans
 * includes/) est reprogrammée par le seul chargement du plugin.
 *
 * Usage :
 *   wp --require=bin/verify-crons.php verify-crons
 *
 * Efface puis reprogramme les tâches récurrentes du plugin : seules leurs
 * prochaines échéances changent. Aucune donnée écrite.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-crons', function () {

    if (!defined('PSC_FILE')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    // Tâches récurrentes, relevées dans le code plutôt que listées à la
    // main : une tâche ajoutée plus tard est couverte sans y penser.
    $hooks = array();
    foreach (glob(PSC_PATH . 'includes/*.php') as $file) {
        preg_match_all("/wp_schedule_event\([^;]*?'(psc_[a-z0-9_]+)'\s*\)/", (string) file_get_contents($file), $m);
        $hooks = array_merge($hooks, $m[1]);
    }
    $hooks = array_values(array_unique($hooks));
    $check(count($hooks) >= 8, 'moins de 8 tâches récurrentes relevées (' . count($hooks) . ') : expression de recherche à revoir');

    // Les init() appelés au chargement du plugin (plugins_loaded), dans
    // l'ordre du fichier principal : rejouer ce chargement, c'est ce que
    // fait chaque requête après une mise à jour par zip.
    preg_match_all('/(Psc_[A-Za-z0-9_]+)::init\(\);/', (string) file_get_contents(PSC_FILE), $m);
    $inits = array_values(array_unique($m[1]));
    $check(count($inits) > 0, 'aucun init() relevé dans le fichier principal');

    foreach ($hooks as $hook) wp_clear_scheduled_hook($hook);
    foreach ($inits as $class) call_user_func(array($class, 'init'));

    foreach ($hooks as $hook) {
        $check((bool) wp_next_scheduled($hook), "« $hook » n'est pas reprogrammée au chargement du plugin");
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(sprintf('%d/%d vérifications en échec.', count($failures), $checks));
    }
    WP_CLI::success(sprintf('%d vérifications OK (%d tâches récurrentes).', $checks, count($hooks)));
});
