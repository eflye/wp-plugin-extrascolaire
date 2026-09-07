<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow"><?php esc_html_e('Famille', 'periscolaire-registration'); ?></div>
<h1 class="psc-portal-h1" data-testid="profil-title"><?php esc_html_e('Mon profil', 'periscolaire-registration'); ?></h1>
<p class="psc-portal-intro"><?php esc_html_e('État civil, coordonnées et adresse du foyer. Ces informations ne concernent que vous — la fiche de chaque enfant se modifie depuis "Mes enfants".', 'periscolaire-registration'); ?></p>

<?php if (!empty($parent->pending_email)): ?>
<p class="psc-notice psc-notice-ok" data-testid="profil-pending-email">
  <?php esc_html_e('Adresse en attente de confirmation :', 'periscolaire-registration'); ?> <strong><?php echo esc_html($parent->pending_email); ?></strong>.
  <?php esc_html_e("Vérifiez votre boîte mail pour l'activer.", 'periscolaire-registration'); ?>
</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:24px;">
  <?php wp_nonce_field('psc_cancel_email_change'); ?>
  <input type="hidden" name="action" value="psc_cancel_email_change">
  <button type="submit" class="psc-portal-btn-sm" data-testid="profil-cancel-email-change"><?php esc_html_e('Annuler ce changement', 'periscolaire-registration'); ?></button>
</form>
<?php endif; ?>

<div class="psc-portal-panel">
  <div class="psc-portal-panel-title"><?php esc_html_e('État civil', 'periscolaire-registration'); ?></div>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psc-portal-profile-form" data-testid="profil-form">
    <?php wp_nonce_field('psc_parent_update_profile'); psc_parent_nonce_field('psc_parent_update_profile'); ?>
    <input type="hidden" name="action" value="psc_parent_update_profile">

    <div class="psc-portal-field-grid">
      <div>
        <div class="psc-portal-field-label"><?php esc_html_e('Prénom', 'periscolaire-registration'); ?></div>
        <input type="text" name="profil_prenom" value="<?php echo esc_attr($parent->prenom); ?>" maxlength="190" class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label"><?php esc_html_e('Nom', 'periscolaire-registration'); ?></div>
        <input type="text" name="profil_nom" value="<?php echo esc_attr($parent->nom); ?>" maxlength="190" class="psc-portal-field-underline">
      </div>
    </div>

    <div class="psc-portal-panel-title" style="margin-top:28px;"><?php esc_html_e('Coordonnées', 'periscolaire-registration'); ?></div>
    <div class="psc-portal-field-grid">
      <div>
        <div class="psc-portal-field-label"><?php esc_html_e('Téléphone mobile', 'periscolaire-registration'); ?></div>
        <input type="tel" name="profil_tel_mobile" value="<?php echo esc_attr($parent->telephone_mobile); ?>" maxlength="40" pattern="<?php echo esc_attr(psc_tel_pattern()); ?>" title="<?php esc_attr_e('Format attendu : 06 12 34 56 78 ou +33 6 12 34 56 78.', 'periscolaire-registration'); ?>" class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label"><?php esc_html_e('Téléphone fixe', 'periscolaire-registration'); ?></div>
        <input type="tel" name="profil_tel_fixe" value="<?php echo esc_attr($parent->telephone_fixe); ?>" maxlength="40" pattern="<?php echo esc_attr(psc_tel_pattern()); ?>" title="<?php esc_attr_e('Format attendu : 06 12 34 56 78 ou +33 6 12 34 56 78.', 'periscolaire-registration'); ?>" class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label"><?php esc_html_e('Adresse e-mail', 'periscolaire-registration'); ?></div>
        <input type="email" name="profil_email" value="<?php echo esc_attr($parent->email); ?>" maxlength="191" required class="psc-portal-field-underline">
      </div>
    </div>

    <div class="psc-portal-panel-title" style="margin-top:28px;"><?php esc_html_e('Adresse du foyer', 'periscolaire-registration'); ?></div>
    <div class="psc-portal-field-grid">
      <div>
        <div class="psc-portal-field-label"><?php esc_html_e('Adresse', 'periscolaire-registration'); ?></div>
        <input type="text" name="profil_adresse" value="<?php echo esc_attr($parent->adresse); ?>" maxlength="255" class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label"><?php esc_html_e('Code postal', 'periscolaire-registration'); ?></div>
        <input type="text" name="profil_code_postal" value="<?php echo esc_attr($parent->code_postal); ?>" maxlength="10" pattern="[0-9]{5}" title="<?php esc_attr_e('Format attendu : 5 chiffres.', 'periscolaire-registration'); ?>" class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label"><?php esc_html_e('Ville', 'periscolaire-registration'); ?></div>
        <input type="text" name="profil_ville" value="<?php echo esc_attr($parent->ville); ?>" maxlength="100" class="psc-portal-field-underline">
      </div>
    </div>

    <p style="margin-top:24px;"><button type="submit" class="psc-portal-btn-gold" data-testid="profil-submit"><?php esc_html_e('Enregistrer', 'periscolaire-registration'); ?></button></p>
  </form>
</div>

<div class="psc-portal-panel" data-testid="profil-payment-panel">
  <div class="psc-portal-panel-title"><?php esc_html_e('Mode de paiement', 'periscolaire-registration'); ?></div>
  <?php if (($parent->payment_mode ?? 'autre') === 'prelevement'): ?>
    <div class="psc-wizard-payment-cards">
      <div class="psc-wizard-payment-card is-active" data-testid="profil-payment-prelevement-active">
        <div class="psc-wizard-payment-card-title"><?php esc_html_e('Prélèvement automatique (SEPA)', 'periscolaire-registration'); ?></div>
        <div class="psc-wizard-payment-card-sub">
          <?php esc_html_e('Actif', 'periscolaire-registration'); ?>
          <?php $psc_profile_iban = psc_read_iban($parent); if ($psc_profile_iban): ?>
            — <?php echo esc_html(psc_mask_iban($psc_profile_iban)); ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <p class="psc-portal-intro"><?php esc_html_e('Pour modifier vos coordonnées bancaires ou mettre fin au prélèvement, contactez la mairie.', 'periscolaire-registration'); ?></p>
  <?php else: ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-testid="profil-sepa-form">
      <?php wp_nonce_field('psc_parent_enable_sepa'); psc_parent_nonce_field('psc_parent_enable_sepa'); ?>
      <input type="hidden" name="action" value="psc_parent_enable_sepa">

      <p class="psc-portal-intro"><?php esc_html_e('Vous réglez actuellement vos factures par chèque ou espèces. Vous pouvez activer le prélèvement automatique.', 'periscolaire-registration'); ?></p>
      <div class="psc-wizard-payment-cards">
        <div class="psc-wizard-payment-card is-active" data-testid="profil-payment-autre-active">
          <div class="psc-wizard-payment-card-title"><?php esc_html_e('Chèque ou espèces', 'periscolaire-registration'); ?></div>
          <div class="psc-wizard-payment-card-sub"><?php esc_html_e('Mode actuel', 'periscolaire-registration'); ?></div>
        </div>
        <button type="button" class="psc-wizard-payment-card" id="psc-profile-pm-prelevement" data-testid="profil-payment-enable-sepa">
          <div class="psc-wizard-payment-card-title"><?php esc_html_e('Prélèvement automatique', 'periscolaire-registration'); ?></div>
          <div class="psc-wizard-payment-card-sub"><?php esc_html_e('Gratuit — prélevé le 5 du mois suivant', 'periscolaire-registration'); ?></div>
        </button>
      </div>

      <div id="psc-profile-sepa-panel" class="psc-wizard-sepa-panel" hidden data-testid="profil-sepa-fields">
        <div class="psc-wizard-sepa-creditor">
          <strong><?php esc_html_e('Créancier :', 'periscolaire-registration'); ?></strong> <?php echo esc_html(get_option('psc_billing_org_name', get_bloginfo('name'))); ?>
          <?php $psc_profile_ics = get_option('psc_billing_org_ics', ''); if ($psc_profile_ics): ?>
            <br><strong><?php esc_html_e('Identifiant créancier SEPA (ICS) :', 'periscolaire-registration'); ?></strong> <?php echo esc_html($psc_profile_ics); ?>
          <?php endif; ?>
        </div>

        <div class="psc-wizard-field-grid" style="margin-top:16px;">
          <div>
            <label class="psc-portal-field-label" for="psc-profile-sepa-titulaire"><?php esc_html_e('Titulaire du compte à débiter', 'periscolaire-registration'); ?> <span class="psc-req">*</span></label>
            <input id="psc-profile-sepa-titulaire" class="psc-portal-field-underline" type="text" name="sepa_titulaire" maxlength="190" value="<?php echo esc_attr(trim($parent->prenom . ' ' . $parent->nom)); ?>" autocomplete="name" required>
          </div>
          <div>
            <label class="psc-portal-field-label" for="psc-profile-sepa-iban"><?php esc_html_e('IBAN', 'periscolaire-registration'); ?> <span class="psc-req">*</span></label>
            <input id="psc-profile-sepa-iban" class="psc-portal-field-underline" type="text" name="sepa_iban" maxlength="42" placeholder="FR76 XXXX XXXX XXXX XXXX XXXX XXX" autocomplete="off" required>
          </div>
          <div>
            <label class="psc-portal-field-label" for="psc-profile-sepa-bic"><?php esc_html_e('BIC', 'periscolaire-registration'); ?> <span class="psc-req">*</span></label>
            <input id="psc-profile-sepa-bic" class="psc-portal-field-underline" type="text" name="sepa_bic" maxlength="11" placeholder="XXXXFRPPXXX" autocomplete="off" required>
          </div>
        </div>

        <label class="psc-wizard-same-address">
          <input type="checkbox" id="psc-profile-sepa-same-address"
                 data-address="<?php echo esc_attr($parent->adresse); ?>"
                 data-postcode="<?php echo esc_attr($parent->code_postal); ?>"
                 data-city="<?php echo esc_attr($parent->ville); ?>">
          <?php esc_html_e("Adresse du titulaire identique à l'adresse du foyer", 'periscolaire-registration'); ?>
        </label>

        <div class="psc-wizard-field-grid" style="margin-bottom:16px;">
          <div>
            <label class="psc-portal-field-label" for="psc-profile-sepa-adresse"><?php esc_html_e('Adresse du titulaire', 'periscolaire-registration'); ?></label>
            <input id="psc-profile-sepa-adresse" class="psc-portal-field-underline" type="text" name="sepa_adresse" maxlength="255" autocomplete="street-address">
          </div>
          <div style="display:flex;gap:12px;">
            <span style="flex:0 0 100px;">
              <label class="psc-portal-field-label" for="psc-profile-sepa-cp"><?php esc_html_e('Code postal', 'periscolaire-registration'); ?></label>
              <input id="psc-profile-sepa-cp" class="psc-portal-field-underline" type="text" name="sepa_code_postal" maxlength="10" pattern="[0-9]{5}" title="<?php esc_attr_e('Format attendu : 5 chiffres.', 'periscolaire-registration'); ?>" autocomplete="postal-code">
            </span>
            <span style="flex:1;">
              <label class="psc-portal-field-label" for="psc-profile-sepa-ville"><?php esc_html_e('Ville', 'periscolaire-registration'); ?></label>
              <input id="psc-profile-sepa-ville" class="psc-portal-field-underline" type="text" name="sepa_ville" maxlength="100" autocomplete="address-level2">
            </span>
          </div>
        </div>

        <p style="font-weight:600;font-size:13px;margin-bottom:8px;"><?php esc_html_e('Règlement concernant le prélèvement', 'periscolaire-registration'); ?></p>
        <div class="psc-wizard-reglement-box" tabindex="0">
          <p><?php esc_html_e('Vous avez opté pour le mode de paiement par prélèvement, ce service est gratuit. Les montants dus au titre de la cantine et de la garderie seront prélevés automatiquement sur le compte désigné.', 'periscolaire-registration'); ?></p>
          <p><?php esc_html_e('Le montant de la facture mensuelle sera prélevé à terme échu le 5 du mois suivant ou, à défaut, le premier jour ouvrable suivant le 5.', 'periscolaire-registration'); ?></p>
          <p><?php esc_html_e('En cas de rejet du prélèvement, les frais bancaires correspondants seront à votre charge et imputés sur la facture suivante.', 'periscolaire-registration'); ?></p>
          <p><?php esc_html_e('Vous pouvez mettre fin au prélèvement automatique sur demande écrite à la mairie.', 'periscolaire-registration'); ?></p>
        </div>
        <label class="psc-wizard-check-line">
          <input type="checkbox" name="sepa_reglement_accepted" value="1" data-testid="profil-sepa-accept" required>
          <?php esc_html_e("J'ai pris connaissance du règlement concernant le prélèvement automatique et je l'approuve, ainsi que du mandat SEPA autorisant la mairie à débiter le compte ci-dessus.", 'periscolaire-registration'); ?>
          <span class="psc-req">*</span>
        </label>
        <p style="margin-top:20px;"><button type="submit" class="psc-portal-btn-gold" data-testid="profil-sepa-submit"><?php esc_html_e('Activer le prélèvement automatique', 'periscolaire-registration'); ?></button></p>
      </div>
    </form>
  <?php endif; ?>
</div>

<div class="psc-portal-panel">
  <div class="psc-portal-panel-title"><?php esc_html_e('Second parent (facultatif)', 'periscolaire-registration'); ?></div>
  <?php $psc_has_second_parent = trim((string) $parent->second_parent_prenom) !== '' || trim((string) $parent->second_parent_nom) !== ''; ?>
  <button type="button" id="psc-add-second-parent" class="psc-wizard-add-pickup-btn" data-testid="profil-add-second-parent"<?php echo $psc_has_second_parent ? ' hidden' : ''; ?>><?php esc_html_e('+ Ajouter un second parent', 'periscolaire-registration'); ?></button>

  <div id="psc-second-parent-block" data-testid="profil-second-parent-block"<?php echo $psc_has_second_parent ? '' : ' hidden'; ?>>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-testid="profil-second-parent-form">
      <?php wp_nonce_field('psc_parent_update_second_parent'); psc_parent_nonce_field('psc_parent_update_second_parent'); ?>
      <input type="hidden" name="action" value="psc_parent_update_second_parent">
      <div class="psc-portal-field-grid">
        <div>
          <div class="psc-portal-field-label"><?php esc_html_e('Prénom', 'periscolaire-registration'); ?></div>
          <input type="text" name="second_parent_prenom" value="<?php echo esc_attr($parent->second_parent_prenom); ?>" maxlength="190" class="psc-portal-field-underline">
        </div>
        <div>
          <div class="psc-portal-field-label"><?php esc_html_e('Nom', 'periscolaire-registration'); ?></div>
          <input type="text" name="second_parent_nom" value="<?php echo esc_attr($parent->second_parent_nom); ?>" maxlength="190" class="psc-portal-field-underline">
        </div>
        <div>
          <div class="psc-portal-field-label"><?php esc_html_e('E-mail', 'periscolaire-registration'); ?></div>
          <input type="email" name="second_parent_email" value="<?php echo esc_attr($parent->second_parent_email); ?>" class="psc-portal-field-underline">
        </div>
        <div>
          <div class="psc-portal-field-label"><?php esc_html_e('Téléphone', 'periscolaire-registration'); ?></div>
          <input type="tel" name="second_parent_telephone" value="<?php echo esc_attr($parent->second_parent_telephone); ?>" maxlength="40" pattern="<?php echo esc_attr(psc_tel_pattern()); ?>" title="<?php esc_attr_e('Format attendu : 06 12 34 56 78 ou +33 6 12 34 56 78.', 'periscolaire-registration'); ?>" class="psc-portal-field-underline">
        </div>
      </div>
      <p style="margin-top:16px;"><button type="submit" class="psc-portal-btn-gold" data-testid="profil-second-parent-submit"><?php esc_html_e('Enregistrer', 'periscolaire-registration'); ?></button></p>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-testid="profil-second-parent-remove-form">
      <?php wp_nonce_field('psc_parent_remove_second_parent'); psc_parent_nonce_field('psc_parent_remove_second_parent'); ?>
      <input type="hidden" name="action" value="psc_parent_remove_second_parent">
      <button type="submit" class="psc-wizard-remove-pickup-btn" data-testid="profil-remove-second-parent" onclick="return confirm('<?php echo esc_js(__('Retirer le second parent ?', 'periscolaire-registration')); ?>');"><?php esc_html_e('Retirer', 'periscolaire-registration'); ?></button>
    </form>
  </div>
</div>
