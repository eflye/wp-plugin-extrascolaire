<?php if (!defined('ABSPATH')) exit; ?>
<?php
/* Rendu comme onglet « Choix fournisseur » de la page « Menus »
   (cf. Psc_Admin_Menu_Propositions::render_tab()) : ni wrap ni <h1> ici.
   Un fichier reçu, selon $etape : 'choix' (formulaire), 'envoi'
   (vérification avant envoi) ou 'suivi' (réponse partie). */
psc_admin_notice_map($psc_notices, $psc_msg, $psc_msg);

$psc_jours    = Psc_Menus::jour_labels();
$psc_cats     = Psc_Menu_Propositions::categories();
$psc_statuts  = Psc_Menu_Propositions::statuts();
$psc_list_url = add_query_arg(array('page' => 'psc_menus', 'tab' => 'choix'), admin_url('admin.php'));
$psc_self_url = add_query_arg(array('proposition' => (int) $proposition->id), $psc_list_url);
$psc_post_url = admin_url('admin-post.php');
$psc_echec    = $bilan && $bilan[Psc_Envois::ECHEC] > 0;
$psc_jours_choisis = 0;
foreach ($retenus as $psc_days) $psc_jours_choisis += count($psc_days);
$psc_groupes = array(
    'v' => __('végétarien', 'periscolaire-registration'),
    'o' => __('option', 'periscolaire-registration'),
);
?>

<p><a href="<?php echo esc_url($psc_list_url); ?>">&larr; <?php esc_html_e('Tous les fichiers reçus', 'periscolaire-registration'); ?></a></p>

<div class="psc-box psc-box-wide" data-testid="choix-detail" data-etape="<?php echo esc_attr($etape); ?>">
<h2><?php echo esc_html($proposition->fichier_nom); ?></h2>
<p>
    <strong><?php esc_html_e('Période :', 'periscolaire-registration'); ?></strong> <?php echo esc_html(Psc_Menu_Propositions::periode_label($proposition)); ?>
    &nbsp;·&nbsp;
    <strong><?php esc_html_e('État :', 'periscolaire-registration'); ?></strong>
    <span data-testid="choix-statut"><?php echo esc_html($psc_statuts[$proposition->statut] ?? $proposition->statut); ?></span>
    <?php if ($proposition->sent_at): ?>
        <?php
        /* translators: 1: adresse du fournisseur, 2: date et heure de l'envoi. */
        echo esc_html(sprintf(__('— à %1$s, le %2$s', 'periscolaire-registration'), (string) $proposition->supplier_email, date_i18n('d/m/Y H:i', strtotime($proposition->sent_at))));
        ?>
    <?php endif; ?>
</p>

<?php if ($ignorees): ?>
<div class="notice notice-warning inline" data-testid="choix-feuilles-ignorees"><p>
    <?php esc_html_e('Certaines feuilles du fichier n’ont pas été comprises et sont laissées telles quelles :', 'periscolaire-registration'); ?>
    <?php
    $psc_ignorees = array();
    foreach ($ignorees as $psc_ignoree) {
        $psc_ignorees[] = ($psc_ignoree['feuille'] ?? '') . ' (' . Psc_Admin_Menu_Propositions::motif_label($psc_ignoree['motif'] ?? '') . ')';
    }
    echo esc_html(implode(' ; ', $psc_ignorees)) . '.';
    ?>
</p></div>
<?php endif; ?>

<?php if ($psc_echec && $etape !== 'suivi'): ?>
<div class="notice notice-error inline" data-testid="choix-envoi-echec"><p>
    <?php esc_html_e('Le dernier envoi au fournisseur a échoué : la réponse n’est pas partie et les menus ne sont pas remplis.', 'periscolaire-registration'); ?>
</p>
<form method="post" action="<?php echo esc_url($psc_post_url); ?>" style="margin:0 0 10px;">
    <?php wp_nonce_field('psc_retry_menu_choix'); ?>
    <input type="hidden" name="action" value="psc_retry_menu_choix">
    <input type="hidden" name="id" value="<?php echo esc_attr($proposition->id); ?>">
    <button type="submit" class="button" data-testid="choix-relancer"><?php esc_html_e('Relancer l’envoi', 'periscolaire-registration'); ?></button>
</form>
</div>
<?php endif; ?>

<?php if ($etape === 'choix'): ?>

<p><?php esc_html_e('Retenez un plat par catégorie pour chaque jour de cantine. La suggestion de la diététicienne est cochée d’avance ; « Aucun » ne commande rien dans la catégorie (par exemple l’accompagnement d’un plat complet). Les mercredis et les jours fermés au calendrier scolaire ne sont pas proposés.', 'periscolaire-registration'); ?></p>

<nav aria-label="<?php esc_attr_e('Semaines du fichier', 'periscolaire-registration'); ?>">
<ul class="psc-choix-nav">
<?php foreach ($semaines as $psc_semaine => $psc_info): ?>
    <li><a href="#psc-choix-<?php echo esc_attr($psc_semaine); ?>"><span class="screen-reader-text"><?php esc_html_e('Semaine du', 'periscolaire-registration'); ?> </span><?php
    echo esc_html(date_i18n('d/m', strtotime($psc_semaine)));
    if (empty($psc_info['jours'])) echo ' <span class="psc-choix-tag">' . esc_html__('(fermée)', 'periscolaire-registration') . '</span>';
    ?></a></li>
<?php endforeach; ?>
</ul>
</nav>

<form method="post" action="<?php echo esc_url($psc_post_url); ?>" data-testid="choix-form">
<?php wp_nonce_field('psc_save_menu_choix'); ?>
<input type="hidden" name="action" value="psc_save_menu_choix">
<input type="hidden" name="id" value="<?php echo esc_attr($proposition->id); ?>">

<?php foreach ($semaines as $psc_semaine => $psc_info): ?>
<?php $psc_sem_label = date_i18n('d/m/Y', strtotime($psc_semaine)); ?>
<section class="psc-choix-semaine" id="psc-choix-<?php echo esc_attr($psc_semaine); ?>" data-testid="choix-semaine-<?php echo esc_attr($psc_semaine); ?>">
<h3><?php
/* translators: %s: lundi de la semaine (jj/mm/aaaa). */
echo esc_html(sprintf(__('Semaine du %s', 'periscolaire-registration'), $psc_sem_label));
?></h3>
<?php if (empty($psc_info['jours'])): ?>
<p><em><?php esc_html_e('Aucun jour de cantine cette semaine-là (vacances scolaires, jour férié ou fermeture) : aucun choix à faire.', 'periscolaire-registration'); ?></em></p>
<?php else: ?>
<table class="widefat striped psc-choix-table">
<caption class="screen-reader-text"><?php
/* translators: %s: lundi de la semaine (jj/mm/aaaa). */
echo esc_html(sprintf(__('Choix des plats pour la semaine du %s', 'periscolaire-registration'), $psc_sem_label));
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
    <?php
    $psc_plats   = isset($psc_day['plats'][$psc_cat]) ? (array) $psc_day['plats'][$psc_cat] : array();
    $psc_current = (int) ($choix[$psc_semaine][$psc_jour][$psc_cat] ?? 0);
    $psc_name    = 'choix[' . $psc_semaine . '][' . $psc_jour . '][' . $psc_cat . ']';
    $psc_legend  = ($psc_jours[$psc_jour] ?? ucfirst($psc_jour)) . ' ' . date_i18n('d/m', strtotime($psc_day['date'])) . ' — ' . $psc_cat_label;
    ?>
    <td>
        <?php if (!$psc_plats): ?>
            <span aria-hidden="true">—</span><span class="screen-reader-text"><?php esc_html_e('Aucune proposition', 'periscolaire-registration'); ?></span>
        <?php else: ?>
        <fieldset class="psc-choix-group" data-testid="choix-<?php echo esc_attr($psc_semaine . '-' . $psc_jour . '-' . $psc_cat); ?>">
            <legend class="psc-choix-legend"><?php echo esc_html($psc_legend); ?></legend>
            <?php $psc_prev = 'p'; $psc_first = true; ?>
            <?php foreach ($psc_plats as $psc_plat): ?>
            <?php
            $psc_g   = (string) ($psc_plat['g'] ?? 'p');
            $psc_sep = $psc_g !== 'p' && $psc_prev === 'p';
            $psc_prev = $psc_g;
            ?>
            <label class="psc-choix-option<?php echo $psc_sep ? ' psc-choix-sep' : ''; ?>">
                <input type="radio" name="<?php echo esc_attr($psc_name); ?>" value="<?php echo esc_attr((int) $psc_plat['r']); ?>" <?php checked($psc_current, (int) $psc_plat['r']); ?>>
                <span><?php echo esc_html($psc_plat['t']); ?></span>
                <?php if ($psc_g === 'p' && $psc_first): ?>
                    <span class="psc-choix-tag">(<?php esc_html_e('suggestion', 'periscolaire-registration'); ?>)</span>
                <?php elseif (isset($psc_groupes[$psc_g])): ?>
                    <span class="psc-choix-tag">(<?php echo esc_html($psc_groupes[$psc_g]); ?>)</span>
                <?php endif; ?>
            </label>
            <?php if ($psc_g === 'p') $psc_first = false; ?>
            <?php endforeach; ?>
            <label class="psc-choix-option psc-choix-sep">
                <input type="radio" name="<?php echo esc_attr($psc_name); ?>" value="0" <?php checked($psc_current, 0); ?>>
                <em><?php esc_html_e('Aucun', 'periscolaire-registration'); ?></em>
            </label>
        </fieldset>
        <?php endif; ?>
    </td>
    <?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</section>
<?php endforeach; ?>

<div class="psc-choix-actions">
    <button type="submit" name="suite" value="brouillon" class="button" data-testid="choix-enregistrer"><?php esc_html_e('Enregistrer les choix', 'periscolaire-registration'); ?></button>
    <button type="submit" name="suite" value="envoi" class="button button-primary" data-testid="choix-verifier"><?php esc_html_e('Enregistrer et vérifier avant l’envoi', 'periscolaire-registration'); ?></button>
    <span class="description"><?php esc_html_e('Rien ne part au fournisseur sans votre confirmation à l’étape suivante.', 'periscolaire-registration'); ?></span>
</div>
</form>

<?php elseif ($etape === 'envoi'): ?>

<h3><?php esc_html_e('Vérifier avant l’envoi', 'periscolaire-registration'); ?></h3>
<ul class="psc-choix-resume" data-testid="choix-resume">
    <li><strong><?php esc_html_e('Destinataire :', 'periscolaire-registration'); ?></strong>
        <?php if (is_email($supplier_email)): ?>
            <span data-testid="choix-destinataire"><?php echo esc_html($supplier_email); ?></span>
        <?php else: ?>
            <strong class="psc-choix-alerte" data-testid="choix-destinataire-absent"><?php echo esc_html($psc_notices['psc_no_supplier_email'][1]); ?></strong>
        <?php endif; ?>
    </li>
    <li><strong><?php esc_html_e('Établissement inscrit sur le fichier :', 'periscolaire-registration'); ?></strong>
        <?php echo Psc_Menu_Propositions::etablissement() !== '' ? esc_html(Psc_Menu_Propositions::etablissement()) : esc_html__('aucun (réglage vide)', 'periscolaire-registration'); ?>
    </li>
    <li><strong><?php esc_html_e('Pièce jointe :', 'periscolaire-registration'); ?></strong>
        <?php echo esc_html(Psc_Menu_Propositions::response_name($proposition)); ?> —
        <span data-testid="choix-total"><?php
        echo esc_html(sprintf(
            /* translators: 1: nombre de croix posées dans le fichier, 2: nombre de jours de cantine concernés. */
            __('%1$d plats cochés sur %2$d jours de cantine', 'periscolaire-registration'),
            (int) $marks['croix'], (int) $psc_jours_choisis
        ));
        ?></span>
    </li>
</ul>

<form method="post" action="<?php echo esc_url($psc_post_url); ?>">
    <?php wp_nonce_field('psc_download_menu_choix'); ?>
    <input type="hidden" name="action" value="psc_download_menu_choix">
    <input type="hidden" name="id" value="<?php echo esc_attr($proposition->id); ?>">
    <button type="submit" class="button" data-testid="choix-telecharger"><?php esc_html_e('Télécharger le fichier rempli pour le relire', 'periscolaire-registration'); ?></button>
</form>

<?php foreach ($semaines as $psc_semaine => $psc_info) include PSC_PATH . 'templates/admin-menu-choix-recap.php'; ?>

<h3><?php esc_html_e('E-mail qui sera envoyé', 'periscolaire-registration'); ?></h3>
<p><strong><?php esc_html_e('Sujet :', 'periscolaire-registration'); ?></strong> <span data-testid="choix-sujet"><?php echo esc_html($email_preview ? $email_preview['subject'] : ''); ?></span></p>
<iframe title="<?php esc_attr_e('Aperçu de l’e-mail qui sera envoyé au fournisseur', 'periscolaire-registration'); ?>"
        data-testid="choix-apercu"
        srcdoc="<?php echo esc_attr($email_preview ? $email_preview['html'] : ''); ?>"
        style="width:100%;max-width:700px;height:360px;border:1px solid #dcdcde;background:#fff;"></iframe>

<div class="psc-choix-actions">
    <a class="button" href="<?php echo esc_url($psc_self_url); ?>" data-testid="choix-modifier"><?php esc_html_e('Modifier les choix', 'periscolaire-registration'); ?></a>
    <form method="post" action="<?php echo esc_url($psc_post_url); ?>" style="margin:0;">
        <?php wp_nonce_field('psc_send_menu_choix'); ?>
        <input type="hidden" name="action" value="psc_send_menu_choix">
        <input type="hidden" name="id" value="<?php echo esc_attr($proposition->id); ?>">
        <input type="hidden" name="lot" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
        <button type="submit" class="button button-primary" data-testid="choix-confirmer"><?php esc_html_e('Envoyer la réponse au fournisseur', 'periscolaire-registration'); ?></button>
    </form>
    <span class="description"><?php esc_html_e('Les menus des semaines choisies seront remplis dès que la réponse sera partie.', 'periscolaire-registration'); ?></span>
</div>

<?php else: ?>

<div class="psc-choix-actions psc-choix-actions-top">
    <form method="post" action="<?php echo esc_url($psc_post_url); ?>" style="margin:0;">
        <?php wp_nonce_field('psc_download_menu_choix'); ?>
        <input type="hidden" name="action" value="psc_download_menu_choix">
        <input type="hidden" name="id" value="<?php echo esc_attr($proposition->id); ?>">
        <button type="submit" class="button" data-testid="choix-telecharger"><?php esc_html_e('Télécharger le fichier envoyé', 'periscolaire-registration'); ?></button>
    </form>
    <?php
    $psc_a_remplir = false;
    foreach ($etat_menus as $psc_e) {
        if (in_array($psc_e['etat'], array('absent', 'different'), true)) $psc_a_remplir = true;
    }
    ?>
    <?php if ($psc_a_remplir): ?>
    <form method="post" action="<?php echo esc_url($psc_post_url); ?>" style="margin:0;">
        <?php wp_nonce_field('psc_fill_menus_from_choix'); ?>
        <input type="hidden" name="action" value="psc_fill_menus_from_choix">
        <input type="hidden" name="id" value="<?php echo esc_attr($proposition->id); ?>">
        <button type="submit" class="button" data-testid="choix-remplir"><?php esc_html_e('Remplir les menus avec ces choix', 'periscolaire-registration'); ?></button>
    </form>
    <?php endif; ?>
</div>
<p><?php esc_html_e('Les menus remplis restent des brouillons : leur envoi aux familles se fait depuis l’onglet « Menus de la semaine ».', 'periscolaire-registration'); ?></p>

<?php foreach ($semaines as $psc_semaine => $psc_info) include PSC_PATH . 'templates/admin-menu-choix-recap.php'; ?>

<?php endif; ?>
</div>
