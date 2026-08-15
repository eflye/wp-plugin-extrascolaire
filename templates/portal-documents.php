<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow">Famille</div>
<h1 class="psc-portal-h1" data-testid="documents-title">Documents</h1>
<p class="psc-portal-intro">Retrouvez ici les documents de référence de l'accueil périscolaire, au format PDF.</p>

<?php
$psc_doc_ri_id = (int) get_option('psc_doc_reglement_interieur_id', 0);
$psc_doc_ri_url = $psc_doc_ri_id ? wp_get_attachment_url($psc_doc_ri_id) : '';
$psc_doc_rp_id = (int) get_option('psc_doc_reglement_prelevement_id', 0);
$psc_doc_rp_url = $psc_doc_rp_id ? wp_get_attachment_url($psc_doc_rp_id) : '';
?>

<div class="psc-portal-panel">
  <div class="psc-portal-panel-title">Règlement intérieur</div>
  <?php if ($psc_doc_ri_url): ?>
  <a href="<?php echo esc_url($psc_doc_ri_url); ?>" class="psc-portal-btn-outline-ink" target="_blank" rel="noopener" data-testid="doc-reglement-interieur-link">Télécharger le PDF</a>
  <?php else: ?>
  <p class="psc-portal-intro" data-testid="doc-reglement-interieur-empty">Ce document n'a pas encore été mis en ligne par la mairie.</p>
  <?php endif; ?>
</div>

<div class="psc-portal-panel">
  <div class="psc-portal-panel-title">Règlement concernant le prélèvement automatique</div>
  <?php if ($psc_doc_rp_url): ?>
  <a href="<?php echo esc_url($psc_doc_rp_url); ?>" class="psc-portal-btn-outline-ink" target="_blank" rel="noopener" data-testid="doc-reglement-prelevement-link">Télécharger le PDF</a>
  <?php else: ?>
  <p class="psc-portal-intro" data-testid="doc-reglement-prelevement-empty">Ce document n'a pas encore été mis en ligne par la mairie.</p>
  <?php endif; ?>
</div>
