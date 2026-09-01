<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1><?php esc_html_e('Trimestres', 'periscolaire-registration'); ?></h1>

<?php
$psc_notices = array(
    'created'            => array('success', __('Trimestre créé, le calendrier a été généré.', 'periscolaire-registration')),
    'updated'            => array('success', __('Trimestre modifié, le calendrier a été régénéré sur la nouvelle période.', 'periscolaire-registration')),
    'trimestre_deleted'  => array('success', __('Trimestre supprimé.', 'periscolaire-registration')),
    'activated'          => array('success', __('Trimestre activé : il est désormais visible par les familles.', 'periscolaire-registration')),
    'invalid_dates'      => array('error', __('Dates invalides. Merci de vérifier le format et de renseigner tous les champs.', 'periscolaire-registration')),
    'order_dates'        => array('error', __('La date de fin doit être postérieure à la date de début.', 'periscolaire-registration')),
    'too_long'           => array('error', __('La période est trop longue (maximum', 'periscolaire-registration') . ' ' . psc_max_trimestre_days() . ' ' . __('jours). Vérifiez les années saisies.', 'periscolaire-registration')),
    'invalid'            => array('error', __('Opération impossible : élément introuvable.', 'periscolaire-registration')),
    'active_trimestre'   => array('error', __('Impossible de supprimer le trimestre actif : activez-en un autre au préalable.', 'periscolaire-registration')),
    'confirm_mismatch'   => array('error', __('Le texte de confirmation ne correspond pas ("CONFIRMER" attendu). Le trimestre n\'a pas été supprimé.', 'periscolaire-registration')),
    'cancelled'          => array('success', __('Modification annulée : le trimestre est inchangé.', 'periscolaire-registration')),
    'trim_confirm_needed' => array('error', __('Cette modification sort des présences déjà déclarées de la période. Confirmez ci-dessous.', 'periscolaire-registration')),
    'trim_updated_purged' => array('success', __('Trimestre modifié. Les présences situées hors de la nouvelle période ont été supprimées.', 'periscolaire-registration')),
);
psc_admin_notice_map($psc_notices, $psc_msg); ?>

<?php
/*
 * Rétrécir un trimestre fait sortir de sa période des jours que des
 * familles ont déjà déclarés. Ces présences ne peuvent pas rester
 * rattachées au trimestre — elles seraient facturées sans appartenir à
 * aucune période valide — mais leur suppression se voit annoncée et
 * confirmée, comme pour la fermeture d'un jour occupé.
 */
if (!empty($pending_trimestre)): ?>
<div class="psc-box" style="border-left:4px solid #d63638;">
<h2>⚠ <?php esc_html_e('Confirmation nécessaire', 'periscolaire-registration'); ?></h2>
<p>
    <?php esc_html_e('La nouvelle période', 'periscolaire-registration'); ?>
    (<strong><?php echo esc_html(date_i18n('d/m/Y', strtotime($pending_trimestre['date_debut']))); ?></strong>
    <?php esc_html_e('au', 'periscolaire-registration'); ?>
    <strong><?php echo esc_html(date_i18n('d/m/Y', strtotime($pending_trimestre['date_fin']))); ?></strong>)
    <?php esc_html_e('ne couvre plus', 'periscolaire-registration'); ?>
    <strong><?php echo (int) $pending_trimestre['orphaned']; ?> <?php esc_html_e('présence(s) déjà déclarée(s)', 'periscolaire-registration'); ?></strong>
    <?php esc_html_e('par des familles sur ce trimestre.', 'periscolaire-registration'); ?>
</p>
<p>
    <?php esc_html_e('Ces présences seront', 'periscolaire-registration'); ?> <strong><?php esc_html_e('définitivement supprimées', 'periscolaire-registration'); ?></strong><?php esc_html_e(' : elles ne seront ni facturées, ni conservées. Les familles concernées ne sont pas prévenues automatiquement.', 'periscolaire-registration'); ?>
</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
    <?php wp_nonce_field('psc_update_trimestre'); ?>
    <input type="hidden" name="action" value="psc_update_trimestre">
    <input type="hidden" name="confirm" value="1">
    <input type="hidden" name="id" value="<?php echo esc_attr($pending_trimestre['id']); ?>">
    <input type="hidden" name="label" value="<?php echo esc_attr($pending_trimestre['label']); ?>">
    <input type="hidden" name="date_debut" value="<?php echo esc_attr($pending_trimestre['date_debut']); ?>">
    <input type="hidden" name="date_fin" value="<?php echo esc_attr($pending_trimestre['date_fin']); ?>">
    <input type="hidden" name="school_year_id" value="<?php echo esc_attr($pending_trimestre['school_year_id']); ?>">
    <button type="submit" class="button button-primary"><?php esc_html_e('Confirmer et supprimer ces présences', 'periscolaire-registration'); ?></button>
</form>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:8px;">
    <?php wp_nonce_field('psc_cancel_trimestre_update'); ?>
    <input type="hidden" name="action" value="psc_cancel_trimestre_update">
    <button type="submit" class="button"><?php esc_html_e('Annuler', 'periscolaire-registration'); ?></button>
</form>
</div>
<?php endif; ?>

<div class="psc-box">
<h2><?php esc_html_e('Créer un trimestre', 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e('Génère automatiquement le calendrier du trimestre : week-ends et jours fériés fermés par défaut, tout le reste ouvert.', 'periscolaire-registration'); ?></p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_add_trimestre'); ?>
<input type="hidden" name="action" value="psc_add_trimestre">
<table class="form-table">
<tr><th><label for="psc-label"><?php esc_html_e('Libellé', 'periscolaire-registration'); ?></label></th><td><input id="psc-label" type="text" name="label" class="regular-text" placeholder="<?php esc_attr_e('3ème trimestre 2025/2026', 'periscolaire-registration'); ?>" maxlength="190" required></td></tr>
<tr><th><label for="psc-annee"><?php esc_html_e('Année scolaire', 'periscolaire-registration'); ?></label></th><td>
<?php $psc_trim_years = Psc_School_Years::all(); ?>
<?php if (empty($psc_trim_years)): ?>
<em><?php esc_html_e("Créez d'abord une", 'periscolaire-registration'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=psc_school_years')); ?>"><?php esc_html_e('année scolaire', 'periscolaire-registration'); ?></a>.</em>
<?php else: ?>
<select id="psc-annee" name="school_year_id" required>
<?php foreach ($psc_trim_years as $y): ?>
<option value="<?php echo esc_attr($y->id); ?>" <?php selected($y->statut, 'active'); ?>><?php echo esc_html($y->label . ' (' . $y->statut . ')'); ?></option>
<?php endforeach; ?>
</select>
<?php endif; ?>
</td></tr>
<tr><th><label for="psc-debut"><?php esc_html_e('Date de début', 'periscolaire-registration'); ?></label></th><td><input id="psc-debut" type="date" name="date_debut" required></td></tr>
<tr><th><label for="psc-fin"><?php esc_html_e('Date de fin', 'periscolaire-registration'); ?></label></th><td><input id="psc-fin" type="date" name="date_fin" required></td></tr>
</table>
<?php submit_button(__('Créer le trimestre', 'periscolaire-registration')); ?>
</form>
</div>

<div class="psc-box">
<h2><?php esc_html_e('Trimestres existants', 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e('Modifier les dates régénère le calendrier du trimestre sur la nouvelle période : les jours fériés/vacances sont recalculés automatiquement, mais une fermeture ponctuelle que vous auriez ajoutée à la main sur un jour resté dans la période peut être réinitialisée.', 'periscolaire-registration'); ?>
<?php esc_html_e('Pour fermer les vacances scolaires ou toute autre période, rendez-vous sur', 'periscolaire-registration'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=psc_school_years')); ?>"><?php esc_html_e('Années scolaires', 'periscolaire-registration'); ?></a>.</p>
<table class="widefat striped">
<thead><tr><th><?php esc_html_e('Libellé', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Année scolaire', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Début', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Fin', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Statut', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Action', 'periscolaire-registration'); ?></th></tr></thead>
<tbody>
<?php if (empty($trimestres)): ?>
<tr><td colspan="6"><?php esc_html_e('Aucun trimestre créé pour le moment.', 'periscolaire-registration'); ?></td></tr>
<?php else: foreach ($trimestres as $t): $edit_form_id = 'trim-edit-form-' . $t->id; ?>
<tr>
<td>
  <label class="screen-reader-text" for="psc-t-label-<?php echo esc_attr($t->id); ?>"><?php esc_html_e('Libellé', 'periscolaire-registration'); ?></label>
  <input id="psc-t-label-<?php echo esc_attr($t->id); ?>" type="text" form="<?php echo esc_attr($edit_form_id); ?>" name="label" value="<?php echo esc_attr($t->label); ?>" maxlength="190" required class="regular-text">
</td>
<td>
  <label class="screen-reader-text" for="psc-t-annee-<?php echo esc_attr($t->id); ?>"><?php esc_html_e('Année scolaire', 'periscolaire-registration'); ?></label>
  <?php if (empty($psc_trim_years)): ?>
  —
  <?php else: ?>
  <select id="psc-t-annee-<?php echo esc_attr($t->id); ?>" form="<?php echo esc_attr($edit_form_id); ?>" name="school_year_id">
  <?php foreach ($psc_trim_years as $y): ?>
  <option value="<?php echo esc_attr($y->id); ?>" <?php selected((int) $t->school_year_id, (int) $y->id); ?>><?php echo esc_html($y->label); ?></option>
  <?php endforeach; ?>
  </select>
  <?php endif; ?>
</td>
<td>
  <label class="screen-reader-text" for="psc-t-debut-<?php echo esc_attr($t->id); ?>"><?php esc_html_e('Date de début', 'periscolaire-registration'); ?></label>
  <input id="psc-t-debut-<?php echo esc_attr($t->id); ?>" type="date" form="<?php echo esc_attr($edit_form_id); ?>" name="date_debut" value="<?php echo esc_attr($t->date_debut); ?>" required>
</td>
<td>
  <label class="screen-reader-text" for="psc-t-fin-<?php echo esc_attr($t->id); ?>"><?php esc_html_e('Date de fin', 'periscolaire-registration'); ?></label>
  <input id="psc-t-fin-<?php echo esc_attr($t->id); ?>" type="date" form="<?php echo esc_attr($edit_form_id); ?>" name="date_fin" value="<?php echo esc_attr($t->date_fin); ?>" required>
</td>
<td><?php echo $t->active ? '<strong class="psc-active">' . esc_html__('Actif (visible sur le site)', 'periscolaire-registration') . '</strong>' : '—'; ?></td>
<td style="white-space:nowrap">
<form id="<?php echo esc_attr($edit_form_id); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_update_trimestre'); ?>
<input type="hidden" name="action" value="psc_update_trimestre">
<input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
<button type="submit" class="button"><?php esc_html_e('Enregistrer', 'periscolaire-registration'); ?></button>
</form>
<?php if (!$t->active): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_activate_trimestre'); ?>
<input type="hidden" name="action" value="psc_activate_trimestre">
<input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
<button class="button"><?php esc_html_e('Activer', 'periscolaire-registration'); ?></button>
</form>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" class="psc-delete-trimestre-form">
<?php wp_nonce_field('psc_delete_trimestre'); ?>
<input type="hidden" name="action" value="psc_delete_trimestre">
<input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
<input type="hidden" name="confirm_text" value="">
<button type="button" class="button psc-delete-trimestre-btn"
        data-label="<?php echo esc_attr($t->label); ?>"
        data-count="<?php echo esc_attr($trimestre_reg_counts[$t->id] ?? 0); ?>"><?php esc_html_e('Supprimer', 'periscolaire-registration'); ?></button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
</div>

<style>
.psc-del-trim-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .4);
    z-index: 99998;
}
.psc-del-trim-modal {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #fff;
    border-radius: 4px;
    padding: 20px 24px;
    width: 460px;
    max-width: calc(100vw - 40px);
    z-index: 99999;
    box-shadow: 0 8px 30px rgba(0, 0, 0, .3);
}
.psc-del-trim-modal h3 { margin-top: 0; }
.psc-del-trim-modal input[type="text"] { width: 100%; margin: 4px 0 0; }
</style>

<div id="psc-del-trim-backdrop" class="psc-del-trim-backdrop" hidden></div>
<div id="psc-del-trim-modal" class="psc-del-trim-modal" role="dialog" aria-modal="true" aria-labelledby="psc-del-trim-title" tabindex="-1" hidden>
    <h3 id="psc-del-trim-title"><?php esc_html_e('Supprimer', 'periscolaire-registration'); ?> <span id="psc-del-trim-label"></span> ?</h3>
    <p id="psc-del-trim-consequence"></p>
    <p><?php esc_html_e('Cette action est', 'periscolaire-registration'); ?> <strong><?php esc_html_e('irréversible', 'periscolaire-registration'); ?></strong>.</p>
    <p><?php esc_html_e('Pour confirmer, tapez', 'periscolaire-registration'); ?> <strong>CONFIRMER</strong> <?php esc_html_e('ci-dessous :', 'periscolaire-registration'); ?></p>
    <input type="text" id="psc-del-trim-input" autocomplete="off">
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
        <button type="button" class="button" id="psc-del-trim-cancel"><?php esc_html_e('Annuler', 'periscolaire-registration'); ?></button>
        <button type="button" class="button button-primary" id="psc-del-trim-confirm" disabled style="background:#b32d2e;border-color:#b32d2e;"><?php esc_html_e('Supprimer définitivement', 'periscolaire-registration'); ?></button>
    </div>
</div>

<script>
(function () {
    var activeForm = null;
    var lastTrigger = null;
    var backdrop = document.getElementById('psc-del-trim-backdrop');
    var modal = document.getElementById('psc-del-trim-modal');
    var labelEl = document.getElementById('psc-del-trim-label');
    var consequenceEl = document.getElementById('psc-del-trim-consequence');
    var input = document.getElementById('psc-del-trim-input');
    var confirmBtn = document.getElementById('psc-del-trim-confirm');
    var cancelBtn = document.getElementById('psc-del-trim-cancel');

    /* La page ne charge pas la mécanique commune (psc-ajax) : la sémantique
       de dialogue est reprise ici en version minimale — Tab piégé dans la
       popin, Échap pour fermer, focus restitué au déclencheur (RGAA 7.1/7.3).
       offsetParent null = élément non rendu, à ignorer dans le cycle. */
    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function trapKeydown(e) {
        if (e.key === 'Escape') { closeModal(); return; }
        if (e.key !== 'Tab') return;
        var items = Array.prototype.filter.call(
            modal.querySelectorAll(FOCUSABLE),
            function (el) { return el.offsetParent !== null; }
        );
        if (!items.length) return;
        var first = items[0];
        var last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    document.querySelectorAll('.psc-delete-trimestre-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            activeForm = btn.closest('form');
            var count = parseInt(btn.getAttribute('data-count'), 10) || 0;
            labelEl.textContent = btn.getAttribute('data-label');
            consequenceEl.textContent = count > 0
                ? '<?php echo esc_js(__('Ce trimestre, son calendrier, ET les', 'periscolaire-registration')); ?> ' + count + ' <?php echo esc_js(__('inscription(s) déjà déclarée(s) par les familles pour cette période seront supprimés définitivement.', 'periscolaire-registration')); ?>'
                : '<?php echo esc_js(__('Ce trimestre et son calendrier seront supprimés définitivement.', 'periscolaire-registration')); ?>';
            input.value = '';
            confirmBtn.disabled = true;
            modal.hidden = false;
            backdrop.hidden = false;
            lastTrigger = btn;
            document.addEventListener('keydown', trapKeydown);
            input.focus();
        });
    });

    input.addEventListener('input', function () {
        confirmBtn.disabled = input.value !== 'CONFIRMER';
    });

    function closeModal() {
        modal.hidden = true;
        backdrop.hidden = true;
        document.removeEventListener('keydown', trapKeydown);
        if (lastTrigger) { lastTrigger.focus(); lastTrigger = null; }
        activeForm = null;
    }
    cancelBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);

    confirmBtn.addEventListener('click', function () {
        if (!activeForm || input.value !== 'CONFIRMER') return;
        activeForm.querySelector('input[name="confirm_text"]').value = input.value;
        activeForm.submit();
    });
})();
</script>
