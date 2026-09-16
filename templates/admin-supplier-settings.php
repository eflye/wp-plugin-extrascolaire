<?php if (!defined('ABSPATH')) exit; ?>
<?php
/* Rendu comme onglet « Réglages » de la page « Commande fournisseur »
   (cf. Psc_Admin_Cantine::page_supplier_orders()) : ni wrap ni <h1> ici,
   fournis par la page hôte. Champ déplacé tel quel depuis Réglages
   (réorganisation du menu, §6) — même option (psc_supplier_email), même
   validation, seule l'action d'enregistrement change
   (cf. Psc_Admin_Cantine::handle_save_supplier_settings()). */
$psc_notices = array(
    'settings_saved' => array('success', __('Réglages enregistrés.', 'periscolaire-registration')),
);
psc_admin_notice_map($psc_notices, $psc_msg);
?>

<div class="psc-box">
<h2><?php esc_html_e('Fournisseur de repas', 'periscolaire-registration'); ?></h2>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_save_supplier_settings'); ?>
<input type="hidden" name="action" value="psc_save_supplier_settings">
<table class="form-table">
<tr>
<th><label for="psc-supplier-mail"><?php esc_html_e('Adresse du fournisseur', 'periscolaire-registration'); ?></label></th>
<td>
  <input id="psc-supplier-mail" type="email" name="supplier_email" class="regular-text"
         value="<?php echo esc_attr(get_option('psc_supplier_email', '')); ?>"
         placeholder="cuisine@prestataire.example">
  <p class="description"><?php esc_html_e("Destinataire de la commande hebdomadaire (onglet Commande ci-dessus).", 'periscolaire-registration'); ?></p>
</td>
</tr>
</table>
<?php submit_button(__('Enregistrer', 'periscolaire-registration'), 'primary', 'submit', false); ?>
</form>
</div>
