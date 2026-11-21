<?php if (!defined('ABSPATH')) exit; ?>
<?php
/* Rendu comme onglet « Notes du fournisseur » de la page « Menus »
   (cf. Psc_Admin_Menu_Notes::render_tab()) : ni wrap ni <h1> ici.
   Une note reçue : son texte, le jour concerné, puis le menu de ce jour
   à corriger, prérempli des remplacements reconnus (statut a_confirmer),
   ou les lignes qui ont été modifiées. */
psc_admin_notice_map($psc_notices, $psc_msg, $psc_msg);

$psc_statuts     = Psc_Menu_Notes::statuts();
$psc_list_url    = add_query_arg(array('page' => 'psc_menus', 'tab' => 'notes'), admin_url('admin.php'));
$psc_post_url    = admin_url('admin-post.php');
$psc_a_confirmer = $note->statut === Psc_Menu_Notes::A_CONFIRMER;
$psc_date_label  = $ctx['date'] !== '' ? date_i18n('l j F Y', strtotime($ctx['date'])) : '';
$psc_menu_url    = $ctx['semaine'] !== '' ? add_query_arg(array('page' => 'psc_menus', 'semaine_debut' => $ctx['semaine']), admin_url('admin.php')) : '';
// Le jour nommé dans la note (« prévus lundi ») contredit-il la date retenue ?
$psc_jours_fr    = array(1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche');
$psc_jour_date   = $ctx['date'] !== '' ? $psc_jours_fr[(int) gmdate('N', strtotime($ctx['date']))] : '';
$psc_contredit   = $changements['jour_cite'] !== '' && $psc_jour_date !== '' && $changements['jour_cite'] !== $psc_jour_date;
?>

<p><a href="<?php echo esc_url($psc_list_url); ?>">&larr; <?php esc_html_e('Toutes les notes reçues', 'periscolaire-registration'); ?></a></p>

<div class="psc-box" data-testid="note-detail" data-statut="<?php echo esc_attr($note->statut); ?>">
<h2><?php echo esc_html($note->fichier_nom); ?></h2>
<p>
    <strong><?php esc_html_e('État :', 'periscolaire-registration'); ?></strong>
    <span data-testid="note-statut"><?php echo esc_html($psc_statuts[$note->statut] ?? $note->statut); ?></span>
    <?php if ($note->applied_at): ?>
        <?php
        /* translators: %s: date et heure de l'application de la note au menu. */
        echo esc_html(sprintf(__('— le %s', 'periscolaire-registration'), date_i18n('d/m/Y H:i', strtotime($note->applied_at))));
        ?>
    <?php endif; ?>
</p>

<h3><?php esc_html_e('Texte de la note', 'periscolaire-registration'); ?></h3>
<div class="psc-note-texte" data-testid="note-texte">
<?php
$psc_in_list = false;
foreach (preg_split('/\r\n|\r|\n/', (string) $note->texte) as $psc_line) {
    $psc_puce = strpos($psc_line, Psc_Menu_Notes::PUCE) === 0;
    if ($psc_puce && !$psc_in_list) { echo '<ul>'; $psc_in_list = true; }
    if (!$psc_puce && $psc_in_list) { echo '</ul>'; $psc_in_list = false; }
    if ($psc_puce) {
        echo '<li>' . esc_html(substr($psc_line, strlen(Psc_Menu_Notes::PUCE))) . '</li>';
    } elseif (trim($psc_line) !== '') {
        echo '<p>' . esc_html($psc_line) . '</p>';
    }
}
if ($psc_in_list) echo '</ul>';
?>
</div>

<h3><?php esc_html_e('Jour de repas concerné', 'periscolaire-registration'); ?></h3>
<?php if ($psc_date_label !== ''): ?>
<p data-testid="note-jour"><strong><?php echo esc_html(ucfirst($psc_date_label)); ?></strong><?php if ($psc_a_confirmer): ?> — <?php esc_html_e('d’après la date du nom du fichier, sauf si vous l’avez modifiée.', 'periscolaire-registration'); ?><?php endif; ?></p>
<?php else: ?>
<p class="psc-choix-alerte" data-testid="note-jour-absent"><strong><?php esc_html_e('Le nom du fichier ne porte pas de date lisible : indiquez le jour de repas concerné.', 'periscolaire-registration'); ?></strong></p>
<?php endif; ?>
<?php if ($psc_contredit && $psc_a_confirmer): ?>
<div class="notice notice-warning inline" data-testid="note-jour-contredit"><p><?php
/* translators: 1: jour de la semaine nommé dans la note, 2: jour de la semaine de la date retenue. */
echo esc_html(sprintf(__('La note parle de « %1$s », mais la date retenue est un %2$s. Vérifiez le jour avant d’appliquer.', 'periscolaire-registration'), $changements['jour_cite'], $psc_jour_date));
?></p></div>
<?php endif; ?>

<?php if ($psc_a_confirmer): ?>
<form method="post" action="<?php echo esc_url($psc_post_url); ?>" class="psc-note-jour-form">
    <?php wp_nonce_field('psc_set_menu_note_day'); ?>
    <input type="hidden" name="action" value="psc_set_menu_note_day">
    <input type="hidden" name="id" value="<?php echo esc_attr($note->id); ?>">
    <label for="psc-note-jour-date"><?php esc_html_e('Autre jour', 'periscolaire-registration'); ?></label>
    <input id="psc-note-jour-date" type="date" name="jour_date" value="<?php echo esc_attr($ctx['date']); ?>" required>
    <button type="submit" class="button" data-testid="note-jour-changer"><?php esc_html_e('Changer le jour', 'periscolaire-registration'); ?></button>
</form>

<h3><?php esc_html_e('Menu du jour à corriger', 'periscolaire-registration'); ?></h3>
<?php if ($ctx['date'] === ''): ?>
    <p><?php esc_html_e('Indiquez d’abord le jour concerné : le menu de ce jour s’affichera ici, prêt à être corrigé.', 'periscolaire-registration'); ?></p>
<?php elseif ($ctx['jour'] === '' || !$ctx['ouvert']): ?>
    <p class="psc-choix-alerte" data-testid="note-jour-ferme"><strong><?php esc_html_e('Ce jour n’est pas un jour de cantine (mercredi, week-end, vacances ou fermeture) : il n’y a pas de menu à corriger. Vérifiez le jour.', 'periscolaire-registration'); ?></strong></p>
<?php elseif (!$ctx['menu'] || !$ctx['lignes']): ?>
    <p class="psc-choix-alerte" data-testid="note-sans-menu"><strong><?php esc_html_e('Aucun menu n’est enregistré pour ce jour : il n’y a rien à corriger.', 'periscolaire-registration'); ?></strong>
    <a href="<?php echo esc_url($psc_menu_url); ?>"><?php esc_html_e('Saisir le menu de cette semaine', 'periscolaire-registration'); ?></a></p>
<?php else: ?>
    <?php if (!empty($ctx['menu']->sent_at)): ?>
    <div class="notice notice-info inline" data-testid="note-menu-envoye"><p><?php esc_html_e('Ce menu a déjà été envoyé aux familles. Il sera corrigé dans le backoffice et dans leur espace, sans nouvel e-mail : utilisez ensuite « Renvoyer » dans l’onglet « Menus de la semaine » si vous souhaitez les prévenir.', 'periscolaire-registration'); ?></p></div>
    <?php endif; ?>

    <?php if (!$propositions): ?>
    <p data-testid="note-aucun-remplacement"><?php esc_html_e('Aucun remplacement n’a été reconnu automatiquement dans cette note. Le menu corrigé ci-dessous reprend donc le menu actuel tel quel : modifiez-le à la main en lisant la note.', 'periscolaire-registration'); ?></p>
    <?php else: ?>
    <p><?php esc_html_e('Ce que la note annonce, et ce qui en a été reporté dans le menu corrigé ci-dessous :', 'periscolaire-registration'); ?></p>
    <ul class="psc-note-remplacements" data-testid="note-remplacements">
    <?php foreach ($propositions as $psc_i => $psc_p): ?>
        <?php $psc_trouve = (int) $psc_p['ligne'] >= 0 && isset($ctx['lignes'][(int) $psc_p['ligne']]); ?>
        <li data-testid="note-remplacement-<?php echo esc_attr($psc_i); ?>" data-trouve="<?php echo $psc_trouve ? '1' : '0'; ?>">
            <strong><?php
            /* translators: 1: produit remplacé, 2: produit de remplacement. */
            echo esc_html(sprintf(__('« %1$s » remplacé par « %2$s »', 'periscolaire-registration'), $psc_p['produit'], $psc_p['nouveau']));
            ?></strong> —
            <?php if ($psc_trouve): ?>
                <?php
                /* translators: 1: ligne actuelle du menu, 2: ligne proposée à la place. */
                echo esc_html(sprintf(__('la ligne « %1$s » devient « %2$s ».', 'periscolaire-registration'), $ctx['lignes'][(int) $psc_p['ligne']], $psc_p['texte']));
                ?>
            <?php else: ?>
                <strong class="psc-choix-alerte"><?php esc_html_e('produit non retrouvé dans le menu de ce jour : rien n’a été reporté, corrigez la ligne à la main s’il y a lieu.', 'periscolaire-registration'); ?></strong>
            <?php endif; ?>
            <?php if (!empty($psc_p['remarque'])): ?>
            <span class="description"><?php
            /* translators: %s: remarque entre parenthèses dans la note du fournisseur. */
            echo esc_html(sprintf(__('Remarque du fournisseur : %s', 'periscolaire-registration'), $psc_p['remarque']));
            ?></span>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url($psc_post_url); ?>" data-testid="note-form">
    <?php wp_nonce_field('psc_apply_menu_note'); ?>
    <input type="hidden" name="action" value="psc_apply_menu_note">
    <input type="hidden" name="id" value="<?php echo esc_attr($note->id); ?>">
    <div class="psc-note-menus">
        <div>
            <h4 id="psc-note-menu-actuel-titre"><?php
            /* translators: %s: jour concerné, par exemple « lundi 9 novembre 2026 ». */
            echo esc_html(sprintf(__('Menu actuel du %s', 'periscolaire-registration'), $psc_date_label));
            ?></h4>
            <ul aria-labelledby="psc-note-menu-actuel-titre" data-testid="note-menu-actuel">
            <?php foreach ($ctx['lignes'] as $psc_ligne): ?>
                <li><?php echo esc_html($psc_ligne); ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
        <div>
            <h4 id="psc-note-menu-corrige-titre"><?php esc_html_e('Menu corrigé', 'periscolaire-registration'); ?></h4>
            <div role="group" aria-labelledby="psc-note-menu-corrige-titre" aria-describedby="psc-note-menu-aide" data-testid="note-menu-corrige">
            <?php echo Psc_Menus::render_type_fields('menu_jour', 'psc-note-menu-jour', Psc_Menus::par_type(implode("\n", $menu_propose), isset($ctx['menu']->{$ctx['jour'] . '_types'}) ? $ctx['menu']->{$ctx['jour'] . '_types'} : '')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
            <p class="description" id="psc-note-menu-aide"><?php esc_html_e('Un plat par ligne, chacun dans son type. Relisez et corrigez librement : ce sont ces champs qui remplaceront le menu de ce jour. Terminez une ligne par une étoile (*) pour un produit bio.', 'periscolaire-registration'); ?></p>
        </div>
    </div>
    <div class="psc-choix-actions">
        <button type="submit" class="button button-primary" data-testid="note-appliquer"><?php esc_html_e('Enregistrer le menu corrigé', 'periscolaire-registration'); ?></button>
        <span class="description"><?php esc_html_e('Seul le menu de ce jour est modifié. Aucun e-mail n’est envoyé.', 'periscolaire-registration'); ?></span>
    </div>
    </form>
<?php endif; ?>

<form method="post" action="<?php echo esc_url($psc_post_url); ?>" class="psc-note-ignorer">
    <?php wp_nonce_field('psc_ignore_menu_note'); ?>
    <input type="hidden" name="action" value="psc_ignore_menu_note">
    <input type="hidden" name="id" value="<?php echo esc_attr($note->id); ?>">
    <button type="submit" class="button" data-testid="note-ignorer"><?php esc_html_e('Classer la note sans modifier le menu', 'periscolaire-registration'); ?></button>
</form>

<?php elseif ($note->statut === Psc_Menu_Notes::APPLIQUEE): ?>

<h3><?php esc_html_e('Lignes du menu modifiées', 'periscolaire-registration'); ?></h3>
<table class="widefat striped" data-testid="note-appliques">
<caption class="screen-reader-text"><?php esc_html_e('Lignes du menu modifiées à la suite de cette note', 'periscolaire-registration'); ?></caption>
<thead>
<tr>
    <th scope="col"><?php esc_html_e('Ligne avant', 'periscolaire-registration'); ?></th>
    <th scope="col"><?php esc_html_e('Ligne après', 'periscolaire-registration'); ?></th>
</tr>
</thead>
<tbody>
<?php foreach ($changements['appliques'] as $psc_a): ?>
<tr>
    <td><?php
        if (($psc_a['avant'] ?? '') !== '') echo esc_html($psc_a['avant']);
        else echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__('Ligne ajoutée', 'periscolaire-registration') . '</span>';
    ?></td>
    <td><?php
        if (($psc_a['apres'] ?? '') !== '') echo esc_html($psc_a['apres']);
        else echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__('Ligne retirée', 'periscolaire-registration') . '</span>';
    ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p>
    <?php if ($changements['menu_envoye']): ?>
        <?php esc_html_e('Ce menu avait déjà été envoyé aux familles ; aucun e-mail n’est reparti.', 'periscolaire-registration'); ?>
    <?php endif; ?>
    <?php if ($psc_menu_url !== ''): ?>
    <a href="<?php echo esc_url($psc_menu_url); ?>" data-testid="note-voir-menu"><?php esc_html_e('Voir le menu de cette semaine (pour le relire ou le renvoyer aux familles)', 'periscolaire-registration'); ?></a>
    <?php endif; ?>
</p>

<?php else: ?>
<p><?php esc_html_e('Cette note a été classée sans modifier le menu.', 'periscolaire-registration'); ?></p>
<?php endif; ?>
</div>
