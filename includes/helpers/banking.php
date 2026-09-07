<?php
/**
 * Coordonnées bancaires : validation, masquage, référence de mandat.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/banking-uk.php';

/** Source unique pour les contrôles PHP et navigateur. */
function psc_banking_rules() {
    static $rules = null;
    if ($rules === null) {
        $rules = array(
            'patterns' => json_decode(file_get_contents(__DIR__ . '/../data/iban-patterns.json'), true),
            'uk' => json_decode(file_get_contents(__DIR__ . '/../data/uk-modulus.json'), true),
        );
    }
    return $rules;
}

function psc_banking_assets() {
    wp_enqueue_script('psc-banking', PSC_URL . 'assets/js/banking.js', array(), PSC_VERSION, true);
    wp_localize_script('psc-banking', 'PSC_BANK', psc_banking_rules());
}

/**
 * Valide un IBAN : pays et structure ISO 13616 + clé mod-97 + contrôles nationaux FR/GB.
 * Renvoie l'IBAN normalisé (majuscules, sans espaces) ou false.
 */
function psc_valid_iban($iban) {
    if (!is_string($iban) || strlen($iban) > 128) return false;
    // Espaces de présentation uniquement : ne jamais effacer ponctuation,
    // caractères de contrôle ou caractères invisibles pour « réparer » un IBAN.
    $iban = strtoupper(str_replace(array(' ', "\xc2\xa0", "\xe2\x80\xaf"), '', $iban));
    if (!preg_match('/\A[A-Z]{2}[0-9]{2}[A-Z0-9]+\z/', $iban)) return false;
    $key = (int) substr($iban, 2, 2);
    if ($key < 2 || $key > 98) return false;
    $rules = psc_banking_rules();
    $country = substr($iban, 0, 2);
    if (!isset($rules['patterns'][$country])) return false;
    if (!preg_match('/\A' . $country . $rules['patterns'][$country] . '\z/', $iban)) return false;

    // Les 4 premiers caractères passent à la fin, les lettres deviennent
    // des chiffres (A=10 .. Z=35), puis on vérifie que le nombre obtenu
    // est congru à 1 modulo 97 (ISO 7064 MOD 97-10).
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    for ($i = 0; $i < strlen($rearranged); $i++) {
        $ch = $rearranged[$i];
        $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
    }

    // Modulo 97 par blocs : le nombre dépasse la capacité d'un int.
    $checksum = 0;
    foreach (str_split($numeric, 7) as $block) {
        $checksum = ((int) ((string) $checksum . $block)) % 97;
    }
    if ($checksum !== 1) return false;
    if ($country === 'FR' && !psc_valid_french_rib($iban)) return false;
    if ($country === 'GB' && !psc_valid_uk_account(substr($iban, 8, 6), substr($iban, 14, 8), $rules['uk'])) return false;
    return $iban;
}

/** Clé RIB : conversion nationale des lettres, distincte de celle de l’IBAN. */
function psc_valid_french_rib($iban) {
    $account = strtr(substr($iban, 14, 11), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', '12345678912345678923456789');
    $remainder = 0;
    foreach (str_split($account) as $digit) $remainder = ($remainder * 10 + (int) $digit) % 97;
    $key = 97 - ((89 * (int) substr($iban, 4, 5) + 15 * (int) substr($iban, 9, 5) + 3 * $remainder) % 97);
    return $key === (int) substr($iban, 25, 2);
}

/**
 * Valide un BIC/SWIFT : 8 caractères (siège) ou 11 (agence).
 * Renvoie le BIC normalisé (majuscules, sans espaces) ou false.
 */
function psc_valid_bic($bic) {
    $bic = strtoupper(preg_replace('/\s+/', '', (string) $bic));
    return preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic) ? $bic : false;
}

/**
 * IBAN partiellement masqué pour l'affichage admin (garde le pays et les
 * 4 derniers caractères) : réduit l'exposition d'une donnée bancaire dans
 * une liste consultée régulièrement.
 */
function psc_mask_iban($iban) {
    $iban = (string) $iban;
    $len = strlen($iban);
    if ($len <= 8) return $iban;
    return substr($iban, 0, 4) . ' •••• •••• ' . substr($iban, -4);
}

/**
 * IBAN en clair d'un enregistrement (famille ou demande), quel que soit son
 * mode de stockage. Point de lecture unique : tout accès direct à la colonne
 * sepa_iban doit passer par ici.
 */
function psc_read_iban($record) {
    if (!$record) return '';
    $raw = is_object($record) ? ($record->sepa_iban ?? '') : ($record['sepa_iban'] ?? '');
    return (string) psc_decrypt($raw);
}

/**
 * Référence unique de mandat (RUM), dérivée de l'id de la demande —
 * stable et unique sans écriture supplémentaire. Utilisée à la fois
 * pour le PDF envoyé à la soumission (Psc_Requests::handle_submit) et
 * pour le compte famille créé à l'approbation (Psc_Requests::handle_approve),
 * afin qu'un même formulaire ait toujours la même RUM.
 */
function psc_sepa_mandate_ref($request_id) {
    return 'RUM' . str_pad((int) $request_id, 8, '0', STR_PAD_LEFT);
}

/** Référence distincte pour un mandat créé depuis le profil d'un foyer. */
function psc_parent_sepa_mandate_ref($parent_id) {
    return 'RUMP' . str_pad((int) $parent_id, 8, '0', STR_PAD_LEFT);
}
