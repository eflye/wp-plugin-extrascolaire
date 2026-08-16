<?php
if (!defined('ABSPATH')) exit;

function montgeroult_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', array('search-form', 'gallery', 'caption'));
    add_theme_support('automatic-feed-links');
    register_nav_menus(array(
        'primary' => __('Menu principal', 'montgeroult'),
    ));
}
add_action('after_setup_theme', 'montgeroult_setup');

if (!function_exists('montgeroult_fallback_menu')) {
    function montgeroult_fallback_menu() {
        echo '<ul><li><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Accueil', 'montgeroult') . '</a></li></ul>';
    }
}

function montgeroult_assets() {
    wp_enqueue_style(
        'montgeroult-fonts',
        'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;1,500;1,600&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700;9..144,800&family=Work+Sans:wght@400;500;600;700&display=swap',
        array(),
        null
    );
    wp_enqueue_style('montgeroult-style', get_stylesheet_uri(), array('montgeroult-fonts'), '1.0.0');

    // Restyle the "Périscolaire - Inscriptions" plugin (handle "psc-frontend")
    // with the commune's palette, without touching the plugin itself.
    // Only enqueued on pages where the plugin has actually registered its
    // own stylesheet (i.e. the page contains the [periscolaire_form] shortcode).
    if (wp_style_is('psc-frontend', 'registered')) {
        wp_enqueue_style(
            'montgeroult-psc-theme',
            get_template_directory_uri() . '/assets/css/psc-theme.css',
            array('psc-frontend'),
            '1.0.0'
        );
    }
}
add_action('wp_enqueue_scripts', 'montgeroult_assets', 20);
