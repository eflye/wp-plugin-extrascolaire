<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal alignfull" data-testid="portal-root">

  <?php if (empty($parent->onboarding_seen_at)): ?>
  <div class="psc-portal-modal-overlay psc-onboarding-overlay" id="psc-onboarding-overlay" data-testid="onboarding-overlay">
    <div class="psc-portal-modal psc-onboarding-modal" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Découverte de votre espace', 'periscolaire-registration'); ?>" tabindex="-1">
      <div class="psc-onboarding-dots" data-testid="onboarding-dots">
        <span class="psc-onboarding-dot is-active"></span>
        <span class="psc-onboarding-dot"></span>
        <span class="psc-onboarding-dot"></span>
        <span class="psc-onboarding-dot"></span>
        <span class="psc-onboarding-dot"></span>
      </div>

      <div class="psc-onboarding-step is-active" data-step="1">
        <p class="psc-portal-modal-title"><?php esc_html_e('Bienvenue dans votre espace famille', 'periscolaire-registration'); ?></p>
        <p class="psc-onboarding-text"><?php esc_html_e("Cet espace vous permet de gérer au quotidien la garderie, la cantine et les informations de vos enfants, sans avoir à contacter la mairie. Ce petit tour en 5 étapes vous montre l'essentiel.", 'periscolaire-registration'); ?></p>
      </div>

      <div class="psc-onboarding-step" data-step="2">
        <p class="psc-portal-modal-title"><?php esc_html_e('Cantine & Garderie', 'periscolaire-registration'); ?></p>
        <p class="psc-onboarding-text"><?php esc_html_e('Cochez les jours de garderie matin, cantine et garderie soir directement dans le calendrier : chaque case est enregistrée immédiatement, sans bouton « Envoyer » à chercher. Vous pouvez aussi annuler rapidement une prestation depuis le tableau de bord si un jour ne convient plus.', 'periscolaire-registration'); ?></p>
      </div>

      <div class="psc-onboarding-step" data-step="3">
        <p class="psc-portal-modal-title"><?php esc_html_e('Mes enfants', 'periscolaire-registration'); ?></p>
        <p class="psc-onboarding-text"><?php esc_html_e("Ajoutez un enfant, déposez son justificatif d'assurance scolaire, et déclarez les personnes autorisées à venir le récupérer au départ de la", 'periscolaire-registration'); ?> <strong><?php esc_html_e('garderie du soir', 'periscolaire-registration'); ?></strong> <?php esc_html_e(' — vous et l\'autre parent y figurez toujours automatiquement.', 'periscolaire-registration'); ?></p>
      </div>

      <div class="psc-onboarding-step" data-step="4">
        <p class="psc-portal-modal-title"><?php esc_html_e('Mon profil', 'periscolaire-registration'); ?></p>
        <p class="psc-onboarding-text"><?php esc_html_e('Tenez vos coordonnées à jour, et ajoutez un second parent si besoin : une fois renseigné, il pourra se connecter à cet espace avec sa propre adresse e-mail et agir exactement comme vous — aucune action supplémentaire à faire de votre côté.', 'periscolaire-registration'); ?></p>
      </div>

      <div class="psc-onboarding-step" data-step="5">
        <p class="psc-portal-modal-title"><?php esc_html_e('Mes factures & Documents', 'periscolaire-registration'); ?></p>
        <p class="psc-onboarding-text"><?php esc_html_e('Retrouvez vos factures mensuelles et leur statut de paiement, ainsi que le règlement intérieur et les autres documents mis à disposition par la mairie. Vous êtes prêt·e — bonne visite !', 'periscolaire-registration'); ?></p>
      </div>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="psc-onboarding-dismiss-form">
        <?php wp_nonce_field('psc_parent_dismiss_onboarding'); psc_parent_nonce_field('psc_parent_dismiss_onboarding'); ?>
        <input type="hidden" name="action" value="psc_parent_dismiss_onboarding">
      </form>

      <div class="psc-portal-modal-actions psc-onboarding-actions">
        <button type="button" class="psc-portal-btn-outline-ink" id="psc-onboarding-skip" data-testid="onboarding-skip"><?php esc_html_e('Passer', 'periscolaire-registration'); ?></button>
        <div class="psc-onboarding-nav-right">
          <button type="button" class="psc-portal-btn-outline-ink" id="psc-onboarding-prev" data-testid="onboarding-prev" hidden><?php esc_html_e('Précédent', 'periscolaire-registration'); ?></button>
          <button type="button" class="psc-portal-btn-gold" id="psc-onboarding-next" data-testid="onboarding-next"><?php esc_html_e('Suivant', 'periscolaire-registration'); ?></button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <aside class="psc-portal-sidebar">
    <div class="psc-portal-section-label"><?php esc_html_e('Espace familles', 'periscolaire-registration'); ?></div>

    <nav class="psc-portal-nav" data-testid="portal-nav">
      <?php foreach ($psc_portal_tabs as $tab_key => $tab): ?>
      <a href="<?php echo esc_url($tab['url']); ?>"
         class="psc-portal-nav-btn<?php echo $tab_key === $active_tab ? ' is-active' : ''; ?>"
         data-portal-tab="<?php echo esc_attr($tab_key); ?>"
         data-testid="portal-nav-<?php echo esc_attr($tab_key); ?>">
        <?php echo $tab['icon']; ?>
        <?php echo esc_html($tab['label']); ?>
      </a>
      <?php endforeach; ?>
    </nav>

    <div class="psc-portal-account" data-testid="account-bar">
      <div class="psc-portal-account-label"><?php esc_html_e('Connecté avec', 'periscolaire-registration'); ?></div>
      <div class="psc-portal-account-email" data-testid="account-email"><?php echo esc_html($parent->email); ?></div>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('psc_logout'); ?>
        <input type="hidden" name="action" value="psc_logout">
        <button type="submit" class="psc-portal-account-logout" data-testid="logout-button"><?php esc_html_e('Se déconnecter', 'periscolaire-registration'); ?></button>
      </form>
    </div>
  </aside>

  <main class="psc-portal-main">
    <?php
    $psc_notices = array(
        'welcome'           => array('ok',  __('Vous êtes connecté.', 'periscolaire-registration')),
        'child_updated'     => array('ok',  __("Informations de l'enfant mises à jour.", 'periscolaire-registration')),
        'child_added'       => array('ok',  __('Enfant ajouté à votre compte.', 'periscolaire-registration')),
        'child_invalid'     => array('err', __('Merci de renseigner le prénom et le nom.', 'periscolaire-registration')),
        'child_limit'       => array('err', __("Nombre maximum d'enfants atteint.", 'periscolaire-registration')),
        'absence_cancelled' => array('ok',  __('Absence signalée : la mairie a été prévenue, ces prestations ne seront pas facturées.', 'periscolaire-registration')),
        'absence_invalid'   => array('err', __("Impossible d'annuler ces prestations (délai dépassé, déjà annulées ou sélection invalide). Rechargez la page.", 'periscolaire-registration')),

        'assurance_uploaded'      => array('ok',  __("Justificatif d'assurance scolaire enregistré.", 'periscolaire-registration')),
        'assurance_invalid'       => array('err', __('Enfant introuvable. Rechargez la page.', 'periscolaire-registration')),
        'assurance_upload_failed' => array('err', __("L'envoi du fichier a échoué. Merci de réessayer.", 'periscolaire-registration')),
        'assurance_too_large'     => array('err', __('Le fichier dépasse la taille maximale autorisée (1 Mo).', 'periscolaire-registration')),
        'assurance_invalid_type'  => array('err', __('Format de fichier non accepté (PDF, JPG ou PNG uniquement).', 'periscolaire-registration')),
        'assurance_required'      => array('err', __("Le justificatif d'assurance scolaire est obligatoire pour ajouter un enfant.", 'periscolaire-registration')),

        'profil_updated'               => array('ok',  __('Vos informations ont été mises à jour.', 'periscolaire-registration')),
        'profil_updated_email_pending' => array('ok',  __("Informations mises à jour. Un e-mail de confirmation a été envoyé à votre nouvelle adresse : cliquez sur le lien qu'il contient pour l'activer.", 'periscolaire-registration')),
        'profil_error'                 => array('err', __("Certaines informations n'ont pas pu être enregistrées. Vérifiez votre saisie.", 'periscolaire-registration')),
        'email_taken'                  => array('err', __('Cette adresse e-mail est déjà utilisée par une autre famille.', 'periscolaire-registration')),
        'email_changed'                => array('ok',  __('Votre nouvelle adresse e-mail est confirmée : utilisez-la désormais pour vous connecter.', 'periscolaire-registration')),
        'email_change_cancelled'       => array('ok',  __("Changement d'adresse e-mail annulé.", 'periscolaire-registration')),
        'bad_email_token'              => array('err', __("Ce lien de confirmation n'est pas valide.", 'periscolaire-registration')),
        'expired_email_token'          => array('err', __('Ce lien de confirmation a expiré. Refaites une demande depuis votre profil.', 'periscolaire-registration')),

        'reinscription_confirmee' => array('ok',  __('Réinscription enregistrée. Merci !', 'periscolaire-registration')),
        'reinscription_invalid'   => array('err', __('La fenêtre de réinscription est fermée ou votre sélection est invalide.', 'periscolaire-registration')),
        'reinscription_required'  => array('err', __("Merci de confirmer le règlement intérieur et de fournir un justificatif d'assurance pour chaque enfant réinscrit.", 'periscolaire-registration')),

        'pickup_added'   => array('ok',  __('Personne autorisée ajoutée (départ de garderie du soir).', 'periscolaire-registration')),
        'pickup_updated' => array('ok',  __('Personne autorisée modifiée.', 'periscolaire-registration')),
        'pickup_removed' => array('ok',  __('Personne retirée de la liste des personnes autorisées.', 'periscolaire-registration')),
        'pickup_invalid' => array('err', __("Nom, prénom et téléphone sont obligatoires, et l'enfant doit être le vôtre.", 'periscolaire-registration')),

        'second_parent_updated'     => array('ok',  __('Second parent enregistré.', 'periscolaire-registration')),
        'second_parent_removed'     => array('ok',  __('Second parent retiré.', 'periscolaire-registration')),
        'second_parent_bad_email'   => array('err', __("L'adresse e-mail du second parent n'est pas valide.", 'periscolaire-registration')),
        'second_parent_bad_phone'   => array('err', __("Le numéro de téléphone du second parent n'est pas valide.", 'periscolaire-registration')),
        'second_parent_email_taken' => array('err', __('Cette adresse e-mail est déjà utilisée par un autre foyer.', 'periscolaire-registration')),

        'household_pickup_added'   => array('ok',  __('Personne autorisée ajoutée (départ de garderie du soir).', 'periscolaire-registration')),
        'household_pickup_removed' => array('ok',  __('Personne retirée de la liste des personnes autorisées.', 'periscolaire-registration')),
        'household_pickup_invalid' => array('err', __('Nom, prénom et téléphone sont obligatoires.', 'periscolaire-registration')),
    );
    // Confirmations : popin auto-masquée (cf. assets/js/frontend.js).
    // Erreurs à corriger : bandeau classique, le temps de lire et d'agir.
    $psc_toast_messages = array('welcome', 'child_updated', 'child_added', 'absence_cancelled', 'profil_updated', 'assurance_uploaded');
    if (!empty($psc_msg) && isset($psc_notices[$psc_msg])):
        list($type, $text) = $psc_notices[$psc_msg];
        $is_toast = in_array($psc_msg, $psc_toast_messages, true);
    ?>
      <?php if ($is_toast): ?>
      <div class="psc-notice psc-notice-<?php echo esc_attr($type); ?> psc-toast" role="status" data-testid="notice-<?php echo esc_attr($psc_msg); ?>">
        <span class="psc-toast-text"><?php echo esc_html($text); ?></span>
        <button type="button" class="psc-toast-close" aria-label="<?php esc_attr_e('Fermer', 'periscolaire-registration'); ?>">&times;</button>
      </div>
      <?php else: ?>
      <p class="psc-notice psc-notice-<?php echo esc_attr($type); ?>" data-testid="notice-<?php echo esc_attr($psc_msg); ?>"><?php echo esc_html($text); ?></p>
      <?php endif; ?>
    <?php endif; ?>

    <section class="psc-portal-section<?php echo $active_tab === 'dashboard' ? ' is-active' : ''; ?>" data-portal-section="dashboard" data-testid="portal-section-dashboard">
      <?php include PSC_PATH . 'templates/portal-dashboard.php'; ?>
    </section>

    <section class="psc-portal-section<?php echo $active_tab === 'cantine' ? ' is-active' : ''; ?>" data-portal-section="cantine" data-testid="portal-section-cantine">
      <?php include PSC_PATH . 'templates/portal-cantine.php'; ?>
    </section>

    <section class="psc-portal-section<?php echo $active_tab === 'menu' ? ' is-active' : ''; ?>" data-portal-section="menu" data-testid="portal-section-menu">
      <?php include PSC_PATH . 'templates/portal-menu.php'; ?>
    </section>

    <section class="psc-portal-section<?php echo $active_tab === 'enfants' ? ' is-active' : ''; ?>" data-portal-section="enfants" data-testid="portal-section-enfants">
      <?php include PSC_PATH . 'templates/portal-enfants.php'; ?>
    </section>

    <section class="psc-portal-section<?php echo $active_tab === 'factures' ? ' is-active' : ''; ?>" data-portal-section="factures" data-testid="portal-section-factures">
      <?php include PSC_PATH . 'templates/portal-factures.php'; ?>
    </section>

    <section class="psc-portal-section<?php echo $active_tab === 'profil' ? ' is-active' : ''; ?>" data-portal-section="profil" data-testid="portal-section-profil">
      <?php include PSC_PATH . 'templates/portal-profil.php'; ?>
    </section>

    <section class="psc-portal-section<?php echo $active_tab === 'documents' ? ' is-active' : ''; ?>" data-portal-section="documents" data-testid="portal-section-documents">
      <?php include PSC_PATH . 'templates/portal-documents.php'; ?>
    </section>

    <?php if (isset($psc_portal_tabs['reinscription'])): ?>
    <section class="psc-portal-section<?php echo $active_tab === 'reinscription' ? ' is-active' : ''; ?>" data-portal-section="reinscription" data-testid="portal-section-reinscription">
      <?php include PSC_PATH . 'templates/portal-reinscription.php'; ?>
    </section>
    <?php endif; ?>
  </main>
</div>
