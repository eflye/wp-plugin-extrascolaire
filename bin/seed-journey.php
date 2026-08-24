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

/**
 * Cherche un jour d'école utilisable comme « maintenant » pour le parcours.
 *
 * Le scénario a besoin, dans un même mois, de deux jours aux propriétés
 * opposées : un déjà verrouillé (pour vérifier qu'on ne peut plus le
 * cocher) et un encore largement modifiable. Avec un verrou de V heures et
 * une ancre A fixée à 10 h :
 *
 *   verrouillé  A lui-même — son échéance (A 00:00 − V) est déjà passée.
 *   ouvert      le premier jour d'école dont l'échéance dépasse A + 3 jours,
 *               soit environ A + V/24 + 4 jours.
 *
 * D'où les deux conditions ci-dessous : l'ancre doit tomber assez tôt dans
 * le mois pour que le jour « ouvert » y tombe encore, et ce jour doit
 * exister. Toute période scolaire les satisfait ; aucune période de vacances
 * ne les satisfait, ce qui est précisément la raison d'être de cette
 * recherche.
 *
 * $from permet de rejouer la recherche depuis une date arbitraire, pour
 * vérifier qu'elle aboutit toute l'année — c'est précisément ce qui manquait
 * quand le jeu de données dépendait de la date d'exécution.
 *
 * @return DateTime|null Ancre à 10 h, ou null si rien n'a été trouvé.
 */
function psc_seed_find_anchor(DateTimeZone $tz, $from = 'today') {
    $lock_days = (int) ceil(psc_lock_hours() / 24);
    $gap       = $lock_days + 4; // marge entre l'ancre et le jour « ouvert »

    $cursor = new DateTime($from, $tz);
    $cursor->setTime(0, 0);

    for ($i = 0; $i < 400; $i++, $cursor->modify('+1 day')) {
        $anchor_date = $cursor->format('Y-m-d');
        if (!psc_is_school_day($anchor_date)) continue;

        // Laisser la place au jour « ouvert » dans le même mois civil : le
        // scénario déplie un seul mois et lit les deux jours dedans.
        $month = $cursor->format('Y-m');

        $open = null;
        $probe = (clone $cursor)->modify("+{$gap} days");
        for ($j = 0; $j < 25; $j++, $probe->modify('+1 day')) {
            if ($probe->format('Y-m') !== $month) break;
            if (psc_is_school_day($probe->format('Y-m-d'))) { $open = clone $probe; break; }
        }
        if (!$open) continue;

        return (clone $cursor)->setTime(10, 0);
    }

    return null;
}

/**
 * Dépose un justificatif d'assurance pour l'année scolaire donnée.
 *
 * Sans lui, l'extension refuse toute nouvelle déclaration de jour : les
 * cases du planning sont rendues désactivées, avec la mention « assurance
 * scolaire manquante ». Le parcours parent ne pouvait donc pas aboutir —
 * un manque du jeu de données passé inaperçu tant qu'un autre échec, plus
 * précoce, empêchait d'arriver jusque-là.
 *
 * Un vrai fichier est écrit, et non un simple chemin en base : c'est ce que
 * fait un dépôt réel, et le parcours propose de le télécharger.
 */
function psc_seed_attach_assurance($child_id, $school_year_id) {
    global $wpdb;

    psc_ensure_private_dir();

    $rel = Psc_Assurances::BASE . '/seed/child-' . (int) $child_id . '.pdf';
    $abs = psc_private_path($rel);
    wp_mkdir_p(dirname($abs));

    // PDF minimal mais valide : une page blanche. Suffit à ce que le
    // téléchargement renvoie un document que le navigateur accepte.
    if (!file_exists($abs)) {
        $written = @file_put_contents($abs, // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
            "%PDF-1.4\n"
            . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\n"
            . "trailer<</Root 1 0 R>>\n%%EOF\n"
        );

        // Échouer bruyamment plutôt que d'enregistrer en base un chemin qui
        // ne mène nulle part. Un jeu de données qui ment coûte cher : le
        // scénario échoue bien plus loin, sur un téléchargement vide ou une
        // case grisée, et rien ne désigne la vraie cause. C'est arrivé —
        // le dossier appartenait à root, l'écriture échouait en silence et
        // les lignes s'écrivaient quand même.
        if ($written === false) {
            WP_CLI::error(sprintf(
                'Écriture impossible dans %s. Le peuplement tourne-t-il sous le compte du serveur web ' .
                '(www-data) ? Un dossier créé par root lui reste inaccessible en écriture.',
                dirname($abs)
            ));
        }
    }

    $wpdb->update(
        psc_table('child_school_years'),
        array(
            'assurance_file_path'         => $rel,
            'assurance_original_filename' => 'attestation-assurance.pdf',
            'assurance_uploaded_at'       => current_time('mysql'),
        ),
        array('child_id' => (int) $child_id, 'school_year_id' => (int) $school_year_id),
        array('%s', '%s', '%s'),
        array('%d', '%d')
    );
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

    $tz = wp_timezone();

    $fmt = function (DateTime $d) {
        return $d->format('Y-m-d');
    };

    /* ---------------------------------------------------------------- */
    /* Ancrage temporel                                                  */
    /* ---------------------------------------------------------------- */

    // Le peuplement ne part plus de la date réelle mais d'un jour d'école
    // choisi pour que le parcours soit jouable, et fige l'horloge dessus.
    //
    // Auparavant le trimestre était calé sur « aujourd'hui ». Lancé pendant
    // les vacances, le scénario ne trouvait plus de jour à la fois ouvert et
    // déjà verrouillé — le premier jour d'école était à plus d'une semaine
    // quand le verrou n'en couvre que deux — et la suite échouait tous les
    // ans à la même période avant de redevenir verte d'elle-même.
    //
    // L'ancre est cherchée dans le calendrier réel plutôt que codée en dur :
    // le jeu de données reste fidèle aux vraies vacances, et ne périme pas
    // quand on change d'année scolaire.
    $anchor = psc_seed_find_anchor($tz);
    if (!$anchor) {
        WP_CLI::error(
            'Aucun jour d\'ancrage trouvé sur les 400 prochains jours : le calendrier scolaire '
            . 'est-il importé, et psc_lock_hours() (' . psc_lock_hours() . 'h) laisse-t-il une fenêtre exploitable ?'
        );
    }

    update_option('psc_test_frozen_now', $anchor->getTimestamp(), false);
    WP_CLI::log(sprintf(
        '  horloge figée ....... %s (ancrage sur un jour d\'école)',
        $anchor->format('D d/m/Y H:i')
    ));

    $today = (clone $anchor)->setTime(0, 0);

    /* ---------------------------------------------------------------- */
    /* Configuration par profil                                          */
    /* ---------------------------------------------------------------- */

    if ($profile === 'test') {

        $date_debut = $fmt((clone $today)->modify('-3 days'));
        $date_fin   = $fmt((clone $today)->modify('+45 days'));

        $config = array(
            'school_year_label' => 'Année de test',
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
            'school_year_label' => "Année {$y}-" . ($y + 1),
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
    $t_years  = psc_table('school_years');
    $t_cy     = psc_table('child_school_years');
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
            $wpdb->query($wpdb->prepare("DELETE FROM $t_cy WHERE child_id IN ($placeholders)", $child_ids));
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
        // Les factures sont désormais mensuelles (parent_id + mois), plus
        // trimestrielles : déjà purgées ci-dessus via le parent_id.
        $wpdb->delete($t_trim, array('id' => $trim_id), array('%d'));
    }

    $old_year_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM $t_years WHERE label = %s", $config['school_year_label']
    ));
    foreach ($old_year_ids as $year_id) {
        $wpdb->delete($t_cy, array('school_year_id' => $year_id), array('%d'));
        $wpdb->delete($t_years, array('id' => $year_id), array('%d'));
    }

    /* ---------------------------------------------------------------- */
    /* Recréation                                                        */
    /* ---------------------------------------------------------------- */

    // Une seule année scolaire active à la fois, même principe que pour le
    // trimestre : Psc_School_Years::activate() archive l'ancienne active
    // avant d'activer celle du seed.
    $school_year_id = Psc_School_Years::create($config['school_year_label'], $config['date_debut'], $config['date_fin']);
    if (is_wp_error($school_year_id)) {
        WP_CLI::error('Création de l\'année scolaire : ' . $school_year_id->get_error_message());
    }
    Psc_School_Years::activate($school_year_id);

    // Un seul trimestre actif à la fois pour que Psc_Frontend::active_trimestre()
    // pointe sans ambiguïté vers celui du seed.
    $wpdb->query("UPDATE $t_trim SET active = 0");

    $wpdb->insert($t_trim, array(
        'label'          => $config['trimestre_label'],
        'school_year_id' => $school_year_id,
        'date_debut'     => $config['date_debut'],
        'date_fin'       => $config['date_fin'],
        'active'         => 1,
    ), array('%s', '%d', '%s', '%s', '%d'));
    $trimestre_id = (int) $wpdb->insert_id;

    Psc_Installer::generate_calendar_days($trimestre_id, $config['date_debut'], $config['date_fin']);

    // onboarding_seen_at fixé à la création : ces specs ne testent pas la
    // popin de découverte, qui bloquerait sinon les clics Playwright sur
    // le reste du portail (cf. templates/frontend-portal.php).
    $parent_id = Psc_Parents::create($config['parent_email'], $config['parent_nom'], array(
        'onboarding_seen_at' => current_time('mysql'),
    ));
    if (is_wp_error($parent_id)) {
        WP_CLI::error('Création du parent : ' . $parent_id->get_error_message());
    }

    $children_ids = array();
    foreach ($config['enfants'] as $c) {
        $wpdb->insert($t_child, array(
            'parent_id'  => $parent_id,
            'nom'        => $c['nom'],
            'prenom'     => $c['prenom'],
            'statut'     => 'actif',
            'created_at' => current_time('mysql'),
        ), array('%d', '%s', '%s', '%s', '%s'));
        $child_id = (int) $wpdb->insert_id;
        $children_ids[] = $child_id;

        Psc_School_Years::enroll($child_id, $school_year_id, $c['classe'], 'inscrit', current_time('mysql'));
        psc_seed_attach_assurance($child_id, $school_year_id);
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
        // Page portant le formulaire, telle que l'extension la résout
        // elle-même pour construire ses liens de connexion. Les scénarios
        // visaient auparavant un ?page_id= codé en dur, qui ne valait que
        // pour l'installation de développement où ce numéro avait été
        // observé : sur un site fraîchement installé, la page en porte un
        // autre et la page ouverte n'était pas la bonne.
        'form_page_url'    => Psc_Mailer::form_page_url(),
    )));

    WP_CLI::success("Seed '$profile' prêt.");
});
