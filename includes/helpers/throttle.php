<?php
/**
 * Limitation de fréquence des formulaires publics.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Limitation de fréquence (anti-spam / anti-énumération).
 * Renvoie false si la limite est atteinte.
 *
 * Désactivée en environnement local/développement (WP_ENVIRONMENT_TYPE),
 * ou via le filtre psc_rate_limit_enabled : évite d'avoir à purger des
 * transients entre deux runs de test.
 */
function psc_rate_limit($key, $max, $window) {
    if (in_array(wp_get_environment_type(), array('local', 'development'), true)) {
        return true;
    }
    if (!apply_filters('psc_rate_limit_enabled', true)) {
        return true;
    }

    $transient = 'psc_rl_' . md5($key);
    $now = time();
    $data = get_transient($transient);

    // Fenêtre fixe : la date de fin est décidée à la première tentative et
    // ne bouge plus. Repousser l'échéance à chaque appel — ce que fait un
    // simple set_transient($count + 1, $window) — donne un compteur qu'un
    // attaquant maintient vivant indéfiniment : il suffit de continuer à
    // frapper pour qu'il n'expire jamais. Sur la clé « adresse e-mail »,
    // cela permettait de priver durablement une famille visée de son lien
    // de connexion, alors que la limite doit se lever d'elle-même.
    //
    // Le format antérieur stockait un entier nu : il est traité comme une
    // fenêtre absente, ce qui repart proprement.
    if (!is_array($data) || empty($data['expires']) || $data['expires'] <= $now) {
        $data = array('count' => 0, 'expires' => $now + $window);
    }

    if ($data['count'] >= $max) {
        return false;
    }

    // Lecture puis écriture ne sont pas atomiques : deux requêtes
    // simultanées peuvent lire le même compteur et passer toutes les deux.
    // L'écart reste de l'ordre du nombre de requêtes concurrentes, sans
    // commune mesure avec ce que la limite vise à contenir.
    $data['count']++;
    set_transient($transient, $data, $data['expires'] - $now);
    return true;
}

/**
 * Limitation de fréquence indexée sur l'adresse IP de l'appelant.
 *
 * Laisse passer lorsque l'IP n'est pas déterminable : à défaut, toutes les
 * requêtes tomberaient dans le même seau et les premiers visiteurs
 * épuiseraient le quota de tous les autres — la protection anti-abus se
 * retournerait en panne pour l'ensemble des familles. Les limites par
 * adresse e-mail, elles, continuent de s'appliquer.
 */
function psc_rate_limit_by_ip($prefix, $max, $window) {
    $ip = psc_client_ip();
    if ($ip === '') {
        return true;
    }
    return psc_rate_limit($prefix . $ip, $max, $window);
}

/**
 * Adresse IP de la requête, utilisée uniquement pour la limitation de
 * fréquence. Volontairement non stockée en base (donnée personnelle).
 *
 * REMOTE_ADDR par défaut : c'est la seule valeur que l'appelant ne choisit
 * pas. Un en-tête « X-Forwarded-For » est envoyé par le client lui-même —
 * s'y fier sans précaution offrirait à un attaquant une IP différente à
 * chaque requête, donc un contournement complet de la limitation.
 *
 * Derrière un répartiteur de charge ou un CDN, REMOTE_ADDR est en revanche
 * celui de l'intermédiaire, identique pour tout le monde. On peut alors
 * désigner explicitement l'en-tête à lire dans wp-config.php :
 *
 *     define('PSC_CLIENT_IP_HEADER', 'HTTP_X_FORWARDED_FOR');
 *
 * La valeur retenue est la DERNIÈRE de la liste, pas la première : un
 * intermédiaire ajoute l'adresse qu'il constate à la fin de l'en-tête, et
 * tout ce qui précède a pu être fabriqué par le client. Régler
 * PSC_TRUSTED_PROXIES si plusieurs intermédiaires se succèdent.
 *
 * @return string IP validée, ou chaîne vide si indéterminable.
 */
function psc_client_ip() {
    $remote = isset($_SERVER['REMOTE_ADDR'])
        ? filter_var(wp_unslash($_SERVER['REMOTE_ADDR']), FILTER_VALIDATE_IP)
        : false;

    $header = defined('PSC_CLIENT_IP_HEADER') ? PSC_CLIENT_IP_HEADER : '';
    $header = (string) apply_filters('psc_client_ip_header', $header);

    if ($header !== '' && !empty($_SERVER[$header])) {
        $chain = array_map('trim', explode(',', (string) wp_unslash($_SERVER[$header])));
        $depth = defined('PSC_TRUSTED_PROXIES') ? max(1, (int) PSC_TRUSTED_PROXIES) : 1;

        // On remonte la chaîne en sautant autant d'entrées qu'il y a
        // d'intermédiaires de confiance ; au-delà, plus rien n'est vérifiable.
        $index = count($chain) - $depth;
        if ($index >= 0 && isset($chain[$index])) {
            $candidate = filter_var($chain[$index], FILTER_VALIDATE_IP);
            if ($candidate) {
                return $candidate;
            }
        }
    }

    return $remote ?: '';
}
