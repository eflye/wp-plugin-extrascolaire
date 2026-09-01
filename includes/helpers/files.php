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

    $dir = WP_CONTENT_DIR . '/psc-private';

    // Repli : wp-content/ non inscriptible et dossier pas déjà créé.
    if (!is_dir($dir) && !wp_is_writable(WP_CONTENT_DIR)) {
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'psc-private';
    }

    return apply_filters('psc_private_dir', $dir);
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
 * Journal des téléchargements depuis le répertoire privé.
 *
 * Documents de mineurs ou données financières : la contrepartie du
 * contrôle d'accès est la traçabilité — savoir qui a consulté quoi et
 * quand. Les deux seuls points de service du répertoire privé
 * (Psc_Assurances::stream(), Psc_Invoices::download()) journalisent ici,
 * et pas à chaque site d'appel : toute voie de lecture ajoutée plus tard
 * sera vue de ce point, ou n'existera pas.
 *
 * Une ligne JSON par téléchargement dans journal-acces.log, déposé dans
 * le répertoire privé lui-même — mêmes garde-fous .htaccess/web.config
 * que les documents journalisés.
 *
 * Un échec d'écriture est silencieux (même règle que
 * psc_ensure_private_dir) : ne jamais empêcher un téléchargement
 * légitime parce que le journal ne peut pas écrire.
 *
 * @param string $kind     Nature du document (« assurance », « facture »).
 * @param string $rel_path Chemin relatif stocké en base.
 */
function psc_log_download($kind, $rel_path) {
    // Identité du demandeur : agent connecté au backoffice, sinon famille
    // connectée au portail. Les deux flux de lecture sont gardés en amont —
    // si aucun des deux n'est identifiable, la ligne le dit : un
    // téléchargement anonyme est précisément ce que le journal doit
    // révéler, jamais ce qu'il doit taire.
    $qui = 'inconnu';
    if (is_user_logged_in()) {
        $qui = 'agent:' . wp_get_current_user()->user_login;
    } elseif (class_exists('Psc_Parents') && ($parent = Psc_Parents::current())) {
        $qui = 'famille:' . (int) $parent->id . ':' . $parent->email;
    }

    $entry = wp_json_encode(array(
        'horodatage' => current_time('mysql'),
        'qui'        => $qui,
        'type'       => (string) $kind,
        'fichier'    => (string) $rel_path,
        'ip'         => psc_client_ip(),
    )) . "\n";

    $log_path = psc_private_path('journal-acces.log');
    if ($log_path) {
        @file_put_contents($log_path, $entry, FILE_APPEND | LOCK_EX); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
    }
}
