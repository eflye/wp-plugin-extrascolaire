<?php
/**
 * Tests unitaires des helpers purs — SANS WordPress.
 *
 * Pas de harnais PHPUnit dans ce projet (Playwright seul pour les tests
 * E2E, verify-*.php pour la logique en conditions WP-CLI) : ceux-ci
 * jouent le rôle du test unitaire classique sur les trois seuls fichiers
 * qui n'ont besoin de rien d'autre qu'eux-mêmes :
 *
 *   - includes/helpers/banking.php : validation IBAN (mod-97) et BIC,
 *     masquage, référence de mandat ;
 *   - includes/helpers/crypto.php : chiffrement au repos
 *     (encrypt/decrypt, préfixe "psc1:", idempotence, altération
 *     détectée) et empreinte de jeton ;
 *   - includes/helpers/request.php : validation téléphone (français,
 *     indicatif +33/00 33), code postal (5 chiffres) et dates de
 *     naissance (enfant ≥ 3 ans au 1er septembre, adulte ≥ 18 ans,
 *     jamais dans le futur).
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
require __DIR__ . '/../../includes/helpers/request.php';

// Le résolveur du planning (helpers/planning.php) dépend de helpers/services.php
// pour les codes de prestation — qui eux-mêmes utilisent trois fonctions
// WordPress de base. Des stubs minimaux suffisent (aucune base, aucun
// filtre réellement abonné dans ces helpers) : même mécanique que wp_salt()
// ci-dessus.
if (!function_exists('__')) {
    function __($text, $domain = null) { return $text; }
}
if (!function_exists('get_option')) {
    function get_option($name, $default = false) { return $default; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}

require __DIR__ . '/../../includes/helpers/services.php';
require __DIR__ . '/../../includes/helpers/planning.php';

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
/* request.php — téléphone, code postal, naissances                   */
/* ---------------------------------------------------------------- */

$assert('téléphone : mobile collé', psc_valid_phone('0612345678'), '0612345678');
$assert('téléphone : mobile espacé normalisé', psc_valid_phone('06 12 34 56 78'), '0612345678');
$assert('téléphone : points et tirets', psc_valid_phone('06.12-34 56 78'), '0612345678');
$assert('téléphone : indicatif +33', psc_valid_phone('+33612345678'), '+33612345678');
$assert('téléphone : indicatif 0033', psc_valid_phone('0033612345678'), '0033612345678');
$assert('téléphone : fixe', psc_valid_phone('01 45 67 89 01'), '0145678901');
$assert('téléphone : numéro étranger (+32) -> false', psc_valid_phone('+3212345678'), false);
$assert('téléphone : trop court -> false', psc_valid_phone('123456'), false);
$assert('téléphone : trop long -> false', psc_valid_phone('06 12 34 56 78 90'), false);
$assert('téléphone : lettres -> false', psc_valid_phone('06 12 34 56 AB'), false);
$assert('téléphone : vide -> false', psc_valid_phone(''), false);

$assert('code postal : 95000', psc_valid_postcode('95000'), true);
$assert('code postal : Monaco 98000', psc_valid_postcode('98000'), true);
$assert('code postal : espace -> false', psc_valid_postcode('95 000'), false);
$assert('code postal : 6 chiffres -> false', psc_valid_postcode('950000'), false);
$assert('code postal : 4 chiffres -> false', psc_valid_postcode('9500'), false);
$assert('code postal : vide -> false', psc_valid_postcode(''), false);

// Le 1er septembre de l'année en cours sert de référence : les cas de
// bordure sont calculés pour rester justes quelle que soit la date
// d'exécution (CI comprise, été inclus).
$ref_sept = new DateTime(date('Y') . '-09-01');
$ref_3ans = clone $ref_sept;
$ref_3ans->modify('-3 years');
$b3 = $ref_3ans->format('Y-m-d');
$b3_plus1 = (clone $ref_3ans)->modify('+1 day')->format('Y-m-d');
$b3_moins1 = (clone $ref_3ans)->modify('-1 day')->format('Y-m-d');

$assert('naissance enfant : 3 ans révolus ce 1er septembre', psc_valid_child_birthdate($b3), $b3);
$assert('naissance enfant : un jour de moins (3 ans et 1 jour)', psc_valid_child_birthdate($b3_moins1), $b3_moins1);
$assert('naissance enfant : 2 ans au 1er septembre -> false', psc_valid_child_birthdate($b3_plus1), false);
$assert('naissance enfant : dans le futur -> false', psc_valid_child_birthdate(date('Y-m-d', strtotime('+1 year'))), false);
$assert('naissance enfant : format invalide -> false', psc_valid_child_birthdate('10/03/2019'), false);
$assert('naissance enfant : mois impossible -> false', psc_valid_child_birthdate('2019-13-45'), false);

$ref_18ans = new DateTime(date('Y-m-d'));
$ref_18ans->modify('-18 years');
$a18 = $ref_18ans->format('Y-m-d');
$a18_plus1 = (clone $ref_18ans)->modify('+1 day')->format('Y-m-d');

$assert('naissance adulte : 18 ans aujourd\'hui', psc_valid_adult_birthdate($a18), $a18);
$assert('naissance adulte : 17 ans -> false', psc_valid_adult_birthdate($a18_plus1), false);
$assert('naissance adulte : né avant 2000 (pas de plancher d\'année)', psc_valid_adult_birthdate('1976-03-15'), '1976-03-15');
$assert('naissance adulte : dans le futur -> false', psc_valid_adult_birthdate(date('Y-m-d', strtotime('+1 year'))), false);

/* ---------------------------------------------------------------- */
/* planning.php — résolution « rythme + exceptions » (psc_is_declared) */
/* ---------------------------------------------------------------- */
/* Les règles PURES du modèle de déclaration (v4) — la partie WordPress
   (lectures en base, verrous, migration) est couverte par
   bin/verify-planning-migration.php et les specs E2E. Ici : la source de
   vérité elle-même, qui arbitre facturation, listes intervenants et
   effectifs cantine. */

$forf = psc_forfait_code(); // 'FORF'

// 1. Pattern seul : « cet enfant mange à la cantine tous les mardis ».
$assert('résolution : pattern seul -> déclaré', psc_resolve_declaration(false, true, null, false, null, true, true, true), true);
$assert('résolution : pas de pattern -> non déclaré', psc_resolve_declaration(false, false, null, false, null, true, true, true), false);

// 2-3. Exception sur le triplet : elle gagne, quelle que soit sa valeur.
$assert('résolution : ajout exceptionnel sans pattern -> déclaré', psc_resolve_declaration(false, false, true, false, null, true, true, true), true);
$assert('résolution : retrait exceptionnel malgré pattern -> non déclaré', psc_resolve_declaration(false, true, false, false, null, true, true, true), false);
$assert('résolution : ajout exceptionnel malgré pattern (redondant, jamais écrit) -> déclaré', psc_resolve_declaration(false, true, true, false, null, true, true, true), true);

// 4. Hors jour d'école (vacances, férié, mercredi, week-end, fermeture
//    manuelle) : TOUJOURS false, quelle que soit la donnée stockée.
$assert('résolution : jour fermé (vacances/férié/mercredi) -> false même avec pattern', psc_resolve_declaration(false, true, null, false, null, false, true, true), false);
$assert('résolution : jour fermé -> false même avec exception d\'ajout', psc_resolve_declaration(false, false, true, false, null, false, true, true), false);
$assert('résolution : jour fermé -> false même pour le forfait', psc_resolve_declaration(true, false, null, true, null, false, true, true), false);

// 5. Prestation fermée un jour d'école : jamais déclarée (l'ancien
//    modèle supprimait les lignes ; ici la fermeture est soustraite).
$assert('résolution : prestation fermée -> false malgré pattern', psc_resolve_declaration(false, true, null, false, null, true, false, true), false);

// 6. Forfait : couvre les prestations élémentaires ; s'il perd une
//    composante (fermée), il n'est pas réalisable — et la composante
//    fermée ne se déclare pas, les autres restent couvertes par le
//    forfait (équivalent calculé de l'ancienne conversion FORF -> unités).
$assert('résolution : forfait déclaré sans pattern unitaire -> unité couverte', psc_resolve_declaration(false, false, null, true, null, true, true, true), true);
$assert('résolution : forfait réalisé -> forfait déclaré', psc_resolve_declaration(true, false, null, true, null, true, true, true), true);
$assert('résolution : une composante fermée -> forfait non réalisé', psc_resolve_declaration(true, false, null, true, null, true, true, false), false);
$assert('résolution : une composante fermée -> les AUTRES restent couvertes', psc_resolve_declaration(false, false, null, true, null, true, true, false), true);
$assert('résolution : retrait exceptionnel du forfait -> non déclaré', psc_resolve_declaration(true, false, null, true, false, true, true, true), false);

// 7. Invariant d'écriture : jamais d'exception dont la valeur égale le
//    rythme. Un parent qui coche puis décoche un jour doit provoquer la
//    SUPPRESSION de la ligne, pas sa mise à jour — sinon la table se
//    remplit de bruit et un futur changement de rythme ne se propage
//    plus à ce jour. Bascule d'un jour deux fois : aucune ligne résiduelle.
$assert('invariant : cocher sans pattern -> exception posée', psc_exception_write_decision(false, false, false, true), 'upsert');
$assert('invariant : décocher (retour au rythme) -> exception supprimée', psc_exception_write_decision(false, false, false, false), 'delete');
// Sans exception au départ, une exception n'a jamais à exister : le double
// clic (posée puis supprimée) laisse la table inchangée.
$decisions = array(
    psc_exception_write_decision(false, false, false, true),  // clic 1 : upsert
    psc_exception_write_decision(false, false, false, false), // clic 2 : delete
);
$assert('invariant : cocher puis décocher -> plus aucune ligne d\'exception', $decisions === array('upsert', 'delete'), true);

// 8. Changement de pattern : les jours verrouillés conservent leur état.
//    Le jour verrouillé était déclaré (pattern on) ; le parent passe le
//    pattern off — l'état effectif d'avant (true) doit être matérialisé
//    en exception figée : l'écriture cible reste un UPSERT (jamais un
//    delete), sans quoi le mardi déjà transmis à la cantine disparaîtrait.
$assert('verrou : jour verrouillé déclaré, pattern passe à off -> exception figée posée', psc_exception_write_decision(false, false, false, true), 'upsert');
$assert('verrou : jour verrouillé non déclaré, pattern passe à on -> exception figée posée (valeur false)', psc_exception_write_decision(false, true, false, false), 'upsert');

// 9. Couverture par le forfait dans l'invariant : cocher une prestation
//    élémentaire sur un jour déjà couvert par un pattern de forfait est
//    un no-op (la base de comparaison inclut la couverture).
$assert('invariant : cocher une unité déjà couverte par le forfait -> delete (no-op)', psc_exception_write_decision(false, false, true, true), 'delete');
$assert('invariant : décocher une unité couverte par le forfait -> retrait écrit', psc_exception_write_decision(false, false, true, false), 'upsert');

// 10. Facturation : un forfait déclaré (et réalisable) est facturé à lui
//     seul, jamais cumulé avec ses composantes.
$assert('facturation : forfait seul', psc_billing_services(array('FORF' => true, 'GM' => false, 'CANT' => false, 'GS' => false)), array('FORF'));
$assert('facturation : unités sans forfait', psc_billing_services(array('FORF' => false, 'GM' => true, 'CANT' => false, 'GS' => true)), array('GM', 'GS'));
$assert('facturation : rien de déclaré', psc_billing_services(array('FORF' => false, 'GM' => false, 'CANT' => false, 'GS' => false)), array());

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
