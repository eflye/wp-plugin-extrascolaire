<?php
/**
 * Seed idempotent pour le parcours journeys/parent-connu.md.
 *
 * Usage :
 *   wp --require=bin/seed-journey.php seed-journey --profile=test
 *   wp --require=bin/seed-journey.php seed-journey --profile=demo
 *
 * (profil par défaut : test)
 *
 * `wp eval-file` refuse les flags qu'il ne déclare pas lui-même : ce script
 * enregistre donc une vraie commande WP-CLI (`seed-journey`) via --require,
 * seule façon fiable d'accepter --profile=... sans bricoler un parsing
 * manuel de $argv.
 *
 * Purge puis recrée les données identifiées par l'adresse e-mail du parent
 * et le libellé du trimestre du profil choisi : réexécutable N fois sans
 * jamais dupliquer de parent ni d'inscription. Ne touche à aucune autre
 * donnée du site (jamais de TRUNCATE).
 *
 * TEST  : conforme au bloc `fixtures` de journeys/parent-connu.md — 1 parent,
 *         1 enfant, calendrier vide, libellés neutres.
 * DEMO  : même structure, données présentables pour capture vidéo — 2
 *         enfants, e-mail crédible, libellé de trimestre correspondant à la
 *         période en cours, quelques jours déjà déclarés.
 *
 * open_day / locked_day sont dérivés par la même règle dans les deux
 * profils (cf. journeys/parent-connu.md, bloc fixtures > jours_calendrier) :
 *   - locked_day : premier jour ouvert dont l'échéance de verrouillage
 *                  (jour 00:00 − psc_lock_hours()) est déjà dépassée.
 *   - open_day   : premier jour ouvert dont l'échéance de verrouillage est
 *                  postérieure à "aujourd'hui + 3 jours".
 *
 * Ne crée aucun test ni configuration Playwright.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('seed-journey', function ($args, $assoc_args) {

    if (!class_exists('Psc_Installer') || !class_exists('Psc_Parents')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    /* ---------------------------------------------------------------- */
    /* Profil                                                            */
    /* ---------------------------------------------------------------- */

    $profile = isset($assoc_args['profile']) ? sanitize_key($assoc_args['profile']) : 'test';
    if (!in_array($profile, array('test', 'demo'), true)) {
        WP_CLI::error("Profil inconnu : '$profile'. Valeurs acceptées : test, demo.");
    }

    $tz    = wp_timezone();
    $today = new DateTime('today', $tz);

    $fmt = function (DateTime $d) {
        return $d->format('Y-m-d');
    };

    /* ---------------------------------------------------------------- */
    /* Configuration par profil                                          */
    /* ---------------------------------------------------------------- */

    if ($profile === 'test') {

        $date_debut = $fmt((clone $today)->modify('-3 days'));
        $date_fin   = $fmt((clone $today)->modify('+45 days'));

        $config = array(
            'trimestre_label' => 'Trimestre de test',
            'date_debut'      => $date_debut,
            'date_fin'        => $date_fin,
            'parent_email'    => 'famille.dupont@example.com',
            'parent_nom'      => 'Dupont',
            'enfants'         => array(
                array('prenom' => 'Léo', 'nom' => 'Dupont', 'classe' => 'CE1'),
            ),
            'preregistrations' => false,
        );

    } else {

        // Libellé réaliste, dérivé du mois en cours plutôt que codé en dur.
        $m = (int) $today->format('n');
        $y = (int) $today->format('Y');
        if ($m >= 9) {
            $label = "1er trimestre {$y}-" . ($y + 1);
        } elseif ($m <= 3) {
            $label = "2e trimestre " . ($y - 1) . "-{$y}";
        } else {
            $label = "3e trimestre " . ($y - 1) . "-{$y}";
        }

        $date_debut = $fmt((clone $today)->modify('-14 days'));
        $date_fin   = $fmt((clone $today)->modify('+60 days'));

        $config = array(
            'trimestre_label' => $label,
            'date_debut'      => $date_debut,
            'date_fin'        => $date_fin,
            // Domaine .invalid (RFC 2606) : adresse crédible à l'écran mais
            // jamais routable, contrairement à un vrai gmail.com — affichée
            // en grand pendant une bonne partie de la vidéo (account-email,
            // confirm-feedback, ...).
            'parent_email'    => 'nathalie.girard@famille-demo.invalid',
            'parent_nom'      => 'Girard',
            'enfants'         => array(
                array('prenom' => 'Camille', 'nom' => 'Girard', 'classe' => 'CE2'),
                array('prenom' => 'Hugo',    'nom' => 'Girard', 'classe' => 'CM1'),
            ),
            'preregistrations' => true,
        );
    }

    global $wpdb;
    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_trim   = psc_table('trimestres');
    $t_days   = psc_table('calendar_days');
    $t_reg    = psc_table('registrations');
    $t_inv    = psc_table('invoices');
    $t_req    = psc_table('requests');

    /* ---------------------------------------------------------------- */
    /* Purge — scoping strict à l'identité du profil (jamais un TRUNCATE)*/
    /* ---------------------------------------------------------------- */

    WP_CLI::log("Profil : $profile — purge des données existantes…");

    // Transients de limitation de fréquence et de snapshot de récapitulatif :
    // même si l'environnement bypasse déjà le rate-limit (psc_rate_limit_enabled
    // / WP_ENVIRONMENT_TYPE), le snapshot de récap n'est pas couvert par ce
    // bypass et doit être purgé pour rester déterministe (cf. journeys/
    // parent-connu.md, bloc env).
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '\_transient\_psc\_rl\_%'
            OR option_name LIKE '\_transient\_timeout\_psc\_rl\_%'
            OR option_name LIKE '\_transient\_psc\_recap\_snap\_%'
            OR option_name LIKE '\_transient\_timeout\_psc\_recap\_snap\_%'"
    );

    $old_parent_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $t_parent WHERE email = %s", strtolower($config['parent_email'])
    ));
    if ($old_parent_id) {
        $child_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $t_child WHERE parent_id = %d", $old_parent_id
        ));
        if ($child_ids) {
            $placeholders = implode(',', array_fill(0, count($child_ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM $t_reg WHERE child_id IN ($placeholders)", $child_ids));
        }
        $wpdb->delete($t_child, array('parent_id' => $old_parent_id), array('%d'));
        $wpdb->delete($t_inv, array('parent_id' => $old_parent_id), array('%d'));
        $wpdb->delete($t_parent, array('id' => $old_parent_id), array('%d'));
    }
    $wpdb->delete($t_req, array('email' => strtolower($config['parent_email'])), array('%s'));

    $old_trim_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM $t_trim WHERE label = %s", $config['trimestre_label']
    ));
    foreach ($old_trim_ids as $trim_id) {
        $wpdb->delete($t_reg, array('trimestre_id' => $trim_id), array('%d'));
        $wpdb->delete($t_days, array('trimestre_id' => $trim_id), array('%d'));
        $wpdb->delete($t_inv, array('trimestre_id' => $trim_id), array('%d'));
        $wpdb->delete($t_trim, array('id' => $trim_id), array('%d'));
    }

    /* ---------------------------------------------------------------- */
    /* Recréation                                                        */
    /* ---------------------------------------------------------------- */

    // Un seul trimestre actif à la fois pour que Psc_Frontend::active_trimestre()
    // pointe sans ambiguïté vers celui du seed.
    $wpdb->query("UPDATE $t_trim SET active = 0");

    $wpdb->insert($t_trim, array(
        'label'      => $config['trimestre_label'],
        'date_debut' => $config['date_debut'],
        'date_fin'   => $config['date_fin'],
        'active'     => 1,
    ), array('%s', '%s', '%s', '%d'));
    $trimestre_id = (int) $wpdb->insert_id;

    Psc_Installer::generate_calendar_days($trimestre_id, $config['date_debut'], $config['date_fin']);

    $parent_id = Psc_Parents::create($config['parent_email'], $config['parent_nom']);
    if (is_wp_error($parent_id)) {
        WP_CLI::error('Création du parent : ' . $parent_id->get_error_message());
    }

    $children_ids = array();
    foreach ($config['enfants'] as $c) {
        $wpdb->insert($t_child, array(
            'parent_id'  => $parent_id,
            'nom'        => $c['nom'],
            'prenom'     => $c['prenom'],
            'classe'     => $c['classe'],
            'active'     => 1,
            'created_at' => current_time('mysql'),
        ), array('%d', '%s', '%s', '%s', '%d', '%s'));
        $children_ids[] = (int) $wpdb->insert_id;
    }

    // children_of() trie par prénom : l'index "-0"/"-1" des testid suit cet
    // ordre, pas l'ordre de création.
    usort($config['enfants'], function ($a, $b) { return strcmp($a['prenom'], $b['prenom']); });

    /* ---------------------------------------------------------------- */
    /* open_day / locked_day — même règle dans les deux profils          */
    /* ---------------------------------------------------------------- */

    $days = $wpdb->get_results($wpdb->prepare(
        "SELECT jour_date, is_open FROM $t_days WHERE trimestre_id = %d ORDER BY jour_date", $trimestre_id
    ));

    $open_threshold = (clone $today)->modify('+3 days')->getTimestamp();
    $today_str      = $fmt($today);

    $open_day = null;
    foreach ($days as $d) {
        if ((int) $d->is_open !== 1) continue;
        if (psc_lock_deadline_ts($d->jour_date) > $open_threshold) {
            $open_day = $d->jour_date;
            break;
        }
    }

    if (!$open_day) {
        WP_CLI::warning("Aucun open_day trouvé dans la plage {$config['date_debut']} → {$config['date_fin']} : élargir la fenêtre du trimestre.");
    }

    // locked_day : le PROCHAIN jour ouvert à venir (jamais dans le passé)
    // dont l'échéance de verrouillage est déjà dépassée, ET dans le même
    // mois que open_day pour rester visible dans le même bloc <details>
    // sans avoir à replier/déplier un autre mois. Chercher à partir
    // d'aujourd'hui (pas du début du trimestre, qui peut être passé de
    // plusieurs semaines côté profil demo) : avec psc_lock_hours()=48 par
    // défaut, ce sont concrètement aujourd'hui/demain/après-demain.
    $locked_day = null;
    if ($open_day) {
        $open_month = substr($open_day, 0, 7);
        foreach ($days as $d) {
            if ((int) $d->is_open !== 1) continue;
            if ($d->jour_date < $today_str) continue; // jamais dans le passé
            if (substr($d->jour_date, 0, 7) !== $open_month) continue;
            if (psc_is_locked($d->jour_date)) {
                $locked_day = $d->jour_date;
                break;
            }
        }
    }

    if (!$locked_day) {
        WP_CLI::warning(
            'Aucun locked_day trouvé à venir dans le mois de open_day (' . ($open_day ? substr($open_day, 0, 7) : '?') . ') : ' .
            'avec psc_lock_hours() = ' . psc_lock_hours() . 'h, élargir la fenêtre du trimestre ou revoir open_day.'
        );
    }

    /* ---------------------------------------------------------------- */
    /* Profil demo : quelques jours déjà déclarés (total de mois non nul)*/
    /* ---------------------------------------------------------------- */

    $preregistered = array();

    if ($config['preregistrations']) {
        // Les deux jours ouverts les plus proches, en remontant depuis
        // aujourd'hui — "passés/proches" plutôt que des offsets fixes qui
        // pourraient tomber un week-end ou un jour férié.
        $near_days = array();
        for ($cursor = clone $today; count($near_days) < 2; $cursor->modify('-1 day')) {
            $date = $fmt($cursor);
            foreach ($days as $d) {
                if ($d->jour_date === $date && (int) $d->is_open === 1) {
                    $near_days[] = $date;
                    break;
                }
            }
            // Garde-fou : ne jamais remonter avant le début du trimestre.
            if ($date <= $config['date_debut']) break;
        }

        if (count($near_days) >= 1) {
            foreach ($children_ids as $child_id) {
                // Jour le plus récent : Cantine + Garderie Matin.
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO $t_reg (child_id, trimestre_id, jour_date, service, updated_at)
                     VALUES (%d, %d, %s, 'CANT', %s), (%d, %d, %s, 'GM', %s)
                     ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
                    $child_id, $trimestre_id, $near_days[0], current_time('mysql'),
                    $child_id, $trimestre_id, $near_days[0], current_time('mysql')
                ));
                $preregistered[] = array('child_id' => $child_id, 'date' => $near_days[0], 'services' => array('CANT', 'GM'));

                // Jour précédent, s'il existe : Cantine seule.
                if (isset($near_days[1])) {
                    $wpdb->query($wpdb->prepare(
                        "INSERT INTO $t_reg (child_id, trimestre_id, jour_date, service, updated_at)
                         VALUES (%d, %d, %s, 'CANT', %s)
                         ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
                        $child_id, $trimestre_id, $near_days[1], current_time('mysql')
                    ));
                    $preregistered[] = array('child_id' => $child_id, 'date' => $near_days[1], 'services' => array('CANT'));
                }
            }
        }
    }

    /* ---------------------------------------------------------------- */
    /* Sortie                                                             */
    /* ---------------------------------------------------------------- */

    $months = array();
    foreach ($days as $d) {
        if ((int) $d->is_open !== 1) continue;
        $key = substr($d->jour_date, 0, 7);
        if (!isset($months[$key])) {
            $months[$key] = date_i18n('F Y', strtotime($d->jour_date));
        }
    }

    WP_CLI::log('');
    WP_CLI::log("Profil ................ $profile");
    WP_CLI::log("Trimestre .............. {$config['trimestre_label']} (id $trimestre_id, actif)");
    WP_CLI::log("Période ................ {$config['date_debut']} → {$config['date_fin']}");
    WP_CLI::log("Parent ................. {$config['parent_email']} (id $parent_id)");
    foreach ($config['enfants'] as $i => $c) {
        WP_CLI::log("  enfant-$i ............ {$c['prenom']} {$c['nom']} ({$c['classe']})");
    }
    WP_CLI::log("open_day ............... " . ($open_day ?: '(non trouvé)'));
    WP_CLI::log("locked_day ............. " . ($locked_day ?: '(non trouvé)'));
    WP_CLI::log("Mois affichés .......... " . implode(', ', array_map(
        function ($key, $label) { return "$key ($label)"; },
        array_keys($months), $months
    )));
    if ($preregistered) {
        WP_CLI::log('Jours pré-cochés (demo) :');
        foreach ($preregistered as $p) {
            WP_CLI::log("  enfant id {$p['child_id']} — {$p['date']} — " . implode('+', $p['services']));
        }
    }

    // Ligne unique, machine-lisible, pour un script vidéo ou une future
    // automatisation de test : dernière ligne de sortie = JSON.
    WP_CLI::log('');
    WP_CLI::log(wp_json_encode(array(
        'profile'          => $profile,
        'parent_email'     => $config['parent_email'],
        'parent_id'        => $parent_id,
        'trimestre_id'     => $trimestre_id,
        'trimestre_label'  => $config['trimestre_label'],
        'enfants'          => array_map(function ($c, $i) {
            return array('index' => $i, 'prenom' => $c['prenom'], 'nom' => $c['nom'], 'classe' => $c['classe']);
        }, $config['enfants'], array_keys($config['enfants'])),
        'open_day'         => $open_day,
        'locked_day'       => $locked_day,
        'months'           => array_keys($months),
    )));

    WP_CLI::success("Seed '$profile' prêt.");
});
