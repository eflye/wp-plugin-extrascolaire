<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Calendrier scolaire</h1>

<?php
$msgs = array(
    'imported'       => array('updated', $imported_n . ' jour(s) importé(s)/mis à jour depuis le calendrier officiel.'),
    'import_failed'  => array('error', 'Le calendrier officiel n\'a pas pu être téléchargé. Réessayez plus tard.'),
    'closed'         => array('updated', 'Jour fermé.'),
    'opened'         => array('updated', 'Jour réouvert.'),
    'cancelled'      => array('notice-warning', 'Fermeture annulée.'),
    'confirm_needed' => array('notice-warning', 'Confirmation nécessaire : des inscriptions existent déjà ce jour-là.'),
    'invalid'        => array('error', 'Date invalide.'),
);
if ($psc_msg && isset($msgs[$psc_msg])):
    list($cls, $txt) = $msgs[$psc_msg];
?>
<div class="notice notice-<?php echo esc_attr($cls); ?> is-dismissible"><p><?php echo esc_html($txt); ?></p></div>
<?php endif; ?>

<div class="psc-box">
<h2>Calendrier officiel (zone C)</h2>
<p>
    Source : calendrier scolaire du ministère de l'Éducation nationale
    (<a href="https://www.education.gouv.fr/les-dates-des-vacances-scolaires-9079" target="_blank" rel="noopener noreferrer">education.gouv.fr/vacances</a>).
    Le chargement ferme automatiquement le périscolaire, la cantine et le menu de cantine pendant les vacances de la zone C —
    sans toucher aux corrections manuelles déjà faites ci-dessous.
</p>
<?php if ($imported_at): ?>
<p><em>Dernier chargement : <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($imported_at))); ?></em></p>
<?php endif; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_import_school_calendar'); ?>
<input type="hidden" name="action" value="psc_import_school_calendar">
<?php submit_button($imported_at ? 'Recharger le calendrier officiel' : 'Charger le calendrier officiel', 'primary', 'submit', false); ?>
</form>
</div>

<?php if ($pending): ?>
<div class="psc-box" style="border-left:4px solid #f5a623;">
<h2>⚠ Confirmation nécessaire</h2>
<p>
    Fermer le <strong><?php echo esc_html(psc_day_label($pending['date']) . ' ' . date_i18n('d/m/Y', strtotime($pending['date']))); ?></strong>
    supprimera <strong><?php echo (int) $pending_affected['registrations']; ?> inscription(s)</strong> déjà déclarée(s)
    par <strong><?php echo count($pending_affected['families']); ?> famille(s)</strong>. Ces prestations ne seront pas facturées,
    et chaque famille concernée recevra un e-mail listant ce qui a été retiré.
</p>
<table class="widefat striped" style="margin-bottom:16px;">
<thead><tr><th>Famille</th><th>Enfant(s) / prestation(s)</th></tr></thead>
<tbody>
<?php foreach ($pending_affected['families'] as $fam): ?>
<tr>
    <td><?php echo esc_html($fam['nom'] ?: $fam['email']); ?></td>
    <td>
        <?php
        $bits = array();
        $services = psc_services();
        foreach ($fam['items'] as $item) {
            $svc_lbl = isset($services[$item->service]) ? $services[$item->service]['label'] : $item->service;
            $bits[] = esc_html($item->child_prenom . ' ' . $item->child_nom . ' — ' . $svc_lbl);
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
<input type="hidden" name="date" value="<?php echo esc_attr($pending['date']); ?>">
<input type="hidden" name="label" value="<?php echo esc_attr($pending['label']); ?>">
<input type="hidden" name="confirm" value="1">
<button type="submit" class="button button-primary">Confirmer la fermeture</button>
</form>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
<?php wp_nonce_field('psc_cancel_school_day_close'); ?>
<input type="hidden" name="action" value="psc_cancel_school_day_close">
<button type="submit" class="button">Annuler</button>
</form>
</div>
<?php endif; ?>

<div class="psc-box">
<h2>Corriger un jour manuellement</h2>
<p>Formation des enseignants, fermeture exceptionnelle, ou correction d'un jour importé à tort — usage exceptionnel.</p>
<div style="display:flex;gap:32px;flex-wrap:wrap;">
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_close_school_day'); ?>
<input type="hidden" name="action" value="psc_close_school_day">
<table class="form-table"><tr>
<th><label for="psc-close-date">Fermer le</label></th>
<td>
    <input id="psc-close-date" type="date" name="date" required>
    <input type="text" name="label" placeholder="Motif (ex: Formation des enseignants)" maxlength="100" style="margin-left:8px;width:280px;">
</td>
</tr></table>
<?php submit_button('Fermer ce jour', 'secondary', 'submit', false); ?>
</form>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_open_school_day'); ?>
<input type="hidden" name="action" value="psc_open_school_day">
<table class="form-table"><tr>
<th><label for="psc-open-date">Réouvrir le</label></th>
<td><input id="psc-open-date" type="date" name="date" required></td>
</tr></table>
<?php submit_button('Réouvrir ce jour', 'secondary', 'submit', false); ?>
</form>
</div>
</div>

<div class="psc-box">
<h2>Jours fermés (<?php echo count($rows); ?>)</h2>
<?php if (empty($groups)): ?>
<p>Aucun jour fermé enregistré. Chargez le calendrier officiel ci-dessus.</p>
<?php else: ?>
<table class="widefat striped">
<thead><tr><th>Période</th><th>Motif</th><th>Jours</th><th>Origine</th></tr></thead>
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
    <td><?php echo $g['source'] === 'manual' ? '<span style="color:#f5a623;">Manuel</span>' : 'Import officiel'; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</div>
