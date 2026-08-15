<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow">Inscriptions</div>
<h1 class="psc-portal-h1" data-testid="cantine-title">Cantine &amp; Garderie</h1>

<?php if (!$trimestre): ?>

  <p>Aucune période d'inscription périscolaire n'est actuellement ouverte. Merci de repasser plus tard ou de contacter la mairie.</p>

<?php elseif (empty($children)): ?>

  <p class="psc-notice psc-notice-err">
    Aucun enfant n'est encore rattaché à votre adresse. Merci de contacter la mairie
    pour faire enregistrer votre ou vos enfants.
  </p>

<?php else: ?>

  <p class="psc-portal-intro" data-testid="period-label">
    Période en cours : <strong><?php echo esc_html($trimestre->label); ?></strong>. Cochez les jours et prestations
    souhaités : l'enregistrement est automatique.
  </p>
  <p class="psc-portal-intro-sub">
    Chaque jour reste modifiable jusqu'à <?php echo esc_html(psc_lock_hours()); ?> heures avant la date concernée.
    Passé ce délai, la case est grisée : contactez la mairie.
  </p>

  <?php $child_index = 0; foreach ($children as $child):
    $assurance_missing = empty($psc_assurance_map[$child->id]);
    $child_days_count = 0;
    $child_total = 0.0;
    foreach ($days_by_month as $days) {
        foreach ($days as $d) {
            $day_has_reg = false;
            foreach (psc_allowed_services() as $s) {
                if (isset($reg_map[$child->id . '|' . $d->jour_date . '|' . $s])) {
                    $day_has_reg = true;
                    $child_total += (float) $services[$s]['price'];
                }
            }
            if ($day_has_reg) $child_days_count++;
        }
    }
  ?>

    <div class="psc-portal-child-block">
      <div class="psc-portal-child-header">
        <h2 data-testid="child-name-<?php echo esc_attr($child_index); ?>">
          <?php echo esc_html($child->prenom . ' ' . $child->nom); ?>
          <?php if ($child->classe): ?><span class="psc-portal-child-classe">(<?php echo esc_html($child->classe); ?>)</span><?php endif; ?>
        </h2>
        <span class="psc-portal-child-total" data-child-total data-testid="child-total-<?php echo esc_attr($child_index); ?>">
          <?php echo esc_html($child_days_count); ?> jour<?php echo $child_days_count > 1 ? 's' : ''; ?>
          · <?php echo esc_html(number_format_i18n($child_total, 2)); ?> €
        </span>
      </div>

      <?php if ($assurance_missing): ?>
      <p class="psc-notice psc-notice-err" data-testid="assurance-missing-<?php echo esc_attr($child_index); ?>">
        Assurance scolaire manquante pour <?php echo esc_html($child->prenom); ?> : les cases sont désactivées tant
        que le justificatif n'est pas fourni. Ajoutez-le depuis « Mes enfants ».
      </p>
      <?php endif; ?>

      <?php foreach ($days_by_month as $month_label => $days): ?>
        <?php
        $month_key = date('Y-m', strtotime($days[0]->jour_date));

        $has_open = false;
        foreach ($days as $d) {
            if (!psc_is_locked($d->jour_date)) { $has_open = true; break; }
        }

        $month_days_count = 0;
        $month_total = 0.0;
        foreach ($days as $d) {
            $day_has_reg = false;
            foreach (psc_allowed_services() as $s) {
                if (isset($reg_map[$child->id . '|' . $d->jour_date . '|' . $s])) {
                    $day_has_reg = true;
                    $month_total += (float) $services[$s]['price'];
                }
            }
            if ($day_has_reg) $month_days_count++;
        }
        ?>
        <details class="psc-month-block" data-testid="month-block-<?php echo esc_attr($child_index); ?>-<?php echo esc_attr($month_key); ?>">
          <summary class="psc-month" data-testid="month-toggle-<?php echo esc_attr($child_index); ?>-<?php echo esc_attr($month_key); ?>">
            <span class="psc-month-chevron" aria-hidden="true"></span>
            <span class="psc-month-name">
              <?php echo esc_html(ucfirst($month_label)); ?>
              <?php if (!$has_open): ?><span class="psc-badge">clôturé</span><?php endif; ?>
            </span>
            <span class="psc-month-summary <?php echo $month_days_count > 0 ? 'psc-month-summary-active' : 'psc-month-summary-empty'; ?>"
                  data-month-summary
                  data-testid="month-summary-<?php echo esc_attr($child_index); ?>-<?php echo esc_attr($month_key); ?>">
              <?php if ($month_days_count > 0): ?>
                <?php echo esc_html($month_days_count); ?> jour<?php echo $month_days_count > 1 ? 's' : ''; ?>
                · <?php echo esc_html(number_format_i18n($month_total, 2)); ?> €
              <?php else: ?>
                Aucun jour déclaré
              <?php endif; ?>
            </span>
          </summary>

          <div class="psc-portal-table-scroll">
          <table class="psc-portal-calendar" data-testid="calendar-table-<?php echo esc_attr($child_index); ?>-<?php echo esc_attr($month_key); ?>">
            <caption class="screen-reader-text">
              Calendrier périscolaire de <?php echo esc_html($child->prenom); ?> pour <?php echo esc_html($month_label); ?>
            </caption>
            <thead>
              <tr>
                <th scope="col">Jour</th>
                <?php
                $short = array('GM' => 'G.M.', 'CANT' => 'Cant.', 'GS' => 'G.S.', 'FORF' => 'Forf.');
                foreach (psc_allowed_services() as $code): ?>
                  <th scope="col">
                    <abbr class="psc-portal-th-abbr" title="<?php echo esc_attr($services[$code]['label']); ?>"><?php echo esc_html($short[$code]); ?></abbr>
                    <small><?php echo esc_html(number_format_i18n($services[$code]['price'], 2)); ?> €</small>
                  </th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($days as $d):
                $date = $d->jour_date;
                $locked = psc_is_locked($date);
            ?>
              <tr class="<?php echo ($locked || $assurance_missing) ? 'psc-row-locked' : ''; ?>" data-testid="day-row-<?php echo esc_attr($child_index); ?>-<?php echo esc_attr($date); ?>">
                <th scope="row" class="psc-daylabel">
                  <?php echo esc_html(psc_day_label($date) . ' ' . date_i18n('d/m', strtotime($date))); ?>
                  <?php if ($locked): ?>
                    <span class="psc-lock" title="Délai de modification dépassé" aria-label="Délai de modification dépassé">&#128274;</span>
                  <?php endif; ?>
                </th>
                <?php foreach (psc_allowed_services() as $s):
                    $checked = isset($reg_map[$child->id . '|' . $date . '|' . $s]);
                    $cell_testid = $child_index . '-' . $date . '-' . $s;
                    // L'assurance manquante bloque uniquement l'AJOUT d'un
                    // jour : une case déjà cochée reste décochable (pas de
                    // blocage rétroactif, cf. ajax_toggle()).
                    $cell_disabled = $locked || ($assurance_missing && !$checked);
                ?>
                  <td data-testid="cell-<?php echo esc_attr($cell_testid); ?>">
                    <input type="checkbox" class="psc-check"
                           aria-label="<?php echo esc_attr(
                               $services[$s]['label'] . ' — ' . psc_day_label($date) . ' '
                               . date_i18n('d/m', strtotime($date)) . ' — ' . $child->prenom
                               . ($locked ? ' (non modifiable)' : '')
                               . ($assurance_missing && !$checked ? ' (assurance scolaire manquante)' : '')
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
        </details>
      <?php endforeach; ?>
    </div>

  <?php $child_index++; endforeach; ?>

  <div class="psc-portal-confirm" data-testid="confirm-block">
    <div class="psc-portal-confirm-title">Confirmer mon planning</div>
    <p class="psc-portal-confirm-text">
      Vos inscriptions sont déjà enregistrées. Ce bouton vous envoie par e-mail
      un récapitulatif complet, à conserver comme preuve de votre saisie.
    </p>
    <button type="button" class="psc-portal-btn-gold" id="psc-confirm" data-testid="confirm-button">Valider et recevoir mon planning</button>
    <p class="psc-confirm-feedback" id="psc-confirm-feedback" role="status" aria-live="polite" data-testid="confirm-feedback"></p>
  </div>

<?php endif; ?>
