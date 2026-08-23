<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Familles</h1>

<?php
$psc_notices = array(
    'added'       => array('success', 'Famille enregistrée. Vous pouvez maintenant lui envoyer son lien d\'accès.'),
    'exists'      => array('error', 'Cette adresse e-mail est déjà enregistrée.'),
    'invalid'     => array('error', 'Adresse e-mail invalide ou famille introuvable.'),
    'link_sent'   => array('success', 'Lien d\'accès envoyé.'),
    'mail_failed' => array('error', "L'envoi a échoué. Vérifiez la configuration e-mail du site (une extension SMTP est souvent nécessaire)."),
    'deactivated' => array('success', 'Accès désactivé.'),
    'reactivated' => array('success', 'Accès réactivé.'),
    'updated'     => array('success', 'Informations de la famille mises à jour.'),
    'bad_iban'    => array('error', 'IBAN invalide.'),
    'bad_bic'     => array('error', 'BIC invalide.'),
    'family_deleted' => array('success', 'Famille supprimée définitivement, avec ses enfants, inscriptions, justificatifs et factures.'),
);
if (!empty($psc_msg) && isset($psc_notices[$psc_msg])):
    list($type, $text) = $psc_notices[$psc_msg]; ?>
    <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible"><p><?php echo esc_html($text); ?></p></div>
<?php endif; ?>

<div class="psc-box">
<p>
  Les familles ne sont <strong>pas</strong> des comptes WordPress : elles accèdent
  au formulaire via un lien envoyé par e-mail, sans mot de passe. Enregistrez ici
  les adresses communiquées par les familles, puis rattachez-leur les enfants
  dans l'onglet « Enfants ».
</p>
</div>

<?php if (!empty($edit_parent)): ?>
<div class="psc-box">
<h2>Modifier — <?php echo esc_html($edit_parent->nom ?: $edit_parent->email); ?></h2>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_edit_parent'); ?>
<input type="hidden" name="action" value="psc_edit_parent">
<input type="hidden" name="id" value="<?php echo esc_attr($edit_parent->id); ?>">
<table class="form-table">
<tr><th><label for="psc-edit-nom">Nom de la famille</label></th>
<td><input id="psc-edit-nom" type="text" name="nom" class="regular-text" maxlength="190"
    value="<?php echo esc_attr($edit_parent->nom ?? ''); ?>"></td></tr>
<tr><th><label for="psc-edit-adresse">Adresse</label></th>
<td><input id="psc-edit-adresse" type="text" name="adresse" class="large-text" maxlength="255"
    value="<?php echo esc_attr($edit_parent->adresse ?? ''); ?>"></td></tr>
<tr><th><label for="psc-edit-cp">Code postal</label></th>
<td><input id="psc-edit-cp" type="text" name="code_postal" class="small-text" maxlength="10"
    value="<?php echo esc_attr($edit_parent->code_postal ?? ''); ?>"></td></tr>
<tr><th><label for="psc-edit-ville">Ville</label></th>
<td><input id="psc-edit-ville" type="text" name="ville" class="regular-text" maxlength="100"
    value="<?php echo esc_attr($edit_parent->ville ?? ''); ?>"></td></tr>
<tr><th>Mode de paiement</th>
<td>
    <label><input type="radio" name="payment_mode" value="autre" <?php checked(($edit_parent->payment_mode ?? 'autre') !== 'prelevement'); ?>> Chèque ou espèces</label><br>
    <label><input type="radio" name="payment_mode" value="prelevement" <?php checked(($edit_parent->payment_mode ?? 'autre') === 'prelevement'); ?>> Prélèvement automatique (SEPA)</label>
</td></tr>
<tr><th><label for="psc-edit-sepa-titulaire">Titulaire du compte</label></th>
<td><input id="psc-edit-sepa-titulaire" type="text" name="sepa_titulaire" class="regular-text" maxlength="190"
    value="<?php echo esc_attr($edit_parent->sepa_titulaire ?? ''); ?>"></td></tr>
<tr><th><label for="psc-edit-sepa-adresse">Adresse du titulaire</label></th>
<td><input id="psc-edit-sepa-adresse" type="text" name="sepa_adresse" class="large-text" maxlength="255"
    value="<?php echo esc_attr($edit_parent->sepa_adresse ?? ''); ?>"></td></tr>
<tr><th><label for="psc-edit-sepa-cp">Code postal (titulaire)</label></th>
<td><input id="psc-edit-sepa-cp" type="text" name="sepa_code_postal" class="small-text" maxlength="10"
    value="<?php echo esc_attr($edit_parent->sepa_code_postal ?? ''); ?>"></td></tr>
<tr><th><label for="psc-edit-sepa-ville">Ville (titulaire)</label></th>
<td><input id="psc-edit-sepa-ville" type="text" name="sepa_ville" class="regular-text" maxlength="100"
    value="<?php echo esc_attr($edit_parent->sepa_ville ?? ''); ?>"></td></tr>
<tr><th><label for="psc-edit-sepa-iban">IBAN</label></th>
<td><input id="psc-edit-sepa-iban" type="text" name="sepa_iban" class="large-text" maxlength="42"
    value="<?php echo esc_attr(psc_read_iban($edit_parent)); ?>" placeholder="FR76 XXXX XXXX XXXX XXXX XXXX XXX"></td></tr>
<tr><th><label for="psc-edit-sepa-bic">BIC</label></th>
<td><input id="psc-edit-sepa-bic" type="text" name="sepa_bic" class="regular-text" maxlength="11"
    value="<?php echo esc_attr($edit_parent->sepa_bic ?? ''); ?>" placeholder="XXXXFRPPXXX"></td></tr>
<?php if (!empty($edit_parent->sepa_mandate_ref)): ?>
<tr><th>Référence du mandat SEPA</th><td><code><?php echo esc_html($edit_parent->sepa_mandate_ref); ?></code></td></tr>
<?php endif; ?>
</table>
<?php submit_button('Enregistrer les modifications'); ?>
</form>
</div>
<?php endif; ?>

<div class="psc-box">
<h2>Enregistrer une famille</h2>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_add_parent'); ?>
<input type="hidden" name="action" value="psc_add_parent">
<table class="form-table">
<tr><th><label for="psc-p-mail">Adresse e-mail</label></th><td><input id="psc-p-mail" type="email" name="email" class="regular-text" required></td></tr>
<tr><th><label for="psc-p-nom">Nom de la famille</label></th><td><input id="psc-p-nom" type="text" name="nom" class="regular-text" maxlength="190" placeholder="Facultatif"></td></tr>
</table>
<?php submit_button('Enregistrer la famille'); ?>
</form>
</div>

<div class="psc-box">
<h2>Familles enregistrées</h2>
<table class="widefat striped">
<thead><tr><th>Nom</th><th>E-mail</th><th>Adresse</th><th>Paiement</th><th>Dernière connexion</th><th>Statut</th><th>Actions</th></tr></thead>
<tbody>
<?php if (empty($parents)): ?>
<tr><td colspan="6">Aucune famille enregistrée.</td></tr>
<?php else: foreach ($parents as $p): ?>
<tr>
<td><?php echo $p->nom ? esc_html($p->nom) : '—'; ?></td>
<td><?php echo esc_html($p->email); ?></td>
<td><?php echo esc_html(trim(($p->adresse ?? '') . ($p->code_postal ? ' — ' . $p->code_postal : '') . ($p->ville ? ' ' . $p->ville : ''))); ?></td>
<td>
  <?php if (($p->payment_mode ?? 'autre') === 'prelevement'): ?>
    Prélèvement<br><small><?php echo esc_html($p->sepa_iban ? psc_mask_iban(psc_read_iban($p)) : '—'); ?></small>
  <?php else: ?>
    Chèque / espèces
  <?php endif; ?>
</td>
<td><?php echo $p->last_login ? esc_html(date_i18n('d/m/Y H:i', strtotime($p->last_login))) : '<em>jamais</em>'; ?></td>
<td><?php echo $p->active ? '<span class="psc-active">Actif</span>' : '<em>désactivé</em>'; ?></td>
<td>
  <a href="<?php echo esc_url(add_query_arg(array('page' => 'psc_parents', 'edit' => $p->id), admin_url('admin.php'))); ?>"
     class="button">Éditer</a>
  <?php if ($p->active): ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
    <?php wp_nonce_field('psc_send_link'); ?>
    <input type="hidden" name="action" value="psc_send_link">
    <input type="hidden" name="id" value="<?php echo esc_attr($p->id); ?>">
    <button class="button button-primary">Envoyer le lien d'accès</button>
  </form>
  <?php endif; ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"
        onsubmit="return confirm('<?php echo $p->active ? 'Désactiver l\'accès de cette famille ?' : 'Réactiver cette famille ?'; ?>');">
    <?php wp_nonce_field('psc_toggle_parent'); ?>
    <input type="hidden" name="action" value="psc_toggle_parent">
    <input type="hidden" name="id" value="<?php echo esc_attr($p->id); ?>">
    <button class="button"><?php echo $p->active ? 'Désactiver' : 'Réactiver'; ?></button>
  </form>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"
        onsubmit="return confirm('Supprimer définitivement cette famille : ses enfants, leurs inscriptions, justificatifs d\'assurance, personnes autorisées et factures ? Cette action est irréversible.');">
    <?php wp_nonce_field('psc_delete_family'); ?>
    <input type="hidden" name="action" value="psc_delete_family">
    <input type="hidden" name="id" value="<?php echo esc_attr($p->id); ?>">
    <button class="button button-link-delete">Supprimer</button>
  </form>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
</div>
