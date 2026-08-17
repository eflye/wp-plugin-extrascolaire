<?php if (!defined('ABSPATH')) exit; ?>
<?php
$psc_wizard_labels = array('Coordonnées', 'Enfants', 'Paiement', 'Règlement');
$psc_wizard_ctx     = isset($psc_wizard) ? $psc_wizard : array('step' => 0, 'payment_mode' => 'autre', 'has_error' => false);
$psc_wizard_step    = (int) $psc_wizard_ctx['step'];
$psc_wizard_payment = $psc_wizard_ctx['payment_mode'];
?>
<div class="psc-guest-card psc-wizard" id="psc-wizard" data-testid="request-wizard" data-default-step="<?php echo esc_attr($psc_wizard_step); ?>" data-has-error="<?php echo $psc_wizard_ctx['has_error'] ? '1' : '0'; ?>">
  <div class="psc-guest-eyebrow">Première inscription</div>
  <h2 class="psc-guest-h1">Déposer une demande</h2>
  <p class="psc-guest-intro">Votre famille n'est pas encore connue du service périscolaire. Une fois la demande envoyée, vous recevrez un e-mail pour confirmer votre adresse, puis la mairie l'examinera avant de vous donner accès à votre espace famille.</p>

  <?php if ($psc_wizard_ctx['has_error'] && !empty($psc_msg) && isset($psc_notices[$psc_msg])):
      list($psc_wm_type, $psc_wm_text) = $psc_notices[$psc_msg];
  ?>
  <p class="psc-notice psc-notice-<?php echo esc_attr($psc_wm_type); ?>" data-testid="notice-<?php echo esc_attr($psc_msg); ?>"><?php echo esc_html($psc_wm_text); ?></p>
  <?php endif; ?>

  <div class="psc-wizard-stepper" data-testid="wizard-stepper">
    <?php foreach ($psc_wizard_labels as $i => $label): $is_last = ($i === count($psc_wizard_labels) - 1); ?>
    <div class="psc-wizard-step-wrap">
      <div class="psc-wizard-step-track">
        <button type="button" class="psc-wizard-circle" data-wizard-goto="<?php echo esc_attr($i); ?>" data-testid="wizard-circle-<?php echo esc_attr($i); ?>"><?php echo esc_html($i + 1); ?></button>
        <?php if (!$is_last): ?><div class="psc-wizard-line"></div><?php endif; ?>
      </div>
      <div class="psc-wizard-step-label" data-testid="wizard-label-<?php echo esc_attr($i); ?>"><?php echo esc_html($label); ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="psc-request-form" data-testid="request-form">
    <?php wp_nonce_field('psc_submit_request'); ?>
    <input type="hidden" name="action" value="psc_submit_request">

    <?php /* Champ piège anti-robot : masqué et hors du parcours clavier. */ ?>
    <div class="psc-hp" aria-hidden="true">
      <label>Site web (ne pas remplir)
        <input type="text" name="psc_website" tabindex="-1" autocomplete="off">
      </label>
    </div>

    <div class="psc-wizard-step" data-wizard-step="0" data-testid="wizard-step-0">
      <div class="psc-wizard-field-grid">
        <div>
          <label class="psc-portal-field-label" for="psc-req-email">Adresse e-mail <span class="psc-req">*</span></label>
          <input id="psc-req-email" class="psc-portal-field-underline" type="email" name="req_email" autocomplete="email" required>
        </div>
        <div>
          <label class="psc-portal-field-label" for="psc-req-prenom">Prénom <span class="psc-req">*</span></label>
          <input id="psc-req-prenom" class="psc-portal-field-underline" type="text" name="req_prenom" maxlength="190" autocomplete="given-name" required>
        </div>
        <div>
          <label class="psc-portal-field-label" for="psc-req-nom">Nom <span class="psc-req">*</span></label>
          <input id="psc-req-nom" class="psc-portal-field-underline" type="text" name="req_nom" maxlength="190" autocomplete="family-name" required>
        </div>
        <div>
          <label class="psc-portal-field-label" for="psc-req-tel">Téléphone <span class="psc-req">*</span></label>
          <input id="psc-req-tel" class="psc-portal-field-underline" type="tel" name="req_telephone" maxlength="40" autocomplete="tel" required>
        </div>
        <div>
          <label class="psc-portal-field-label" for="psc-req-adresse">Adresse postale <span class="psc-req">*</span></label>
          <input id="psc-req-adresse" class="psc-portal-field-underline" type="text" name="req_adresse" maxlength="255" autocomplete="street-address" required>
        </div>
        <div>
          <label class="psc-portal-field-label" for="psc-req-cp">Code postal <span class="psc-req">*</span></label>
          <input id="psc-req-cp" class="psc-portal-field-underline" type="text" name="req_code_postal" maxlength="10" autocomplete="postal-code" required>
        </div>
        <div>
          <label class="psc-portal-field-label" for="psc-req-ville">Ville <span class="psc-req">*</span></label>
          <input id="psc-req-ville" class="psc-portal-field-underline" type="text" name="req_ville" maxlength="100" autocomplete="address-level2" required>
        </div>
      </div>

      <div class="psc-wizard-pickup-block">
        <button type="button" id="psc-add-second-parent" class="psc-wizard-add-pickup-btn" data-testid="add-second-parent-button">+ Ajouter un second parent</button>
        <div id="psc-second-parent-block" hidden data-testid="second-parent-block">
          <p class="psc-wizard-pickup-title">Second parent (facultatif)</p>
          <div class="psc-wizard-field-grid">
            <div>
              <label class="psc-portal-field-label" for="psc-sp-prenom">Prénom</label>
              <input id="psc-sp-prenom" class="psc-portal-field-underline" type="text" name="second_parent_prenom" maxlength="190" autocomplete="given-name">
            </div>
            <div>
              <label class="psc-portal-field-label" for="psc-sp-nom">Nom</label>
              <input id="psc-sp-nom" class="psc-portal-field-underline" type="text" name="second_parent_nom" maxlength="190" autocomplete="family-name">
            </div>
            <div>
              <label class="psc-portal-field-label" for="psc-sp-email">E-mail</label>
              <input id="psc-sp-email" class="psc-portal-field-underline" type="email" name="second_parent_email" autocomplete="email">
            </div>
            <div>
              <label class="psc-portal-field-label" for="psc-sp-tel">Téléphone</label>
              <input id="psc-sp-tel" class="psc-portal-field-underline" type="tel" name="second_parent_telephone" maxlength="40" autocomplete="tel">
            </div>
          </div>
          <button type="button" id="psc-remove-second-parent" class="psc-wizard-remove-pickup-btn" data-testid="remove-second-parent-button">Retirer</button>
        </div>
      </div>
    </div>

    <div class="psc-wizard-step" data-wizard-step="1" data-testid="wizard-step-1">
      <p class="psc-wizard-help">Renseignez au moins un enfant (nom, prénom). Vous pouvez préciser un régime alimentaire particulier.</p>

      <datalist id="psc-pickup-lien-suggestions">
        <?php foreach (psc_pickup_lien_suggestions() as $psc_lien): ?>
        <option value="<?php echo esc_attr($psc_lien); ?>">
        <?php endforeach; ?>
      </datalist>

      <div id="psc-children-list">
        <div class="psc-wizard-child-row" data-index="0">
          <div>
            <label class="psc-portal-field-label screen-reader-text" for="psc-cp-0">Prénom de l'enfant 1</label>
            <input id="psc-cp-0" class="psc-portal-field-underline" type="text" name="child_prenom_0" placeholder="Prénom" maxlength="190" required>
          </div>
          <div>
            <label class="psc-portal-field-label screen-reader-text" for="psc-cn-0">Nom de l'enfant 1</label>
            <input id="psc-cn-0" class="psc-portal-field-underline" type="text" name="child_nom_0" placeholder="Nom" maxlength="190" required>
          </div>
          <div>
            <label class="psc-portal-field-label screen-reader-text" for="psc-cc-0">Classe de l'enfant 1</label>
            <select id="psc-cc-0" class="psc-portal-field-underline" name="child_classe_0" required>
              <?php foreach (psc_classe_options() as $v => $l): ?>
              <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($l); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="psc-portal-field-label screen-reader-text" for="psc-cb-0">Date de naissance de l'enfant 1</label>
            <input id="psc-cb-0" class="psc-portal-field-underline" type="date" name="child_naissance_0" required>
          </div>
          <div>
            <label class="psc-portal-field-label" for="psc-ca-0">Justificatif d'assurance scolaire</label>
            <input id="psc-ca-0" type="file" name="child_assurance_0" accept=".pdf,.jpg,.jpeg,.png" required>
          </div>
          <label class="psc-wizard-diet-check"><input type="checkbox" name="child_sans_porc_0" value="1"> Sans porc</label>
          <label class="psc-wizard-diet-check"><input type="checkbox" name="child_vegan_0" value="1"> Sans viande</label>

          <div class="psc-wizard-pickup-block">
            <p class="psc-wizard-pickup-title">Personnes autorisées à récupérer cet enfant (facultatif)</p>
            <div class="psc-wizard-pickup-list" data-pickup-list></div>
            <button type="button" class="psc-wizard-add-pickup-btn" data-testid="add-pickup-person-0">+ Ajouter une personne autorisée</button>
          </div>

          <button type="button" class="psc-wizard-remove-btn" aria-label="Supprimer cet enfant" hidden>Retirer</button>
        </div>
      </div>

      <button type="button" id="psc-add-child" class="psc-wizard-add-btn" data-testid="add-child-button">+ Ajouter un enfant</button>

      <div style="margin-top:24px;">
        <label class="psc-portal-field-label" for="psc-req-msg">Message pour la mairie (facultatif)</label>
        <textarea id="psc-req-msg" class="psc-portal-field-underline" name="req_message" rows="3" maxlength="1000" style="border:1px solid var(--psc-rule-light);padding:8px;"></textarea>
      </div>
    </div>

    <div class="psc-wizard-step" data-wizard-step="2" data-testid="wizard-step-2">
      <p class="psc-wizard-help">Comment réglerez-vous les factures de cantine et de garderie ?</p>

      <div class="psc-wizard-payment-cards">
        <button type="button" class="psc-wizard-payment-card<?php echo $psc_wizard_payment === 'autre' ? ' is-active' : ''; ?>" id="psc-pm-autre-card" data-payment-mode="autre" data-testid="payment-card-autre">
          <div class="psc-wizard-payment-card-title">Chèque ou espèces</div>
          <div class="psc-wizard-payment-card-sub">Réglé à réception de facture</div>
        </button>
        <button type="button" class="psc-wizard-payment-card<?php echo $psc_wizard_payment === 'prelevement' ? ' is-active' : ''; ?>" id="psc-pm-prelevement-card" data-payment-mode="prelevement" data-testid="payment-card-prelevement">
          <div class="psc-wizard-payment-card-title">Prélèvement automatique</div>
          <div class="psc-wizard-payment-card-sub">Gratuit — prélevé le 5 du mois suivant</div>
        </button>
      </div>
      <input type="hidden" name="payment_mode" id="psc-payment-mode-input" value="<?php echo esc_attr($psc_wizard_payment); ?>">

      <div id="psc-sepa-block" class="psc-wizard-sepa-panel" <?php echo $psc_wizard_payment === 'prelevement' ? '' : 'hidden'; ?> data-testid="sepa-panel">
        <div class="psc-wizard-sepa-creditor">
          <strong>Créancier :</strong> <?php echo esc_html(get_option('psc_billing_org_name', get_bloginfo('name'))); ?>
          <?php $psc_ics = get_option('psc_billing_org_ics', ''); if ($psc_ics): ?>
          <br><strong>Identifiant créancier SEPA (ICS) :</strong> <?php echo esc_html($psc_ics); ?>
          <?php endif; ?>
        </div>

        <div class="psc-wizard-field-grid" style="margin-top:16px;">
          <div>
            <label class="psc-portal-field-label" for="psc-sepa-titulaire">Titulaire du compte à débiter</label>
            <input id="psc-sepa-titulaire" class="psc-portal-field-underline" type="text" name="sepa_titulaire" maxlength="190" autocomplete="name">
          </div>
          <div>
            <label class="psc-portal-field-label" for="psc-sepa-iban">IBAN</label>
            <input id="psc-sepa-iban" class="psc-portal-field-underline" type="text" name="sepa_iban" maxlength="42" placeholder="FR76 XXXX XXXX XXXX XXXX XXXX XXX" autocomplete="off">
          </div>
          <div>
            <label class="psc-portal-field-label" for="psc-sepa-bic">BIC</label>
            <input id="psc-sepa-bic" class="psc-portal-field-underline" type="text" name="sepa_bic" maxlength="11" placeholder="XXXXFRPPXXX" autocomplete="off">
          </div>
        </div>

        <label class="psc-wizard-same-address">
          <input type="checkbox" id="psc-sepa-same-address"> Adresse du titulaire identique à l'adresse renseignée à l'étape 1
        </label>

        <div class="psc-wizard-field-grid" style="margin-bottom:16px;">
          <div>
            <label class="psc-portal-field-label" for="psc-sepa-adresse">Adresse du titulaire</label>
            <input id="psc-sepa-adresse" class="psc-portal-field-underline" type="text" name="sepa_adresse" maxlength="255" autocomplete="off">
          </div>
          <div style="display:flex;gap:12px;">
            <span style="flex:0 0 100px;">
              <label class="psc-portal-field-label" for="psc-sepa-cp">Code postal</label>
              <input id="psc-sepa-cp" class="psc-portal-field-underline" type="text" name="sepa_code_postal" maxlength="10" autocomplete="off">
            </span>
            <span style="flex:1;">
              <label class="psc-portal-field-label" for="psc-sepa-ville">Ville</label>
              <input id="psc-sepa-ville" class="psc-portal-field-underline" type="text" name="sepa_ville" maxlength="100" autocomplete="off">
            </span>
          </div>
        </div>

        <p style="font-weight:600;font-size:13px;margin-bottom:8px;">Règlement concernant le prélèvement</p>
        <div class="psc-wizard-reglement-box" tabindex="0">
          <p>Vous avez opté pour le mode de paiement par prélèvement, ce service est gratuit. Les montants dus au titre de la cantine, garderie seront prélevés automatiquement sur le compte que vous avez désigné dans les conditions suivantes :</p>
          <p>Le montant de la facture mensuelle sera prélevé à terme échu le 5 du mois suivant ou à défaut, le premier jour ouvrable suivant le 5.</p>
          <p>En cas de rejet du prélèvement, les frais bancaires correspondants seront à votre charge et seront imputés sur la facture suivante.</p>
          <p>Les rejets feront l'objet de rappels émis par la mairie et devront être réglés par chèque ou espèces dans les meilleurs délais. Sans régularisation de votre part dans les 15 jours suivant l'émission du rappel, le dossier sera automatiquement transmis au Trésor Public pour un recouvrement contentieux.</p>
          <p>En cas de changement de domiciliation bancaire, il sera nécessaire de remplir un nouveau mandat de prélèvement SEPA accompagné d'un nouveau RIB.</p>
          <p>Vous pouvez à tout moment décider de mettre fin au prélèvement automatique, sur simple demande écrite à la mairie. Le montant des factures échues sera alors à payer par chèque ou espèces dès leur réception.</p>
        </div>
        <label class="psc-wizard-check-line">
          <input type="checkbox" name="sepa_reglement_accepted" id="psc-sepa-reglement-cb" value="1">
          J'ai pris connaissance du règlement concernant le prélèvement automatique et je l'approuve, ainsi que du mandat de prélèvement SEPA autorisant la mairie à débiter le compte ci-dessus.
          <span class="psc-req">*</span>
        </label>
      </div>
    </div>

    <div class="psc-wizard-step" data-wizard-step="3" data-testid="wizard-step-3">
      <div class="psc-wizard-reglement-box" tabindex="0">
        <h4>1 – Préambule</h4>
        <p>La demi-pension, la garderie et l'étude sont des services communaux réservés aux enfants scolarisés à l'école maternelle et élémentaire. Ces services fonctionnent pendant l'année scolaire.</p>

        <h4>2 – Fonctionnement</h4>
        <p><strong>Inscription</strong> — L'inscription de chaque enfant est obligatoire pour être accueilli aux différents temps périscolaires. Toute modification d'inscription doit être signalée au responsable du périscolaire.</p>
        <p><strong>Engagement</strong> — Les inscriptions sont fermes et définitives pour le trimestre, que ce soit pour la demi-pension ou la garderie. Cet engagement permet de bénéficier d'un tarif annuel avantageux.</p>
        <p><strong>Facturation</strong> — Le paiement des prestations s'effectue par prélèvement, par chèque ou en espèces à terme échu. Il n'y aura pas de remboursement pour absence de l'enfant, sauf fermeture de l'établissement, sortie scolaire, hospitalisation ou maladie de plus de 3 jours médicalement justifiée (justificatif à fournir dans les deux premiers jours d'absence ; une franchise de deux jours sera appliquée dans ces deux derniers cas).</p>
        <p><strong>Tarifs</strong> — Les tarifs sont déterminés par délibération pour l'année scolaire et sont identiques pour les enfants de maternelle et d'élémentaire. Aucun calcul de quotient familial n'est appliqué.</p>
        <p><strong>Repas</strong> — Les repas sont fournis par un prestataire de service. En cas de prescription médicale d'un régime particulier, un certificat médical doit être fourni.</p>
        <p><strong>Encadrement</strong> — Pendant les temps périscolaires, les enfants sont placés sous la surveillance exclusive du personnel. Aucune autre personne n'est admise lors des services.</p>
        <p><strong>Traitement médical</strong> — En cas de nécessité absolue dûment constatée par une ordonnance médicale, le personnel donnera à l'enfant les remèdes prescrits, selon les indications écrites des parents.</p>

        <h4>3 – Discipline</h4>
        <p>Les enfants suivent les mêmes règles qu'à l'école (maladie, situations d'urgence, sorties). En cas de problème disciplinaire grave ou répété, toute sanction nécessaire au bon fonctionnement pourra être prise.</p>

        <h4>4 – Responsabilité</h4>
        <p>Dès que vous êtes présents dans les services du périscolaire, votre ou vos enfants sont sous votre responsabilité. Pour des raisons de sécurité, il est préférable de ne pas s'attarder dans les locaux pendant les heures de surveillance.</p>
      </div>
      <label class="psc-wizard-check-line">
        <input type="checkbox" name="reglement_accepted" value="1" required>
        J'ai pris connaissance du règlement intérieur des services périscolaires et je l'approuve dans sa totalité.
        <span class="psc-req">*</span>
      </label>

      <p class="psc-wizard-rgpd">
        Les informations saisies sont transmises à la mairie pour le seul traitement de votre demande d'inscription au service périscolaire. Une demande non confirmée est automatiquement supprimée au bout de 7 jours. Vous disposez d'un droit d'accès et de rectification en contactant la mairie.
      </p>
    </div>

    <div class="psc-wizard-nav">
      <button type="button" class="psc-portal-btn-outline-forest" id="psc-wizard-prev" data-testid="wizard-prev">Précédent</button>
      <button type="button" class="psc-portal-btn-gold" id="psc-wizard-next" data-testid="wizard-next">Suivant</button>
      <button type="submit" class="psc-portal-btn-gold" id="psc-wizard-submit" data-testid="wizard-submit">Envoyer ma demande</button>
    </div>
  </form>
</div>
