<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow"><?php esc_html_e('Famille', 'periscolaire-registration'); ?></div>
<h1 class="psc-portal-h1" data-testid="documents-title"><?php esc_html_e('Documents', 'periscolaire-registration'); ?></h1>
<p class="psc-portal-intro"><?php esc_html_e("Retrouvez ici les documents de référence de l'accueil périscolaire, au format PDF.", 'periscolaire-registration'); ?></p>

<?php
$psc_doc_ri_id = (int) get_option('psc_doc_reglement_interieur_id', 0);
$psc_doc_ri_url = $psc_doc_ri_id ? wp_get_attachment_url($psc_doc_ri_id) : '';
$psc_doc_rp_id = (int) get_option('psc_doc_reglement_prelevement_id', 0);
$psc_doc_rp_url = $psc_doc_rp_id ? wp_get_attachment_url($psc_doc_rp_id) : '';
?>

<div class="psc-portal-panel">
  <div class="psc-portal-panel-title"><?php esc_html_e('Règlement intérieur', 'periscolaire-registration'); ?></div>
  <?php if ($psc_doc_ri_url): ?>
  <a href="<?php echo esc_url($psc_doc_ri_url); ?>" class="psc-portal-btn-outline-ink" target="_blank" rel="noopener" data-testid="doc-reglement-interieur-link"><?php esc_html_e('Télécharger le PDF', 'periscolaire-registration'); ?></a>
  <?php else: ?>
  <p class="psc-portal-intro" data-testid="doc-reglement-interieur-empty"><?php esc_html_e("Ce document n'a pas encore été mis en ligne par la mairie.", 'periscolaire-registration'); ?></p>
  <?php endif; ?>
</div>

<div class="psc-portal-panel">
  <div class="psc-portal-panel-title"><?php esc_html_e('Règlement concernant le prélèvement automatique', 'periscolaire-registration'); ?></div>
  <?php if ($psc_doc_rp_url): ?>
  <a href="<?php echo esc_url($psc_doc_rp_url); ?>" class="psc-portal-btn-outline-ink" target="_blank" rel="noopener" data-testid="doc-reglement-prelevement-link"><?php esc_html_e('Télécharger le PDF', 'periscolaire-registration'); ?></a>
  <?php else: ?>
  <p class="psc-portal-intro" data-testid="doc-reglement-prelevement-empty"><?php esc_html_e("Ce document n'a pas encore été mis en ligne par la mairie.", 'periscolaire-registration'); ?></p>
  <?php endif; ?>
</div>
