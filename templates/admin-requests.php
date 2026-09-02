<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1><?php esc_html_e("Demandes d'inscription", 'periscolaire-registration'); ?></h1>

<?php
$psc_notices = array(
    'approved'   => array('success', __("Demande validée. La famille a reçu son lien d'accès par e-mail.", 'periscolaire-registration')),
    'rejected'   => array('success', __('Demande refusée.', 'periscolaire-registration')),
    'deleted'    => array('success', __('Demande supprimée.', 'periscolaire-registration')),
    'invalid'    => array('error', __('Demande introuvable ou déjà traitée.', 'periscolaire-registration')),
    'need_child' => array('error', __('Indiquez au moins un enfant (nom et prénom) avant de valider.', 'periscolaire-registration')),
    'child_bad_birthdate' => array('error', __('Date de naissance incohérente : jamais dans le futur, et au moins 3 ans au 1er septembre de l\'année en cours.', 'periscolaire-registration')),
);
psc_admin_notice_map($psc_notices, $psc_msg); ?>

<div class="psc-box">
<p>
  <?php esc_html_e("Seules les demandes dont l'adresse e-mail a été", 'periscolaire-registration'); ?>
  <strong><?php esc_html_e('confirmée par le parent', 'periscolaire-registration'); ?></strong>
  <?php esc_html_e("apparaissent ici : les formulaires remplis par des robots n'atteignent jamais cette page.", 'periscolaire-registration'); ?>
</p>
<p>
  <?php esc_html_e('Les informations affichées sont', 'periscolaire-registration'); ?>
  <strong><?php esc_html_e('déclaratives', 'periscolaire-registration'); ?></strong>
  <?php esc_html_e(' : elles ont été saisies librement par le demandeur. Vérifiez-les (et corrigez-les si besoin) avant de valider.', 'periscolaire-registration'); ?>
</p>
</div>

<h2><?php esc_html_e('En attente', 'periscolaire-registration'); ?> <?php if ($pending): ?><span class="psc-count"><?php echo count($pending); ?></span><?php endif; ?></h2>

<?php if (empty($pending)): ?>
  <div class="psc-box"><p><?php esc_html_e('Aucune demande en attente.', 'periscolaire-registration'); ?></p></div>
<?php else: foreach ($pending as $req):
    $req_children = Psc_Requests::children_of($req); ?>

  <div class="psc-box psc-request">
    <?php $req_family_name = trim(($req->prenom ?? '') . ' ' . ($req->nom ?? '')); ?>
    <h3><?php echo $req_family_name !== '' ? esc_html($req_family_name) : esc_html($req->email); ?></h3>

    <table class="widefat psc-request-meta">
      <tr><th><?php esc_html_e('E-mail', 'periscolaire-registration'); ?></th><td><?php echo esc_html($req->email); ?></td></tr>
      <?php if ($req->telephone): ?>
      <tr><th><?php esc_html_e('Téléphone', 'periscolaire-registration'); ?></th><td><?php echo esc_html($req->telephone); ?></td></tr>
      <?php endif; ?>
      <tr><th><?php esc_html_e('Reçue le', 'periscolaire-registration'); ?></th><td><?php echo esc_html(date_i18n('d/m/Y à H:i', strtotime($req->created_at))); ?></td></tr>
      <?php if ($req->message): ?>
      <tr><th><?php esc_html_e('Message', 'periscolaire-registration'); ?></th><td><?php echo nl2br(esc_html($req->message)); ?></td></tr>
      <?php endif; ?>
      <tr>
        <th><?php esc_html_e('Règlement intérieur', 'periscolaire-registration'); ?></th>
        <td>
          <?php if (!empty($req->reglement_accepted_at)): ?>
            <span style="color:#46b450">✔ <?php esc_html_e('Accepté le', 'periscolaire-registration'); ?> <?php echo esc_html(date_i18n('d/m/Y à H:i', strtotime($req->reglement_accepted_at))); ?></span>
          <?php else: ?>
            <span style="color:#b32d2e">✘ <?php esc_html_e('Non accepté', 'periscolaire-registration'); ?></span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th><?php esc_html_e('Mode de paiement', 'periscolaire-registration'); ?></th>
        <td>
          <?php if (($req->payment_mode ?? 'autre') === 'prelevement'): ?>
            <strong><?php esc_html_e('Prélèvement automatique (SEPA)', 'periscolaire-registration'); ?></strong>
            <ul style="margin:6px 0 0;padding-left:18px;">
              <li><?php esc_html_e('Titulaire :', 'periscolaire-registration'); ?> <?php echo esc_html($req->sepa_titulaire ?: '—'); ?></li>
              <li><?php esc_html_e('Adresse :', 'periscolaire-registration'); ?> <?php echo esc_html(trim(($req->sepa_adresse ?? '') . ' ' . ($req->sepa_code_postal ?? '') . ' ' . ($req->sepa_ville ?? '')) ?: '—'); ?></li>
              <li><?php esc_html_e('IBAN :', 'periscolaire-registration'); ?> <?php echo esc_html($req->sepa_iban ? psc_mask_iban(psc_read_iban($req)) : '—'); ?></li>
              <li><?php esc_html_e('BIC :', 'periscolaire-registration'); ?> <?php echo esc_html($req->sepa_bic ?: '—'); ?></li>
              <li><?php esc_html_e('Règlement prélèvement :', 'periscolaire-registration'); ?>
                <?php if (!empty($req->sepa_reglement_accepted_at)): ?>
                  <span style="color:#46b450">✔ <?php esc_html_e('Accepté le', 'periscolaire-registration'); ?> <?php echo esc_html(date_i18n('d/m/Y à H:i', strtotime($req->sepa_reglement_accepted_at))); ?></span>
                <?php else: ?>
                  <span style="color:#b32d2e">✘ <?php esc_html_e('Non accepté', 'periscolaire-registration'); ?></span>
                <?php endif; ?>
              </li>
            </ul>
          <?php else: ?>
            <?php esc_html_e('Chèque ou espèces', 'periscolaire-registration'); ?>
          <?php endif; ?>
        </td>
      </tr>
    </table>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psc-approve-form">
      <?php wp_nonce_field('psc_approve_request'); ?>
      <input type="hidden" name="action" value="psc_approve_request">
      <input type="hidden" name="id" value="<?php echo esc_attr($req->id); ?>">

      <h4><?php esc_html_e('Enfant(s) déclaré(s) — corrigez si nécessaire', 'periscolaire-registration'); ?></h4>
      <table class="widefat striped">
        <thead><tr><th><?php esc_html_e('Prénom', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Nom', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Classe', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Naissance', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Régime cantine', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Allergies alimentaires', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Assurance', 'periscolaire-registration'); ?></th></tr></thead>
        <tbody>
        <?php for ($i = 0; $i < Psc_Requests::MAX_CHILDREN; $i++):
            $c = isset($req_children[$i]) ? $req_children[$i] : null;
            if (!$c && $i >= count($req_children) + 1) break; // une ligne vide en plus
        ?>
          <tr>
            <td><input type="text" name="child_prenom_<?php echo (int) $i; ?>" maxlength="190"
                       value="<?php echo $c ? esc_attr($c['prenom']) : ''; ?>"></td>
            <td><input type="text" name="child_nom_<?php echo (int) $i; ?>" maxlength="190"
                       value="<?php echo $c ? esc_attr($c['nom']) : ''; ?>"></td>
            <td><input type="text" name="child_classe_<?php echo (int) $i; ?>" maxlength="100"
                       value="<?php echo $c ? esc_attr($c['classe']) : ''; ?>"></td>
            <td><input type="date" name="child_naissance_<?php echo (int) $i; ?>"
                       max="<?php echo esc_attr(psc_child_birthdate_max()); ?>"
                       value="<?php echo ($c && !empty($c['date_naissance'])) ? esc_attr($c['date_naissance']) : ''; ?>"></td>
            <td class="psc-diet-options">
              <label><input type="checkbox" name="child_sans_porc_<?php echo (int) $i; ?>" value="1" <?php checked($c && !empty($c['sans_porc'])); ?>> <?php esc_html_e('Sans porc', 'periscolaire-registration'); ?></label>
              <label><input type="checkbox" name="child_vegan_<?php echo (int) $i; ?>" value="1" <?php checked($c && !empty($c['vegan'])); ?>> <?php esc_html_e('Sans viande', 'periscolaire-registration'); ?></label>
            </td>
            <td>
              <?php if ($c && !empty($c['food_allergies'])): ?>
                <div style="max-width:220px;line-height:1.45;color:#9E4A4A;font-weight:600;"><?php echo esc_html($c['food_allergies']); ?></div>
                <p class="description" style="margin:4px 0 0;max-width:220px;"><?php esc_html_e("La mairie contactera la famille si un PAI doit être mis en place. Aucun menu différencié : l'enfant apporte son repas.", 'periscolaire-registration'); ?></p>
              <?php else: ?>
                <span style="color:#b32d2e">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($c && !empty($c['assurance_rel_path'])): ?>
                <span style="color:#46b450">✔ <?php esc_html_e('Fournie', 'periscolaire-registration'); ?></span> —
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=psc_download_pending_assurance&request_id=' . $req->id . '&index=' . $i), 'psc_download_pending_assurance_' . $req->id . '_' . $i)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Voir le fichier', 'periscolaire-registration'); ?></a>
              <?php elseif ($c): ?>
                <span style="color:#b32d2e">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>

      <p class="psc-actions">
        <button class="button button-primary"
                onclick="return confirm('<?php echo esc_js(__("Valider cette demande ? La famille recevra immédiatement son lien d'accès.", 'periscolaire-registration')); ?>');">
          <?php esc_html_e("Valider et donner l'accès", 'periscolaire-registration'); ?>
        </button>
      </p>
    </form>

    <details class="psc-reject">
      <summary><?php esc_html_e('Refuser cette demande', 'periscolaire-registration'); ?></summary>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('psc_reject_request'); ?>
        <input type="hidden" name="action" value="psc_reject_request">
        <input type="hidden" name="id" value="<?php echo esc_attr($req->id); ?>">
        <p>
          <label for="psc-note-<?php echo esc_attr($req->id); ?>"><?php esc_html_e('Motif (facultatif)', 'periscolaire-registration'); ?></label><br>
          <textarea id="psc-note-<?php echo esc_attr($req->id); ?>" name="note" rows="2" class="large-text" maxlength="1000"></textarea>
        </p>
        <p>
          <label>
            <input type="checkbox" name="notify" value="1" checked>
            <?php esc_html_e('Informer le demandeur par e-mail', 'periscolaire-registration'); ?>
          </label>
        </p>
        <p><button class="button"><?php esc_html_e('Refuser la demande', 'periscolaire-registration'); ?></button></p>
      </form>
    </details>
  </div>

<?php endforeach; endif; ?>

<h2><?php esc_html_e('Demandes traitées', 'periscolaire-registration'); ?></h2>
<div class="psc-box">
<table class="widefat striped">
<thead><tr><th><?php esc_html_e('E-mail', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Famille', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Décision', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Date', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Motif', 'periscolaire-registration'); ?></th><th></th></tr></thead>
<tbody>
<?php if (empty($handled)): ?>
<tr><td colspan="6"><?php esc_html_e('Aucune demande traitée.', 'periscolaire-registration'); ?></td></tr>
<?php else: foreach ($handled as $h): ?>
<tr>
<td><?php echo esc_html($h->email); ?></td>
<td><?php $h_family_name = trim(($h->prenom ?? '') . ' ' . ($h->nom ?? '')); ?><?php echo $h_family_name !== '' ? esc_html($h_family_name) : '—'; ?></td>
<td><?php echo $h->status === 'approved'
        ? '<span class="psc-active">' . esc_html__('Validée', 'periscolaire-registration') . '</span>'
        : '<em>' . esc_html__('Refusée', 'periscolaire-registration') . '</em>'; ?></td>
<td><?php echo $h->decided_at ? esc_html(date_i18n('d/m/Y', strtotime($h->decided_at))) : '—'; ?></td>
<td><?php echo $h->note ? esc_html($h->note) : '—'; ?></td>
<td>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
        onsubmit="return confirm('<?php echo esc_js(__('Supprimer définitivement cette demande ?', 'periscolaire-registration')); ?>');">
    <?php wp_nonce_field('psc_delete_request'); ?>
    <input type="hidden" name="action" value="psc_delete_request">
    <input type="hidden" name="id" value="<?php echo esc_attr($h->id); ?>">
    <button class="button button-link-delete"><?php esc_html_e('Supprimer', 'periscolaire-registration'); ?></button>
  </form>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
<p class="description">
  <?php esc_html_e('Les demandes traitées sont supprimées automatiquement au bout de 90 jours, et les demandes non confirmées au bout de 7 jours.', 'periscolaire-registration'); ?>
</p>
</div>
</div>
