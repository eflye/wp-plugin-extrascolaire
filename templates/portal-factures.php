<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow"><?php esc_html_e('Comptabilité', 'periscolaire-registration'); ?></div>
<h1 class="psc-portal-h1" data-testid="factures-title"><?php esc_html_e('Mes factures', 'periscolaire-registration'); ?></h1>

<?php if (empty($invoices)): ?>
<p class="psc-portal-dash-menu-empty" data-testid="portal-invoices-empty"><?php esc_html_e("Aucune facture n'a encore été émise pour votre famille.", 'periscolaire-registration'); ?></p>
<?php else: ?>
<div class="psc-portal-table-scroll">
<table class="psc-portal-table" data-testid="portal-invoices-table">
  <thead>
    <tr><th><?php esc_html_e('Mois', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Montant', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Statut', 'periscolaire-registration'); ?></th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($invoices as $inv): ?>
    <tr data-testid="portal-invoice-row-<?php echo esc_attr($inv->id); ?>">
      <td style="font-family:'Fraunces',serif;font-weight:600;"><?php echo esc_html(Psc_Invoices::month_label($inv->mois)); ?></td>
      <td style="font-family:'Fraunces',serif;font-weight:700;"><?php echo esc_html(number_format_i18n((float) $inv->total, 2)); ?> €</td>
      <td>
        <?php if ($inv->sent_at): ?>
          <span class="psc-portal-pill"><?php esc_html_e('Envoyée le', 'periscolaire-registration'); ?> <?php echo esc_html(date_i18n('d/m/Y', strtotime($inv->sent_at))); ?></span>
        <?php else: ?>
          <span class="psc-portal-pill psc-portal-pill--pending"><?php esc_html_e('En attente', 'periscolaire-registration'); ?></span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($inv->pdf_path): ?>
        <a class="psc-portal-btn-outline-forest"
           href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'psc_parent_download_invoice', 'invoice_id' => $inv->id), admin_url('admin-post.php')), 'psc_parent_download_invoice_' . $inv->id)); ?>">
          <?php esc_html_e('Télécharger', 'periscolaire-registration'); ?>
        </a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
