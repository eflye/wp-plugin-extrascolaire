<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow">Comptabilité</div>
<h1 class="psc-portal-h1" data-testid="factures-title">Mes factures</h1>

<?php if (empty($invoices)): ?>
<p class="psc-portal-dash-menu-empty" data-testid="portal-invoices-empty">Aucune facture n'a encore été émise pour votre famille.</p>
<?php else: ?>
<div class="psc-portal-table-scroll">
<table class="psc-portal-table" data-testid="portal-invoices-table">
  <thead>
    <tr><th>Mois</th><th>Montant</th><th>Statut</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($invoices as $inv): ?>
    <tr data-testid="portal-invoice-row-<?php echo esc_attr($inv->id); ?>">
      <td style="font-family:'Fraunces',serif;font-weight:600;"><?php echo esc_html(Psc_Invoices::month_label($inv->mois)); ?></td>
      <td style="font-family:'Fraunces',serif;font-weight:700;"><?php echo esc_html(number_format_i18n((float) $inv->total, 2)); ?> €</td>
      <td>
        <?php if ($inv->sent_at): ?>
          <span class="psc-portal-pill">Envoyée le <?php echo esc_html(date_i18n('d/m/Y', strtotime($inv->sent_at))); ?></span>
        <?php else: ?>
          <span class="psc-portal-pill psc-portal-pill--pending">En attente</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($inv->pdf_path): ?>
        <a class="psc-portal-btn-outline-forest"
           href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'psc_parent_download_invoice', 'invoice_id' => $inv->id), admin_url('admin-post.php')), 'psc_parent_download_invoice_' . $inv->id)); ?>">
          Télécharger
        </a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
