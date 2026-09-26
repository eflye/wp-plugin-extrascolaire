<?php if (!defined('ABSPATH')) exit;
/** @var object $version  @var int $psc_acceptations */
$psc_types = psc_reglement_types();
?>
<div class="wrap psc-admin">
<h1><?php echo esc_html(sprintf(__('%1$s — version n° %2$d', 'periscolaire-registration'), $psc_types[$version->type]['label'] ?? $version->type, (int) $version->id)); ?></h1>

<div class="psc-box">
<table class="widefat striped" data-testid="reglement-version-meta">
<caption class="screen-reader-text"><?php esc_html_e('Caractéristiques de la version', 'periscolaire-registration'); ?></caption>
<tbody>
<tr><th scope="row"><?php esc_html_e('En vigueur à partir du', 'periscolaire-registration'); ?></th><td><?php echo esc_html(date_i18n('d/m/Y à H:i', strtotime($version->cree_le))); ?></td></tr>
<tr><th scope="row"><?php esc_html_e('Empreinte (SHA-256)', 'periscolaire-registration'); ?></th><td><code><?php echo esc_html($version->empreinte); ?></code></td></tr>
<tr><th scope="row"><?php esc_html_e('PDF complémentaire', 'periscolaire-registration'); ?></th><td>
<?php if ($version->pdf_fichier): ?>
  <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'psc_family_reglement_pdf', 'id' => (int) $version->id), admin_url('admin-post.php')), 'psc_family_reglement_pdf')); ?>" data-testid="reglement-version-pdf"><?php echo esc_html($version->pdf_nom ?: __('Télécharger le PDF', 'periscolaire-registration')); ?></a>
  <br><small><?php echo esc_html(sprintf(__('Empreinte du PDF : %s', 'periscolaire-registration'), $version->pdf_sha256)); ?></small>
<?php elseif ($version->pdf_sha256): ?>
  <?php esc_html_e('Un PDF était en ligne, mais sa copie n’a pas pu être conservée.', 'periscolaire-registration'); ?>
<?php else: ?>
  <?php esc_html_e('Aucun : seul le texte ci-dessous était affiché.', 'periscolaire-registration'); ?>
<?php endif; ?>
</td></tr>
<tr><th scope="row"><?php esc_html_e('Acceptations qui citent cette version', 'periscolaire-registration'); ?></th><td data-testid="reglement-version-count"><?php echo (int) $psc_acceptations; ?></td></tr>
</tbody>
</table>
</div>

<div class="psc-box">
<h2><?php esc_html_e('Texte affiché à la famille', 'periscolaire-registration'); ?></h2>
<div data-testid="reglement-version-texte" style="max-width:760px;">
<?php echo wp_kses_post($version->texte); ?>
</div>
</div>
</div>
