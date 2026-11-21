<?php
if (!defined('ABSPATH')) exit;

/**
 * Lecture et remplissage du fichier de menus du fournisseur de cantine
 * (.xlsx), sans dépendance : ZipArchive + DOM.
 *
 * Le fichier porte une feuille par semaine. Chaque feuille aligne les cinq
 * jours en colonnes (« LUNDI » … « VENDREDI », suivis de deux colonnes de
 * réponse « Ad » et « Enf ») et les catégories en lignes, annoncées dans
 * la colonne de gauche (« Entrées », « Options », « Plats Protidiques »,
 * « Végétarien », « Accompagnements », « Fromage / Laitage »,
 * « Desserts »). La mairie répond en posant une croix dans la colonne
 * « Enf », en face du plat retenu.
 *
 * Rien n'est supposé de la position des lignes ni des colonnes : la
 * structure est retrouvée par ses intitulés, de sorte qu'une ligne ajoutée
 * par le fournisseur (troisième entrée, semaine à thème) ne décale rien.
 *
 * Le remplissage ne réécrit que les feuilles concernées, cellule par
 * cellule, dans une copie de l'original : mise en page, images et
 * pictogrammes du fournisseur sont conservés tels quels.
 */
class Psc_Menu_Xlsx {

    /** Taille maximale du fichier déposé (cf. Psc_Ooxml). */
    const MAX_FILE_SIZE = Psc_Ooxml::MAX_FILE_SIZE;

    const MAX_SHEETS = 60;

    /** Nombre de lignes explorées sous l'en-tête des jours. */
    const MAX_ROWS = 80;

    const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Catégories d'un repas, dans l'ordre du service. */
    const CATEGORIES = array('entree', 'plat', 'accompagnement', 'fromage', 'dessert');

    const JOURS = array('lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi');

    const MOIS = array(
        'janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6,
        'juillet' => 7, 'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12,
    );

    /* ------------------------------------------------------------------ */
    /* Lecture                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Lit les propositions du fournisseur.
     *
     * @param string $path Fichier .xlsx.
     * @return array|WP_Error array(
     *     'semaines' => liste triée de array(
     *         'semaine' => lundi (Y-m-d), 'feuille' => nom de la feuille,
     *         'part' => chemin de la feuille dans l'archive,
     *         'etab' => cellule « Etablissement » ('' si absente), 'etab_texte' => son texte,
     *         'jours' => array(jour => array('enf' => colonne de réponse, 'plats' => array(
     *             catégorie => liste de array('r' => ligne, 't' => libellé,
     *                 'g' => 'p' proposition | 'v' végétarien | 'o' option permanente))))),
     *     'ignorees' => liste de array('feuille' => nom, 'motif' => code))
     */
    public static function read($path) {
        $zip = self::open($path);
        if (is_wp_error($zip)) return $zip;

        $sheets = self::sheet_list($zip);
        if (is_wp_error($sheets)) {
            $zip->close();
            return $sheets;
        }
        $strings = self::shared_strings($zip);
        if (is_wp_error($strings)) {
            $zip->close();
            return $strings;
        }

        $semaines = array();
        $ignorees = array();
        foreach ($sheets as $sheet) {
            $xml = self::part($zip, $sheet['part']);
            $doc = is_wp_error($xml) ? $xml : self::load($xml);
            if (is_wp_error($doc)) {
                $ignorees[] = array('feuille' => $sheet['name'], 'motif' => 'illisible');
                continue;
            }
            $parsed = self::parse_sheet(self::cells($doc, $strings));
            if (is_string($parsed)) {
                $ignorees[] = array('feuille' => $sheet['name'], 'motif' => $parsed);
                continue;
            }
            $parsed['feuille'] = $sheet['name'];
            $parsed['part']    = $sheet['part'];
            // La semaine se lit d'abord dans le nom de la feuille (toujours
            // complet), à défaut dans le titre de la feuille.
            $parsed['dates'] = self::parse_week($sheet['name']);
            if (!$parsed['dates'] || $parsed['dates']['y2'] === null) {
                $from_title = self::parse_week($parsed['titre']);
                if ($from_title && (!$parsed['dates'] || $from_title['y2'] !== null)) $parsed['dates'] = $from_title;
            }
            $semaines[] = $parsed;
        }
        $zip->close();

        $out  = array();
        $seen = array();
        foreach (self::resolve_weeks($semaines) as $semaine) {
            if (!$semaine['semaine']) {
                $ignorees[] = array('feuille' => $semaine['feuille'], 'motif' => 'semaine_inconnue');
                continue;
            }
            if (isset($seen[$semaine['semaine']])) {
                $ignorees[] = array('feuille' => $semaine['feuille'], 'motif' => 'semaine_en_double');
                continue;
            }
            $seen[$semaine['semaine']] = true;
            unset($semaine['dates'], $semaine['titre']);
            $out[] = $semaine;
        }
        if (!$out) {
            return new WP_Error('psc_xlsx_aucune_semaine', __('Aucune semaine de menus n’a été reconnue dans ce fichier.', 'periscolaire-registration'));
        }
        usort($out, function ($a, $b) { return strcmp($a['semaine'], $b['semaine']); });

        return array('semaines' => $out, 'ignorees' => $ignorees);
    }

    /**
     * Ouvre l'archive (cf. Psc_Ooxml::open()), erreurs traduites pour le classeur.
     *
     * @return ZipArchive|WP_Error
     */
    private static function open($path) {
        $zip = Psc_Ooxml::open($path);
        return is_wp_error($zip) ? self::erreur($zip) : $zip;
    }

    /**
     * Contenu d'une partie de l'archive (cf. Psc_Ooxml::part()).
     *
     * @return string|WP_Error
     */
    private static function part(ZipArchive $zip, $name) {
        $xml = Psc_Ooxml::part($zip, $name);
        return is_wp_error($xml) ? self::erreur($xml) : $xml;
    }

    /**
     * Charge une partie XML (cf. Psc_Ooxml::load()).
     *
     * @return DOMDocument|WP_Error
     */
    private static function load($xml) {
        $doc = Psc_Ooxml::load($xml);
        return is_wp_error($doc) ? self::erreur($doc) : $doc;
    }

    /** Erreur de Psc_Ooxml, sous le code et le message du classeur. */
    private static function erreur($error) {
        switch (Psc_Ooxml::reason($error)) {
            case 'extension':
                return new WP_Error('psc_xlsx_extension', __('Les extensions PHP Zip et DOM sont requises pour lire ce fichier.', 'periscolaire-registration'));
            case 'illisible':
                return new WP_Error('psc_xlsx_illisible', __('Le fichier n’a pas pu être lu.', 'periscolaire-registration'));
            case 'taille':
                return new WP_Error('psc_xlsx_taille', __('Le fichier est vide ou dépasse 10 Mo.', 'periscolaire-registration'));
        }
        return new WP_Error('psc_xlsx_format', __('Ce fichier n’est pas un classeur Excel (.xlsx).', 'periscolaire-registration'));
    }

    /**
     * Feuilles du classeur, dans l'ordre des onglets.
     *
     * @return array|WP_Error Liste de array('name' => nom, 'part' => chemin dans l'archive).
     */
    private static function sheet_list(ZipArchive $zip) {
        $workbook = self::part($zip, 'xl/workbook.xml');
        $rels     = is_wp_error($workbook) ? $workbook : self::part($zip, 'xl/_rels/workbook.xml.rels');
        if (is_wp_error($rels)) return $rels;
        $wb_doc  = self::load($workbook);
        $rel_doc = is_wp_error($wb_doc) ? $wb_doc : self::load($rels);
        if (is_wp_error($rel_doc)) return $rel_doc;

        $targets = array();
        foreach ($rel_doc->getElementsByTagName('Relationship') as $rel) {
            $target = (string) $rel->getAttribute('Target');
            // Cible relative au dossier xl/, ou absolue dans l'archive.
            $targets[(string) $rel->getAttribute('Id')] = strpos($target, '/') === 0 ? ltrim($target, '/') : 'xl/' . $target;
        }

        $sheets = array();
        foreach ($wb_doc->getElementsByTagNameNS(self::NS_MAIN, 'sheet') as $sheet) {
            $rid = (string) $sheet->getAttributeNS(self::NS_REL, 'id');
            if (!isset($targets[$rid]) || strpos($targets[$rid], '..') !== false) continue;
            $sheets[] = array('name' => self::clean((string) $sheet->getAttribute('name')), 'part' => $targets[$rid]);
            if (count($sheets) >= self::MAX_SHEETS) break;
        }
        if (!$sheets) {
            return new WP_Error('psc_xlsx_format', __('Ce fichier n’est pas un classeur Excel (.xlsx).', 'periscolaire-registration'));
        }
        return $sheets;
    }

    /**
     * Table des textes partagés (absente d'un classeur sans texte).
     *
     * @return array|WP_Error
     */
    private static function shared_strings(ZipArchive $zip) {
        if ($zip->statName('xl/sharedStrings.xml') === false) return array();
        $xml = self::part($zip, 'xl/sharedStrings.xml');
        $doc = is_wp_error($xml) ? $xml : self::load($xml);
        if (is_wp_error($doc)) return $doc;
        $strings = array();
        foreach ($doc->getElementsByTagNameNS(self::NS_MAIN, 'si') as $si) {
            $strings[] = self::rich_text($si);
        }
        return $strings;
    }

    /** Texte d'un élément <si> ou <is> : <t> directs et <r><t>, sans les guides phonétiques. */
    private static function rich_text(DOMElement $node) {
        $text = '';
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            if ($child->localName === 't') {
                $text .= $child->textContent;
            } elseif ($child->localName === 'r') {
                foreach ($child->childNodes as $run) {
                    if ($run instanceof DOMElement && $run->localName === 't') $text .= $run->textContent;
                }
            }
        }
        return $text;
    }

    /**
     * Cellules non vides d'une feuille.
     *
     * @return array array(ligne => array(numéro de colonne => texte)).
     */
    private static function cells(DOMDocument $doc, array $strings) {
        $cells = array();
        foreach ($doc->getElementsByTagNameNS(self::NS_MAIN, 'c') as $c) {
            $ref = self::split_ref((string) $c->getAttribute('r'));
            if (!$ref) continue;
            $type  = (string) $c->getAttribute('t');
            $value = '';
            if ($type === 'inlineStr') {
                foreach ($c->childNodes as $child) {
                    if ($child instanceof DOMElement && $child->localName === 'is') $value = self::rich_text($child);
                }
            } else {
                foreach ($c->childNodes as $child) {
                    if ($child instanceof DOMElement && $child->localName === 'v') $value = $child->textContent;
                }
                if ($type === 's') $value = isset($strings[(int) $value]) ? $strings[(int) $value] : '';
            }
            $value = self::clean($value);
            if ($value !== '') $cells[$ref[1]][$ref[0]] = $value;
        }
        ksort($cells);
        return $cells;
    }

    /**
     * Retrouve la structure d'une feuille par ses intitulés.
     *
     * @return array|string Feuille lue, ou le motif pour lequel elle est ignorée.
     */
    private static function parse_sheet(array $cells) {
        // En-tête des jours : la ligne qui porte « LUNDI ».
        $header = 0;
        foreach ($cells as $row => $cols) {
            foreach ($cols as $text) {
                if (self::key($text) === 'lundi') { $header = (int) $row; break 2; }
            }
        }
        if (!$header) return 'jours_introuvables';

        $day_cols = array();
        foreach ($cells[$header] as $col => $text) {
            $key = self::key($text);
            if (in_array($key, self::JOURS, true) && !isset($day_cols[$key])) $day_cols[$key] = (int) $col;
        }
        asort($day_cols);
        $jours = array();
        $order = array_keys($day_cols);
        foreach ($order as $i => $jour) {
            $from = $day_cols[$jour];
            $to   = isset($order[$i + 1]) ? $day_cols[$order[$i + 1]] : PHP_INT_MAX;
            $enf  = 0;
            foreach ($cells[$header] as $col => $text) {
                if ($col > $from && $col < $to && self::key($text) === 'enf') { $enf = (int) $col; break; }
            }
            // Sans colonne « Enf », la réponse ne pourrait pas être portée
            // dans le fichier : la feuille n'est pas au format attendu.
            if (!$enf) return 'colonne_enf_introuvable';
            $jours[$jour] = array('col' => $from, 'enf' => self::col_letters($enf), 'plats' => array());
        }
        $first_day_col = min($day_cols);

        // Au-dessus de l'en-tête : titre de la semaine et cellule « Etablissement ».
        $titre = '';
        $etab = '';
        $etab_texte = '';
        foreach ($cells as $row => $cols) {
            if ($row >= $header) break;
            foreach ($cols as $col => $text) {
                $key = self::key($text);
                if ($titre === '' && strpos($key, 'semaine du') === 0) $titre = $text;
                if ($etab === '' && strpos($key, 'etablissement') === 0) {
                    $etab = self::col_letters($col) . $row;
                    $etab_texte = $text;
                }
            }
        }

        $categorie = null;
        $groupe    = 'p';
        $found     = false;
        $last_row  = max(array_keys($cells));
        for ($row = $header + 1; $row <= min($last_row, $header + self::MAX_ROWS); $row++) {
            $cols = isset($cells[$row]) ? $cells[$row] : array();

            // Intitulé de catégorie, dans les colonnes à gauche du lundi.
            $vegetarien = false;
            foreach ($cols as $col => $text) {
                if ($col >= $first_day_col) continue;
                $label = self::category_of($text);
                if (!$label) continue;
                if ($label === 'option') {
                    $groupe = 'o';
                } elseif ($label === 'vegetarien') {
                    $categorie  = 'plat';
                    $vegetarien = true;
                } else {
                    $categorie = $label;
                    $groupe    = 'p';
                }
            }

            $row_has_dish = false;
            foreach ($jours as $jour => $info) {
                if (!isset($cols[$info['col']])) continue;
                $text = $cols[$info['col']];
                // La légende, sous le tableau, occupe les mêmes colonnes.
                if (strpos(self::key($text), 'suggestion de notre') === 0) break 2;
                if (!$categorie) continue;
                $row_has_dish = true;
                $found = true;
                $jours[$jour]['plats'][$categorie][] = array(
                    'r' => $row,
                    't' => function_exists('mb_substr') ? mb_substr($text, 0, 200) : substr($text, 0, 200),
                    'g' => $vegetarien ? 'v' : $groupe,
                );
            }
            // L'intitulé « Végétarien » ne couvre que sa ligne : celles qui
            // suivent dans le même bloc (jambon blanc) sont des substitutions
            // permanentes, comme les options.
            if ($vegetarien) $groupe = 'o';
            // Le dernier bloc (desserts) n'est borné par aucun intitulé :
            // il s'arrête à la première ligne vide.
            if ($categorie === 'dessert' && !$row_has_dish && self::any_dish($jours, 'dessert')) break;
        }
        if (!$found) return 'aucun_plat';

        foreach ($jours as $jour => $info) unset($jours[$jour]['col']);

        return array('titre' => $titre, 'etab' => $etab, 'etab_texte' => $etab_texte, 'jours' => $jours);
    }

    private static function any_dish(array $jours, $categorie) {
        foreach ($jours as $info) {
            if (!empty($info['plats'][$categorie])) return true;
        }
        return false;
    }

    /** Catégorie annoncée par un intitulé de la colonne de gauche, sinon ''. */
    private static function category_of($text) {
        $key = self::key($text);
        if (strpos($key, 'entree') === 0) return 'entree';
        if (strpos($key, 'option') === 0) return 'option';
        if (strpos($key, 'vegeta') === 0) return 'vegetarien';
        if (strpos($key, 'plat') === 0) return 'plat';
        if (strpos($key, 'accompagnement') === 0) return 'accompagnement';
        if (strpos($key, 'fromage') === 0 || strpos($key, 'laitage') === 0) return 'fromage';
        if (strpos($key, 'dessert') === 0) return 'dessert';
        return '';
    }

    /**
     * Dates d'un intitulé de semaine : « Du 11 au 15 janvier 2027 », « Du
     * 1er au 5 février 2027 », « Semaine du 18 au 22 janvier (S3) », « Du
     * 29 mars au 2 avril 2027 ».
     *
     * @return array|false array('d1','m1','y1','d2','m2','y2'), m1/y1/y2 à null si absents.
     */
    public static function parse_week($text) {
        $key = self::key($text);
        if (!preg_match('/(\d{1,2})(?:er)?(?:\s+([a-z]+))?(?:\s+(\d{4}))?\s+au\s+(\d{1,2})(?:er)?\s+([a-z]+)(?:\s+(\d{4}))?/', $key, $m)) {
            return false;
        }
        if (!isset(self::MOIS[$m[5]])) return false;
        $m1 = ($m[2] !== '' && isset(self::MOIS[$m[2]])) ? self::MOIS[$m[2]] : null;
        return array(
            'd1' => (int) $m[1], 'm1' => $m1, 'y1' => $m[3] !== '' ? (int) $m[3] : null,
            'd2' => (int) $m[4], 'm2' => self::MOIS[$m[5]], 'y2' => isset($m[6]) ? (int) $m[6] : null,
        );
    }

    /**
     * Lundi de chaque feuille. Une feuille dont l'intitulé n'a pas d'année
     * prend celle qui la place au plus près d'une feuille datée du même
     * fichier.
     */
    private static function resolve_weeks(array $semaines) {
        $known = array();
        foreach ($semaines as $i => $semaine) {
            $semaines[$i]['semaine'] = '';
            $dates = $semaine['dates'];
            if (!$dates || $dates['y2'] === null) continue;
            $monday = self::monday_of($dates, $dates['y2']);
            if ($monday) {
                $semaines[$i]['semaine'] = $monday;
                $known[] = strtotime($monday);
            }
        }
        if (!$known) return $semaines;
        foreach ($semaines as $i => $semaine) {
            $dates = $semaine['dates'];
            if ($semaine['semaine'] !== '' || !$dates) continue;
            $best = '';
            $best_gap = PHP_INT_MAX;
            $ref_year = (int) gmdate('Y', $known[0]);
            foreach (array($ref_year - 1, $ref_year, $ref_year + 1) as $year) {
                $monday = self::monday_of($dates, $year);
                if (!$monday) continue;
                foreach ($known as $ts) {
                    $gap = abs(strtotime($monday) - $ts);
                    if ($gap < $best_gap) { $best_gap = $gap; $best = $monday; }
                }
            }
            $semaines[$i]['semaine'] = $best;
        }
        return $semaines;
    }

    /** Lundi de la semaine décrite, pour une année de fin donnée ; false si les dates sont incohérentes. */
    private static function monday_of(array $dates, $year_end) {
        $m1 = $dates['m1'] !== null ? $dates['m1'] : $dates['m2'];
        $y1 = $dates['y1'] !== null ? $dates['y1'] : ($m1 > $dates['m2'] ? $year_end - 1 : $year_end);
        if (!checkdate($m1, $dates['d1'], $y1) || !checkdate($dates['m2'], $dates['d2'], $year_end)) return false;
        $start = sprintf('%04d-%02d-%02d', $y1, $m1, $dates['d1']);
        $end   = sprintf('%04d-%02d-%02d', $year_end, $dates['m2'], $dates['d2']);
        $monday = psc_week_start($start);
        // Une semaine de menus tient dans une même semaine civile.
        if (!$monday || $end < $start || psc_week_start($end) !== $monday) return false;
        return $monday;
    }

    /* ------------------------------------------------------------------ */
    /* Remplissage                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Écrit des textes dans une copie du fichier d'origine.
     *
     * @param string $source Fichier du fournisseur.
     * @param string $target Copie à produire (écrasée).
     * @param array  $sheets array(chemin de la feuille => array(cellule 'D7' => texte)).
     * @return true|WP_Error
     */
    public static function fill($source, $target, array $sheets) {
        $probe = self::open($source);
        if (is_wp_error($probe)) return $probe;
        $probe->close();

        if (!copy($source, $target)) {
            return new WP_Error('psc_xlsx_ecriture', __('Le fichier rempli n’a pas pu être produit.', 'periscolaire-registration'));
        }
        $zip = new ZipArchive();
        if ($zip->open($target) !== true) {
            return new WP_Error('psc_xlsx_ecriture', __('Le fichier rempli n’a pas pu être produit.', 'periscolaire-registration'));
        }
        foreach ($sheets as $part => $values) {
            if (!$values) continue;
            $xml = self::part($zip, (string) $part);
            $doc = is_wp_error($xml) ? $xml : self::load($xml);
            if (is_wp_error($doc)) {
                $zip->close();
                return $doc;
            }
            foreach ($values as $ref => $text) {
                if (!self::set_cell($doc, (string) $ref, (string) $text)) {
                    $zip->close();
                    return new WP_Error('psc_xlsx_ecriture', __('Le fichier rempli n’a pas pu être produit.', 'periscolaire-registration'));
                }
            }
            $zip->deleteName((string) $part);
            if (!$zip->addFromString((string) $part, (string) $doc->saveXML())) {
                $zip->close();
                return new WP_Error('psc_xlsx_ecriture', __('Le fichier rempli n’a pas pu être produit.', 'periscolaire-registration'));
            }
        }
        if (!$zip->close()) {
            return new WP_Error('psc_xlsx_ecriture', __('Le fichier rempli n’a pas pu être produit.', 'periscolaire-registration'));
        }
        return true;
    }

    /**
     * Pose un texte dans une cellule, en conservant son style. Le texte est
     * écrit dans la cellule elle-même (inlineStr) : la table des textes
     * partagés du fournisseur n'est pas touchée.
     */
    private static function set_cell(DOMDocument $doc, $ref, $text) {
        $target = self::split_ref($ref);
        $data   = $doc->getElementsByTagNameNS(self::NS_MAIN, 'sheetData')->item(0);
        if (!$target || !$data instanceof DOMElement) return false;
        list($col, $row_num) = $target;

        $row = null;
        $before = null;
        foreach ($data->childNodes as $node) {
            if (!$node instanceof DOMElement || $node->localName !== 'row') continue;
            $n = (int) $node->getAttribute('r');
            if ($n === $row_num) { $row = $node; break; }
            if ($n > $row_num) { $before = $node; break; }
        }
        if (!$row) {
            $row = $doc->createElementNS(self::NS_MAIN, 'row');
            $row->setAttribute('r', (string) $row_num);
            $data->insertBefore($row, $before);
        }

        $cell = null;
        $before = null;
        foreach ($row->childNodes as $node) {
            if (!$node instanceof DOMElement || $node->localName !== 'c') continue;
            $node_ref = self::split_ref((string) $node->getAttribute('r'));
            if (!$node_ref) continue;
            if ($node_ref[0] === $col) { $cell = $node; break; }
            if ($node_ref[0] > $col) { $before = $node; break; }
        }
        if (!$cell) {
            $cell = $doc->createElementNS(self::NS_MAIN, 'c');
            $cell->setAttribute('r', self::col_letters($col) . $row_num);
            $row->insertBefore($cell, $before);
        }
        while ($cell->firstChild) $cell->removeChild($cell->firstChild);
        $cell->setAttribute('t', 'inlineStr');
        $is = $doc->createElementNS(self::NS_MAIN, 'is');
        $t  = $doc->createElementNS(self::NS_MAIN, 't');
        $t->setAttribute('xml:space', 'preserve');
        $t->appendChild($doc->createTextNode($text));
        $is->appendChild($t);
        $cell->appendChild($is);
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Outils                                                               */
    /* ------------------------------------------------------------------ */

    /** Texte d'une cellule : espaces insécables et répétés réduits, extrémités nettoyées. */
    public static function clean($text) {
        $text = str_replace(array("\xC2\xA0", "\r", "\n", "\t"), ' ', (string) $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Forme de comparaison d'un intitulé : minuscules, sans accent ni ligature. */
    public static function key($text) {
        $text = self::clean($text);
        $text = str_replace(array('Œ', 'œ', 'Æ', 'æ'), array('OE', 'oe', 'AE', 'ae'), $text);
        $text = function_exists('remove_accents') ? remove_accents($text) : $text;
        return strtolower($text);
    }

    /** « D7 » → array(4, 7) ; false si la référence est invalide. */
    public static function split_ref($ref) {
        if (!preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,6})$/', (string) $ref, $m)) return false;
        $col = 0;
        foreach (str_split($m[1]) as $letter) $col = $col * 26 + (ord($letter) - 64);
        return array($col, (int) $m[2]);
    }

    /** 4 → « D ». */
    public static function col_letters($col) {
        $col = (int) $col;
        $letters = '';
        while ($col > 0) {
            $mod = ($col - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $col = (int) (($col - $mod - 1) / 26);
        }
        return $letters;
    }
}
