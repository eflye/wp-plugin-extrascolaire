<?php
/**
 * Script de vérification autonome (P2-10) — la limitation de fréquence
 * telle qu'elle se comporte en PRODUCTION.
 *
 * La CI tourne en WP_ENVIRONMENT_TYPE=local, qui désactive la limitation :
 * sans ce script, son comportement réel n'était jamais testé. Le filtre
 * psc_rate_limit_enabled la réactive ici, le temps du run.
 *
 * Vérifie :
 *  - le quota est tenu, puis refusé ;
 *  - la fenêtre est fixe : insister ne la prolonge pas (sinon un attaquant
 *    priverait durablement une famille de son lien de connexion) ;
 *  - la fenêtre expirée repart de zéro ;
 *  - chaque adresse IP a son propre compteur ; une IP indéterminable passe
 *    (sinon un seul seau pour tous, et la protection deviendrait une panne) ;
 *  - un en-tête X-Forwarded-For fabriqué par le client est ignoré, sauf
 *    déclaration explicite de l'en-tête de confiance, dont on lit alors le
 *    maillon ajouté par le proxy.
 *
 * Usage :
 *   wp --require=bin/verify-rate-limit.php verify-rate-limit
 *
 * Transients de test (préfixe verify_rl_) purgés avant et après.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-rate-limit', function () {

    if (!function_exists('psc_rate_limit') || !function_exists('psc_client_ip')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };
    $keys = array('verify_rl_quota', 'verify_rl_fenetre', 'verify_rl_ip_203.0.113.5', 'verify_rl_ip_203.0.113.6');
    $purge = function () use ($keys) {
        foreach ($keys as $k) delete_transient('psc_rl_' . md5($k));
    };
    $purge();
    $server = $_SERVER;

    // Comportement de production : limitation active.
    add_filter('psc_rate_limit_enabled', '__return_true', 99);

    try {
        // 1. Quota tenu puis refusé.
        $results = array();
        for ($i = 0; $i < 4; $i++) $results[] = psc_rate_limit('verify_rl_quota', 3, 60);
        $check($results === array(true, true, true, false), 'quota : ' . wp_json_encode($results));

        // 2. Fenêtre fixe : insister ne repousse pas l'échéance.
        psc_rate_limit('verify_rl_fenetre', 1, 60);
        $expires = get_transient('psc_rl_' . md5('verify_rl_fenetre'))['expires'];
        sleep(1);
        for ($i = 0; $i < 5; $i++) psc_rate_limit('verify_rl_fenetre', 1, 60);
        $check(get_transient('psc_rl_' . md5('verify_rl_fenetre'))['expires'] === $expires, 'fenêtre prolongée par des tentatives répétées');
        $check(psc_rate_limit('verify_rl_fenetre', 1, 60) === false, 'fenêtre : limite levée avant l’échéance');

        // 3. Fenêtre expirée : le compteur repart de zéro.
        set_transient('psc_rl_' . md5('verify_rl_fenetre'), array('count' => 1, 'expires' => time() - 1), 60);
        $check(psc_rate_limit('verify_rl_fenetre', 1, 60) === true, 'fenêtre expirée : toujours refusé');

        // 4. Un compteur par IP ; IP indéterminable → passe.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        psc_rate_limit_by_ip('verify_rl_ip_', 1, 60);
        $check(psc_rate_limit_by_ip('verify_rl_ip_', 1, 60) === false, 'IP : quota non tenu');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.6';
        $check(psc_rate_limit_by_ip('verify_rl_ip_', 1, 60) === true, 'IP : quota partagé entre deux adresses');
        unset($_SERVER['REMOTE_ADDR']);
        $check(psc_rate_limit_by_ip('verify_rl_ip_', 1, 60) === true, 'IP indéterminable : bloquée (seau commun)');

        // 5. En-tête forgé ignoré ; en-tête de confiance lu au bon maillon.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
        $check(psc_client_ip() === '203.0.113.5', 'X-Forwarded-For forgé pris en compte : ' . psc_client_ip());
        $header = function () { return 'HTTP_X_FORWARDED_FOR'; };
        add_filter('psc_client_ip_header', $header);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99, 192.0.2.44';
        $check(psc_client_ip() === '192.0.2.44', 'proxy déclaré : mauvais maillon lu (' . psc_client_ip() . ')');
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'pas-une-ip';
        $check(psc_client_ip() === '203.0.113.5', 'proxy déclaré, en-tête invalide : ' . psc_client_ip());
        remove_filter('psc_client_ip_header', $header);
    } finally {
        $_SERVER = $server;
        remove_filter('psc_rate_limit_enabled', '__return_true', 99);
        $purge();
    }

    if ($failures) {
        foreach ($failures as $failure) WP_CLI::warning($failure);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Limitation de fréquence (profil production) : $checks vérifications.");
});
