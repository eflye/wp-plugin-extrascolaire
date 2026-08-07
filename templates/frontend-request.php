<?php if (!defined('ABSPATH')) exit; ?>

<details class="psc-request-block" <?php echo in_array($psc_msg, array('need_child', 'bad_email'), true) ? 'open' : ''; ?>>
  <summary><strong>Première inscription ?</strong> Faire une demande auprès de la mairie</summary>

  <p class="psc-help">
    Si votre famille n'est pas encore connue du service périscolaire, remplissez
    ce formulaire. Vous recevrez d'abord un e-mail pour confirmer votre adresse,
    puis la mairie examinera votre demande et vous répondra.
  </p>

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
    </fieldset>

    <fieldset>
      <legend>Votre ou vos enfants <span class="psc-req">*</span></legend>
      <p class="psc-help">Renseignez au moins un enfant.</p>
      <?php for ($i = 0; $i < 3; $i++): ?>
        <div class="psc-child-row">
          <label class="screen-reader-text" for="psc-cp-<?php echo (int) $i; ?>">Prénom de l'enfant <?php echo (int) $i + 1; ?></label>
          <input id="psc-cp-<?php echo (int) $i; ?>" type="text" name="child_prenom_<?php echo (int) $i; ?>" placeholder="Prénom" maxlength="190">

          <label class="screen-reader-text" for="psc-cn-<?php echo (int) $i; ?>">Nom de l'enfant <?php echo (int) $i + 1; ?></label>
          <input id="psc-cn-<?php echo (int) $i; ?>" type="text" name="child_nom_<?php echo (int) $i; ?>" placeholder="Nom" maxlength="190">

          <label class="screen-reader-text" for="psc-cc-<?php echo (int) $i; ?>">Classe de l'enfant <?php echo (int) $i + 1; ?></label>
          <input id="psc-cc-<?php echo (int) $i; ?>" type="text" name="child_classe_<?php echo (int) $i; ?>" placeholder="Classe" maxlength="100">
        </div>
      <?php endfor; ?>
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
