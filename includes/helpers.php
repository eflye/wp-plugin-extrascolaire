<?php
if (!defined('ABSPATH')) exit;

/**
 * Capacité requise pour accéder au backoffice périscolaire.
 * Filtrable : permet de donner l'accès à un rôle dédié sans passer par
 * un compte Administrateur complet.
 */
function psc_manage_cap() {
    return apply_filters('psc_manage_capability', 'manage_options');
}

function psc_user_can_manage() {
    return current_user_can(psc_manage_cap());
}

/**
 * Nom complet d'une table du plugin (avec préfixe WP).
 */
function psc_table($name) {
    global $wpdb;
    return $wpdb->prefix . 'psc_' . $name;
}

/**
 * Codes de service autorisés. Toute valeur hors de cette liste est rejetée.
 */
function psc_allowed_services() {
    return array('GM', 'CANT', 'GS', 'FORF');
}

/**
 * Récupère et nettoie une valeur de $_POST.
 * wp_unslash() est indispensable : WordPress applique addslashes() sur les
 * superglobales, sans quoi "O'Brien" est enregistré "O\'Brien".
 */
function psc_post($key, $default = '') {
    if (!isset($_POST[$key])) return $default;
    return sanitize_text_field(wp_unslash($_POST[$key]));
}

function psc_get_int($key, $default = 0) {
    return isset($_GET[$key]) ? absint($_GET[$key]) : $default;
}

function psc_post_int($key, $default = 0) {
    return isset($_POST[$key]) ? absint($_POST[$key]) : $default;
}

/**
 * Valide strictement une date au format Y-m-d.
 * Empêche à la fois les injections et les erreurs fatales de DateTime.
 */
function psc_valid_date($date) {
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        return false;
    }
    $year = (int) substr($date, 0, 4);
    if ($year < 2000 || $year > 2100) {
        return false;
    }
    return $date;
}

/**
 * Lundi de la semaine contenant $date (une "semaine" de menu de cantine
 * commence toujours un lundi, quelle que soit la date saisie par l'admin).
 */
function psc_week_start($date) {
    $date = psc_valid_date($date);
    if (!$date) return false;
    $d = new DateTime($date);
    $dow = (int) $d->format('N'); // 1 (lundi) .. 7 (dimanche)
    if ($dow > 1) {
        $d->modify('-' . ($dow - 1) . ' days');
    }
    return $d->format('Y-m-d');
}

/**
 * Nombre maximum de jours qu'un trimestre peut couvrir.
 * Garde-fou contre une saisie erronée qui générerait des millions de lignes.
 */
function psc_max_trimestre_days() {
    return 400;
}

/**
 * Nombre maximum d'enfants qu'un même compte parent peut créer.
 * Empêche qu'un compte compromis ou un script ne remplisse la table.
 */
function psc_max_children_per_user() {
    return apply_filters('psc_max_children_per_user', 10);
}

/**
 * Date de Pâques (algorithme de Gauss/Meeus), sans dépendance à l'extension calendar.
 */
function psc_easter_date($year) {
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

/**
 * Liste des jours fériés français (métropole) pour une année donnée, format Y-m-d.
 */
function psc_french_holidays($year) {
    $year = (int) $year;
    $easter = psc_easter_date($year);
    $holidays = array();
    $holidays[] = "$year-01-01";
    $holidays[] = (clone $easter)->modify('+1 day')->format('Y-m-d');   // Lundi de Paques
    $holidays[] = "$year-05-01";
    $holidays[] = "$year-05-08";
    $holidays[] = (clone $easter)->modify('+39 days')->format('Y-m-d'); // Ascension
    $holidays[] = (clone $easter)->modify('+50 days')->format('Y-m-d'); // Lundi de Pentecote
    $holidays[] = "$year-07-14";
    $holidays[] = "$year-08-15";
    $holidays[] = "$year-11-01";
    $holidays[] = "$year-11-11";
    $holidays[] = "$year-12-25";
    return $holidays;
}

function psc_is_holiday($date_str) {
    $year = (int) substr($date_str, 0, 4);
    return in_array($date_str, psc_french_holidays($year), true);
}

function psc_is_weekend($date_str) {
    $dow = (int) date('N', strtotime($date_str)); // 1 (lundi) .. 7 (dimanche)
    return $dow >= 6;
}

/**
 * Le mercredi n'est pas un jour de service (pas de périscolaire ni de cantine).
 */
function psc_is_wednesday($date_str) {
    return (int) date('N', strtotime($date_str)) === 3;
}

/**
 * Vacances scolaires de la zone C (Créteil, Montpellier, Paris, Toulouse,
 * Versailles) — chargées par la mairie depuis le calendrier officiel du
 * ministère de l'Éducation nationale (Périscolaire > Calendrier scolaire).
 * Voir Psc_School_Calendar.
 */
function psc_school_vacation_label($date_str) {
    return Psc_School_Calendar::label($date_str);
}

function psc_is_school_vacation($date_str) {
    return Psc_School_Calendar::is_closed($date_str);
}

/**
 * Un jour est-il un jour d'école (donc de service périscolaire/cantine
 * potentiel) ? Ne dépend d'aucun trimestre en base : utilisable même avant
 * la création d'un trimestre (ex : widget menu public).
 */
function psc_is_school_day($date_str) {
    if (psc_is_weekend($date_str) || psc_is_wednesday($date_str)) return false;
    if (psc_is_school_vacation($date_str)) return false;
    if (psc_is_holiday($date_str)) return false;
    return true;
}

/**
 * Décalage en jours depuis le lundi pour chacun des 4 jours de service
 * (lundi/mardi/jeudi/vendredi) — le mercredi n'est jamais un jour de
 * service périscolaire/cantine. Partagé par les menus de cantine et les
 * commandes fournisseur.
 */
function psc_service_jour_offsets() {
    return array('lundi' => 0, 'mardi' => 1, 'jeudi' => 3, 'vendredi' => 4);
}

/**
 * Jours scolaires ouverts (parmi lundi/mardi/jeudi/vendredi) de la
 * semaine donnée, sous la forme [jour => date Y-m-d]. Un jour fermé
 * (vacances, jour férié, fermeture ponctuelle) n'a pas de service
 * périscolaire/cantine ce jour-là, donc rien à saisir ni à commander.
 */
function psc_open_days($monday) {
    $monday = psc_week_start($monday);
    if (!$monday) return array();
    $open = array();
    foreach (psc_service_jour_offsets() as $jour => $offset) {
        $date = gmdate('Y-m-d', strtotime($monday . " +{$offset} days"));
        if (psc_is_school_day($date)) $open[$jour] = $date;
    }
    return $open;
}

/**
 * Premier lundi (à partir de $from_date) dont la semaine contient au
 * moins un jour scolaire ouvert — évite de proposer par défaut une
 * semaine entièrement fermée (vacances, pont...).
 */
function psc_next_open_week($from_date) {
    $monday = psc_week_start($from_date);
    if (!$monday) return false;
    for ($i = 0; $i < 26; $i++) {
        if (!empty(psc_open_days($monday))) return $monday;
        $monday = gmdate('Y-m-d', strtotime($monday . ' +7 days'));
    }
    return $monday; // garde-fou : calendrier scolaire mal configuré / jamais chargé
}

function psc_day_label($date_str) {
    $jours = array('Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche');
    $dow = (int) date('N', strtotime($date_str));
    return isset($jours[$dow - 1]) ? $jours[$dow - 1] : '';
}

/**
 * Services proposés et leurs tarifs (éditables depuis Périscolaire > Réglages).
 */
function psc_services() {
    $defaults = array(
        'GM'   => array('label' => 'Garderie Matin', 'price' => 1.85),
        'CANT' => array('label' => 'Cantine', 'price' => 5.80),
        'GS'   => array('label' => 'Garderie Soir', 'price' => 4.70),
        'FORF' => array('label' => 'Forfait journée', 'price' => 11.70),
    );
    $saved = get_option('psc_service_prices', array());
    if (is_array($saved)) {
        foreach ($saved as $code => $price) {
            if (isset($defaults[$code])) {
                $defaults[$code]['price'] = max(0, floatval($price));
            }
        }
    }
    return $defaults;
}

/**
 * Liste ordonnée des niveaux scolaires pour les menus déroulants.
 * Clé = valeur stockée en base, valeur = libellé affiché.
 */
function psc_classe_options() {
    return array(
        ''   => '— Classe —',
        'PS' => 'Petite Section (PS)',
        'MS' => 'Moyenne Section (MS)',
        'GS' => 'Grande Section (GS)',
        'CP' => 'CP',
        'CE1'=> 'CE1',
        'CE2'=> 'CE2',
        'CM1'=> 'CM1',
        'CM2'=> 'CM2',
    );
}

/**
 * Année de rentrée en cours (ex : 2026 du 1er septembre 2026 au 31 août
 * 2027). Sert de repère pour savoir si la classe d'un enfant a déjà été
 * basculée cette année scolaire (cf. Psc_Frontend::bump_classes()).
 */
function psc_rentree_year($timestamp = null) {
    $ts = $timestamp ?: current_time('timestamp');
    $month = (int) date('n', $ts);
    $year  = (int) date('Y', $ts);
    return $month >= 9 ? $year : $year - 1;
}

/**
 * Classe attendue pour un enfant né le $date_naissance, à la rentrée
 * $rentree_year (âge au 31 décembre de cette année civile — règle
 * officielle française). Sert uniquement à initialiser la classe d'un
 * enfant qui n'en a pas encore : jamais utilisée pour recorriger une
 * classe déjà définie (cf. Psc_Frontend::bump_classes()).
 */
function psc_classe_for_birthdate($date_naissance, $rentree_year) {
    if (!$date_naissance) return '';
    $age = $rentree_year - (int) date('Y', strtotime($date_naissance));
    $map = array(3 => 'PS', 4 => 'MS', 5 => 'GS', 6 => 'CP', 7 => 'CE1', 8 => 'CE2', 9 => 'CM1', 10 => 'CM2');
    return $map[$age] ?? '';
}

/**
 * Classe suivante dans la progression PS→MS→GS→CP→CE1→CE2→CM1→CM2.
 * Renvoie null si $classe est déjà CM2 (fin du cycle géré par le
 * périscolaire) ou n'est pas une classe reconnue.
 */
function psc_classe_superieure($classe) {
    $order = array('PS', 'MS', 'GS', 'CP', 'CE1', 'CE2', 'CM1', 'CM2');
    $i = array_search($classe, $order, true);
    if ($i === false || $i === count($order) - 1) return null;
    return $order[$i + 1];
}

/**
 * Neutralise l'injection de formules CSV (Excel / LibreOffice).
 *
 * Une valeur commençant par = + - @ (ou tabulation / retour chariot) est
 * interprétée comme une formule à l'ouverture du fichier. Un nom d'enfant
 * saisi par un parent finit dans cet export : sans échappement, un parent
 * malveillant peut faire exécuter du code sur le poste de l'agent qui ouvre
 * le fichier. On préfixe par une apostrophe, qu'Excel traite comme
 * "forcer le format texte".
 */
function psc_csv_escape($value) {
    $value = (string) $value;
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

/* ------------------------------------------------------------------
 * Mandat de prélèvement SEPA (IBAN / BIC)
 * ------------------------------------------------------------------ */

/**
 * Valide un IBAN : format général (ISO 13616) + clé de contrôle mod-97.
 * Renvoie l'IBAN normalisé (majuscules, sans espaces) ou false.
 */
function psc_valid_iban($iban) {
    $iban = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $iban));
    if (strlen($iban) < 15 || strlen($iban) > 34) return false;
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $iban)) return false;

    // Les 4 premiers caractères passent à la fin, les lettres deviennent
    // des chiffres (A=10 .. Z=35), puis on vérifie que le nombre obtenu
    // est congru à 1 modulo 97 (ISO 7064 MOD 97-10).
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    for ($i = 0; $i < strlen($rearranged); $i++) {
        $ch = $rearranged[$i];
        $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
    }

    // Modulo 97 par blocs : le nombre dépasse la capacité d'un int.
    $checksum = 0;
    foreach (str_split($numeric, 7) as $block) {
        $checksum = (int) (((string) $checksum . $block) % 97);
    }
    return $checksum === 1 ? $iban : false;
}

/**
 * Valide un BIC/SWIFT : 8 caractères (siège) ou 11 (agence).
 * Renvoie le BIC normalisé (majuscules, sans espaces) ou false.
 */
function psc_valid_bic($bic) {
    $bic = strtoupper(preg_replace('/\s+/', '', (string) $bic));
    return preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic) ? $bic : false;
}

/**
 * IBAN partiellement masqué pour l'affichage admin (garde le pays et les
 * 4 derniers caractères) : réduit l'exposition d'une donnée bancaire dans
 * une liste consultée régulièrement.
 */
function psc_mask_iban($iban) {
    $iban = (string) $iban;
    $len = strlen($iban);
    if ($len <= 8) return $iban;
    return substr($iban, 0, 4) . ' •••• •••• ' . substr($iban, -4);
}

/**
 * Référence unique de mandat (RUM), dérivée de l'id de la demande —
 * stable et unique sans écriture supplémentaire. Utilisée à la fois
 * pour le PDF envoyé à la soumission (Psc_Requests::handle_submit) et
 * pour le compte famille créé à l'approbation (Psc_Requests::handle_approve),
 * afin qu'un même formulaire ait toujours la même RUM.
 */
function psc_sepa_mandate_ref($request_id) {
    return 'RUM' . str_pad((int) $request_id, 8, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------------
 * Délai de modification (verrouillage à l'approche de la date)
 * ------------------------------------------------------------------ */

/**
 * Délai minimal, en heures, avant le jour concerné, en deçà duquel un
 * parent ne peut plus modifier son planning. Par défaut 48 h.
 */
function psc_lock_hours() {
    $h = (int) get_option('psc_lock_hours', 48);
    if ($h < 0) $h = 0;
    if ($h > 720) $h = 720; // 30 jours max
    return $h;
}

/**
 * Horodatage courant dans le fuseau du site.
 * On n'utilise pas time() directement : le serveur peut être en UTC alors
 * que la commune est en Europe/Paris, ce qui décalerait le verrouillage.
 */
function psc_now_ts() {
    return (int) current_time('timestamp');
}

/**
 * Instant à partir duquel un jour donné n'est plus modifiable.
 * Le décompte part du début du jour de service (00:00), pas de l'heure
 * de la prestation : c'est plus simple à expliquer aux familles et cela
 * couvre la garderie du matin.
 */
function psc_lock_deadline_ts($date_str) {
    $tz = wp_timezone();
    $day = new DateTime($date_str . ' 00:00:00', $tz);
    return $day->getTimestamp() - (psc_lock_hours() * HOUR_IN_SECONDS);
}

/**
 * Un jour est-il verrouillé pour les parents ?
 * La mairie n'est jamais concernée par ce verrou (elle doit pouvoir
 * corriger une erreur de dernière minute).
 */
function psc_is_locked($date_str) {
    if (psc_lock_hours() === 0) return false;
    return psc_now_ts() >= psc_lock_deadline_ts($date_str);
}

/**
 * Message lisible expliquant jusqu'à quand un jour reste modifiable.
 */
function psc_lock_message($date_str) {
    $deadline = psc_lock_deadline_ts($date_str);
    return sprintf(
        'Modifiable jusqu\'au %s',
        date_i18n('j F Y à H:i', $deadline)
    );
}

/* ------------------------------------------------------------------
 * Options de notification
 * ------------------------------------------------------------------ */

function psc_notify_mairie_enabled() {
    return (bool) get_option('psc_notify_mairie', 0);
}

function psc_mairie_email() {
    $mail = get_option('psc_mairie_email', '');
    if (!$mail || !is_email($mail)) {
        $mail = get_option('admin_email');
    }
    return $mail;
}

/* ------------------------------------------------------------------
 * Authentification des parents (indépendante des comptes WordPress)
 * ------------------------------------------------------------------ */

/** Durée de validité d'un lien de connexion envoyé par e-mail. */
function psc_login_link_ttl() {
    return 30 * MINUTE_IN_SECONDS;
}

/** Durée d'une session parent une fois le lien utilisé. */
function psc_session_ttl() {
    return (int) apply_filters('psc_session_ttl', 12 * HOUR_IN_SECONDS);
}

function psc_session_cookie_name() {
    return 'psc_session';
}

/**
 * Signe une valeur avec les clés secrètes du site.
 * Permet de faire confiance au contenu d'un cookie sans stocker de session
 * en base : si la signature ne correspond pas, la valeur a été altérée.
 */
function psc_sign($payload) {
    return hash_hmac('sha256', $payload, wp_salt('psc_session'));
}

/**
 * Hash d'un jeton de connexion avant stockage.
 * On ne stocke jamais le jeton en clair : une fuite de la base ne permet
 * donc pas de se connecter aux comptes parents.
 */
function psc_hash_token($token) {
    return hash_hmac('sha256', $token, wp_salt('psc_token'));
}

/**
 * Limitation de fréquence (anti-spam / anti-énumération).
 * Renvoie false si la limite est atteinte.
 *
 * Désactivée en environnement local/développement (WP_ENVIRONMENT_TYPE),
 * ou via le filtre psc_rate_limit_enabled : évite d'avoir à purger des
 * transients entre deux runs de test.
 */
function psc_rate_limit($key, $max, $window) {
    if (in_array(wp_get_environment_type(), array('local', 'development'), true)) {
        return true;
    }
    if (!apply_filters('psc_rate_limit_enabled', true)) {
        return true;
    }

    $transient = 'psc_rl_' . md5($key);
    $count = (int) get_transient($transient);
    if ($count >= $max) {
        return false;
    }
    set_transient($transient, $count + 1, $window);
    return true;
}

/**
 * Adresse IP de la requête, utilisée uniquement pour la limitation de
 * fréquence. Volontairement non stockée en base (donnée personnelle).
 */
function psc_client_ip() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : '';
    $ip = filter_var($ip, FILTER_VALIDATE_IP);
    return $ip ?: '0.0.0.0';
}
