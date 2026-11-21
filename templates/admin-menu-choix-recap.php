<?php if (!defined('ABSPATH')) exit; ?>
<?php
/* Récapitulatif en lecture seule d'une semaine de choix (vérification
   avant envoi, puis suivi) : inclus en boucle par
   templates/admin-menu-choix-detail.php, qui fournit $psc_semaine (lundi),
   $psc_info (cf. Psc_Menu_Propositions::semaines()), $retenus, $etat_menus,
   $etape et $proposition. */
$psc_jours     = Psc_Menus::jour_labels();
$psc_cats      = Psc_Menu_Propositions::categories();
$psc_etat      = $etat_menus[$psc_semaine]['etat'] ?? 'sans_choix';
$psc_menu_url  = add_query_arg(array('page' => 'psc_menus', 'semaine_debut' => $psc_semaine), admin_url('admin.php'));
$psc_sem_label = date_i18n('d/m/Y', strtotime($psc_semaine));
?>
<section class="psc-choix-semaine" data-testid="choix-recap-<?php echo esc_attr($psc_semaine); ?>">
<h3><?php
/* translators: %s: lundi de la semaine (jj/mm/aaaa). */
echo esc_html(sprintf(__('Semaine du %s', 'periscolaire-registration'), $psc_sem_label));
?></h3>
<?php if (empty($psc_info['jours'])): ?>
<p><em><?php esc_html_e('Aucun jour de cantine cette semaine-là (vacances scolaires, jour férié ou fermeture) : rien n’est coché sur cette feuille.', 'periscolaire-registration'); ?></em></p>
<?php else: ?>
<table class="widefat striped psc-choix-table">
<caption class="screen-reader-text"><?php
/* translators: %s: lundi de la semaine (jj/mm/aaaa). */
echo esc_html(sprintf(__('Plats retenus pour la semaine du %s', 'periscolaire-registration'), $psc_sem_label));
?></caption>
<thead>
<tr>
    <th scope="col"><?php esc_html_e('Catégorie', 'periscolaire-registration'); ?></th>
    <?php foreach ($psc_info['jours'] as $psc_jour => $psc_day): ?>
    <th scope="col"><?php echo esc_html(($psc_jours[$psc_jour] ?? ucfirst($psc_jour)) . ' ' . date_i18n('d/m', strtotime($psc_day['date']))); ?></th>
    <?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php foreach ($psc_cats as $psc_cat => $psc_cat_label): ?>
<tr>
    <th scope="row"><?php echo esc_html($psc_cat_label); ?></th>
    <?php foreach ($psc_info['jours'] as $psc_jour => $psc_day): ?>
    <td data-label="<?php echo esc_attr(($psc_jours[$psc_jour] ?? ucfirst($psc_jour)) . ' ' . date_i18n('d/m', strtotime($psc_day['date']))); ?>" data-testid="choix-recap-<?php echo esc_attr($psc_semaine . '-' . $psc_jour . '-' . $psc_cat); ?>"><?php
        if (isset($retenus[$psc_semaine][$psc_jour][$psc_cat])) {
            echo esc_html($retenus[$psc_semaine][$psc_jour][$psc_cat]);
        } else {
            echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__('Aucun plat', 'periscolaire-registration') . '</span>';
        }
    ?></td>
    <?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p class="psc-choix-menu-etat" data-testid="choix-menu-etat-<?php echo esc_attr($psc_semaine); ?>" data-etat="<?php echo esc_attr($psc_etat); ?>">
<?php if ($etape === 'envoi'): ?>
    <?php
    $psc_avant = array(
        'absent'           => __('Après l’envoi, le menu de cette semaine sera créé.', 'periscolaire-registration'),
        'different'        => __('Un menu non envoyé existe déjà pour cette semaine : après l’envoi, il sera remplacé sur les jours choisis.', 'periscolaire-registration'),
        'a_jour'           => __('Le menu de cette semaine correspond déjà à ces choix.', 'periscolaire-registration'),
        'envoye_different' => __('Un menu a déjà été envoyé aux familles pour cette semaine : il ne sera pas modifié sans votre confirmation, que vous pourrez donner après l’envoi.', 'periscolaire-registration'),
        'sans_choix'       => __('Aucun plat retenu cette semaine : aucun menu ne sera rempli.', 'periscolaire-registration'),
    );
    echo esc_html($psc_avant[$psc_etat]);
    ?>
<?php else: ?>
    <?php
    $psc_apres = array(
        'absent'           => __('Menu non rempli.', 'periscolaire-registration'),
        'different'        => __('Le menu de cette semaine diffère de ces choix (modifié à la main, ou pas encore rempli).', 'periscolaire-registration'),
        'a_jour'           => __('Menu rempli avec ces choix.', 'periscolaire-registration'),
        'envoye_different' => __('Un menu avait déjà été envoyé aux familles pour cette semaine : il n’a pas été remplacé.', 'periscolaire-registration'),
        'sans_choix'       => __('Aucun plat retenu cette semaine.', 'periscolaire-registration'),
    );
    echo esc_html($psc_apres[$psc_etat]);
    ?>
    <?php if (in_array($psc_etat, array('a_jour', 'different', 'envoye_different'), true)): ?>
        <a href="<?php echo esc_url($psc_menu_url); ?>"><?php
        /* translators: %s: lundi de la semaine (jj/mm/aaaa). */
        echo esc_html(sprintf(__('Voir le menu du %s', 'periscolaire-registration'), $psc_sem_label));
        ?></a>
    <?php endif; ?>
<?php endif; ?>
</p>
<?php if ($etape === 'suivi' && $psc_etat === 'envoye_different'): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Remplacer ce menu, déjà envoyé aux familles, par les plats retenus ? Les familles ne sont pas prévenues : pensez à leur renvoyer le menu.', 'periscolaire-registration')); ?>');">
    <?php wp_nonce_field('psc_fill_menus_from_choix'); ?>
    <input type="hidden" name="action" value="psc_fill_menus_from_choix">
    <input type="hidden" name="id" value="<?php echo esc_attr($proposition->id); ?>">
    <input type="hidden" name="semaine" value="<?php echo esc_attr($psc_semaine); ?>">
    <button type="submit" class="button" data-testid="choix-remplacer-<?php echo esc_attr($psc_semaine); ?>"><?php
    /* translators: %s: lundi de la semaine (jj/mm/aaaa). */
    echo esc_html(sprintf(__('Remplacer le menu du %s', 'periscolaire-registration'), $psc_sem_label));
    ?></button>
</form>
<?php endif; ?>
<?php endif; ?>
</section>
