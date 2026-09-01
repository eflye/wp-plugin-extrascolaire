<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-guest-menu-nav" data-testid="menu-widget">
  <a class="psc-portal-menu-nav-btn" data-testid="menu-nav-prev" href="<?php echo esc_url($psc_guest_menu['prev_url']); ?>" aria-label="<?php esc_attr_e('Semaine précédente', 'periscolaire-registration'); ?>">&larr;</a>

  <div style="text-align:center">
    <div class="psc-guest-menu-label"><?php esc_html_e('Menu de la cantine', 'periscolaire-registration'); ?></div>
    <div class="psc-guest-menu-week" data-testid="menu-week-label"><?php echo esc_html($psc_guest_menu['week_label']); ?></div>
    <?php if (!$psc_guest_menu['is_current_week']): ?>
      <a class="psc-guest-menu-today-link" href="<?php echo esc_url($psc_guest_menu['reset_url']); ?>"><?php esc_html_e('Revenir à cette semaine', 'periscolaire-registration'); ?></a>
    <?php endif; ?>
  </div>

  <a class="psc-portal-menu-nav-btn" data-testid="menu-nav-next" href="<?php echo esc_url($psc_guest_menu['next_url']); ?>" aria-label="<?php esc_attr_e('Semaine suivante', 'periscolaire-registration'); ?>">&rarr;</a>
</div>

<?php if ($psc_guest_menu['has_content']): ?>
<table class="psc-guest-menu-table">
  <?php foreach ($psc_guest_menu['days'] as $d): ?>
  <tr>
    <th scope="row"><?php echo esc_html($d['day']); ?></th>
    <td><?php echo nl2br(esc_html($d['dish'])); ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php elseif ($psc_guest_menu['no_school_week']): ?>
<p class="psc-guest-menu-empty" data-testid="menu-no-school"><?php esc_html_e("Pas d'école cette semaine (vacances scolaires) : pas de périscolaire ni de cantine.", 'periscolaire-registration'); ?></p>
<?php else: ?>
<p class="psc-guest-menu-empty" data-testid="menu-not-filled"><?php esc_html_e('Menu non encore renseigné pour cette semaine.', 'periscolaire-registration'); ?></p>
<?php endif; ?>
