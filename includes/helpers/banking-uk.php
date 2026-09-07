<?php
if (!defined('ABSPATH')) exit;

/** Contrôle national VocaLink V900 pour un BBAN britannique de 18 caractères.
 * Algorithme adapté de cs278/bank-modulus (MIT, voir includes/data).
 */
function psc_valid_uk_account($sort, $account, $data) {
    $checks = array();
    foreach ($data['rows'] as $row) {
        if ((int) $sort >= $row[0] && (int) $sort <= $row[1]) $checks[] = $row;
    }
    if (!$checks) return true; // Hors des plages : contrôle non applicable.
    $number = $sort . $account;
    if (count($checks) === 2 && $checks[0][4] === 2 && $checks[1][4] === 9 && $account[0] !== '0') {
        $checks[0][3] = $account[6] === '9'
            ? array(0,0,0,0,0,0,0,0,8,7,10,9,3,1)
            : array(0,0,1,2,5,3,6,4,8,7,10,9,3,1);
    }
    if (count($checks) === 2 && $checks[0][4] === 6 && $checks[1][4] === 6
        && $account[0] >= '4' && $account[0] <= '8' && $account[6] === $account[7]) return true;
    $first = psc_uk_modulus_check($number, $checks[0], 1, $data['substitutes']);
    if (count($checks) === 1) {
        return $first || ($checks[0][4] === 14 && psc_uk_modulus_check($number, $checks[0], 2, $data['substitutes']));
    }
    $second = psc_uk_modulus_check($number, $checks[1], 2, $data['substitutes']);
    $pair = $checks[0][4] . ':' . $checks[1][4];
    return in_array($pair, array('2:9', '10:11', '12:13'), true) ? ($first || $second) : ($first && $second);
}

function psc_uk_modulus_check($number, $row, $pass, $substitutes) {
    $algorithm = $row[2]; $weights = $row[3]; $exception = $row[4];
    if (($exception === 7 && $number[12] === '9')
        || ($exception === 10 && in_array(substr($number, 6, 2), array('09', '99'), true) && $number[12] === '9')) {
        for ($i = 0; $i < 8; $i++) $weights[$i] = 0;
    }
    if ($exception === 9 && $pass === 2) $number = '309634' . substr($number, 6);
    if ($exception === 8) $number = '090126' . substr($number, 6);
    if ($exception === 5) $number = ($substitutes[substr($number, 0, 6)] ?? substr($number, 0, 6)) . substr($number, 6);
    if ($exception === 14 && $pass === 2) {
        if (!in_array($number[13], array('0', '1', '9'), true)) return false;
        $number = substr($number, 0, 6) . '0' . substr($number, 6, 7);
    }
    if ($algorithm === 'DBLAL' && $exception === 3 && in_array($number[8], array('6', '9'), true)) return true;
    $total = 0;
    for ($i = 0; $i < 14; $i++) {
        $product = (int) $number[$i] * $weights[$i];
        $total += $algorithm === 'DBLAL' ? array_sum(str_split((string) $product)) : $product;
    }
    $remainder = $total % ($algorithm === 'MOD11' ? 11 : 10);
    if ($exception === 4) return $remainder === (int) substr($number, 12, 2);
    if ($exception === 5) {
        $digit = (int) $number[$algorithm === 'DBLAL' ? 13 : 12];
        if ($remainder === 0 && $digit === 0) return true;
        if ($algorithm === 'MOD11' && $remainder === 1) return false;
        return $digit === ($algorithm === 'MOD11' ? 11 : 10) - $remainder;
    }
    if ($exception === 1 && $algorithm === 'DBLAL') return $remainder === 0 || ($total + 27) % 10 === 0;
    return $remainder === 0;
}
