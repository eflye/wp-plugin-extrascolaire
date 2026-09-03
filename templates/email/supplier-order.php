<?php if (!defined('ABSPATH')) exit; ?>
<?php
/**
 * E-mail « Commande fournisseur » — rendu autonome (maquette Email
 * Commande Fournisseur.dc.html) : carte blanche 600px sur fond sable, un
 * seul tableau une ligne par jour de service, ventilation par régime sous
 * un chapeau « Repas de midi » (colspan), Total midi accentué, goûters
 * détachés, total semaine, pied de mail configurable.
 *
 * CSS inline uniquement, tableaux imbriqués, attributs align en complément
 * de text-align, role="presentation" sur les tableaux de mise en page —
 * compatibilité Outlook Windows, Gmail web, Apple Mail.
 *
 * Variables attendues :
 *   $site_name   (string)  nom du site
 *   $subject     (string)  objet du mail (non affiché dans le corps)
 *   $semaine_label (string) libellé de semaine
 *   $intro       (string)  corps configurable, déjà échappé + nl2br
 *   $rows        (array)   [jour => ['standard','sans_porc','vegetarien','midi','gouter']]
 *   $jours       (array)   [jour => date Y-m-d] (jours d'école ouverts)
 *   $totaux      (array)   sommes par colonne
 *   $footer      (string)  pied de mail échappé + nl2br, '' → non rendu
 */
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#EDEAE4;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;background:#EDEAE4;">
<tr>
<td align="center" style="padding:28px 16px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:600px;max-width:100%;margin:0 auto;background:#FFFFFF;border-collapse:collapse;">
<tr>
<td style="padding:26px 30px 0;">
<div style="font-size:19px;font-weight:700;color:#1A1A1A;line-height:1.35;">Semaine du <?php echo esc_html($semaine_label); ?></div>
</td>
</tr>
<?php if ($intro !== ''): ?>
<tr>
<td style="padding:18px 30px 0;">
<p style="margin:0;font-size:13.5px;color:#1A1A1A;line-height:1.6;"><?php echo $intro; // déjà échappé + nl2br (body_html) ?></p>
</td>
</tr>
<?php endif; ?>
<tr>
<td style="padding:18px 30px 0;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;">
<tr>
<td style="padding:0 8px 5px 0;"></td>
<td colspan="4" align="center" style="padding:0 0 5px 8px;font-size:10px;letter-spacing:0.14em;text-transform:uppercase;color:#E08A5F;font-weight:700;border-bottom:1px solid #EDEAE4;"><?php esc_html_e('Repas de midi', 'periscolaire-registration'); ?></td>
<td style="padding:0 0 5px 14px;"></td>
</tr>
<tr>
<th align="left" style="padding:7px 8px 7px 0;font-size:10px;letter-spacing:0.1em;text-transform:uppercase;color:#8B8279;font-weight:600;border-bottom:1px solid #24405C;"><?php esc_html_e('Jour', 'periscolaire-registration'); ?></th>
<th align="right" style="padding:7px 0 7px 8px;font-size:10px;letter-spacing:0.08em;text-transform:uppercase;color:#8B8279;font-weight:600;border-bottom:1px solid #24405C;"><?php esc_html_e('Standard', 'periscolaire-registration'); ?></th>
<th align="right" style="padding:7px 0 7px 8px;font-size:10px;letter-spacing:0.08em;text-transform:uppercase;color:#8B8279;font-weight:600;border-bottom:1px solid #24405C;"><?php esc_html_e('Sans porc', 'periscolaire-registration'); ?></th>
<th align="right" style="padding:7px 0 7px 8px;font-size:10px;letter-spacing:0.08em;text-transform:uppercase;color:#8B8279;font-weight:600;border-bottom:1px solid #24405C;"><?php esc_html_e('Végétarien', 'periscolaire-registration'); ?></th>
<th align="right" style="padding:7px 0 7px 8px;font-size:10px;letter-spacing:0.08em;text-transform:uppercase;color:#24405C;font-weight:700;border-bottom:1px solid #24405C;"><?php esc_html_e('Total midi', 'periscolaire-registration'); ?></th>
<th align="right" style="padding:7px 0 7px 14px;font-size:10px;letter-spacing:0.08em;text-transform:uppercase;color:#8B8279;font-weight:600;border-bottom:1px solid #24405C;"><?php esc_html_e('Goûters', 'periscolaire-registration'); ?></th>
</tr>
<?php foreach ($rows as $jour => $row): ?>
<tr>
<td align="left" style="padding:9px 8px 9px 0;font-size:13.5px;color:#1A1A1A;font-weight:600;border-bottom:1px solid #EDEAE4;white-space:nowrap;"><?php echo esc_html(ucfirst($jour)); ?> <span style="font-weight:400;color:#8B8279;"><?php echo esc_html(date_i18n('d/m', strtotime($jours[$jour]))); ?></span></td>
<td align="right" style="padding:9px 0 9px 8px;font-size:14px;color:#1A1A1A;border-bottom:1px solid #EDEAE4;"><?php echo (int) $row['standard']; ?></td>
<td align="right" style="padding:9px 0 9px 8px;font-size:14px;color:#1A1A1A;border-bottom:1px solid #EDEAE4;"><?php echo (int) $row['sans_porc']; ?></td>
<td align="right" style="padding:9px 0 9px 8px;font-size:14px;color:#1A1A1A;border-bottom:1px solid #EDEAE4;"><?php echo (int) $row['vegetarien']; ?></td>
<td align="right" style="padding:9px 0 9px 8px;font-size:14px;color:#24405C;font-weight:700;border-bottom:1px solid #EDEAE4;background:#FAF6F1;"><?php echo (int) $row['midi']; ?></td>
<td align="right" style="padding:9px 0 9px 14px;font-size:14px;color:#1A1A1A;font-weight:600;border-bottom:1px solid #EDEAE4;"><?php echo (int) $row['gouter']; ?></td>
</tr>
<?php endforeach; ?>
<tr>
<td align="left" style="padding:10px 8px 0 0;font-size:11px;letter-spacing:0.1em;text-transform:uppercase;color:#24405C;font-weight:700;"><?php esc_html_e('Total semaine', 'periscolaire-registration'); ?></td>
<td align="right" style="padding:10px 0 0 8px;font-size:14px;color:#1A1A1A;font-weight:600;"><?php echo (int) $totaux['standard']; ?></td>
<td align="right" style="padding:10px 0 0 8px;font-size:14px;color:#1A1A1A;font-weight:600;"><?php echo (int) $totaux['sans_porc']; ?></td>
<td align="right" style="padding:10px 0 0 8px;font-size:14px;color:#1A1A1A;font-weight:600;"><?php echo (int) $totaux['vegetarien']; ?></td>
<td align="right" style="padding:10px 0 0 8px;font-size:15px;color:#24405C;font-weight:700;background:#FAF6F1;"><?php echo (int) $totaux['midi']; ?></td>
<td align="right" style="padding:10px 0 0 14px;font-size:15px;color:#1A1A1A;font-weight:700;"><?php echo (int) $totaux['gouter']; ?></td>
</tr>
</table>
</td>
</tr>
<?php if ($footer !== ''): ?>
<tr>
<td style="padding:22px 30px 26px;">
<div style="border-top:1px solid #EDEAE4;padding-top:14px;font-size:11.5px;color:#8B8279;line-height:1.65;"><?php echo $footer; // déjà échappé + nl2br (footer_html) ?></div>
</td>
</tr>
<?php endif; ?>
</table>
</td>
</tr>
</table>
</body>
</html>
