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
<h2><?php esc_html_e('Calendrier officiel (zone C)', 'periscolaire-registration'); ?></h2>
<p>
    <?php esc_html_e("Source : calendrier scolaire du ministère de l'Éducation nationale
    (", 'periscolaire-registration'); ?><a href="https://www.education.gouv.fr/les-dates-des-vacances-scolaires-9079" target="_blank" rel="noopener noreferrer"><?php esc_html_e('education.gouv.fr/vacances', 'periscolaire-registration'); ?></a><?php esc_html_e(").
    Ne remplace ", 'periscolaire-registration'); ?><strong><?php esc_html_e('jamais', 'periscolaire-registration'); ?></strong><?php esc_html_e(" une correction manuelle déjà faite (jour ou service fermé à la main) — seules les
    fermetures importées automatiquement sont rafraîchies. Un trimestre déjà créé n'est pas régénéré par ce
    rechargement ; seul un trimestre créé après bénéficiera des nouvelles dates.", 'periscolaire-registration'); ?>
</p>
<?php if ($imported_at): ?>
<p><em><?php esc_html_e('Dernier chargement :', 'periscolaire-registration'); ?> <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($imported_at))); ?></em></p>
<?php endif; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:12px;"
      onsubmit="return confirm('<?php echo esc_js(__('Recharger le calendrier officiel ? Les corrections manuelles ne seront pas écrasées, seules les fermetures importées automatiquement seront rafraîchies.', 'periscolaire-registration')); ?>');">
<?php wp_nonce_field('psc_import_school_calendar'); ?>
<input type="hidden" name="action" value="psc_import_school_calendar">
<?php submit_button($imported_at ? __('Recharger le calendrier officiel', 'periscolaire-registration') : __('Charger le calendrier officiel', 'periscolaire-registration'), 'primary', 'submit', false); ?>
</form>

<p style="margin-top:20px;">
    <?php esc_html_e("Le serveur n'a pas d'accès Internet sortant ? Téléchargez le fichier .ics depuis", 'periscolaire-registration'); ?>
    <a href="https://www.education.gouv.fr/les-dates-des-vacances-scolaires-9079" target="_blank" rel="noopener noreferrer"><?php esc_html_e('education.gouv.fr', 'periscolaire-registration'); ?></a>
    <?php esc_html_e('sur votre ordinateur, puis chargez-le ici :', 'periscolaire-registration'); ?>
</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data"
      style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;"
      onsubmit="return confirm('<?php echo esc_js(__('Importer ce fichier ? Les corrections manuelles ne seront pas écrasées, seules les fermetures importées automatiquement seront rafraîchies.', 'periscolaire-registration')); ?>');">
<?php wp_nonce_field('psc_upload_school_calendar'); ?>
<input type="hidden" name="action" value="psc_upload_school_calendar">
<input type="file" name="ics_file" accept=".ics,text/calendar" required>
<?php submit_button(__('Importer le fichier', 'periscolaire-registration'), 'secondary', 'submit', false); ?>
</form>

<p class="description" style="margin-top:12px;">
    <?php esc_html_e('URL utilisée pour le chargement automatique ci-dessus :', 'periscolaire-registration'); ?>
    <code><?php echo esc_html(Psc_School_Calendar::ics_url()); ?></code>
    <?php esc_html_e('— modifiable dans', 'periscolaire-registration'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=psc_settings')); ?>"><?php esc_html_e('Réglages', 'periscolaire-registration'); ?></a>.
</p>
</div>
