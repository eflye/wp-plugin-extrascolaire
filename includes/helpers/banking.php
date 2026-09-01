<?php
/**
 * Coordonnées bancaires : validation, masquage, référence de mandat.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Valide un IBAN : format général (ISO 13616) + clé de contrôle mod-97.
 * Renvoie l'IBAN normalisé (majuscules, sans espaces) ou false.
 */
function psc_valid_iban($iban) {
    $iban = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $iban));
    if (strlen($iban) < 15 || strlen($iban) > 34) return false;
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $iban)) return false;

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
    return $checksum === 1 ? $iban : false;
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
