<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Demandes d'inscription</h1>

<?php
$psc_notices = array(
    'approved'   => array('success', 'Demande validée. La famille a reçu son lien d\'accès par e-mail.'),
    'rejected'   => array('success', 'Demande refusée.'),
    'deleted'    => array('success', 'Demande supprimée.'),
    'invalid'    => array('error', 'Demande introuvable ou déjà traitée.'),
    'need_child' => array('error', 'Indiquez au moins un enfant (nom et prénom) avant de valider.'),
);
psc_admin_notice_map($psc_notices, $psc_msg); ?>

<div class="psc-box">
<p>
  Seules les demandes dont l'adresse e-mail a été <strong>confirmée par le parent</strong>
  apparaissent ici : les formulaires remplis par des robots n'atteignent jamais cette page.
</p>
<p>
  Les informations affichées sont <strong>déclaratives</strong> : elles ont été saisies
  librement par le demandeur. Vérifiez-les (et corrigez-les si besoin) avant de valider.
</p>
</div>

<h2>En attente <?php if ($pending): ?><span class="psc-count"><?php echo count($pending); ?></span><?php endif; ?></h2>

<?php if (empty($pending)): ?>
  <div class="psc-box"><p>Aucune demande en attente.</p></div>
<?php else: foreach ($pending as $req):
    $req_children = Psc_Requests::children_of($req); ?>

  <div class="psc-box psc-request">
    <?php $req_family_name = trim(($req->prenom ?? '') . ' ' . ($req->nom ?? '')); ?>
    <h3><?php echo $req_family_name !== '' ? esc_html($req_family_name) : esc_html($req->email); ?></h3>

    <table class="widefat psc-request-meta">
      <tr><th>E-mail</th><td><?php echo esc_html($req->email); ?></td></tr>
      <?php if ($req->telephone): ?>
      <tr><th>Téléphone</th><td><?php echo esc_html($req->telephone); ?></td></tr>
      <?php endif; ?>
      <tr><th>Reçue le</th><td><?php echo esc_html(date_i18n('d/m/Y à H:i', strtotime($req->created_at))); ?></td></tr>
      <?php if ($req->message): ?>
      <tr><th>Message</th><td><?php echo nl2br(esc_html($req->message)); ?></td></tr>
      <?php endif; ?>
      <tr>
        <th>Règlement intérieur</th>
        <td>
          <?php if (!empty($req->reglement_accepted_at)): ?>
            <span style="color:#46b450">✔ Accepté le <?php echo esc_html(date_i18n('d/m/Y à H:i', strtotime($req->reglement_accepted_at))); ?></span>
          <?php else: ?>
            <span style="color:#b32d2e">✘ Non accepté</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th>Mode de paiement</th>
        <td>
          <?php if (($req->payment_mode ?? 'autre') === 'prelevement'): ?>
            <strong>Prélèvement automatique (SEPA)</strong>
            <ul style="margin:6px 0 0;padding-left:18px;">
              <li>Titulaire : <?php echo esc_html($req->sepa_titulaire ?: '—'); ?></li>
              <li>Adresse : <?php echo esc_html(trim(($req->sepa_adresse ?? '') . ' ' . ($req->sepa_code_postal ?? '') . ' ' . ($req->sepa_ville ?? '')) ?: '—'); ?></li>
              <li>IBAN : <?php echo esc_html($req->sepa_iban ? psc_mask_iban(psc_read_iban($req)) : '—'); ?></li>
              <li>BIC : <?php echo esc_html($req->sepa_bic ?: '—'); ?></li>
              <li>Règlement prélèvement :
                <?php if (!empty($req->sepa_reglement_accepted_at)): ?>
                  <span style="color:#46b450">✔ Accepté le <?php echo esc_html(date_i18n('d/m/Y à H:i', strtotime($req->sepa_reglement_accepted_at))); ?></span>
                <?php else: ?>
                  <span style="color:#b32d2e">✘ Non accepté</span>
                <?php endif; ?>
              </li>
            </ul>
          <?php else: ?>
            Chèque ou espèces
          <?php endif; ?>
        </td>
      </tr>
    </table>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psc-approve-form">
      <?php wp_nonce_field('psc_approve_request'); ?>
      <input type="hidden" name="action" value="psc_approve_request">
      <input type="hidden" name="id" value="<?php echo esc_attr($req->id); ?>">

      <h4>Enfant(s) déclaré(s) — corrigez si nécessaire</h4>
      <table class="widefat striped">
        <thead><tr><th>Prénom</th><th>Nom</th><th>Classe</th><th>Naissance</th><th>Régime cantine</th><th>Assurance</th></tr></thead>
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
                       value="<?php echo ($c && !empty($c['date_naissance'])) ? esc_attr($c['date_naissance']) : ''; ?>"></td>
            <td class="psc-diet-options">
              <label><input type="checkbox" name="child_sans_porc_<?php echo (int) $i; ?>" value="1" <?php checked($c && !empty($c['sans_porc'])); ?>> Sans porc</label>
              <label><input type="checkbox" name="child_vegan_<?php echo (int) $i; ?>" value="1" <?php checked($c && !empty($c['vegan'])); ?>> Sans viande</label>
            </td>
            <td>
              <?php if ($c && !empty($c['assurance_rel_path'])): ?>
                <span style="color:#46b450">✔ Fournie</span> —
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=psc_download_pending_assurance&request_id=' . $req->id . '&index=' . $i), 'psc_download_pending_assurance_' . $req->id . '_' . $i)); ?>" target="_blank" rel="noopener">Voir le fichier</a>
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
                onclick="return confirm('Valider cette demande ? La famille recevra immédiatement son lien d\'accès.');">
          Valider et donner l'accès
        </button>
      </p>
    </form>

    <details class="psc-reject">
      <summary>Refuser cette demande</summary>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('psc_reject_request'); ?>
        <input type="hidden" name="action" value="psc_reject_request">
        <input type="hidden" name="id" value="<?php echo esc_attr($req->id); ?>">
        <p>
          <label for="psc-note-<?php echo esc_attr($req->id); ?>">Motif (facultatif)</label><br>
          <textarea id="psc-note-<?php echo esc_attr($req->id); ?>" name="note" rows="2" class="large-text" maxlength="1000"></textarea>
        </p>
        <p>
          <label>
            <input type="checkbox" name="notify" value="1" checked>
            Informer le demandeur par e-mail
          </label>
        </p>
        <p><button class="button">Refuser la demande</button></p>
      </form>
    </details>
  </div>

<?php endforeach; endif; ?>

<h2>Demandes traitées</h2>
<div class="psc-box">
<table class="widefat striped">
<thead><tr><th>E-mail</th><th>Famille</th><th>Décision</th><th>Date</th><th>Motif</th><th></th></tr></thead>
<tbody>
<?php if (empty($handled)): ?>
<tr><td colspan="6">Aucune demande traitée.</td></tr>
<?php else: foreach ($handled as $h): ?>
<tr>
<td><?php echo esc_html($h->email); ?></td>
<td><?php $h_family_name = trim(($h->prenom ?? '') . ' ' . ($h->nom ?? '')); ?><?php echo $h_family_name !== '' ? esc_html($h_family_name) : '—'; ?></td>
<td><?php echo $h->status === 'approved'
        ? '<span class="psc-active">Validée</span>'
        : '<em>Refusée</em>'; ?></td>
<td><?php echo $h->decided_at ? esc_html(date_i18n('d/m/Y', strtotime($h->decided_at))) : '—'; ?></td>
<td><?php echo $h->note ? esc_html($h->note) : '—'; ?></td>
<td>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
        onsubmit="return confirm('Supprimer définitivement cette demande ?');">
    <?php wp_nonce_field('psc_delete_request'); ?>
    <input type="hidden" name="action" value="psc_delete_request">
    <input type="hidden" name="id" value="<?php echo esc_attr($h->id); ?>">
    <button class="button button-link-delete">Supprimer</button>
  </form>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
<p class="description">
  Les demandes traitées sont supprimées automatiquement au bout de 90 jours,
  et les demandes non confirmées au bout de 7 jours.
</p>
</div>
</div>
