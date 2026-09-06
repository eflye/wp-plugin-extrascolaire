<?php
// Reproductions pures de l'audit : aucune connexion à WordPress ou à la base.
define('ABSPATH', '/audit/');
function __($s, $domain = null) { return $s; }
function get_option($name, $default = false) { return $default; }
function apply_filters($tag, $value) { return $value; }
require __DIR__ . '/../../../includes/helpers/services.php';
require __DIR__ . '/../../../includes/helpers/planning.php';
function resolve_audit($pats, $exc) {
    $out = array();
    foreach (psc_allowed_services() as $svc) {
        $slot = array('request'=>$svc, 'cant_pattern'=>!empty($pats['CANT']), 'msr_pattern'=>!empty($pats['MSR']), 'cant_exception'=>$exc['CANT'] ?? null, 'msr_exception'=>$exc['MSR'] ?? null);
        $out[$svc] = psc_resolve_declaration($svc === 'FORF', !empty($pats[$svc]), $exc[$svc] ?? null, !empty($pats['FORF']), $exc['FORF'] ?? null, true, true, true, $slot);
    }
    return $out;
}
function report_audit($name, $pats, $exc) {
    $resolved=resolve_audit($pats,$exc);
    $bill=psc_billing_services($resolved);
    $prices=psc_services(); $amount=0;
    foreach($bill as $svc) $amount += $prices[$svc]['price'];
    echo json_encode(array('scenario'=>$name,'resolved'=>$resolved,'billing'=>$bill,'amount'=>$amount), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), "\n";
}
report_audit('Forfait seul au rythme', array('FORF'=>true), array());
list($pats,$exc)=psc_cantine_sans_repas_convert(array('FORF'=>true),array());
report_audit('Forfait + indicateur mairie cantine sans repas', $pats,$exc);
report_audit('Retrait exceptionnel GM sur rythme forfait',array('FORF'=>true),array('GM'=>false));
report_audit('Ajout exceptionnel MSR sur rythme forfait',array('FORF'=>true),array('MSR'=>true));
echo json_encode(array('scenario'=>'Annuler GM couvert par exception FORF, sans rythme','decision'=>psc_exception_write_decision(false,false,false,false)),JSON_UNESCAPED_UNICODE),"\n";
// Reproduction du contrat current_time('timestamp') de WordPress : epoch + offset local.
define('HOUR_IN_SECONDS',3600);
function wp_timezone() { return new DateTimeZone('Europe/Paris'); }
function current_time($type) {
    $instant = new DateTimeImmutable('2026-09-05 23:00:00',wp_timezone());
    return $instant->getTimestamp() + wp_timezone()->getOffset($instant);
}
require __DIR__.'/../../../includes/helpers/lock.php';
$real_now=new DateTimeImmutable('2026-09-05 23:00:00',wp_timezone());
echo json_encode(array('scenario'=>'Verrou 48h, mardi 8 septembre, samedi 5 à 23h Paris','deadline_local'=>(new DateTimeImmutable('@'.psc_lock_deadline_ts('2026-09-08')))->setTimezone(wp_timezone())->format('c'),'expected_locked'=>$real_now->getTimestamp()>=psc_lock_deadline_ts('2026-09-08'),'actual_locked'=>psc_is_locked('2026-09-08')),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
