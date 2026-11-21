<?php
if (!defined('ABSPATH')) exit;

/**
 * Choix des menus proposés par le fournisseur de cantine.
 *
 * Le fournisseur envoie un classeur (une feuille par semaine, cf.
 * Psc_Menu_Xlsx). La mairie le dépose, retient un plat par catégorie et
 * par jour de service, puis renvoie le classeur rempli — une croix dans la
 * colonne « Enf » en face de chaque plat retenu. Une fois la réponse
 * acceptée par le serveur d'envoi, les menus des semaines concernées sont
 * remplis (Psc_Menus) : ils restent des brouillons, l'envoi aux familles
 * demeure manuel.
 *
 * Une ligne de psc_menu_propositions = un fichier reçu. Statuts :
 * brouillon (choix modifiables) → envoye (réponse partie) → menus_remplis.
 * Le routage HTTP vit dans Psc_Admin_Menu_Propositions.
 */
class Psc_Menu_Propositions {

    const BROUILLON     = 'brouillon';
    const ENVOYE        = 'envoye';
    const MENUS_REMPLIS = 'menus_remplis';

    /** Type d'objet dans la file d'envoi (cf. Psc_Envois::sender()). */
    const ENVOI = 'reponse_menus';

    /** Dossier des fichiers reçus, relatif au répertoire privé. */
    const DIR = 'periscolaire/menus-fournisseur';

    /** Nom porté en face d'« Etablissement » tant que le réglage n'est pas renseigné. */
    const ETABLISSEMENT_DEFAUT = 'Montgeroult / Courcelles 95';

    /** Marque posée dans la colonne « Enf » du fichier du fournisseur. */
    const CROIX = 'X';

    /** Mêmes types, mêmes libellés que les menus des familles. */
    public static function categories() {
        return Psc_Menus::type_labels();
    }

    public static function statuts() {
        return array(
            self::BROUILLON     => __('Choix en cours', 'periscolaire-registration'),
            self::ENVOYE        => __('Réponse envoyée', 'periscolaire-registration'),
            self::MENUS_REMPLIS => __('Réponse envoyée, menus remplis', 'periscolaire-registration'),
        );
    }

    /** Nom de l'établissement inscrit dans le fichier renvoyé (Commande fournisseur > Réglages). */
    public static function etablissement() {
        return trim((string) get_option('psc_supplier_etablissement', self::ETABLISSEMENT_DEFAUT));
    }

    public static function get($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('menu_propositions') . ' WHERE id = %d', $id));
    }

    /** Fichiers reçus, du plus récent au plus ancien, sans leur contenu (listes). */
    public static function recent($limit = 20) {
        global $wpdb;
        $limit = max(1, min(100, (int) $limit));
        return $wpdb->get_results(
            'SELECT id, fichier_nom, periode_debut, periode_fin, statut, sent_at, menus_remplis_at, created_at FROM '
            . psc_table('menu_propositions') . " ORDER BY id DESC LIMIT $limit"
        );
    }

    /** « 11/01/2027 au 12/03/2027 ». */
    public static function periode_label($row) {
        /* translators: 1: premier jour de la période, 2: dernier jour (jj/mm/aaaa). */
        return sprintf(__('%1$s au %2$s', 'periscolaire-registration'), date_i18n('d/m/Y', strtotime($row->periode_debut)), date_i18n('d/m/Y', strtotime($row->periode_fin)));
    }

    /* ------------------------------------------------------------------ */
    /* Dépôt du fichier                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Lit le fichier du fournisseur, le range dans le répertoire privé et
     * enregistre ses propositions.
     *
     * @param string $path     Fichier à lire (dépôt temporaire).
     * @param string $name     Nom d'origine, conservé pour l'affichage et la réponse.
     * @param bool   $uploaded true : fichier issu d'un dépôt HTTP (move_uploaded_file).
     * @return int|WP_Error Identifiant de la proposition.
     */
    public static function import($path, $name, $uploaded = true) {
        global $wpdb;

        if (strtolower(pathinfo((string) $name, PATHINFO_EXTENSION)) !== 'xlsx') {
            return new WP_Error('psc_xlsx_format', __('Ce fichier n’est pas un classeur Excel (.xlsx).', 'periscolaire-registration'));
        }
        $data = Psc_Menu_Xlsx::read($path);
        if (is_wp_error($data)) return $data;

        $rel = self::DIR . '/proposition-' . wp_generate_uuid4() . '.xlsx';
        $abs = psc_private_path($rel);
        if ($abs === '' || !wp_mkdir_p(dirname($abs))) {
            return new WP_Error('psc_proposition_stockage', __('Le fichier n’a pas pu être enregistré.', 'periscolaire-registration'));
        }
        $moved = $uploaded ? move_uploaded_file($path, $abs) : copy($path, $abs);
        if (!$moved) {
            return new WP_Error('psc_proposition_stockage', __('Le fichier n’a pas pu être enregistré.', 'periscolaire-registration'));
        }

        $semaines = $data['semaines'];
        $last     = end($semaines);
        $now      = current_time('mysql');
        $user_id  = get_current_user_id();
        $inserted = $wpdb->insert(psc_table('menu_propositions'), array(
            'fichier_nom'       => mb_substr(sanitize_text_field(wp_basename((string) $name)), 0, 191),
            'fichier_path'      => $rel,
            'periode_debut'     => $semaines[0]['semaine'],
            'periode_fin'       => gmdate('Y-m-d', strtotime($last['semaine'] . ' +4 days')),
            'propositions_json' => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
            'statut'            => self::BROUILLON,
            'created_by'        => $user_id ?: null,
            'created_at'        => $now,
            'updated_at'        => $now,
        ), array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'));
        if (false === $inserted) {
            wp_delete_file($abs);
            return new WP_Error('psc_proposition_stockage', __('Le fichier n’a pas pu être enregistré.', 'periscolaire-registration'));
        }
        return (int) $wpdb->insert_id;
    }

    /** Supprime la proposition, son fichier et le suivi de son envoi. Les menus déjà remplis sont conservés. */
    public static function delete($id) {
        global $wpdb;
        $row = self::get($id);
        if (!$row) return false;
        if (!empty($row->fichier_path)) {
            $abs = psc_private_path($row->fichier_path);
            if ($abs !== '' && is_file($abs)) wp_delete_file($abs);
        }
        $wpdb->delete(psc_table('envois'), array('objet_type' => self::ENVOI, 'objet_id' => (int) $row->id), array('%s', '%d'));
        return (bool) $wpdb->delete(psc_table('menu_propositions'), array('id' => (int) $row->id), array('%d'));
    }

    /* ------------------------------------------------------------------ */
    /* Propositions et choix                                                */
    /* ------------------------------------------------------------------ */

    /** Feuilles du fichier que la lecture a écartées : liste de array('feuille', 'motif'). */
    public static function ignorees($row) {
        $data = json_decode((string) $row->propositions_json, true);
        return is_array($data) && !empty($data['ignorees']) && is_array($data['ignorees']) ? $data['ignorees'] : array();
    }

    /**
     * Semaines du fichier, réduites aux jours de service ouverts au
     * calendrier scolaire (ni mercredi, ni vacances, ni férié, ni
     * fermeture) : une semaine fermée garde une liste de jours vide.
     *
     * @return array array(lundi Y-m-d => array('feuille', 'part', 'etab', 'etab_texte',
     *               'jours' => array(jour => array('date', 'enf', 'plats' => array(catégorie => plats)))))
     */
    public static function semaines($row) {
        $data = json_decode((string) $row->propositions_json, true);
        $out  = array();
        if (!is_array($data) || empty($data['semaines']) || !is_array($data['semaines'])) return $out;
        // Fermetures de toutes les semaines lues en une requête (cf.
        // Psc_School_Calendar::preload_closed()), au lieu d'une par jour.
        $dates = array();
        foreach ($data['semaines'] as $semaine) {
            $monday = psc_week_start($semaine['semaine'] ?? '');
            if (!$monday) continue;
            foreach (psc_service_jour_offsets() as $offset) $dates[] = gmdate('Y-m-d', strtotime($monday . " +{$offset} days"));
        }
        Psc_School_Calendar::preload_closed($dates);
        foreach ($data['semaines'] as $semaine) {
            $monday = psc_week_start($semaine['semaine'] ?? '');
            if (!$monday) continue;
            $jours = array();
            foreach (psc_open_days($monday) as $jour => $date) {
                if (empty($semaine['jours'][$jour]['plats'])) continue;
                $jours[$jour] = array(
                    'date'  => $date,
                    'enf'   => (string) $semaine['jours'][$jour]['enf'],
                    'plats' => (array) $semaine['jours'][$jour]['plats'],
                );
            }
            $out[$monday] = array(
                'feuille'    => (string) ($semaine['feuille'] ?? ''),
                'part'       => (string) ($semaine['part'] ?? ''),
                'etab'       => (string) ($semaine['etab'] ?? ''),
                'etab_texte' => (string) ($semaine['etab_texte'] ?? ''),
                'jours'      => $jours,
            );
        }
        return $out;
    }

    /** Plat suggéré par la diététicienne : la première proposition de la catégorie (0 si aucune). */
    private static function suggestion(array $plats) {
        foreach ($plats as $plat) {
            if (($plat['g'] ?? '') === 'p') return (int) $plat['r'];
        }
        return 0;
    }

    /** Lignes proposées dans une catégorie. */
    private static function lignes(array $plats) {
        $rows = array();
        foreach ($plats as $plat) $rows[] = (int) $plat['r'];
        return $rows;
    }

    /**
     * Choix effectifs : ceux enregistrés, sinon la suggestion de la
     * diététicienne. 0 = aucun plat dans la catégorie.
     *
     * @param array|null $semaines Résultat de semaines() s'il est déjà calculé.
     * @return array array(semaine => array(jour => array(catégorie => ligne du fichier)))
     */
    public static function choix($row, $semaines = null) {
        if ($semaines === null) $semaines = self::semaines($row);
        $saved = json_decode((string) $row->choix_json, true);
        if (!is_array($saved)) $saved = array();
        $out = array();
        foreach ($semaines as $semaine => $info) {
            foreach ($info['jours'] as $jour => $day) {
                foreach (Psc_Menu_Xlsx::CATEGORIES as $cat) {
                    $plats = isset($day['plats'][$cat]) ? (array) $day['plats'][$cat] : array();
                    $value = self::suggestion($plats);
                    if (isset($saved[$semaine][$jour][$cat])) {
                        $candidate = (int) $saved[$semaine][$jour][$cat];
                        if ($candidate === 0 || in_array($candidate, self::lignes($plats), true)) $value = $candidate;
                    }
                    $out[$semaine][$jour][$cat] = $value;
                }
            }
        }
        return $out;
    }

    /**
     * Enregistre les choix reçus du formulaire. Une valeur absente garde
     * le choix en place ; une valeur qui ne désigne aucun plat proposé est
     * ignorée.
     *
     * @param array $input array(semaine => array(jour => array(catégorie => ligne)))
     * @return true|WP_Error
     */
    public static function save_choix($id, array $input) {
        global $wpdb;
        $row = self::get($id);
        if (!$row) return new WP_Error('psc_proposition_introuvable', __('Proposition introuvable.', 'periscolaire-registration'));
        if ($row->statut !== self::BROUILLON) {
            return new WP_Error('psc_proposition_verrouillee', __('La réponse est déjà partie : les choix ne sont plus modifiables.', 'periscolaire-registration'));
        }
        $semaines = self::semaines($row);
        $choix    = self::choix($row, $semaines);
        foreach ($choix as $semaine => $jours) {
            foreach ($jours as $jour => $cats) {
                foreach ($cats as $cat => $current) {
                    if (!isset($input[$semaine][$jour][$cat]) || !is_scalar($input[$semaine][$jour][$cat])) continue;
                    $candidate = absint($input[$semaine][$jour][$cat]);
                    $plats = isset($semaines[$semaine]['jours'][$jour]['plats'][$cat]) ? (array) $semaines[$semaine]['jours'][$jour]['plats'][$cat] : array();
                    if ($candidate === 0 || in_array($candidate, self::lignes($plats), true)) {
                        $choix[$semaine][$jour][$cat] = $candidate;
                    }
                }
            }
        }
        $updated = $wpdb->update(
            psc_table('menu_propositions'),
            array('choix_json' => wp_json_encode($choix), 'updated_at' => current_time('mysql')),
            array('id' => (int) $row->id),
            array('%s', '%s'),
            array('%d')
        );
        if (false === $updated) {
            return new WP_Error('psc_proposition_stockage', __('Les choix n’ont pas pu être enregistrés.', 'periscolaire-registration'));
        }
        return true;
    }

    /** Libellé d'un plat retenu, d'après sa ligne ; '' si aucun. */
    private static function plat(array $plats, $ligne) {
        foreach ($plats as $plat) {
            if ((int) $plat['r'] === (int) $ligne) return (string) $plat['t'];
        }
        return '';
    }

    /**
     * Plats retenus, pour l'affichage du récapitulatif.
     *
     * @return array array(semaine => array(jour => array(catégorie => libellé)))
     */
    public static function plats_retenus($row) {
        $semaines = self::semaines($row);
        $out = array();
        foreach (self::choix($row, $semaines) as $semaine => $jours) {
            foreach ($jours as $jour => $cats) {
                foreach ($cats as $cat => $ligne) {
                    if (!$ligne) continue;
                    $text = self::plat((array) ($semaines[$semaine]['jours'][$jour]['plats'][$cat] ?? array()), $ligne);
                    if ($text !== '') $out[$semaine][$jour][$cat] = $text;
                }
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Fichier rempli                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Cellules à écrire dans le fichier du fournisseur : une croix dans la
     * colonne « Enf » en face de chaque plat retenu, et le nom de
     * l'établissement sur chaque feuille.
     *
     * @return array array('croix' => nombre de croix, 'feuilles' => array(chemin => array(cellule => texte)))
     */
    public static function marks($row) {
        $semaines = self::semaines($row);
        $choix    = self::choix($row, $semaines);
        $etab     = self::etablissement();
        $feuilles = array();
        $croix    = 0;
        foreach ($semaines as $semaine => $info) {
            if ($info['part'] === '') continue;
            $cells = array();
            if ($etab !== '' && $info['etab'] !== '') {
                $colon  = strpos($info['etab_texte'], ':');
                $prefix = $colon !== false ? rtrim(substr($info['etab_texte'], 0, $colon)) . ':' : __('Etablissement :', 'periscolaire-registration');
                $cells[$info['etab']] = $prefix . ' ' . $etab;
            }
            foreach ($info['jours'] as $jour => $day) {
                foreach (Psc_Menu_Xlsx::CATEGORIES as $cat) {
                    $ligne = (int) ($choix[$semaine][$jour][$cat] ?? 0);
                    if ($ligne > 0 && preg_match('/^[A-Z]{1,3}$/', $day['enf'])) {
                        $cells[$day['enf'] . $ligne] = self::CROIX;
                        $croix++;
                    }
                }
            }
            if ($cells) $feuilles[$info['part']] = $cells;
        }
        return array('croix' => $croix, 'feuilles' => $feuilles);
    }

    /** Nom de la pièce jointe : celui du fournisseur, suffixé. */
    public static function response_name($row) {
        $base = pathinfo((string) $row->fichier_nom, PATHINFO_FILENAME);
        $name = sanitize_file_name(($base !== '' ? $base : 'menus') . '-reponse.xlsx');
        return $name !== '' ? $name : 'menus-reponse.xlsx';
    }

    /**
     * Produit le fichier rempli dans un dossier temporaire qui lui est
     * propre (la pièce jointe porte ainsi son vrai nom). À supprimer par
     * discard_file() après usage.
     *
     * @return string|WP_Error Chemin du fichier.
     */
    public static function build_file($row) {
        $source = !empty($row->fichier_path) ? psc_private_path($row->fichier_path) : '';
        if ($source === '' || !is_file($source)) {
            return new WP_Error('psc_fichier_absent', __('Le fichier du fournisseur est introuvable : déposez-le de nouveau.', 'periscolaire-registration'));
        }
        $dir = trailingslashit(get_temp_dir()) . 'psc-menus-' . wp_generate_uuid4();
        if (!wp_mkdir_p($dir)) {
            return new WP_Error('psc_xlsx_ecriture', __('Le fichier rempli n’a pas pu être produit.', 'periscolaire-registration'));
        }
        $target = $dir . '/' . self::response_name($row);
        $marks  = self::marks($row);
        $filled = Psc_Menu_Xlsx::fill($source, $target, $marks['feuilles']);
        if (is_wp_error($filled)) {
            self::discard_file($target);
            return $filled;
        }
        return $target;
    }

    /** Supprime un fichier produit par build_file() et son dossier. */
    public static function discard_file($path) {
        if (!is_string($path) || $path === '') return;
        if (is_file($path)) wp_delete_file($path);
        $dir = dirname($path);
        if (strpos(wp_basename($dir), 'psc-menus-') === 0 && is_dir($dir)) {
            @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        }
    }

    /* ------------------------------------------------------------------ */
    /* Menus des familles                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Ligne de menu d'un plat du fournisseur : la mention « BIO » devient
     * l'étoile du logo AB (cf. Psc_Menus::label_rules()) ; « Plat complet »
     * en accompagnement n'est pas un plat.
     */
    public static function menu_line($text) {
        $name = str_replace('*', '', (string) $text);
        $bio  = (bool) preg_match('/\bbio\b/iu', $name);
        if ($bio) $name = (string) preg_replace('/\bbio\b/iu', ' ', $name);
        $name = Psc_Menu_Xlsx::clean($name);
        if ($name === '' || Psc_Menu_Xlsx::key($name) === 'plat complet') return '';
        return $name . ($bio ? '*' : '');
    }

    /**
     * Plats que les choix produisent, rangés par type : c'est sous cette
     * forme qu'ils sont enregistrés dans les menus (cf. Psc_Menus::save()).
     *
     * @return array array(semaine => array(jour => array(type => ligne de menu))), jours sans plat exclus.
     */
    public static function plats_prevus($row) {
        $out = array();
        foreach (self::plats_retenus($row) as $semaine => $jours) {
            foreach ($jours as $jour => $cats) {
                $par_type = array();
                foreach (Psc_Menu_Xlsx::CATEGORIES as $cat) {
                    $line = isset($cats[$cat]) ? self::menu_line($cats[$cat]) : '';
                    if ($line !== '') $par_type[$cat] = $line;
                }
                if ($par_type) $out[$semaine][$jour] = $par_type;
            }
        }
        return $out;
    }

    /**
     * Menus que les choix produisent : un plat par ligne, dans l'ordre du
     * service.
     *
     * @return array array(semaine => array(jour => texte du jour)), jours sans plat exclus.
     */
    public static function menus_prevus($row) {
        $out = array();
        foreach (self::plats_prevus($row) as $semaine => $jours) {
            foreach ($jours as $jour => $par_type) $out[$semaine][$jour] = implode("\n", $par_type);
        }
        return $out;
    }

    private static function same_text($a, $b) {
        $norm = function ($text) {
            return trim((string) preg_replace('/[ \t]*\R[ \t]*/u', "\n", trim((string) $text)));
        };
        return $norm($a) === $norm($b);
    }

    /**
     * Situation du menu de chaque semaine au regard des choix :
     * 'sans_choix' (semaine fermée ou sans plat retenu), 'absent' (aucun
     * menu), 'a_jour', 'different' (menu non envoyé qui diffère),
     * 'envoye_different' (menu déjà envoyé aux familles, qui diffère).
     *
     * @return array array(semaine => array('etat' => code, 'menu' => ligne psc_menus ou null))
     */
    public static function etat_menus($row) {
        $prevus = self::menus_prevus($row);
        $out = array();
        foreach (array_keys(self::semaines($row)) as $semaine) {
            $menu = Psc_Menus::get_by_week($semaine);
            if (empty($prevus[$semaine])) {
                $etat = 'sans_choix';
            } elseif (!$menu) {
                $etat = 'absent';
            } else {
                $etat = 'a_jour';
                foreach ($prevus[$semaine] as $jour => $text) {
                    if (!self::same_text($menu->$jour ?? '', $text)) {
                        $etat = $menu->sent_at ? 'envoye_different' : 'different';
                        break;
                    }
                }
            }
            $out[$semaine] = array('etat' => $etat, 'menu' => $menu);
        }
        return $out;
    }

    /**
     * Remplit les menus des semaines choisies. Un menu absent est créé ; un
     * menu non envoyé est remplacé sur les jours choisis (les autres jours
     * et l'origine de la viande sont conservés) ; un menu déjà envoyé aux
     * familles n'est remplacé que si sa semaine figure dans $forcer.
     *
     * @param array $forcer Lundis dont le menu, déjà envoyé, doit être remplacé.
     * @return array array(semaine => 'cree' | 'remplace' | 'a_jour' | 'conserve' | 'erreur')
     */
    public static function remplir_menus($row, array $forcer = array()) {
        global $wpdb;
        $prevus = self::plats_prevus($row);
        $out    = array();
        foreach (self::etat_menus($row) as $semaine => $info) {
            if ($info['etat'] === 'sans_choix') continue;
            $menu = $info['menu'];
            if ($info['etat'] === 'a_jour') {
                // Même texte : rien à remplacer, sauf les types des plats
                // quand le menu n'en porte pas (saisi avant le schéma
                // 4.25.0, ou à la main en texte seul). Ils sont alors
                // ajoutés, menu envoyé ou non : le texte lu par les familles
                // ne change pas, il gagne seulement ses types.
                $out[$semaine] = 'a_jour';
                $typer = false;
                foreach ($prevus[$semaine] as $jour => $par_type) {
                    if (Psc_Menus::types_of($menu->$jour, $menu->{$jour . '_types'} ?? '') !== array_keys($par_type)) $typer = true;
                }
                if (!$typer) continue;
            } elseif ($info['etat'] === 'envoye_different' && !in_array($semaine, $forcer, true)) {
                $out[$semaine] = 'conserve';
                continue;
            }
            $jours = array();
            foreach (Psc_Menus::JOURS as $jour) $jours[$jour] = $menu ? (string) $menu->$jour : '';
            foreach ($prevus[$semaine] as $jour => $par_type) $jours[$jour] = $par_type;
            $saved = Psc_Menus::save($menu ? (int) $menu->id : 0, $semaine, $jours);
            if (is_wp_error($saved) || !$saved) $out[$semaine] = 'erreur';
            elseif ($info['etat'] !== 'a_jour') $out[$semaine] = $menu ? 'remplace' : 'cree';
        }
        if (!in_array('erreur', $out, true) && $row->statut !== self::BROUILLON && empty($row->menus_remplis_at)) {
            $wpdb->update(
                psc_table('menu_propositions'),
                array('statut' => self::MENUS_REMPLIS, 'menus_remplis_at' => current_time('mysql'), 'updated_at' => current_time('mysql')),
                array('id' => (int) $row->id),
                array('%s', '%s', '%s'),
                array('%d')
            );
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Réponse au fournisseur                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Enregistre l'instantané du mail (destinataire, sujet, corps) puis
     * confie l'envoi à Psc_Envois. Le même formulaire soumis deux fois
     * (même $lot) n'envoie rien de plus.
     *
     * @return int|WP_Error Identifiant de la proposition si le mail est accepté.
     */
    public static function send($id, $lot = '') {
        global $wpdb;
        $row = self::get($id);
        if (!$row) return new WP_Error('psc_proposition_introuvable', __('Proposition introuvable.', 'periscolaire-registration'));
        $lot = Psc_Envois::lot($lot);

        $existing = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . psc_table('envois') . ' WHERE objet_type = %s AND objet_id = %d AND lot = %s LIMIT 1',
            self::ENVOI, (int) $row->id, $lot
        ));
        if ($existing) {
            return self::finish((int) $row->id, Psc_Envois::bilan(self::ENVOI, (int) $row->id, $lot));
        }
        if ($row->statut !== self::BROUILLON) {
            return new WP_Error('psc_proposition_verrouillee', __('La réponse est déjà partie : les choix ne sont plus modifiables.', 'periscolaire-registration'));
        }

        $supplier_email = get_option('psc_supplier_email', '');
        if (!$supplier_email || !is_email($supplier_email)) {
            return new WP_Error(
                'psc_no_supplier_email',
                __("Aucune adresse e-mail fournisseur n'est configurée (Périscolaire > Commande fournisseur > Réglages).", 'periscolaire-registration')
            );
        }
        $marks = self::marks($row);
        if ($marks['croix'] === 0) {
            return new WP_Error('psc_aucun_choix', __('Aucun plat n’est retenu : il n’y a rien à répondre au fournisseur.', 'periscolaire-registration'));
        }
        $source = !empty($row->fichier_path) ? psc_private_path($row->fichier_path) : '';
        if ($source === '' || !is_file($source)) {
            return new WP_Error('psc_fichier_absent', __('Le fichier du fournisseur est introuvable : déposez-le de nouveau.', 'periscolaire-registration'));
        }

        $built = Psc_Mailer::build_menu_choices($row);
        $saved = $wpdb->update(
            psc_table('menu_propositions'),
            array(
                'supplier_email' => $supplier_email,
                'email_subject'  => $built['subject'],
                'email_body'     => $built['html'],
                // Les choix effectifs (suggestions comprises) sont figés
                // avec le mail : la réponse ne dépend plus des défauts.
                'choix_json'     => wp_json_encode(self::choix($row)),
                'updated_at'     => current_time('mysql'),
            ),
            array('id' => (int) $row->id),
            array('%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );
        if (false === $saved) {
            return new WP_Error('psc_proposition_stockage', __('La réponse n’a pas pu être enregistrée : rien n’a été envoyé.', 'periscolaire-registration'));
        }

        return self::finish((int) $row->id, Psc_Envois::lancer(self::ENVOI, (int) $row->id, $lot, null));
    }

    /** Relance l'envoi d'une réponse en échec (même lot). */
    public static function relancer($id) {
        return self::finish((int) $id, Psc_Envois::relancer_echecs(self::ENVOI, (int) $id));
    }

    private static function finish($id, $bilan) {
        if ($bilan['total'] > 0 && $bilan[Psc_Envois::ACCEPTE] === $bilan['total']) return $id;
        return new WP_Error('psc_mail_failed', __("L'envoi du mail a échoué.", 'periscolaire-registration'));
    }

    /**
     * Expéditeur d'une réponse (cf. Psc_Envois::sender()) : produit le
     * fichier rempli, l'envoie en pièce jointe, date l'envoi puis remplit
     * les menus.
     */
    public static function deliver($id, $famille_id = null) {
        global $wpdb;
        $row = self::get($id);
        if (!$row) return new WP_Error('proposition_introuvable', 'Proposition introuvable');
        if (!is_email($row->supplier_email)) return new WP_Error('adresse_invalide', 'Adresse invalide');
        $file = self::build_file($row);
        if (is_wp_error($file)) return new WP_Error('fichier_indisponible', 'Fichier indisponible');

        $sent = Psc_Mailer::send_raw($row->supplier_email, (string) $row->email_subject, (string) $row->email_body, array($file));
        self::discard_file($file);
        if (!$sent) return new WP_Error('mail_refuse', 'Envoi refusé');

        $now = current_time('mysql');
        $wpdb->update(
            psc_table('menu_propositions'),
            array('statut' => self::ENVOYE, 'sent_at' => $now, 'updated_at' => $now),
            array('id' => (int) $row->id, 'statut' => self::BROUILLON),
            array('%s', '%s', '%s'),
            array('%d', '%s')
        );
        // Les menus ne se remplissent qu'une fois la réponse partie.
        $fresh = self::get($id);
        if ($fresh) self::remplir_menus($fresh);
        return true;
    }
}
