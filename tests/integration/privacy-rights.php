<?php
/**
 * wp eval-file tests/integration/privacy-rights.php
 *
 * Postconditions de la suppression d'un foyer par la mairie, comparées à
 * celles de l'effaceur RGPD : les deux chemins doivent conserver les pièces
 * comptables et laisser le même reliquat sur la ligne parent.
 */
if (!defined('WP_CLI') || !WP_CLI) return;

global $wpdb;
$checks = 0;
$assert = function ($condition, $message) use (&$checks) {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$t_parent = psc_table('parents');
$t_child  = psc_table('children');
$t_inv    = psc_table('invoices');

$make_family = function ($slug) use ($wpdb, $t_parent, $t_child) {
    $wpdb->insert($t_parent, array(
        'email'            => $slug . '@example.invalid',
        'nom'              => 'Famille-' . $slug,
        'prenom'           => 'Camille',
        'telephone_mobile' => '0600000000',
        'sepa_iban'        => 'psc1:chiffre-factice',
        'active'           => 1,
        'created_at'       => current_time('mysql'),
    ));
    $parent_id = (int) $wpdb->insert_id;
    $wpdb->insert($t_child, array(
        'parent_id'  => $parent_id,
        'nom'        => 'Enfant-' . $slug,
        'prenom'     => 'Noé',
        'statut'     => 'actif',
        'created_at' => current_time('mysql'),
    ));
    return array($parent_id, (int) $wpdb->insert_id);
};

// Facture réelle sur disque : la sonde doit prouver que le PDF survit, pas
// seulement la ligne SQL.
$make_invoice = function ($parent_id, $mois) use ($wpdb, $t_inv) {
    $rel = 'periscolaire/test-facture-' . $parent_id . '-' . $mois . '.pdf';
    $abs = psc_private_path($rel);
    if (!is_dir(dirname($abs))) wp_mkdir_p(dirname($abs));
    file_put_contents($abs, '%PDF-1.4 sonde');
    $wpdb->insert($t_inv, array(
        'parent_id'  => $parent_id,
        'mois'       => $mois,
        'total'      => 42.50,
        'pdf_path'   => $rel,
        'created_at' => current_time('mysql'),
    ));
    return array((int) $wpdb->insert_id, $abs);
};

$created_files = array();

$wpdb->query('START TRANSACTION');
try {
    /* 1. Foyer avec facture : la facture et son PDF survivent, la ligne
          parent est anonymisée au lieu d'être supprimée. */
    list($billed_id, $billed_child) = $make_family('facture');
    list($invoice_id, $invoice_abs) = $make_invoice($billed_id, '2026-01');
    $created_files[] = $invoice_abs;

    /* 2. Foyer témoin : sa facture ne doit pas bouger. */
    list($other_id, ) = $make_family('temoin');
    list($other_invoice_id, $other_abs) = $make_invoice($other_id, '2026-02');
    $created_files[] = $other_abs;

    $result = Psc_Admin_Familles::delete_family($billed_id);
    $assert(is_array($result), 'La suppression n’a rien renvoyé pour une famille existante.');
    $assert($result['invoices_retained'] === 1, 'Le bilan ne signale pas la facture conservée.');
    $assert($result['parent_anonymized'] === true, 'Le bilan ne signale pas l’anonymisation.');

    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_inv WHERE id = %d", $invoice_id)) === 1,
        'La facture a été supprimée avec le foyer, malgré l’obligation comptable.');
    $assert(file_exists($invoice_abs),
        'Le PDF de la facture a été effacé du disque alors que la ligne est conservée.');

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_parent WHERE id = %d", $billed_id));
    $assert($row !== null, 'La ligne parent a disparu alors qu’une facture y reste rattachée.');
    $assert($row->email === 'famille-supprimee-' . $billed_id . '@invalide.local', 'L’e-mail n’a pas été neutralisé.');
    $assert($row->nom === null && $row->prenom === null, 'L’identité subsiste sur la ligne parent.');
    $assert($row->sepa_iban === null, 'L’IBAN subsiste après suppression.');
    $assert((int) $row->active === 0, 'La famille anonymisée reste active.');

    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_child WHERE id = %d", $billed_child)) === 0,
        'L’enfant n’a pas été purgé.');

    /* Le reliquat doit être exactement celui de l'effaceur RGPD : si un
       chemin ajoute un champ sensible, l'autre doit le nettoyer aussi. */
    foreach (Psc_Privacy::anonymized_parent_fields($billed_id) as $field => $expected) {
        $assert($row->$field == $expected, sprintf('Le champ %s diffère du reliquat de l’effaceur RGPD.', $field));
    }

    /* 3. Le foyer témoin est intact. */
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_inv WHERE id = %d", $other_invoice_id)) === 1,
        'La suppression a touché la facture d’une autre famille.');
    $assert(file_exists($other_abs), 'Le PDF d’une autre famille a été supprimé.');
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_parent WHERE id = %d", $other_id)) === 1,
        'La suppression a touché une autre famille.');

    /* 4. Foyer sans facture : rien à conserver, suppression complète. */
    list($plain_id, $plain_child) = $make_family('sans-facture');
    $plain = Psc_Admin_Familles::delete_family($plain_id);
    $assert($plain['invoices_retained'] === 0, 'Une facture est signalée là où il n’y en a aucune.');
    $assert($plain['parent_anonymized'] === false, 'Un foyer sans facture a été anonymisé au lieu d’être supprimé.');
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_parent WHERE id = %d", $plain_id)) === 0,
        'Un foyer sans facture subsiste après suppression.');
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_child WHERE id = %d", $plain_child)) === 0,
        'L’enfant d’un foyer sans facture subsiste.');

    /* 5. Famille inexistante : refus explicite, pas d'effet de bord. */
    $assert(Psc_Admin_Familles::delete_family(0) === null, 'Un identifiant vide n’est pas refusé.');
    $assert(Psc_Admin_Familles::delete_family(999999999) === null, 'Un identifiant inconnu n’est pas refusé.');

    /* 6. Idempotence : rejouer la suppression d'un foyer anonymisé ne
          détruit pas la facture qu'il conserve. */
    $again = Psc_Admin_Familles::delete_family($billed_id);
    $assert(is_array($again) && $again['invoices_retained'] === 1, 'La seconde suppression ne conserve plus la facture.');
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_inv WHERE id = %d", $invoice_id)) === 1,
        'La facture disparaît à la seconde suppression.');
    $assert(file_exists($invoice_abs), 'Le PDF disparaît à la seconde suppression.');

    WP_CLI::log(sprintf('OK : %d vérifications sur la conservation des pièces comptables.', $checks));
} finally {
    $wpdb->query('ROLLBACK');
    foreach ($created_files as $file) {
        if (file_exists($file)) unlink($file);
    }
}
