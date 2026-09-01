<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-guest alignfull" data-testid="guest-root">
  <div class="psc-guest-inner">

    <?php include PSC_PATH . 'templates/guest-menu.php'; ?>

    <?php
    $psc_notices = array(
        'link_sent'     => array('ok', __('Si cette adresse est enregistrée auprès de la mairie, un lien de connexion vient d\'être envoyé. Pensez à vérifier vos courriers indésirables.', 'periscolaire-registration')),
        'bad_email'     => array('err', __('Merci de saisir une adresse e-mail valide.', 'periscolaire-registration')),
        'bad_token'     => array('err', __('Ce lien n\'est pas valide. Demandez-en un nouveau ci-dessous.', 'periscolaire-registration')),
        'expired_token' => array('err', __('Ce lien a expiré. Demandez-en un nouveau ci-dessous.', 'periscolaire-registration')),
        'logged_out'    => array('ok', __('Vous êtes déconnecté.', 'periscolaire-registration')),
        'request_sent'  => array('ok', __('Votre demande a bien été prise en compte. Un e-mail de confirmation vient de vous être envoyé : cliquez sur le lien qu\'il contient pour la transmettre à la mairie.', 'periscolaire-registration')),
        'verified'      => array('ok', __('Merci, votre adresse est confirmée. Votre demande a été transmise à la mairie, qui vous répondra par e-mail.', 'periscolaire-registration')),
        'verified_auto' => array('ok', __('Merci, votre adresse est confirmée. Votre espace famille est prêt : vous allez recevoir votre lien d\'accès par e-mail.', 'periscolaire-registration')),
        'bad_verify'    => array('err', __('Ce lien de confirmation n\'est pas valide.', 'periscolaire-registration')),
        'expired_verify'=> array('err', __('Ce lien de confirmation a expiré. Vous pouvez déposer une nouvelle demande.', 'periscolaire-registration')),
        'coordonnees_incomplete'  => array('err', __('Merci de renseigner tous les champs (prénom, nom, téléphone, adresse, code postal, ville).', 'periscolaire-registration')),
        'need_child'    => array('err', __('Merci d\'indiquer au moins un enfant (nom et prénom).', 'periscolaire-registration')),
        'child_incomplete' => array('err', __('Merci de renseigner tous les champs de chaque enfant (prénom, nom, classe, date de naissance).', 'periscolaire-registration')),
        'assurance_required'      => array('err', __('Merci de joindre le justificatif d\'assurance scolaire de chaque enfant déclaré.', 'periscolaire-registration')),
        'assurance_too_large'     => array('err', __('Un des justificatifs d\'assurance dépasse la taille maximale autorisée (1 Mo).', 'periscolaire-registration')),
        'assurance_invalid_type'  => array('err', __('Format de justificatif non accepté (PDF, JPG ou PNG uniquement).', 'periscolaire-registration')),
        'reglement_required'      => array('err', __('Merci de prendre connaissance du règlement intérieur et de cocher la case d\'approbation.', 'periscolaire-registration')),
        'sepa_reglement_required' => array('err', __('Merci de prendre connaissance du règlement concernant le prélèvement et de cocher la case d\'approbation.', 'periscolaire-registration')),
        'sepa_missing'            => array('err', __('Merci de renseigner le titulaire du compte à débiter.', 'periscolaire-registration')),
        'bad_iban'                => array('err', __('L\'IBAN saisi n\'est pas valide. Vérifiez sa saisie.', 'periscolaire-registration')),
        'bad_bic'                 => array('err', __('Le BIC saisi n\'est pas valide. Vérifiez sa saisie.', 'periscolaire-registration')),
        'second_parent_bad_email'   => array('err', __('L\'adresse e-mail du second parent n\'est pas valide.', 'periscolaire-registration')),
        'second_parent_bad_phone'   => array('err', __('Le numéro de téléphone du second parent n\'est pas valide.', 'periscolaire-registration')),
        'second_parent_email_taken' => array('err', __('Cette adresse e-mail est déjà utilisée par un autre foyer.', 'periscolaire-registration')),
    );
    // Confirmations : popin auto-masquée (cf. assets/js/frontend.js).
    // Erreurs de connexion : bandeau classique en haut de page.
    // Erreurs du wizard d'inscription (need_child, bad_iban, ...) :
    // affichées DANS le wizard lui-même (cf. templates/guest-request.php)
    // plutôt qu'ici, tout en haut — sinon le message n'a aucun lien visuel
    // avec l'étape qui vient de s'ouvrir automatiquement pour le corriger,
    // cf. Psc_Frontend::wizard_error_context().
    $psc_toast_messages = array('link_sent', 'logged_out', 'bad_token', 'expired_token', 'request_sent');
    $psc_wizard_messages = array(
        'coordonnees_incomplete',
        'need_child', 'child_incomplete', 'assurance_required', 'assurance_too_large', 'assurance_invalid_type',
        'reglement_required', 'sepa_reglement_required',
        'sepa_missing', 'bad_iban', 'bad_bic',
        'second_parent_bad_email', 'second_parent_bad_phone', 'second_parent_email_taken',
    );
    if (!empty($psc_msg) && isset($psc_notices[$psc_msg]) && !in_array($psc_msg, $psc_wizard_messages, true)):
        list($type, $text) = $psc_notices[$psc_msg];
        $is_toast = in_array($psc_msg, $psc_toast_messages, true);
    ?>
      <?php if ($is_toast): ?>
      <div class="psc-notice psc-notice-<?php echo esc_attr($type); ?> psc-toast" data-testid="notice-<?php echo esc_attr($psc_msg); ?>">
        <span class="psc-toast-text"><?php echo esc_html($text); ?></span>
        <button type="button" class="psc-toast-close" aria-label="<?php esc_attr_e('Fermer', 'periscolaire-registration'); ?>">&times;</button>
      </div>
      <?php else: ?>
      <p class="psc-notice psc-notice-<?php echo esc_attr($type); ?>" data-testid="notice-<?php echo esc_attr($psc_msg); ?>"><?php echo esc_html($text); ?></p>
      <?php endif; ?>
    <?php endif; ?>

    <?php include PSC_PATH . 'templates/guest-login.php'; ?>

    <div class="psc-guest-divider">
      <div class="psc-guest-divider-rule"></div>
      <div class="psc-guest-divider-label"><?php esc_html_e('Nouvelle famille', 'periscolaire-registration'); ?></div>
      <div class="psc-guest-divider-rule"></div>
    </div>

    <?php include PSC_PATH . 'templates/guest-request.php'; ?>

  </div>
</div>
