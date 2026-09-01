<?php
/**
 * Template Name: Espace Familles
 * Sélectionnez ce modèle sur la page qui contient le shortcode [periscolaire_form]
 * pour afficher le bandeau "Espace familles" au-dessus du formulaire.
 */
if (!defined('ABSPATH')) exit;
get_header();
?>
<main class="site-main">
  <?php
  // Vue visiteur uniquement : les familles connectées ne sont pas des
  // utilisateurs WordPress (session propre au plugin), la condition est
  // donc Psc_Parents::current() et non is_user_logged_in(). Une fois
  // connectée, le portail prend toute la page et la bascule « Espaces »
  // est rendue par le plugin, en haut à droite de la colonne de contenu.
  $psc_famille_connectee = class_exists('Psc_Parents') && Psc_Parents::current();
  ?>
  <?php if (!$psc_famille_connectee) : ?>
  <div class="familles-masthead">
    <div class="familles-brand"><?php esc_html_e('Service périscolaire', 'montgeroult'); ?></div>
    <div class="familles-nav">
      <a href="<?php echo esc_url(get_permalink(get_queried_object_id())); ?>" class="familles-eyebrow"><?php esc_html_e('Espace familles', 'montgeroult'); ?></a>
      <?php $psc_intervenants_id = class_exists('Psc_Sidscm') ? Psc_Sidscm::page_id() : 0; ?>
      <?php if ($psc_intervenants_id): ?>
      <a href="<?php echo esc_url(get_permalink($psc_intervenants_id)); ?>" class="familles-eyebrow"><?php esc_html_e('Espace intervenants', 'montgeroult'); ?></a>
      <?php endif; ?>
    </div>
  </div>
  <div class="familles-hairline"><span class="line"></span><span class="dot"></span><span class="line"></span></div>
  <?php endif; ?>

  <?php while (have_posts()) : the_post(); ?>
    <div class="entry-content"><?php the_content(); ?></div>
  <?php endwhile; ?>
</main>
<?php get_footer(); ?>
