<?php
if (!defined('ABSPATH')) exit;

/**
 * Bascule de thème du portail famille : Encre (par défaut) / Clair.
 *
 * Le thème choisi est porté par l'attribut data-psc-theme="light" sur
 * <html>, pour que la feuille portal-light.css puisse aussi habiller le
 * bandeau et le pied de page rendus par le thème WordPress. Absent, c'est
 * l'Encre : aucune règle existante ne dépend de cet attribut.
 *
 * La préférence vit à deux endroits, écrits ensemble par theme-toggle.js :
 * - le cookie psc_theme, lu ici pour poser l'attribut dans le HTML servi
 *   (pas de flash Encre → Clair au chargement) — il fait autorité ;
 * - localStorage['psc-theme'], filet de sécurité quand le cookie a été
 *   effacé : le petit script en ligne de <head> repose alors l'attribut
 *   et réécrit le cookie avant le premier rendu.
 *
 * Ce n'est pas un mode sombre : prefers-color-scheme n'est pas suivi.
 *
 * Portée : la seule page du portail famille ([periscolaire_form]),
 * connectée ou invitée. Jamais sur l'écran intervenants, qui reste en
 * Encre, ni en administration.
 */
class Psc_Theme_Toggle {

    const COOKIE = 'psc_theme';
    const STORAGE_KEY = 'psc-theme';

    public static function init() {
        add_filter('language_attributes', array(__CLASS__, 'html_attribute'));
        // Priorité 1 : le plus tôt possible dans <head>, avant les feuilles
        // de style, pour que l'attribut soit posé avant le premier rendu.
        add_action('wp_head', array(__CLASS__, 'print_head_script'), 1);
        // Priorité 30 : après la feuille du thème (montgeroult-psc-theme, en
        // 20), que portal-light.css doit pouvoir surcharger.
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'), 30);
        // Bandeau du thème (header.php, à droite du menu « Accueil ») ; les
        // gabarits du plugin rendent le bouton eux-mêmes si le thème
        // installé ne déclenche pas cette action.
        add_action('montgeroult_header_actions', array(__CLASS__, 'header_button'));
    }

    /**
     * Vrai sur la page du portail famille, et seulement là. Même condition
     * que Psc_Frontend::assets() (page singulière portant le shortcode),
     * l'écran intervenants étant exclu explicitement : une page qui
     * porterait les deux shortcodes reste en Encre.
     */
    public static function in_scope() {
        if (is_admin() || !did_action('wp') || !is_singular()) return false;
        $post = get_post();
        if (!$post || !has_shortcode($post->post_content, 'periscolaire_form')) return false;
        if (has_shortcode($post->post_content, 'periscolaire_sidscm')) return false;
        if (class_exists('Psc_Sidscm') && (int) Psc_Sidscm::page_id() === (int) $post->ID) return false;
        return true;
    }

    /** Vrai si le cookie demande le thème Clair (toute autre valeur : Encre). */
    public static function is_light() {
        if (!isset($_COOKIE[self::COOKIE])) return false;
        // Comparaison stricte : « LIGHT » ou « light » suivi d'autre chose
        // ne demandent pas le Clair (sanitize_key minusculerait la valeur).
        return sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])) === 'light';
    }

    /** Ajoute data-psc-theme="light" aux attributs de <html>. */
    public static function html_attribute($output) {
        if (!self::in_scope() || !self::is_light()) return $output;
        return $output . ' data-psc-theme="light"';
    }

    /**
     * Filet de sécurité anti-flash : cookie absent mais préférence Clair
     * restée dans localStorage (cookie effacé, expiré) → on pose
     * l'attribut et on réécrit le cookie avant le rendu. Une valeur « ink »
     * ne demande rien : l'Encre est le rendu par défaut. Quand le cookie
     * existe, le serveur l'a déjà appliqué : il fait autorité.
     */
    public static function print_head_script() {
        if (!self::in_scope()) return;
        $js = "(function(){try{if(/(?:^|;\\s*)" . self::COOKIE . "=/.test(document.cookie))return;"
            . "if(window.localStorage.getItem('" . self::STORAGE_KEY . "')!=='light')return;"
            . "document.documentElement.setAttribute('data-psc-theme','light');"
            . "document.cookie='" . self::COOKIE . "=light; path=/; max-age=31536000; SameSite=Lax'+(location.protocol==='https:'?'; Secure':'');"
            . "}catch(e){}})();";
        if (function_exists('wp_print_inline_script_tag')) {
            wp_print_inline_script_tag($js);
        } else {
            echo '<script>' . $js . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- chaîne littérale.
        }
    }

    public static function assets() {
        if (!self::in_scope()) return;
        $deps = array('psc-portal');
        if (wp_style_is('psc-messages', 'registered')) $deps[] = 'psc-messages';
        wp_enqueue_style('psc-portal-light', PSC_URL . 'assets/css/portal-light.css', $deps, PSC_VERSION);
        wp_enqueue_script('psc-theme-toggle', PSC_URL . 'assets/js/theme-toggle.js', array(), PSC_VERSION, true);
    }

    /** Rendu dans le bandeau du thème (action montgeroult_header_actions). */
    public static function header_button() {
        if (!self::in_scope()) return;
        self::render_button();
    }

    /**
     * Bouton soleil/lune, de nom « Thème clair » avec aria-pressed. Le soleil (« passer en clair ») s'affiche en
     * Encre, la lune (« passer en encre ») en Clair : les deux pictogrammes
     * sont dans le markup et la feuille portal-light.css n'en montre qu'un
     * selon l'attribut de <html>, ce qui évite tout rendu JS au chargement.
     *
     * @param bool $inline Bouton rendu dans le contenu du plugin (thème sans
     *                     action montgeroult_header_actions), sur fond clair.
     */
    public static function render_button($inline = false) {
        $light = self::is_light();
        // Bouton bascule : nom fixe + aria-pressed (« Thème clair, activé »).
        // Un nom qui décrirait l'action (« Activer le thème encre ») se
        // contredirait avec l'état annoncé (« …, activé »). L'infobulle,
        // elle, décrit le prochain clic.
        $name = __('Thème clair', 'periscolaire-registration');
        $title_light = __('Activer le thème clair', 'periscolaire-registration');
        $title_ink = __('Activer le thème encre', 'periscolaire-registration');
        $title = $light ? $title_ink : $title_light;
        $class = 'psc-theme-toggle' . ($inline ? ' psc-theme-toggle--inline' : '');
        ?>
<button type="button" class="<?php echo esc_attr($class); ?>" data-psc-theme-toggle aria-pressed="<?php echo $light ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr($name); ?>" title="<?php echo esc_attr($title); ?>" data-title-light="<?php echo esc_attr($title_light); ?>" data-title-ink="<?php echo esc_attr($title_ink); ?>">
  <svg class="psc-theme-toggle-sun" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M5.3 18.7l1.6-1.6M17.1 6.9l1.6-1.6"/></svg>
  <svg class="psc-theme-toggle-moon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" focusable="false"><path d="M19.5 14.5A8 8 0 0 1 9.5 4.5a8 8 0 1 0 10 10Z"/></svg>
</button>
        <?php
    }
}
