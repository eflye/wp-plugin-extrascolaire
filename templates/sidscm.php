<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-sidscm" id="psc-sidscm-root" data-testid="sidscm-root">

  <div class="psc-sidscm-lock-screen" id="psc-sidscm-lock" data-testid="sidscm-lock">
    <div class="psc-sidscm-lock-inner">

      <div class="psc-sidscm-lock-masthead">
        <div class="psc-sidscm-lock-brand-eyebrow"><?php esc_html_e('Service périscolaire', 'periscolaire-registration'); ?></div>
        <div class="psc-sidscm-lock-nav">
          <a href="<?php echo esc_url(Psc_Mailer::form_page_url()); ?>" class="psc-sidscm-lock-nav-link" data-testid="sidscm-nav-familles"><?php esc_html_e('Espace familles', 'periscolaire-registration'); ?></a>
          <div class="psc-sidscm-lock-nav-active" data-testid="sidscm-nav-intervenants"><?php esc_html_e('Espace intervenants', 'periscolaire-registration'); ?></div>
        </div>
      </div>
      <div class="psc-sidscm-lock-hairline"><span class="line"></span><span class="dot"></span><span class="line"></span></div>

      <div class="psc-sidscm-lock-card-wrap">
        <div class="psc-sidscm-lock-card">
          <div class="psc-sidscm-eyebrow"><?php esc_html_e('SIDSCM · Montgeroult', 'periscolaire-registration'); ?></div>
          <h1 class="psc-sidscm-lock-title"><?php esc_html_e('Accès intervenants', 'periscolaire-registration'); ?></h1>
          <p class="psc-sidscm-lock-intro"><?php esc_html_e('Saisissez le code communiqué par la mairie pour consulter les listes des enfants attendus.', 'periscolaire-registration'); ?></p>
          <form id="psc-sidscm-code-form" data-testid="sidscm-code-form">
            <label class="screen-reader-text" for="psc-sidscm-code-input"><?php esc_html_e('Code d\'accès', 'periscolaire-registration'); ?></label>
            <input type="text" id="psc-sidscm-code-input" class="psc-sidscm-code-input" placeholder="<?php esc_attr_e('Code d\'accès', 'periscolaire-registration'); ?>" autocomplete="off" data-testid="sidscm-code-input">
            <p class="psc-sidscm-code-error" id="psc-sidscm-code-error" hidden data-testid="sidscm-code-error"><?php esc_html_e('Code incorrect. Réessayez.', 'periscolaire-registration'); ?></p>
            <button type="submit" class="psc-sidscm-code-submit" data-testid="sidscm-code-submit"><?php esc_html_e('Accéder à la liste', 'periscolaire-registration'); ?></button>
          </form>
        </div>
      </div>

    </div>
  </div>

  <div class="psc-sidscm-app" id="psc-sidscm-app" hidden data-testid="sidscm-app">
    <div class="psc-sidscm-header">
      <div>
        <div class="psc-sidscm-eyebrow psc-sidscm-eyebrow--dark"><?php esc_html_e('SIDSCM · Montgeroult', 'periscolaire-registration'); ?></div>
        <div class="psc-sidscm-title"><?php esc_html_e('Listes des enfants attendus', 'periscolaire-registration'); ?></div>
        <div class="psc-sidscm-today" data-testid="sidscm-today"><?php echo esc_html(date_i18n('l j F Y', current_time('timestamp'))); ?></div>
      </div>
      <div class="psc-sidscm-header-actions">
        <button type="button" class="psc-sidscm-mode-btn" id="psc-sidscm-mode-day" data-testid="sidscm-mode-day"><?php esc_html_e('Jour', 'periscolaire-registration'); ?></button>
        <button type="button" class="psc-sidscm-mode-btn" id="psc-sidscm-mode-week" data-testid="sidscm-mode-week"><?php esc_html_e('Semaine', 'periscolaire-registration'); ?></button>
        <button type="button" class="psc-sidscm-lock-btn" id="psc-sidscm-lock-btn" data-testid="sidscm-lock-button"><?php esc_html_e('Verrouiller', 'periscolaire-registration'); ?></button>
      </div>
    </div>

    <div class="psc-sidscm-days" id="psc-sidscm-days" data-testid="sidscm-days"></div>

    <div class="psc-sidscm-services" id="psc-sidscm-services" data-testid="sidscm-services"></div>

    <div class="psc-sidscm-content" id="psc-sidscm-content" data-testid="sidscm-content"></div>
  </div>

</div>
