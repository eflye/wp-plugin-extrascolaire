<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow">Bonjour</div>
<h1 class="psc-portal-h1 psc-portal-h1--dashboard" data-testid="dashboard-title"><?php echo esc_html($psc_portal_dashboard['title']); ?></h1>
<p class="psc-portal-subtitle">Voici l'essentiel de votre espace périscolaire.</p>

<div class="psc-portal-cards">
  <div class="psc-portal-card">
    <div class="psc-portal-card-label">Cette période</div>
    <div class="psc-portal-card-value" data-testid="dashboard-days"><?php echo esc_html($psc_portal_dashboard['days_label']); ?></div>
    <div class="psc-portal-card-sub"><?php echo esc_html($psc_portal_dashboard['amount_label']); ?> € déclarés</div>
  </div>
  <div class="psc-portal-card">
    <div class="psc-portal-card-label">Prochaine facture</div>
    <?php if ($psc_portal_dashboard['next_invoice']): ?>
    <div class="psc-portal-card-value" data-testid="dashboard-next-invoice"><?php echo esc_html($psc_portal_dashboard['next_invoice']['mois_label']); ?></div>
    <div class="psc-portal-card-sub"><?php echo esc_html($psc_portal_dashboard['next_invoice']['status_label']); ?></div>
    <?php else: ?>
    <div class="psc-portal-card-value psc-portal-card-value--empty" style="font-size:17px;color:#8A837A;">Aucune</div>
    <div class="psc-portal-card-sub">Rien à régler pour le moment</div>
    <?php endif; ?>
  </div>
  <div class="psc-portal-card psc-portal-card--gold">
    <div class="psc-portal-card-label">Accès rapide</div>
    <a href="<?php echo esc_url($psc_portal_tabs['cantine']['url']); ?>" class="psc-portal-btn-ink" data-portal-tab-link="cantine" style="display:block;text-align:center;text-decoration:none;">Déclarer un jour</a>
    <a href="<?php echo esc_url($psc_portal_tabs['enfants']['url']); ?>" class="psc-portal-btn-outline-ink" data-portal-tab-link="enfants" style="display:block;text-align:center;text-decoration:none;">Ajouter un enfant</a>
    <button type="button" id="psc-absence-trigger" class="psc-portal-btn-outline-ink" data-testid="absence-trigger" style="width:100%;">Annulation / signalement d'absence</button>
  </div>
</div>

<div id="psc-absence-modal" class="psc-portal-modal-overlay" hidden data-testid="absence-modal">
  <div class="psc-portal-modal">
    <h3 class="psc-portal-modal-title">Signaler une absence</h3>

    <?php if (empty($psc_portal_absence_days)): ?>
      <p class="psc-portal-dash-menu-empty">Aucune prestation à venir à annuler pour le moment.</p>
      <div class="psc-portal-modal-actions">
        <button type="button" class="psc-portal-btn-outline-ink" data-absence-close>Fermer</button>
      </div>
    <?php else: ?>
      <p class="psc-portal-dash-menu-empty" style="margin:0 0 16px;">Toutes les prestations déjà cochées du jour choisi seront annulées pour cet enfant. Seuls les jours encore modifiables apparaissent ci-dessous.</p>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-testid="absence-form">
        <?php wp_nonce_field('psc_cancel_absence'); ?>
        <input type="hidden" name="action" value="psc_cancel_absence">

        <label class="psc-portal-field-label" for="psc-absence-child">Enfant</label>
        <select id="psc-absence-child" name="child_id" class="psc-portal-field-underline" data-testid="absence-child-select">
          <?php foreach ($psc_portal_absence_days as $child_id => $child_data): ?>
          <option value="<?php echo esc_attr($child_id); ?>"><?php echo esc_html($child_data['name']); ?></option>
          <?php endforeach; ?>
        </select>

        <label class="psc-portal-field-label" for="psc-absence-date" style="margin-top:16px;">Jour</label>
        <select id="psc-absence-date" name="date" class="psc-portal-field-underline" data-testid="absence-date-select"></select>

        <div class="psc-portal-modal-actions">
          <button type="button" class="psc-portal-btn-outline-ink" data-absence-close>Annuler</button>
          <button type="submit" class="psc-portal-btn-gold" data-testid="absence-submit">Confirmer l'absence</button>
        </div>
      </form>
      <script type="application/json" id="psc-absence-data"><?php echo wp_json_encode($psc_portal_absence_days, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <?php endif; ?>
  </div>
</div>

<div class="psc-portal-section-heading">
  <span class="psc-portal-numeral">I.</span>
  <span class="psc-portal-heading-label">Menu de la semaine en cours</span>
</div>
<div class="psc-portal-dash-menu">
  <?php if (!empty($psc_portal_dashboard['menu'])): ?>
    <?php foreach ($psc_portal_dashboard['menu'] as $m): ?>
    <div>
      <div class="psc-portal-dash-menu-day"><?php echo esc_html($m['day']); ?></div>
      <div class="psc-portal-dash-menu-dish"><?php echo esc_html($m['dish']); ?></div>
    </div>
    <?php endforeach; ?>
  <?php elseif (!empty($psc_portal_dashboard['menu_no_school'])): ?>
    <p class="psc-portal-dash-menu-empty">Pas d'école cette semaine (vacances scolaires) : pas de périscolaire ni de cantine.</p>
  <?php else: ?>
    <p class="psc-portal-dash-menu-empty">Menu non encore renseigné pour cette semaine.</p>
  <?php endif; ?>
</div>

<div class="psc-portal-section-heading">
  <span class="psc-portal-numeral">II.</span>
  <span class="psc-portal-heading-label">Mes enfants</span>
</div>
<?php if (empty($psc_portal_dashboard['children'])): ?>
<p class="psc-portal-dash-menu-empty">Aucun enfant n'est encore rattaché à votre compte.</p>
<?php else: ?>
<div class="psc-portal-dash-children">
  <?php foreach ($psc_portal_dashboard['children'] as $c): ?>
  <div class="psc-portal-dash-child-card">
    <div class="psc-portal-dash-child-name"><?php echo esc_html($c['name']); ?></div>
    <div class="psc-portal-dash-child-meta"><?php echo esc_html($c['meta']); ?></div>
    <div class="psc-portal-dash-child-summary"><?php echo esc_html($c['summary']); ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
