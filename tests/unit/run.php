<?php
/**
 * Tests unitaires des helpers purs — SANS WordPress.
 *
 * Pas de harnais PHPUnit dans ce projet (Playwright seul pour les tests
 * E2E, verify-*.php pour la logique en conditions WP-CLI) : ceux-ci
 * jouent le rôle du test unitaire classique sur les deux seuls fichiers
 * qui n'ont besoin de rien d'autre qu'eux-mêmes :
 *
 *   - includes/helpers/banking.php : validation IBAN (mod-97) et BIC,
 *     masquage, référence de mandat ;
 *   - includes/helpers/crypto.php : chiffrement au repos
 *     (encrypt/decrypt, préfixe "psc1:", idempotence, altération
 *     détectée) et empreinte de jeton.
 *
 * WordPress n'est PAS amorcé : ABSPATH est posé pour passer les
 * garde-fous, PSC_ENCRYPTION_KEY rend la clé de chiffrement
 * déterministe, et wp_salt() — l'unique fonction WordPress encore
 * utilisée par ces helpers (hash du jeton) — est un stub de trois
 * lignes. Aucune autre dépendance.
 *
 * Branché dans lint.yml sur la matrice PHP 7.4 → 8.3 : les chemins de
 * chiffrement varient selon la build de PHP (sodium, openssl, repli) —
 * le scénario doit tenir sur chacun.
 *
 * Usage :
 *   php tests/unit/run.php          # exit 0 si tout passe, 1 sinon
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/wp/');
}
if (!defined('PSC_ENCRYPTION_KEY')) {
    define('PSC_ENCRYPTION_KEY', 'cle-de-test-unitaire-0123456789abcdef');
}
if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') {
        return 'sel-de-test-' . $scheme;
    }
}

require __DIR__ . '/../../includes/helpers/crypto.php';
require __DIR__ . '/../../includes/helpers/banking.php';

$failures = array();
$checks   = 0;

$assert = function ($label, $actual, $expected) use (&$failures, &$checks) {
    $checks++;
    if ($actual !== $expected) {
        $failures[] = sprintf(
            "%s : attendu %s, obtenu %s",
            $label,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
};

/* ---------------------------------------------------------------- */
/* crypto.php — chiffrement au repos                                  */
/* ---------------------------------------------------------------- */

$iban_sepa = 'FR7630006000011234567890189';

$assert('encrypt : chaîne vide inchangée', psc_encrypt(''), '');
$assert('encrypt : null inchangé', psc_encrypt(null), null);
$assert('encrypt : valeur déjà chiffrée jamais re-chiffrée', psc_encrypt('psc1:DEJA'), 'psc1:DEJA');

$cipher = psc_encrypt($iban_sepa);
$assert('encrypt : préfixe psc1:', strpos($cipher, 'psc1:') === 0, true);
$assert('decrypt : aller-retour chiffré', psc_decrypt($cipher), $iban_sepa);
$assert('encrypt : idempotent', psc_encrypt($cipher), $cipher);
$assert('encrypt : nonce aléatoire (deux chiffrements diffèrent)', psc_encrypt($iban_sepa) !== $cipher, true);
$assert('decrypt : donnée héritée en clair rendue telle quelle', psc_decrypt($iban_sepa), $iban_sepa);
$assert('decrypt : null inchangé', psc_decrypt(null), null);

$assert('decrypt : base64 invalide -> null', psc_decrypt('psc1:!!!pas-du-base64!!'), null);
$assert('decrypt : charge trop courte -> null', psc_decrypt('psc1:' . base64_encode('court')), null);

// Altération garantie différente : le dernier caractère est remplacé par
// un autre, quel que soit le chiffrement produit.
$last     = substr($cipher, -1);
$tampered = substr($cipher, 0, -1) . ($last === 'A' ? 'B' : 'A');
$assert('decrypt : charge altérée -> null', psc_decrypt($tampered), null);

// Quelle primitive a réellement servi : sodium (préférée), sinon
// openssl. Si aucune n'est disponible, encrypt est un repli transparent
// et le round-trip ci-dessus reste vrai (la donnée n'est jamais perdue).
$primitive = function_exists('sodium_crypto_secretbox') ? 'sodium' : (function_exists('openssl_encrypt') ? 'openssl' : 'repli');
$assert('crypto : une primitive de chiffrement disponible', $primitive !== 'repli', true);

$assert('hash_token : 64 caractères hexadécimaux', (bool) preg_match('/^[0-9a-f]{64}$/', psc_hash_token('jeton-connexion')), true);
$assert('hash_token : déterministe', psc_hash_token('jeton-connexion'), psc_hash_token('jeton-connexion'));
$assert('hash_token : deux jetons, deux empreintes', psc_hash_token('jeton-connexion') !== psc_hash_token('autre-jeton'), true);

/* ---------------------------------------------------------------- */
/* banking.php — IBAN / BIC                                           */
/* ---------------------------------------------------------------- */

$assert('IBAN : FR valide, espaces et bas de casse normalisés', psc_valid_iban('FR76 3000 6000 0112 3456 7890 189'), 'FR7630006000011234567890189');
$assert('IBAN : DE valide', psc_valid_iban('DE89 3704 0044 0532 0130 00'), 'DE89370400440532013000');
$assert('IBAN : GB valide', psc_valid_iban('GB82 WEST 1234 5698 7654 32'), 'GB82WEST12345698765432');
$assert('IBAN : clé de contrôle erronée -> false', psc_valid_iban('FR76 3000 6000 0112 3456 7890 188'), false);
$assert('IBAN : trop court -> false', psc_valid_iban('FR76 1234 567'), false);
$assert('IBAN : trop long (> 34) -> false', psc_valid_iban('FR763000600001123456789018900000000000000000'), false);
$assert('IBAN : séparateurs non standards (tirets, points) supprimés', psc_valid_iban('FR76-3000.6000-0112.3456-7890-189'), 'FR7630006000011234567890189');
$assert('IBAN : texte sans structure -> false', psc_valid_iban('bonjour le monde'), false);
$assert('IBAN : pays en bas de casse accepté', psc_valid_iban('fr7630006000011234567890189'), 'FR7630006000011234567890189');

$assert('BIC : 8 caractères (siège)', psc_valid_bic('AGRIFRPP'), 'AGRIFRPP');
$assert('BIC : 11 caractères (agence)', psc_valid_bic('AGRIFRPPXXX'), 'AGRIFRPPXXX');
$assert('BIC : 9 caractères -> false', psc_valid_bic('AGRIFRPP8'), false);
$assert('BIC : bas de casse normalisé', psc_valid_bic('agrifRppxxx'), 'AGRIFRPPXXX');
$assert('BIC : espaces supprimés', psc_valid_bic('AGRIF RPP XXX'), 'AGRIFRPPXXX');
$assert('BIC : caractère interdit -> false', psc_valid_bic('AGRIFRPP8X!'), false);

$assert('masquage : chaîne courte inchangée', psc_mask_iban('FR763000'), 'FR763000');
$assert('masquage : pays + 4 derniers', psc_mask_iban('FR7630006000011234567890189'), 'FR76 •••• •••• 0189');

$assert('mandat : identifiant à 8 chiffres', psc_sepa_mandate_ref(42), 'RUM00000042');
$assert('mandat : identifiant nul', psc_sepa_mandate_ref(0), 'RUM00000000');

$assert(
    'lecture : foyer chiffré déchiffré',
    psc_read_iban((object) array('sepa_iban' => $cipher)),
    $iban_sepa
);
$assert(
    'lecture : donnée héritée en clair dans un tableau',
    psc_read_iban(array('sepa_iban' => $iban_sepa)),
    $iban_sepa
);
$assert('lecture : enregistrement vide', psc_read_iban(false), '');

/* ---------------------------------------------------------------- */

if ($failures) {
    foreach ($failures as $f) {
        echo "  ÉCHEC — $f\n";
    }
}
printf(
    "Tests unitaires des helpers purs : %d vérification(s), %d échec(s) (primitive : %s).\n",
    $checks,
    count($failures),
    $primitive
);
exit($failures ? 1 : 0);
