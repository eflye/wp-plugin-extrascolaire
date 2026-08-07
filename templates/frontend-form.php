<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-wrap">

<?php
$psc_notices = array(
    'welcome' => array('ok', 'Vous êtes connecté.'),
);
if (!empty($psc_msg) && isset($psc_notices[$psc_msg])):
    list($type, $text) = $psc_notices[$psc_msg]; ?>
    <p class="psc-notice psc-notice-<?php echo esc_attr($type); ?>"><?php echo esc_html($text); ?></p>
<?php endif; ?>

<div class="psc-account">
  <span>Connecté avec <strong><?php echo esc_html($parent->email); ?></strong></span>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('psc_logout'); ?>
    <input type="hidden" name="action" value="psc_logout">
    <button type="submit" class="psc-link-btn">Se déconnecter</button>
  </form>
</div>

<?php if (!$trimestre): ?>

  <p>Aucune période d'inscription périscolaire n'est actuellement ouverte. Merci de repasser plus tard ou de contacter la mairie.</p>

<?php elseif (empty($children)): ?>

  <p class="psc-notice psc-notice-err">
    Aucun enfant n'est encore rattaché à votre adresse. Merci de contacter la mairie
    pour faire enregistrer votre ou vos enfants.
  </p>

<?php else: ?>

  <p class="psc-period">Période en cours : <strong><?php echo esc_html($trimestre->label); ?></strong></p>

  <p class="psc-help">
    Cochez les jours et prestations souhaités : l'enregistrement est automatique.
    Chaque jour reste modifiable jusqu'à <strong><?php echo esc_html(psc_lock_hours()); ?> heures</strong>
    avant la date concernée. Passé ce délai, la case est grisée : contactez la mairie.
  </p>

  <?php foreach ($children as $child): ?>

    <h2 class="psc-child-name">
      <?php echo esc_html($child->prenom . ' ' . $child->nom); ?>
      <?php if ($child->classe): ?><span class="psc-classe">(<?php echo esc_html($child->classe); ?>)</span><?php endif; ?>
    </h2>

    <?php foreach ($days_by_month as $month_label => $days): ?>
      <?php
      // On masque un mois entièrement verrouillé plutôt que d'afficher
      // des dizaines de lignes grisées inutilisables.
      $has_open = false;
      foreach ($days as $d) {
          if (!psc_is_locked($d->jour_date)) { $has_open = true; break; }
      }
      ?>
      <details class="psc-month-block" <?php echo $has_open ? 'open' : ''; ?>>
        <summary class="psc-month">
          <?php echo esc_html(ucfirst($month_label)); ?>
          <?php if (!$has_open): ?><span class="psc-badge">clôturé</span><?php endif; ?>
        </summary>

        <table class="psc-calendar">
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
                  <?php echo esc_html($short[$code]); ?><br>
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
            <tr class="<?php echo $locked ? 'psc-row-locked' : ''; ?>">
              <th scope="row" class="psc-daylabel">
                <?php echo esc_html(psc_day_label($date) . ' ' . date_i18n('d/m', strtotime($date))); ?>
                <?php if ($locked): ?>
                  <span class="psc-lock" title="Délai de modification dépassé" aria-label="Délai de modification dépassé">&#128274;</span>
                <?php endif; ?>
              </th>
              <?php foreach (psc_allowed_services() as $s):
                  $checked = isset($reg_map[$child->id . '|' . $date . '|' . $s]);
              ?>
                <td class="psc-cell">
                  <input type="checkbox" class="psc-check"
                         aria-label="<?php echo esc_attr(
                             $services[$s]['label'] . ' — ' . psc_day_label($date) . ' '
                             . date_i18n('d/m', strtotime($date)) . ' — ' . $child->prenom
                             . ($locked ? ' (non modifiable)' : '')
                         ); ?>"
                         data-child="<?php echo esc_attr($child->id); ?>"
                         data-date="<?php echo esc_attr($date); ?>"
                         data-service="<?php echo esc_attr($s); ?>"
                         <?php checked($checked); ?>
                         <?php disabled($locked); ?>>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endforeach; ?>

  <?php endforeach; ?>

  <div class="psc-confirm-block">
    <h3>Confirmer mon planning</h3>
    <p>
      Vos inscriptions sont déjà enregistrées. Ce bouton vous envoie par e-mail
      un récapitulatif complet, à conserver comme preuve de votre saisie.
    </p>
    <button type="button" class="psc-btn" id="psc-confirm">Valider et recevoir mon planning</button>
    <p class="psc-confirm-feedback" id="psc-confirm-feedback" role="status" aria-live="polite"></p>
  </div>

<?php endif; ?>

</div>
