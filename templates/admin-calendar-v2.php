<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Calendrier scolaire v2</h1>
<p class="description">
    Vue visuelle du calendrier — pour fermer une <strong>plage de dates</strong> ou faire une correction texte, utilisez
    <a href="<?php echo esc_url(admin_url('admin.php?page=psc_school_calendar')); ?>">Calendrier scolaire</a>.
</p>

<?php
$notices = array(
    'imported'             => array('updated', ((int) psc_get_int('n')) . ' jour(s) importé(s)/mis à jour depuis le calendrier officiel.'),
    'import_failed'        => array('error', 'Le calendrier officiel n\'a pas pu être téléchargé. Réessayez plus tard, ou chargez le fichier manuellement ci-dessous.'),
    'uploaded'              => array('updated', ((int) psc_get_int('n')) . ' jour(s) importé(s)/mis à jour depuis le fichier envoyé.'),
    'upload_failed'         => array('error', 'Le fichier n\'a pas pu être lu. Vérifiez qu\'il s\'agit bien d\'un export .ics valide.'),
    'upload_invalid_type'   => array('error', 'Le fichier doit être au format .ics.'),
    'upload_too_large'      => array('error', 'Le fichier dépasse la taille maximale autorisée (2 Mo).'),
);
if ($psc_msg && isset($notices[$psc_msg])):
    list($cls, $txt) = $notices[$psc_msg];
?>
<div class="notice notice-<?php echo esc_attr($cls); ?> is-dismissible"><p><?php echo esc_html($txt); ?></p></div>
<?php endif; ?>

<div class="psc-box" style="max-width:none;">
<h2>Recharger le calendrier officiel (zone C)</h2>
<p>
    Source : calendrier scolaire du ministère de l'Éducation nationale. Ne remplace <strong>jamais</strong> une
    correction manuelle déjà faite (jour ou service fermé à la main) — seules les fermetures importées automatiquement
    sont rafraîchies. Un trimestre déjà créé n'est pas régénéré par ce rechargement ; seul un trimestre créé après
    bénéficiera des nouvelles dates.
</p>
<?php if ($imported_at): ?>
<p><em>Dernier chargement : <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($imported_at))); ?></em></p>
<?php endif; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:12px;"
      onsubmit="return confirm('Recharger le calendrier officiel ? Les corrections manuelles ne seront pas écrasées, seules les fermetures importées automatiquement seront rafraîchies.');">
<?php wp_nonce_field('psc_import_school_calendar'); ?>
<input type="hidden" name="action" value="psc_import_school_calendar">
<input type="hidden" name="return_page" value="psc_school_calendar_v2">
<?php submit_button($imported_at ? 'Recharger le calendrier officiel' : 'Charger le calendrier officiel', 'primary', 'submit', false); ?>
</form>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data"
      style="display:inline-flex;align-items:center;gap:8px;"
      onsubmit="return confirm('Importer ce fichier ? Les corrections manuelles ne seront pas écrasées, seules les fermetures importées automatiquement seront rafraîchies.');">
<?php wp_nonce_field('psc_upload_school_calendar'); ?>
<input type="hidden" name="action" value="psc_upload_school_calendar">
<input type="hidden" name="return_page" value="psc_school_calendar_v2">
<input type="file" name="ics_file" accept=".ics,text/calendar" required>
<?php submit_button('Importer le fichier', 'secondary', 'submit', false); ?>
</form>
</div>

<div class="psc-box" style="max-width:none;">

<div class="psc-cal2-toolbar">
    <div class="psc-cal2-nav">
        <?php if ($view === 'month'):
            $prev_month = gmdate('Y-m', strtotime($month . '-01 -1 month'));
            $next_month = gmdate('Y-m', strtotime($month . '-01 +1 month'));
        ?>
        <a class="button" href="<?php echo esc_url(add_query_arg(array('view' => 'month', 'month' => $prev_month))); ?>">&larr;</a>
        <h2><?php echo esc_html(date_i18n('F Y', strtotime($month . '-01'))); ?></h2>
        <a class="button" href="<?php echo esc_url(add_query_arg(array('view' => 'month', 'month' => $next_month))); ?>">&rarr;</a>
        <a class="button" href="<?php echo esc_url(add_query_arg(array('view' => 'month', 'month' => gmdate('Y-m')))); ?>">Aujourd'hui</a>
        <?php else:
            $prev_week = gmdate('Y-m-d', strtotime($dates[0] . ' -7 days'));
            $next_week = gmdate('Y-m-d', strtotime($dates[0] . ' +7 days'));
        ?>
        <a class="button" href="<?php echo esc_url(add_query_arg(array('view' => 'week', 'week' => $prev_week))); ?>">&larr;</a>
        <h2><?php echo esc_html(date_i18n('d/m', strtotime($dates[0]))) . ' – ' . esc_html(date_i18n('d/m/Y', strtotime(end($dates)))); ?></h2>
        <a class="button" href="<?php echo esc_url(add_query_arg(array('view' => 'week', 'week' => $next_week))); ?>">&rarr;</a>
        <a class="button" href="<?php echo esc_url(add_query_arg(array('view' => 'week', 'week' => gmdate('Y-m-d')))); ?>">Cette semaine</a>
        <?php endif; ?>
    </div>
    <div class="psc-cal2-view-switch">
        <a class="button <?php echo $view === 'month' ? 'button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('view' => 'month', 'month' => gmdate('Y-m'), 'week' => false))); ?>">Mois</a>
        <a class="button <?php echo $view === 'week' ? 'button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('view' => 'week', 'week' => $dates[0], 'month' => false))); ?>">Semaine</a>
    </div>
</div>

<div class="psc-cal2-legend">
    <span><span class="psc-cal2-legend-swatch" style="background:#f4faf3;border:1px solid #b8dfb8;"></span>Ouvert</span>
    <span><span class="psc-cal2-legend-swatch" style="background:#fdf2f2;border:1px solid #f0b8b8;"></span>Fermé (jour)</span>
    <span><span class="psc-cal2-legend-swatch" style="background:#f6f7f7;border:1px solid #dcdcde;"></span>Hors trimestre</span>
    <span><span class="psc-cal2-legend-swatch" style="background:#d63638;"></span>Prestation fermée</span>
    <span>Cliquez sur un jour pour le fermer/réouvrir, en tout ou en partie.</span>
</div>

<div class="psc-cal2-grid <?php echo $view === 'week' ? 'psc-cal2-grid--week' : ''; ?>">
<?php foreach (array('Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim') as $wd): ?>
    <div class="psc-cal2-weekday"><?php echo esc_html($wd); ?></div>
<?php endforeach; ?>

<?php foreach ($dates as $date):
    $d = $days[$date];
    $in_month = $view === 'month' ? (substr($date, 0, 7) === $month) : true;
?>
    <div class="psc-cal2-day psc-cal2-day--<?php echo esc_attr($d['status']); ?><?php echo $in_month ? '' : ' psc-cal2-day--dimmed'; ?>"
         <?php if ($d['status'] !== 'out_of_term'): ?>data-date="<?php echo esc_attr($date); ?>" data-status="<?php echo esc_attr($d['status']); ?>"<?php endif; ?>
         <?php if ($d['status'] === 'open'): foreach ($d['services'] as $code => $svc): ?>
         data-closed-<?php echo esc_attr(strtolower($code)); ?>="<?php echo $svc['closed'] ? '1' : '0'; ?>"
         <?php endforeach; endif; ?>
         style="<?php echo $in_month ? '' : 'opacity:.45;'; ?>">
        <div class="psc-cal2-daynum"><?php echo (int) substr($date, 8, 2); ?></div>

        <?php if ($d['status'] === 'out_of_term'): ?>
            <span class="psc-cal2-badge psc-cal2-badge--muted">Hors trimestre</span>
        <?php elseif ($d['status'] === 'closed_day'): ?>
            <span class="psc-cal2-badge psc-cal2-badge--closed"><?php echo esc_html($d['label']); ?></span>
        <?php else: ?>
            <span class="psc-cal2-badge psc-cal2-badge--open">Ouvert</span>
            <div class="psc-cal2-services">
            <?php foreach ($d['services'] as $code => $svc):
                $lbl = isset($services_meta[$code]) ? $services_meta[$code]['label'] : $code;
            ?>
                <span class="psc-cal2-pill <?php echo $svc['closed'] ? 'psc-cal2-pill--closed' : 'psc-cal2-pill--open'; ?>">
                    <?php echo esc_html($lbl); ?><?php if ($svc['count']): ?> (<?php echo (int) $svc['count']; ?>)<?php endif; ?>
                </span>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>

</div>
</div>

<div id="psc-cal2-modal-backdrop" class="psc-cal2-modal-backdrop" hidden></div>
<div id="psc-cal2-modal" class="psc-cal2-modal" hidden>
    <h3 id="psc-cal2-modal-title"></h3>
    <p id="psc-cal2-modal-body"></p>
    <div class="psc-cal2-modal-actions" style="flex-direction:column;align-items:stretch;">
        <input type="text" id="psc-cal2-modal-label" placeholder="Motif (optionnel)" maxlength="191">
        <div style="display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" class="button" id="psc-cal2-modal-cancel">Annuler</button>
            <button type="button" class="button button-primary" id="psc-cal2-modal-confirm">Confirmer</button>
        </div>
    </div>
</div>
