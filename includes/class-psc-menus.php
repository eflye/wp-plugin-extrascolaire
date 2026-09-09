<?php
if (!defined('ABSPATH')) exit;

/**
 * Menus de cantine : une ligne par semaine (identifiée par son lundi),
 * saisie par la mairie et poussée aux familles par e-mail sur action
 * manuelle de l'admin — jamais automatique, jamais de cron. Le routage
 * HTTP (nonces, permissions, redirections) vit dans Psc_Admin, comme pour
 * Psc_Invoices et Psc_Requests ; cette classe ne fait que la logique
 * métier.
 */
class Psc_Menus {

    const JOURS = array('lundi', 'mardi', 'jeudi', 'vendredi');

    /** Décalage en jours depuis le lundi de la semaine. */
    const JOUR_OFFSETS = array('lundi' => 0, 'mardi' => 1, 'jeudi' => 3, 'vendredi' => 4);

    /** Règles partagées avec l’aperçu local : l’ordre des suffixes est significatif. */
    public static function label_rules() {
        return array('suffixes' => array('label_rouge' => '\*\*\s*$', 'bio' => '\*\s*$'), 'cleanup' => array('\*+\s*$', '\*+'));
    }

    public static function parse_menu_lines(string $raw): array {
        $rules = self::label_rules();
        $out = array();
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $label = null;
            foreach ($rules['suffixes'] as $key => $pattern) {
                if (preg_match('/' . $pattern . '/u', $line)) { $label = $key; break; }
            }
            foreach ($rules['cleanup'] as $pattern) $line = preg_replace('/' . $pattern . '/u', '', $line);
            $name = trim($line);
            if ($name !== '') $out[] = array('name' => $name, 'label' => $label);
        }
        return $out;
    }

    public static function quality_labels() {
        return array(
            'bio' => array('url' => PSC_URL . 'assets/img/logo-ab.png', 'alt' => __("Issu de l'agriculture biologique", 'periscolaire-registration'), 'legend' => __('Plat préparé avec des ingrédients issus de l’agriculture biologique.', 'periscolaire-registration')),
            'label_rouge' => array('url' => PSC_URL . 'assets/img/logo-label-rouge.png', 'alt' => __('Label Rouge — garantie qualité supérieure', 'periscolaire-registration'), 'legend' => __('Produit Label Rouge, garantie de qualité supérieure.', 'periscolaire-registration')),
        );
    }

    public static function label_image($label, $height = 22) {
        $labels = self::quality_labels();
        if (!isset($labels[$label])) return '';
        $item = $labels[$label];
        return '<img src="' . esc_url($item['url']) . '" alt="' . esc_attr($item['alt']) . '" title="' . esc_attr($item['alt']) . '" height="' . (int) $height . '" style="height:' . (int) $height . 'px;width:auto;max-width:none;flex:none;border:0;display:inline-block;vertical-align:middle;">';
    }

    /** HTML échappé partagé par le portail, le tableau de bord, l’impression et l’e-mail. */
    public static function render_dishes($raw, $context = 'frontend') {
        $html = '';
        foreach (self::parse_menu_lines((string) $raw) as $dish) {
            $style = $context === 'email' ? 'margin:0 0 5px;color:#24405C;' : 'display:flex;align-items:center;gap:8px;color:#24405C;';
            $html .= '<div class="psc-menu-dish" style="' . $style . '"><span>' . esc_html($dish['name']) . '</span>';
            if ($dish['label']) $html .= ' ' . self::label_image($dish['label'], $context === 'preview' ? 16 : 22);
            $html .= '</div>';
        }
        return $html;
    }

    public static function render_legend(array $days) {
        $present = array();
        foreach ($days as $day) {
            foreach (self::parse_menu_lines((string) $day['dish']) as $dish) {
                if ($dish['label']) $present[$dish['label']] = true;
            }
        }
        if (!$present) return '';
        $html = '<div class="psc-menu-legend" style="border-top:1px solid #F0E7DC;margin-top:16px;padding-top:12px;color:#4E6C8D;">';
        foreach (self::quality_labels() as $key => $label) {
            if (isset($present[$key])) $html .= '<p style="margin:0 0 8px;font-size:13px;">' . self::label_image($key) . ' ' . esc_html($label['legend']) . '</p>';
        }
        return $html . '</div>';
    }

    public static function default_meat_origin() {
        return __('Les viandes de bœuf, volaille, porc et de veau qui vous sont servies sont issues d’animaux nés, élevés et abattus en France et pouvant être issue de l’agriculture biologique.', 'periscolaire-registration');
    }

    /**
     * Les menus créés avant l'ajout du champ ont une valeur NULL : ils
     * utilisent alors la mention par défaut. Une chaîne vide enregistrée
     * explicitement continue de masquer volontairement la mention.
     */
    public static function meat_origin($menu) {
        if (!$menu) return '';
        if (!isset($menu->origine_viande)) return self::default_meat_origin();
        return trim((string) $menu->origine_viande);
    }

    public static function jour_labels() {
        return array(
            'lundi'     => __('Lundi', 'periscolaire-registration'),
            'mardi'     => __('Mardi', 'periscolaire-registration'),
            'jeudi'     => __('Jeudi', 'periscolaire-registration'),
            'vendredi'  => __('Vendredi', 'periscolaire-registration'),
        );
    }

    public static function get($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('menus') . ' WHERE id = %d', $id));
    }

    public static function get_by_week($monday) {
        global $wpdb;
        $monday = psc_valid_date($monday);
        if (!$monday) return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('menus') . ' WHERE semaine_debut = %s', $monday));
    }

    public static function recent($limit = 12) {
        global $wpdb;
        $limit = max(1, min(52, (int) $limit));
        return $wpdb->get_results('SELECT * FROM ' . psc_table('menus') . " ORDER BY semaine_debut DESC LIMIT $limit");
    }

    /** Voir psc_open_days() — un jour fermé n'a pas de menu à saisir. */
    public static function open_days($monday) {
        return psc_open_days($monday);
    }

    /** Voir psc_next_open_week(). */
    public static function next_open_week($from_date) {
        return psc_next_open_week($from_date);
    }

    /**
     * Crée ou met à jour le menu d'une semaine. Une semaine = une seule
     * ligne : si $id ne correspond pas à la semaine visée mais qu'une
     * ligne existe déjà pour cette semaine-là, c'est CETTE ligne qui est
     * mise à jour — évite un doublon si l'admin déplace la date d'un menu
     * en édition vers une semaine déjà saisie par ailleurs.
     *
     * Renvoie l'id du menu, ou un WP_Error si la semaine est invalide.
     */
    public static function save($id, $semaine_debut, array $jours, $origine_viande = null) {
        global $wpdb;
        $t = psc_table('menus');

        $semaine = psc_week_start($semaine_debut);
        if (!$semaine) {
            return new WP_Error('psc_invalid_week', __('Date de semaine invalide.', 'periscolaire-registration'));
        }

        // Un jour fermé (vacances, férié, fermeture ponctuelle) n'a jamais de
        // contenu, quoi que le formulaire ait pu envoyer — appliqué ici aussi
        // (pas seulement à l'affichage) car c'est la seule garantie fiable.
        $open   = self::open_days($semaine);
        $data   = array('semaine_debut' => $semaine, 'updated_at' => current_time('mysql'));
        $format = array('%s', '%s');
        foreach (self::JOURS as $jour) {
            $data[$jour] = (isset($open[$jour]) && isset($jours[$jour]))
                ? mb_substr(sanitize_textarea_field($jours[$jour]), 0, 2000)
                : '';
            $format[]    = '%s';
        }

        $existing = self::get_by_week($semaine);
        if ($existing) {
            $id = (int) $existing->id;
        }
        $id = absint($id);
        // Les anciens appelants qui ne transmettent pas le champ conservent
        // la valeur existante. Le formulaire transmet toujours sa valeur.
        if ($origine_viande !== null) {
            $data['origine_viande'] = mb_substr(sanitize_textarea_field($origine_viande), 0, 2000);
            $format[] = '%s';
        }

        if ($id) {
            $wpdb->update($t, $data, array('id' => $id), $format, array('%d'));
        } else {
            $data['created_at'] = current_time('mysql');
            $format[]            = '%s';
            $wpdb->insert($t, $data, $format);
            $id = (int) $wpdb->insert_id;
        }

        return $id;
    }

    public static function delete($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return false;
        return $wpdb->delete(psc_table('menus'), array('id' => $id), array('%d'));
    }

    /**
     * Familles concernées par l'envoi : parents actifs ayant au moins un
     * enfant actif. Les préférences sans_porc/vegan ne filtrent RIEN ici :
     * le menu envoyé est le même pour tout le monde, ces informations
     * servent la cuisine (visible côté admin > Enfants), pas ce message.
     */
    public static function recipients() {
        global $wpdb;
        $t_parent = psc_table('parents');
        $t_child  = psc_table('children');
        return $wpdb->get_results(
            "SELECT DISTINCT p.* FROM $t_parent p
             INNER JOIN $t_child c ON c.parent_id = p.id
             WHERE p.active = 1 AND c.statut = 'actif'"
        );
    }

    /**
     * Envoie le menu à toutes les familles concernées et marque sent_at.
     * Renvoie le nombre d'e-mails effectivement envoyés.
     */
    public static function send($menu) {
        global $wpdb;

        $sent_count = 0;
        foreach (self::recipients() as $parent) {
            if (Psc_Mailer::send_weekly_menu($parent, $menu)) {
                $sent_count++;
            }
        }

        $wpdb->update(
            psc_table('menus'),
            array('sent_at' => current_time('mysql')),
            array('id' => (int) $menu->id),
            array('%s'),
            array('%d')
        );

        return $sent_count;
    }
}
