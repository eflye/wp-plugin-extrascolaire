<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1><?php esc_html_e('Commande fournisseur', 'periscolaire-registration'); ?></h1>

<?php
$psc_notices = array(
    'sent'                    => array('updated',  __('Commande envoyée au fournisseur.', 'periscolaire-registration')),
    'psc_invalid_week'        => array('error',    __('Date de semaine invalide.', 'periscolaire-registration')),
    'psc_no_supplier_email'   => array('error',    __("Aucune adresse e-mail fournisseur n'est configurée. Renseignez-la dans Périscolaire > Réglages.", 'periscolaire-registration')),
    'psc_mail_failed'         => array('error',    __("L'envoi du mail a échoué. Vérifiez la configuration e-mail.", 'periscolaire-registration')),
    'error'                   => array('error',    __('Une erreur est survenue.', 'periscolaire-registration')),
    'cantine_invalid'         => array('error',    __('Date invalide.', 'periscolaire-registration')),
    'cantine_reason_required' => array('error',    __("Merci d'indiquer un motif.", 'periscolaire-registration')),
    'cantine_none'            => array('warning',  __('Aucune inscription cantine trouvée pour cette classe ce jour-là.', 'periscolaire-registration')),
    'cantine_confirm_needed'  => array('warning',  __('Confirmation nécessaire : des familles ont déjà déclaré cette cantine.', 'periscolaire-registration')),
    'cantine_dismissed'       => array('updated',  __("Annulation abandonnée, rien n'a été modifié.", 'periscolaire-registration')),
    'cantine_cancelled'       => array('updated',  __('Cantine annulée pour la classe :', 'periscolaire-registration') . ' ' . $cantine_n . ' ' . __('inscription(s) supprimée(s), famille(s) prévenue(s) par e-mail.', 'periscolaire-registration')),
);
psc_admin_notice_map($psc_notices, $psc_msg, $psc_msg);
?>

<div class="psc-box">
<p>
    <?php esc_html_e("Nombre de repas de cantine et de goûters (garderie du soir) par classe, pour la semaine choisie. L'envoi au fournisseur est toujours manuel — aucun envoi automatique ni planifié.", 'periscolaire-registration'); ?>
</p>
<form method="get" style="display:flex;align-items:center;gap:10px;">
    <input type="hidden" name="page" value="psc_supplier_orders">
    <label for="psc-sup-week"><strong><?php esc_html_e('Semaine du', 'periscolaire-registration'); ?></strong></label>
    <input id="psc-sup-week" type="date" name="semaine_debut" value="<?php echo esc_attr($preview['semaine_debut'] ?? ''); ?>" data-testid="supplier-week-input">
    <button type="submit" class="button" data-testid="supplier-refresh-button"><?php esc_html_e("Actualiser l'aperçu", 'periscolaire-registration'); ?></button>
</form>
</div>

<?php if (is_wp_error($preview)): ?>
<div class="psc-box"><p><?php echo esc_html($preview->get_error_message()); ?></p></div>
<?php else: ?>
<div class="psc-box" data-testid="supplier-preview">
<h2><?php esc_html_e('Aperçu — semaine du', 'periscolaire-registration'); ?> <?php echo esc_html(date_i18n('d/m/Y', strtotime($preview['semaine_debut']))); ?></h2>

<?php if (empty($preview['jours'])): ?>
<p data-testid="supplier-preview-empty"><em><?php esc_html_e("Aucun jour d'école cette semaine-là (vacances scolaires ou jour férié) — pas de service cantine, rien à commander.", 'periscolaire-registration'); ?></em></p>
<?php else: ?>

<table class="widefat striped psc-recap" data-testid="supplier-quantities-table">
<thead>
<tr>
    <th rowspan="2"><?php esc_html_e('Jour', 'periscolaire-registration'); ?></th>
    <th colspan="4" style="text-align:center;color:#E08A5F;"><?php esc_html_e('Repas de midi', 'periscolaire-registration'); ?></th>
    <th rowspan="2" style="text-align:center;border-left:2px solid #EDEAE4;"><?php esc_html_e('Goûters', 'periscolaire-registration'); ?></th>
</tr>
<tr>
    <th style="text-align:center"><?php esc_html_e('Standard', 'periscolaire-registration'); ?></th>
    <th style="text-align:center"><?php esc_html_e('Sans porc', 'periscolaire-registration'); ?></th>
    <th style="text-align:center"><?php esc_html_e('Végétarien', 'periscolaire-registration'); ?></th>
    <th style="text-align:center"><?php esc_html_e('Total midi', 'periscolaire-registration'); ?></th>
</tr>
</thead>
<tbody>
<?php foreach ($preview['rows'] as $jour => $row): ?>
<tr data-testid="supplier-row-<?php echo esc_attr($jour); ?>">
    <td><strong><?php echo esc_html(ucfirst($jour)); ?></strong> <?php echo esc_html(date_i18n('d/m', strtotime($preview['jours'][$jour]))); ?></td>
    <td style="text-align:center" data-testid="supplier-cell-<?php echo esc_attr($jour); ?>-standard"><?php echo (int) $row['standard']; ?></td>
    <td style="text-align:center" data-testid="supplier-cell-<?php echo esc_attr($jour); ?>-sansporc"><?php echo (int) $row['sans_porc']; ?></td>
    <td style="text-align:center" data-testid="supplier-cell-<?php echo esc_attr($jour); ?>-vegetarien"><?php echo (int) $row['vegetarien']; ?></td>
    <td style="text-align:center;font-weight:bold" data-testid="supplier-cell-<?php echo esc_attr($jour); ?>-midi"><?php echo (int) $row['midi']; ?></td>
    <td style="text-align:center;border-left:2px solid #EDEAE4" data-testid="supplier-cell-<?php echo esc_attr($jour); ?>-gouter"><?php echo (int) $row['gouter']; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr>
    <th><?php esc_html_e('Total semaine', 'periscolaire-registration'); ?></th>
    <th style="text-align:center" data-testid="supplier-total-standard"><?php echo (int) $preview['totaux']['standard']; ?></th>
    <th style="text-align:center" data-testid="supplier-total-sansporc"><?php echo (int) $preview['totaux']['sans_porc']; ?></th>
    <th style="text-align:center" data-testid="supplier-total-vegetarien"><?php echo (int) $preview['totaux']['vegetarien']; ?></th>
    <th style="text-align:center" data-testid="supplier-total-midi"><?php echo (int) $preview['totaux']['midi']; ?></th>
    <th style="text-align:center;border-left:2px solid #EDEAE4" data-testid="supplier-total-gouter"><?php echo (int) $preview['totaux']['gouter']; ?></th>
</tr>
</tfoot>
</table>
<p class="description"><?php esc_html_e("Goûters servis à la garderie du soir. Les enfants porteurs d'une allergie alimentaire apportent le leur : ils ne sont pas comptés.", 'periscolaire-registration'); ?></p>

<button type="button" class="button button-primary" data-testid="supplier-send-button" id="psc-sup-send-open">
    &#9993; <?php esc_html_e('Envoyer au fournisseur (', 'periscolaire-registration'); ?><?php echo (int) $preview['total']; ?> <?php esc_html_e('repas +', 'periscolaire-registration'); ?> <?php echo (int) $preview['total_gouters']; ?> <?php esc_html_e('goûters)', 'periscolaire-registration'); ?>
</button>

<?php /* Popin de confirmation : la mairie visualise l'e-mail exact qui
   partira (rendu autonome, identique à l'archivé) avant de confirmer. */ ?>
<div id="psc-sup-send-modal" class="psc-sup-modal-overlay" hidden data-testid="supplier-send-modal">
    <div class="psc-sup-modal" role="dialog" aria-modal="true" aria-labelledby="psc-sup-send-title" tabindex="-1">
        <h2 id="psc-sup-send-title"><?php esc_html_e('Confirmer l’envoi au fournisseur', 'periscolaire-registration'); ?></h2>
        <p class="description">
            <?php esc_html_e('Voici l’e-mail qui sera envoyé à', 'periscolaire-registration'); ?>
            <strong><?php echo esc_html(get_option('psc_supplier_email', '')); ?></strong>.
            <?php esc_html_e('Vérifiez les quantités, puis confirmez.', 'periscolaire-registration'); ?>
        </p>
        <p style="margin:8px 0 0;">
            <strong><?php esc_html_e('Sujet :', 'periscolaire-registration'); ?></strong>
            <span data-testid="supplier-modal-subject"><?php echo esc_html($email_preview ? $email_preview['subject'] : ''); ?></span>
        </p>
        <iframe title="<?php esc_attr_e('Aperçu de l’e-mail qui sera envoyé', 'periscolaire-registration'); ?>"
                data-testid="supplier-modal-iframe"
                srcdoc="<?php echo esc_attr($email_preview ? $email_preview['html'] : ''); ?>"
                style="width:100%;height:420px;border:1px solid #dcdcde;margin-top:8px;background:#fff;"></iframe>
        <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="button" data-testid="supplier-modal-cancel" id="psc-sup-send-cancel"><?php esc_html_e('Retour', 'periscolaire-registration'); ?></button>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                <?php wp_nonce_field('psc_send_supplier_order'); ?>
                <input type="hidden" name="action" value="psc_send_supplier_order">
                <input type="hidden" name="semaine_debut" value="<?php echo esc_attr($preview['semaine_debut']); ?>">
                <button type="submit" class="button button-primary" data-testid="supplier-modal-confirm"><?php esc_html_e('Confirmer l’envoi', 'periscolaire-registration'); ?></button>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var openBtn = document.getElementById('psc-sup-send-open');
    var modal = document.getElementById('psc-sup-send-modal');
    if (!openBtn || !modal) return;
    var cancelBtn = document.getElementById('psc-sup-send-cancel');

    function open() {
        modal.hidden = false;
        var confirmBtn = modal.querySelector('[data-testid="supplier-modal-confirm"]');
        if (confirmBtn) confirmBtn.focus();
    }
    function close() {
        modal.hidden = true;
        openBtn.focus();
    }

    openBtn.addEventListener('click', open);
    if (cancelBtn) cancelBtn.addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) close();
    });
})();
</script>
<?php endif; ?>
</div>
<?php endif; ?>

<?php if ($pending_cantine): ?>
<div class="psc-box" style="border-left:4px solid #f5a623;" data-testid="cantine-pending-warning">
<h2>⚠ <?php esc_html_e('Confirmation nécessaire', 'periscolaire-registration'); ?></h2>
<p>
    <?php esc_html_e('Annuler la cantine de la classe', 'periscolaire-registration'); ?> <strong><?php echo esc_html($pending_cantine['classe'] !== '' ? (Psc_School_Years::classe_options()[$pending_cantine['classe']] ?? $pending_cantine['classe']) : __('Non renseignée', 'periscolaire-registration')); ?></strong>
    <?php esc_html_e('le', 'periscolaire-registration'); ?> <strong><?php echo esc_html(psc_day_label($pending_cantine['date']) . ' ' . date_i18n('d/m/Y', strtotime($pending_cantine['date']))); ?></strong>
    <?php esc_html_e('supprimera', 'periscolaire-registration'); ?> <strong><?php echo count($pending_cantine_affected); ?> <?php esc_html_e('inscription(s)', 'periscolaire-registration'); ?></strong> <?php esc_html_e('déjà déclarée(s). Ces prestations ne seront pas facturées, et chaque famille concernée recevra un e-mail avec le motif indiqué :', 'periscolaire-registration'); ?> «&nbsp;<?php echo esc_html($pending_cantine['reason']); ?>&nbsp;».
</p>
<table class="widefat striped" style="margin-bottom:16px;">
<thead><tr><th><?php esc_html_e('Famille', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Enfant', 'periscolaire-registration'); ?></th></tr></thead>
<tbody>
<?php foreach ($pending_cantine_affected as $row): ?>
<tr>
    <td><?php echo esc_html($row->parent_nom ?: $row->email); ?></td>
    <td><?php echo esc_html($row->child_prenom . ' ' . $row->child_nom); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
<?php wp_nonce_field('psc_cancel_class_meals'); ?>
<input type="hidden" name="action" value="psc_cancel_class_meals">
<input type="hidden" name="date" value="<?php echo esc_attr($pending_cantine['date']); ?>">
<input type="hidden" name="classe" value="<?php echo esc_attr($pending_cantine['classe']); ?>">
<input type="hidden" name="reason" value="<?php echo esc_attr($pending_cantine['reason']); ?>">
<input type="hidden" name="confirm" value="1">
<button type="submit" class="button button-primary" data-testid="cantine-confirm-button"><?php esc_html_e("Confirmer l'annulation", 'periscolaire-registration'); ?></button>
</form>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
<?php wp_nonce_field('psc_dismiss_cancel_class_meals'); ?>
<input type="hidden" name="action" value="psc_dismiss_cancel_class_meals">
<button type="submit" class="button" data-testid="cantine-dismiss-button"><?php esc_html_e('Annuler', 'periscolaire-registration'); ?></button>
</form>
</div>
<?php endif; ?>

<div class="psc-box">
<h2><?php esc_html_e('Annuler la cantine pour une classe', 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e('Sortie scolaire ou fermeture ponctuelle touchant une classe entière — usage exceptionnel. Les familles concernées sont prévenues par e-mail avec le motif indiqué.', 'periscolaire-registration'); ?></p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_cancel_class_meals'); ?>
<input type="hidden" name="action" value="psc_cancel_class_meals">
<table class="form-table">
<tr>
<th><label for="psc-cantine-date"><?php esc_html_e('Jour', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-cantine-date" type="date" name="date" required data-testid="cantine-date-input"></td>
</tr>
<tr>
<th><label for="psc-cantine-classe"><?php esc_html_e('Classe', 'periscolaire-registration'); ?></label></th>
<td>
<select id="psc-cantine-classe" name="classe" data-testid="cantine-classe-select">
<?php foreach (Psc_School_Years::classe_options() as $code => $label): ?>
<option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label ?: __('Non renseignée', 'periscolaire-registration')); ?></option>
<?php endforeach; ?>
</select>
</td>
</tr>
<tr>
<th><label for="psc-cantine-reason"><?php esc_html_e('Motif', 'periscolaire-registration'); ?></label></th>
<td><textarea id="psc-cantine-reason" name="reason" rows="2" class="large-text" maxlength="500" required placeholder="<?php esc_attr_e('Ex : Sortie scolaire à la ferme pédagogique', 'periscolaire-registration'); ?>" data-testid="cantine-reason-input"></textarea></td>
</tr>
</table>
<?php submit_button(__('Annuler la cantine', 'periscolaire-registration'), 'secondary', 'submit', false, array('data-testid' => 'cantine-cancel-submit')); ?>
</form>
</div>

<div class="psc-box">
<h2><?php esc_html_e('Historique des envois', 'periscolaire-registration'); ?></h2>
<?php if (empty($recent)): ?>
<p data-testid="supplier-history-empty"><?php esc_html_e('Aucune commande envoyée pour le moment.', 'periscolaire-registration'); ?></p>
<?php else: ?>
<table class="widefat striped" data-testid="supplier-history-table">
<thead>
<tr>
    <th><?php esc_html_e('Semaine', 'periscolaire-registration'); ?></th>
    <th><?php esc_html_e('Total repas', 'periscolaire-registration'); ?></th>
    <th><?php esc_html_e('Destinataire', 'periscolaire-registration'); ?></th>
    <th><?php esc_html_e('Envoyée le', 'periscolaire-registration'); ?></th>
    <th></th>
</tr>
</thead>
<tbody>
<?php foreach ($recent as $h): ?>
<tr data-testid="supplier-history-row-<?php echo esc_attr($h->id); ?>">
    <td data-testid="supplier-history-semaine-<?php echo esc_attr($h->id); ?>"><?php echo esc_html(date_i18n('d/m/Y', strtotime($h->semaine_debut))); ?></td>
    <td data-testid="supplier-history-total-<?php echo esc_attr($h->id); ?>"><?php echo (int) $h->total_repas; ?></td>
    <td data-testid="supplier-history-email-<?php echo esc_attr($h->id); ?>"><?php echo esc_html($h->supplier_email); ?></td>
    <td data-testid="supplier-history-date-<?php echo esc_attr($h->id); ?>"><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($h->sent_at))); ?></td>
    <td>
        <details data-testid="supplier-history-details-<?php echo esc_attr($h->id); ?>">
            <summary><?php esc_html_e('Voir le contenu envoyé', 'periscolaire-registration'); ?></summary>
            <p><strong><?php esc_html_e('Sujet :', 'periscolaire-registration'); ?></strong> <span data-testid="supplier-history-subject-<?php echo esc_attr($h->id); ?>"><?php echo esc_html($h->email_subject); ?></span></p>
            <iframe title="<?php esc_attr_e("Contenu de l'e-mail envoyé le", 'periscolaire-registration'); ?> <?php echo esc_attr(date_i18n('d/m/Y H:i', strtotime($h->sent_at))); ?>"
                    data-testid="supplier-history-iframe-<?php echo esc_attr($h->id); ?>"
                    srcdoc="<?php echo esc_attr($h->email_body); ?>"
                    style="width:100%;max-width:700px;height:420px;border:1px solid #dcdcde;margin-top:8px;"></iframe>
        </details>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</div>
