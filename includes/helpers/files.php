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
 * contrôle d'accès (Psc_Frontend::stream_assurance_file(),
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

/** Chemin absolu d'un fichier à partir de son chemin relatif stocké en base. */
function psc_private_path($rel_path) {
    return trailingslashit(psc_private_dir()) . ltrim((string) $rel_path, '/');
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
