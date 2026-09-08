<?php
// Exécution WP-CLI : aucun fichier ni demande créés.
if (!defined('WP_CLI') || !WP_CLI) return;
$cases = array(UPLOAD_ERR_NO_FILE=>'required', UPLOAD_ERR_INI_SIZE=>'too_large', UPLOAD_ERR_FORM_SIZE=>'too_large', UPLOAD_ERR_PARTIAL=>'partial', UPLOAD_ERR_NO_TMP_DIR=>'failed', UPLOAD_ERR_CANT_WRITE=>'failed', UPLOAD_ERR_EXTENSION=>'failed', UPLOAD_ERR_OK=>true);
foreach ($cases as $error=>$expected) {
    $actual = Psc_Assurances::validate_upload(array('error'=>$error, 'size'=>18000, 'name'=>'assurance.pdf'));
    if ($actual !== $expected) throw new RuntimeException('Classification incorrecte pour erreur ' . $error);
}
if (Psc_Assurances::validate_upload(null) !== 'required') throw new RuntimeException('Fichier absent');
if (Psc_Assurances::validate_upload(array('error'=>0,'size'=>0,'name'=>'assurance.pdf')) !== 'partial') throw new RuntimeException('Fichier vide');
echo "OK : 10 cas de réception des justificatifs.\n";
