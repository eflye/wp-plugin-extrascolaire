<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1><?php esc_html_e('Tableau de bord', 'periscolaire-registration'); ?></h1>

<div class="psc-dash-stats">
    <div class="psc-dash-card">
        <span class="psc-dash-card-label"><?php esc_html_e('Trimestre actif', 'periscolaire-registration'); ?></span>
        <?php if ($stats['trimestre']): ?>
        <span class="psc-dash-card-value"><?php echo esc_html($stats['trimestre']->label); ?></span>
        <span class="psc-dash-card-sub">
            <?php esc_html_e("jusqu'au", 'periscolaire-registration'); ?> <?php echo esc_html(date_i18n('d/m/Y', strtotime($stats['trimestre']->date_fin))); ?>
        </span>
        <?php else: ?>
        <span class="psc-dash-card-value psc-dash-card-empty"><?php esc_html_e('Aucun', 'periscolaire-registration'); ?></span>
        <?php endif; ?>
    </div>
    <div class="psc-dash-card">
        <span class="psc-dash-card-label"><?php esc_html_e('Familles actives', 'periscolaire-registration'); ?></span>
        <span class="psc-dash-card-value"><?php echo (int) $stats['familles_actives']; ?></span>
    </div>
    <div class="psc-dash-card">
        <span class="psc-dash-card-label"><?php esc_html_e('Enfants actifs', 'periscolaire-registration'); ?></span>
        <span class="psc-dash-card-value"><?php echo (int) $stats['enfants_actifs']; ?></span>
    </div>
</div>

<div class="psc-box">
<h2><?php esc_html_e('À faire', 'periscolaire-registration'); ?></h2>
<ul class="psc-dash-todos">
<?php foreach ($todos as $todo): ?>
<li class="psc-dash-todo <?php echo $todo['done'] ? 'psc-dash-todo-done' : 'psc-dash-todo-pending'; ?>">
    <span class="psc-dash-todo-icon" aria-hidden="true"><?php echo $todo['done'] ? '✔' : '○'; ?></span>
    <a href="<?php echo esc_url($todo['url']); ?>"><?php echo esc_html($todo['label']); ?></a>
</li>
<?php endforeach; ?>
</ul>
</div>
</div>
