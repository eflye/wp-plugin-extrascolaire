<?php
/**
 * Chiffrement au repos et empreintes. La clé vit dans wp-config.php.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Clé de chiffrement des données bancaires.
 *
 * Priorité à une clé dédiée déclarée dans wp-config.php :
 *
 *     define('PSC_ENCRYPTION_KEY', 'une-longue-chaine-aleatoire');
 *
 * À défaut, elle est dérivée des sels WordPress — qui vivent eux aussi dans
 * wp-config.php, donc hors de la base : un dump SQL seul ne permet pas de
 * déchiffrer, ce qui est précisément la menace visée.
 *
 * ATTENTION : régénérer les sels WordPress (ou changer PSC_ENCRYPTION_KEY)
 * rend les IBAN déjà enregistrés illisibles — ils devront être ressaisis.
 * Déclarer PSC_ENCRYPTION_KEY met à l'abri d'une rotation de sels.
 */
function psc_encryption_key() {
    $secret = defined('PSC_ENCRYPTION_KEY') && PSC_ENCRYPTION_KEY
        ? PSC_ENCRYPTION_KEY
        : wp_salt('psc_sepa');
    return hash('sha256', $secret, true); // 32 octets bruts
}

/**
 * Chiffre une valeur destinée à la base. Retourne une chaîne préfixée
 * "psc1:" — le préfixe rend l'opération idempotente (une valeur déjà
 * chiffrée n'est jamais re-chiffrée, ce qui évite toute une classe de bugs
 * quand une donnée transite d'une table à l'autre) et permet de reconnaître
 * les valeurs héritées restées en clair.
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
        if ($cipher === false) return $value;
        return 'psc1:' . base64_encode($iv . $tag . $cipher);
    }

    return $value; // aucune primitive disponible : ne jamais perdre la donnée
}

/**
 * Déchiffre une valeur lue en base. Une valeur sans préfixe est retournée
 * telle quelle (donnée héritée, enregistrée avant le chiffrement). Retourne
 * null si le déchiffrement échoue — typiquement après une rotation des sels :
 * l'appelant affiche alors un champ vide à ressaisir plutôt que de planter.
 */
function psc_decrypt($value) {
    if ($value === null || $value === '') return $value;
    $value = (string) $value;
    if (strpos($value, 'psc1:') !== 0) return $value; // clair hérité

    $raw = base64_decode(substr($value, 5), true);
    if ($raw === false) return null;
    $key = psc_encryption_key();

    if (function_exists('sodium_crypto_secretbox_open')) {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($raw) <= $n) return null;
        $plain = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), $key);
        if ($plain !== false) return $plain;
    }

    if (function_exists('openssl_decrypt')) {
        if (strlen($raw) <= 28) return null;
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
