<?php
/**
 * Chiffrement au repos et empreintes.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Secrets de chiffrement des données bancaires, du courant au plus ancien.
 *
 * Le secret courant est la constante PSC_ENCRYPTION_KEY de wp-config.php
 * quand elle est déclarée :
 *
 *     define('PSC_ENCRYPTION_KEY', 'une-longue-chaine-aleatoire');
 *
 * sinon la variable d'environnement du même nom — le moyen naturel d'un
 * WordPress en conteneur, dont wp-config.php est généré par l'image
 * (docker-compose : `environment: PSC_ENCRYPTION_KEY: …`). Même principe
 * pour PSC_ENCRYPTION_KEY_PREVIOUS.
 *
 * À défaut, c'est wp_salt('psc_sepa'). Pour ce nom de sel propre au
 * plugin, WordPress n'utilise PAS les sels de wp-config.php : il dérive le
 * sel de l'option `secret_key`, enregistrée EN BASE (sauf constante
 * SECRET_KEY, que le wp-config.php standard ne déclare pas). Sans
 * PSC_ENCRYPTION_KEY, un dump de la base contient donc de quoi déchiffrer
 * les IBAN : la clé doit être sortie de la base (cf. Psc_Key_Rotation et
 * la commande `wp psc chiffrement`).
 *
 * Les secrets précédents ne servent qu'à lire : PSC_ENCRYPTION_KEY_PREVIOUS
 * pendant une rotation entre deux constantes, et le secret tiré de la base
 * tant que des valeurs chiffrées avant la déclaration de la constante
 * n'ont pas été rechiffrées (`wp psc chiffrement rechiffrer`). Toute
 * écriture utilise le secret courant.
 *
 * @return array<string, string> Secrets indexés par origine, le courant en premier.
 */
function psc_encryption_secrets() {
    $secrets = array();
    if (defined('PSC_ENCRYPTION_KEY') && PSC_ENCRYPTION_KEY) {
        $secrets['constante'] = (string) PSC_ENCRYPTION_KEY;
    } elseif (psc_env_secret('PSC_ENCRYPTION_KEY') !== '') {
        $secrets['environnement'] = psc_env_secret('PSC_ENCRYPTION_KEY');
    }
    if ($secrets) {
        if (defined('PSC_ENCRYPTION_KEY_PREVIOUS') && PSC_ENCRYPTION_KEY_PREVIOUS) {
            $secrets['constante_precedente'] = (string) PSC_ENCRYPTION_KEY_PREVIOUS;
        } elseif (psc_env_secret('PSC_ENCRYPTION_KEY_PREVIOUS') !== '') {
            $secrets['constante_precedente'] = psc_env_secret('PSC_ENCRYPTION_KEY_PREVIOUS');
        }
    }
    $secrets['base'] = wp_salt('psc_sepa');
    return apply_filters('psc_encryption_secrets', $secrets);
}

/** Clé courante (32 octets bruts), la seule utilisée pour chiffrer. */
function psc_encryption_key() {
    $secrets = psc_encryption_secrets();
    return hash('sha256', (string) reset($secrets), true);
}

/** Variable d'environnement non vide, ou ''. */
function psc_env_secret($name) {
    $value = getenv($name);
    if ($value === false || $value === '') $value = $_ENV[$name] ?? ($_SERVER[$name] ?? '');
    return is_string($value) ? trim($value) : '';
}

/** Vrai si la clé courante est hors de la base (constante ou variable d'environnement). */
function psc_encryption_key_outside_db() {
    return psc_encryption_key_source() !== 'base';
}

/**
 * Origine du secret courant : 'constante' (wp-config.php), 'environnement'
 * (variable du conteneur) ou 'base' (option secret_key).
 */
function psc_encryption_key_source() {
    $secrets = psc_encryption_secrets();
    return (string) key($secrets);
}

/**
 * Chiffre une valeur destinée à la base. Retourne une chaîne préfixée
 * "psc1:" — le préfixe rend l'opération idempotente (une valeur déjà
 * chiffrée n'est jamais re-chiffrée, ce qui évite toute une classe de bugs
 * quand une donnée transite d'une table à l'autre) et permet de reconnaître
 * les valeurs héritées restées en clair.
 *
 * ÉCHEC EXPLICITE : sans primitive disponible, ou si le chiffrement
 * OpenSSL échoue, un WP_Error est renvoyé et la donnée n'est JAMAIS
 * écrite en clair — l'appelant doit refuser l'enregistrement (réessayable
 * une fois l'hébergement réparé) plutôt que de stocker un IBAN lisible
 * dans la base. Un chiffrement qui échoue en silence est pire qu'un
 * enregistrement refusé : la famille croit être protégée.
 *
 * @return string|WP_Error La valeur chiffrée, ou WP_Error('psc_crypto_unavailable')
 *                        / WP_Error('psc_crypto_failed').
 */
function psc_encrypt($value) {
    if ($value === null || $value === '') return $value;
    $value = (string) $value;
    if (strpos($value, 'psc1:') === 0) return $value; // déjà chiffrée

    $key = psc_encryption_key();

    if (function_exists('sodium_crypto_secretbox')) {
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($value, $nonce, $key);
        return 'psc1:' . base64_encode($nonce . $cipher);
    }

    if (function_exists('openssl_encrypt')) {
        $iv     = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return new WP_Error('psc_crypto_failed', __('Le chiffrement des données bancaires a échoué : enregistrement refusé.', 'periscolaire-registration'));
        }
        return 'psc1:' . base64_encode($iv . $tag . $cipher);
    }

    return new WP_Error('psc_crypto_unavailable', __('Aucune primitive de chiffrement disponible sur le serveur : enregistrement refusé. Contactez l\'hébergeur (extension sodium ou OpenSSL requise).', 'periscolaire-registration'));
}

/**
 * Déchiffre une valeur lue en base. Une valeur sans préfixe est retournée
 * telle quelle (donnée héritée, enregistrée avant le chiffrement). Retourne
 * null si aucune clé connue ne la déchiffre : l'appelant affiche alors un
 * champ vide à ressaisir plutôt que de planter.
 */
function psc_decrypt($value) {
    $result = psc_decrypt_with_source($value);
    return $result[0];
}

/**
 * Comme psc_decrypt(), en indiquant quelle clé a servi : l'origine du
 * secret (cf. psc_encryption_secrets()), 'clair' pour une valeur héritée
 * non chiffrée, '' pour une valeur vide ou illisible.
 *
 * @return array{0: string|null, 1: string}
 */
function psc_decrypt_with_source($value) {
    if ($value === null || $value === '') return array($value, '');
    $value = (string) $value;
    if (strpos($value, 'psc1:') !== 0) return array($value, 'clair');

    $raw = base64_decode(substr($value, 5), true);
    if ($raw === false) return array(null, '');

    foreach (psc_encryption_secrets() as $source => $secret) {
        $plain = psc_decrypt_raw($raw, hash('sha256', (string) $secret, true));
        if ($plain !== null) return array($plain, $source);
    }
    return array(null, '');
}

/** Déchiffrement authentifié (sodium, sinon AES-256-GCM) avec une clé donnée, ou null. */
function psc_decrypt_raw($raw, $key) {
    if (function_exists('sodium_crypto_secretbox_open')) {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($raw) > $n) {
            $plain = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), $key);
            if ($plain !== false) return $plain;
        }
    }

    if (function_exists('openssl_decrypt') && strlen($raw) > 28) {
        $plain = openssl_decrypt(
            substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16)
        );
        if ($plain !== false) return $plain;
    }

    return null;
}

/**
 * Hash d'un jeton de connexion avant stockage.
 * On ne stocke jamais le jeton en clair : une fuite de la base ne permet
 * donc pas de se connecter aux comptes parents.
 */
function psc_hash_token($token) {
    return hash_hmac('sha256', $token, wp_salt('psc_token'));
}

/**
 * Signe une valeur avec les clés secrètes du site.
 * Permet de faire confiance au contenu d'un cookie sans stocker de session
 * en base : si la signature ne correspond pas, la valeur a été altérée.
 */
function psc_sign($payload) {
    return hash_hmac('sha256', $payload, wp_salt('psc_session'));
}
