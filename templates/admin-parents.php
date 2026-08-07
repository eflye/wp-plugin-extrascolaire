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
<thead><tr><th>Nom</th><th>E-mail</th><th>Dernière connexion</th><th>Statut</th><th>Actions</th></tr></thead>
<tbody>
<?php if (empty($parents)): ?>
<tr><td colspan="5">Aucune famille enregistrée.</td></tr>
<?php else: foreach ($parents as $p): ?>
<tr>
<td><?php echo $p->nom ? esc_html($p->nom) : '—'; ?></td>
<td><?php echo esc_html($p->email); ?></td>
<td><?php echo $p->last_login ? esc_html(date_i18n('d/m/Y H:i', strtotime($p->last_login))) : '<em>jamais</em>'; ?></td>
<td><?php echo $p->active ? '<span class="psc-active">Actif</span>' : '<em>désactivé</em>'; ?></td>
<td>
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
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
</div>
