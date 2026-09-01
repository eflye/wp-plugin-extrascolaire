<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-guest-card" data-testid="login-card">
  <div class="psc-guest-eyebrow"><?php esc_html_e('Famille déjà connue', 'periscolaire-registration'); ?></div>
  <h2 class="psc-guest-h1"><?php esc_html_e("Se connecter à l'espace famille", 'periscolaire-registration'); ?></h2>
  <p class="psc-guest-intro"><?php esc_html_e("Saisissez l'adresse e-mail communiquée à la mairie : vous recevrez un lien d'accès, sans mot de passe à retenir.", 'periscolaire-registration'); ?></p>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psc-login-form" data-testid="login-form">
    <?php wp_nonce_field('psc_request_link'); ?>
    <input type="hidden" name="action" value="psc_request_link">
    <div class="psc-guest-login-row">
      <div style="flex:1">
        <label class="psc-portal-field-label" for="psc-email"><?php esc_html_e('Adresse e-mail', 'periscolaire-registration'); ?></label>
        <input id="psc-email" class="psc-portal-field-underline" type="email" name="psc_email" placeholder="vous@exemple.fr" autocomplete="email" required data-testid="login-email-input">
      </div>
      <button type="submit" class="psc-portal-btn-gold" style="white-space:nowrap;" data-testid="login-submit-button"><?php esc_html_e('Recevoir mon lien', 'periscolaire-registration'); ?></button>
    </div>
  </form>
  <p class="psc-guest-hint"><?php esc_html_e('Rien reçu après quelques minutes ? Vérifiez vos courriers indésirables, ou contactez la mairie.', 'periscolaire-registration'); ?></p>
</div>
