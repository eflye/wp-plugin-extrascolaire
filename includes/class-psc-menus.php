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

    /**
     * Types de plat, dans l'ordre du service (mêmes clés que
     * Psc_Menu_Xlsx::CATEGORIES). Le type de chaque ligne d'un jour est
     * enregistré dans la colonne `<jour>_types`, à côté du texte.
     */
    const TYPES = array('entree', 'plat', 'accompagnement', 'fromage', 'dessert');

    /** Longueur des colonnes `<jour>_types`. */
    const TYPES_MAX = 255;

    public static function type_labels() {
        return array(
            'entree'         => __('Entrée', 'periscolaire-registration'),
            'plat'           => __('Plat', 'periscolaire-registration'),
            'accompagnement' => __('Accompagnement', 'periscolaire-registration'),
            'fromage'        => __('Fromage / Laitage', 'periscolaire-registration'),
            'dessert'        => __('Dessert', 'periscolaire-registration'),
        );
    }

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

    /** Lignes de plats d'un texte, brutes : exactement celles que parse_menu_lines() retient. */
    public static function lines($raw): array {
        $out = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) $raw) as $line) {
            $line = trim($line);
            if ($line !== '' && self::parse_menu_lines($line)) $out[] = $line;
        }
        return $out;
    }

    /**
     * Type de chaque ligne d'un jour, ou tableau vide quand les types ne
     * sont pas fiables : menu saisi avant le schéma 4.25.0, texte réécrit
     * sans ses types, liste qui ne compte pas autant d'éléments que le
     * texte de lignes. L'affichage retombe alors sur la liste sans type.
     */
    public static function types_of($raw, $types): array {
        $list = array_values(array_filter(explode(',', (string) $types), function ($type) { return $type !== ''; }));
        if (!$list || count($list) !== count(self::lines($raw))) return array();
        foreach ($list as $type) {
            if (!in_array($type, self::TYPES, true)) return array();
        }
        return $list;
    }

    /**
     * Texte et types d'un jour à partir de ses plats rangés par type
     * (un plat par ligne dans chaque type).
     *
     * @param array $par_type array(type => texte)
     * @return array array('texte' => un plat par ligne, 'types' => types des lignes, séparés par des virgules)
     */
    public static function compose(array $par_type): array {
        $lines = array();
        $types = array();
        foreach (self::TYPES as $type) {
            $value = isset($par_type[$type]) && is_scalar($par_type[$type]) ? (string) $par_type[$type] : '';
            foreach (self::lines($value) as $line) {
                $lines[] = $line;
                $types[] = $type;
            }
        }
        return array('texte' => implode("\n", $lines), 'types' => implode(',', $types));
    }

    /**
     * Plats d'un jour rangés par type, pour les champs du backoffice. Sans
     * types fiables (cf. types_of()), les lignes sont réparties dans l'ordre
     * du service — la première en entrée, la deuxième en plat… le surplus
     * en dessert — et la mairie corrige avant d'enregistrer : rien n'est
     * deviné en base.
     *
     * @return array array(type => texte, un plat par ligne), tous les types présents.
     */
    public static function par_type($raw, $types = ''): array {
        $out  = array_fill_keys(self::TYPES, array());
        $list = self::types_of($raw, $types);
        $last = count(self::TYPES) - 1;
        foreach (self::lines($raw) as $i => $line) {
            $out[$list ? $list[$i] : self::TYPES[min($i, $last)]][] = $line;
        }
        foreach ($out as $type => $lines) $out[$type] = implode("\n", $lines);
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

    /**
     * HTML échappé partagé par le portail, le tableau de bord, l’impression
     * et l’e-mail. Avec des types fiables ($types, cf. types_of()), chaque
     * type renseigné précède ses plats ; un type sans plat n'apparaît pas.
     */
    public static function render_dishes($raw, $context = 'frontend', $types = '') {
        $dishes = array();
        foreach (self::parse_menu_lines((string) $raw) as $dish) {
            $style = $context === 'email' ? 'margin:0 0 5px;color:#24405C;' : 'display:flex;align-items:center;gap:8px;color:#24405C;';
            $html  = '<div class="psc-menu-dish" style="' . $style . '"><span>' . esc_html($dish['name']) . '</span>';
            if ($dish['label']) $html .= ' ' . self::label_image($dish['label'], $context === 'preview' ? 16 : 22);
            $dishes[] = $html . '</div>';
        }
        $list = self::types_of($raw, $types);
        if (!$list) return implode('', $dishes);

        $groups = array();
        foreach ($dishes as $i => $html) {
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last]['type'] === $list[$i]) $groups[$last]['html'] .= $html;
            else $groups[] = array('type' => $list[$i], 'html' => $html);
        }
        $labels = self::type_labels();
        if ($context === 'email') {
            $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">';
            foreach ($groups as $group) {
                $out .= '<tr><td style="width:120px;padding:1px 10px 0 0;vertical-align:top;color:#4E6C8D;font-size:12px;font-weight:bold;">' . esc_html($labels[$group['type']]) . '</td>'
                    . '<td style="padding:0;vertical-align:top;">' . $group['html'] . '</td></tr>';
            }
            return $out . '</table>';
        }
        $out = '<dl class="psc-menu-types" style="margin:0;">';
        foreach ($groups as $group) {
            $out .= '<div class="psc-menu-type" data-type="' . esc_attr($group['type']) . '" style="display:flex;flex-wrap:wrap;align-items:baseline;gap:0 12px;margin:0 0 4px;">'
                . '<dt style="flex:0 0 9.5em;margin:0;color:#4E6C8D;font-size:.85em;font-weight:600;">' . esc_html($labels[$group['type']]) . '</dt>'
                . '<dd style="flex:1 1 9em;min-width:0;margin:0;">' . $group['html'] . '</dd></div>';
        }
        return $out . '</dl>';
    }

    /**
     * Champs de saisie d'un jour dans le backoffice : un champ par type,
     * toujours affichés tous les cinq, un plat par ligne dans chacun.
     *
     * @param string $name     Nom du champ de formulaire (« lundi » → lundi[entree]…).
     * @param string $id       Préfixe des identifiants HTML.
     * @param array  $par_type array(type => texte), cf. par_type().
     * @param bool   $preview  Ajoute la zone d'aperçu alimentée par menu-preview.js.
     */
    public static function render_type_fields($name, $id, array $par_type, $preview = false) {
        $html = '<div class="psc-menu-type-fields">';
        foreach (self::type_labels() as $type => $label) {
            $value = isset($par_type[$type]) ? (string) $par_type[$type] : '';
            $field = $id . '-' . $type;
            $html .= '<div class="psc-menu-type-field">'
                . '<label for="' . esc_attr($field) . '">' . esc_html($label) . '</label>'
                . '<div><textarea id="' . esc_attr($field) . '" name="' . esc_attr($name . '[' . $type . ']') . '" rows="' . (int) max(1, count(self::lines($value))) . '" class="large-text" maxlength="400"'
                . ($preview ? ' data-menu-input' : '') . '>' . esc_textarea($value) . '</textarea>';
            if ($preview) $html .= '<div class="psc-menu-preview" data-menu-preview>' . self::render_dishes($value, 'preview') . '</div>';
            $html .= '</div></div>';
        }
        return $html . '</div>';
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

    /** Menus déjà lus dans la requête, par lundi (PERF-06). */
    private static $by_week = array();

    public static function get_by_week($monday) {
        global $wpdb;
        $monday = psc_valid_date($monday);
        if (!$monday) return null;
        static $watched = false;
        if (!$watched) {
            $watched = true;
            // Toute écriture sur la table des menus vide la mémoïsation.
            Psc_School_Years::on_table_write(array(psc_table('menus')), function () { self::$by_week = array(); });
        }
        if (!array_key_exists($monday, self::$by_week)) {
            self::$by_week[$monday] = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('menus') . ' WHERE semaine_debut = %s', $monday));
        }
        return self::$by_week[$monday];
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
     * Chaque jour de $jours est soit ses plats rangés par type
     * (array(type => texte), cf. compose()) — le type de chaque ligne est
     * alors enregistré —, soit un texte seul : ses types sont conservés si
     * le texte du jour ne change pas, effacés sinon (ils ne décriraient
     * plus les bonnes lignes).
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
        $open     = self::open_days($semaine);
        $existing = self::get_by_week($semaine);
        // Référence pour conserver les types d'un jour dont le texte ne
        // change pas (appelants qui ne transmettent que du texte).
        $before = $existing ?: (absint($id) ? self::get($id) : null);
        $data   = array('semaine_debut' => $semaine, 'updated_at' => current_time('mysql'));
        $format = array('%s', '%s');
        foreach (self::JOURS as $jour) {
            $value = (isset($open[$jour]) && isset($jours[$jour])) ? $jours[$jour] : '';
            if (is_array($value)) {
                $par_type = array();
                foreach (self::TYPES as $type) {
                    $par_type[$type] = isset($value[$type]) && is_scalar($value[$type]) ? sanitize_textarea_field((string) $value[$type]) : '';
                }
                $day   = self::compose($par_type);
                $text  = mb_substr($day['texte'], 0, 2000);
                // Un texte tronqué perd ses dernières lignes : leurs types aussi.
                $types = implode(',', array_slice(explode(',', $day['types']), 0, count(self::lines($text))));
            } else {
                $text  = mb_substr(sanitize_textarea_field(is_scalar($value) ? (string) $value : ''), 0, 2000);
                $types = ($before && (string) $before->$jour === $text && isset($before->{$jour . '_types'})) ? (string) $before->{$jour . '_types'} : '';
            }
            if (strlen($types) > self::TYPES_MAX) $types = '';
            $data[$jour]            = $text;
            $data[$jour . '_types'] = $types;
            $format[]               = '%s';
            $format[]               = '%s';
        }

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
     * enfant actif, sauf celles qui ont choisi de ne plus recevoir le menu
     * par e-mail (menu_emails = 0, depuis « Mon profil », la fiche famille
     * ou le lien de désinscription de l'e-mail). Les préférences
     * sans_porc/vegan ne filtrent RIEN ici : le menu envoyé est le même
     * pour tout le monde, ces informations servent la cuisine (visible
     * côté admin > Enfants), pas ce message.
     */
    public static function recipients() {
        global $wpdb;
        $t_parent = psc_table('parents');
        $t_child  = psc_table('children');
        return $wpdb->get_results(
            "SELECT DISTINCT p.* FROM $t_parent p
             INNER JOIN $t_child c ON c.parent_id = p.id
             WHERE p.active = 1 AND p.menu_emails = 1 AND " . Psc_School_Years::inscrit_ouvert_sql('c.id')
        );
    }

    /**
     * Familles concernées par le menu qui ont choisi de ne pas le recevoir
     * par e-mail : affiché à côté du bouton d'envoi, pour que la mairie
     * comprenne l'écart avec le nombre de familles inscrites.
     */
    public static function opted_out_count() {
        global $wpdb;
        $t_parent = psc_table('parents');
        $t_child  = psc_table('children');
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.id) FROM $t_parent p
             INNER JOIN $t_child c ON c.parent_id = p.id
             WHERE p.active = 1 AND p.menu_emails = 0 AND " . Psc_School_Years::inscrit_ouvert_sql('c.id')
        );
    }

    /* ------------------------------------------------------------------ */
    /* Lien de désinscription des e-mails de menu                           */
    /* ------------------------------------------------------------------ */

    /**
     * Jeton du lien « Ne plus recevoir ces menus » : signature HMAC de la
     * famille et de son adresse, rien n'est stocké. Il n'ouvre aucune
     * session et ne permet que de couper l'envoi des menus ; il cesse de
     * valoir quand l'adresse du titulaire change (ou à la rotation de la
     * clé de signature). Sans date d'expiration, comme tout lien de
     * désinscription : un e-mail ancien doit rester désinscriptible.
     */
    public static function unsubscribe_token($parent) {
        return substr(psc_sign('menu_unsubscribe|' . (int) ($parent->id ?? 0) . '|' . strtolower((string) ($parent->email ?? ''))), 0, 40);
    }

    public static function unsubscribe_url($parent) {
        return add_query_arg(array(
            'psc_menu_unsub' => (int) ($parent->id ?? 0),
            't'              => self::unsubscribe_token($parent),
        ), home_url('/'));
    }

    /** Famille active désignée par un lien de désinscription valide, sinon null. */
    public static function parent_from_unsubscribe($parent_id, $token) {
        $parent = Psc_Parents::get_by_id(absint($parent_id));
        if (!$parent || !is_string($token) || $token === '') return null;
        return hash_equals(self::unsubscribe_token($parent), $token) ? $parent : null;
    }

    /**
     * Coupe l'envoi des menus depuis le lien de l'e-mail. Journalisé au nom
     * de la famille (aucune session n'est ouverte : l'acteur est imposé).
     */
    public static function unsubscribe($parent) {
        if ((int) ($parent->menu_emails ?? 1) === 0) return true;
        $result = Psc_Parents::update((int) $parent->id, array('menu_emails' => 0), array(
            'type' => 'famille', 'id' => (int) $parent->id, 'pour_le_compte_de' => null,
            /* translators: %d: identifiant de la famille */
            'libelle' => sprintf(__('Famille #%d (lien de désinscription des menus)', 'periscolaire-registration'), (int) $parent->id),
        ));
        return $result !== false && !is_wp_error($result);
    }

    /**
     * Envoie le menu à toutes les familles concernées et marque sent_at.
     * Renvoie le nombre d'e-mails effectivement envoyés.
     */
    /**
     * Envoie le menu aux familles actives, par lot suivi destinataire par
     * destinataire (cf. Psc_Envois) : un double clic ne renvoie rien, un
     * échec est compté et relançable, et le menu n'est marqué « envoyé »
     * que si toutes les familles l'ont reçu.
     *
     * @param object $menu
     * @param string $lot Jeton du formulaire d'envoi (cf. Psc_Envois::lot()).
     * @return array Bilan du lot (cf. Psc_Envois::bilan()).
     */
    public static function send($menu, $lot = '') {
        $famille_ids = array_map('intval', wp_list_pluck(self::recipients(), 'id'));
        $bilan = Psc_Envois::lancer('menu', (int) $menu->id, Psc_Envois::lot($lot), $famille_ids);
        self::sync_sent_at((int) $menu->id, $bilan['lot']);
        return $bilan;
    }

    /** Relance les seuls échecs du dernier envoi du menu. */
    public static function relancer($menu) {
        $bilan = Psc_Envois::relancer_echecs('menu', (int) $menu->id);
        if ($bilan['lot'] !== null) self::sync_sent_at((int) $menu->id, $bilan['lot']);
        return $bilan;
    }

    /** Marque le menu envoyé quand tout le lot est accepté (jamais avant). */
    public static function sync_sent_at($menu_id, $lot) {
        global $wpdb;
        $bilan = Psc_Envois::bilan('menu', (int) $menu_id, $lot);
        if ($bilan['total'] > 0 && $bilan[Psc_Envois::ACCEPTE] === $bilan['total']) {
            $wpdb->update(psc_table('menus'), array('sent_at' => current_time('mysql')), array('id' => (int) $menu_id), array('%s'), array('%d'));
        }
    }

    /**
     * Expéditeur d'un envoi de menu (cf. Psc_Envois::sender()) : relit le
     * menu et la famille au moment de l'envoi.
     */
    public static function deliver($menu_id, $famille_id) {
        $menu = self::get($menu_id);
        if (!$menu) return new WP_Error('menu_introuvable', 'Menu introuvable');
        $parent = Psc_Parents::get_by_id($famille_id);
        if (!$parent) return new WP_Error('famille_inactive', 'Famille inactive ou supprimée');
        if (!is_email($parent->email)) return new WP_Error('adresse_invalide', 'Adresse invalide');
        // Désinscription survenue entre le lancement du lot et cet envoi :
        // le choix de la famille l'emporte (échec explicite, jamais d'envoi).
        if ((int) ($parent->menu_emails ?? 1) === 0) return new WP_Error('famille_desinscrite', 'Famille désinscrite des menus par e-mail');
        return Psc_Mailer::send_weekly_menu($parent, $menu) ? true : new WP_Error('mail_refuse', 'Envoi refusé');
    }
}
