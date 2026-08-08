<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-wrap">

<?php
$psc_notices = array(
    'welcome'       => array('ok',  'Vous êtes connecté.'),
    'child_updated' => array('ok',  'Informations de l\'enfant mises à jour.'),
    'child_added'   => array('ok',  'Enfant ajouté à votre compte.'),
    'child_invalid' => array('err', 'Merci de renseigner le prénom et le nom.'),
    'child_limit'   => array('err', 'Nombre maximum d\'enfants atteint.'),
);
$child_msg = in_array($psc_msg, array('child_updated', 'child_added', 'child_invalid', 'child_limit'), true);
// Les confirmations (connexion, enregistrement réussi) n'apportent rien
// de plus après quelques secondes : popin auto-masquée (cf.
// assets/js/frontend.js). Les erreurs à corriger (child_invalid,
// child_limit) restent des bandeaux classiques, le temps de lire et
// d'agir sur le formulaire concerné.
$psc_toast_messages = array('welcome', 'child_updated', 'child_added');
if (!empty($psc_msg) && isset($psc_notices[$psc_msg])):
    list($type, $text) = $psc_notices[$psc_msg];
    $is_toast = in_array($psc_msg, $psc_toast_messages, true);
    ?>
    <p class="psc-notice psc-notice-<?php echo esc_attr($type); ?><?php echo $is_toast ? ' psc-toast' : ''; ?>" data-testid="notice-<?php echo esc_attr($psc_msg); ?>"><?php echo esc_html($text); ?></p>
<?php endif; ?>

<div class="psc-account" data-testid="account-bar">
  <span>Connecté avec <strong data-testid="account-email"><?php echo esc_html($parent->email); ?></strong></span>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('psc_logout'); ?>
    <input type="hidden" name="action" value="psc_logout">
    <button type="submit" class="psc-link-btn" data-testid="logout-button">Se déconnecter</button>
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

  <p class="psc-period" data-testid="period-label">Période en cours : <strong><?php echo esc_html($trimestre->label); ?></strong></p>

  <p class="psc-help">
    Cochez les jours et prestations souhaités : l'enregistrement est automatique.
    Chaque jour reste modifiable jusqu'à <strong><?php echo esc_html(psc_lock_hours()); ?> heures</strong>
    avant la date concernée. Passé ce délai, la case est grisée : contactez la mairie.
  </p>

  <ul class="psc-glossary">
    <li><strong>G.M.</strong> : Garderie Matin</li>
    <li><strong>Cant.</strong> : Cantine</li>
    <li><strong>G.S.</strong> : Garderie Soir</li>
    <li><strong>Forf.</strong> : Forfait journée</li>
  </ul>

  <?php $child_index = 0; foreach ($children as $child): ?>

    <h2 class="psc-child-name" data-testid="child-name-<?php echo esc_attr($child_index); ?>">
      <?php echo esc_html($child->prenom . ' ' . $child->nom); ?>
      <?php if ($child->classe): ?><span class="psc-classe">(<?php echo esc_html($child->classe); ?>)</span><?php endif; ?>
    </h2>

    <?php foreach ($days_by_month as $month_label => $days): ?>
      <?php
      // Clé YYYY-MM utilisée dans les testid : le test connaît la date
      // ciblée, il n'a pas à recalculer un index de mois pour la retrouver.
      $month_key = date('Y-m', strtotime($days[0]->jour_date));

      // On masque un mois entièrement verrouillé plutôt que d'afficher
      // des dizaines de lignes grisées inutilisables.
      $has_open = false;
      foreach ($days as $d) {
          if (!psc_is_locked($d->jour_date)) { $has_open = true; break; }
      }

      // Résumé du mois : nombre de jours déclarés et montant correspondant,
      // affichés dans l'en-tête pour éviter d'avoir à déplier chaque mois.
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

        <table class="psc-calendar" data-testid="calendar-table-<?php echo esc_attr($child_index); ?>-<?php echo esc_attr($month_key); ?>">
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
            <tr class="<?php echo $locked ? 'psc-row-locked' : ''; ?>" data-testid="day-row-<?php echo esc_attr($child_index); ?>-<?php echo esc_attr($date); ?>">
              <th scope="row" class="psc-daylabel">
                <?php echo esc_html(psc_day_label($date) . ' ' . date_i18n('d/m', strtotime($date))); ?>
                <?php if ($locked): ?>
                  <span class="psc-lock" title="Délai de modification dépassé" aria-label="Délai de modification dépassé">&#128274;</span>
                <?php endif; ?>
              </th>
              <?php foreach (psc_allowed_services() as $s):
                  $checked = isset($reg_map[$child->id . '|' . $date . '|' . $s]);
                  $cell_testid = $child_index . '-' . $date . '-' . $s;
              ?>
                <td class="psc-cell" data-testid="cell-<?php echo esc_attr($cell_testid); ?>">
                  <input type="checkbox" class="psc-check"
                         aria-label="<?php echo esc_attr(
                             $services[$s]['label'] . ' — ' . psc_day_label($date) . ' '
                             . date_i18n('d/m', strtotime($date)) . ' — ' . $child->prenom
                             . ($locked ? ' (non modifiable)' : '')
                         ); ?>"
                         data-child="<?php echo esc_attr($child->id); ?>"
                         data-date="<?php echo esc_attr($date); ?>"
                         data-service="<?php echo esc_attr($s); ?>"
                         data-price="<?php echo esc_attr($services[$s]['price']); ?>"
                         data-testid="check-<?php echo esc_attr($cell_testid); ?>"
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

  <?php $child_index++; endforeach; ?>

  <div class="psc-confirm-block" data-testid="confirm-block">
    <h3>Confirmer mon planning</h3>
    <p>
      Vos inscriptions sont déjà enregistrées. Ce bouton vous envoie par e-mail
      un récapitulatif complet, à conserver comme preuve de votre saisie.
    </p>
    <button type="button" class="psc-btn" id="psc-confirm" data-testid="confirm-button">Valider et recevoir mon planning</button>
    <p class="psc-confirm-feedback" id="psc-confirm-feedback" role="status" aria-live="polite" data-testid="confirm-feedback"></p>
  </div>

<?php endif; ?>

<?php /* ---- Section gestion des enfants ---- */ ?>
<details class="psc-children-mgmt" <?php echo $child_msg ? 'open' : ''; ?>>
  <summary><strong>Mes enfants</strong></summary>

  <?php if (!empty($all_children)): ?>
  <table class="psc-children-table">
    <thead>
      <tr>
        <th>Prénom</th><th>Nom</th><th>Classe</th><th>Régime cantine</th><th>Actif</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($all_children as $c): ?>
      <tr class="<?php echo (int)$c->active ? '' : 'psc-child-inactive'; ?>">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psc-child-update-form">
          <?php wp_nonce_field('psc_parent_update_child'); ?>
          <input type="hidden" name="action" value="psc_parent_update_child">
          <input type="hidden" name="child_id" value="<?php echo esc_attr($c->id); ?>">
          <td><?php echo esc_html($c->prenom); ?></td>
          <td><?php echo esc_html($c->nom); ?></td>
          <td>
            <select name="classe" aria-label="Classe de <?php echo esc_attr($c->prenom); ?>">
              <?php foreach (psc_classe_options() as $v => $l): ?>
              <option value="<?php echo esc_attr($v); ?>" <?php selected($c->classe, $v); ?>><?php echo esc_html($l); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="psc-diet-options">
            <label>
              <input type="checkbox" name="sans_porc" value="1" <?php checked((int)$c->sans_porc, 1); ?>>
              Sans porc
            </label>
            <label>
              <input type="checkbox" name="vegan" value="1" <?php checked((int)$c->vegan, 1); ?>>
              Vegan
            </label>
          </td>
          <td>
            <label class="psc-toggle" aria-label="Activer ou désactiver <?php echo esc_attr($c->prenom); ?>">
              <input type="checkbox" name="active" value="1" <?php checked((int)$c->active, 1); ?>>
              <span class="psc-toggle-track"></span>
            </label>
          </td>
          <td><button type="submit" class="psc-btn-sm">Enregistrer</button></td>
        </form>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <details class="psc-add-child-block">
    <summary>Ajouter un enfant</summary>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psc-add-child-form">
      <?php wp_nonce_field('psc_parent_add_child'); ?>
      <input type="hidden" name="action" value="psc_parent_add_child">
      <div class="psc-child-row">
        <label class="screen-reader-text" for="psc-new-prenom">Prénom</label>
        <input id="psc-new-prenom" type="text" name="new_prenom" placeholder="Prénom" maxlength="190" required>
        <label class="screen-reader-text" for="psc-new-nom">Nom</label>
        <input id="psc-new-nom" type="text" name="new_nom" placeholder="Nom" maxlength="190" required>
        <label class="screen-reader-text" for="psc-new-classe">Classe</label>
        <select id="psc-new-classe" name="new_classe">
          <?php foreach (psc_classe_options() as $v => $l): ?>
          <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($l); ?></option>
          <?php endforeach; ?>
        </select>
        <span class="psc-diet-options">
          <label><input type="checkbox" name="new_sans_porc" value="1"> Sans porc</label>
          <label><input type="checkbox" name="new_vegan" value="1"> Vegan</label>
        </span>
        <button type="submit" class="psc-btn-sm">Ajouter</button>
      </div>
    </form>
  </details>
</details>

<?php /* ---- Section factures ---- */ ?>
<details class="psc-invoices-mgmt">
  <summary><strong>Mes factures</strong></summary>

  <?php if (empty($invoices)): ?>
  <p class="psc-help">Aucune facture n'a encore été émise pour votre famille.</p>
  <?php else: ?>
  <table class="psc-invoices-table">
    <thead>
      <tr><th>Mois</th><th>Montant</th><th>Statut</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($invoices as $inv): ?>
      <tr>
        <td><?php echo esc_html(Psc_Invoices::month_label($inv->mois)); ?></td>
        <td><?php echo esc_html(number_format_i18n((float) $inv->total, 2)); ?> €</td>
        <td>
          <?php if ($inv->sent_at): ?>
            <span class="psc-active">Envoyée le <?php echo esc_html(date_i18n('d/m/Y', strtotime($inv->sent_at))); ?></span>
          <?php else: ?>
            <em>Pas encore envoyée</em>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($inv->pdf_path): ?>
          <a class="psc-btn-sm"
             href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'psc_parent_download_invoice', 'invoice_id' => $inv->id), admin_url('admin-post.php')), 'psc_parent_download_invoice_' . $inv->id)); ?>">
            Télécharger
          </a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</details>

</div>
