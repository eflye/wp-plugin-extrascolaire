<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1><?php esc_html_e('Années scolaires', 'periscolaire-registration'); ?></h1>

<?php
$psc_notices = array(
    'created'             => array('success', __('Année scolaire créée.', 'periscolaire-registration')),
    'updated'             => array('success', __('Année scolaire modifiée.', 'periscolaire-registration')),
    'year_deleted'        => array('success', __('Année scolaire supprimée.', 'periscolaire-registration')),
    'activated'           => array('success', __("Année activée : c'est désormais celle visible par les familles.", 'periscolaire-registration')),
    'archived'            => array('success', __('Année archivée.', 'periscolaire-registration')),
    'promoted'            => array('success', __("Passage d'année effectué.", 'periscolaire-registration')),
    'promotion_cancelled' => array('success', __("Passage d'année annulé.", 'periscolaire-registration')),
    'invalid'             => array('error', __('Opération impossible : élément introuvable ou invalide.', 'periscolaire-registration')),
    'order_dates'         => array('error', __('La date de fin doit être postérieure à la date de début.', 'periscolaire-registration')),
    'active_year'         => array('error', __("Impossible de supprimer l'année active : activez-en une autre au préalable.", 'periscolaire-registration')),
    'imported'            => array('success', ((int) $imported_n) . ' ' . __('jour(s) importé(s)/mis à jour depuis le calendrier officiel.', 'periscolaire-registration')),
    'import_failed'       => array('error', __("Le calendrier officiel n'a pas pu être téléchargé. Réessayez plus tard, ou chargez le fichier manuellement ci-dessous.", 'periscolaire-registration')),
    'uploaded'            => array('success', ((int) $imported_n) . ' ' . __('jour(s) importé(s)/mis à jour depuis le fichier envoyé.', 'periscolaire-registration')),
    'upload_failed'       => array('error', __("Le fichier n'a pas pu être lu. Vérifiez qu'il s'agit bien d'un export .ics valide.", 'periscolaire-registration')),
    'upload_invalid_type' => array('error', __('Le fichier doit être au format .ics.', 'periscolaire-registration')),
    'upload_too_large'    => array('error', __('Le fichier dépasse la taille maximale autorisée (2 Mo).', 'periscolaire-registration')),
    'closed'              => array('success', __('Jour(s) fermé(s).', 'periscolaire-registration')),
    'opened'              => array('success', __('Jour réouvert.', 'periscolaire-registration')),
    'cancelled'           => array('error', __('Fermeture annulée.', 'periscolaire-registration')),
    'confirm_needed'      => array('error', __('Confirmation nécessaire : des inscriptions existent déjà sur cette période.', 'periscolaire-registration')),
    'invalid_date'        => array('error', __('Date(s) invalide(s).', 'periscolaire-registration')),
    'year_config_saved'   => array('success', __('Configuration du planning enregistrée.', 'periscolaire-registration')),
    'year_config_invalid' => array('error', __('Configuration invalide : vérifiez les dates (la fin doit suivre le début).', 'periscolaire-registration')),
    'year_invalid'        => array('error', __('Aucune année scolaire courante.', 'periscolaire-registration')),
);
psc_admin_notice_map($psc_notices, $psc_msg); ?>

<?php
/* ------------------------------------------------------------------
   Configuration du planning de l'année scolaire courante : bornes,
   plages de vacances, jours fériés à exclure, verrou. Les jours
   d'école sont calculés (lundi, mardi, jeudi, vendredi, moins
   vacances et fériés) — jamais stockés.
------------------------------------------------------------------ */
if ($planning_year):
?>
<div class="psc-box" id="psc-planning-year-config">
<h2><?php esc_html_e('Planning — année scolaire courante', 'periscolaire-registration'); ?> (<?php echo esc_html($planning_year->year_key); ?>)</h2>
<p class="description"><?php printf(esc_html__('%d jour(s) d\'école calculés sur cette période (lundi, mardi, jeudi et vendredi, moins vacances et fériés).', 'periscolaire-registration'), (int) $planning_school_days); ?></p>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_save_school_year_config'); ?>
<input type="hidden" name="action" value="psc_save_school_year_config">

<table class="form-table">
<tr>
<th><label for="psc-sy-start"><?php esc_html_e('Début de l\'année', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-sy-start" type="date" name="date_start" value="<?php echo esc_attr($planning_year->date_start); ?>" required></td>
</tr>
<tr>
<th><label for="psc-sy-end"><?php esc_html_e('Fin de l\'année', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-sy-end" type="date" name="date_end" value="<?php echo esc_attr($planning_year->date_end); ?>" required></td>
</tr>
<tr>
<th><label for="psc-sy-lock"><?php esc_html_e('Préavis de modification', 'periscolaire-registration'); ?></label></th>
<td>
<input id="psc-sy-lock" type="number" name="lock_hours" min="0" max="720" value="<?php echo esc_attr((int) $planning_year->lock_hours); ?>" class="small-text"> <?php esc_html_e('heures', 'periscolaire-registration'); ?>
<p class="description"><?php esc_html_e('S\'applique aux deux écritures : une exception à moins de 48 h est refusée côté serveur, et un changement de rythme ne repropage jamais sur les jours déjà verrouillés.', 'periscolaire-registration'); ?></p>
</td>
</tr>
</table>

<h3><?php esc_html_e('Plages de vacances', 'periscolaire-registration'); ?></h3>
<p class="description"><?php esc_html_e('Six plages possibles (Toussaint, Noël, hiver, printemps, ponts...). Laissez tout vide pour utiliser le calendrier scolaire officiel importé ci-dessous.', 'periscolaire-registration'); ?></p>
<table class="widefat striped" style="max-width:560px;">
<thead><tr><th><?php esc_html_e('Début', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Fin (incluse)', 'periscolaire-registration'); ?></th></tr></thead>
<tbody>
<?php for ($i = 0; $i < 6; $i++): $r = isset($planning_ranges[$i]) ? $planning_ranges[$i] : array('', ''); ?>
<tr>
<td><input type="date" name="vacation_start[<?php echo esc_attr($i); ?>]" value="<?php echo esc_attr($r[0]); ?>"></td>
<td><input type="date" name="vacation_end[<?php echo esc_attr($i); ?>]" value="<?php echo esc_attr($r[1]); ?>"></td>
</tr>
<?php endfor; ?>
</tbody>
</table>

<p><?php submit_button(__('Enregistrer la configuration du planning', 'periscolaire-registration'), 'primary', 'submit', false); ?></p>
</form>

<h3><?php esc_html_e('Jours fériés à exclure', 'periscolaire-registration'); ?></h3>
<p class="description"><?php esc_html_e('Pré-remplis avec les fériés métropole : retirez ou complétez (ponts, fêtes locales). Un jour retiré redevient un jour d\'école.', 'periscolaire-registration'); ?></p>
<table class="widefat striped" style="max-width:560px;">
<thead><tr><th><?php esc_html_e('Date', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Libellé', 'periscolaire-registration'); ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($planning_holidays as $h => $h_label): ?>
<tr>
<td><?php echo esc_html(psc_day_label($h) . ' ' . date_i18n('d/m/Y', strtotime($h))); ?></td>
<td><?php echo esc_html($h_label !== null && $h_label !== '' ? $h_label : __('Férié', 'periscolaire-registration')); ?></td>
<td>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_attr(__('Retirer ce jour des jours fériés ? Il redevient un jour d\'école.', 'periscolaire-registration')); ?>');">
<?php wp_nonce_field('psc_remove_school_holiday'); ?>
<input type="hidden" name="action" value="psc_remove_school_holiday">
<input type="hidden" name="jour_date" value="<?php echo esc_attr($h); ?>">
<?php submit_button(__('Retirer', 'periscolaire-registration'), 'small', 'submit', false); ?>
</form>
</td>
</tr>
<?php endforeach; ?>
<?php if (!$planning_holidays): ?>
<tr><td colspan="3"><?php esc_html_e('Aucun jour férié enregistré : les fériés métropole calculés serviront de repli.', 'periscolaire-registration'); ?></td></tr>
<?php endif; ?>
</tbody>
</table>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
<?php wp_nonce_field('psc_add_school_holiday'); ?>
<input type="hidden" name="action" value="psc_add_school_holiday">
<input type="date" name="jour_date" required>
<input type="text" name="label" placeholder="<?php esc_attr_e('Libellé (facultatif)', 'periscolaire-registration'); ?>">
<?php submit_button(__('Ajouter un jour férié', 'periscolaire-registration'), 'secondary', 'submit', false); ?>
</form>
</div>
<?php endif; ?>

<?php include PSC_PATH . 'templates/partials/import-school-calendar.php'; ?>

<?php if ($pending): ?>
<div class="psc-box" style="border-left:4px solid #f5a623;">
<h2>⚠ <?php esc_html_e('Confirmation nécessaire', 'periscolaire-registration'); ?></h2>
<p>
    <?php if ($pending['date_debut'] === $pending['date_fin']): ?>
    <?php esc_html_e('Fermer le', 'periscolaire-registration'); ?> <strong><?php echo esc_html(psc_day_label($pending['date_debut']) . ' ' . date_i18n('d/m/Y', strtotime($pending['date_debut']))); ?></strong>
    <?php else: ?>
    <?php esc_html_e('Fermer la période du', 'periscolaire-registration'); ?> <strong><?php echo esc_html(date_i18n('d/m/Y', strtotime($pending['date_debut']))); ?></strong>
    <?php esc_html_e('au', 'periscolaire-registration'); ?> <strong><?php echo esc_html(date_i18n('d/m/Y', strtotime($pending['date_fin']))); ?></strong>
    <?php endif; ?>
    <?php esc_html_e('supprimera', 'periscolaire-registration'); ?> <strong><?php echo (int) $pending_affected['registrations']; ?> <?php esc_html_e('inscription(s)', 'periscolaire-registration'); ?></strong> <?php esc_html_e('déjà déclarée(s) par', 'periscolaire-registration'); ?>
    <strong><?php echo count($pending_affected['families']); ?> <?php esc_html_e('famille(s)', 'periscolaire-registration'); ?></strong><?php esc_html_e(". Ces prestations ne seront pas facturées, et chaque famille concernée recevra un e-mail listant ce qui a été retiré.", 'periscolaire-registration'); ?>
</p>
<table class="widefat striped" style="margin-bottom:16px;">
<thead><tr><th><?php esc_html_e('Famille', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Enfant(s) / prestation(s)', 'periscolaire-registration'); ?></th></tr></thead>
<tbody>
<?php foreach ($pending_affected['families'] as $fam): ?>
<tr>
    <td><?php echo esc_html($fam['nom'] ?: $fam['email']); ?></td>
    <td>
        <?php
        $bits = array();
        $services = psc_services();
        $is_range = $pending['date_debut'] !== $pending['date_fin'];
        foreach ($fam['items'] as $item) {
            $svc_lbl = isset($services[$item->service]) ? $services[$item->service]['label'] : $item->service;
            $bit = esc_html($item->child_prenom . ' ' . $item->child_nom . ' — ' . $svc_lbl);
            if ($is_range) {
                $bit .= ' (' . esc_html(date_i18n('d/m/Y', strtotime($item->jour_date))) . ')';
            }
            $bits[] = $bit;
        }
        echo implode('<br>', $bits);
        ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
<?php wp_nonce_field('psc_close_school_day'); ?>
<input type="hidden" name="action" value="psc_close_school_day">
<input type="hidden" name="date_debut" value="<?php echo esc_attr($pending['date_debut']); ?>">
<input type="hidden" name="date_fin" value="<?php echo esc_attr($pending['date_fin']); ?>">
<input type="hidden" name="label" value="<?php echo esc_attr($pending['label']); ?>">
<input type="hidden" name="confirm" value="1">
<button type="submit" class="button button-primary"><?php esc_html_e('Confirmer la fermeture', 'periscolaire-registration'); ?></button>
</form>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
<?php wp_nonce_field('psc_cancel_school_day_close'); ?>
<input type="hidden" name="action" value="psc_cancel_school_day_close">
<button type="submit" class="button"><?php esc_html_e('Annuler', 'periscolaire-registration'); ?></button>
</form>
</div>
<?php endif; ?>

<div class="psc-box">
<h2><?php esc_html_e('Créer une année scolaire', 'periscolaire-registration'); ?></h2>
<?php if (!empty($candidates)): ?>
<p class="description"><?php esc_html_e("Années scolaires détectées dans le calendrier importé (intervalle entre deux étés) — cliquez pour préremplir le formulaire ci-dessous, rien n'est créé automatiquement :", 'periscolaire-registration'); ?></p>
<table class="widefat striped" style="margin-bottom:16px;max-width:600px;">
<thead><tr><th><?php esc_html_e('Libellé proposé', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Début', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Fin', 'periscolaire-registration'); ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($candidates as $i => $c): ?>
<tr data-testid="year-candidate-<?php echo esc_attr($i); ?>">
    <td><?php echo esc_html($c['label']); ?></td>
    <td><?php echo esc_html(date_i18n('d/m/Y', strtotime($c['date_debut']))); ?></td>
    <td><?php echo esc_html(date_i18n('d/m/Y', strtotime($c['date_fin']))); ?></td>
    <td>
    <?php if ($c['exists']): ?>
        <em><?php esc_html_e('Déjà créée', 'periscolaire-registration'); ?></em>
    <?php else: ?>
        <button type="button" class="button psc-year-candidate-btn"
                data-label="<?php echo esc_attr($c['label']); ?>"
                data-debut="<?php echo esc_attr($c['date_debut']); ?>"
                data-fin="<?php echo esc_attr($c['date_fin']); ?>"
                data-testid="year-candidate-prefill-<?php echo esc_attr($i); ?>"><?php esc_html_e('Préremplir le formulaire', 'periscolaire-registration'); ?></button>
    <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_add_school_year'); ?>
<input type="hidden" name="action" value="psc_add_school_year">
<table class="form-table">
<tr><th><label for="psc-y-label"><?php esc_html_e('Libellé', 'periscolaire-registration'); ?></label></th><td><input id="psc-y-label" type="text" name="label" class="regular-text" placeholder="2026-2027" maxlength="20" required data-testid="year-label-input"></td></tr>
<tr><th><label for="psc-y-debut"><?php esc_html_e('Date de début', 'periscolaire-registration'); ?></label></th><td><input id="psc-y-debut" type="date" name="date_debut" required data-testid="year-debut-input"></td></tr>
<tr><th><label for="psc-y-fin"><?php esc_html_e('Date de fin', 'periscolaire-registration'); ?></label></th><td><input id="psc-y-fin" type="date" name="date_fin" required data-testid="year-fin-input"></td></tr>
</table>
<?php submit_button(__('Créer l\'année', 'periscolaire-registration'), 'primary', 'submit', true, array('data-testid' => 'year-create-submit')); ?>
</form>
</div>

<div class="psc-box">
<h2><?php esc_html_e('Années existantes', 'periscolaire-registration'); ?></h2>
<table class="widefat striped">
<thead><tr><th><?php esc_html_e('Libellé', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Début', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Fin', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Statut', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Action', 'periscolaire-registration'); ?></th></tr></thead>
<tbody>
<?php if (empty($years)): ?>
<tr><td colspan="5"><?php esc_html_e('Aucune année scolaire créée pour le moment.', 'periscolaire-registration'); ?></td></tr>
<?php else: foreach ($years as $y): $edit_form_id = 'year-edit-form-' . $y->id; ?>
<tr data-testid="year-row-<?php echo esc_attr($y->id); ?>">
<td>
  <label class="screen-reader-text" for="psc-y-label-<?php echo esc_attr($y->id); ?>"><?php esc_html_e('Libellé', 'periscolaire-registration'); ?></label>
  <input id="psc-y-label-<?php echo esc_attr($y->id); ?>" type="text" form="<?php echo esc_attr($edit_form_id); ?>" name="label" value="<?php echo esc_attr($y->label); ?>" maxlength="20" required class="regular-text" data-testid="year-edit-label-<?php echo esc_attr($y->id); ?>">
</td>
<td>
  <label class="screen-reader-text" for="psc-y-debut-<?php echo esc_attr($y->id); ?>"><?php esc_html_e('Date de début', 'periscolaire-registration'); ?></label>
  <input id="psc-y-debut-<?php echo esc_attr($y->id); ?>" type="date" form="<?php echo esc_attr($edit_form_id); ?>" name="date_debut" value="<?php echo esc_attr($y->date_debut); ?>" required data-testid="year-edit-debut-<?php echo esc_attr($y->id); ?>">
</td>
<td>
  <label class="screen-reader-text" for="psc-y-fin-<?php echo esc_attr($y->id); ?>"><?php esc_html_e('Date de fin', 'periscolaire-registration'); ?></label>
  <input id="psc-y-fin-<?php echo esc_attr($y->id); ?>" type="date" form="<?php echo esc_attr($edit_form_id); ?>" name="date_fin" value="<?php echo esc_attr($y->date_fin); ?>" required data-testid="year-edit-fin-<?php echo esc_attr($y->id); ?>">
</td>
<td data-testid="year-statut-<?php echo esc_attr($y->id); ?>">
<?php if ($y->statut === 'active'): ?><strong class="psc-active"><?php esc_html_e('Active (visible sur le site)', 'periscolaire-registration'); ?></strong>
<?php elseif ($y->statut === 'preparation'): ?><?php esc_html_e('En préparation', 'periscolaire-registration'); ?>
<?php else: ?><?php esc_html_e('Archivée', 'periscolaire-registration'); ?>
<?php endif; ?>
</td>
<td style="white-space:nowrap">
<form id="<?php echo esc_attr($edit_form_id); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_update_school_year'); ?>
<input type="hidden" name="action" value="psc_update_school_year">
<input type="hidden" name="id" value="<?php echo esc_attr($y->id); ?>">
<button type="submit" class="button" data-testid="year-save-<?php echo esc_attr($y->id); ?>"><?php esc_html_e('Enregistrer', 'periscolaire-registration'); ?></button>
</form>
<?php if ($y->statut !== 'active'): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_activate_school_year'); ?>
<input type="hidden" name="action" value="psc_activate_school_year">
<input type="hidden" name="id" value="<?php echo esc_attr($y->id); ?>">
<button class="button" data-testid="year-activate-<?php echo esc_attr($y->id); ?>"><?php esc_html_e('Activer', 'periscolaire-registration'); ?></button>
</form>
<?php endif; ?>
<?php if ($y->statut !== 'archivee'): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_archive_school_year'); ?>
<input type="hidden" name="action" value="psc_archive_school_year">
<input type="hidden" name="id" value="<?php echo esc_attr($y->id); ?>">
<button class="button" onclick="return confirm('<?php echo esc_js(__('Archiver cette année ? Elle restera consultable en lecture seule.', 'periscolaire-registration')); ?>');" data-testid="year-archive-<?php echo esc_attr($y->id); ?>"><?php esc_html_e('Archiver', 'periscolaire-registration'); ?></button>
</form>
<?php endif; ?>
<?php if ($y->statut !== 'active'): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_delete_school_year'); ?>
<input type="hidden" name="action" value="psc_delete_school_year">
<input type="hidden" name="id" value="<?php echo esc_attr($y->id); ?>">
<button class="button" onclick="return confirm('<?php echo esc_js(__("Supprimer définitivement l'année", 'periscolaire-registration')); ?> <?php echo esc_js($y->label); ?> <?php echo esc_js(__("? Les inscriptions des enfants pour cette année (classe, justificatif d'assurance) seront supprimées.", 'periscolaire-registration')); ?>');" data-testid="year-delete-<?php echo esc_attr($y->id); ?>"><?php esc_html_e('Supprimer', 'periscolaire-registration'); ?></button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>

<div class="psc-box">
<h2><?php esc_html_e('Corriger un jour manuellement', 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e("Formation des enseignants, vacances scolaires, fermeture exceptionnelle, ou correction d'un jour importé à tort. Laissez « Au » vide pour ne fermer qu'un seul jour.", 'periscolaire-registration'); ?></p>
<div style="display:flex;gap:32px;flex-wrap:wrap;">
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_close_school_day'); ?>
<input type="hidden" name="action" value="psc_close_school_day">
<table class="form-table">
<tr>
<th><label for="psc-close-date-debut"><?php esc_html_e('Fermer du', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-close-date-debut" type="date" name="date_debut" required></td>
</tr>
<tr>
<th><label for="psc-close-date-fin"><?php esc_html_e('Au (optionnel)', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-close-date-fin" type="date" name="date_fin"></td>
</tr>
<tr>
<th><label for="psc-close-label"><?php esc_html_e('Motif', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-close-label" type="text" name="label" placeholder="<?php esc_attr_e('Ex : Formation des enseignants, Vacances de la Toussaint', 'periscolaire-registration'); ?>" maxlength="100" style="width:280px;"></td>
</tr>
</table>
<?php submit_button(__('Fermer', 'periscolaire-registration'), 'secondary', 'submit', false); ?>
</form>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_open_school_day'); ?>
<input type="hidden" name="action" value="psc_open_school_day">
<table class="form-table"><tr>
<th><label for="psc-open-date"><?php esc_html_e('Réouvrir le', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-open-date" type="date" name="date" required></td>
</tr></table>
<?php submit_button(__('Réouvrir ce jour', 'periscolaire-registration'), 'secondary', 'submit', false); ?>
</form>
</div>
</div>

<?php if (!empty($groups)): ?>
<div class="psc-box">
<h2><?php esc_html_e('Jours fermés (', 'periscolaire-registration'); ?><?php echo (int) array_sum(array_column($groups, 'count')); ?>)</h2>
<p class="description"><?php esc_html_e('Résultat du dernier chargement du calendrier officiel ci-dessus.', 'periscolaire-registration'); ?></p>
<table class="widefat striped">
<thead><tr><th><?php esc_html_e('Période', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Motif', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Jours', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Origine', 'periscolaire-registration'); ?></th></tr></thead>
<tbody>
<?php foreach (array_reverse($groups) as $g): ?>
<tr>
    <td>
        <?php if ($g['start'] === $g['end']): ?>
            <?php echo esc_html(date_i18n('d/m/Y', strtotime($g['start']))); ?>
        <?php else: ?>
            <?php echo esc_html(date_i18n('d/m/Y', strtotime($g['start']))); ?> → <?php echo esc_html(date_i18n('d/m/Y', strtotime($g['end']))); ?>
        <?php endif; ?>
    </td>
    <td><?php echo esc_html($g['label'] ?: '—'); ?></td>
    <td><?php echo (int) $g['count']; ?></td>
    <td><?php echo $g['source'] === 'manual' ? '<span style="color:#f5a623;">' . esc_html__('Manuel', 'periscolaire-registration') . '</span>' : esc_html__('Import officiel', 'periscolaire-registration'); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<div class="psc-box">
<h2><?php esc_html_e("Passage à l'année suivante", 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e("Prépare la montée de classe en masse des enfants actifs, de l'année en cours vers une année cible : un récapitulatif s'affiche pour correction avant toute écriture en base. Rien n'est modifié tant que vous n'avez pas confirmé sur l'écran suivant.", 'periscolaire-registration'); ?></p>
<?php if (count($years) < 2): ?>
<p><em><?php esc_html_e("Créez d'abord au moins deux années scolaires (l'année en cours et la suivante).", 'periscolaire-registration'); ?></em></p>
<?php else: ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_stage_promotion'); ?>
<input type="hidden" name="action" value="psc_stage_promotion">
<table class="form-table">
<tr><th><label for="psc-from-year"><?php esc_html_e("Depuis l'année", 'periscolaire-registration'); ?></label></th><td>
<select id="psc-from-year" name="from_year_id" required data-testid="promotion-from-select">
<?php foreach ($years as $y): ?>
<option value="<?php echo esc_attr($y->id); ?>" <?php selected($y->statut, 'active'); ?>><?php echo esc_html($y->label); ?></option>
<?php endforeach; ?>
</select>
</td></tr>
<tr><th><label for="psc-to-year"><?php esc_html_e("Vers l'année", 'periscolaire-registration'); ?></label></th><td>
<select id="psc-to-year" name="to_year_id" required data-testid="promotion-to-select">
<?php foreach ($years as $y): ?>
<option value="<?php echo esc_attr($y->id); ?>" <?php selected($y->statut, 'preparation'); ?>><?php echo esc_html($y->label); ?></option>
<?php endforeach; ?>
</select>
</td></tr>
</table>
<?php submit_button(__('Préparer le passage d\'année', 'periscolaire-registration'), 'primary', 'submit', true, array('data-testid' => 'promotion-stage-submit')); ?>
</form>
<?php endif; ?>
</div>
</div>

<script>
(function () {
    document.querySelectorAll('.psc-year-candidate-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('psc-y-label').value = btn.getAttribute('data-label');
            document.getElementById('psc-y-debut').value = btn.getAttribute('data-debut');
            document.getElementById('psc-y-fin').value = btn.getAttribute('data-fin');
            document.getElementById('psc-y-label').scrollIntoView({ block: 'center' });
            document.getElementById('psc-y-label').focus();
        });
    });
})();
</script>
