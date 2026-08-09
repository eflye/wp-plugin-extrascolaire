<?php if (!defined('ABSPATH')) exit; ?>

<details class="psc-card psc-request-block" <?php echo in_array($psc_msg, array(
    'need_child', 'bad_email', 'reglement_required', 'sepa_reglement_required',
    'sepa_missing', 'bad_iban', 'bad_bic',
), true) ? 'open' : ''; ?>>
  <summary><strong>Première inscription</strong></summary>

  <p class="psc-lead">Votre famille n'est pas encore connue du service périscolaire</p>
  <p class="psc-card-intro">Remplissez le formulaire ci-dessous. La suite se déroule en trois temps :</p>
  <ol class="psc-steps">
    <li>Vous recevez un e-mail pour confirmer votre adresse ;</li>
    <li>La mairie examine votre demande ;</li>
    <li>Une fois validée, vous accédez à votre planning avec un simple lien e-mail.</li>
  </ol>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psc-request-form">
    <?php wp_nonce_field('psc_submit_request'); ?>
    <input type="hidden" name="action" value="psc_submit_request">

    <?php /* Champ piège anti-robot : masqué et hors du parcours clavier. */ ?>
    <div class="psc-hp" aria-hidden="true">
      <label>Site web (ne pas remplir)
        <input type="text" name="psc_website" tabindex="-1" autocomplete="off">
      </label>
    </div>

    <fieldset>
      <legend>Vos coordonnées</legend>
      <p>
        <label for="psc-req-email">Adresse e-mail <span class="psc-req">*</span></label><br>
        <input id="psc-req-email" type="email" name="req_email" autocomplete="email" required>
      </p>
      <p>
        <label for="psc-req-nom">Nom de famille</label><br>
        <input id="psc-req-nom" type="text" name="req_nom" maxlength="190" autocomplete="family-name">
      </p>
      <p>
        <label for="psc-req-tel">Téléphone</label><br>
        <input id="psc-req-tel" type="tel" name="req_telephone" maxlength="40" autocomplete="tel">
      </p>
      <p>
        <label for="psc-req-adresse">Adresse postale</label><br>
        <input id="psc-req-adresse" type="text" name="req_adresse" maxlength="255" autocomplete="street-address">
      </p>
      <p style="display:flex;gap:12px;">
        <span style="flex:0 0 100px;">
          <label for="psc-req-cp">Code postal</label><br>
          <input id="psc-req-cp" type="text" name="req_code_postal" maxlength="10" autocomplete="postal-code" style="width:100%">
        </span>
        <span style="flex:1;">
          <label for="psc-req-ville">Ville</label><br>
          <input id="psc-req-ville" type="text" name="req_ville" maxlength="100" autocomplete="address-level2" style="width:100%">
        </span>
      </p>
    </fieldset>

    <fieldset>
      <legend>Votre ou vos enfants <span class="psc-req">*</span></legend>
      <p class="psc-help">Renseignez au moins un enfant.</p>

      <div id="psc-children-list">
        <div class="psc-child-row" data-index="0">
          <label class="screen-reader-text" for="psc-cp-0">Prénom de l'enfant 1</label>
          <input id="psc-cp-0" type="text" name="child_prenom_0" placeholder="Prénom" maxlength="190" required>

          <label class="screen-reader-text" for="psc-cn-0">Nom de l'enfant 1</label>
          <input id="psc-cn-0" type="text" name="child_nom_0" placeholder="Nom" maxlength="190" required>

          <label class="screen-reader-text" for="psc-cc-0">Classe de l'enfant 1</label>
          <select id="psc-cc-0" name="child_classe_0">
            <?php foreach (psc_classe_options() as $v => $l): ?>
            <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($l); ?></option>
            <?php endforeach; ?>
          </select>

          <span class="psc-diet-options">
            <label><input type="checkbox" name="child_sans_porc_0" value="1"> Sans porc</label>
            <label><input type="checkbox" name="child_vegan_0" value="1"> Sans viande</label>
          </span>
        </div>
      </div>

      <p>
        <button type="button" id="psc-add-child" class="psc-link-btn" aria-label="Ajouter un enfant">+ Ajouter un enfant</button>
      </p>
    </fieldset>

    <p>
      <label for="psc-req-msg">Message pour la mairie (facultatif)</label><br>
      <textarea id="psc-req-msg" name="req_message" rows="3" maxlength="1000"></textarea>
    </p>

    <fieldset>
      <legend>Mode de paiement</legend>
      <p class="psc-help">Comment réglerez-vous les factures de cantine et de garderie ?</p>
      <div class="psc-payment-choice">
        <label><input type="radio" name="payment_mode" value="autre" id="psc-pm-autre" checked> Chèque ou espèces</label>
        <label><input type="radio" name="payment_mode" value="prelevement" id="psc-pm-prelevement"> Prélèvement automatique (SEPA)</label>
      </div>

      <div id="psc-sepa-block" class="psc-sepa-block" hidden>
        <p class="psc-help">Le prélèvement est gratuit. Une fois votre inscription validée par la mairie, les factures seront prélevées automatiquement sur le compte que vous indiquez ici.</p>

        <div class="psc-sepa-creditor">
          <strong>Créancier :</strong> <?php echo esc_html(get_option('psc_billing_org_name', get_bloginfo('name'))); ?><br>
          <?php $psc_ics = get_option('psc_billing_org_ics', ''); if ($psc_ics): ?>
          <strong>Identifiant créancier SEPA (ICS) :</strong> <?php echo esc_html($psc_ics); ?>
          <?php endif; ?>
        </div>

        <p>
          <label for="psc-sepa-titulaire">Titulaire du compte à débiter</label><br>
          <input id="psc-sepa-titulaire" type="text" name="sepa_titulaire" maxlength="190" autocomplete="name">
        </p>
        <p>
          <label><input type="checkbox" id="psc-sepa-same-address"> Adresse du titulaire identique à l'adresse renseignée ci-dessus</label>
        </p>
        <p>
          <label for="psc-sepa-adresse">Adresse du titulaire</label><br>
          <input id="psc-sepa-adresse" type="text" name="sepa_adresse" maxlength="255" autocomplete="off">
        </p>
        <p style="display:flex;gap:12px;">
          <span style="flex:0 0 100px;">
            <label for="psc-sepa-cp">Code postal</label><br>
            <input id="psc-sepa-cp" type="text" name="sepa_code_postal" maxlength="10" autocomplete="off" style="width:100%">
          </span>
          <span style="flex:1;">
            <label for="psc-sepa-ville">Ville</label><br>
            <input id="psc-sepa-ville" type="text" name="sepa_ville" maxlength="100" autocomplete="off" style="width:100%">
          </span>
        </p>
        <p>
          <label for="psc-sepa-iban">IBAN</label><br>
          <input id="psc-sepa-iban" type="text" name="sepa_iban" maxlength="42" placeholder="FR76 XXXX XXXX XXXX XXXX XXXX XXX" autocomplete="off">
        </p>
        <p>
          <label for="psc-sepa-bic">BIC</label><br>
          <input id="psc-sepa-bic" type="text" name="sepa_bic" maxlength="11" placeholder="XXXXFRPPXXX" autocomplete="off">
        </p>

        <p><strong>Règlement concernant le prélèvement</strong></p>
        <div class="psc-reglement-box" tabindex="0">
          <p>Vous avez opté pour le mode de paiement par prélèvement, ce service est gratuit. Les montants dus au titre de la cantine, garderie seront prélevés automatiquement sur le compte que vous avez désigné dans les conditions suivantes :</p>
          <p>Le montant de la facture mensuelle sera prélevé à terme échu le 5 du mois suivant ou à défaut, le premier jour ouvrable suivant le 5.</p>
          <p>En cas de rejet du prélèvement, les frais bancaires correspondants seront à votre charge et seront imputés sur la facture suivante.</p>
          <p>Les rejets feront l'objet de rappels émis par la mairie et devront être réglés par chèque ou espèces dans les meilleurs délais. Sans régularisation de votre part dans les 15 jours suivant l'émission du rappel, le dossier sera automatiquement transmis au Trésor Public pour un recouvrement contentieux.</p>
          <p>En cas de changement de domiciliation bancaire, il sera nécessaire de remplir un nouveau mandat de prélèvement SEPA accompagné d'un nouveau RIB.</p>
          <p>Vous pouvez à tout moment décider de mettre fin au prélèvement automatique, sur simple demande écrite à la mairie. Le montant des factures échues sera alors à payer par chèque ou espèces dès leur réception.</p>
        </div>
        <p>
          <label>
            <input type="checkbox" name="sepa_reglement_accepted" id="psc-sepa-reglement-cb" value="1">
            J'ai pris connaissance du règlement concernant le prélèvement automatique et je l'approuve, ainsi que du mandat de prélèvement SEPA autorisant la mairie à débiter le compte ci-dessus.
            <span class="psc-req">*</span>
          </label>
        </p>
      </div>
    </fieldset>

    <fieldset>
      <legend>Règlement intérieur <span class="psc-req">*</span></legend>
      <div class="psc-reglement-box" tabindex="0">
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
      <p>
        <label>
          <input type="checkbox" name="reglement_accepted" value="1" required>
          J'ai pris connaissance du règlement intérieur des services périscolaires et je l'approuve dans sa totalité.
          <span class="psc-req">*</span>
        </label>
      </p>
    </fieldset>

    <p class="psc-rgpd">
      Les informations saisies sont transmises à la mairie pour le seul traitement
      de votre demande d'inscription au service périscolaire. Une demande non
      confirmée est automatiquement supprimée au bout de 7 jours. Vous disposez
      d'un droit d'accès et de rectification en contactant la mairie.
    </p>

    <p><button type="submit" class="psc-btn">Envoyer ma demande</button></p>
  </form>
</details>

<script>
(function () {
  var MAX   = 5;
  var next  = 1;
  var list  = document.getElementById('psc-children-list');
  var addBtn = document.getElementById('psc-add-child');

  var classeOptions = <?php echo json_encode(psc_classe_options()); ?>;

  function buildSelect(name, id) {
    var sel = document.createElement('select');
    sel.name = name;
    sel.id   = id;
    Object.keys(classeOptions).forEach(function (v) {
      var opt = document.createElement('option');
      opt.value       = v;
      opt.textContent = classeOptions[v];
      sel.appendChild(opt);
    });
    return sel;
  }

  function updateAddBtn() {
    addBtn.style.display = (list.children.length >= MAX) ? 'none' : '';
  }

  addBtn.addEventListener('click', function () {
    if (list.children.length >= MAX) return;

    var idx = next++;
    var n   = list.children.length + 1;

    var row = document.createElement('div');
    row.className        = 'psc-child-row';
    row.dataset.index    = idx;

    var lblP = document.createElement('label');
    lblP.className = 'screen-reader-text';
    lblP.setAttribute('for', 'psc-cp-' + idx);
    lblP.textContent = 'Prénom de l\'enfant ' + n;

    var inP = document.createElement('input');
    inP.type        = 'text';
    inP.id          = 'psc-cp-' + idx;
    inP.name        = 'child_prenom_' + idx;
    inP.placeholder = 'Prénom';
    inP.maxLength   = 190;

    var lblN = document.createElement('label');
    lblN.className = 'screen-reader-text';
    lblN.setAttribute('for', 'psc-cn-' + idx);
    lblN.textContent = 'Nom de l\'enfant ' + n;

    var inN = document.createElement('input');
    inN.type        = 'text';
    inN.id          = 'psc-cn-' + idx;
    inN.name        = 'child_nom_' + idx;
    inN.placeholder = 'Nom';
    inN.maxLength   = 190;

    var lblC = document.createElement('label');
    lblC.className = 'screen-reader-text';
    lblC.setAttribute('for', 'psc-cc-' + idx);
    lblC.textContent = 'Classe de l\'enfant ' + n;

    var sel = buildSelect('child_classe_' + idx, 'psc-cc-' + idx);

    var dietWrap = document.createElement('span');
    dietWrap.className = 'psc-diet-options';

    var lblPorc = document.createElement('label');
    var cbPorc  = document.createElement('input');
    cbPorc.type = 'checkbox';
    cbPorc.name = 'child_sans_porc_' + idx;
    cbPorc.value = '1';
    lblPorc.appendChild(cbPorc);
    lblPorc.appendChild(document.createTextNode(' Sans porc'));

    var lblVegan = document.createElement('label');
    var cbVegan  = document.createElement('input');
    cbVegan.type = 'checkbox';
    cbVegan.name = 'child_vegan_' + idx;
    cbVegan.value = '1';
    lblVegan.appendChild(cbVegan);
    lblVegan.appendChild(document.createTextNode(' Sans viande'));

    dietWrap.appendChild(lblPorc);
    dietWrap.appendChild(lblVegan);

    var removeBtn = document.createElement('button');
    removeBtn.type      = 'button';
    removeBtn.className = 'psc-child-remove psc-link-btn';
    removeBtn.setAttribute('aria-label', 'Supprimer cet enfant');
    removeBtn.textContent = '×';
    removeBtn.addEventListener('click', function () {
      row.parentNode.removeChild(row);
      updateAddBtn();
    });

    row.appendChild(lblP);
    row.appendChild(inP);
    row.appendChild(lblN);
    row.appendChild(inN);
    row.appendChild(lblC);
    row.appendChild(sel);
    row.appendChild(dietWrap);
    row.appendChild(removeBtn);

    list.appendChild(row);
    inP.focus();
    updateAddBtn();
  });
})();

(function () {
  var pmAutre       = document.getElementById('psc-pm-autre');
  var pmPrelevement = document.getElementById('psc-pm-prelevement');
  var sepaBlock      = document.getElementById('psc-sepa-block');
  var sepaRequired   = ['psc-sepa-iban', 'psc-sepa-bic', 'psc-sepa-titulaire', 'psc-sepa-reglement-cb'];

  function toggleSepa() {
    var show = pmPrelevement.checked;
    sepaBlock.hidden = !show;
    sepaRequired.forEach(function (id) {
      document.getElementById(id).required = show;
    });
  }
  pmAutre.addEventListener('change', toggleSepa);
  pmPrelevement.addEventListener('change', toggleSepa);
  toggleSepa();

  var sameAddress = document.getElementById('psc-sepa-same-address');
  sameAddress.addEventListener('change', function () {
    if (!sameAddress.checked) return;
    document.getElementById('psc-sepa-adresse').value = document.getElementById('psc-req-adresse').value;
    document.getElementById('psc-sepa-cp').value       = document.getElementById('psc-req-cp').value;
    document.getElementById('psc-sepa-ville').value    = document.getElementById('psc-req-ville').value;
  });
})();
</script>
