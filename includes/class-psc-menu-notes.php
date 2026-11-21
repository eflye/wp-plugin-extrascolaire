<?php
if (!defined('ABSPATH')) exit;

/**
 * Notes de changement de menus du fournisseur de cantine.
 *
 * Quand un produit vient à manquer, le fournisseur envoie une note Word :
 * quelques phrases du type « La tomme noire est remplacée par du
 * camembert », pour un jour de repas dont la date figure dans le nom du
 * fichier (« … 09-11-26.docx »). La mairie dépose la note ; l'extension en
 * lit le texte (Psc_Menu_Docx), y repère les remplacements, les rapproche
 * des lignes du menu de ce jour-là et les propose. Rien n'est modifié sans
 * confirmation : le menu du jour s'affiche dans une zone modifiable,
 * prérempli des remplacements reconnus, à côté du texte de la note. La
 * mairie le relit, le corrige librement — la détection n'est qu'une aide,
 * la correction à la main marche toujours — puis enregistre. Le menu est
 * alors réécrit, qu'il soit en brouillon ou déjà envoyé aux familles — sans
 * aucun envoi (décision du 21/11/2026 : le renvoi aux familles reste
 * manuel).
 *
 * Une ligne de psc_menu_notes = une note reçue ; son texte est conservé,
 * pas le fichier. Statuts : a_confirmer → appliquee ou ignoree. Le routage
 * HTTP vit dans Psc_Admin_Menu_Notes.
 */
class Psc_Menu_Notes {

    const A_CONFIRMER = 'a_confirmer';
    const APPLIQUEE   = 'appliquee';
    const IGNOREE     = 'ignoree';

    /** Marque d'un élément de liste dans le texte conservé. */
    const PUCE = '• ';

    /** Jour de la semaine (ISO) → jour de service ; le mercredi et le week-end n'en sont pas. */
    const JOURS_ISO = array(1 => 'lundi', 2 => 'mardi', 4 => 'jeudi', 5 => 'vendredi');

    public static function statuts() {
        return array(
            self::A_CONFIRMER => __('À confirmer', 'periscolaire-registration'),
            self::APPLIQUEE   => __('Appliquée au menu', 'periscolaire-registration'),
            self::IGNOREE     => __('Ignorée', 'periscolaire-registration'),
        );
    }

    public static function get($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('menu_notes') . ' WHERE id = %d', $id));
    }

    /** Notes reçues, de la plus récente à la plus ancienne. */
    public static function recent($limit = 30) {
        global $wpdb;
        $limit = max(1, min(100, (int) $limit));
        return $wpdb->get_results(
            'SELECT id, fichier_nom, jour_date, statut, applied_at, created_at FROM ' . psc_table('menu_notes') . " ORDER BY id DESC LIMIT $limit"
        );
    }

    /* ------------------------------------------------------------------ */
    /* Lecture de la note                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Jour de repas concerné : la date portée par le nom du fichier
     * (« Note d'info changement de menus 09-11-26.docx » → 2026-11-09). La
     * dernière date du nom l'emporte ; une année sur deux chiffres est
     * lue 20xx.
     *
     * @return string Y-m-d, ou '' si le nom ne porte pas de date valide.
     */
    public static function date_from_name($name) {
        $base = pathinfo((string) $name, PATHINFO_FILENAME);
        if (!preg_match_all('/(?<!\d)(\d{1,2})[-._\/ ](\d{1,2})[-._\/ ](\d{4}|\d{2})(?!\d)/', $base, $matches, PREG_SET_ORDER)) return '';
        $last  = end($matches);
        $year  = (int) $last[3];
        if ($year < 100) $year += 2000;
        if (!checkdate((int) $last[2], (int) $last[1], $year)) return '';
        $date = sprintf('%04d-%02d-%02d', $year, (int) $last[2], (int) $last[1]);
        return psc_valid_date($date) ? $date : '';
    }

    /** Jour de la semaine nommé dans la note (« prévus lundi »), s'il n'y en a qu'un ; sinon ''. */
    public static function jour_cite($texte) {
        $found = array();
        if (preg_match_all('/\b(lundi|mardi|mercredi|jeudi|vendredi)s?\b/u', Psc_Menu_Xlsx::key($texte), $matches)) {
            $found = array_values(array_unique($matches[1]));
        }
        return count($found) === 1 ? $found[0] : '';
    }

    /** Nom de produit sans son article (« Les yaourts » → « yaourts », « du camembert » → « camembert »). */
    private static function sans_article($text) {
        $text = Psc_Menu_Xlsx::clean($text);
        $text = (string) preg_replace('/^(?:de\s+la\s+|de\s+l[\'’]\s*|les\s+|le\s+|la\s+|des\s+|du\s+|de\s+|une\s+|un\s+|l[\'’]\s*|d[\'’]\s*)/iu', '', $text);
        return trim($text, " \t.;:,!");
    }

    /**
     * Remplacements annoncés par la note : phrases « X est remplacé par
     * Y » (accords et auxiliaires usuels) et « Remplacement de X par Y ».
     * Une parenthèse finale est gardée comme remarque.
     *
     * @param array $lignes Résultat de Psc_Menu_Docx::read().
     * @return array Liste de array('source' => phrase, 'produit' => produit remplacé,
     *               'nouveau' => produit de remplacement, 'remarque' => texte de la parenthèse ou '').
     */
    public static function detecter(array $lignes) {
        $out = array();
        foreach ($lignes as $ligne) {
            $paragraphe = Psc_Menu_Xlsx::clean(is_array($ligne) ? ($ligne['t'] ?? '') : $ligne);
            $phrases = preg_split('/(?<=[.;!?])\s+(?=\p{Lu})/u', $paragraphe);
            foreach ((array) $phrases as $phrase) {
                $source = trim((string) $phrase);
                $base   = rtrim($source, " \t.;:,!");
                $remarque = '';
                if (preg_match('/^(.*\S)\s*\(([^()]*)\)$/u', $base, $m)) {
                    $base     = rtrim($m[1], " \t.;:,!");
                    $remarque = trim($m[2]);
                }
                if (preg_match('/^(.+?)\s+(?:(?:est|sont|a\s+été|ont\s+été|sera|seront|a\s+dû\s+être|ont\s+dû\s+être)\s+)?remplac[ée]e?s?\s+par\s+(.+)$/iu', $base, $m)
                    || preg_match('/^remplacement\s+(.+?)\s+par\s+(.+)$/iu', $base, $m)) {
                    $produit = self::sans_article($m[1]);
                    $nouveau = self::sans_article($m[2]);
                    if ($produit === '' || $nouveau === '') continue;
                    $out[] = array(
                        'source'   => mb_substr($source, 0, 400),
                        'produit'  => mb_substr($produit, 0, 200),
                        'nouveau'  => mb_substr($nouveau, 0, 200),
                        'remarque' => mb_substr($remarque, 0, 200),
                    );
                }
            }
        }
        return $out;
    }

    /**
     * Lit la note et l'enregistre, à confirmer. Le fichier lui-même n'est
     * pas conservé.
     *
     * @param string $path Fichier .docx (dépôt temporaire).
     * @param string $name Nom d'origine : il porte la date du jour concerné.
     * @return int|WP_Error Identifiant de la note.
     */
    public static function import($path, $name) {
        global $wpdb;

        if (strtolower(pathinfo((string) $name, PATHINFO_EXTENSION)) !== 'docx') {
            return new WP_Error('psc_docx_format', __('Ce fichier n’est pas un document Word (.docx).', 'periscolaire-registration'));
        }
        $lignes = Psc_Menu_Docx::read($path);
        if (is_wp_error($lignes)) return $lignes;

        $texte = array();
        foreach ($lignes as $ligne) $texte[] = (!empty($ligne['puce']) ? self::PUCE : '') . $ligne['t'];
        $texte = implode("\n", $texte);
        $date  = self::date_from_name($name);
        $now   = current_time('mysql');
        $user  = get_current_user_id();

        $inserted = $wpdb->insert(psc_table('menu_notes'), array(
            'fichier_nom'      => mb_substr(sanitize_text_field(wp_basename((string) $name)), 0, 191),
            'jour_date'        => $date !== '' ? $date : null,
            'texte'            => $texte,
            'changements_json' => wp_json_encode(array('detectes' => self::detecter($lignes), 'jour_cite' => self::jour_cite($texte), 'appliques' => array()), JSON_UNESCAPED_UNICODE),
            'statut'           => self::A_CONFIRMER,
            'created_by'       => $user ?: null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ), array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'));
        if (false === $inserted) {
            return new WP_Error('psc_note_stockage', __('La note n’a pas pu être enregistrée.', 'periscolaire-registration'));
        }
        return (int) $wpdb->insert_id;
    }

    /** Contenu de changements_json : array('detectes' => liste, 'jour_cite' => jour ou '', 'appliques' => liste). */
    public static function changements($row) {
        $data = json_decode((string) $row->changements_json, true);
        if (!is_array($data)) $data = array();
        return array(
            'detectes'  => isset($data['detectes']) && is_array($data['detectes']) ? array_values($data['detectes']) : array(),
            'jour_cite' => isset($data['jour_cite']) ? (string) $data['jour_cite'] : '',
            'appliques' => isset($data['appliques']) && is_array($data['appliques']) ? array_values($data['appliques']) : array(),
            'menu_envoye' => !empty($data['menu_envoye']),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Rapprochement avec le menu                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Menu visé par la note.
     *
     * @return array array('date' => Y-m-d ou '', 'semaine' => lundi ou '', 'jour' => jour de service ou '',
     *               'ouvert' => bool jour ouvert au calendrier, 'menu' => ligne psc_menus ou null,
     *               'lignes' => plats du jour, un par entrée)
     */
    public static function contexte($row) {
        $ctx  = array('date' => '', 'semaine' => '', 'jour' => '', 'ouvert' => false, 'menu' => null, 'lignes' => array());
        $date = psc_valid_date((string) $row->jour_date);
        if (!$date) return $ctx;
        $ctx['date']    = $date;
        $ctx['semaine'] = (string) psc_week_start($date);
        $iso = (int) gmdate('N', strtotime($date));
        $ctx['jour'] = isset(self::JOURS_ISO[$iso]) ? self::JOURS_ISO[$iso] : '';
        if ($ctx['jour'] === '' || $ctx['semaine'] === '') return $ctx;
        $open = psc_open_days($ctx['semaine']);
        $ctx['ouvert'] = isset($open[$ctx['jour']]);
        $ctx['menu']   = Psc_Menus::get_by_week($ctx['semaine']);
        if ($ctx['menu']) $ctx['lignes'] = self::lignes_du_jour($ctx['menu'], $ctx['jour']);
        return $ctx;
    }

    /** Plats d'un jour de menu, un par entrée (lignes vides écartées). */
    private static function lignes_du_jour($menu, $jour) {
        $lines = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) ($menu->$jour ?? '')) as $line) {
            $line = trim($line);
            if ($line !== '') $lines[] = $line;
        }
        return $lines;
    }

    /** Mots significatifs d'un nom de plat : sans accent, article, pluriel ni mention « bio ». */
    private static function mots($text) {
        $key   = (string) preg_replace('/[^a-z0-9]+/', ' ', Psc_Menu_Xlsx::key($text));
        $stop  = array('de', 'du', 'des', 'la', 'le', 'les', 'un', 'une', 'a', 'au', 'aux', 'et', 'en', 'd', 'l', 'bio');
        $words = array();
        foreach (explode(' ', $key) as $word) {
            if ($word === '' || in_array($word, $stop, true)) continue;
            if (strlen($word) > 3 && in_array(substr($word, -1), array('s', 'x'), true)) $word = substr($word, 0, -1);
            $words[$word] = true;
        }
        return array_keys($words);
    }

    /**
     * Ligne du menu qui correspond au produit remplacé, ou -1. Les deux
     * tiers des mots du produit au moins doivent s'y retrouver (tous s'il
     * n'en a qu'un ou deux) ; à égalité, la ligne la plus proche l'emporte.
     *
     * @param array  $lignes  Plats du jour.
     * @param string $produit Produit nommé par la note.
     * @return int Index dans $lignes.
     */
    public static function trouver(array $lignes, $produit) {
        $wanted = self::mots($produit);
        if (!$wanted) return -1;
        $bio  = (bool) preg_match('/\bbio\b/iu', (string) $produit);
        $best = -1;
        $best_score = null;
        foreach (array_values($lignes) as $i => $ligne) {
            $have    = self::mots($ligne);
            $matched = count(array_intersect($wanted, $have));
            if ($matched * 3 < count($wanted) * 2 || $matched === 0) continue;
            $agree = ($bio === (strpos((string) $ligne, '*') !== false)) ? 1 : 0;
            // Plus de mots retrouvés, puis moins de mots en trop, puis même mention bio.
            $score = array($matched * 1000 / count($wanted), -(count($have) - $matched), $agree);
            if ($best_score === null || $score > $best_score) {
                $best_score = $score;
                $best = $i;
            }
        }
        return $best;
    }

    /**
     * Texte proposé pour la ligne du menu : le produit de remplacement, en
     * forme de plat. « bio » devient l'étoile du logo AB ; « classique »,
     * par lequel le fournisseur dit seulement « non bio », est retiré.
     */
    public static function ligne_proposee($nouveau) {
        $text = (string) preg_replace('/\s+classiques?$/iu', '', Psc_Menu_Xlsx::clean($nouveau));
        if ($text === '') $text = Psc_Menu_Xlsx::clean($nouveau);
        $text = mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
        return Psc_Menu_Propositions::menu_line($text);
    }

    /**
     * Remplacements détectés, rapprochés du menu du jour.
     *
     * @param array|null $ctx Résultat de contexte() s'il est déjà calculé.
     * @return array Les entrées de detecter(), plus 'ligne' (index dans les plats du jour, -1 si
     *               introuvable) et 'texte' (nouvelle ligne proposée).
     */
    public static function propositions($row, $ctx = null) {
        if ($ctx === null) $ctx = self::contexte($row);
        $data = self::changements($row);
        $out  = array();
        $used = array();
        foreach ($data['detectes'] as $detecte) {
            $ligne = self::trouver($ctx['lignes'], (string) ($detecte['produit'] ?? ''));
            // Une ligne ne se propose qu'une fois.
            if ($ligne >= 0 && isset($used[$ligne])) $ligne = -1;
            if ($ligne >= 0) $used[$ligne] = true;
            $detecte['ligne'] = $ligne;
            $detecte['texte'] = self::ligne_proposee((string) ($detecte['nouveau'] ?? ''));
            $out[] = $detecte;
        }
        return $out;
    }

    /**
     * Menu du jour tel que la note le laisse : les lignes rapprochées sont
     * remplacées par le texte proposé, les autres reprises telles quelles.
     * C'est ce texte qui préremplit la zone de correction ; la mairie le
     * relit et le modifie librement avant d'enregistrer.
     *
     * @param array|null $ctx Résultat de contexte() s'il est déjà calculé.
     * @return array Plats du jour, un par entrée.
     */
    public static function menu_propose($row, $ctx = null) {
        if ($ctx === null) $ctx = self::contexte($row);
        $lines = $ctx['lignes'];
        foreach (self::propositions($row, $ctx) as $proposition) {
            if ($proposition['ligne'] >= 0 && isset($lines[$proposition['ligne']]) && $proposition['texte'] !== '') {
                $lines[$proposition['ligne']] = $proposition['texte'];
            }
        }
        return $lines;
    }

    /* ------------------------------------------------------------------ */
    /* Décisions de la mairie                                               */
    /* ------------------------------------------------------------------ */

    private static function modifiable($id) {
        $row = self::get($id);
        if (!$row) return new WP_Error('psc_note_introuvable', __('Cette note n’existe plus.', 'periscolaire-registration'));
        if ($row->statut !== self::A_CONFIRMER) {
            return new WP_Error('psc_note_verrouillee', __('Cette note a déjà été traitée.', 'periscolaire-registration'));
        }
        return $row;
    }

    /** Change le jour de repas concerné (date illisible dans le nom du fichier, ou erronée). */
    public static function set_day($id, $date) {
        global $wpdb;
        $row = self::modifiable($id);
        if (is_wp_error($row)) return $row;
        $date = psc_valid_date((string) $date);
        if (!$date) return new WP_Error('psc_note_jour', __('Indiquez le jour de repas concerné par la note.', 'periscolaire-registration'));
        $wpdb->update(psc_table('menu_notes'), array('jour_date' => $date, 'updated_at' => current_time('mysql')), array('id' => (int) $row->id), array('%s', '%s'), array('%d'));
        return true;
    }

    /**
     * Enregistre le menu du jour corrigé par la mairie. Le menu est réécrit
     * sur le seul jour concerné ; son envoi aux familles n'est ni refait ni
     * modifié.
     *
     * @param mixed $texte Menu du jour corrigé : une chaîne, un plat par ligne (toute autre valeur vaut un menu vide).
     * @return int|WP_Error Nombre de lignes modifiées, ajoutées ou retirées.
     */
    public static function apply($id, $texte) {
        global $wpdb;
        $row = self::modifiable($id);
        if (is_wp_error($row)) return $row;

        $ctx = self::contexte($row);
        if ($ctx['date'] === '') return new WP_Error('psc_note_jour', __('Indiquez le jour de repas concerné par la note.', 'periscolaire-registration'));
        if ($ctx['jour'] === '' || !$ctx['ouvert']) {
            return new WP_Error('psc_note_jour_ferme', __('Ce jour n’est pas un jour de cantine (mercredi, week-end, vacances ou fermeture).', 'periscolaire-registration'));
        }
        if (!$ctx['menu'] || !$ctx['lignes']) {
            return new WP_Error('psc_note_sans_menu', __('Aucun menu n’est enregistré pour ce jour : il n’y a rien à corriger.', 'periscolaire-registration'));
        }

        $lines = array();
        foreach (preg_split('/\r\n|\r|\n/', is_scalar($texte) ? (string) $texte : '') as $line) {
            $line = mb_substr(sanitize_text_field($line), 0, 200);
            if ($line !== '') $lines[] = $line;
        }
        if (!$lines) {
            return new WP_Error('psc_note_vide', __('Le menu du jour ne peut pas être vide.', 'periscolaire-registration'));
        }
        $appliques = array();
        for ($i = 0; $i < max(count($lines), count($ctx['lignes'])); $i++) {
            $avant = isset($ctx['lignes'][$i]) ? $ctx['lignes'][$i] : '';
            $apres = isset($lines[$i]) ? $lines[$i] : '';
            if ($avant !== $apres) $appliques[] = array('avant' => $avant, 'apres' => $apres);
        }
        if (!$appliques) {
            return new WP_Error('psc_note_rien', __('Le menu corrigé est identique au menu actuel : rien n’a été modifié.', 'periscolaire-registration'));
        }

        $menu  = $ctx['menu'];
        $jours = array();
        foreach (Psc_Menus::JOURS as $jour) $jours[$jour] = (string) $menu->$jour;
        $jours[$ctx['jour']] = implode("\n", $lines);
        $saved = Psc_Menus::save((int) $menu->id, $ctx['semaine'], $jours);
        if (is_wp_error($saved) || !$saved) {
            return new WP_Error('psc_note_stockage', __('Le menu n’a pas pu être corrigé.', 'periscolaire-registration'));
        }

        $data = self::changements($row);
        $data['appliques']   = $appliques;
        $data['menu_envoye'] = !empty($menu->sent_at);
        $now = current_time('mysql');
        $wpdb->update(
            psc_table('menu_notes'),
            array('changements_json' => wp_json_encode($data, JSON_UNESCAPED_UNICODE), 'statut' => self::APPLIQUEE, 'applied_at' => $now, 'updated_at' => $now),
            array('id' => (int) $row->id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );
        return count($appliques);
    }

    /** Classe la note sans rien changer au menu. */
    public static function ignore($id) {
        global $wpdb;
        $row = self::modifiable($id);
        if (is_wp_error($row)) return $row;
        $wpdb->update(psc_table('menu_notes'), array('statut' => self::IGNOREE, 'updated_at' => current_time('mysql')), array('id' => (int) $row->id), array('%s', '%s'), array('%d'));
        return true;
    }

    /** Supprime la note. Le menu, corrigé ou non, n'est pas touché. */
    public static function delete($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return false;
        return (bool) $wpdb->delete(psc_table('menu_notes'), array('id' => $id), array('%d'));
    }
}
