<?php
/**
 * Stockage des documents déposés par les familles, hors racine web.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Répertoire de stockage des documents déposés par les familles.
 *
 * Ces fichiers concernent des mineurs (attestations d'assurance nominatives)
 * ou portent des données financières (factures) : ils ne doivent JAMAIS être
 * servis directement par le serveur web, mais uniquement streamés après
 * contrôle d'accès (Psc_Assurances::stream(),
 * Psc_Invoices::download()).
 *
 * wp-content/uploads/ est systématiquement exposé en HTTP — y déposer ces
 * documents les rend téléchargeables par quiconque devine l'URL, et les noms
 * sont séquentiels (child-12.pdf, facture-7.pdf). On sort donc du dossier des
 * médias, avec un repli si wp-content/ n'est pas inscriptible (cas fréquent
 * en hébergement mutualisé, où seul uploads/ l'est) — dans ce cas la
 * protection repose sur les fichiers .htaccess/web.config posés par
 * psc_ensure_private_dir(), et l'écran d'administration vérifie que le
 * dossier est bien injoignable (cf. Psc_Admin::private_dir_exposed()).
 *
 * Les chemins stockés en base restent relatifs à ce répertoire ("periscolaire/…"),
 * ce qui rend le déplacement transparent pour les données existantes.
 */
function psc_private_dir() {
    // Emplacement explicite, déclaré dans wp-config.php. Seule solution
    // pleinement sûre en hébergement mutualisé, où l'on ne peut pas modifier
    // la configuration du serveur web : viser un dossier situé HORS de la
    // racine web le rend inatteignable par construction, quel que soit le
    // traitement réservé aux .htaccess. Sur un mutualisé OVH par exemple, la
    // racine web est .../www/, et le dossier parent convient :
    //     define('PSC_PRIVATE_DIR', dirname(ABSPATH) . '/psc-private');
    if (defined('PSC_PRIVATE_DIR') && PSC_PRIVATE_DIR) {
        return apply_filters('psc_private_dir', rtrim(PSC_PRIVATE_DIR, '/\\'));
    }

    // Un processus root (WP-CLI lancé par l'hébergeur, cron système) voit
    // tout inscriptible : son avis sur l'emplacement ne vaut pas celui du
    // serveur web. Il reprend donc l'emplacement déjà retenu, sans quoi il
    // créerait un dossier hors racine lui appartenant, que le serveur web
    // retiendrait ensuite sans pouvoir y écrire — et Psc_Installer y
    // déménagerait les documents existants.
    if (psc_running_as_root()) {
        $known = (string) get_option('psc_private_dir_path', '');
        if ($known !== '' && is_dir($known)) {
            return apply_filters('psc_private_dir', $known);
        }
    }

    // Défaut hors racine HTTP : ABSPATH pointe sur la racine WordPress,
    // son parent est donc inatteignable par une URL même si le serveur
    // ignore les fichiers .htaccess.
    $dir = dirname(rtrim(ABSPATH, '/\\')) . '/psc-private';

    // Repli : le dossier hors racine n'est pas utilisable — existant mais
    // non inscriptible, ou impossible à créer (et jamais créé par root,
    // cf. ci-dessus). Dans ce cas, uploads/ reste acceptable uniquement
    // avec les garde-fous serveur et l'alerte d'administration qui
    // vérifie l'accès HTTP.
    $usable = is_dir($dir)
        ? wp_is_writable($dir)
        : (!psc_running_as_root() && wp_is_writable(dirname($dir)));
    if (!$usable) {
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'psc-private';
    }

    return apply_filters('psc_private_dir', $dir);
}

/**
 * Vrai si le processus PHP courant tourne en root (typiquement WP-CLI
 * avec --allow-root). Faux quand l'information n'est pas disponible.
 */
function psc_running_as_root() {
    return function_exists('posix_geteuid') && posix_geteuid() === 0;
}

/**
 * Chemin absolu d'un fichier à partir de son chemin relatif stocké en
 * base — contraint à rester SOUS le répertoire privé.
 *
 * Les chemins relatifs viennent de la base (champs assurance_file_path,
 * pdf_path…), écrite par le plugin lui-même : aucun ne devrait contenir
 * de "..". Les contrôler ici plutôt qu'à chaque site d'appel protège
 * aussi tout appel futur, et ferme la porte à un chemin falsifié
 * (import, autre extension écrivant dans les tables, compromission) :
 * la lecture d'un justificatif de mineur ne doit jamais pouvoir devenir
 * celle d'un fichier arbitraire du serveur, wp-config.php en tête.
 *
 * La cible n'existe pas forcément encore (répertoire sur le point d'être
 * créé) : les segments sont d'abord réduits lexicalement — ce qui retire
 * tout ".." restant — puis le chemin est revérifié par realpath() quand
 * il existe, pour couvrir un lien symbolique glissé dans l'arborescence.
 *
 * @return string Chemin absolu, ou chaîne vide si le chemin sort du
 *                répertoire privé (les appelants testent file_exists(),
 *                qui échoue alors proprement).
 */
function psc_private_path($rel_path) {
    $root = wp_normalize_path(psc_private_dir());
    $rel  = str_replace('\\', '/', ltrim((string) $rel_path, '/'));

    // Réduction lexicale : "a/b/../../c" devient "c". Un segment ".."
    // qui remonterait au-dessus de la racine est rejeté d'office.
    $segs = array();
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') {
            if (!$segs) return '';
            array_pop($segs);
            continue;
        }
        $segs[] = $seg;
    }
    $abs = $segs ? $root . '/' . implode('/', $segs) : $root;

    // Cible déjà sur disque : realpath() résout aussi les liens
    // symboliques — une cible qui s'échappe du répertoire privé est
    // rejetée. (Si le répertoire privé lui-même n'existe pas encore,
    // la réduction lexicale ci-dessus a déjà fait tout le travail.)
    $resolved = realpath($abs);
    if ($resolved !== false) {
        $resolved_root = realpath(psc_private_dir());
        if ($resolved_root && strpos($resolved . DIRECTORY_SEPARATOR, $resolved_root . DIRECTORY_SEPARATOR) !== 0) {
            return '';
        }
    }

    return $abs;
}

/**
 * Crée le répertoire privé s'il manque et y (re)pose les garde-fous
 * serveur : refus d'accès Apache et IIS, plus un index.php neutre contre
 * le listing. Ces fichiers sont une défense en profondeur — nginx ne lit
 * pas .htaccess, d'où la vérification active côté administration.
 */
function psc_ensure_private_dir() {
    $dir = psc_private_dir();
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        return false;
    }

    // Le dossier peut exister sans être inscriptible (droits repris par
    // l'hébergeur, restauration de sauvegarde…). Écrire quand même y
    // déclencherait un warning PHP émis AVANT les en-têtes HTTP, ce qui
    // casserait toutes les redirections du site — on renonce silencieusement,
    // l'alerte d'administration prend le relais si l'accès est réellement ouvert.
    if (!wp_is_writable($dir)) {
        return true;
    }

    $guards = array(
        '.htaccess'  => "# Documents personnels : accès direct interdit.\n"
                      . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                      . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n",
        'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n"
                      . "    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n"
                      . "  </system.webServer>\n</configuration>\n",
        'index.php'  => "<?php // Silence.\n",
        // Fichier témoin : sert uniquement à vérifier, depuis le navigateur
        // d'un administrateur, que le dossier n'est PAS servi en HTTP
        // (cf. Psc_Admin::notice_private_dir_exposed()).
        'psc-probe.txt' => "psc-probe-" . wp_generate_password(20, false) . "\n",
    );
    foreach ($guards as $name => $contents) {
        $path = trailingslashit($dir) . $name;
        if (!file_exists($path)) {
            // @ : même raison que ci-dessus — un échec d'écriture ne doit
            // jamais produire de sortie avant les en-têtes.
            @file_put_contents($path, $contents); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
        }
    }

    return true;
}

/**
 * URL publique correspondant au répertoire privé, ou null s'il est hors de
 * la racine web (auquel cas il n'y a rien à vérifier).
 */
function psc_private_dir_url() {
    $dir = wp_normalize_path(psc_private_dir());

    $upload = wp_upload_dir();
    $up_dir = wp_normalize_path(trailingslashit($upload['basedir']));
    if (strpos($dir, $up_dir) === 0) {
        return trailingslashit($upload['baseurl']) . ltrim(substr($dir, strlen($up_dir)), '/');
    }

    $wp_dir = wp_normalize_path(trailingslashit(WP_CONTENT_DIR));
    if (strpos($dir, $wp_dir) === 0) {
        return trailingslashit(content_url()) . ltrim(substr($dir, strlen($wp_dir)), '/');
    }

    return null; // hors racine web : inatteignable par construction
}

/**
 * Vérifie qu'un document déposé est bien ce que son nom annonce : un PDF,
 * un JPEG ou un PNG réel, entier et sans contenu actif visible.
 *
 * Le nom (et donc l'extension) vient du navigateur, comme la taille
 * déclarée : un fichier renommé « .pdf » passait tel quel. On juge ici le
 * contenu lui-même, lu sur le disque :
 *  - taille réelle non nulle et sous le plafond ;
 *  - signature binaire du format (en-tête, et marqueur de fin — un fichier
 *    tronqué n'est pas un document) ;
 *  - type détecté par fileinfo quand l'extension PHP est présente ;
 *  - extension du nom cohérente avec le type détecté ;
 *  - images : décodables (getimagesize) et de dimensions non nulles ;
 *  - PDF : traité comme contenu non fiable — refusé s'il déclare du
 *    JavaScript, une action de lancement ou des fichiers embarqués (les
 *    formulaires XFA restent admis : des attestations d'assureurs en
 *    portent). La
 *    recherche porte sur la structure en clair : un marqueur caché dans un
 *    flux compressé lui échappe, d'où les défenses qui restent en aval
 *    (stockage privé, contrôle d'accès, nosniff).
 *
 * Un analyseur antivirus éventuel de l'hébergement se branche sur le
 * filtre psc_document_scan (renvoyer false refuse le fichier).
 *
 * @param string $path     Fichier à contrôler (upload temporaire ou fichier en attente).
 * @param string $name     Nom annoncé, dont l'extension doit correspondre au contenu.
 * @param int    $max_size Taille maximale en octets.
 * @return string|true true, ou un code : 'partial' (vide), 'too_large',
 *                     'invalid_type' (contenu faux, incohérent ou malformé), 'failed'.
 */
function psc_validate_document_file($path, $name, $max_size) {
    if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) return 'failed';

    clearstatcache(true, $path);
    $size = (int) filesize($path);
    if ($size <= 0) return 'partial';
    if ($size > (int) $max_size) return 'too_large';

    $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
    $types = array('pdf' => 'pdf', 'jpg' => 'jpeg', 'jpeg' => 'jpeg', 'png' => 'png');
    if (!isset($types[$ext])) return 'invalid_type';
    $expected = $types[$ext];

    $handle = @fopen($path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
    if (!$handle) return 'failed';
    $head = (string) fread($handle, 1024); // phpcs:ignore WordPress.WP.AlternativeFunctions
    fseek($handle, max(0, $size - 1024));
    $tail = (string) fread($handle, 1024); // phpcs:ignore WordPress.WP.AlternativeFunctions
    fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions

    if ($expected === 'pdf') {
        // L'en-tête peut suivre quelques octets parasites (tolérés par la
        // norme dans le premier kilo-octet) ; la fin doit porter %%EOF.
        if (strpos($head, '%PDF-') === false || strpos($tail, '%%EOF') === false) return 'invalid_type';
        $body = (string) file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if (preg_match('~/(JavaScript|JS|Launch|EmbeddedFile)\b~', $body)) return 'invalid_type';
    } elseif ($expected === 'jpeg') {
        if (strncmp($head, "\xFF\xD8\xFF", 3) !== 0) return 'invalid_type';
    } else {
        if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) !== 0 || strpos($tail, 'IEND') === false) return 'invalid_type';
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $path) : '';
        if ($finfo) finfo_close($finfo);
        $mimes = array('pdf' => array('application/pdf'), 'jpeg' => array('image/jpeg', 'image/pjpeg'), 'png' => array('image/png'));
        if ($mime !== '' && !in_array($mime, $mimes[$expected], true)) return 'invalid_type';
    }

    if ($expected !== 'pdf') {
        $info = @getimagesize($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $image_type = $expected === 'jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG;
        if (!$info || (int) $info[2] !== $image_type || empty($info[0]) || empty($info[1])) return 'invalid_type';
    }

    if (!apply_filters('psc_document_scan', true, $path, $expected)) return 'invalid_type';

    return true;
}

/**
 * Journal des téléchargements depuis le répertoire privé.
 *
 * Documents de mineurs ou données financières : la contrepartie du
 * contrôle d'accès est la traçabilité — savoir qui a consulté quoi et
 * quand. Les deux seuls points de service du répertoire privé
 * (Psc_Assurances::stream(), Psc_Invoices::download()) journalisent ici,
 * et pas à chaque site d'appel : toute voie de lecture ajoutée plus tard
 * sera vue de ce point, ou n'existera pas.
 *
 * Route vers le journal d'audit unifié (Psc_Audit, catégorie documents) —
 * qui identifie déjà l'agent, la famille ou la consultation en cours plus
 * précisément que le "qui" texte libre d'origine. L'écriture directe dans
 * journal-acces.log n'est plus faite ici : Psc_Audit::log() y bascule
 * elle-même, au même format, si l'insertion en base échoue (cf. sa
 * documentation) — un seul repli à maintenir, pas deux.
 *
 * @param string $kind     Nature du document (« assurance », « facture », « factures », « prelevements », « conversation_attachment »).
 * @param string $rel_path Chemin relatif stocké en base.
 */
function psc_log_download($kind, $rel_path) {
    $actions = array(
        'assurance'    => array('action' => 'assurance.telechargement', 'objet' => 'assurance'),
        'facture'      => array('action' => 'facture.telechargement', 'objet' => 'facture'),
        'factures'     => array('action' => 'facture.export', 'objet' => null),
        'prelevements' => array('action' => 'sepa.export', 'objet' => null),
        'conversation_attachment' => array('action' => 'conversation.piece_jointe_telechargement', 'objet' => 'conversation'),
    );
    $entry = isset($actions[$kind]) ? $actions[$kind] : array('action' => 'inconnu.action', 'objet' => null);

    if (class_exists('Psc_Audit')) {
        Psc_Audit::log($entry['action'], array(
            'objet_type' => $entry['objet'],
            'meta'       => array('type' => (string) $kind, 'fichier' => (string) $rel_path),
        ));
    }
}
