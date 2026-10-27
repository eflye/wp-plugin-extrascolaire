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
<?php if (!current_user_can('psc_manage_config')): ?>
<?php /* L'enregistrement exige psc_manage_config (psc_save_supplier_settings) :
         un agent « présences » voit l'adresse sans formulaire voué au refus. */ ?>
<table class="form-table">
<tr>
<th scope="row"><?php esc_html_e('Adresse du fournisseur', 'periscolaire-registration'); ?></th>
<td>
  <?php $psc_supplier_email = (string) get_option('psc_supplier_email', ''); ?>
  <p data-testid="supplier-email-readonly"><?php echo $psc_supplier_email !== '' ? esc_html($psc_supplier_email) : esc_html__('Non renseignée.', 'periscolaire-registration'); ?></p>
  <p class="description"><?php esc_html_e('Seule une personne habilitée aux réglages peut modifier cette adresse.', 'periscolaire-registration'); ?></p>
</td>
</tr>
</table>
<?php else: ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_save_supplier_settings'); ?>
<input type="hidden" name="action" value="psc_save_supplier_settings">
<table class="form-table">
<tr>
<th scope="row"><label for="psc-supplier-mail"><?php esc_html_e('Adresse du fournisseur', 'periscolaire-registration'); ?></label></th>
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
<?php endif; ?>
</div>
