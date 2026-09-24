<?php
/**
 * wp eval-file tests/integration/private-storage-receipt.php
 *
 * Confinement du répertoire privé. psc_private_path() est le seul rempart
 * entre un chemin relatif venu de la base et le système de fichiers : sa
 * réduction lexicale et sa vérification realpath() n'étaient couvertes par
 * aucun test, alors qu'elles décident si la lecture d'un justificatif peut
 * devenir celle de wp-config.php.
 *
 * Ne conclut RIEN sur l'hébergement distant : l'inaccessibilité HTTP réelle
 * se constate depuis l'extérieur (cf. documentation/installation/fiche-recette-p1.md).
 */
if (!defined('WP_CLI') || !WP_CLI) return;

$checks = 0;
$assert = function ($condition, $message) use (&$checks) {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$root = wp_normalize_path(psc_private_dir());
$assert($root !== '', 'Le répertoire privé n’est pas résolu.');
$assert(psc_ensure_private_dir(), 'Le répertoire privé n’a pas pu être préparé.');

$created = array();
$inside = function ($path) use ($root) {
    $path = wp_normalize_path((string) $path);
    return $path !== '' && strpos($path . '/', rtrim($root, '/') . '/') === 0;
};

try {
    /* 1. Chemins légitimes : conservés, et sous la racine. */
    $assert($inside(psc_private_path('periscolaire/justificatif.pdf')),
        'Un chemin normal sort du répertoire privé.');
    $assert(psc_private_path('') === $root, 'Un chemin vide ne retombe pas sur la racine.');
    $assert(psc_private_path('./a/./b.pdf') === $root . '/a/b.pdf',
        'Les segments neutres ne sont pas réduits.');
    $assert(psc_private_path('a/b/../c.pdf') === $root . '/a/c.pdf',
        'Une remontée interne légitime est mal réduite.');

    /* 2. Évasion lexicale : refus par chaîne vide, jamais un chemin hors racine. */
    foreach (array(
        '../wp-config.php',
        '../../wp-config.php',
        'periscolaire/../../../etc/passwd',
        'a/../../b.pdf',
        '..\\..\\wp-config.php',
    ) as $attempt) {
        $assert(psc_private_path($attempt) === '',
            sprintf('Le chemin traversant « %s » n’est pas refusé.', $attempt));
    }

    /* La défense ne repose pas sur un retrait de « ../ » dans la chaîne :
       la charge classique « ....// », qui survit à un filtre naïf, n'est ici
       qu'un nom de dossier inoffensif, sous la racine. */
    $doubled = psc_private_path('....//....//wp-config.php');
    $assert($inside($doubled) && substr($doubled, -13) === 'wp-config.php',
        'La charge « ....// » n’est pas neutralisée sous la racine privée.');

    /* Un chemin absolu est traité comme relatif à la racine privée : il ne
       doit jamais désigner le fichier système du même nom. */
    $absolute = psc_private_path('/etc/passwd');
    $assert($absolute !== '/etc/passwd' && $inside($absolute),
        'Un chemin absolu échappe au répertoire privé.');

    /* 3. Évasion par lien symbolique : seule realpath() peut l'attraper,
          la réduction lexicale ne voit rien d'anormal. */
    $outside_dir = wp_normalize_path(get_temp_dir()) . 'psc-sonde-hors-privé';
    if (!is_dir($outside_dir)) {
        wp_mkdir_p($outside_dir);
        $created[] = array('dir', $outside_dir);
    }
    $outside_file = $outside_dir . '/secret.txt';
    file_put_contents($outside_file, 'contenu hors répertoire privé');
    $created[] = array('file', $outside_file);

    $link = $root . '/sonde-evasion';
    if (!file_exists($link) && function_exists('symlink') && @symlink($outside_dir, $link)) {
        $created[] = array('link', $link);
        $assert(psc_private_path('sonde-evasion/secret.txt') === '',
            'Un lien symbolique sortant n’est pas refusé.');
    } else {
        WP_CLI::warning('Lien symbolique non créé : branche realpath() non couverte sur cet hôte.');
    }

    /* 4. Garde-fous serveur réellement posés. */
    $guards = array('.htaccess', 'web.config', 'index.php', 'psc-probe.txt');
    foreach ($guards as $guard) {
        $assert(file_exists($root . '/' . $guard),
            sprintf('Le garde-fou %s est absent du répertoire privé.', $guard));
    }
    $htaccess = (string) file_get_contents($root . '/.htaccess');
    $assert(strpos($htaccess, 'Require all denied') !== false && strpos($htaccess, 'Deny from all') !== false,
        'Le .htaccess ne refuse pas l’accès sur les deux familles de modules Apache.');
    $assert(trim((string) file_get_contents($root . '/index.php')) === '<?php // Silence.',
        'L’index.php de protection contre le listing a changé de contenu.');

    /* 5. Le fichier témoin ne porte qu'un jeton aléatoire : le mécanisme de
          vérification ne doit rien révéler du contenu du dossier. */
    $probe = trim((string) file_get_contents($root . '/psc-probe.txt'));
    $assert(preg_match('/^psc-probe-[A-Za-z0-9]{20}$/', $probe) === 1,
        'Le fichier témoin ne contient pas uniquement un jeton aléatoire.');

    /* 6. Idempotence : rejouer la préparation ne régénère pas le jeton,
          sans quoi la vérification d'exposition changerait de cible à
          chaque chargement de page. */
    $assert(psc_ensure_private_dir(), 'La seconde préparation du répertoire échoue.');
    $assert(trim((string) file_get_contents($root . '/psc-probe.txt')) === $probe,
        'Le jeton témoin est régénéré à chaque préparation.');

    /* 7. Résolution d'URL : null hors racine web, sinon une URL qui pointe
          bien dans le dossier concerné. */
    $url = psc_private_dir_url();
    $upload = wp_upload_dir();
    $under_web = strpos($root, wp_normalize_path(trailingslashit($upload['basedir']))) === 0
        || strpos($root, wp_normalize_path(trailingslashit(WP_CONTENT_DIR))) === 0;
    if ($under_web) {
        $assert(is_string($url) && $url !== '', 'Un répertoire sous la racine web n’expose pas d’URL à vérifier.');
    } else {
        $assert($url === null, 'Un répertoire hors racine web annonce une URL publique.');
    }

    /* La branche « sous uploads » doit rester vérifiable même quand
       l'installation courante range le dossier hors racine. */
    $forced = wp_normalize_path(trailingslashit($upload['basedir'])) . 'psc-private-sonde';
    $force = function () use ($forced) { return $forced; };
    add_filter('psc_private_dir', $force);
    $forced_url = psc_private_dir_url();
    remove_filter('psc_private_dir', $force);
    $assert(is_string($forced_url) && strpos($forced_url, $upload['baseurl']) === 0,
        'Un répertoire privé placé sous uploads ne résout pas vers une URL vérifiable.');

    WP_CLI::log(sprintf('OK : %d vérifications de confinement du répertoire privé (%s).', $checks, $root));
    WP_CLI::log('Rappel : l’inaccessibilité HTTP réelle ne se constate que depuis l’extérieur du serveur.');
} finally {
    foreach (array_reverse($created) as $item) {
        list($type, $path) = $item;
        if ($type === 'link' && is_link($path)) unlink($path);
        elseif ($type === 'file' && file_exists($path)) unlink($path);
        elseif ($type === 'dir' && is_dir($path)) rmdir($path);
    }
}
