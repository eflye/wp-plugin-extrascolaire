<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Factures</h1>

<?php
$msgs = array(
    'generated'   => array('updated', 'Factures générées avec succès.'),
    'gen_zero'    => array('notice-warning', 'Aucune inscription trouvée pour ce mois.'),
    'gen_error'   => array('error', 'Erreur lors de la génération.'),
    'sent'        => array('updated', 'Facture envoyée par e-mail.'),
    'sent_all'    => array('updated', 'Toutes les factures ont été envoyées.'),
    'mail_failed' => array('error', 'L\'envoi du mail a échoué. Vérifiez la configuration e-mail.'),
    'no_file'     => array('error', 'Fichier PDF introuvable. Regénérez la facture.'),
    'invalid'     => array('error', 'Paramètre invalide.'),
);
if ($psc_msg && isset($msgs[$psc_msg])):
    list($cls, $txt) = $msgs[$psc_msg];
?>
<div class="notice notice-<?php echo esc_attr($cls); ?> is-dismissible"><p><?php echo esc_html($txt); ?></p></div>
<?php endif; ?>

<?php if (empty($all_months)): ?>
<p>Aucune inscription enregistrée. Les factures seront disponibles dès que des familles auront des inscriptions.</p>
<?php else: ?>

<!-- Sélection du mois + génération -->
<div class="psc-factures-toolbar">
    <form method="get" style="display:inline-flex;align-items:center;gap:10px;">
        <input type="hidden" name="page" value="psc_factures">
        <label><strong>Mois :</strong>
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
        <button type="submit" class="button button-primary">
            &#8635; Générer / Regénérer les factures de <?php echo esc_html(Psc_Invoices::month_label($selected_mois)); ?>
        </button>
    </form>
    <?php endif; ?>
</div>

<?php if ($selected_mois && !empty($invoices)): ?>

<p style="margin-top:16px;">
    <?php echo count($invoices); ?> facture(s) — mois de <?php echo esc_html(Psc_Invoices::month_label($selected_mois)); ?>
    &nbsp;|&nbsp;
    <?php $unsent = array_filter($invoices, fn($i) => !$i->sent_at); ?>
    <?php if (!empty($unsent)): ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
        <input type="hidden" name="action" value="psc_send_all_invoices">
        <input type="hidden" name="mois" value="<?php echo esc_attr($selected_mois); ?>">
        <?php wp_nonce_field('psc_send_all_invoices'); ?>
        <button type="submit" class="button button-secondary" onclick="return confirm('Envoyer les <?php echo count($unsent); ?> facture(s) non encore envoyées ?');">
            &#9993; Envoyer toutes les factures non envoyées (<?php echo count($unsent); ?>)
        </button>
    </form>
    <?php else: ?>
    <em>Toutes les factures de ce mois ont été envoyées.</em>
    <?php endif; ?>
</p>

<table class="widefat striped psc-recap">
<thead>
<tr>
    <th>Famille</th>
    <th>Email</th>
    <th style="text-align:right">Total</th>
    <th>Générée le</th>
    <th>Envoyée le</th>
    <th>Actions</th>
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
            <span style="color:#999">Non envoyée</span>
        <?php endif; ?>
    </td>
    <td style="white-space:nowrap">
        <!-- Télécharger -->
        <a class="button button-small"
           href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=psc_download_invoice&invoice_id=' . $inv->id), 'psc_download_invoice_' . $inv->id)); ?>">
            &#8659; Télécharger
        </a>
        &nbsp;
        <!-- Envoyer -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <input type="hidden" name="action" value="psc_send_invoice">
            <input type="hidden" name="invoice_id" value="<?php echo esc_attr($inv->id); ?>">
            <input type="hidden" name="mois" value="<?php echo esc_attr($selected_mois); ?>">
            <?php wp_nonce_field('psc_send_invoice'); ?>
            <button type="submit" class="button button-small <?php echo $inv->sent_at ? '' : 'button-primary'; ?>"
                    onclick="return confirm('Envoyer la facture à <?php echo esc_js($inv->parent_email); ?> ?');">
                &#9993; <?php echo $inv->sent_at ? 'Renvoyer' : 'Envoyer'; ?>
            </button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr>
    <th colspan="2">Total du mois</th>
    <th style="text-align:right">
        <?php echo esc_html(number_format(array_sum(array_column($invoices, 'total')), 2, ',', ' ')); ?> €
    </th>
    <th colspan="3"></th>
</tr>
</tfoot>
</table>

<?php elseif ($selected_mois): ?>
<p>Aucune facture générée pour <?php echo esc_html(Psc_Invoices::month_label($selected_mois)); ?>.
Cliquez sur « Générer » pour créer les factures à partir des inscriptions.</p>
<?php endif; ?>
<?php endif; ?>
</div>
