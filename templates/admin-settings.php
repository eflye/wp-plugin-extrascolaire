<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Réglages</h1>
<?php if (!empty($psc_msg) && $psc_msg === 'saved'): ?>
<div class="notice notice-success is-dismissible"><p>Tarifs enregistrés.</p></div>
<?php endif; ?>

<div class="psc-box">
<h2>Tarifs des prestations</h2>
<p>Ces tarifs sont affichés aux familles dans le formulaire. Ils ne déclenchent aucun paiement en ligne (le service reste géré par la mairie).</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_save_settings'); ?>
<input type="hidden" name="action" value="psc_save_settings">
<table class="form-table">
<?php foreach ($services as $code => $s): ?>
<tr>
<th><label for="psc-price-<?php echo esc_attr($code); ?>"><?php echo esc_html($s['label']); ?> (<?php echo esc_html($code); ?>)</label></th>
<td><input id="psc-price-<?php echo esc_attr($code); ?>" type="text" inputmode="decimal" name="price_<?php echo esc_attr($code); ?>" value="<?php echo esc_attr(number_format($s['price'], 2, ',', '')); ?>"> €</td>
</tr>
<?php endforeach; ?>
</table>
</table>

<h2>Délai de modification</h2>
<p>Au-delà de ce délai avant le jour concerné, les familles ne peuvent plus modifier leur planning en ligne. La mairie, elle, reste toujours en mesure de corriger depuis ce backoffice.</p>
<table class="form-table">
<tr>
<th><label for="psc-lock">Préavis minimum</label></th>
<td>
  <input id="psc-lock" type="number" name="lock_hours" min="0" max="720" step="1"
         value="<?php echo esc_attr(psc_lock_hours()); ?>" class="small-text"> heures
  <p class="description">48 heures par défaut. Mettre 0 pour désactiver totalement le verrouillage.</p>
</td>
</tr>
</table>

<h2>Notifications</h2>
<table class="form-table">
<tr>
<th>Copie à la mairie</th>
<td>
  <label>
    <input type="checkbox" name="notify_mairie" value="1" <?php checked(psc_notify_mairie_enabled()); ?>>
    Recevoir une copie de chaque planning validé par une famille
  </label>
</td>
</tr>
<tr>
<th><label for="psc-mairie-mail">Adresse de la mairie</label></th>
<td>
  <input id="psc-mairie-mail" type="email" name="mairie_email" class="regular-text"
         value="<?php echo esc_attr(get_option('psc_mairie_email', '')); ?>"
         placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
  <p class="description">Laisser vide pour utiliser l'adresse d'administration du site.</p>
</td>
</tr>
</table>
<?php submit_button('Enregistrer'); ?>
</form>
</div>

<div class="psc-box">
<h2>Envoi des e-mails</h2>
<p>
  Ce plugin utilise la fonction d'envoi standard de WordPress. Sur beaucoup
  d'hébergements, les messages partent en indésirables ou ne partent pas du tout
  sans configuration SMTP. Comme les familles reçoivent leur lien de connexion
  par e-mail, cette configuration est <strong>indispensable au bon fonctionnement</strong> :
  faites installer une extension SMTP par l'administrateur du site et testez un envoi réel.
</p>
</div>
</div>
