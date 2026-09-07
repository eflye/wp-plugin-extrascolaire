<?php
/** php tests/unit/sepa-export.php : XML réel + XSD, aucune base ni transmission. */
if (!defined('ABSPATH')) define('ABSPATH', '/wp/');
if (!function_exists('__')) { function __($s, $domain = '') { return $s; } }
if (!function_exists('current_time')) { function current_time($format) { return $format === 'c' ? '2026-09-07T12:00:00+02:00' : '2026-09-07'; } }
if (!function_exists('remove_accents')) { function remove_accents($s) { return strtr($s, array('é'=>'e', 'è'=>'e', 'É'=>'E')); } }
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $message;
        public function __construct($code, $message) { $this->message = $message; }
        public function get_error_message() { return $this->message; }
    }
}
require_once __DIR__ . '/../../includes/helpers/banking.php';
require_once __DIR__ . '/../../includes/class-psc-sepa-export.php';
$checks = 0;
$assert = function ($condition, $label) use (&$checks) {
    $checks++;
    if (!$condition) throw new RuntimeException($label);
};
$creditor = array('name'=>'Mairie de Test', 'ics'=>'FR15ZZZ612780', 'iban'=>'FR7630006000011234567890189', 'bic'=>'AGRIFRPP');
$row = array('invoice_ref'=>'FC-202609-1', 'titulaire'=>'Élodie Père & Fils', 'iban'=>'FR1420041010050500013M02606', 'bic'=>'PSSTFRPPPAR',
    'mandate_ref'=>'PSC-2026-1', 'mandate_accepted_at'=>'2026-09-01 11:22:33', 'montant_decimal'=>'12.34', 'adresse'=>'12 rue des Écoles',
    'code_postal'=>'95000', 'ville'=>'Pontoise', 'country'=>'FR');
$build = function ($rows = null, $config = null, $date = '2026-10-05', $month = '2026-09') use ($row, $creditor) {
    return Psc_Sepa_Export::build($rows ?? array($row), $config ?? $creditor, $month, $date, '2026-09-07');
};
$xml = $build();
$assert(is_string($xml), $xml instanceof WP_Error ? $xml->get_error_message() : 'XML attendu');
$doc = new DOMDocument(); $doc->loadXML($xml);
$assert($doc->schemaValidate(__DIR__ . '/../../includes/schemas/pain.008.001.02.xsd'), 'Schéma officiel');
$xp = new DOMXPath($doc); $xp->registerNamespace('p', Psc_Sepa_Export::NS);
$value = function ($query) use ($xp) { return $xp->evaluate('string(' . $query . ')'); };
foreach (array('//p:PmtMtd'=>'DD', '//p:SvcLvl/p:Cd'=>'SEPA', '//p:LclInstrm/p:Cd'=>'CORE', '//p:SeqTp'=>'RCUR', '//p:ChrgBr'=>'SLEV',
    '//p:DtOfSgntr'=>'2026-09-01', '//p:MndtId'=>'PSC-2026-1', '//p:ReqdColltnDt'=>'2026-10-05', '//p:EndToEndId'=>'FC-202609-1',
    '//p:Dbtr/p:Nm'=>'Elodie Pere et Fils', '//p:GrpHdr/p:NbOfTxs'=>'1', '//p:GrpHdr/p:CtrlSum'=>'12.34', '//p:InstdAmt/@Ccy'=>'EUR',
    '//p:CdtrSchmeId/p:Id/p:PrvtId/p:Othr/p:Id'=>'FR15ZZZ612780', '//p:CdtrSchmeId//p:Prtry'=>'SEPA') as $query=>$expected) $assert($value($query) === $expected, $query);
$assert(strlen($value('//p:MsgId')) <= 35 && strlen($value('//p:PmtInfId')) <= 35, 'Identifiants limités');
foreach (array('iban'=>'FR7630006000011234567890188', 'mandate_ref'=>'', 'mandate_accepted_at'=>'', 'bic'=>'INVALID', 'titulaire'=>'',
    'montant_decimal'=>'-1.00') as $key=>$bad) {
    $bad_row = array_merge($row, array($key=>$bad));
    $assert($build(array($row, array_merge($bad_row, array('invoice_ref'=>'FC-202609-2')))) instanceof WP_Error, 'Lot entier refusé : ' . $key);
}
foreach (array('0.001', 'NaN', '1,23', '1000000000.00', '1e2') as $amount) $assert($build(array(array_merge($row, array('montant_decimal'=>$amount)))) instanceof WP_Error, 'Montant interdit ' . $amount);
foreach (array('2026-02-30', '2026-09-07', '2026-09-06', '2026-10-05"') as $date) $assert($build(null,null,$date) instanceof WP_Error, 'Date interdite');
foreach (array('2026-13', '2026-00', '../2026-09') as $month) $assert($build(null,null,'2026-10-05',$month) instanceof WP_Error, 'Mois interdit');
foreach (array('2026-09-08 00:00:00', '2026-02-30', '0000-00-00') as $date) $assert($build(array(array_merge($row, array('mandate_accepted_at'=>$date)))) instanceof WP_Error, 'Acceptation invalide');
foreach (array('bad//rum', '/bad', 'bad/', str_repeat('A',36), 'RUMé') as $rum) $assert($build(array(array_merge($row, array('mandate_ref'=>$rum)))) instanceof WP_Error, 'RUM invalide');
foreach (array('ics'=>'FR16ZZZ612780', 'iban'=>'', 'name'=>'', 'bic'=>'BAD') as $key=>$bad) $assert($build(null,array_merge($creditor,array($key=>$bad))) instanceof WP_Error, 'Créancier invalide ' . $key);
$assert($build(array($row,$row)) instanceof WP_Error, 'Facture dupliquée');
$assert($build(array()) instanceof WP_Error, 'Remise vide');
$zero = array_merge($row,array('montant_decimal'=>'0.00', 'invoice_ref'=>'FC-202609-0', 'iban'=>'', 'mandate_ref'=>''));
$assert(is_string($build(array($zero,$row))), 'Facture nulle exclue');
$assert($build(array($zero)) instanceof WP_Error, 'Remise uniquement nulle');
$rows = array(array_merge($row,array('montant_decimal'=>'0.10')),array_merge($row,array('invoice_ref'=>'FC-202609-2','montant_decimal'=>'0.20')));
$sum = $build($rows); $assert(strpos($sum, '<CtrlSum>0.30</CtrlSum>') !== false, 'Somme exacte de centimes');
$reversed = $build(array_reverse($rows));
preg_match('~<MsgId>([^<]+)</MsgId>~',$sum,$m1); preg_match('~<MsgId>([^<]+)</MsgId>~',$reversed,$m2);
$assert($m1[1] === $m2[1], 'Même remise : même identifiant indépendamment du tri');
$changed = $build($rows,null,'2026-10-06'); preg_match('~<MsgId>([^<]+)</MsgId>~',$changed,$m3);
$assert($m3[1] !== $m1[1], 'Nouvelle échéance : autre identifiant');
$no_bic = $build(array(array_merge($row,array('bic'=>''))),array_merge($creditor,array('bic'=>'')));
$assert(is_string($no_bic) && substr_count($no_bic,'<Id>NOTPROVIDED</Id>') === 2, 'BIC optionnels EEE');
$foreign = array_merge($row,array('iban'=>'GB33BUKB20201555555555','bic'=>'BUKBGB22','country'=>'FR'));
$assert(is_string($build(array($foreign))), 'Banque GB, titulaire en France');
$assert($build(array(array_merge($foreign,array('adresse'=>'')))) instanceof WP_Error, 'Adresse obligatoire hors EEE');
$assert($build(array(array_merge($foreign,array('bic'=>'')))) instanceof WP_Error, 'BIC obligatoire hors EEE');
$assert($build(array(array_merge($foreign,array('country'=>'')))) instanceof WP_Error, 'Pays obligatoire hors EEE');
$assert($build(array(array_merge($row,array('iban'=>'BR1800360305000010009795493C1')))) instanceof WP_Error, 'Compte hors SEPA');
$assert(Psc_Sepa_Export::valid_ics('FR15ABC612780') === 'FR15ABC612780', 'Code activité exclu du checksum ICS');
echo $checks . " contrôles pain.008 réussis (XML, XSD et règles SEPA).\n";
