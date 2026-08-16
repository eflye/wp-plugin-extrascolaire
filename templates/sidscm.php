<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-sidscm" id="psc-sidscm-root" data-testid="sidscm-root">

  <div class="psc-sidscm-lock-screen" id="psc-sidscm-lock" data-testid="sidscm-lock">
    <div class="psc-sidscm-lock-card">
      <div class="psc-sidscm-eyebrow">SIDSCM · Montgeroult</div>
      <h1 class="psc-sidscm-lock-title">Accès intervenantes</h1>
      <p class="psc-sidscm-lock-intro">Saisissez le code communiqué par la mairie pour consulter les listes des enfants attendus.</p>
      <form id="psc-sidscm-code-form" data-testid="sidscm-code-form">
        <label class="screen-reader-text" for="psc-sidscm-code-input">Code d'accès</label>
        <input type="text" id="psc-sidscm-code-input" class="psc-sidscm-code-input" placeholder="Code d'accès" autocomplete="off" data-testid="sidscm-code-input">
        <p class="psc-sidscm-code-error" id="psc-sidscm-code-error" hidden data-testid="sidscm-code-error">Code incorrect. Réessayez.</p>
        <button type="submit" class="psc-sidscm-code-submit" data-testid="sidscm-code-submit">Accéder à la liste</button>
      </form>
    </div>
  </div>

  <div class="psc-sidscm-app" id="psc-sidscm-app" hidden data-testid="sidscm-app">
    <div class="psc-sidscm-header">
      <div>
        <div class="psc-sidscm-eyebrow psc-sidscm-eyebrow--dark">SIDSCM · Montgeroult</div>
        <div class="psc-sidscm-title">Listes des enfants attendus</div>
        <div class="psc-sidscm-today" data-testid="sidscm-today"><?php echo esc_html(date_i18n('l j F Y', current_time('timestamp'))); ?></div>
      </div>
      <div class="psc-sidscm-header-actions">
        <button type="button" class="psc-sidscm-mode-btn" id="psc-sidscm-mode-day" data-testid="sidscm-mode-day">Jour</button>
        <button type="button" class="psc-sidscm-mode-btn" id="psc-sidscm-mode-week" data-testid="sidscm-mode-week">Semaine</button>
        <button type="button" class="psc-sidscm-lock-btn" id="psc-sidscm-lock-btn" data-testid="sidscm-lock-button">Verrouiller</button>
      </div>
    </div>

    <div class="psc-sidscm-days" id="psc-sidscm-days" data-testid="sidscm-days"></div>

    <div class="psc-sidscm-services" id="psc-sidscm-services" data-testid="sidscm-services"></div>

    <div class="psc-sidscm-content" id="psc-sidscm-content" data-testid="sidscm-content"></div>
  </div>

</div>
