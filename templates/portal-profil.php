<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow">Famille</div>
<h1 class="psc-portal-h1" data-testid="profil-title">Mon profil</h1>
<p class="psc-portal-intro">État civil, coordonnées et adresse du foyer. Ces informations ne concernent que vous — la fiche de chaque enfant se modifie depuis "Mes enfants".</p>

<?php if (!empty($parent->pending_email)): ?>
<p class="psc-notice psc-notice-ok" data-testid="profil-pending-email">
  Adresse en attente de confirmation : <strong><?php echo esc_html($parent->pending_email); ?></strong>.
  Vérifiez votre boîte mail pour l'activer.
</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:24px;">
  <?php wp_nonce_field('psc_cancel_email_change'); ?>
  <input type="hidden" name="action" value="psc_cancel_email_change">
  <button type="submit" class="psc-portal-btn-sm" data-testid="profil-cancel-email-change">Annuler ce changement</button>
</form>
<?php endif; ?>

<div class="psc-portal-panel">
  <div class="psc-portal-panel-title">État civil</div>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psc-portal-profile-form" data-testid="profil-form">
    <?php wp_nonce_field('psc_parent_update_profile'); ?>
    <input type="hidden" name="action" value="psc_parent_update_profile">

    <div class="psc-portal-field-grid">
      <div>
        <div class="psc-portal-field-label">Prénom</div>
        <input type="text" name="profil_prenom" value="<?php echo esc_attr($parent->prenom); ?>" maxlength="190" class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label">Nom</div>
        <input type="text" name="profil_nom" value="<?php echo esc_attr($parent->nom); ?>" maxlength="190" class="psc-portal-field-underline">
      </div>
    </div>

    <div class="psc-portal-panel-title" style="margin-top:28px;">Coordonnées</div>
    <div class="psc-portal-field-grid">
      <div>
        <div class="psc-portal-field-label">Téléphone mobile</div>
        <input type="tel" name="profil_tel_mobile" value="<?php echo esc_attr($parent->telephone_mobile); ?>" maxlength="40" class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label">Téléphone fixe</div>
        <input type="tel" name="profil_tel_fixe" value="<?php echo esc_attr($parent->telephone_fixe); ?>" maxlength="40" class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label">Adresse e-mail</div>
        <input type="email" name="profil_email" value="<?php echo esc_attr($parent->email); ?>" maxlength="191" required class="psc-portal-field-underline">
      </div>
    </div>

    <div class="psc-portal-panel-title" style="margin-top:28px;">Adresse du foyer</div>
    <div class="psc-portal-field-grid">
      <div>
        <div class="psc-portal-field-label">Adresse</div>
        <input type="text" name="profil_adresse" value="<?php echo esc_attr($parent->adresse); ?>" maxlength="255" class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label">Code postal</div>
        <input type="text" name="profil_code_postal" value="<?php echo esc_attr($parent->code_postal); ?>" maxlength="10" class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label">Ville</div>
        <input type="text" name="profil_ville" value="<?php echo esc_attr($parent->ville); ?>" maxlength="100" class="psc-portal-field-underline">
      </div>
    </div>

    <p style="margin-top:24px;"><button type="submit" class="psc-portal-btn-gold" data-testid="profil-submit">Enregistrer</button></p>
  </form>
</div>
