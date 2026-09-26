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

/**
 * Pas d'émojis servis par WordPress.org : sur un navigateur qui ne les
 * affiche pas lui-même, le script de WordPress les remplace par des images
 * téléchargées depuis s.w.org, qui reçoit alors l'adresse IP du visiteur.
 * Les émojis restent affichés par le navigateur quand il le sait.
 */
function montgeroult_disable_emoji() {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('admin_print_styles', 'print_emoji_styles');
    add_filter('emoji_svg_url', '__return_false');
}
add_action('init', 'montgeroult_disable_emoji');

if (!function_exists('montgeroult_fallback_menu')) {
    function montgeroult_fallback_menu() {
        echo '<ul><li><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Accueil', 'montgeroult') . '</a></li></ul>';
    }
}

function montgeroult_assets() {
    // Police servie par le thème : aucune requête vers un service tiers
    // (Google Fonts recevait l'adresse IP de chaque visiteur).
    wp_enqueue_style(
        'montgeroult-fonts',
        get_template_directory_uri() . '/assets/css/fonts.css',
        array(),
        '1.1.0'
    );
    wp_enqueue_style('montgeroult-style', get_stylesheet_uri(), array('montgeroult-fonts'), '1.1.0');

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
