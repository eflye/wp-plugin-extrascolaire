<?php
/**
 * Interface d'administration : fragments partagés par les templates backoffice.
 *
 * Critère d'appartenance : tout ce qui rend un élément d'interface
 * répété dans plusieurs écrans de l'administration. La première pièce
 * est la notice WordPress — le motif « <div class="notice notice-X
 * is-dismissible"><p>…</p></div> » était copié-collé dans une douzaine
 * de templates, sous deux formes : une carte code de retour -> [type,
 * texte] (une par écran) et des if/elseif écrits en dur. Un seul point
 * de rendu évite que l'échappement, le type ou la dismissibilité
 * divergent à la prochaine copie.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Notice d'administration WordPress.
 *
 * @param string $text        Message — échappé ici ; ne jamais passer du HTML.
 * @param string $type        success | error | warning | info.
 * @param bool   $dismissible Bandeau fermable (true : le cas général) ou collant.
 * @param string $testid      Suffixe d'ancre de test « data-testid="notice-…" »
 *                            (l'écran de commande fournisseur s'en sert).
 */
function psc_admin_notice($text, $type = 'success', $dismissible = true, $testid = '') {
    printf(
        '<div class="notice notice-%1$s%2$s"%3$s><p>%4$s</p></div>',
        esc_attr($type),
        $dismissible ? ' is-dismissible' : '',
        $testid !== '' ? ' data-testid="notice-' . esc_attr($testid) . '"' : '',
        esc_html($text)
    );
}

/**
 * Notice choisie dans la carte de messages de l'écran (code de retour ->
 * [type, texte]). Rien si le code est vide ou inconnu : un retour qui
 * n'a pas de message associé ne s'affiche pas — comme avant la
 * factorisation, chaque template gardant sa carte en tête de fichier.
 *
 * @param array  $map    Carte du template (variable locale $psc_notices).
 * @param string $msg    Code reçu en paramètre d'URL ($psc_msg).
 * @param string $testid Suffixe data-testid optionnel, cf. psc_admin_notice().
 */
function psc_admin_notice_map($map, $msg, $testid = '') {
    if (empty($msg) || !isset($map[$msg])) return;
    list($type, $text) = $map[$msg];
    psc_admin_notice($text, $type, true, $testid);
}
