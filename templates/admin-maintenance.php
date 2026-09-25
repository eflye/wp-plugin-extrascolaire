<?php
if (!defined('ABSPATH')) exit;
/**
 * Périscolaire › Maintenance (cf. Psc_Admin_Maintenance).
 *
 * @var array  $steps
 * @var array  $recette_items
 * @var string $psc_msg
 * @var string $generated_key
 * @var array  $rechiffre
 */
$psc_notices = array(
    'sauvegarde_ok'           => array('success', __('Sauvegarde confirmée pour cette version.', 'periscolaire-registration')),
    'sauvegarde_requise'      => array('error', __('Confirmez d’abord la sauvegarde (étape 1).', 'periscolaire-registration')),
    'base_ok'                 => array('success', __('La base de données est à jour.', 'periscolaire-registration')),
    'base_echec'              => array('error', __('La mise à jour de la base ne s’est pas terminée : voir l’étape 2.', 'periscolaire-registration')),
    'cle_en_base'             => array('error', __('La clé est encore dans la base : déclarez PSC_ENCRYPTION_KEY puis redémarrez le conteneur (étape 3).', 'periscolaire-registration')),
    'rechiffrement_ok'        => array('success', sprintf(/* translators: %d: nombre de valeurs */ __('Rechiffrement terminé : %d valeur(s) rechiffrée(s).', 'periscolaire-registration'), $rechiffre['n'])),
    'rechiffrement_incomplet' => array('error', sprintf(/* translators: %d: nombre de valeurs */ __('Rechiffrement incomplet : %d valeur(s) en erreur. Relancez-le, les valeurs déjà rechiffrées ne sont pas retouchées.', 'periscolaire-registration'), $rechiffre['er'])),
    'modeles_ok'              => array('success', __('Modèles d’e-mails notés comme relus.', 'periscolaire-registration')),
    'recette_ok'              => array('success', __('Recette enregistrée.', 'periscolaire-registration')),
);
$psc_status_label = array(
    'ok'       => __('Fait', 'periscolaire-registration'),
    'a_faire'  => __('À faire', 'periscolaire-registration'),
    'bloquant' => __('Bloquant', 'periscolaire-registration'),
);
$psc_status = function ($key) use ($steps, $psc_status_label) {
    $status = $steps[$key]['status'];
    return '<span class="psc-maint-status psc-maint-' . esc_attr($status) . '" data-testid="maintenance-status-' . esc_attr($key) . '">'
        . esc_html($psc_status_label[$status]) . '</span>';
};
$psc_who = function ($stamp) {
    if (!$stamp) return '';
    $user = get_userdata((int) $stamp['user']);
    return sprintf(
        /* translators: 1: nom de l'utilisateur, 2: date */
        __('par %1$s le %2$s', 'periscolaire-registration'),
        $user ? $user->display_name : '#' . (int) $stamp['user'],
        date_i18n('d/m/Y H:i', strtotime($stamp['at']))
    );
};
$psc_sauvegarde_ok = (bool) $steps['sauvegarde']['stamp'];
?>
<div class="wrap psc-maintenance">
<h1><?php esc_html_e('Maintenance', 'periscolaire-registration'); ?></h1>
<?php psc_admin_notice_map($psc_notices, $psc_msg); ?>
<p class="description">
<?php
printf(
    /* translators: %s: version du plugin */
    esc_html__('Étapes à suivre après l’installation de la version %s. Tout se fait ici, sans ligne de commande. Chaque état est recalculé à l’ouverture de la page.', 'periscolaire-registration'),
    esc_html(PSC_VERSION)
);
?>
</p>
<style>
.psc-maintenance section { background:#fff; border:1px solid #c3c4c7; padding:4px 20px 16px; margin:16px 0; max-width:960px; }
.psc-maint-status { display:inline-block; padding:2px 10px; border-radius:10px; font-size:13px; font-weight:600; margin-left:8px; vertical-align:middle; }
.psc-maint-ok { background:#e6f4ea; color:#1e5631; }
.psc-maint-a_faire { background:#fcf0e3; color:#6b3a00; }
.psc-maint-bloquant { background:#fbe7e7; color:#8a1f1f; }
.psc-maint-key { font-family:monospace; word-break:break-all; background:#f6f7f7; padding:8px; display:block; }
</style>

<section aria-labelledby="psc-m-1" data-testid="maintenance-sauvegarde">
<h2 id="psc-m-1"><?php esc_html_e('1. Sauvegarde', 'periscolaire-registration'); ?> <?php echo $psc_status('sauvegarde'); // phpcs:ignore WordPress.Security.EscapeOutput ?></h2>
<p><?php esc_html_e('Avant toute opération : sauvegardez la base de données (volume du conteneur MySQL ou outil de l’hébergeur), le dossier privé des documents et la clé de chiffrement si elle est déjà hors de la base. Vérifiez que la sauvegarde se restaure.', 'periscolaire-registration'); ?></p>
<p><?php esc_html_e('Dossier privé à sauvegarder :', 'periscolaire-registration'); ?> <code><?php echo esc_html(psc_private_dir()); ?></code></p>
<?php if ($psc_sauvegarde_ok): ?>
<p><?php echo esc_html(sprintf(__('Sauvegarde confirmée %s.', 'periscolaire-registration'), $psc_who($steps['sauvegarde']['stamp']))); ?></p>
<?php else: ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_config_maintenance_sauvegarde'); ?>
<input type="hidden" name="action" value="psc_config_maintenance_sauvegarde">
<p><label><input type="checkbox" name="sauvegarde_ok" value="1" required data-testid="maintenance-sauvegarde-check"> <?php esc_html_e('La base, le dossier privé et la clé sont sauvegardés, et la restauration a été vérifiée.', 'periscolaire-registration'); ?></label></p>
<?php submit_button(__('Confirmer la sauvegarde', 'periscolaire-registration'), 'primary', 'submit', false, array('data-testid' => 'maintenance-sauvegarde-submit')); ?>
</form>
<?php endif; ?>
</section>

<section aria-labelledby="psc-m-2" data-testid="maintenance-base">
<h2 id="psc-m-2"><?php esc_html_e('2. Base de données', 'periscolaire-registration'); ?> <?php echo $psc_status('base'); // phpcs:ignore WordPress.Security.EscapeOutput ?></h2>
<?php $psc_b = $steps['base']; ?>
<p>
<?php
printf(
    /* translators: 1: version du schéma en base, 2: version attendue */
    esc_html__('Schéma de la base : %1$s (attendu : %2$s).', 'periscolaire-registration'),
    '<code>' . esc_html($psc_b['version'] ?: '—') . '</code>',
    '<code>' . esc_html($psc_b['attendue']) . '</code>'
);
?>
</p>
<?php if ($psc_b['echec']): ?>
<p data-testid="maintenance-base-echec"><strong><?php esc_html_e('La mise à jour s’est arrêtée', 'periscolaire-registration'); ?></strong> —
<?php echo esc_html(sprintf(__('étape %1$s : %2$s.', 'periscolaire-registration'), $psc_b['echec']['etape'] ?? '', ($psc_b['echec']['requete'] ?? '') !== '' ? $psc_b['echec']['requete'] : __('erreur SQL', 'periscolaire-registration'))); ?>
<?php esc_html_e('Les étapes précédentes sont conservées ; rien n’est perdu.', 'periscolaire-registration'); ?></p>
<?php endif; ?>
<?php if ($psc_b['doublons']): ?>
<table class="widefat striped" data-testid="maintenance-doublons">
<caption><?php esc_html_e('Années scolaires en double : une seule par rentrée. Supprimez dans Année scolaire celle qui est en trop (ses inscriptions sont supprimées avec elle), puis relancez la mise à jour.', 'periscolaire-registration'); ?></caption>
<thead><tr><th scope="col"><?php esc_html_e('Année', 'periscolaire-registration'); ?></th><th scope="col"><?php esc_html_e('N°', 'periscolaire-registration'); ?></th><th scope="col"><?php esc_html_e('Dates', 'periscolaire-registration'); ?></th><th scope="col"><?php esc_html_e('Statut', 'periscolaire-registration'); ?></th><th scope="col"><?php esc_html_e('Inscriptions', 'periscolaire-registration'); ?></th></tr></thead>
<tbody>
<?php foreach ($psc_b['doublons'] as $psc_y): ?>
<tr><td><?php echo esc_html($psc_y->year_key); ?></td><td><?php echo (int) $psc_y->id; ?></td><td><?php echo esc_html(date_i18n('d/m/Y', strtotime($psc_y->date_debut)) . ' → ' . date_i18n('d/m/Y', strtotime($psc_y->date_fin))); ?></td><td><?php echo esc_html($psc_y->statut); ?></td><td><?php echo (int) $psc_y->inscriptions; ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=psc_school_calendar_v2&tab=historique')); ?>"><?php esc_html_e('Ouvrir Année scolaire', 'periscolaire-registration'); ?></a></p>
<?php endif; ?>
<?php if ($psc_b['stockage']): ?>
<p><?php esc_html_e('Des documents n’ont pas pu être déplacés vers le dossier privé : voir l’alerte en haut de page. Rien n’a été supprimé.', 'periscolaire-registration'); ?></p>
<?php endif; ?>
<?php if ($psc_b['status'] !== 'ok'): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_config_maintenance_relancer'); ?>
<input type="hidden" name="action" value="psc_config_maintenance_relancer">
<?php submit_button(__('Relancer la mise à jour de la base', 'periscolaire-registration'), 'secondary', 'submit', false, array('data-testid' => 'maintenance-relancer')); ?>
</form>
<?php endif; ?>
</section>

<section aria-labelledby="psc-m-3" data-testid="maintenance-cle">
<h2 id="psc-m-3"><?php esc_html_e('3. Clé de chiffrement des IBAN', 'periscolaire-registration'); ?> <?php echo $psc_status('cle'); // phpcs:ignore WordPress.Security.EscapeOutput ?></h2>
<?php $psc_c = $steps['cle']; $psc_r = $psc_c['rapport']; ?>
<p data-testid="maintenance-cle-source"><?php
$psc_sources = array(
    'constante'     => __('La clé est la constante PSC_ENCRYPTION_KEY de wp-config.php : elle est hors de la base.', 'periscolaire-registration'),
    'environnement' => __('La clé est la variable d’environnement PSC_ENCRYPTION_KEY du conteneur : elle est hors de la base.', 'periscolaire-registration'),
    'base'          => __('La clé est tirée de la base de données (option secret_key) : une copie de la base suffit à lire les IBAN.', 'periscolaire-registration'),
);
echo esc_html($psc_sources[$psc_c['source']] ?? $psc_c['source']);
?></p>
<table class="widefat striped" style="max-width:520px">
<caption><?php esc_html_e('IBAN chiffrés (familles, demandes, créancier)', 'periscolaire-registration'); ?></caption>
<tbody>
<tr><th scope="row"><?php esc_html_e('Avec la clé courante', 'periscolaire-registration'); ?></th><td data-testid="maintenance-cle-courant"><?php echo (int) $psc_r['courant']; ?></td></tr>
<tr><th scope="row"><?php esc_html_e('Avec une ancienne clé (à rechiffrer)', 'periscolaire-registration'); ?></th><td data-testid="maintenance-cle-ancienne"><?php echo (int) $psc_r['ancienne_cle']; ?></td></tr>
<tr><th scope="row"><?php esc_html_e('En clair (à chiffrer)', 'periscolaire-registration'); ?></th><td><?php echo (int) $psc_r['clair']; ?></td></tr>
<tr><th scope="row"><?php esc_html_e('Illisibles avec toutes les clés connues', 'periscolaire-registration'); ?></th><td><?php echo (int) $psc_r['illisible']; ?></td></tr>
</tbody>
</table>
<?php if ($psc_r['illisible']): ?>
<p><?php esc_html_e('Valeurs illisibles, laissées intactes (la famille devra ressaisir son IBAN) :', 'periscolaire-registration'); ?> <?php echo esc_html(implode(', ', $psc_r['illisibles'])); ?></p>
<?php endif; ?>

<?php if ($psc_c['source'] === 'base'): ?>
<h3><?php esc_html_e('Sortir la clé de la base', 'periscolaire-registration'); ?></h3>
<ol>
<li><?php esc_html_e('Générez une clé ci-dessous et rangez-la immédiatement dans un coffre de mots de passe : elle n’est affichée qu’une fois et n’est enregistrée nulle part.', 'periscolaire-registration'); ?></li>
<li><?php esc_html_e('Déclarez-la au conteneur WordPress (variable d’environnement), ou dans wp-config.php, puis redémarrez le conteneur.', 'periscolaire-registration'); ?></li>
<li><?php esc_html_e('Revenez sur cette page : l’étape indique alors la nouvelle clé, et le bouton « Rechiffrer » termine l’opération.', 'periscolaire-registration'); ?></li>
</ol>
<?php if ($generated_key !== ''): ?>
<div data-testid="maintenance-cle-generee">
<p><strong><?php esc_html_e('Clé générée — copiez-la maintenant, elle ne sera plus affichée :', 'periscolaire-registration'); ?></strong></p>
<p><?php esc_html_e('Conteneur (docker-compose.yml, service WordPress) :', 'periscolaire-registration'); ?></p>
<code class="psc-maint-key">environment:
  PSC_ENCRYPTION_KEY: "<?php echo esc_html($generated_key); ?>"</code>
<p><?php esc_html_e('Ou, dans wp-config.php :', 'periscolaire-registration'); ?></p>
<code class="psc-maint-key">define( 'PSC_ENCRYPTION_KEY', '<?php echo esc_html($generated_key); ?>' );</code>
</div>
<?php else: ?>
<form method="post" action="<?php echo esc_url(admin_url('admin.php?page=psc_maintenance')); ?>">
<?php wp_nonce_field('psc_config_maintenance_cle'); ?>
<?php submit_button(__('Générer une clé', 'periscolaire-registration'), 'secondary', 'psc_generer_cle', false, array('data-testid' => 'maintenance-generer-cle')); ?>
</form>
<?php endif; ?>
<?php elseif ($psc_r['ancienne_cle'] || $psc_r['clair']): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_config_maintenance_rechiffrer'); ?>
<input type="hidden" name="action" value="psc_config_maintenance_rechiffrer">
<?php if (!$psc_sauvegarde_ok): ?><p><?php esc_html_e('Confirmez d’abord la sauvegarde (étape 1).', 'periscolaire-registration'); ?></p><?php endif; ?>
<?php submit_button(__('Rechiffrer avec la nouvelle clé', 'periscolaire-registration'), 'primary', 'submit', false, array_merge(array('data-testid' => 'maintenance-rechiffrer'), $psc_sauvegarde_ok ? array() : array('disabled' => 'disabled'))); ?>
</form>
<?php endif; ?>
</section>

<section aria-labelledby="psc-m-4" data-testid="maintenance-reglages">
<h2 id="psc-m-4"><?php esc_html_e('4. Nouveaux réglages', 'periscolaire-registration'); ?> <?php echo $psc_status('reglages'); // phpcs:ignore WordPress.Security.EscapeOutput ?></h2>
<?php
$psc_k = $steps['reglages']['checks'];
$psc_rows = array(
    'confidentialite' => array(__('Responsable du traitement renseigné (Réglages › Confidentialité).', 'periscolaire-registration'), 'admin.php?page=psc_settings#psc-confidentialite'),
    'annee_active'    => array(__('Une année scolaire est active (Année scolaire).', 'periscolaire-registration'), 'admin.php?page=psc_school_calendar_v2&tab=historique'),
    'debug_inactif'   => array(__('Mode debug de suppression des factures désactivé.', 'periscolaire-registration'), ''),
    'modeles'         => array(__('Nouveaux modèles d’e-mails relus (dont « Réinscription retirée par la famille »).', 'periscolaire-registration'), 'admin.php?page=psc_email_templates'),
);
?>
<ul>
<?php foreach ($psc_rows as $psc_key => $psc_row): ?>
<li data-testid="maintenance-reglage-<?php echo esc_attr($psc_key); ?>">
<strong><?php echo $psc_k[$psc_key] ? esc_html__('Fait :', 'periscolaire-registration') : esc_html__('À faire :', 'periscolaire-registration'); ?></strong>
<?php echo esc_html($psc_row[0]); ?>
<?php if (!$psc_k[$psc_key] && $psc_row[1] !== ''): ?> <a href="<?php echo esc_url(admin_url($psc_row[1])); ?>"><?php esc_html_e('Ouvrir', 'periscolaire-registration'); ?></a><?php endif; ?>
<?php if ($psc_key === 'debug_inactif' && !$psc_k[$psc_key]): ?> <?php esc_html_e('Il se désactive par l’hébergeur, hors du backoffice : option psc_invoice_debug_delete à supprimer.', 'periscolaire-registration'); ?><?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php if (!$psc_k['modeles']): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_config_maintenance_modeles'); ?>
<input type="hidden" name="action" value="psc_config_maintenance_modeles">
<?php submit_button(__('J’ai relu les modèles d’e-mails', 'periscolaire-registration'), 'secondary', 'submit', false, array('data-testid' => 'maintenance-modeles')); ?>
</form>
<?php else: ?>
<p><?php echo esc_html(sprintf(__('Modèles relus %s.', 'periscolaire-registration'), $psc_who($steps['reglages']['modeles']))); ?></p>
<?php endif; ?>
</section>

<section aria-labelledby="psc-m-5" data-testid="maintenance-recette">
<h2 id="psc-m-5"><?php esc_html_e('5. Recette', 'periscolaire-registration'); ?> <?php echo $psc_status('recette'); // phpcs:ignore WordPress.Security.EscapeOutput ?></h2>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_config_maintenance_recette'); ?>
<input type="hidden" name="action" value="psc_config_maintenance_recette">
<fieldset>
<legend><?php esc_html_e('Cochez chaque point après l’avoir constaté vous-même, sur une famille de test.', 'periscolaire-registration'); ?></legend>
<?php foreach ($recette_items as $psc_key => $psc_label): $psc_done = $steps['recette']['faits'][$psc_key] ?? null; ?>
<p><label><input type="checkbox" name="recette[]" value="<?php echo esc_attr($psc_key); ?>" <?php checked((bool) $psc_done); ?> data-testid="maintenance-recette-<?php echo esc_attr($psc_key); ?>"> <?php echo esc_html($psc_label); ?></label>
<?php if ($psc_done): ?> <span class="description">— <?php echo esc_html($psc_who($psc_done)); ?></span><?php endif; ?></p>
<?php endforeach; ?>
</fieldset>
<?php submit_button(__('Enregistrer la recette', 'periscolaire-registration'), 'secondary', 'submit', false, array('data-testid' => 'maintenance-recette-submit')); ?>
</form>
</section>
</div>
