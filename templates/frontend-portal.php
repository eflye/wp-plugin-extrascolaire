<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal alignfull" data-testid="portal-root">

  <aside class="psc-portal-sidebar">
    <div class="psc-portal-section-label">Espace familles</div>

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
      <div class="psc-portal-account-label">Connecté avec</div>
      <div class="psc-portal-account-email" data-testid="account-email"><?php echo esc_html($parent->email); ?></div>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('psc_logout'); ?>
        <input type="hidden" name="action" value="psc_logout">
        <button type="submit" class="psc-portal-account-logout" data-testid="logout-button">Se déconnecter</button>
      </form>
    </div>
  </aside>

  <main class="psc-portal-main">
    <?php
    $psc_notices = array(
        'welcome'           => array('ok',  'Vous êtes connecté.'),
        'child_updated'     => array('ok',  'Informations de l\'enfant mises à jour.'),
        'child_added'       => array('ok',  'Enfant ajouté à votre compte.'),
        'child_invalid'     => array('err', 'Merci de renseigner le prénom et le nom.'),
        'child_limit'       => array('err', 'Nombre maximum d\'enfants atteint.'),
        'absence_cancelled' => array('ok',  'Absence signalée : la mairie a été prévenue, ces prestations ne seront pas facturées.'),
        'absence_locked'    => array('err', 'Ce jour n\'est plus modifiable en ligne (délai dépassé). Contactez la mairie.'),
        'absence_invalid'   => array('err', 'Impossible de signaler cette absence (jour déjà annulé ou invalide). Rechargez la page.'),

        'assurance_uploaded'      => array('ok',  'Justificatif d\'assurance scolaire enregistré.'),
        'assurance_invalid'       => array('err', 'Enfant introuvable. Rechargez la page.'),
        'assurance_upload_failed' => array('err', 'L\'envoi du fichier a échoué. Merci de réessayer.'),
        'assurance_too_large'     => array('err', 'Le fichier dépasse la taille maximale autorisée (5 Mo).'),
        'assurance_invalid_type'  => array('err', 'Format de fichier non accepté (PDF, JPG ou PNG uniquement).'),
        'assurance_required'      => array('err', 'Le justificatif d\'assurance scolaire est obligatoire pour ajouter un enfant.'),

        'profil_updated'               => array('ok',  'Vos informations ont été mises à jour.'),
        'profil_updated_email_pending' => array('ok',  'Informations mises à jour. Un e-mail de confirmation a été envoyé à votre nouvelle adresse : cliquez sur le lien qu\'il contient pour l\'activer.'),
        'profil_error'                 => array('err', 'Certaines informations n\'ont pas pu être enregistrées. Vérifiez votre saisie.'),
        'email_taken'                  => array('err', 'Cette adresse e-mail est déjà utilisée par une autre famille.'),
        'email_changed'                => array('ok',  'Votre nouvelle adresse e-mail est confirmée : utilisez-la désormais pour vous connecter.'),
        'email_change_cancelled'       => array('ok',  'Changement d\'adresse e-mail annulé.'),
        'bad_email_token'              => array('err', 'Ce lien de confirmation n\'est pas valide.'),
        'expired_email_token'          => array('err', 'Ce lien de confirmation a expiré. Refaites une demande depuis votre profil.'),
    );
    // Confirmations : popin auto-masquée (cf. assets/js/frontend.js).
    // Erreurs à corriger : bandeau classique, le temps de lire et d'agir.
    $psc_toast_messages = array('welcome', 'child_updated', 'child_added', 'absence_cancelled', 'profil_updated', 'assurance_uploaded');
    if (!empty($psc_msg) && isset($psc_notices[$psc_msg])):
        list($type, $text) = $psc_notices[$psc_msg];
        $is_toast = in_array($psc_msg, $psc_toast_messages, true);
    ?>
      <?php if ($is_toast): ?>
      <div class="psc-notice psc-notice-<?php echo esc_attr($type); ?> psc-toast" data-testid="notice-<?php echo esc_attr($psc_msg); ?>">
        <span class="psc-toast-text"><?php echo esc_html($text); ?></span>
        <button type="button" class="psc-toast-close" aria-label="Fermer">&times;</button>
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
  </main>
</div>
