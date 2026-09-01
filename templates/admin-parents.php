<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1><?php esc_html_e('Familles', 'periscolaire-registration'); ?></h1>

<?php
$psc_notices = array(
    'added'       => array('success', __('Famille enregistrée. Vous pouvez maintenant lui envoyer son lien d\'accès.', 'periscolaire-registration')),
    'exists'      => array('error', __('Cette adresse e-mail est déjà enregistrée.', 'periscolaire-registration')),
    'invalid'     => array('error', __('Adresse e-mail invalide ou famille introuvable.', 'periscolaire-registration')),
    'link_sent'   => array('success', __("Lien d'accès envoyé.", 'periscolaire-registration')),
    'mail_failed' => array('error', __("L'envoi a échoué. Vérifiez la configuration e-mail du site (une extension SMTP est souvent nécessaire).", 'periscolaire-registration')),
    'deactivated' => array('success', __('Accès désactivé.', 'periscolaire-registration')),
    'reactivated' => array('success', __('Accès réactivé.', 'periscolaire-registration')),
    'updated'     => array('success', __('Informations de la famille mises à jour.', 'periscolaire-registration')),
    'bad_iban'    => array('error', __('IBAN invalide.', 'periscolaire-registration')),
    'bad_bic'     => array('error', __('BIC invalide.', 'periscolaire-registration')),
    'bad_code_postal' => array('error', __('Code postal invalide.', 'periscolaire-registration')),
    'family_deleted' => array('success', __('Famille supprimée définitivement, avec ses enfants, inscriptions, justificatifs et factures.', 'periscolaire-registration')),
);
psc_admin_notice_map($psc_notices, $psc_msg); ?>

<div class="psc-box">
<p>
  <?php esc_html_e('Les familles ne sont', 'periscolaire-registration'); ?>
  <strong><?php esc_html_e('pas', 'periscolaire-registration'); ?></strong>
  <?php esc_html_e("des comptes WordPress : elles accèdent au formulaire via un lien envoyé par e-mail, sans mot de passe. Enregistrez ici les adresses communiquées par les familles, puis rattachez-leur les enfants dans l'onglet « Enfants ».", 'periscolaire-registration'); ?>
</p>
</div>

<?php if (!empty($edit_parent)): ?>
<div class="psc-box">
<h2><?php esc_html_e('Modifier —', 'periscolaire-registration'); ?> <?php echo esc_html($edit_parent->nom ?: $edit_parent->email); ?></h2>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_edit_parent'); ?>
<input type="hidden" name="action" value="psc_edit_parent">
<input type="hidden" name="id" value="<?php echo esc_attr($edit_parent->id); ?>">
<table class="form-table">
<tr><th><label for="psc-edit-nom"><?php esc_html_e('Nom de la famille', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-edit-nom" type="text" name="nom" class="regular-text" maxlength="190"
    value="<?php echo esc_attr($edit_parent->nom ?? ''); ?>"></td></tr>
<tr><th><label for="psc-edit-adresse"><?php esc_html_e('Adresse', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-edit-adresse" type="text" name="adresse" class="large-text" maxlength="255"
    value="<?php echo esc_attr($edit_parent->adresse ?? ''); ?>"></td></tr>
<tr><th><label for="psc-edit-cp"><?php esc_html_e('Code postal', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-edit-cp" type="text" name="code_postal" class="small-text" maxlength="10"
    pattern="[0-9]{5}" title="<?php esc_attr_e('Format attendu : 5 chiffres.', 'periscolaire-registration'); ?>"
    value="<?php echo esc_attr($edit_parent->code_postal ?? ''); ?>"></td></tr>
<tr><th><label for="psc-edit-ville"><?php esc_html_e('Ville', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-edit-ville" type="text" name="ville" class="regular-text" maxlength="100"
    value="<?php echo esc_attr($edit_parent->ville ?? ''); ?>"></td></tr>
<tr><th><?php esc_html_e('Mode de paiement', 'periscolaire-registration'); ?></th>
<td>
    <label><input type="radio" name="payment_mode" value="autre" <?php checked(($edit_parent->payment_mode ?? 'autre') !== 'prelevement'); ?>> <?php esc_html_e('Chèque ou espèces', 'periscolaire-registration'); ?></label><br>
    <label><input type="radio" name="payment_mode" value="prelevement" <?php checked(($edit_parent->payment_mode ?? 'autre') === 'prelevement'); ?>> <?php esc_html_e('Prélèvement automatique (SEPA)', 'periscolaire-registration'); ?></label>
</td></tr>
<tr><th><label for="psc-edit-sepa-titulaire"><?php esc_html_e('Titulaire du compte', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-edit-sepa-titulaire" type="text" name="sepa_titulaire" class="regular-text" maxlength="190"
    value="<?php echo esc_attr($edit_parent->sepa_titulaire ?? ''); ?>"></td></tr>
<tr><th><label for="psc-edit-sepa-adresse"><?php esc_html_e('Adresse du titulaire', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-edit-sepa-adresse" type="text" name="sepa_adresse" class="large-text" maxlength="255"
    value="<?php echo esc_attr($edit_parent->sepa_adresse ?? ''); ?>"></td></tr>
<tr><th><label for="psc-edit-sepa-cp"><?php esc_html_e('Code postal (titulaire)', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-edit-sepa-cp" type="text" name="sepa_code_postal" class="small-text" maxlength="10"
    pattern="[0-9]{5}" title="<?php esc_attr_e('Format attendu : 5 chiffres.', 'periscolaire-registration'); ?>"
    value="<?php echo esc_attr($edit_parent->sepa_code_postal ?? ''); ?>"></td></tr>
<tr><th><label for="psc-edit-sepa-ville"><?php esc_html_e('Ville (titulaire)', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-edit-sepa-ville" type="text" name="sepa_ville" class="regular-text" maxlength="100"
    value="<?php echo esc_attr($edit_parent->sepa_ville ?? ''); ?>"></td></tr>
<tr><th><label for="psc-edit-sepa-iban"><?php esc_html_e('IBAN', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-edit-sepa-iban" type="text" name="sepa_iban" class="large-text" maxlength="42"
    value="<?php echo esc_attr(psc_read_iban($edit_parent)); ?>" placeholder="FR76 XXXX XXXX XXXX XXXX XXXX XXX"></td></tr>
<tr><th><label for="psc-edit-sepa-bic"><?php esc_html_e('BIC', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-edit-sepa-bic" type="text" name="sepa_bic" class="regular-text" maxlength="11"
    value="<?php echo esc_attr($edit_parent->sepa_bic ?? ''); ?>" placeholder="XXXXFRPPXXX"></td></tr>
<?php if (!empty($edit_parent->sepa_mandate_ref)): ?>
<tr><th><?php esc_html_e('Référence du mandat SEPA', 'periscolaire-registration'); ?></th><td><code><?php echo esc_html($edit_parent->sepa_mandate_ref); ?></code></td></tr>
<?php endif; ?>
</table>
<?php submit_button(__('Enregistrer les modifications', 'periscolaire-registration')); ?>
</form>
</div>
<?php endif; ?>

<div class="psc-box">
<h2><?php esc_html_e('Enregistrer une famille', 'periscolaire-registration'); ?></h2>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_add_parent'); ?>
<input type="hidden" name="action" value="psc_add_parent">
<table class="form-table">
<tr><th><label for="psc-p-mail"><?php esc_html_e('Adresse e-mail', 'periscolaire-registration'); ?></label></th><td><input id="psc-p-mail" type="email" name="email" class="regular-text" required></td></tr>
<tr><th><label for="psc-p-nom"><?php esc_html_e('Nom de la famille', 'periscolaire-registration'); ?></label></th><td><input id="psc-p-nom" type="text" name="nom" class="regular-text" maxlength="190" placeholder="<?php esc_attr_e('Facultatif', 'periscolaire-registration'); ?>"></td></tr>
</table>
<?php submit_button(__('Enregistrer la famille', 'periscolaire-registration')); ?>
</form>
</div>

<div class="psc-box">
<h2><?php esc_html_e('Familles enregistrées', 'periscolaire-registration'); ?></h2>
<table class="widefat striped">
<thead><tr><th><?php esc_html_e('Nom', 'periscolaire-registration'); ?></th><th><?php esc_html_e('E-mail', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Adresse', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Paiement', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Dernière connexion', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Statut', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Actions', 'periscolaire-registration'); ?></th></tr></thead>
<tbody>
<?php if (empty($parents)): ?>
<tr><td colspan="6"><?php esc_html_e('Aucune famille enregistrée.', 'periscolaire-registration'); ?></td></tr>
<?php else: foreach ($parents as $p): ?>
<tr>
<td><?php echo $p->nom ? esc_html($p->nom) : '—'; ?></td>
<td><?php echo esc_html($p->email); ?></td>
<td><?php echo esc_html(trim(($p->adresse ?? '') . ($p->code_postal ? ' — ' . $p->code_postal : '') . ($p->ville ? ' ' . $p->ville : ''))); ?></td>
<td>
  <?php if (($p->payment_mode ?? 'autre') === 'prelevement'): ?>
    <?php esc_html_e('Prélèvement', 'periscolaire-registration'); ?><br><small><?php echo esc_html($p->sepa_iban ? psc_mask_iban(psc_read_iban($p)) : '—'); ?></small>
  <?php else: ?>
    <?php esc_html_e('Chèque / espèces', 'periscolaire-registration'); ?>
  <?php endif; ?>
</td>
<td><?php echo $p->last_login ? esc_html(date_i18n('d/m/Y H:i', strtotime($p->last_login))) : '<em>' . esc_html__('jamais', 'periscolaire-registration') . '</em>'; ?></td>
<td><?php echo $p->active ? '<span class="psc-active">' . esc_html__('Actif', 'periscolaire-registration') . '</span>' : '<em>' . esc_html__('désactivé', 'periscolaire-registration') . '</em>'; ?></td>
<td>
  <a href="<?php echo esc_url(add_query_arg(array('page' => 'psc_parents', 'edit' => $p->id), admin_url('admin.php'))); ?>"
     class="button"><?php esc_html_e('Éditer', 'periscolaire-registration'); ?></a>
  <?php if ($p->active): ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
    <?php wp_nonce_field('psc_send_link'); ?>
    <input type="hidden" name="action" value="psc_send_link">
    <input type="hidden" name="id" value="<?php echo esc_attr($p->id); ?>">
    <button class="button button-primary"><?php esc_html_e("Envoyer le lien d'accès", 'periscolaire-registration'); ?></button>
  </form>
  <?php endif; ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"
        onsubmit="return confirm('<?php echo $p->active ? esc_js(__("Désactiver l'accès de cette famille ?", 'periscolaire-registration')) : esc_js(__('Réactiver cette famille ?', 'periscolaire-registration')); ?>');">
    <?php wp_nonce_field('psc_toggle_parent'); ?>
    <input type="hidden" name="action" value="psc_toggle_parent">
    <input type="hidden" name="id" value="<?php echo esc_attr($p->id); ?>">
    <button class="button"><?php echo $p->active ? esc_html__('Désactiver', 'periscolaire-registration') : esc_html__('Réactiver', 'periscolaire-registration'); ?></button>
  </form>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"
        onsubmit="return confirm('<?php echo esc_js(__("Supprimer définitivement cette famille : ses enfants, leurs inscriptions, justificatifs d'assurance, personnes autorisées et factures ? Cette action est irréversible.", 'periscolaire-registration')); ?>');">
    <?php wp_nonce_field('psc_delete_family'); ?>
    <input type="hidden" name="action" value="psc_delete_family">
    <input type="hidden" name="id" value="<?php echo esc_attr($p->id); ?>">
    <button class="button button-link-delete"><?php esc_html_e('Supprimer', 'periscolaire-registration'); ?></button>
  </form>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
</div>
