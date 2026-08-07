<?php if (!defined('ABSPATH')) exit; ?>

<details class="psc-card psc-request-block" <?php echo in_array($psc_msg, array('need_child', 'bad_email'), true) ? 'open' : ''; ?>>
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
    row.appendChild(removeBtn);

    list.appendChild(row);
    inP.focus();
    updateAddBtn();
  });
})();
</script>
