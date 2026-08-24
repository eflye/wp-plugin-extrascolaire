<?php
/**
 * Sessions familles, durées de validité et jetons anti-CSRF.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/** Durée d'une session parent une fois le lien utilisé. */
function psc_session_ttl() {
    return (int) apply_filters('psc_session_ttl', 12 * HOUR_IN_SECONDS);
}

function psc_session_cookie_name() {
    return 'psc_session';
}

/**
 * Clé de stockage d'une session révoquée.
 *
 * Un cookie signé se vérifie sans rien consulter, ce qui fait sa légèreté
 * mais aussi sa faiblesse : supprimer le cookie du navigateur ne le rend
 * pas invalide. Quiconque en détient une copie — profil de navigateur
 * partagé, poste public, sauvegarde synchronisée — peut continuer à s'en
 * servir jusqu'à son expiration, malgré la déconnexion.
 *
 * On tient donc une courte liste des sessions explicitement fermées. Elle
 * ne grossit pas : chaque entrée expire d'elle-même en même temps que la
 * session qu'elle invalide.
 */
function psc_session_revoked_key($sid) {
    return 'psc_sess_rev_' . preg_replace('/[^a-f0-9]/', '', (string) $sid);
}

/** Marque une session comme révoquée jusqu'à sa date d'expiration. */
function psc_revoke_session($sid, $expires) {
    $remaining = (int) $expires - time();
    if ($sid === '' || $remaining <= 0) {
        return; // déjà expirée : la révoquer ne changerait rien
    }
    set_transient(psc_session_revoked_key($sid), 1, $remaining);
}

/** Vrai si la session a été fermée avant son expiration naturelle. */
function psc_session_is_revoked($sid) {
    return $sid !== '' && get_transient(psc_session_revoked_key($sid)) !== false;
}

/** Durée de validité d'un lien de connexion envoyé par e-mail (réglable, Réglages > Demandes d'inscription). */
function psc_login_link_ttl() {
    $minutes = (int) get_option('psc_login_link_ttl_minutes', 30);
    if ($minutes < 1) $minutes = 30;
    return (int) apply_filters('psc_login_link_ttl', $minutes * MINUTE_IN_SECONDS);
}

/**
 * Durée de validité d'un lien de confirmation par e-mail (adresse d'une
 * nouvelle demande, changement d'adresse e-mail depuis "Mon profil") —
 * réglable, Réglages > Demandes d'inscription. Distincte de
 * psc_login_link_ttl() : un lien de confirmation n'ouvre pas de session,
 * il valide juste une adresse, donc une durée plus longue est acceptable.
 */
function psc_email_confirmation_ttl() {
    $days = (int) get_option('psc_email_confirmation_ttl_days', 3);
    if ($days < 1) $days = 3;
    return (int) apply_filters('psc_email_confirmation_ttl', $days * DAY_IN_SECONDS);
}

/**
 * Jeton anti-CSRF lié à UNE famille.
 *
 * wp_create_nonce() ne convient pas ici : il dérive le jeton de
 * l'utilisateur WordPress courant, or les familles n'en sont pas. Pour tout
 * visiteur non connecté, l'uid vaut 0 — le nonce est donc identique pour
 * tout le monde, et un attaquant n'a qu'à charger la page publique pour
 * obtenir un jeton valide et le rejouer.
 *
 * On dérive donc le jeton de l'identifiant de la famille et d'un secret du
 * site : un tiers ne peut ni le calculer (il n'a pas le sel), ni le lire
 * (sans la session de la famille, la page ne lui en montre aucun).
 *
 * Découpage en tranches de 12 h, la précédente restant acceptée : un
 * formulaire ouvert longtemps reste valide jusqu'à 24 h, comme le
 * comportement natif de WordPress.
 */
function psc_parent_nonce($action, $parent_id, $tick_offset = 0) {
    $tick = (int) ceil(time() / (12 * HOUR_IN_SECONDS)) - (int) $tick_offset;
    return substr(hash_hmac(
        'sha256',
        $tick . '|' . $action . '|' . (int) $parent_id,
        wp_salt('psc_parent_nonce')
    ), 0, 24);
}

/** Vérifie un jeton du portail (comparaison à temps constant, deux tranches acceptées). */
function psc_verify_parent_nonce($action, $parent_id, $nonce) {
    if (!$nonce || !$parent_id) return false;
    $nonce = (string) $nonce;
    foreach (array(0, 1) as $offset) {
        if (hash_equals(psc_parent_nonce($action, $parent_id, $offset), $nonce)) {
            return true;
        }
    }
    return false;
}

/**
 * Champ caché à placer dans chaque formulaire du portail — équivalent de
 * wp_nonce_field() pour une famille. Sans session ouverte, aucun jeton
 * n'est émis : le formulaire ne peut alors pas être soumis, ce qui est le
 * comportement voulu.
 */
function psc_parent_nonce_field($action) {
    $parent = Psc_Parents::current();
    if (!$parent) return;
    printf(
        '<input type="hidden" name="psc_nonce" value="%s">',
        esc_attr(psc_parent_nonce($action, $parent->id))
    );
}
