<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-wrap">
  <div class="psc-card psc-menu-widget" data-testid="menu-widget">
    <div class="psc-menu-nav">
      <a class="psc-menu-nav-btn" data-testid="menu-nav-prev"
         href="<?php echo esc_url($prev_url); ?>" aria-label="Semaine précédente">&larr;</a>

      <div class="psc-menu-week">
        <h2 class="psc-menu-week-label" data-testid="menu-week-label">
          Menu de la cantine — semaine du <?php echo esc_html(date_i18n('d/m/Y', strtotime($menu_week))); ?>
        </h2>
        <?php if (!$is_current_week): ?>
          <a class="psc-menu-today-link" href="<?php echo esc_url(remove_query_arg(array('psc_semaine', 'psc_msg'))); ?>">Revenir à cette semaine</a>
        <?php endif; ?>
      </div>

      <a class="psc-menu-nav-btn" data-testid="menu-nav-next"
         href="<?php echo esc_url($next_url); ?>" aria-label="Semaine suivante">&rarr;</a>
    </div>

    <?php if ($no_school_week): ?>
      <p class="psc-menu-empty" data-testid="menu-no-school">Pas d'école cette semaine (vacances scolaires) : pas de périscolaire ni de cantine.</p>
    <?php elseif ($has_content): ?>
      <table class="psc-menu-table">
        <?php foreach (Psc_Menus::jour_labels() as $key => $label):
          $day_date = gmdate('Y-m-d', strtotime($menu_week . ' +' . Psc_Menus::JOUR_OFFSETS[$key] . ' days'));
          if (!psc_is_school_day($day_date)) continue;
          $content = trim((string) $menu->$key);
          if ($content === '') continue;
        ?>
        <tr>
          <th scope="row"><?php echo esc_html($label); ?></th>
          <td><?php echo nl2br(esc_html($content)); ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <p class="psc-menu-empty">Menu non encore renseigné pour cette semaine.</p>
    <?php endif; ?>
  </div>
</div>
