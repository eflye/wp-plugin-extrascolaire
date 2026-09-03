<?php if (!defined('ABSPATH')) exit; ?>
<?php
/**
 * Planning — rythme + exceptions (maquette Family Portal v3, écran
 * « Cantine & Garderie »).
 *
 * Quatre zones de haut en bas :
 *  a. Frise de l'année : onze boutons (Sept. → Juil.), chacun affichant le
 *     nombre de jours déclarés pour toute la fratrie — le contrôle de
 *     complétude (un mois à « 0 j » saute aux yeux) ; estimation annuelle à
 *     droite du libellé ;
 *  b. Onglets enfants : prénom + classe + jours du mois (4 plannings
 *     empilés rendraient la page inutilisable) ;
 *  c. Étape 1 — rythme habituel (grille 4 jours × 4 services, boutons ✓) +
 *     copie fratrie (bordure pointillée) ;
 *  d. Étape 2 — exceptions du mois : en-tête avec navigation ← →, barre
 *     récap de l'enfant (fond sable) + lien « revenir au rythme », tableau
 *     avec origine lisible de chaque case, légende ;
 *  e. Récapitulatif fratrie (mois et année, blocs à filet abricot).
 *
 * Même modèle que Planning - 1 : chaque clic écrit une ligne de pattern
 * (étape 1) ou une exception (étape 2), enregistrée immédiatement.
 */
$psc_v2 = isset($psc_planning_data) ? $psc_planning_data : array();
$psc_active_child_id = isset($psc_v2['active_child']) ? (int) $psc_v2['active_child'] : 0;
$psc_cells = isset($psc_v2['cells']['cells']) ? $psc_v2['cells']['cells'] : array();
$psc_all_patterns = isset($psc_v2['patterns']) ? $psc_v2['patterns'] : array();
$psc_year_key   = $psc_year ? $psc_year->year_key : '';
$psc_show_both  = !empty($psc_portal_tabs['cantine']);
$psc_services   = $services;
$psc_short      = psc_service_short_labels();

$psc_nav_base = remove_query_arg(array('psc_msg', 'psc_child'));
$psc_switch_url = add_query_arg('psc_tab', 'cantine', $psc_nav_base);

// Charge utile initiale du JS (re-rendus après chaque écriture AJAX).
$psc_v2_boot = array(
    'year_key'    => $psc_year_key,
    'year_end_label' => $psc_year ? date_i18n('F Y', strtotime($psc_year->date_end)) : '',
    'month'       => $psc_month_key,
    'months'      => $psc_months,
    'active_child' => $psc_active_child_id,
    'children'    => array(),
    'patterns'    => $psc_all_patterns,
    'per_child'   => array(),
    'month_days'  => (int) ($psc_year_summary['months'][$psc_month_key]['days'] ?? 0),
    'month_amount' => (float) ($psc_year_summary['months'][$psc_month_key]['amount'] ?? 0),
    'year_days'   => (int) $psc_year_summary['year']['days'],
    'year_amount' => (float) $psc_year_summary['year']['amount'],
);
foreach ($children as $c) {
    $cid = (int) $c->id;
    $psc_v2_boot['children'][] = array(
        'id'     => $cid,
        'name'   => trim($c->prenom . ' ' . $c->nom),
        'prenom' => $c->prenom,
        'classe' => Psc_School_Years::classe_for($cid),
    );
}
if (isset($psc_year_summary['months'][$psc_month_key]['per_child'])) {
    foreach ($psc_year_summary['months'][$psc_month_key]['per_child'] as $cid => $row) {
        $psc_v2_boot['per_child'][$cid] = array(
            'month_days'   => (int) $row['days'],
            'month_amount' => round((float) $row['amount'], 2),
            'year_days'    => isset($psc_year_summary['year']['per_child'][$cid]) ? (int) $psc_year_summary['year']['per_child'][$cid]['days'] : 0,
            'year_amount'  => isset($psc_year_summary['year']['per_child'][$cid]) ? round((float) $psc_year_summary['year']['per_child'][$cid]['amount'], 2) : 0.0,
        );
    }
}
$psc_active_name = '';
foreach ($children as $c) {
    if ((int) $c->id === $psc_active_child_id) { $psc_active_name = $c->prenom; break; }
}
$psc_active_classe = Psc_School_Years::classe_for($psc_active_child_id);
$psc_active_month = $psc_year_summary['months'][$psc_month_key]['per_child'][$psc_active_child_id] ?? array('days' => 0, 'amount' => 0.0);
$psc_active_year = $psc_year_summary['year']['per_child'][$psc_active_child_id] ?? array('days' => 0, 'amount' => 0.0);
?>
<div class="psc-portal-eyebrow"><?php esc_html_e('Inscriptions', 'periscolaire-registration'); ?></div>
<h1 class="psc-portal-h1" data-testid="cantine2-title"><?php esc_html_e('Planning cantine & garderie', 'periscolaire-registration'); ?></h1>

<?php if ($psc_show_both): ?>
<nav class="psc-planning-switch" data-testid="planning-switch-2" aria-label="<?php esc_attr_e('Variante d\'écran', 'periscolaire-registration'); ?>">
  <span class="psc-planning-switch-current"><?php echo psc_planning_single_variant() ? esc_html__('Planning', 'periscolaire-registration') : esc_html__('Planning - 2 · rythme + exceptions', 'periscolaire-registration'); ?></span>
  <a href="<?php echo esc_url($psc_switch_url); ?>" class="psc-planning-switch-link" data-testid="planning-switch-link"><?php esc_html_e('Basculer vers Planning - 1 (saisie jour par jour)', 'periscolaire-registration'); ?></a>
</nav>
<?php endif; ?>

<?php if (!$psc_year): ?>

  <p><?php esc_html_e("Aucune année scolaire n'est encore configurée par la mairie. Merci de repasser plus tard ou de contacter la mairie.", 'periscolaire-registration'); ?></p>

<?php elseif (empty($children) || !$psc_active_child_id): ?>

  <p class="psc-notice psc-notice-err">
    <?php esc_html_e("Aucun enfant n'est encore rattaché à votre adresse. Merci de contacter la mairie
    pour faire enregistrer votre ou vos enfants.", 'periscolaire-registration'); ?>
  </p>

<?php else: ?>

  <script type="application/json" id="psc-planning2-data"><?php echo wp_json_encode($psc_v2_boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

  <p class="psc-portal-intro" style="max-width:700px;">
    <strong><?php esc_html_e('Année scolaire', 'periscolaire-registration'); ?> <?php echo esc_html($psc_year_key); ?>.</strong>
    <?php esc_html_e("Déclarez une seule fois le rythme habituel de chaque enfant : il vaut pour toute
    l'année. Vous n'ajustez ensuite que les exceptions, mois par mois.", 'periscolaire-registration'); ?>
  </p>
  <p class="psc-portal-intro-sub" style="color:#4E6C8D;">
    <?php esc_html_e('Chaque jour reste modifiable jusqu\'à', 'periscolaire-registration'); ?> <?php echo esc_html(psc_lock_hours()); ?> <?php esc_html_e('heures avant la date concernée.
    Passé ce délai, la case est grisée : contactez la mairie.', 'periscolaire-registration'); ?>
  </p>

  <?php /* a. Frise de l'année */ ?>
  <div class="psc-frieze-wrap" data-testid="planning-frieze">
    <div class="psc-frieze-head">
      <div class="psc-frieze-title"><?php esc_html_e('Année scolaire — jours déclarés pour la fratrie', 'periscolaire-registration'); ?></div>
      <div class="psc-frieze-estimate"><?php esc_html_e('Estimation année :', 'periscolaire-registration'); ?> <span data-year-amount><?php echo esc_html(number_format_i18n((float) $psc_year_summary['year']['amount'], 2)); ?> €</span></div>
    </div>
    <div class="psc-frieze" role="group" aria-label="<?php esc_attr_e('Année scolaire mois par mois', 'periscolaire-registration'); ?>">
      <?php foreach ($psc_months as $m):
          $count = (int) ($psc_year_summary['months'][$m['key']]['days'] ?? 0);
          $is_current = $m['key'] === $psc_month_key;
      ?>
      <a class="psc-frieze-btn<?php echo $is_current ? ' is-current' : ''; ?>"
         href="<?php echo esc_url(add_query_arg(array('psc_tab' => 'cantine2', 'psc_mois' => $m['key']), $psc_nav_base)); ?>"
         data-month="<?php echo esc_attr($m['key']); ?>"
         data-count="<?php echo esc_attr($count); ?>"
         data-testid="frieze-<?php echo esc_attr($m['key']); ?>"
         aria-label="<?php echo esc_attr($m['label'] . ' — ' . $count . ' ' . _n('jour déclaré', 'jours déclarés', $count, 'periscolaire-registration')); ?>">
        <?php echo esc_html($m['short']); ?><span class="psc-frieze-count"><?php echo esc_html($count); ?> j</span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php /* b. Onglets enfants */ ?>
  <div class="psc-child-tabs-label"><?php esc_html_e('Enfant', 'periscolaire-registration'); ?></div>
  <div class="psc-child-tabs" data-testid="child-tabs" role="tablist" aria-label="<?php esc_attr_e('Enfants', 'periscolaire-registration'); ?>">
    <?php foreach ($children as $c):
        $cid = (int) $c->id;
        $days = (int) ($psc_year_summary['months'][$psc_month_key]['per_child'][$cid]['days'] ?? 0);
        $classe = Psc_School_Years::classe_for($cid);
    ?>
    <button type="button" class="psc-child-tab<?php echo $cid === $psc_active_child_id ? ' is-active' : ''; ?>"
            role="tab" aria-selected="<?php echo $cid === $psc_active_child_id ? 'true' : 'false'; ?>"
            data-child-tab="<?php echo esc_attr($cid); ?>"
            data-testid="child-tab-<?php echo esc_attr($cid); ?>">
      <?php echo esc_html($c->prenom); ?>
      <span class="psc-child-tab-meta" data-tab-days="<?php echo esc_attr($cid); ?>"><?php echo esc_html($classe . ' · ' . $days . ' j. ce mois'); ?></span>
    </button>
    <?php endforeach; ?>
  </div>

  <div class="psc-planning-panels">
    <?php /* c. Étape 1 — rythme habituel (panneau gauche) */ ?>
    <div class="psc-planning-panel" data-testid="panel-rythme">
      <div class="psc-panel-step psc-panel-step--gold"><?php esc_html_e('Étape 1 — toute l\'année', 'periscolaire-registration'); ?></div>
      <div class="psc-panel-title"><?php echo esc_html(sprintf(__('Rythme habituel — %s', 'periscolaire-registration'), $psc_active_name)); ?></div>
      <p class="psc-panel-sub">
        <?php echo esc_html(sprintf(__('S\'applique à chaque semaine d\'école jusqu\'en %s, vacances exclues.', 'periscolaire-registration'), $psc_year ? date_i18n('F Y', strtotime($psc_year->date_end)) : '')); ?>
      </p>
      <table class="psc-pattern-grid" data-testid="pattern-grid" data-child="<?php echo esc_attr($psc_active_child_id); ?>">
        <thead>
          <tr>
            <th scope="col"><?php esc_html_e('Jour', 'periscolaire-registration'); ?></th>
            <?php foreach (psc_allowed_services() as $code): ?>
            <th scope="col" title="<?php echo esc_attr($psc_services[$code]['label']); ?>"><?php echo esc_html($psc_short[$code]); ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php
          $jours = array(1 => __('Lundi', 'periscolaire-registration'), 2 => __('Mardi', 'periscolaire-registration'), 4 => __('Jeudi', 'periscolaire-registration'), 5 => __('Vendredi', 'periscolaire-registration'));
          foreach ($jours as $wd => $jour_label): ?>
          <tr>
            <th scope="row"><?php echo esc_html($jour_label); ?></th>
            <?php foreach (psc_allowed_services() as $code):
                $on = !empty($psc_all_patterns[$psc_active_child_id][$psc_year_key][$wd][$code]); ?>
            <td>
              <button type="button"
                      class="psc-pat-btn<?php echo $on ? ' is-on' : ''; ?>"
                      data-weekday="<?php echo esc_attr($wd); ?>"
                      data-service="<?php echo esc_attr($code); ?>"
                      data-testid="pattern-<?php echo esc_attr($wd); ?>-<?php echo esc_attr($code); ?>"
                      aria-pressed="<?php echo $on ? 'true' : 'false'; ?>"
                      aria-label="<?php echo esc_attr($jour_label . ' — ' . $psc_services[$code]['label'] . ' — ' . $psc_active_name); ?>">
                <?php echo $on ? '✓' : ''; ?>
              </button>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($children) > 1): ?>
      <?php /* La copie n'a de sens qu'à partir de deux enfants : famille
               mono-enfant, pas de bouton (et pas de feedback orphelin). */ ?>
      <button type="button" class="psc-apply-siblings" id="psc-apply-siblings" data-testid="apply-siblings">
        <?php esc_html_e('Appliquer ce rythme à toute la fratrie', 'periscolaire-registration'); ?>
      </button>
      <p class="psc-apply-siblings-feedback" id="psc-apply-siblings-feedback" role="status" aria-live="polite"></p>
      <?php endif; ?>
    </div>

    <?php /* d. Étape 2 — exceptions du mois (panneau droit) */ ?>
    <div class="psc-planning-panel psc-planning-panel--flush" data-testid="panel-exceptions">
      <div class="psc-exc-head">
        <div>
          <div class="psc-panel-step psc-panel-step--gold"><?php esc_html_e('Étape 2 — exceptions', 'periscolaire-registration'); ?></div>
          <div class="psc-panel-title psc-exc-month" data-exc-month><?php echo esc_html($psc_month_label); ?></div>
        </div>
        <div class="psc-month-nav">
          <button type="button" class="psc-month-nav-btn psc-exc-prev" data-testid="exc-prev" aria-label="<?php esc_attr_e('Mois précédent', 'periscolaire-registration'); ?>"<?php disabled($psc_prev_month === null); ?>>←</button>
          <button type="button" class="psc-month-nav-btn psc-exc-next" data-testid="exc-next" aria-label="<?php esc_attr_e('Mois suivant', 'periscolaire-registration'); ?>"<?php disabled($psc_next_month === null); ?>>→</button>
        </div>
      </div>

      <div class="psc-exc-bar">
        <span data-exc-bar-summary><?php echo esc_html($psc_active_name); ?> · <?php echo esc_html((int) $psc_active_month['days']); ?> <?php echo esc_html(_n('jour', 'jours', (int) $psc_active_month['days'], 'periscolaire-registration')); ?> · <strong><?php echo esc_html(number_format_i18n((float) $psc_active_month['amount'], 2)); ?> €</strong> <?php esc_html_e('ce mois', 'periscolaire-registration'); ?></span>
        <a href="#" class="psc-exc-reset" id="psc-exc-reset" data-testid="exc-reset" data-count="0"></a>
      </div>

      <div class="psc-exc-scroll">
      <table class="psc-exception-grid" data-testid="exception-grid" data-child="<?php echo esc_attr($psc_active_child_id); ?>">
        <caption class="screen-reader-text">
          <?php esc_html_e('Écarts au rythme habituel de', 'periscolaire-registration'); ?> <?php echo esc_html($psc_active_name); ?> <?php esc_html_e('pour', 'periscolaire-registration'); ?> <?php echo esc_html($psc_month_label); ?>
        </caption>
        <thead>
          <tr>
            <th scope="col" class="psc-exc-day-col"><?php esc_html_e('Jour', 'periscolaire-registration'); ?></th>
            <?php foreach (psc_allowed_services() as $code): ?>
            <th scope="col" title="<?php echo esc_attr($psc_services[$code]['label']); ?>">
              <?php echo esc_html($psc_short[$code]); ?><br><span class="psc-exc-price"><?php echo esc_html(number_format_i18n($psc_services[$code]['price'], 2)); ?> €</span>
            </th>
            <?php endforeach; ?>
          </tr>
          <tr class="psc-exc-actions-row">
            <th scope="col" class="psc-exc-month-label"><?php esc_html_e('Mois entier', 'periscolaire-registration'); ?></th>
            <?php foreach (psc_allowed_services() as $code):
                $exc_dates = array();
                foreach ($psc_cells as $date => $day) {
                    if ($day['locked']) continue;
                    $cell = $day['services'][$code];
                    if ($cell['closed']) continue;
                    $exc_dates[] = $date;
                }
                $all_on = $exc_dates && count(array_filter($exc_dates, function ($d) use ($psc_cells, $code) {
                    return $psc_cells[$d]['services'][$code]['declared'];
                })) === count($exc_dates);
            ?>
            <th scope="col">
              <button type="button" class="psc-exc-tout"
                      data-service="<?php echo esc_attr($code); ?>"
                      data-testid="exc-tout-<?php echo esc_attr($code); ?>"
                      data-state="<?php echo $all_on ? 'all' : 'none'; ?>"
                      <?php disabled(empty($exc_dates)); ?>
                      data-dates="<?php echo esc_attr(implode(',', $exc_dates)); ?>">
                <?php echo $all_on ? esc_html__('Aucun', 'periscolaire-registration') : esc_html__('Tout', 'periscolaire-registration'); ?>
              </button>
            </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody id="psc-exception-body">
          <?php foreach ($psc_cells as $date => $day):
              $abbr = psc_day_short($date); ?>
          <tr data-testid="exc-row-<?php echo esc_attr($date); ?>"<?php echo $day['locked'] ? ' class="psc-row-locked"' : ''; ?>>
            <th scope="row" class="psc-exc-day<?php echo $day['locked'] ? ' is-locked' : ''; ?>">
              <?php echo esc_html($abbr); ?>
              <?php if ($day['locked']): ?><span class="psc-lock" aria-hidden="true">&#128274;</span><?php endif; ?>
            </th>
            <?php foreach (psc_allowed_services() as $code):
                $cell = $day['services'][$code];
                $state = 'none';
                if ($cell['exception_value'] === true) $state = 'add';
                elseif ($cell['exception_value'] === false) $state = 'remove';
                elseif ($cell['origin'] === 'pattern') $state = 'pattern';
            ?>
            <td>
              <button type="button"
                      class="psc-exc-cell psc-exc-<?php echo esc_attr($state); ?>"
                      data-date="<?php echo esc_attr($date); ?>"
                      data-service="<?php echo esc_attr($code); ?>"
                      data-state="<?php echo esc_attr($state); ?>"
                      data-testid="exc-<?php echo esc_attr($date); ?>-<?php echo esc_attr($code); ?>"
                      aria-pressed="<?php echo $cell['declared'] ? 'true' : 'false'; ?>"
                      title="<?php echo esc_attr($cell['locked'] ? __('Verrouillé (délai de modification dépassé)', 'periscolaire-registration') : psc_lock_message($date)); ?>"
                      <?php disabled($day['locked'] || $cell['closed']); ?>>
                <span class="screen-reader-text">
                  <?php
                  if ($state === 'pattern') esc_html_e('Rythme habituel', 'periscolaire-registration');
                  elseif ($state === 'add') esc_html_e('Ajout exceptionnel', 'periscolaire-registration');
                  elseif ($state === 'remove') esc_html_e('Retiré ce jour-là', 'periscolaire-registration');
                  else esc_html_e('Non déclaré', 'periscolaire-registration');
                  ?>
                </span>
                <span class="psc-exc-glyph" aria-hidden="true"><?php echo ($state === 'pattern' || $state === 'add') ? '✓' : ($state === 'remove' ? '–' : ''); ?></span>
              </button>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <div class="psc-exc-legend" data-testid="exc-legend">
        <span><span class="psc-exc-swatch psc-exc-pattern" aria-hidden="true"></span> <?php esc_html_e('Rythme habituel', 'periscolaire-registration'); ?></span>
        <span><span class="psc-exc-swatch psc-exc-add" aria-hidden="true"></span> <?php esc_html_e('Ajout exceptionnel', 'periscolaire-registration'); ?></span>
        <span><span class="psc-exc-swatch psc-exc-remove" aria-hidden="true"></span> <?php esc_html_e('Retiré ce jour-là', 'periscolaire-registration'); ?></span>
        <span><span class="psc-exc-swatch psc-exc-locked" aria-hidden="true"></span> <?php esc_html_e('Verrouillé (48 h)', 'periscolaire-registration'); ?></span>
      </div>
    </div>
  </div>

  <?php /* e. Récapitulatif fratrie */ ?>
  <div class="psc-sibling-recap" data-testid="sibling-recap">
    <div class="psc-sibling-recap-head">
      <div class="psc-sibling-recap-title"><?php echo esc_html(sprintf(__('Récapitulatif de la fratrie — %s', 'periscolaire-registration'), $psc_month_label)); ?></div>
      <div class="psc-sibling-recap-total" data-recap-family-month><?php echo esc_html(number_format_i18n((float) $psc_year_summary['months'][$psc_month_key]['amount'], 2)); ?> €</div>
    </div>
    <div class="psc-sibling-cards">
      <?php foreach ($children as $c):
          $cid = (int) $c->id;
          $month = $psc_year_summary['months'][$psc_month_key]['per_child'][$cid] ?? array('days' => 0, 'amount' => 0.0);
          $year = $psc_year_summary['year']['per_child'][$cid] ?? array('days' => 0, 'amount' => 0.0);
          $classe = Psc_School_Years::classe_for($cid);
      ?>
      <div class="psc-sibling-card" data-testid="sibling-card-<?php echo esc_attr($cid); ?>">
        <div class="psc-sibling-card-name"><?php echo esc_html($c->prenom); ?> <span><?php echo esc_html($classe); ?></span></div>
        <div class="psc-sibling-card-month" data-recap-month="<?php echo esc_attr($cid); ?>">
          <?php echo esc_html((int) $month['days']); ?> j · <?php echo esc_html(number_format_i18n((float) $month['amount'], 2)); ?> € <?php esc_html_e('ce mois', 'periscolaire-registration'); ?>
        </div>
        <div class="psc-sibling-card-year" data-recap-year="<?php echo esc_attr($cid); ?>">
          <?php echo esc_html((int) $year['days']); ?> j · <?php echo esc_html(number_format_i18n((float) $year['amount'], 2)); ?> € <?php esc_html_e("sur l'année", 'periscolaire-registration'); ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="psc-portal-confirm" data-testid="confirm-block-2">
    <div class="psc-portal-confirm-title"><?php esc_html_e('Confirmer mon planning', 'periscolaire-registration'); ?></div>
    <p class="psc-portal-confirm-text">
      <?php esc_html_e("Vos déclarations sont déjà enregistrées. Ce bouton vous envoie par e-mail
      un récapitulatif complet de l'année scolaire, à conserver comme preuve de votre saisie.", 'periscolaire-registration'); ?>
    </p>
    <button type="button" class="psc-portal-btn-gold" id="psc-confirm-2" data-testid="confirm-button-2"><?php esc_html_e('Valider et recevoir mon planning', 'periscolaire-registration'); ?></button>
    <p class="psc-confirm-feedback" id="psc-confirm-feedback-2" role="status" aria-live="polite" data-testid="confirm-feedback-2"></p>
  </div>

<?php endif; ?>
