<?php
if (!defined('ABSPATH')) exit;

/**
 * Écrans Planning (variantes 1 et 2) : enregistrement automatique par AJAX,
 * sans bouton « Enregistrer ». Les deux variantes lisent et écrivent LE MÊME
 * modèle (rythme habituel + exceptions) — une saisie faite dans l'une se
 * retrouve dans l'autre, aucune synchronisation.
 *
 * Points d'entrée :
 *  - psc_toggle_exception            {child_id, date, service_code}  — le serveur
 *    calcule l'état effectif et écrit ou SUPPRIME selon l'invariant
 *    (jamais d'exception dont la valeur égale le rythme) ;
 *  - psc_toggle_exception_bulk       {child_id, service_code, dates} — bouton
 *    « Tout / Retirer » par colonne (Planning - 1, portée mois affiché) ;
 *  - psc_toggle_pattern              {child_id, weekday, service_code} —
 *    une case cochée = une ligne de psc_pattern, valable jusqu'en juillet ;
 *  - psc_apply_pattern_to_siblings   {source_child_id} — copie du rythme ;
 *  - psc_reset_month_exceptions      {child_id, year, month} — « revenir
 *    au rythme » pour le mois affiché de l'enfant actif ;
 *  - psc_load_month                  {child_id, year, month} — jours du mois
 *    avec état effectif + origine (pattern | exception | none) + verrou ;
 *  - psc_confirm                     — « Valider et recevoir mon planning » :
 *    récapitulatif ANNUEL par e-mail.
 *
 * Sécurité : chaque appel porte le nonce WordPress (psc_front) ET le jeton
 * propre à la famille (psc_verify_parent_nonce) ; le serveur vérifie que
 * l'enfant appartient au foyer connecté sur les SEPT points d'entrée. Le
 * verrou de 48 h est revérifié côté serveur sur chaque écriture.
 *
 * Chaque réponse d'écriture renvoie l'état complet du planning (psc_load_month
 * + frise + récapitulatifs) : le client re-rend sans recharger la page.
 *
 * Les parents ne sont PAS des utilisateurs WordPress : les actions AJAX
 * sont donc exposées en "nopriv". L'autorisation est vérifiée dans chaque
 * handler via la session du plugin.
 */
class Psc_Frontend_Inscriptions extends Psc_Frontend_Base {

    public static function init() {
        foreach (array(
            'psc_toggle_exception',
            'psc_toggle_exception_bulk',
            'psc_toggle_pattern',
            'psc_apply_pattern_to_siblings',
            'psc_reset_month_exceptions',
            'psc_load_month',
            'psc_confirm',
        ) as $action) {
            add_action('wp_ajax_nopriv_' . $action, array(__CLASS__, 'ajax_' . substr($action, 4)));
            add_action('wp_ajax_' . $action, array(__CLASS__, 'ajax_' . substr($action, 4)));
        }

        // "Annulation prestations" : popin du tableau de bord, POST classique.
        add_action('admin_post_nopriv_psc_cancel_absence', array(__CLASS__, 'handle_cancel_absence'));
        add_action('admin_post_psc_cancel_absence', array(__CLASS__, 'handle_cancel_absence'));
    }

    /* ================================================================
     * SOCHE COMMUN DES APPELS AJAX
     * ================================================================ */

    /** Session famille + double nonce (WordPress + jeton propre au foyer). */
    protected static function ajax_parent() {
        check_ajax_referer('psc_front', 'nonce');

        $parent = Psc_Parents::current();
        if (!$parent) {
            wp_send_json_error(array('code' => 'auth'), 403);
        }
        // Jeton propre à cette famille : le nonce ci-dessus ne distingue pas
        // les visiteurs non connectés entre eux (cf. psc_parent_nonce()).
        if (!psc_verify_parent_nonce('psc_front', $parent->id, psc_post('parent_nonce'))) {
            wp_send_json_error(array('code' => 'auth'), 403);
        }
        return $parent;
    }

    /** Année scolaire configurée, ou erreur 403 (planning fermé). */
    protected static function ajax_year() {
        Psc_School_Year::ensure_default();
        $year = Psc_School_Year::active();
        if (!$year) {
            wp_send_json_error(array('code' => 'closed'), 403);
        }
        return $year;
    }

    /**
     * Enfant appartenant au foyer, actif, avec assurance à jour pour les
     * AJOUTS de déclaration ponctuelle (un retrait n'est jamais bloqué par
     * l'assurance — pas de blocage rétroactif). Le RYTHME habituel
     * (toggle_pattern) est volontairement hors de ce contrôle : il est posé
     * dès l'inscription initiale (cf. Psc_Planning::seed_patterns_from_wizard,
     * appelé par Psc_Requests::approve_request sans exigence d'assurance) —
     * l'exiger ici rendait la grille du rythme inerte pour toute famille
     * dont le justificatif n'est pas encore fourni, sans cohérence avec le
     * wizard. Le blocage des JOURS reste porté par les exceptions d'ajout
     * (cf. ajax_toggle_exception) et la notice affichée sur l'écran.
     */
    protected static function ajax_owned_child($parent, $child_id, $allow_add = true) {
        $child = self::owned_child($child_id, $parent->id);
        if (!$child) {
            wp_send_json_error(array('code' => 'notfound'), 404);
        }
        if ($child->statut !== 'actif') {
            wp_send_json_error(array('code' => 'notfound'), 404);
        }
        if ($allow_add && !Psc_Assurances::has_valid((int) $child->id)) {
            wp_send_json_error(array(
                'code'    => 'assurance_missing',
                'message' => __('L\'assurance scolaire de cet enfant n\'a pas été fournie pour l\'année en cours. Ajoutez-la depuis « Mes enfants » pour pouvoir déclarer des jours.', 'periscolaire-registration'),
            ), 403);
        }
        return $child;
    }

    /* ================================================================
     * ÉTAT DU PLANNING — charge utile commune à toutes les réponses
     * ================================================================ */

    /**
     * État complet pour re-rendre l'écran sans recharger la page :
     * cases du mois affiché (état effectif + origine + verrou), rythme de
     * chaque enfant, frise (compteurs par mois — une requête groupée),
     * récapitulatif fratrie du mois et estimation annuelle.
     *
     * $ym vide ou invalide (appelant sans mois exploitable) : retombe sur
     * le mois courant de l'année, comme le rendu initial de la page — un
     * état vide ne doit jamais partir vers le client, la grille serait
     * effacée au re-rendu.
     */
    protected static function planning_state($parent, $child_id, $ym) {
        $children = self::children_of($parent->id, true);
        $year = Psc_School_Year::active();
        $child_ids = array_map(function ($c) { return (int) $c->id; }, $children);

        if (!preg_match('/^\d{4}-\d{2}$/', (string) $ym)) {
            $ym = self::default_month($year);
        }

        // Précharge le calendrier scolaire en 2 requêtes (fermetures
        // importées/manuelles) sur l'année ÉTENDUE aux bornes de ses mois :
        // août 2026 et juillet 2027 débordent de date_start/date_end, et
        // chaque débordement relançait ses propres lectures mois par mois.
        if ($year) {
            $psc_year_months = Psc_School_Year::months($year->year_key);
            if ($psc_year_months) {
                $psc_first = reset($psc_year_months)['key'] . '-01';
                $psc_last  = gmdate('Y-m-t', strtotime(end($psc_year_months)['key'] . '-01'));
                Psc_School_Year::school_days($psc_first, $psc_last);
            }
        }

        $summary  = Psc_Planning::year_summary($children, $year->year_key);
        $patterns = Psc_Planning::load_patterns($child_ids);
        $cells    = Psc_Planning::month_state($child_id, $ym);

        $exceptions_count = 0;
        foreach ($cells['cells'] as $date => $day) {
            if ($day['locked']) continue;
            foreach ($day['services'] as $svc) {
                if ($svc['origin'] === 'exception') $exceptions_count++;
            }
        }

        $per_child = array();
        foreach ($child_ids as $cid) {
            $m = isset($summary['months'][$ym]['per_child'][$cid]) ? $summary['months'][$ym]['per_child'][$cid] : array('days' => 0, 'amount' => 0.0);
            $y = isset($summary['year']['per_child'][$cid]) ? $summary['year']['per_child'][$cid] : array('days' => 0, 'amount' => 0.0);
            $per_child[$cid] = array(
                'month_days'   => (int) $m['days'],
                'month_amount' => round((float) $m['amount'], 2),
                'year_days'    => (int) $y['days'],
                'year_amount'  => round((float) $y['amount'], 2),
            );
        }

        $frieze = array();
        foreach ($summary['months'] as $key => $row) {
            $frieze[$key] = (int) $row['days'];
        }

        $child = null;
        $children_list = array();
        foreach ($children as $c) {
            $children_list[] = array(
                'id'     => (int) $c->id,
                'name'   => trim($c->prenom . ' ' . $c->nom),
                'prenom' => $c->prenom,
                'classe' => Psc_School_Years::classe_for($c->id),
            );
            if ((int) $c->id === (int) $child_id) { $child = $c; }
        }

        return array(
            'year_key'         => $year->year_key,
            'month'            => $ym,
            'month_label'      => self::month_label($year, $ym),
            'dates'            => $cells['dates'],
            'cells'            => $cells['cells'],
            // Cases « explicites » multi-enfants : l'écran Planning - 1 les
            // applique telles quelles (une case cochée = une ligne stockée).
            'explicit'         => Psc_Planning::month_explicit_map($child_ids, $ym),
            // Rythme de l'enfant affiché, DÉJÀ restreint à son année :
            // {weekday => {service => true}} — le client ne doit pas avoir
            // à connaître la clé d'année (niveau [enfant][année] retiré).
            'patterns'         => isset($patterns[$child_id][$year->year_key]) ? $patterns[$child_id][$year->year_key] : array(),
            'all_patterns'     => $patterns,
            'children_list'    => $children_list,
            'frieze'           => $frieze,
            'per_child'        => $per_child,
            'month_days'       => isset($summary['months'][$ym]) ? (int) $summary['months'][$ym]['days'] : 0,
            'month_amount'     => isset($summary['months'][$ym]) ? round((float) $summary['months'][$ym]['amount'], 2) : 0.0,
            'year_days'        => (int) $summary['year']['days'],
            'year_amount'      => round((float) $summary['year']['amount'], 2),
            'exceptions_count' => $exceptions_count,
            'active_child'     => $child ? array(
                'id'     => (int) $child->id,
                'name'   => trim($child->prenom . ' ' . $child->nom),
                'classe' => Psc_School_Years::classe_for($child->id),
            ) : null,
        );
    }

    /** Mois demandé (YYYY-MM), restreint à l'année scolaire active. */
    protected static function ajax_month($year) {
        $requested = psc_post('month');
        $months = wp_list_pluck(Psc_School_Year::months($year->year_key), 'key');
        if (preg_match('/^\d{4}-\d{2}$/', $requested) && in_array($requested, $months, true)) {
            return $requested;
        }
        // Mois courant s'il est dans l'année, sinon le premier.
        $today = current_time('Y-m');
        return in_array($today, $months, true) ? $today : $months[0];
    }

    /** Libellé lisible d'un mois de l'année ('Septembre 2026'). */
    protected static function month_label($year, $ym) {
        foreach (Psc_School_Year::months($year->year_key) as $m) {
            if ($m['key'] === $ym) return $m['label'];
        }
        return $ym;
    }

    /** Mois par défaut (courant s'il est dans l'année, sinon le premier). */
    protected static function default_month($year) {
        $months = wp_list_pluck(Psc_School_Year::months($year->year_key), 'key');
        $today = current_time('Y-m');
        return in_array($today, $months, true) ? $today : (string) reset($months);
    }

    /* ================================================================
     * ENDPOINTS
     * ================================================================ */

    /**
     * Un clic sur une case du planning (variantes 1 et 2, zone exceptions).
     * Le serveur calcule l'état effectif : cocher ce qui est déjà effectif
     * ou décocher ce qui ne l'est pas provoque la SUPPRESSION de toute
     * exception résiduelle (invariant) — cliquer deux fois un même jour ne
     * laisse aucune ligne.
     *
     * L'assurance scolaire ne bloque que l'AJOUT NET (décision d'écriture
     * « upsert » avec cible true). Un retrait, ou le RETOUR AU RYTHME
     * (re-cocher après un retrait : la décision est une suppression),
     * doivent toujours passer — sinon une famille dont le justificatif a
     * expiré ne pourrait plus revenir en arrière, et l'invariant laisserait
     * des exceptions de retrait résiduelles.
     */
    public static function ajax_toggle_exception() {
        $parent = self::ajax_parent();
        $year   = self::ajax_year();

        $child_id = psc_post_int('child_id');
        $date     = psc_valid_date(psc_post('date'));
        $service  = psc_post('service_code');
        $checked  = psc_post('checked') === '1';

        if (!$child_id || !$date || !psc_is_valid_service($service)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }
        if ($date < $year->date_start || $date > $year->date_end) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        $child = self::owned_child($child_id, $parent->id);
        if (!$child || $child->statut !== 'actif') {
            wp_send_json_error(array('code' => 'notfound'), 404);
        }

        // Décision d'écriture (même règle que le moteur) : la base de
        // comparaison est l'état qui prévaudrait sans l'exception de ce
        // triplet — pattern propre, sinon couverture par le forfait.
        $year_key = Psc_School_Year::year_key_for_date($date);
        $weekday  = (int) date('N', strtotime($date));
        $patterns = Psc_Planning::load_patterns(array($child_id));
        $pats     = isset($patterns[$child_id][$year_key][$weekday]) ? $patterns[$child_id][$year_key][$weekday] : array();
        $forf     = psc_forfait_code();
        $decision = psc_exception_write_decision(
            $service === $forf,
            !empty($pats[$service]),
            !empty($pats[$forf]),
            (bool) $checked
        );
        $is_net_add = ($decision === 'upsert' && $checked);

        if ($is_net_add && !Psc_Assurances::has_valid((int) $child->id)) {
            wp_send_json_error(array(
                'code'    => 'assurance_missing',
                'message' => __('L\'assurance scolaire de cet enfant n\'a pas été fournie pour l\'année en cours. Ajoutez-la depuis « Mes enfants » pour pouvoir déclarer des jours.', 'periscolaire-registration'),
            ), 403);
        }

        $result = Psc_Planning::toggle_exception($child_id, $date, $service, $checked);

        if ($result['status'] === 'locked') {
            wp_send_json_error(array(
                'code'    => 'locked',
                'message' => sprintf(
                    __('Ce jour n\'est plus modifiable en ligne (délai de %d h dépassé). Contactez la mairie.', 'periscolaire-registration'),
                    psc_lock_hours()
                ),
            ), 403);
        }
        if ($result['status'] === 'day_closed') {
            wp_send_json_error(array('code' => 'day_closed'), 403);
        }
        if (!in_array($result['status'], array('added', 'removed'), true)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        wp_send_json_success(array(
            'status' => $result['status'],
            'state'  => self::planning_state($parent, $child_id, substr($date, 0, 7)),
        ));
    }

    /**
     * Bouton « Tout / Retirer » par colonne de service (Planning - 1) :
     * écrit les exceptions d'un lot de dates reçues du client (celles rendues
     * non verrouillées au chargement), mais revalide chacune côté serveur —
     * jour d'école, non verrouillé, prestation ouverte — plutôt que de faire
     * confiance à cette liste, dont l'état a pu changer depuis le rendu de
     * la page. Les dates rejetées sont ignorées silencieusement plutôt que
     * d'échouer tout le lot.
     */
    public static function ajax_toggle_exception_bulk() {
        $parent = self::ajax_parent();
        $year   = self::ajax_year();

        $child_id = psc_post_int('child_id');
        $service  = psc_post('service_code');
        $checked  = psc_post('checked') === '1';
        $raw_dates = isset($_POST['dates']) ? wp_unslash($_POST['dates']) : '';
        $raw_dates = is_array($raw_dates) ? $raw_dates : explode(',', (string) $raw_dates);

        if (!$child_id || !psc_is_valid_service($service)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        $dates = array();
        foreach ($raw_dates as $raw) {
            $d = psc_valid_date($raw);
            if ($d && $d >= $year->date_start && $d <= $year->date_end) {
                $dates[$d] = $d; // dédoublonnage
            }
        }
        if (empty($dates)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        $child = self::ajax_owned_child($parent, $child_id, $checked);

        $applied = Psc_Planning::toggle_exception_bulk($child_id, $dates, $service, $checked);

        // Le mois pour re-rendre l'écran : $dates est indexé par les DATES
        // elles-mêmes (dédoublonnage) — $dates[0] serait une clé inexistante
        // et produirait un état vide. Premier élément = premier jour touché.
        $first = reset($dates);

        wp_send_json_success(array(
            'applied' => $applied,
            'state'   => self::planning_state($parent, $child_id, substr($first, 0, 7)),
        ));
    }

    /**
     * Un clic sur la grille du rythme habituel (Planning - 2, étape 1) :
     * une case cochée = une ligne de psc_pattern, valable jusqu'en juillet.
     * Psc_Planning::toggle_pattern gèle les jours verrouillés concernés et
     * purge les exceptions devenues du bruit.
     */
    public static function ajax_toggle_pattern() {
        $parent = self::ajax_parent();
        $year   = self::ajax_year();

        $child_id = psc_post_int('child_id');
        $weekday  = psc_post_int('weekday');
        $service  = psc_post('service_code');
        $checked  = psc_post('checked') === '1';

        if (!$child_id || !psc_is_valid_service($service)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        // Le rythme habituel n'exige pas l'assurance scolaire : il est posé
        // dès l'inscription initiale sans cette exigence (cf.
        // ajax_owned_child() et seed_patterns_from_wizard()) — l'exiger ici
        // rendait la grille du rythme inerte sans cohérence avec le wizard.
        $child = self::ajax_owned_child($parent, $child_id, false);

        $result = Psc_Planning::toggle_pattern($child_id, $year->year_key, $weekday, $service, $checked);

        if ($result['status'] === 'invalid') {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        wp_send_json_success(array(
            'status'  => $result['status'],
            'frozen'  => isset($result['frozen']) ? (int) $result['frozen'] : 0,
            'purged'  => isset($result['purged']) ? (int) $result['purged'] : 0,
            'state'   => self::planning_state($parent, $child_id, self::ajax_month($year)),
        ));
    }

    /**
     * « Appliquer ce rythme à toute la fratrie » : le levier principal des
     * familles nombreuses — 4 enfants au même rythme se déclarent en ~6
     * clics. Les exceptions individuelles préexistantes sont conservées ;
     * les jours verrouillés dont l'état change sont figés.
     */
    public static function ajax_apply_pattern_to_siblings() {
        $parent = self::ajax_parent();
        $year   = self::ajax_year();

        $source_child_id = psc_post_int('source_child_id');
        if (!$source_child_id) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        $source = self::ajax_owned_child($parent, $source_child_id, true);

        $children = self::children_of($parent->id, true);
        $target_ids = array();
        foreach ($children as $c) {
            if ((int) $c->id !== (int) $source->id) $target_ids[] = (int) $c->id;
        }
        if (empty($target_ids)) {
            wp_send_json_error(array('code' => 'nochild'), 400);
        }

        $results = Psc_Planning::apply_pattern_to_siblings($source->id, $target_ids);

        wp_send_json_success(array(
            'results' => $results,
            'state'   => self::planning_state($parent, (int) $source->id, self::ajax_month($year)),
        ));
    }

    /**
     * « N exception(s) ce mois-ci — revenir au rythme » : purge les
     * exceptions du mois affiché pour l'enfant actif (jours non verrouillés).
     */
    public static function ajax_reset_month_exceptions() {
        $parent = self::ajax_parent();
        $year   = self::ajax_year();

        $child_id = psc_post_int('child_id');
        $ym       = self::ajax_month($year);
        if (!$child_id) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        self::ajax_owned_child($parent, $child_id, false);

        $deleted = Psc_Planning::reset_month_exceptions($child_id, $ym);

        wp_send_json_success(array(
            'deleted' => (int) $deleted,
            'state'   => self::planning_state($parent, $child_id, $ym),
        ));
    }

    /**
     * Chargement du mois affiché (navigation ← →, onglets enfants) :
     * jours du mois avec état effectif + origine, jamais l'année entière.
     */
    public static function ajax_load_month() {
        $parent = self::ajax_parent();
        $year   = self::ajax_year();

        $child_id = psc_post_int('child_id');
        if (!$child_id) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }
        self::ajax_owned_child($parent, $child_id, false);

        $ym = self::ajax_month($year);

        wp_send_json_success(array(
            'state' => self::planning_state($parent, $child_id, $ym),
        ));
    }

    /**
     * « Valider et recevoir mon planning » : envoie un récapitulatif ANNUEL
     * — rythme par enfant + exceptions à venir + estimation annuelle.
     */
    public static function ajax_confirm() {
        $parent = self::ajax_parent();
        $year   = self::ajax_year();

        // Évite l'envoi répété de récapitulatifs (clics multiples).
        if (!psc_rate_limit('recap_' . $parent->id, 5, 10 * MINUTE_IN_SECONDS)) {
            wp_send_json_error(array(
                'code'    => 'rate',
                'message' => __('Plusieurs récapitulatifs viennent d\'être envoyés. Merci de patienter quelques minutes.', 'periscolaire-registration'),
            ), 429);
        }

        $children = self::children_of($parent->id, true);
        if (empty($children)) {
            wp_send_json_error(array('code' => 'nochild'), 400);
        }

        $child_ids = array_map(function ($c) { return (int) $c->id; }, $children);
        $summary   = Psc_Planning::year_summary($children, $year->year_key);
        $patterns  = Psc_Planning::load_patterns($child_ids);
        $upcoming  = Psc_Planning::upcoming_exceptions($child_ids, current_time('Y-m-d'));

        $sent = Psc_Mailer::send_recap($parent, $year, $children, $summary, $patterns, $upcoming, psc_services());

        if (!$sent) {
            wp_send_json_error(array(
                'code'    => 'mail',
                'message' => __('L\'envoi de l\'e-mail a échoué. Vos inscriptions sont bien enregistrées ; contactez la mairie si besoin.', 'periscolaire-registration'),
            ), 500);
        }

        wp_send_json_success(array(
            'message' => sprintf(__('Récapitulatif envoyé à %s.', 'periscolaire-registration'), $parent->email),
        ));
    }

    /**
     * "Annulation prestations" depuis le tableau de bord : signale, pour un
     * enfant donné, une sélection de prestations à venir. Dans le nouveau
     * modèle, annuler = écrire une exception de RETRAIT (value false) pour
     * chaque (date, service) — la mairie est notifiée par e-mail, la
     * résolution fait le reste (non facturé, absent des listes). $_POST['items']
     * est un tableau de chaînes "YYYY-MM-DD|SERVICE" — cf.
     * Psc_Frontend::absence_candidates() pour la construction de la liste.
     * Un forfait (FORF) est indivisible : les 3 lignes GM/CANT/GS qui le
     * représentent dans l'UI portent service=FORF, donc cocher n'importe
     * laquelle (ou plusieurs) revient à annuler le même forfait —
     * dédoublonné ci-dessous par date+service. Même ordre de vérification
     * que l'écriture unitaire : appartenance de l'enfant, jour d'école,
     * délai de préavis non dépassé — une prestation qui ne passe pas ces
     * contrôles (entre le chargement de la popin et la soumission) est
     * silencieusement ignorée plutôt que de faire échouer tout le lot.
     */
    public static function handle_cancel_absence() {
        $parent = self::authed_parent('psc_cancel_absence');
        if (!$parent) self::parent_form_redirect('auth');

        $child_id  = psc_post_int('child_id');
        $raw_items = isset($_POST['items']) && is_array($_POST['items']) ? wp_unslash($_POST['items']) : array();

        $pairs = array();
        foreach ($raw_items as $raw) {
            $parts = explode('|', (string) $raw, 2);
            if (count($parts) !== 2) continue;
            $date    = psc_valid_date($parts[0]);
            $service = $parts[1];
            if (!$date || !psc_is_valid_service($service)) continue;
            $pairs[$date . '|' . $service] = array('date' => $date, 'service' => $service);
        }
        if (!$child_id || !$pairs) self::parent_form_redirect('absence_invalid');

        $child = self::owned_child($child_id, $parent->id);
        if (!$child) self::parent_form_redirect('absence_invalid');

        $cancelled_by_date = array(); // date => [services annulés]
        foreach ($pairs as $pair) {
            $date    = $pair['date'];
            $service = $pair['service'];

            if (!Psc_School_Year::is_school_day($date)) continue;
            if (psc_is_locked($date)) continue;

            // Déjà effectivement non déclaré ? Rien à écrire.
            if (!Psc_Planning::is_declared($child_id, $date, $service)) continue;

            $r = Psc_Planning::toggle_exception($child_id, $date, $service, false);
            if ($r['status'] === 'added') {
                $cancelled_by_date[$date][] = $service;
            }
        }

        if (!$cancelled_by_date) self::parent_form_redirect('absence_invalid');

        foreach ($cancelled_by_date as $date => $services) {
            Psc_Mailer::notify_absence_cancelled($parent, $child, $date, $services);
        }

        self::parent_form_redirect('absence_cancelled');
    }
}
