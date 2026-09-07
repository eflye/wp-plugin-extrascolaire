<?php
// Jeux SWIFT, VocaLink et régressions des saisies françaises.
foreach (json_decode(file_get_contents(__DIR__ . '/iban-cases.json'), true) as $case) {
    $assert($case['label'], psc_valid_iban($case['value']) !== false, $case['valid']);
}
foreach (array(null, false, 123, array('FR7630006000011234567890189'), new stdClass()) as $value) {
    $assert('IBAN : type non textuel refusé', psc_valid_iban($value), false);
}
