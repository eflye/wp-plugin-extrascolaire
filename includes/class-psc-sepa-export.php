<?php
if (!defined('ABSPATH')) exit;

/** Remise SEPA CORE récurrente, CFONB pain.008.001.02, guide v1.7 (02/2023). */
class Psc_Sepa_Export {
    const NS = 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02';
    // Périmètre EPC v8.0, incluant la Serbie (mai 2026) ; GB couvre les dépendances.
    const SEPA = 'AD AL AT BE BG CH CY CZ DE DK EE ES FI FR GB GI GR HR HU IE IS IT LI LT LU LV MC MD ME MK MT NL NO PL PT RO RS SE SI SK SM VA';
    const EEA = 'AT BE BG CY CZ DE DK EE ES FI FR GR HR HU IE IS IT LI LT LU LV MT NL NO PL PT RO SE SI SK';

    public static function valid_date($value) {
        if (!is_string($value) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)) return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }

    /** ICS français : la clé exclut le code activité (positions 5 à 7). */
    public static function valid_ics($value) {
        if (!is_string($value)) return false;
        $value = strtoupper(str_replace(' ', '', $value));
        if (!preg_match('/\AFR[0-9]{2}[A-Z0-9]{9}\z/', $value)) return false;
        if ((int) substr($value, 2, 2) < 2 || (int) substr($value, 2, 2) > 98) return false;
        $digits = preg_replace_callback('/[A-Z]/', function ($m) { return (string) (ord($m[0]) - 55); }, substr($value, 7) . substr($value, 0, 4));
        $remainder = 0;
        foreach (str_split($digits) as $digit) $remainder = ($remainder * 10 + (int) $digit) % 97;
        return $remainder === 1 ? $value : false;
    }

    public static function creditor() {
        return array(
            'name' => get_option('psc_billing_org_name', ''),
            'ics' => get_option('psc_billing_org_ics', ''),
            'iban' => psc_decrypt(get_option('psc_billing_org_iban', '')),
            'bic' => get_option('psc_billing_org_bic', ''),
        );
    }

    /** Texte SEPA latin : accents translittérés, identifiants jamais modifiés. */
    private static function text($value, $max) {
        $value = trim(preg_replace('/\s+/u', ' ', str_replace(array('’', '–', '—', '&'), array("'", '-', '-', ' et '), remove_accents((string) $value))));
        if ($value === '' || strlen($value) > $max || preg_match("~[^A-Za-z0-9 /?:().,'+\\-]~", $value)) return false;
        return $value;
    }

    private static function reference($value) {
        return is_string($value) && strlen($value) >= 1 && strlen($value) <= 35
            && !preg_match("~[^A-Za-z0-9 /?:().,'+\\-]~", $value)
            && trim($value) === $value && $value[0] !== '/' && substr($value, -1) !== '/' && strpos($value, '//') === false;
    }

    /** Montants de facture DECIMAL : aucun calcul en virgule flottante. */
    private static function cents($value) {
        if (!is_string($value) || !preg_match('/\A([0-9]{1,9})(?:\.([0-9]{1,2}))?\z/', $value, $m)) return false;
        $digits = ltrim($m[1] . str_pad($m[2] ?? '', 2, '0'), '0');
        if (strlen($digits) > strlen((string) PHP_INT_MAX) || (strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) > 0)) return false;
        return (int) $digits;
    }

    private static function amount($cents) {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function in_area($iban, $area) {
        return in_array(substr($iban, 0, 2), explode(' ', $area), true);
    }

    /** Renvoie le XML ou toutes les erreurs bloquantes (jamais de lot partiel). */
    public static function build(array $rows, array $creditor, $month, $collection_date, $today = null) {
        $today = $today ?? current_time('Y-m-d');
        $errors = array();
        if (!self::valid_date($month . '-01')) $errors[] = __('Mois de facturation invalide.', 'periscolaire-registration');
        if (!self::valid_date($collection_date) || $collection_date <= $today) $errors[] = __('Choisissez une date de prélèvement future.', 'periscolaire-registration');
        $name = self::text($creditor['name'] ?? '', 70);
        $ics = self::valid_ics($creditor['ics'] ?? '');
        $iban = psc_valid_iban($creditor['iban'] ?? '');
        $bic_raw = $creditor['bic'] ?? '';
        $bic = $bic_raw === '' ? '' : psc_valid_bic($bic_raw);
        if (!$name) $errors[] = __('Réglages : nom du créancier requis, 70 caractères latins maximum.', 'periscolaire-registration');
        if (!$ics) $errors[] = __('Réglages : ICS français absent ou invalide.', 'periscolaire-registration');
        if (!$iban || !self::in_area($iban, self::SEPA)) $errors[] = __('Réglages : IBAN du créancier absent, illisible ou hors zone SEPA.', 'periscolaire-registration');
        if ($bic === false || ($iban && !self::in_area($iban, self::EEA) && !$bic)) $errors[] = __('Réglages : BIC du créancier invalide ou requis pour un compte hors EEE.', 'periscolaire-registration');
        $transactions = array(); $seen = array(); $total = 0;
        foreach ($rows as $row) {
            $ref = $row['invoice_ref'] ?? '';
            $label = sprintf(__('Facture %s : ', 'periscolaire-registration'), $ref);
            $cents = self::cents($row['montant_decimal'] ?? '');
            if ($cents === 0) continue; // Une facture nulle ne produit pas d'ordre bancaire.
            if ($cents === false) { $errors[] = $label . __('montant invalide (0,01 à 999 999 999,99 EUR).', 'periscolaire-registration'); continue; }
            if (!self::reference($ref) || isset($seen[$ref])) $errors[] = $label . __('référence absente, invalide ou dupliquée.', 'periscolaire-registration');
            $seen[$ref] = true;
            $debtor = self::text($row['titulaire'] ?? '', 70);
            $debtor_iban = psc_valid_iban($row['iban'] ?? '');
            $debtor_bic_raw = $row['bic'] ?? '';
            $debtor_bic = $debtor_bic_raw === '' ? '' : psc_valid_bic($debtor_bic_raw);
            $rum = $row['mandate_ref'] ?? '';
            $signed = substr((string) ($row['mandate_accepted_at'] ?? ''), 0, 10);
            if (!$debtor) $errors[] = $label . __('titulaire requis, 70 caractères latins maximum.', 'periscolaire-registration');
            if (!$debtor_iban || !self::in_area($debtor_iban, self::SEPA)) $errors[] = $label . __('IBAN absent, invalide, illisible ou hors zone SEPA.', 'periscolaire-registration');
            if (!self::reference($rum)) $errors[] = $label . __('référence unique de mandat (RUM) absente ou invalide.', 'periscolaire-registration');
            if (!self::valid_date($signed) || $signed > $today || $signed > $collection_date) $errors[] = $label . __('date d’acceptation du prélèvement absente ou invalide.', 'periscolaire-registration');
            $outside_eea = ($iban && !self::in_area($iban, self::EEA)) || ($debtor_iban && !self::in_area($debtor_iban, self::EEA));
            if ($debtor_bic === false || ($outside_eea && !$debtor_bic)) $errors[] = $label . __('BIC invalide ou requis pour une opération hors EEE.', 'periscolaire-registration');
            $address = self::text($row['adresse'] ?? '', 70);
            $city = self::text(trim(($row['code_postal'] ?? '') . ' ' . ($row['ville'] ?? '')), 70);
            $country = $row['country'] ?? '';
            $country_ok = is_string($country) && preg_match('/\A[A-Z]{2}\z/', $country);
            if ($outside_eea && (!$address || !$city || !$country_ok)) $errors[] = $label . __('adresse complète et pays du titulaire requis pour une opération hors EEE.', 'periscolaire-registration');
            if ($total > PHP_INT_MAX - $cents) { $errors[] = __('Total de la remise trop élevé.', 'periscolaire-registration'); continue; }
            $total += $cents;
            $transactions[] = array('ref' => $ref, 'amount' => self::amount($cents), 'name' => $debtor, 'iban' => $debtor_iban, 'bic' => $debtor_bic,
                'rum' => $rum, 'signed' => $signed, 'address' => $address, 'city' => $city, 'country' => $country_ok ? $country : '');
        }
        if (!$transactions) $errors[] = __('Aucun prélèvement de montant positif pour ce mois.', 'periscolaire-registration');
        if ($errors) return new WP_Error('sepa_invalid', implode("\n", array_unique($errors)));
        if (!class_exists('DOMDocument')) return new WP_Error('sepa_dom', __('L’extension PHP DOM est requise pour cet export.', 'periscolaire-registration'));
        usort($transactions, function ($a, $b) { return strcmp($a['ref'], $b['ref']); });
        // Même contenu et même date = même référence de remise : facilite
        // la détection bancaire d'un fichier téléchargé/importé deux fois.
        $id = 'PSC-' . str_replace('-', '', $month) . '-' . substr(hash('sha256', json_encode(array($collection_date, $name, $ics, $iban, $bic, $transactions))), 0, 20);
        $doc = new DOMDocument('1.0', 'UTF-8'); $doc->formatOutput = true;
        $root = $doc->appendChild($doc->createElementNS(self::NS, 'Document'));
        $message = self::node($doc, $root, 'CstmrDrctDbtInitn');
        $group = self::node($doc, $message, 'GrpHdr');
        self::node($doc, $group, 'MsgId', $id);
        self::node($doc, $group, 'CreDtTm', current_time('c'));
        self::node($doc, $group, 'NbOfTxs', (string) count($transactions));
        self::node($doc, $group, 'CtrlSum', self::amount($total));
        self::node($doc, self::node($doc, $group, 'InitgPty'), 'Nm', $name);
        $batch = self::node($doc, $message, 'PmtInf');
        self::node($doc, $batch, 'PmtInfId', $id . '-1');
        self::node($doc, $batch, 'PmtMtd', 'DD');
        self::node($doc, $batch, 'NbOfTxs', (string) count($transactions));
        self::node($doc, $batch, 'CtrlSum', self::amount($total));
        $type = self::node($doc, $batch, 'PmtTpInf');
        self::node($doc, self::node($doc, $type, 'SvcLvl'), 'Cd', 'SEPA');
        self::node($doc, self::node($doc, $type, 'LclInstrm'), 'Cd', 'CORE');
        self::node($doc, $type, 'SeqTp', 'RCUR');
        self::node($doc, $batch, 'ReqdColltnDt', $collection_date);
        self::node($doc, self::node($doc, $batch, 'Cdtr'), 'Nm', $name);
        self::account($doc, $batch, 'CdtrAcct', $iban);
        self::agent($doc, $batch, 'CdtrAgt', $bic);
        self::node($doc, $batch, 'ChrgBr', 'SLEV');
        $scheme = self::node($doc, self::node($doc, self::node($doc, self::node($doc, $batch, 'CdtrSchmeId'), 'Id'), 'PrvtId'), 'Othr');
        self::node($doc, $scheme, 'Id', $ics);
        self::node($doc, self::node($doc, $scheme, 'SchmeNm'), 'Prtry', 'SEPA');
        foreach ($transactions as $tx) {
            $transaction = self::node($doc, $batch, 'DrctDbtTxInf');
            self::node($doc, self::node($doc, $transaction, 'PmtId'), 'EndToEndId', $tx['ref']);
            self::node($doc, $transaction, 'InstdAmt', $tx['amount'])->setAttribute('Ccy', 'EUR');
            $mandate = self::node($doc, self::node($doc, $transaction, 'DrctDbtTx'), 'MndtRltdInf');
            self::node($doc, $mandate, 'MndtId', $tx['rum']);
            self::node($doc, $mandate, 'DtOfSgntr', $tx['signed']);
            self::agent($doc, $transaction, 'DbtrAgt', $tx['bic']);
            $party = self::node($doc, $transaction, 'Dbtr');
            self::node($doc, $party, 'Nm', $tx['name']);
            if ($tx['address'] && $tx['city'] && $tx['country']) {
                $postal = self::node($doc, $party, 'PstlAdr');
                self::node($doc, $postal, 'Ctry', $tx['country']);
                self::node($doc, $postal, 'AdrLine', $tx['address']);
                self::node($doc, $postal, 'AdrLine', $tx['city']);
            }
            self::account($doc, $transaction, 'DbtrAcct', $tx['iban']);
            self::node($doc, self::node($doc, $transaction, 'RmtInf'), 'Ustrd', 'Periscolaire ' . $month . ' - ' . $tx['ref']);
        }
        $previous = libxml_use_internal_errors(true);
        $valid = $doc->schemaValidate(__DIR__ . '/schemas/pain.008.001.02.xsd');
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        if (!$valid) return new WP_Error('sepa_schema', __('Le fichier ne respecte pas le schéma pain.008.001.02. Aucun export produit.', 'periscolaire-registration'));
        $xml = $doc->saveXML();
        return $xml !== false ? $xml : new WP_Error('sepa_xml', __('Création du fichier XML impossible.', 'periscolaire-registration'));
    }

    private static function node(DOMDocument $doc, DOMNode $parent, $name, $value = null) {
        $node = $doc->createElementNS(self::NS, $name);
        if ($value !== null) $node->appendChild($doc->createTextNode((string) $value));
        $parent->appendChild($node);
        return $node;
    }
    private static function account($doc, $parent, $name, $iban) {
        self::node($doc, self::node($doc, self::node($doc, $parent, $name), 'Id'), 'IBAN', $iban);
    }
    private static function agent($doc, $parent, $name, $bic) {
        $agent = self::node($doc, self::node($doc, $parent, $name), 'FinInstnId');
        if ($bic) self::node($doc, $agent, 'BIC', $bic);
        else self::node($doc, self::node($doc, $agent, 'Othr'), 'Id', 'NOTPROVIDED');
    }
}
