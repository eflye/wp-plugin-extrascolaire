<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1><?php esc_html_e('État des comptes familles', 'periscolaire-registration'); ?></h1>
<p><?php echo esc_html(sprintf(__('Situation au %s — factures générées jusqu’au mois en cours, hors mois futurs.', 'periscolaire-registration'), date_i18n('d/m/Y', strtotime($as_of)))); ?></p>
<p class="description"><?php esc_html_e('Les prélèvements sont considérés comme payés par défaut selon le mode de paiement actuel de la famille. Les chèques et espèces sont payés lorsqu’ils sont marqués reçus dans Facturation.', 'periscolaire-registration'); ?></p>
<?php if (!$accounts): ?>
<p><?php esc_html_e('Aucune famille enregistrée.', 'periscolaire-registration'); ?></p>
<?php else: ?>
<p><strong><?php esc_html_e('Total dû à ce jour :', 'periscolaire-registration'); ?> <?php echo esc_html(number_format(array_sum(array_column($accounts, 'due')) / 100, 2, ',', ' ')); ?> €</strong></p>
<table class="widefat striped">
<thead><tr>
<th><?php esc_html_e('Famille', 'periscolaire-registration'); ?></th>
<th><?php esc_html_e('Factures payées et non payées', 'periscolaire-registration'); ?></th>
<th style="text-align:right"><?php esc_html_e('Total payé', 'periscolaire-registration'); ?></th>
<th style="text-align:right"><?php esc_html_e('Somme due à ce jour', 'periscolaire-registration'); ?></th>
</tr></thead>
<tbody>
<?php foreach ($accounts as $account): ?>
<tr>
<td><strong><?php echo esc_html($account['name'] ?: '—'); ?></strong><br><?php echo esc_html($account['email']); ?></td>
<td>
<?php if (!$account['invoices']): ?>
<?php esc_html_e('Aucune facture à ce jour.', 'periscolaire-registration'); ?>
<?php else: ?>
<details><summary><?php echo esc_html(sprintf(__('%1$d payée(s), %2$d non payée(s) — voir les factures', 'periscolaire-registration'), count(array_filter($account['invoices'], static function ($invoice) { return $invoice['paid']; })), count(array_filter($account['invoices'], static function ($invoice) { return !$invoice['paid']; })))); ?></summary>
<ul>
<?php foreach ($account['invoices'] as $invoice): ?>
<li>
<a href="<?php echo esc_url(add_query_arg(array('page' => 'psc_factures', 'mois' => $invoice['month']), admin_url('admin.php'))); ?>"><?php echo esc_html(Psc_Invoices::month_label($invoice['month'])); ?></a>
 — <?php echo esc_html(number_format($invoice['cents'] / 100, 2, ',', ' ')); ?> € —
<strong><?php echo $invoice['paid'] ? esc_html__('Payée', 'periscolaire-registration') : esc_html__('Non payée', 'periscolaire-registration'); ?></strong>
<?php if ($invoice['direct_debit']): ?>
(<?php esc_html_e('prélèvement, payé par défaut', 'periscolaire-registration'); ?>)
<?php elseif ($invoice['received_at']): ?>
(<?php echo esc_html(sprintf(__('reçu le %s', 'periscolaire-registration'), date_i18n('d/m/Y', strtotime($invoice['received_at'])))); ?>)
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
</details>
<?php endif; ?>
</td>
<td style="text-align:right"><?php echo esc_html(number_format($account['paid'] / 100, 2, ',', ' ')); ?> €</td>
<td style="text-align:right"><strong><?php echo esc_html(number_format($account['due'] / 100, 2, ',', ' ')); ?> €</strong></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
