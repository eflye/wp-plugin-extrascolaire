<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow">Restauration scolaire</div>
<h1 class="psc-portal-h1" data-testid="portal-menu-title">Menu de la semaine</h1>

<div class="psc-portal-menu-nav" data-testid="portal-menu-widget">
  <a class="psc-portal-menu-nav-btn" data-testid="portal-menu-nav-prev" href="<?php echo esc_url($psc_portal_menu['prev_url']); ?>" aria-label="Semaine précédente">&larr;</a>

  <div class="psc-portal-menu-week">
    <h2 class="psc-portal-menu-week-label" data-testid="portal-menu-week-label"><?php echo esc_html($psc_portal_menu['week_label']); ?></h2>
    <?php if (!$psc_portal_menu['is_current_week']): ?>
      <a class="psc-portal-menu-today-link" href="<?php echo esc_url($psc_portal_menu['reset_url']); ?>">Revenir à cette semaine</a>
    <?php endif; ?>
  </div>

  <a class="psc-portal-menu-nav-btn" data-testid="portal-menu-nav-next" href="<?php echo esc_url($psc_portal_menu['next_url']); ?>" aria-label="Semaine suivante">&rarr;</a>
</div>

<?php if ($psc_portal_menu['has_content']): ?>
<table class="psc-portal-menu-table" data-testid="portal-menu-table">
  <?php foreach ($psc_portal_menu['days'] as $d): ?>
  <tr>
    <th scope="row"><?php echo esc_html($d['day']); ?></th>
    <td><?php echo nl2br(esc_html($d['dish'])); ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php else: ?>
<p class="psc-portal-menu-empty" data-testid="portal-menu-empty">Menu non encore renseigné pour cette semaine.</p>
<?php endif; ?>
