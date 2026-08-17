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

<div class="psc-portal-panel">
  <div class="psc-portal-panel-title">Second parent (facultatif)</div>
  <?php $psc_has_second_parent = trim((string) $parent->second_parent_prenom) !== '' || trim((string) $parent->second_parent_nom) !== ''; ?>
  <button type="button" id="psc-add-second-parent" class="psc-wizard-add-pickup-btn" data-testid="profil-add-second-parent"<?php echo $psc_has_second_parent ? ' hidden' : ''; ?>>+ Ajouter un second parent</button>

  <div id="psc-second-parent-block" data-testid="profil-second-parent-block"<?php echo $psc_has_second_parent ? '' : ' hidden'; ?>>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-testid="profil-second-parent-form">
      <?php wp_nonce_field('psc_parent_update_second_parent'); ?>
      <input type="hidden" name="action" value="psc_parent_update_second_parent">
      <div class="psc-portal-field-grid">
        <div>
          <div class="psc-portal-field-label">Prénom</div>
          <input type="text" name="second_parent_prenom" value="<?php echo esc_attr($parent->second_parent_prenom); ?>" maxlength="190" class="psc-portal-field-underline">
        </div>
        <div>
          <div class="psc-portal-field-label">Nom</div>
          <input type="text" name="second_parent_nom" value="<?php echo esc_attr($parent->second_parent_nom); ?>" maxlength="190" class="psc-portal-field-underline">
        </div>
        <div>
          <div class="psc-portal-field-label">E-mail</div>
          <input type="email" name="second_parent_email" value="<?php echo esc_attr($parent->second_parent_email); ?>" class="psc-portal-field-underline">
        </div>
        <div>
          <div class="psc-portal-field-label">Téléphone</div>
          <input type="tel" name="second_parent_telephone" value="<?php echo esc_attr($parent->second_parent_telephone); ?>" maxlength="40" class="psc-portal-field-underline">
        </div>
      </div>
      <p style="margin-top:16px;"><button type="submit" class="psc-portal-btn-gold" data-testid="profil-second-parent-submit">Enregistrer</button></p>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-testid="profil-second-parent-remove-form">
      <?php wp_nonce_field('psc_parent_remove_second_parent'); ?>
      <input type="hidden" name="action" value="psc_parent_remove_second_parent">
      <button type="submit" class="psc-wizard-remove-pickup-btn" data-testid="profil-remove-second-parent" onclick="return confirm('Retirer le second parent ?');">Retirer</button>
    </form>
  </div>
</div>

<div class="psc-portal-panel psc-portal-panel--wide">
  <div class="psc-portal-panel-title">Personnes autorisées à récupérer les enfants</div>
  <p class="psc-portal-intro">Les deux parents figurent toujours dans cette liste. Ajoutez ici les autres personnes pouvant venir chercher vos enfants en fin de garderie — l'ajout s'applique à tous vos enfants actifs.</p>

  <?php if (!empty($psc_household_authorized)): ?>
  <div class="psc-portal-table-scroll">
  <table class="psc-portal-table" data-testid="profil-household-authorized-table">
    <thead><tr><th>Prénom</th><th>Nom</th><th>Rôle</th><th>Téléphone</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($psc_household_authorized as $psc_hh_i => $psc_hh): ?>
      <tr data-testid="profil-authorized-row-<?php echo esc_attr($psc_hh_i); ?>">
        <td style="font-weight:500;"><?php echo esc_html($psc_hh['prenom']); ?></td>
        <td><?php echo esc_html($psc_hh['nom']); ?></td>
        <td><span class="psc-portal-pill" data-testid="profil-authorized-role-<?php echo esc_attr($psc_hh_i); ?>"><?php echo esc_html($psc_hh['role']); ?></span></td>
        <td><?php echo $psc_hh['telephone'] !== '' ? esc_html($psc_hh['telephone']) : '—'; ?></td>
        <td class="psc-portal-row-save">
          <?php if ($psc_hh['removable']): ?>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
            <?php wp_nonce_field('psc_parent_remove_household_pickup_person'); ?>
            <input type="hidden" name="action" value="psc_parent_remove_household_pickup_person">
            <input type="hidden" name="pickup_ids" value="<?php echo esc_attr(implode(',', $psc_hh['ids'])); ?>">
            <button type="submit" class="psc-portal-btn-sm" data-testid="profil-remove-authorized-<?php echo esc_attr($psc_hh_i); ?>" onclick="return confirm('Retirer <?php echo esc_js($psc_hh['prenom'] . ' ' . $psc_hh['nom']); ?> de la liste des personnes autorisées ?');">Retirer</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>

  <div class="psc-wizard-pickup-block" style="border-top:none;padding-top:16px;">
    <button type="button" id="psc-add-household-pickup" class="psc-wizard-add-pickup-btn" data-testid="profil-add-household-pickup">+ Ajouter une personne autorisée</button>
    <div id="psc-household-pickup-form-block" hidden data-testid="profil-household-pickup-form-block">
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psc-wizard-pickup-row" data-testid="profil-household-pickup-form">
        <?php wp_nonce_field('psc_parent_add_household_pickup_person'); ?>
        <input type="hidden" name="action" value="psc_parent_add_household_pickup_person">
        <div>
          <label class="psc-portal-field-label screen-reader-text" for="psc-hh-prenom">Prénom</label>
          <input id="psc-hh-prenom" class="psc-portal-field-underline" type="text" name="prenom" placeholder="Prénom" maxlength="191" required>
        </div>
        <div>
          <label class="psc-portal-field-label screen-reader-text" for="psc-hh-nom">Nom</label>
          <input id="psc-hh-nom" class="psc-portal-field-underline" type="text" name="nom" placeholder="Nom" maxlength="191" required>
        </div>
        <div>
          <label class="psc-portal-field-label screen-reader-text" for="psc-hh-lien">Lien de parenté</label>
          <input id="psc-hh-lien" class="psc-portal-field-underline" type="text" name="lien" placeholder="Lien de parenté" maxlength="100" list="psc-pickup-lien-suggestions-household">
          <datalist id="psc-pickup-lien-suggestions-household">
            <?php foreach (psc_pickup_lien_suggestions() as $psc_lien): ?>
            <option value="<?php echo esc_attr($psc_lien); ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
        <div>
          <label class="psc-portal-field-label screen-reader-text" for="psc-hh-tel">Téléphone</label>
          <input id="psc-hh-tel" class="psc-portal-field-underline" type="tel" name="telephone" placeholder="Téléphone" maxlength="40" required>
        </div>
        <button type="submit" class="psc-portal-btn-gold" data-testid="profil-household-pickup-submit">Ajouter</button>
        <button type="button" class="psc-wizard-remove-pickup-btn" data-household-pickup-cancel data-testid="profil-household-pickup-cancel">Annuler</button>
      </form>
    </div>
  </div>
</div>
