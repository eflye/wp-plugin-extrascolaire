<?php
if (!defined('ABSPATH')) exit;

/**
 * Capacité requise pour accéder au backoffice périscolaire. Capacité
 * dédiée (pas manage_options) : elle est accordée par défaut aux
 * administrateurs ET aux éditeurs (cf. Psc_Installer::sync_roles()),
 * pour qu'un membre de la mairie puisse gérer le périscolaire sans avoir
 * les droits d'administration complète du site (thèmes, extensions,
 * réglages WordPress). Filtrable pour pointer vers une capacité
 * entièrement personnalisée si besoin.
 */
function psc_manage_cap() {
    return apply_filters('psc_manage_capability', 'psc_manage_periscolaire');
}

/**
 * Rôles WordPress auxquels psc_manage_cap() est accordée par défaut à
 * l'activation/mise à jour du plugin. Filtrable : retourner un tableau
 * vide désactive l'attribution automatique (utile si la capacité a été
 * personnalisée via psc_manage_capability et gérée à la main).
 */
function psc_manage_default_roles() {
    return apply_filters('psc_manage_default_roles', array('administrator', 'editor'));
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
 * Vérifie qu'un code de prestation est reconnu.
 *
 * Point de contrôle unique : la liste était déjà centralisée, mais le
 * test était réécrit à chaque endroit qui écrit une inscription. Un
 * chemin d'écriture ajouté plus tard pouvait donc simplement oublier de
 * le faire, sans que rien ne le signale.
 *
 * La colonne correspondante est par ailleurs contrainte côté base
 * (cf. Psc_Installer::ensure_service_enum()) : cette fonction refuse la
 * valeur proprement, la base l'aurait de toute façon rejetée.
 */
function psc_is_valid_service($service) {
    return in_array($service, psc_allowed_services(), true);
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
 * Nombre maximum de personnes autorisées à récupérer un même enfant
 * (liste courante, actives). Même esprit que psc_max_children_per_user().
 */
function psc_max_pickup_persons_per_child() {
    return apply_filters('psc_max_pickup_persons_per_child', 8);
}

/**
 * Suggestions de lien avec l'enfant pour la liste déroulante (<datalist>)
 * du champ "lien" — champ libre malgré tout : psc_pickup_lien_options()
 * n'est qu'une aide à la saisie, jamais une validation côté serveur.
 */
function psc_pickup_lien_suggestions() {
    return array(
        'Grand-parent',
        'Oncle / Tante',
        'Voisin(e)',
        'Nounou / Assistant(e) maternel(le)',
        'Ami(e) de la famille',
        'Autre',
    );
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
 * 2027). Sert de repère pour dater les documents (assurance, dossiers)
 * quand aucune année scolaire n'est explicitement fournie.
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
 * classe déjà définie (cf. Psc_School_Years::build_promotion_plan()).
 */
function psc_classe_for_birthdate($date_naissance, $rentree_year) {
    if (!$date_naissance) return '';
    $age = $rentree_year - (int) date('Y', strtotime($date_naissance));
    $map = array(3 => 'PS', 4 => 'MS', 5 => 'GS', 6 => 'CP', 7 => 'CE1', 8 => 'CE2', 9 => 'CM1', 10 => 'CM2');
    return $map[$age] ?? '';
}

/**
 * Table de correspondance classe -> classe suivante (ou 'sortie'),
 * éditable depuis Périscolaire > Réglages : une école à classes
 * multi-niveaux peut avoir une progression différente du simple
 * PS→MS→GS→CP→CE1→CE2→CM1→CM2. Valeur par défaut = cette progression
 * standard, CM2 menant à la sortie.
 */
function psc_classe_progression_defaut() {
    return array(
        'PS'  => 'MS',
        'MS'  => 'GS',
        'GS'  => 'CP',
        'CP'  => 'CE1',
        'CE1' => 'CE2',
        'CE2' => 'CM1',
        'CM1' => 'CM2',
        'CM2' => 'sortie',
    );
}

function psc_classe_progression() {
    $saved = get_option('psc_classe_progression', array());
    $defaut = psc_classe_progression_defaut();
    if (!is_array($saved) || empty($saved)) return $defaut;

    $progression = array();
    foreach (array_keys(psc_classe_options()) as $code) {
        if ($code === '') continue;
        $progression[$code] = isset($saved[$code]) ? $saved[$code] : ($defaut[$code] ?? 'sortie');
    }
    return $progression;
}

/**
 * Classe suivante pour $classe selon la table de correspondance
 * configurée (Réglages). Renvoie 'sortie' en fin de cycle, ou null si
 * $classe n'est pas une classe reconnue.
 */
function psc_classe_superieure($classe) {
    $progression = psc_classe_progression();
    return $progression[$classe] ?? null;
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
 * Valide un numéro de téléphone : format volontairement large (aucun
 * numéro de téléphone existant dans le plugin — mobile, fixe — n'est
 * autrement validé), pour ne pas rejeter un numéro fixe étranger ou une
 * saisie avec indicatif. Accepte chiffres, espaces, points, tirets et
 * parenthèses ; exige 6 à 15 chiffres significatifs, + initial optionnel.
 * Renvoie le numéro normalisé (espaces/ponctuation retirés) ou false.
 * N'est jamais appelée sur une valeur vide : le champ reste facultatif,
 * c'est à l'appelant de ne valider que si une valeur a été saisie.
 */
function psc_valid_phone($phone) {
    $digits = preg_replace('/[\s.\-()]/', '', (string) $phone);
    return preg_match('/^\+?\d{6,15}$/', $digits) ? $digits : false;
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

/* ------------------------------------------------------------------
 * Chiffrement au repos des coordonnées bancaires
 * ------------------------------------------------------------------ */

/**
 * Clé de chiffrement des données bancaires.
 *
 * Priorité à une clé dédiée déclarée dans wp-config.php :
 *
 *     define('PSC_ENCRYPTION_KEY', 'une-longue-chaine-aleatoire');
 *
 * À défaut, elle est dérivée des sels WordPress — qui vivent eux aussi dans
 * wp-config.php, donc hors de la base : un dump SQL seul ne permet pas de
 * déchiffrer, ce qui est précisément la menace visée.
 *
 * ATTENTION : régénérer les sels WordPress (ou changer PSC_ENCRYPTION_KEY)
 * rend les IBAN déjà enregistrés illisibles — ils devront être ressaisis.
 * Déclarer PSC_ENCRYPTION_KEY met à l'abri d'une rotation de sels.
 */
function psc_encryption_key() {
    $secret = defined('PSC_ENCRYPTION_KEY') && PSC_ENCRYPTION_KEY
        ? PSC_ENCRYPTION_KEY
        : wp_salt('psc_sepa');
    return hash('sha256', $secret, true); // 32 octets bruts
}

/**
 * Chiffre une valeur destinée à la base. Retourne une chaîne préfixée
 * "psc1:" — le préfixe rend l'opération idempotente (une valeur déjà
 * chiffrée n'est jamais re-chiffrée, ce qui évite toute une classe de bugs
 * quand une donnée transite d'une table à l'autre) et permet de reconnaître
 * les valeurs héritées restées en clair.
 */
function psc_encrypt($value) {
    if ($value === null || $value === '') return $value;
    $value = (string) $value;
    if (strpos($value, 'psc1:') === 0) return $value; // déjà chiffrée

    $key = psc_encryption_key();

    if (function_exists('sodium_crypto_secretbox')) {
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($value, $nonce, $key);
        return 'psc1:' . base64_encode($nonce . $cipher);
    }

    if (function_exists('openssl_encrypt')) {
        $iv     = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) return $value;
        return 'psc1:' . base64_encode($iv . $tag . $cipher);
    }

    return $value; // aucune primitive disponible : ne jamais perdre la donnée
}

/**
 * Déchiffre une valeur lue en base. Une valeur sans préfixe est retournée
 * telle quelle (donnée héritée, enregistrée avant le chiffrement). Retourne
 * null si le déchiffrement échoue — typiquement après une rotation des sels :
 * l'appelant affiche alors un champ vide à ressaisir plutôt que de planter.
 */
function psc_decrypt($value) {
    if ($value === null || $value === '') return $value;
    $value = (string) $value;
    if (strpos($value, 'psc1:') !== 0) return $value; // clair hérité

    $raw = base64_decode(substr($value, 5), true);
    if ($raw === false) return null;
    $key = psc_encryption_key();

    if (function_exists('sodium_crypto_secretbox_open')) {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($raw) <= $n) return null;
        $plain = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), $key);
        if ($plain !== false) return $plain;
    }

    if (function_exists('openssl_decrypt')) {
        if (strlen($raw) <= 28) return null;
        $plain = openssl_decrypt(
            substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16)
        );
        if ($plain !== false) return $plain;
    }

    return null;
}

/**
 * IBAN en clair d'un enregistrement (famille ou demande), quel que soit son
 * mode de stockage. Point de lecture unique : tout accès direct à la colonne
 * sepa_iban doit passer par ici.
 */
function psc_read_iban($record) {
    if (!$record) return '';
    $raw = is_object($record) ? ($record->sepa_iban ?? '') : ($record['sepa_iban'] ?? '');
    return (string) psc_decrypt($raw);
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

/**
 * Auto-validation des demandes d'inscription : dès que la famille
 * confirme son adresse e-mail, elle accède directement à son espace,
 * sans relecture de la mairie. Désactivé par défaut — la modération
 * manuelle (Périscolaire > Demandes) reste le comportement standard.
 */
function psc_auto_approve_requests_enabled() {
    return (bool) apply_filters('psc_auto_approve_requests', get_option('psc_auto_approve_requests', 0));
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

/**
 * Hash d'un jeton de connexion avant stockage.
 * On ne stocke jamais le jeton en clair : une fuite de la base ne permet
 * donc pas de se connecter aux comptes parents.
 */
function psc_hash_token($token) {
    return hash_hmac('sha256', $token, wp_salt('psc_token'));
}

/* ------------------------------------------------------------------
 * Jetons anti-CSRF du portail famille
 * ------------------------------------------------------------------ */

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
    $now = time();
    $data = get_transient($transient);

    // Fenêtre fixe : la date de fin est décidée à la première tentative et
    // ne bouge plus. Repousser l'échéance à chaque appel — ce que fait un
    // simple set_transient($count + 1, $window) — donne un compteur qu'un
    // attaquant maintient vivant indéfiniment : il suffit de continuer à
    // frapper pour qu'il n'expire jamais. Sur la clé « adresse e-mail »,
    // cela permettait de priver durablement une famille visée de son lien
    // de connexion, alors que la limite doit se lever d'elle-même.
    //
    // Le format antérieur stockait un entier nu : il est traité comme une
    // fenêtre absente, ce qui repart proprement.
    if (!is_array($data) || empty($data['expires']) || $data['expires'] <= $now) {
        $data = array('count' => 0, 'expires' => $now + $window);
    }

    if ($data['count'] >= $max) {
        return false;
    }

    // Lecture puis écriture ne sont pas atomiques : deux requêtes
    // simultanées peuvent lire le même compteur et passer toutes les deux.
    // L'écart reste de l'ordre du nombre de requêtes concurrentes, sans
    // commune mesure avec ce que la limite vise à contenir.
    $data['count']++;
    set_transient($transient, $data, $data['expires'] - $now);
    return true;
}

/**
 * Limitation de fréquence indexée sur l'adresse IP de l'appelant.
 *
 * Laisse passer lorsque l'IP n'est pas déterminable : à défaut, toutes les
 * requêtes tomberaient dans le même seau et les premiers visiteurs
 * épuiseraient le quota de tous les autres — la protection anti-abus se
 * retournerait en panne pour l'ensemble des familles. Les limites par
 * adresse e-mail, elles, continuent de s'appliquer.
 */
function psc_rate_limit_by_ip($prefix, $max, $window) {
    $ip = psc_client_ip();
    if ($ip === '') {
        return true;
    }
    return psc_rate_limit($prefix . $ip, $max, $window);
}

/**
 * Adresse IP de la requête, utilisée uniquement pour la limitation de
 * fréquence. Volontairement non stockée en base (donnée personnelle).
 *
 * REMOTE_ADDR par défaut : c'est la seule valeur que l'appelant ne choisit
 * pas. Un en-tête « X-Forwarded-For » est envoyé par le client lui-même —
 * s'y fier sans précaution offrirait à un attaquant une IP différente à
 * chaque requête, donc un contournement complet de la limitation.
 *
 * Derrière un répartiteur de charge ou un CDN, REMOTE_ADDR est en revanche
 * celui de l'intermédiaire, identique pour tout le monde. On peut alors
 * désigner explicitement l'en-tête à lire dans wp-config.php :
 *
 *     define('PSC_CLIENT_IP_HEADER', 'HTTP_X_FORWARDED_FOR');
 *
 * La valeur retenue est la DERNIÈRE de la liste, pas la première : un
 * intermédiaire ajoute l'adresse qu'il constate à la fin de l'en-tête, et
 * tout ce qui précède a pu être fabriqué par le client. Régler
 * PSC_TRUSTED_PROXIES si plusieurs intermédiaires se succèdent.
 *
 * @return string IP validée, ou chaîne vide si indéterminable.
 */
function psc_client_ip() {
    $remote = isset($_SERVER['REMOTE_ADDR'])
        ? filter_var(wp_unslash($_SERVER['REMOTE_ADDR']), FILTER_VALIDATE_IP)
        : false;

    $header = defined('PSC_CLIENT_IP_HEADER') ? PSC_CLIENT_IP_HEADER : '';
    $header = (string) apply_filters('psc_client_ip_header', $header);

    if ($header !== '' && !empty($_SERVER[$header])) {
        $chain = array_map('trim', explode(',', (string) wp_unslash($_SERVER[$header])));
        $depth = defined('PSC_TRUSTED_PROXIES') ? max(1, (int) PSC_TRUSTED_PROXIES) : 1;

        // On remonte la chaîne en sautant autant d'entrées qu'il y a
        // d'intermédiaires de confiance ; au-delà, plus rien n'est vérifiable.
        $index = count($chain) - $depth;
        if ($index >= 0 && isset($chain[$index])) {
            $candidate = filter_var($chain[$index], FILTER_VALIDATE_IP);
            if ($candidate) {
                return $candidate;
            }
        }
    }

    return $remote ?: '';
}

/* ------------------------------------------------------------------
 * Stockage privé des documents (justificatifs d'assurance, factures)
 * ------------------------------------------------------------------ */

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

