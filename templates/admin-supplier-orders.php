<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Commande fournisseur</h1>

<?php
$psc_notices = array(
    'sent'                    => array('updated',  'Commande envoyée au fournisseur.'),
    'psc_invalid_week'        => array('error',    'Date de semaine invalide.'),
    'psc_no_supplier_email'   => array('error',    "Aucune adresse e-mail fournisseur n'est configurée. Renseignez-la dans Périscolaire > Réglages."),
    'psc_mail_failed'         => array('error',    "L'envoi du mail a échoué. Vérifiez la configuration e-mail."),
    'error'                   => array('error',    'Une erreur est survenue.'),
    'cantine_invalid'         => array('error',    'Date invalide.'),
    'cantine_reason_required' => array('error',    'Merci d\'indiquer un motif.'),
    'cantine_none'            => array('warning',  'Aucune inscription cantine trouvée pour cette classe ce jour-là.'),
    'cantine_confirm_needed'  => array('warning',  'Confirmation nécessaire : des familles ont déjà déclaré cette cantine.'),
    'cantine_dismissed'       => array('updated',  'Annulation abandonnée, rien n\'a été modifié.'),
    'cantine_cancelled'       => array('updated',  'Cantine annulée pour la classe : ' . $cantine_n . ' inscription(s) supprimée(s), famille(s) prévenue(s) par e-mail.'),
);
psc_admin_notice_map($psc_notices, $psc_msg, $psc_msg);
?>

<div class="psc-box">
<p>
    Nombre de repas de cantine par classe, pour la semaine choisie. L'envoi au fournisseur est toujours
    manuel — aucun envoi automatique ni planifié.
</p>
<form method="get" style="display:flex;align-items:center;gap:10px;">
    <input type="hidden" name="page" value="psc_supplier_orders">
    <label for="psc-sup-week"><strong>Semaine du</strong></label>
    <input id="psc-sup-week" type="date" name="semaine_debut" value="<?php echo esc_attr($preview['semaine_debut'] ?? ''); ?>" data-testid="supplier-week-input">
    <button type="submit" class="button" data-testid="supplier-refresh-button">Actualiser l'aperçu</button>
</form>
</div>

<?php if (is_wp_error($preview)): ?>
<div class="psc-box"><p><?php echo esc_html($preview->get_error_message()); ?></p></div>
<?php else: ?>
<div class="psc-box" data-testid="supplier-preview">
<h2>Aperçu — semaine du <?php echo esc_html(date_i18n('d/m/Y', strtotime($preview['semaine_debut']))); ?></h2>

<?php if (empty($preview['jours'])): ?>
<p data-testid="supplier-preview-empty"><em>Aucun jour d'école cette semaine-là (vacances scolaires ou jour férié) — pas de service cantine, rien à commander.</em></p>
<?php else: ?>

<?php if (empty($preview['classes'])): ?>
<p data-testid="supplier-preview-empty">Aucun repas de cantine déclaré pour cette semaine.</p>
<?php else: ?>
<table class="widefat striped psc-recap">
<thead>
<tr>
    <th>Classe</th>
    <?php foreach (array_keys($preview['jours']) as $jour): ?>
    <th style="text-align:center"><?php echo esc_html(Psc_Supplier_Orders::jour_labels()[$jour]); ?><br><small><?php echo esc_html(date_i18n('d/m', strtotime($preview['jours'][$jour]))); ?></small></th>
    <?php endforeach; ?>
    <th style="text-align:center">Total</th>
</tr>
</thead>
<tbody>
<?php foreach ($preview['classes'] as $code => $label): ?>
<tr data-testid="supplier-row-<?php echo esc_attr($code ?: 'none'); ?>">
    <td><strong><?php echo esc_html($label); ?></strong></td>
    <?php foreach (array_keys($preview['jours']) as $jour): ?>
    <td style="text-align:center" data-testid="supplier-cell-<?php echo esc_attr($code ?: 'none'); ?>-<?php echo esc_attr($jour); ?>">
        <?php $n = $preview['counts'][$code][$jour] ?? 0; echo $n > 0 ? (int) $n : '—'; ?>
    </td>
    <?php endforeach; ?>
    <td style="text-align:center" data-testid="supplier-total-classe-<?php echo esc_attr($code ?: 'none'); ?>">
        <strong><?php echo (int) $preview['totaux_classe'][$code]; ?></strong>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr>
    <th>TOTAL</th>
    <?php foreach (array_keys($preview['jours']) as $jour): ?>
    <th style="text-align:center" data-testid="supplier-total-jour-<?php echo esc_attr($jour); ?>"><?php echo (int) $preview['totaux_jour'][$jour]; ?></th>
    <?php endforeach; ?>
    <th style="text-align:center" data-testid="supplier-total-general"><?php echo (int) $preview['total']; ?></th>
</tr>
</tfoot>
</table>
<?php endif; ?>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px;">
    <?php wp_nonce_field('psc_send_supplier_order'); ?>
    <input type="hidden" name="action" value="psc_send_supplier_order">
    <input type="hidden" name="semaine_debut" value="<?php echo esc_attr($preview['semaine_debut']); ?>">
    <button type="submit" class="button button-primary" data-testid="supplier-send-button"
            onclick="return confirm('Envoyer la commande de <?php echo (int) $preview['total']; ?> repas au fournisseur ?');">
        &#9993; Envoyer au fournisseur (<?php echo (int) $preview['total']; ?> repas)
    </button>
</form>
<?php endif; ?>
</div>
<?php endif; ?>

<?php if ($pending_cantine): ?>
<div class="psc-box" style="border-left:4px solid #f5a623;" data-testid="cantine-pending-warning">
<h2>⚠ Confirmation nécessaire</h2>
<p>
    Annuler la cantine de la classe <strong><?php echo esc_html($pending_cantine['classe'] !== '' ? (Psc_School_Years::classe_options()[$pending_cantine['classe']] ?? $pending_cantine['classe']) : 'Non renseignée'); ?></strong>
    le <strong><?php echo esc_html(psc_day_label($pending_cantine['date']) . ' ' . date_i18n('d/m/Y', strtotime($pending_cantine['date']))); ?></strong>
    supprimera <strong><?php echo count($pending_cantine_affected); ?> inscription(s)</strong> déjà déclarée(s). Ces prestations ne seront
    pas facturées, et chaque famille concernée recevra un e-mail avec le motif indiqué : «&nbsp;<?php echo esc_html($pending_cantine['reason']); ?>&nbsp;».
</p>
<table class="widefat striped" style="margin-bottom:16px;">
<thead><tr><th>Famille</th><th>Enfant</th></tr></thead>
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
<button type="submit" class="button button-primary" data-testid="cantine-confirm-button">Confirmer l'annulation</button>
</form>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
<?php wp_nonce_field('psc_dismiss_cancel_class_meals'); ?>
<input type="hidden" name="action" value="psc_dismiss_cancel_class_meals">
<button type="submit" class="button" data-testid="cantine-dismiss-button">Annuler</button>
</form>
</div>
<?php endif; ?>

<div class="psc-box">
<h2>Annuler la cantine pour une classe</h2>
<p>Sortie scolaire ou fermeture ponctuelle touchant une classe entière — usage exceptionnel. Les familles concernées sont prévenues par e-mail avec le motif indiqué.</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_cancel_class_meals'); ?>
<input type="hidden" name="action" value="psc_cancel_class_meals">
<table class="form-table">
<tr>
<th><label for="psc-cantine-date">Jour</label></th>
<td><input id="psc-cantine-date" type="date" name="date" required data-testid="cantine-date-input"></td>
</tr>
<tr>
<th><label for="psc-cantine-classe">Classe</label></th>
<td>
<select id="psc-cantine-classe" name="classe" data-testid="cantine-classe-select">
<?php foreach (Psc_School_Years::classe_options() as $code => $label): ?>
<option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label ?: 'Non renseignée'); ?></option>
<?php endforeach; ?>
</select>
</td>
</tr>
<tr>
<th><label for="psc-cantine-reason">Motif</label></th>
<td><textarea id="psc-cantine-reason" name="reason" rows="2" class="large-text" maxlength="500" required placeholder="Ex : Sortie scolaire à la ferme pédagogique" data-testid="cantine-reason-input"></textarea></td>
</tr>
</table>
<?php submit_button('Annuler la cantine', 'secondary', 'submit', false, array('data-testid' => 'cantine-cancel-submit')); ?>
</form>
</div>

<div class="psc-box">
<h2>Historique des envois</h2>
<?php if (empty($recent)): ?>
<p data-testid="supplier-history-empty">Aucune commande envoyée pour le moment.</p>
<?php else: ?>
<table class="widefat striped" data-testid="supplier-history-table">
<thead>
<tr>
    <th>Semaine</th>
    <th>Total repas</th>
    <th>Destinataire</th>
    <th>Envoyée le</th>
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
            <summary>Voir le contenu envoyé</summary>
            <p><strong>Sujet :</strong> <span data-testid="supplier-history-subject-<?php echo esc_attr($h->id); ?>"><?php echo esc_html($h->email_subject); ?></span></p>
            <iframe title="Contenu de l'e-mail envoyé le <?php echo esc_attr(date_i18n('d/m/Y H:i', strtotime($h->sent_at))); ?>"
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
