<?php if (!defined('ABSPATH')) exit; ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header class="site-header">
  <div class="site-header-inner">
    <div class="site-branding">
      <svg width="30" height="34" viewBox="0 0 40 46" fill="none" aria-hidden="true">
        <path d="M20 2 L36 8 L36 24 C36 34 28 40 20 44 C12 40 4 34 4 24 L4 8 Z" fill="#E2A72B" stroke="#1A1A1A" stroke-width="1"/>
        <ellipse cx="15" cy="19" rx="5.5" ry="3.2" fill="#1A1A1A"/>
        <ellipse cx="25" cy="26" rx="5.5" ry="3.2" fill="#1A1A1A"/>
      </svg>
      <div>
        <div class="site-tagline"><?php esc_html_e('Syndicat Intercommunal d\'Intérêt Scolaire de', 'montgeroult'); ?></div>
        <a class="site-title" href="<?php echo esc_url(home_url('/')); ?>"><?php bloginfo('name'); ?></a>
      </div>
    </div>
    <nav class="primary-nav" aria-label="<?php esc_attr_e('Menu principal', 'montgeroult'); ?>">
      <?php wp_nav_menu(array(
          'theme_location' => 'primary',
          'container'      => false,
          'fallback_cb'    => 'montgeroult_fallback_menu',
      )); ?>
    </nav>
  </div>
</header>
