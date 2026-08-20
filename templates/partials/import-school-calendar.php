<?php if (!defined('ABSPATH')) exit;
/**
 * Bloc "Calendrier officiel (zone C)" — inclus depuis "Années scolaires"
 * (seule page à proposer le chargement/rechargement du calendrier du
 * ministère, dont on déduit aussi des années scolaires candidates).
 * Variable attendue, déjà disponible dans la portée de la page incluante :
 * - $imported_at : date du dernier import (option 'psc_school_calendar_imported_at')
 */
?>
<div class="psc-box" style="max-width:none;">
<h2>Calendrier officiel (zone C)</h2>
<p>
    Source : calendrier scolaire du ministère de l'Éducation nationale
    (<a href="https://www.education.gouv.fr/les-dates-des-vacances-scolaires-9079" target="_blank" rel="noopener noreferrer">education.gouv.fr/vacances</a>).
    Ne remplace <strong>jamais</strong> une correction manuelle déjà faite (jour ou service fermé à la main) — seules les
    fermetures importées automatiquement sont rafraîchies. Un trimestre déjà créé n'est pas régénéré par ce
    rechargement ; seul un trimestre créé après bénéficiera des nouvelles dates.
</p>
<?php if ($imported_at): ?>
<p><em>Dernier chargement : <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($imported_at))); ?></em></p>
<?php endif; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:12px;"
      onsubmit="return confirm('Recharger le calendrier officiel ? Les corrections manuelles ne seront pas écrasées, seules les fermetures importées automatiquement seront rafraîchies.');">
<?php wp_nonce_field('psc_import_school_calendar'); ?>
<input type="hidden" name="action" value="psc_import_school_calendar">
<?php submit_button($imported_at ? 'Recharger le calendrier officiel' : 'Charger le calendrier officiel', 'primary', 'submit', false); ?>
</form>

<p style="margin-top:20px;">
    Le serveur n'a pas d'accès Internet sortant ? Téléchargez le fichier .ics depuis
    <a href="https://www.education.gouv.fr/les-dates-des-vacances-scolaires-9079" target="_blank" rel="noopener noreferrer">education.gouv.fr</a>
    sur votre ordinateur, puis chargez-le ici :
</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data"
      style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;"
      onsubmit="return confirm('Importer ce fichier ? Les corrections manuelles ne seront pas écrasées, seules les fermetures importées automatiquement seront rafraîchies.');">
<?php wp_nonce_field('psc_upload_school_calendar'); ?>
<input type="hidden" name="action" value="psc_upload_school_calendar">
<input type="file" name="ics_file" accept=".ics,text/calendar" required>
<?php submit_button('Importer le fichier', 'secondary', 'submit', false); ?>
</form>

<p class="description" style="margin-top:12px;">
    URL utilisée pour le chargement automatique ci-dessus :
    <code><?php echo esc_html(Psc_School_Calendar::ics_url()); ?></code>
    — modifiable dans <a href="<?php echo esc_url(admin_url('admin.php?page=psc_settings')); ?>">Réglages</a>.
</p>
</div>
