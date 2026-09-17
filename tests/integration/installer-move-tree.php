<?php
/** Sonde P1-17 : un conflit de contenu ne doit jamais supprimer la source. */
if (!defined('ABSPATH')) exit;
$src = trailingslashit(sys_get_temp_dir()) . 'psc-move-src-' . wp_generate_uuid4();
$dst = trailingslashit(sys_get_temp_dir()) . 'psc-move-dst-' . wp_generate_uuid4();
wp_mkdir_p($src); wp_mkdir_p($dst);
file_put_contents($src . '/document.txt', 'source');
file_put_contents($dst . '/document.txt', 'autre contenu');
$method = new ReflectionMethod('Psc_Installer', 'move_tree');
$method->setAccessible(true);
$result = $method->invoke(null, $src, $dst);
$source_preserved = file_exists($src . '/document.txt');
@unlink($src . '/document.txt'); @rmdir($src); @unlink($dst . '/document.txt'); @rmdir($dst);
if ($result !== false || !$source_preserved) {
    fwrite(STDERR, "FAIL: conflit de migration non signalé ou source supprimée\n"); exit(1);
}
echo "OK : conflit de migration conservé\n";
