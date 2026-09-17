<?php
/** Probe WP-CLI non destructive du registre d'identités intervenants. */
if (!defined('ABSPATH')) exit;

$before = get_option('psc_sidscm_intervenants', null);
$rows = Psc_Sidscm::sanitize_intervenants(array(array(
    'nom' => 'Agent de test', 'code' => 'Secret-123', 'active' => 1,
)));
if (count($rows) !== 1 || !wp_check_password('Secret-123', $rows[0]['code_hash'])) {
    fwrite(STDERR, "FAIL: création/hash du registre\n"); exit(1);
}
if (wp_check_password('mauvais-code', $rows[0]['code_hash'])) {
    fwrite(STDERR, "FAIL: code incorrect accepté\n"); exit(1);
}
update_option('psc_sidscm_intervenants', $rows);
if (Psc_Sidscm::public_intervenants()[0]['nom'] !== 'Agent de test') {
    fwrite(STDERR, "FAIL: identité active non publiée\n"); exit(1);
}
$rows[0]['active'] = 0;
update_option('psc_sidscm_intervenants', $rows);
if (Psc_Sidscm::public_intervenants()) {
    fwrite(STDERR, "FAIL: révocation non appliquée\n"); exit(1);
}
if ($before === null) delete_option('psc_sidscm_intervenants'); else update_option('psc_sidscm_intervenants', $before);
echo "OK : registre intervenants individuel\n";
