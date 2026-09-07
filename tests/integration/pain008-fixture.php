<?php
// Fixture isolée pour tests/pain008.spec.ts ; aucune notification envoyée.
if (!defined('WP_CLI') || !WP_CLI) return;
global $wpdb;
$mode = $args[0] ?? 'setup';
$key = 'psc_test_pain008_backup';
if ($mode === 'cleanup') {
    $backup = get_option($key);
    if (!$backup) return;
    if (!empty($backup['parent_id'])) {
        $wpdb->delete(psc_table('invoices'), array('parent_id'=>$backup['parent_id']));
        $wpdb->delete(psc_table('parents'), array('id'=>$backup['parent_id']));
    }
    foreach ($backup['options'] as $option=>$value) {
        if ($value === null) delete_option($option); else update_option($option, $value);
    }
    delete_option($key);
    WP_CLI::log('Fixture pain.008 supprimée, réglages restaurés.');
    return;
}
if (get_option($key)) throw new RuntimeException('Fixture existante : exécuter cleanup avant setup.');
$backup = array('options'=>array());
foreach (array('psc_billing_org_name','psc_billing_org_ics','psc_billing_org_iban','psc_billing_org_bic') as $option) $backup['options'][$option] = get_option($option, null);
add_option($key,$backup,'',false);
$parent = Psc_Parents::create('pain008-' . wp_generate_password(8,false,false) . '@example.invalid', 'TestExport', array(
    'payment_mode'=>'prelevement','sepa_iban'=>'FR7630006000011234567890189','sepa_bic'=>'AGRIFRPP',
    'sepa_titulaire'=>'Famille Test Export', 'sepa_mandate_ref'=>'PSC-PAIN008-TEST', 'sepa_reglement_accepted_at'=>'2026-01-01 12:00:00'
));
if (is_wp_error($parent)) throw new RuntimeException($parent->get_error_message());
$backup['parent_id'] = $parent;
update_option($key,$backup);
$wpdb->insert(psc_table('invoices'),array('parent_id'=>$parent,'mois'=>'2099-11','total'=>'12.34','created_at'=>current_time('mysql')));
if (!$wpdb->insert_id) throw new RuntimeException('Fixture facture impossible.');
update_option('psc_billing_org_name','Mairie Test Export');
update_option('psc_billing_org_ics','FR15ZZZ612780');
update_option('psc_billing_org_iban',psc_encrypt('FR7630006000011234567890189'));
update_option('psc_billing_org_bic','AGRIFRPP');
WP_CLI::log('Fixture pain.008 prête.');
