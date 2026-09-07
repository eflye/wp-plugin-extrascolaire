<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1><?php esc_html_e('Facturation', 'periscolaire-registration'); ?></h1>

<?php
$psc_notices = array(
    'payment_saved' => array('updated', __('Statut du paiement enregistré.', 'periscolaire-registration')),
    'generated'   => array('updated', __('Factures générées avec succès.', 'periscolaire-registration')),
    'gen_zero'    => array('warning', __('Aucune inscription trouvée pour ce mois.', 'periscolaire-registration')),
    'gen_error'   => array('error', __('Erreur lors de la génération.', 'periscolaire-registration')),
    'deleted'     => array('updated', __('Factures du mois supprimées (fichiers inclus).', 'periscolaire-registration')),
    'sepa_need_generate' => array('warning', __('Générez d\'abord les factures du mois : l\'export des prélèvements reprend leur montant.', 'periscolaire-registration')),
    'sepa_none'   => array('warning', __('Aucune famille en prélèvement (avec facture) pour ce mois.', 'periscolaire-registration')),
    'sepa_failed' => array('error', __('Création du fichier d\'export impossible.', 'periscolaire-registration')),
    'sent'        => array('updated', __('Facture envoyée par e-mail.', 'periscolaire-registration')),
    'sent_all'    => array('updated', __('Toutes les factures ont été envoyées.', 'periscolaire-registration')),
    'mail_failed' => array('error', __("L'envoi du mail a échoué. Vérifiez la configuration e-mail.", 'periscolaire-registration')),
    'no_file'     => array('error', __('Fichier PDF introuvable. Regénérez la facture.', 'periscolaire-registration')),
    'invalid'     => array('error', __('Paramètre invalide.', 'periscolaire-registration')),
);
psc_admin_notice_map($psc_notices, $psc_msg);
?>

<?php if (empty($all_months)): ?>
<p><?php esc_html_e('Aucune inscription enregistrée. Les factures seront disponibles dès que des familles auront des inscriptions.', 'periscolaire-registration'); ?></p>
<?php else: ?>

<!-- Sélection du mois + génération -->
<div class="psc-factures-toolbar">
    <form method="get" style="display:inline-flex;align-items:center;gap:10px;">
        <input type="hidden" name="page" value="psc_factures">
        <label><strong><?php esc_html_e('Mois :', 'periscolaire-registration'); ?></strong>
            <select name="mois" onchange="this.form.submit()">
            <?php foreach ($all_months as $m): ?>
                <option value="<?php echo esc_attr($m); ?>" <?php selected($selected_mois, $m); ?>>
                    <?php echo esc_html(Psc_Invoices::month_label($m)); ?>
                </option>
            <?php endforeach; ?>
            </select>
        </label>
    </form>

    <?php if ($selected_mois): ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
        <input type="hidden" name="action" value="psc_generate_invoices">
        <input type="hidden" name="mois" value="<?php echo esc_attr($selected_mois); ?>">
        <?php wp_nonce_field('psc_generate_invoices'); ?>
        <button type="submit" class="button button-primary"
                onclick="return confirm('<?php echo esc_js(__('Générer ou régénérer les factures de', 'periscolaire-registration')); ?> <?php echo esc_js(Psc_Invoices::month_label($selected_mois)); ?> <?php echo esc_js(__('? Les PDF existants seront remplacés ; le statut d\'envoi est conservé.', 'periscolaire-registration')); ?>');">
            &#8635; <?php esc_html_e('Générer / Regénérer les factures de', 'periscolaire-registration'); ?> <?php echo esc_html(Psc_Invoices::month_label($selected_mois)); ?>
        </button>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
        <input type="hidden" name="action" value="psc_delete_invoices">
        <input type="hidden" name="mois" value="<?php echo esc_attr($selected_mois); ?>">
        <?php wp_nonce_field('psc_delete_invoices'); ?>
        <button type="submit" class="button"
                onclick="return confirm('<?php echo esc_js(__('Supprimer TOUTES les factures de', 'periscolaire-registration')); ?> <?php echo esc_js(Psc_Invoices::month_label($selected_mois)); ?> <?php echo esc_js(__('? Les fichiers PDF sont effacés, y compris celles déjà envoyées. Action irréversible.', 'periscolaire-registration')); ?>');">
            &#10005; <?php esc_html_e('Supprimer les factures du mois', 'periscolaire-registration'); ?>
        </button>
    </form>
    <?php endif; ?>
</div>

<section class="postbox" style="padding:16px;margin-top:20px;" aria-labelledby="psc-exports-title">
<h2 id="psc-exports-title" style="margin-top:0;"><?php esc_html_e('Exports par mois', 'periscolaire-registration'); ?></h2>
<form method="get" style="margin-bottom:16px;">
    <input type="hidden" name="page" value="psc_factures">
    <label for="psc-export-month"><strong><?php esc_html_e('Mois à exporter', 'periscolaire-registration'); ?></strong></label>
    <select id="psc-export-month" name="mois" onchange="this.form.submit()">
        <?php foreach ($all_months as $m): ?>
        <option value="<?php echo esc_attr($m); ?>" <?php selected($selected_mois, $m); ?>><?php echo esc_html(Psc_Invoices::month_label($m)); ?></option>
        <?php endforeach; ?>
    </select>
    <button class="button"><?php esc_html_e('Choisir', 'periscolaire-registration'); ?></button>
</form>
<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
<?php foreach (array('csv', 'ods', 'general') as $format): $action = $format === 'general' ? 'psc_download_general' : 'psc_download_sepa'; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
    <input type="hidden" name="format" value="<?php echo esc_attr($format); ?>">
    <input type="hidden" name="mois" value="<?php echo esc_attr($selected_mois); ?>">
    <?php wp_nonce_field($action); ?>
    <button class="button button-secondary"><?php echo esc_html($format === 'general' ? __('Export général (.csv)', 'periscolaire-registration') : sprintf(__('Export prélèvements (SEPA, .%s)', 'periscolaire-registration'), $format)); ?></button>
</form>
<?php endforeach; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="action" value="psc_download_pain008">
    <input type="hidden" name="mois" value="<?php echo esc_attr($selected_mois); ?>">
    <?php wp_nonce_field('psc_download_pain008'); ?>
    <label for="psc-collection-date"><?php esc_html_e('Date de prélèvement :', 'periscolaire-registration'); ?></label>
    <input id="psc-collection-date" type="date" name="collection_date" required min="<?php echo esc_attr((new DateTimeImmutable('tomorrow', wp_timezone()))->format('Y-m-d')); ?>">
    <button class="button button-secondary"><?php esc_html_e('Export fichier pain.008', 'periscolaire-registration'); ?></button>
</form>
</div>
<p class="description"><?php esc_html_e('Pour le fichier pain.008, choisissez la date convenue avec votre banque. Le téléchargement ne transmet aucun ordre à la banque.', 'periscolaire-registration'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=psc_settings#psc-org-ics')); ?>"><?php esc_html_e('Configurer le compte créancier', 'periscolaire-registration'); ?></a></p>
</section>

<?php if ($selected_mois && !empty($invoices)): ?>

<p style="margin-top:16px;">
    <?php echo count($invoices); ?> <?php esc_html_e('facture(s) — mois de', 'periscolaire-registration'); ?> <?php echo esc_html(Psc_Invoices::month_label($selected_mois)); ?>
    &nbsp;|&nbsp;
    <?php $unsent = array_filter($invoices, fn($i) => !$i->sent_at); ?>
    <?php if (!empty($unsent)): ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
        <input type="hidden" name="action" value="psc_send_all_invoices">
        <input type="hidden" name="mois" value="<?php echo esc_attr($selected_mois); ?>">
        <?php wp_nonce_field('psc_send_all_invoices'); ?>
        <button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js(__('Envoyer les', 'periscolaire-registration')); ?> <?php echo count($unsent); ?> <?php echo esc_js(__('facture(s) non encore envoyées ?', 'periscolaire-registration')); ?>');">
            &#9993; <?php esc_html_e('Envoyer toutes les factures non envoyées (', 'periscolaire-registration'); ?><?php echo count($unsent); ?>)
        </button>
    </form>
    <?php else: ?>
    <em><?php esc_html_e('Toutes les factures de ce mois ont été envoyées.', 'periscolaire-registration'); ?></em>
    <?php endif; ?>
</p>

<table class="widefat striped psc-recap">
<thead>
<tr>
    <th><?php esc_html_e('Famille', 'periscolaire-registration'); ?></th>
    <th><?php esc_html_e('Email', 'periscolaire-registration'); ?></th>
    <th style="text-align:right"><?php esc_html_e('Total', 'periscolaire-registration'); ?></th>
    <th><?php esc_html_e('Générée le', 'periscolaire-registration'); ?></th>
    <th><?php esc_html_e('Envoyée le', 'periscolaire-registration'); ?></th>
    <th><?php esc_html_e('Paiement', 'periscolaire-registration'); ?></th>
    <th><?php esc_html_e('Actions', 'periscolaire-registration'); ?></th>
</tr>
</thead>
<tbody>
<?php foreach ($invoices as $inv): ?>
<tr>
    <td><?php echo esc_html($inv->parent_nom ?: '—'); ?></td>
    <td><?php echo esc_html($inv->parent_email); ?></td>
    <td style="text-align:right"><strong><?php echo esc_html(number_format((float) $inv->total, 2, ',', ' ')); ?> €</strong></td>
    <td><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($inv->created_at))); ?></td>
    <td>
        <?php if ($inv->sent_at): ?>
            <span style="color:#46b450">✔ <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($inv->sent_at))); ?></span>
        <?php else: ?>
            <span style="color:#999"><?php esc_html_e('Non envoyée', 'periscolaire-registration'); ?></span>
        <?php endif; ?>
    </td>
    <td>
        <?php if ($inv->payment_mode === 'autre'): ?>
        <strong><?php echo $inv->payment_received_at ? esc_html__('Reçu', 'periscolaire-registration') : esc_html__('Non reçu', 'periscolaire-registration'); ?></strong><br>
        <small><?php esc_html_e('Chèque ou espèces', 'periscolaire-registration'); ?><?php if ($inv->payment_received_at) echo ' — ' . esc_html(date_i18n('d/m/Y', strtotime($inv->payment_received_at))); ?></small>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="psc_payment_received">
            <input type="hidden" name="invoice_id" value="<?php echo esc_attr($inv->id); ?>">
            <input type="hidden" name="received" value="<?php echo $inv->payment_received_at ? '0' : '1'; ?>">
            <?php wp_nonce_field('psc_payment_received'); ?>
            <button class="button button-small"><?php echo $inv->payment_received_at ? esc_html__('Marquer non reçu', 'periscolaire-registration') : esc_html__('Marquer reçu', 'periscolaire-registration'); ?></button>
        </form>
        <?php else: ?>
        <?php esc_html_e('Prélèvement', 'periscolaire-registration'); ?>
        <?php endif; ?>
    </td>
    <td style="white-space:nowrap">
        <!-- Télécharger -->
        <a class="button button-small"
           href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=psc_download_invoice&invoice_id=' . $inv->id), 'psc_download_invoice_' . $inv->id)); ?>">
            &#8659; <?php esc_html_e('Télécharger', 'periscolaire-registration'); ?>
        </a>
        &nbsp;
        <!-- Envoyer -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <input type="hidden" name="action" value="psc_send_invoice">
            <input type="hidden" name="invoice_id" value="<?php echo esc_attr($inv->id); ?>">
            <input type="hidden" name="mois" value="<?php echo esc_attr($selected_mois); ?>">
            <?php wp_nonce_field('psc_send_invoice'); ?>
            <button type="submit" class="button button-small <?php echo $inv->sent_at ? '' : 'button-primary'; ?>"
                    onclick="return confirm('<?php echo esc_js(__('Envoyer la facture à', 'periscolaire-registration')); ?> <?php echo esc_js($inv->parent_email); ?> ?');">
                &#9993; <?php echo $inv->sent_at ? esc_html__('Renvoyer', 'periscolaire-registration') : esc_html__('Envoyer', 'periscolaire-registration'); ?>
            </button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr>
    <th colspan="2"><?php esc_html_e('Total du mois', 'periscolaire-registration'); ?></th>
    <th style="text-align:right">
        <?php echo esc_html(number_format(array_sum(array_column($invoices, 'total')), 2, ',', ' ')); ?> €
    </th>
    <th colspan="4"></th>
</tr>
</tfoot>
</table>

<?php elseif ($selected_mois): ?>
<p><?php esc_html_e('Aucune facture générée pour', 'periscolaire-registration'); ?> <?php echo esc_html(Psc_Invoices::month_label($selected_mois)); ?>.
<?php esc_html_e('Cliquez sur « Générer » pour créer les factures à partir des inscriptions du mois (déclarations réelles, mêmes futures).', 'periscolaire-registration'); ?></p>
<?php endif; ?>
<?php endif; ?>
</div>
