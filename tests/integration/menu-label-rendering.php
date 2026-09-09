<?php
// WP-CLI uniquement, aucun e-mail envoyé ni menu enregistré.
if (!defined('WP_CLI') || !WP_CLI) return;
$raw = "Carottes râpées*\nSauté de dinde fermière**\nSa*lade\n<script>test</script>";
$days = array(array('day'=>'Lundi', 'dish'=>$raw));
foreach (array('frontend', 'preview', 'email') as $context) {
    $html = Psc_Menus::render_dishes($raw, $context);
    if (strpos($html, '*') !== false || strpos($html, '<script>') !== false || substr_count($html, '<img ') !== 2) throw new RuntimeException('Rendu incorrect : ' . $context);
}
if (Psc_Menus::render_legend(array(array('dish'=>'Plat simple'))) !== '') throw new RuntimeException('Légende sans label');
if (substr_count(Psc_Menus::render_legend($days), '<img ') !== 2) throw new RuntimeException('Légende labels');
$captured = '';
$intercept = static function ($return, $attributes) use (&$captured) { $captured = $attributes['message']; return true; };
add_filter('pre_wp_mail', $intercept, 10, 2);
try {
    Psc_Mailer::send_weekly_menu((object) array('email'=>'menu@example.invalid'), (object) array('semaine_debut'=>'2026-09-14','lundi'=>$raw,'mardi'=>'','jeudi'=>'','vendredi'=>'','origine_viande'=>'France'));
} finally { remove_filter('pre_wp_mail', $intercept, 10); }
if (strpos($captured, 'logo-ab.png') === false || strpos($captured, 'logo-label-rouge.png') === false || strpos($captured, 'Sauté de dinde fermière**') !== false) throw new RuntimeException('Email incorrect');
foreach (array('templates/guest-menu.php'=>'psc_guest_menu', 'templates/portal-menu-block.php'=>'psc_portal_menu') as $template=>$variable) {
    $$variable = array('days'=>$days,'has_content'=>true,'week_label'=>'Semaine test','is_current_week'=>true,'prev_url'=>'#','next_url'=>'#','origine_viande'=>'France');
    ob_start(); include PSC_PATH . $template; $rendered = ob_get_clean();
    if (strpos($rendered, '*') !== false || substr_count($rendered, 'logo-ab.png') !== 2) throw new RuntimeException($template);
    if (strpos($rendered, 'psc-menu-legend') > strpos($rendered, 'menu-meat-origin')) throw new RuntimeException('Ordre de la légende');
}
echo wp_json_encode(array('rules'=>Psc_Menus::label_rules(), 'labels'=>Psc_Menus::quality_labels(), 'summary'=>'%n plats saisis · %b bio · %r Label Rouge (%p % labellisés)', 'email'=>$captured, 'portal'=>$rendered));
