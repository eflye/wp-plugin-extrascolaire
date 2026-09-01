<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow"><?php esc_html_e('Bonjour', 'periscolaire-registration'); ?></div>
<h1 class="psc-portal-h1 psc-portal-h1--dashboard" data-testid="dashboard-title"><?php echo esc_html($psc_portal_dashboard['title']); ?></h1>
<p class="psc-portal-subtitle"><?php esc_html_e("Voici l'essentiel de votre espace périscolaire.", 'periscolaire-registration'); ?></p>

<div class="psc-portal-cards">
  <div class="psc-portal-card">
    <div class="psc-portal-card-label"><?php esc_html_e('Cette période', 'periscolaire-registration'); ?></div>
    <div class="psc-portal-card-value" data-testid="dashboard-days"><?php echo esc_html($psc_portal_dashboard['days_label']); ?></div>
    <div class="psc-portal-card-sub"><?php echo esc_html($psc_portal_dashboard['amount_label']); ?> <?php esc_html_e('€ déclarés', 'periscolaire-registration'); ?></div>
  </div>
  <div class="psc-portal-card">
    <div class="psc-portal-card-label"><?php esc_html_e('Prochaine facture', 'periscolaire-registration'); ?></div>
    <?php if ($psc_portal_dashboard['next_invoice']): ?>
    <div class="psc-portal-card-value" data-testid="dashboard-next-invoice"><?php echo esc_html($psc_portal_dashboard['next_invoice']['mois_label']); ?></div>
    <div class="psc-portal-card-sub"><?php echo esc_html($psc_portal_dashboard['next_invoice']['status_label']); ?></div>
    <?php else: ?>
    <div class="psc-portal-card-value psc-portal-card-value--empty" style="font-size:17px;color:#8B8279;"><?php esc_html_e('Aucune', 'periscolaire-registration'); ?></div>
    <div class="psc-portal-card-sub"><?php esc_html_e('Rien à régler pour le moment', 'periscolaire-registration'); ?></div>
    <?php endif; ?>
  </div>
  <div class="psc-portal-card psc-portal-card--gold">
    <div class="psc-portal-card-label"><?php esc_html_e('Accès rapide', 'periscolaire-registration'); ?></div>
    <a href="<?php echo esc_url($psc_portal_tabs['cantine']['url']); ?>" class="psc-portal-btn-ink" data-portal-tab-link="cantine" style="display:block;text-align:center;text-decoration:none;"><?php esc_html_e('Déclarer un jour', 'periscolaire-registration'); ?></a>
    <a href="<?php echo esc_url($psc_portal_tabs['enfants']['url']); ?>" class="psc-portal-btn-outline-ink" data-portal-tab-link="enfants" style="display:block;text-align:center;text-decoration:none;"><?php esc_html_e('Ajouter un enfant', 'periscolaire-registration'); ?></a>
    <button type="button" id="psc-absence-trigger" class="psc-portal-btn-outline-ink" data-testid="absence-trigger" style="width:100%;"><?php esc_html_e('Annulation prestations', 'periscolaire-registration'); ?></button>
  </div>
</div>

<div id="psc-absence-modal" class="psc-portal-modal-overlay" hidden data-testid="absence-modal">
  <div class="psc-portal-modal" role="dialog" aria-modal="true" aria-labelledby="psc-absence-modal-title" tabindex="-1">
    <h3 class="psc-portal-modal-title" id="psc-absence-modal-title"><?php esc_html_e('Signaler une absence', 'periscolaire-registration'); ?></h3>

    <?php if (empty($psc_portal_absence_days)): ?>
      <p class="psc-portal-dash-menu-empty"><?php esc_html_e('Aucune prestation à venir à annuler pour le moment.', 'periscolaire-registration'); ?></p>
      <div class="psc-portal-modal-actions">
        <button type="button" class="psc-portal-btn-outline-ink" data-absence-close><?php esc_html_e('Fermer', 'periscolaire-registration'); ?></button>
      </div>
    <?php else: ?>
      <p class="psc-portal-dash-menu-empty" style="margin:0 0 16px;"><?php esc_html_e('Cochez les prestations à annuler pour cet enfant. Un forfait journée est listé comme 3 prestations (garderie matin, cantine, garderie soir) : en cocher une seule annule le forfait en entier. Seules les prestations encore modifiables apparaissent ci-dessous.', 'periscolaire-registration'); ?></p>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-testid="absence-form">
        <?php wp_nonce_field('psc_cancel_absence'); psc_parent_nonce_field('psc_cancel_absence'); ?>
        <input type="hidden" name="action" value="psc_cancel_absence">

        <label class="psc-portal-field-label" for="psc-absence-child"><?php esc_html_e('Enfant', 'periscolaire-registration'); ?></label>
        <select id="psc-absence-child" name="child_id" class="psc-portal-field-underline" data-testid="absence-child-select">
          <?php foreach ($psc_portal_absence_days as $child_id => $child_data): ?>
          <option value="<?php echo esc_attr($child_id); ?>"><?php echo esc_html($child_data['name']); ?></option>
          <?php endforeach; ?>
        </select>

        <label class="psc-portal-field-label" style="margin-top:16px;"><?php esc_html_e('Prestations', 'periscolaire-registration'); ?></label>
        <div id="psc-absence-items" class="psc-absence-items" data-testid="absence-items"></div>
        <p class="psc-portal-dash-menu-empty" id="psc-absence-items-error" hidden style="color:#b32d2e;margin:6px 0 0;"><?php esc_html_e('Cochez au moins une prestation à annuler.', 'periscolaire-registration'); ?></p>

        <div class="psc-portal-modal-actions">
          <button type="button" class="psc-portal-btn-outline-ink" data-absence-close><?php esc_html_e('Annuler', 'periscolaire-registration'); ?></button>
          <button type="submit" class="psc-portal-btn-gold" data-testid="absence-submit"><?php esc_html_e("Confirmer l'annulation", 'periscolaire-registration'); ?></button>
        </div>
      </form>
      <script type="application/json" id="psc-absence-data"><?php echo wp_json_encode($psc_portal_absence_days, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <?php endif; ?>
  </div>
</div>

<div class="psc-portal-section-heading">
  <span class="psc-portal-numeral">I.</span>
  <span class="psc-portal-heading-label"><?php esc_html_e('Menu de la semaine en cours', 'periscolaire-registration'); ?></span>
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
    <p class="psc-portal-dash-menu-empty"><?php esc_html_e("Pas d'école cette semaine (vacances scolaires) : pas de périscolaire ni de cantine.", 'periscolaire-registration'); ?></p>
  <?php else: ?>
    <p class="psc-portal-dash-menu-empty"><?php esc_html_e('Menu non encore renseigné pour cette semaine.', 'periscolaire-registration'); ?></p>
  <?php endif; ?>
</div>

<div class="psc-portal-section-heading">
  <span class="psc-portal-numeral">II.</span>
  <span class="psc-portal-heading-label"><?php esc_html_e('Mes enfants', 'periscolaire-registration'); ?></span>
</div>
<?php if (empty($psc_portal_dashboard['children'])): ?>
<p class="psc-portal-dash-menu-empty"><?php esc_html_e("Aucun enfant n'est encore rattaché à votre compte.", 'periscolaire-registration'); ?></p>
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
