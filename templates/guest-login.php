<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-guest-card" data-testid="login-card">
  <div class="psc-guest-eyebrow">Famille déjà connue</div>
  <h2 class="psc-guest-h1">Se connecter à l'espace famille</h2>
  <p class="psc-guest-intro">Saisissez l'adresse e-mail communiquée à la mairie : vous recevrez un lien d'accès, sans mot de passe à retenir.</p>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psc-login-form" data-testid="login-form">
    <?php wp_nonce_field('psc_request_link'); ?>
    <input type="hidden" name="action" value="psc_request_link">
    <div class="psc-guest-login-row">
      <div style="flex:1">
        <label class="psc-portal-field-label" for="psc-email">Adresse e-mail</label>
        <input id="psc-email" class="psc-portal-field-underline" type="email" name="psc_email" placeholder="vous@exemple.fr" autocomplete="email" required data-testid="login-email-input">
      </div>
      <button type="submit" class="psc-portal-btn-gold" style="white-space:nowrap;" data-testid="login-submit-button">Recevoir mon lien</button>
    </div>
  </form>
  <p class="psc-guest-hint">Rien reçu après quelques minutes ? Vérifiez vos courriers indésirables, ou contactez la mairie.</p>
</div>
