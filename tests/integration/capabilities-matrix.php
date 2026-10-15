<?php
/**
 * wp eval-file tests/integration/capabilities-matrix.php
 *
 * Matrice d'habilitations (P1-02) : chaque endpoint admin du plugin
 * (admin_post_psc_* et wp_ajax_psc_* sans variante anonyme) est appelé
 * sous des comptes restreints, avec un nonce VALIDE pour son action — seul
 * le contrôle de capacité peut donc l'arrêter. Un endpoint qui redirige
 * ou rend la main au lieu de refuser est un défaut.
 *
 * Sortie : une ligne JSON {"ok":true,...} ou {"ok":false,"failures":[...]}.
 */
if (!defined('WP_CLI') || !WP_CLI) return;

class Psc_Probe_Stop extends Exception {
    public $status;
    public function __construct($message, $status) {
        parent::__construct((string) $message);
        $this->status = (int) $status;
    }
}

$failures = array();
$checks = 0;

// Les rôles se resynchronisent au chargement quand ROLES_VERSION change ;
// on vérifie l'état obtenu, pas l'appel.
$grant = array_merge(array(psc_manage_cap(), 'psc_impersonate_family'), psc_domain_capability_keys());
$admin_role = get_role('administrator');
$editor_role = get_role('editor');
foreach ($grant as $cap) {
    $checks++;
    if (!$admin_role || !$admin_role->has_cap($cap)) $failures[] = "administrateur sans $cap";
    $checks++;
    if ($editor_role && $editor_role->has_cap($cap)) $failures[] = "éditeur conserve $cap";
}

$make_user = function ($login, $role, array $caps) {
    $existing = get_user_by('login', $login);
    if ($existing) wp_delete_user($existing->ID);
    $id = wp_insert_user(array('user_login' => $login, 'user_pass' => wp_generate_password(24), 'user_email' => $login . '@example.invalid', 'role' => $role));
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $user = get_user_by('id', $id);
    foreach ($caps as $cap) $user->add_cap($cap);
    return (int) $id;
};

require_once ABSPATH . 'wp-admin/includes/user.php';
$users = array(
    'editeur'     => $make_user('psc-probe-editeur', 'editor', array()),
    'facturation' => $make_user('psc-probe-facturation', 'subscriber', array('psc_manage_billing')),
    'familles'    => $make_user('psc-probe-familles', 'subscriber', array('psc_manage_families')),
    'presence'    => $make_user('psc-probe-presence', 'subscriber', array('psc_manage_presence')),
    'config'      => $make_user('psc-probe-config', 'subscriber', array('psc_manage_config')),
);
$user_for_cap = array(
    'psc_manage_billing'  => 'facturation',
    'psc_manage_families' => 'familles',
    'psc_manage_presence' => 'presence',
    'psc_manage_config'   => 'config',
);

// Endpoints admin du plugin : ceux qui ont une variante anonyme (nopriv)
// servent les familles ou les intervenants, avec leur propre contrôle.
global $wp_filter;
$endpoints = array();
foreach (array_keys($wp_filter) as $hook) {
    if (preg_match('/^admin_post_(psc_.+)$/', $hook, $m) && !isset($wp_filter['admin_post_nopriv_' . $m[1]])) {
        $endpoints[] = array('hook' => $hook, 'action' => $m[1], 'ajax' => false);
    } elseif (preg_match('/^wp_ajax_(psc_.+)$/', $hook, $m) && !isset($wp_filter['wp_ajax_nopriv_' . $m[1]])) {
        $endpoints[] = array('hook' => $hook, 'action' => $m[1], 'ajax' => true);
    }
}
if (count($endpoints) < 20) $failures[] = 'trop peu d’endpoints découverts (' . count($endpoints) . ')';

$ajax_mode = false;
add_filter('wp_doing_ajax', function () use (&$ajax_mode) { return $ajax_mode; });
$die = function () {
    return function ($message, $title = '', $args = array()) {
        $status = is_array($args) && isset($args['response']) ? $args['response'] : 500;
        if ($message instanceof WP_Error) $message = $message->get_error_message();
        throw new Psc_Probe_Stop(is_scalar($message) ? $message : wp_json_encode($message), $status ?: 200);
    };
};
foreach (array('wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler') as $filter) add_filter($filter, $die, 99);
add_filter('wp_redirect', function ($location) { throw new Psc_Probe_Stop('redirection ' . $location, 302); }, 99);
// wp_send_json_error() fixe le code HTTP avant wp_die() : on le relève ici.
$last_status = null;
add_filter('status_header', function ($header, $code) use (&$last_status) { $last_status = (int) $code; return $header; }, 10, 2);

/**
 * Appelle l'endpoint sous l'utilisateur donné. Renvoie null si refusé
 * (403, ou erreur JSON 403), sinon une description de ce qui s'est passé.
 */
$call = function ($user_id, array $ep) use (&$ajax_mode, &$last_status) {
    wp_set_current_user($user_id);
    $nonce = wp_create_nonce($ep['action']);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = $_POST = $_REQUEST = array('action' => $ep['action'], '_wpnonce' => $nonce, 'nonce' => $nonce, '_ajax_nonce' => $nonce);
    $ajax_mode = $ep['ajax'];
    $last_status = null;
    ob_start();
    try {
        do_action($ep['hook']);
        $out = ob_get_clean();
        return 'a rendu la main' . ($out !== '' ? ' (' . substr(trim(wp_strip_all_tags($out)), 0, 80) . ')' : '');
    } catch (Psc_Probe_Stop $stop) {
        $out = ob_get_clean();
        $status = $last_status ?: $stop->status;
        if ($status === 403) return null;
        $json = json_decode($out, true);
        if (is_array($json) && isset($json['success']) && $json['success'] === false && $last_status === 403) return null;
        return 'statut ' . $status . ' : ' . substr(trim(wp_strip_all_tags($stop->getMessage() . ' ' . $out)), 0, 100);
    } catch (Throwable $e) {
        ob_end_clean();
        return 'erreur ' . get_class($e) . ' : ' . substr($e->getMessage(), 0, 100);
    }
};

// L'éditeur n'atteint aucun endpoint admin du plugin.
foreach ($endpoints as $ep) {
    $checks++;
    $result = $call($users['editeur'], $ep);
    if ($result !== null) $failures[] = 'éditeur → ' . $ep['hook'] . ' : ' . $result;
}

// Hors de leur domaine, les habilitations partielles sont refusées ; les
// allergies exigent psc_view_health en plus du dossier famille.
$outside = array(
    'facturation' => array('admin_post_psc_approve_request', 'admin_post_psc_save_settings', 'admin_post_psc_admin_update_registrations'),
    'familles'    => array('admin_post_psc_save_settings', 'admin_post_psc_download_sepa'),
);
$by_hook = array();
foreach ($endpoints as $ep) $by_hook[$ep['hook']] = $ep;
foreach ($outside as $who => $hooks) {
    foreach ($hooks as $hook) {
        $checks++;
        if (!isset($by_hook[$hook])) { $failures[] = "endpoint attendu absent : $hook"; continue; }
        $result = $call($users[$who], $by_hook[$hook]);
        if ($result !== null) $failures[] = "$who → $hook : $result";
    }
}

// PRA-02 — table explicite : chaque action passée à guard() dans le code
// figure dans psc_capability_map(), et la table ne garde aucune entrée
// morte. Une action inconnue est refusée à tous (do_not_allow).
$guarded = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PSC_PATH . 'includes', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (substr($file->getFilename(), -4) !== '.php') continue;
    if (preg_match_all("/self::guard\\('([a-z0-9_]+)'\\)/", (string) file_get_contents($file->getPathname()), $m)) {
        foreach ($m[1] as $a) $guarded[$a] = true;
    }
}
$map = psc_capability_map();
$checks++;
if (count($guarded) < 40) $failures[] = 'trop peu d’appels à guard() découverts (' . count($guarded) . ')';
foreach (array_keys($guarded) as $a) {
    $checks++;
    if (!isset($map[$a])) $failures[] = "action gardée absente de psc_capability_map() : $a";
}
foreach ($map as $a => $cap) {
    $checks++;
    if (!isset($guarded[$a])) $failures[] = "entrée morte dans psc_capability_map() : $a";
    $checks++;
    if (!isset($user_for_cap[$cap])) $failures[] = "capacité inattendue pour $a : $cap";
}
$checks++;
if (psc_required_capability('psc_action_inconnue') !== 'do_not_allow') $failures[] = 'action inconnue non refusée par défaut';
$checks++;
if (current_user_can(psc_required_capability('psc_action_inconnue'))) $failures[] = 'do_not_allow accordé';
// Mappages explicitement demandés par l'audit (IBAN en clair, paiements).
foreach (array('psc_download_pain008' => 'psc_manage_billing', 'psc_payment_received' => 'psc_manage_billing', 'psc_download_general' => 'psc_manage_billing', 'psc_admin_update_registrations' => 'psc_manage_presence', 'psc_close_school_day' => 'psc_manage_config', 'psc_add_school_holiday' => 'psc_manage_config', 'psc_confirm_promotion' => 'psc_manage_config') as $a => $cap) {
    $checks++;
    if (psc_required_capability($a) !== $cap) $failures[] = "$a devrait exiger $cap";
}

// Accès légitime : l'habilitation du domaine franchit le contrôle de
// capacité et n'est arrêtée que par le nonce (volontairement invalide, pour
// qu'aucune action ne s'exécute) ; une autre habilitation est refusée dès
// le contrôle de capacité.
$denied_msg = esc_html__('Vous n\'avez pas les droits nécessaires.', 'periscolaire-registration');
$probe_guard = function ($user_id, $action) use (&$last_status) {
    wp_set_current_user($user_id);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = $_POST = $_REQUEST = array('action' => $action, '_wpnonce' => 'invalide');
    $last_status = null;
    ob_start();
    try {
        do_action('admin_post_' . $action);
        ob_end_clean();
        return 'a rendu la main';
    } catch (Psc_Probe_Stop $stop) {
        ob_end_clean();
        return $stop->getMessage();
    } catch (Throwable $e) {
        ob_end_clean();
        return 'erreur ' . get_class($e);
    }
};
foreach ($map as $a => $cap) {
    if (!isset($user_for_cap[$cap])) continue;
    $checks++;
    if (!has_action('admin_post_' . $a)) { $failures[] = "endpoint attendu absent : admin_post_$a"; continue; }
    $own = $probe_guard($users[$user_for_cap[$cap]], $a);
    if ($own === $denied_msg || $own === 'a rendu la main' || strpos($own, 'erreur ') === 0) {
        $failures[] = "accès légitime refusé : {$user_for_cap[$cap]} → $a ($own)";
    }
    foreach ($user_for_cap as $other_cap => $other) {
        if ($other_cap === $cap) continue;
        $checks++;
        if ($probe_guard($users[$other], $a) !== $denied_msg) $failures[] = "$other → $a non refusé par la capacité";
    }
}

wp_set_current_user(0);
foreach ($users as $id) wp_delete_user($id);

echo wp_json_encode(array('ok' => !$failures, 'checks' => $checks, 'endpoints' => count($endpoints), 'failures' => $failures)) . "\n";
