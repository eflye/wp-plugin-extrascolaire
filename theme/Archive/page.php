<?php if (!defined('ABSPATH')) exit; get_header(); ?>
<main class="site-main">
<?php while (have_posts()) : the_post(); ?>
  <article <?php post_class(); ?>>
    <h1 class="page-title"><?php the_title(); ?></h1>
    <div class="entry-content"><?php the_content(); ?></div>
  </article>
<?php endwhile; ?>
</main>
<?php get_footer(); ?>
