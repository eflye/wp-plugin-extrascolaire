<?php if (!defined('ABSPATH')) exit; ?>
<?php
/**
 * Planning - 1 — saisie jour par jour.
 *
 * Reprise du modèle historique, étendue à l'année scolaire : un tableau
 * jour × service par enfant, sections empilées, navigation mois par mois
 * (← →). Chaque clic écrit une EXCEPTION (ajout ou retrait) — cette variante
 * n'a pas d'éditeur de rythme : le rythme habituel posé dans Planning - 2
 * reste la base, les clics ici ne portent que les écarts ponctuels.
 *
 * Les deux écrans lisent et écrivent le même modèle : une saisie faite ici
 * se retrouve dans Planning - 2, et inversement.
 */
$psc_v1 = isset($psc_planning_data) ? $psc_planning_data : array();
$psc_explicit   = isset($psc_v1['explicit']) ? $psc_v1['explicit'] : array();
$psc_month_days = isset($psc_v1['month_dates']) ? $psc_v1['month_dates'] : array();
$psc_year_key   = $psc_year ? $psc_year->year_key : '';
$psc_show_both  = isset($psc_portal_tabs['cantine2']);

$psc_nav_base = remove_query_arg(array('psc_msg'));
$psc_switch_url = add_query_arg('psc_tab', 'cantine2', $psc_nav_base);
$psc_prev_url = $psc_prev_month ? add_query_arg(array('psc_tab' => 'cantine', 'psc_mois' => $psc_prev_month), $psc_nav_base) : '';
$psc_next_url = $psc_next_month ? add_query_arg(array('psc_tab' => 'cantine', 'psc_mois' => $psc_next_month), $psc_nav_base) : '';
?>
<div class="psc-portal-eyebrow"><?php esc_html_e('Inscriptions', 'periscolaire-registration'); ?></div>
<h1 class="psc-portal-h1" data-testid="cantine-title"><?php esc_html_e('Planning cantine & garderie', 'periscolaire-registration'); ?></h1>

<?php if ($psc_show_both): ?>
<nav class="psc-planning-switch" data-testid="planning-switch" aria-label="<?php esc_attr_e('Variante d\'écran', 'periscolaire-registration'); ?>">
  <span class="psc-planning-switch-current"><?php echo psc_planning_single_variant() ? esc_html__('Planning', 'periscolaire-registration') : esc_html__('Planning - 1 · saisie jour par jour', 'periscolaire-registration'); ?></span>
  <a href="<?php echo esc_url($psc_switch_url); ?>" class="psc-planning-switch-link"><?php esc_html_e('Basculer vers Planning - 2 (rythme + exceptions)', 'periscolaire-registration'); ?></a>
</nav>
<?php endif; ?>

<?php if (!$psc_year): ?>

  <p><?php esc_html_e("Aucune année scolaire n'est encore configurée par la mairie. Merci de repasser plus tard ou de contacter la mairie.", 'periscolaire-registration'); ?></p>

<?php elseif (empty($children)): ?>

  <p class="psc-notice psc-notice-err">
    <?php esc_html_e("Aucun enfant n'est encore rattaché à votre adresse. Merci de contacter la mairie
    pour faire enregistrer votre ou vos enfants.", 'periscolaire-registration'); ?>
  </p>

<?php else: ?>

  <p class="psc-portal-intro" data-testid="period-label">
    <?php esc_html_e('Année scolaire', 'periscolaire-registration'); ?> <strong><?php echo esc_html($psc_year_key); ?></strong>
    — <?php echo esc_html($psc_month_label); ?>. <?php esc_html_e("Cochez les jours et prestations
    souhaités : l'enregistrement est automatique.", 'periscolaire-registration'); ?>
  </p>
  <p class="psc-portal-intro-sub">
    <?php esc_html_e("Chaque jour reste modifiable jusqu'à", 'periscolaire-registration'); ?> <?php echo esc_html(psc_lock_hours()); ?> <?php esc_html_e("heures avant la date concernée.
    Passé ce délai, la case est grisée : contactez la mairie.", 'periscolaire-registration'); ?>
  </p>

  <div class="psc-planning-nav" data-testid="planning-nav">
    <?php if ($psc_prev_url): ?>
    <a class="psc-planning-nav-btn" href="<?php echo esc_url($psc_prev_url); ?>" data-testid="month-prev" aria-label="<?php esc_attr_e('Mois précédent', 'periscolaire-registration'); ?>">←</a>
    <?php else: ?>
    <span class="psc-planning-nav-btn is-disabled" aria-hidden="true">←</span>
    <?php endif; ?>
    <span class="psc-planning-nav-label" data-testid="month-label"><?php echo esc_html($psc_month_label); ?></span>
    <?php if ($psc_next_url): ?>
    <a class="psc-planning-nav-btn" href="<?php echo esc_url($psc_next_url); ?>" data-testid="month-next" aria-label="<?php esc_attr_e('Mois suivant', 'periscolaire-registration'); ?>">→</a>
    <?php else: ?>
    <span class="psc-planning-nav-btn is-disabled" aria-hidden="true">→</span>
    <?php endif; ?>
  </div>

  <div class="psc-sibling-banner" data-testid="sibling-banner" role="status" aria-live="polite" id="psc-sibling-banner">
    <?php
    $psc_banner_month = isset($psc_year_summary['months'][$psc_month_key]) ? $psc_year_summary['months'][$psc_month_key] : array('days' => 0, 'amount' => 0.0);
    ?>
    <span class="psc-sibling-banner-label"><?php esc_html_e('Toute la fratrie —', 'periscolaire-registration'); ?> <?php echo esc_html($psc_month_label); ?></span>
    <span class="psc-sibling-banner-value" data-sibling-total>
      <?php echo esc_html((int) $psc_banner_month['days']); ?> <?php echo esc_html(_n('jour', 'jours', (int) $psc_banner_month['days'], 'periscolaire-registration')); ?>
      · <?php echo esc_html(number_format_i18n((float) $psc_banner_month['amount'], 2)); ?> €
    </span>
  </div>

  <?php $child_index = 0; foreach ($children as $child):
    $assurance_missing = empty($psc_assurance_map[$child->id]);
    $child_map = isset($psc_explicit[(int) $child->id]) ? $psc_explicit[(int) $child->id] : array();
    $child_summary = isset($psc_year_summary['year']['per_child'][(int) $child->id]) ? $psc_year_summary['year']['per_child'][(int) $child->id] : array('days' => 0, 'amount' => 0.0);
    $child_month_summary = isset($psc_year_summary['months'][$psc_month_key]['per_child'][(int) $child->id]) ? $psc_year_summary['months'][$psc_month_key]['per_child'][(int) $child->id] : array('days' => 0, 'amount' => 0.0);
  ?>

    <div class="psc-portal-child-block" data-child-id="<?php echo esc_attr($child->id); ?>">
      <div class="psc-portal-child-header">
        <h2 data-testid="child-name-<?php echo esc_attr($child_index); ?>">
          <?php echo esc_html($child->prenom . ' ' . $child->nom); ?>
          <?php $psc_child_classe = Psc_School_Years::classe_for($child->id); ?>
          <?php if ($psc_child_classe): ?><span class="psc-portal-child-classe">(<?php echo esc_html($psc_child_classe); ?>)</span><?php endif; ?>
        </h2>
        <span class="psc-portal-child-total" data-child-total role="status" aria-live="polite" data-testid="child-total-<?php echo esc_attr($child_index); ?>">
          <?php echo esc_html((int) $child_month_summary['days']); ?> <?php echo esc_html(_n('jour', 'jours', (int) $child_month_summary['days'], 'periscolaire-registration')); ?>
          · <?php echo esc_html(number_format_i18n((float) $child_month_summary['amount'], 2)); ?> €
        </span>
      </div>

      <?php if ($assurance_missing): ?>
      <p class="psc-notice psc-notice-err" data-testid="assurance-missing-<?php echo esc_attr($child_index); ?>">
        <?php esc_html_e('Assurance scolaire manquante pour', 'periscolaire-registration'); ?> <?php echo esc_html($child->prenom); ?> <?php esc_html_e(" : les cases sont désactivées tant
        que le justificatif n'est pas fourni. Ajoutez-le depuis « Mes enfants ».", 'periscolaire-registration'); ?>
      </p>
      <?php endif; ?>

      <div class="psc-portal-table-scroll">
      <table class="psc-portal-calendar" data-testid="calendar-table-<?php echo esc_attr($child_index); ?>">
        <caption class="screen-reader-text">
          <?php esc_html_e('Calendrier périscolaire de', 'periscolaire-registration'); ?> <?php echo esc_html($child->prenom); ?> <?php esc_html_e('pour', 'periscolaire-registration'); ?> <?php echo esc_html($psc_month_label); ?>
        </caption>
        <thead>
          <tr>
            <th scope="col"><?php esc_html_e('Jour', 'periscolaire-registration'); ?></th>
            <?php
            $short = psc_service_short_labels();
            foreach (psc_allowed_services() as $code): ?>
              <th scope="col">
                <abbr class="psc-portal-th-abbr" title="<?php echo esc_attr($services[$code]['label']); ?>"><?php echo esc_html($short[$code]); ?></abbr>
                <small><?php echo esc_html(number_format_i18n($services[$code]['price'], 2)); ?> €</small>
              </th>
            <?php endforeach; ?>
          </tr>
          <tr class="psc-portal-tout-row" data-testid="tout-row-<?php echo esc_attr($child_index); ?>">
            <th scope="col"></th>
            <?php foreach (psc_allowed_services() as $code):
                $tout_dates = array();
                $tout_checked_count = 0;
                foreach ($psc_month_days as $date) {
                    $cell = isset($child_map[$date][$code]) ? $child_map[$date][$code] : null;
                    if (!$cell || $cell['locked'] || $cell['closed']) continue;
                    $tout_dates[] = $date;
                    if ($cell['explicit']) $tout_checked_count++;
                }
                $tout_disabled    = empty($tout_dates);
                $tout_all_checked = !$tout_disabled && $tout_checked_count === count($tout_dates);
            ?>
              <th scope="col">
                <button type="button"
                        class="psc-tout-btn<?php echo $tout_all_checked ? ' psc-tout-btn-all' : ''; ?>"
                        data-child="<?php echo esc_attr($child->id); ?>"
                        data-service="<?php echo esc_attr($code); ?>"
                        data-dates="<?php echo esc_attr(implode(',', $tout_dates)); ?>"
                        data-testid="tout-<?php echo esc_attr($child_index); ?>-<?php echo esc_attr($code); ?>"
                        <?php disabled($tout_disabled); ?>>
                  <?php echo esc_html($tout_all_checked ? __('Retirer', 'periscolaire-registration') : __('Tout', 'periscolaire-registration')); ?>
                </button>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($psc_month_days as $date):
            $locked = psc_is_locked($date);
        ?>
          <tr class="<?php echo $locked ? 'psc-row-locked' : ''; ?>" data-testid="day-row-<?php echo esc_attr($child_index); ?>-<?php echo esc_attr($date); ?>">
            <th scope="row" class="psc-daylabel">
              <?php echo esc_html(psc_day_label($date) . ' ' . date_i18n('d/m', strtotime($date))); ?>
              <?php if ($locked): ?>
                <span class="psc-lock" title="<?php esc_attr_e('Délai de modification dépassé', 'periscolaire-registration'); ?>" aria-label="<?php esc_attr_e('Délai de modification dépassé', 'periscolaire-registration'); ?>">&#128274;</span>
              <?php endif; ?>
            </th>
            <?php foreach (psc_allowed_services() as $s):
                $cell = isset($child_map[$date][$s]) ? $child_map[$date][$s] : array('explicit' => false, 'declared' => false, 'locked' => $locked, 'closed' => false);
                // Une case cochée = une ligne explicite (pattern ou exception).
                // Une case "déclarée via forfait" sans ligne propre est rendue
                // comme une case FORF cochée — convention de facturation.
                $checked = !empty($cell['explicit']);
                $cell_testid = $child_index . '-' . $date . '-' . $s;
                $service_closed = !empty($cell['closed']);
                $cell_locked = !empty($cell['locked']);
                $cell_disabled = $cell_locked || $service_closed || ($assurance_missing && !$checked);
            ?>
              <td data-testid="cell-<?php echo esc_attr($cell_testid); ?>">
                <input type="checkbox" class="psc-check"
                       aria-label="<?php echo esc_attr(
                           $services[$s]['label'] . ' — ' . psc_day_label($date) . ' '
                           . date_i18n('d/m', strtotime($date)) . ' — ' . $child->prenom
                           . ($cell_locked ? ' ' . __('(non modifiable)', 'periscolaire-registration') : '')
                           . ($service_closed ? ' ' . __('(prestation fermée)', 'periscolaire-registration') : '')
                           . ($assurance_missing && !$checked ? ' ' . __('(assurance scolaire manquante)', 'periscolaire-registration') : '')
                       ); ?>"
                       data-child="<?php echo esc_attr($child->id); ?>"
                       data-date="<?php echo esc_attr($date); ?>"
                       data-service="<?php echo esc_attr($s); ?>"
                       data-price="<?php echo esc_attr($services[$s]['price']); ?>"
                       data-testid="check-<?php echo esc_attr($cell_testid); ?>"
                       <?php checked($checked); ?>
                       <?php disabled($cell_disabled); ?>>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

  <?php $child_index++; endforeach; ?>

  <div class="psc-portal-confirm" data-testid="confirm-block">
    <div class="psc-portal-confirm-title"><?php esc_html_e('Confirmer mon planning', 'periscolaire-registration'); ?></div>
    <p class="psc-portal-confirm-text">
      <?php esc_html_e("Vos déclarations sont déjà enregistrées. Ce bouton vous envoie par e-mail
      un récapitulatif complet de l'année scolaire : rythme de chaque enfant, écarts à venir et
      estimation annuelle, à conserver comme preuve de votre saisie.", 'periscolaire-registration'); ?>
    </p>
    <button type="button" class="psc-portal-btn-gold" id="psc-confirm" data-testid="confirm-button"><?php esc_html_e('Valider et recevoir mon planning', 'periscolaire-registration'); ?></button>
    <p class="psc-confirm-feedback" id="psc-confirm-feedback" role="status" aria-live="polite" data-testid="confirm-feedback"></p>
  </div>

<?php endif; ?>
