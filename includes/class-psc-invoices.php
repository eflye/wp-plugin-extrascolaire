<?php
if (!defined('ABSPATH')) exit;

class Psc_Invoices {

    /**
     * Mois facturables : ceux de l'année scolaire (le planning étant
     * calculé — rythme + exceptions —, tout mois scolaire est facturable,
     * qu'une famille ait ou non déclaré ce mois-là) plus ceux pour
     * lesquels une facture existe déjà (historique). Triés du plus récent.
     */
    public static function months_with_data() {
        $months = array();

        foreach (Psc_School_Year::all() as $year) {
            $cursor = new DateTime($year->date_start);
            $end = new DateTime($year->date_end);
            $guard = 0;
            while ($cursor <= $end && $guard++ < 24) {
                $months[$cursor->format('Y-m')] = true;
                $cursor->modify('first day of next month');
            }
        }

        global $wpdb;
        $rows = $wpdb->get_col('SELECT DISTINCT mois FROM ' . psc_table('invoices'));
        if ($rows) {
            foreach ($rows as $m) $months[$m] = true;
        }

        $months = array_keys($months);
        sort($months);
        return array_reverse($months);
    }

    /**
     * Génère les factures PDF pour toutes les familles actives ayant des
     * déclarations sur le mois donné. Retourne le nombre de factures créées.
     */
    public static function generate_month($mois) {
        global $wpdb;

        if (!preg_match('/^\d{4}-\d{2}$/', $mois)) {
            return new WP_Error('invalid_month', __('Format de mois invalide.', 'periscolaire-registration'));
        }

        // Génération possible pour N'IMPORTE QUEL mois, passé ou futur :
        // les montants viennent de la résolution du planning (rythmes +
        // exceptions), déjà disponible pour les mois à venir — la mairie
        // facture quand elle le décide, la régénération corrige.

        // Toutes les déclarations du mois, en un lot : les déclarations
        // viennent de la source de vérité unique (psc_is_declared /
        // Psc_Planning::declared_map) — jamais de l'ancienne table.
        $dates = Psc_School_Year::school_days_in_month($mois);
        if (!$dates) return 0;

        // Sélection par déclarations réelles du mois, pas par statut
        // actuel : un enfant sorti depuis reste facturé sur son mois
        // passé, un enfant inscrit mais sans déclaration ce mois-là n'a
        // rien à facturer. Les familles désactivées ne reçoivent rien.
        $t_child = psc_table('children');
        $children = $wpdb->get_results(
            "SELECT c.id, c.parent_id FROM $t_child c
             JOIN " . psc_table('parents') . " p ON p.id = c.parent_id
             WHERE p.active = 1"
        );
        if (!$children) return 0;

        $child_ids = array();
        $child_parent = array();
        foreach ($children as $c) {
            $child_ids[] = (int) $c->id;
            $child_parent[(int) $c->id] = (int) $c->parent_id;
        }

        $declared = Psc_Planning::declared_map($child_ids, $dates);
        $forf = psc_forfait_code();

        $parent_ids = array();
        foreach ($child_ids as $cid) {
            foreach ($dates as $date) {
                $day = isset($declared[$cid][$date]) ? $declared[$cid][$date] : array();
                if (!in_array(true, $day, true)) continue;
                $parent_ids[$child_parent[$cid]] = true;
                break;
            }
        }

        $count = 0;
        foreach (array_keys($parent_ids) as $parent_id) {
            $result = self::generate_one((int) $parent_id, $mois);
            if (!is_wp_error($result)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Génère (ou regénère) la facture PDF d'une famille pour un mois donné.
     * La génération et l'envoi sont décorrelés : régénérer remplace le
     * calcul et le PDF mais conserve le statut d'envoi existant.
     * Retourne l'ID de la facture en base, ou WP_Error.
     */
    public static function generate_one($parent_id, $mois) {
        global $wpdb;

        $parent = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('parents') . ' WHERE id = %d',
            $parent_id
        ));
        if (!$parent) {
            return new WP_Error('no_parent', __('Famille introuvable.', 'periscolaire-registration'));
        }

        $t_child = psc_table('children');

        // All children of this parent (shown in PDF even with 0 registrations)
        $children = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t_child WHERE parent_id = %d ORDER BY nom, prenom",
            $parent_id
        ));
        if (empty($children)) {
            return new WP_Error('no_children', __('Aucun enfant pour cette famille.', 'periscolaire-registration'));
        }

        // Déclarations du mois, source de vérité unique : psc_is_declared
        // (rythme habituel + exceptions, jours d'école calculés), puis règle
        // de facturation unique (psc_billing_services).
        $child_ids = array_map(function ($c) { return (int) $c->id; }, $children);
        $dates = Psc_School_Year::school_days_in_month($mois);
        $declared = $dates ? Psc_Planning::declared_map($child_ids, $dates) : array();

        $t_inv    = psc_table('invoices');
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t_inv WHERE parent_id = %d AND mois = %s",
            $parent_id, $mois
        ));

        // Une facture déjà envoyée se recalcule avec la règle sous laquelle
        // elle a été émise (champ « calcul » de son instantané, 1 à défaut) :
        // l'adoption d'une nouvelle règle ne la rectifie jamais d'elle-même.
        // Seul un vrai changement de déclarations peut encore la corriger.
        $regle = psc_billing_rule_version();
        if ($existing && !empty($existing->sent_at)) {
            $stored = json_decode((string) ($existing->lines_json ?? ''), true);
            $regle = is_array($stored) && isset($stored['calcul']) ? (int) $stored['calcul'] : 1;
        }

        // Lignes de facture : une par prestation et par tarif. Chaque jour
        // est facturé au tarif EN VIGUEUR ce jour-là, avec le statut « sans
        // repas » de ce jour-là (P1-16) : un changement de prix ou de statut
        // en cours de mois coupe la ligne en deux, il ne réécrit pas les
        // jours précédents. grid[clé de ligne][child_id] = nombre.
        $sans_repas = Psc_Sans_Repas::periods($child_ids);
        $flags = array();
        $grid = array();
        $lines = array();
        $has_data = false;
        foreach ($declared as $cid => $by_date) {
            foreach ($by_date as $date => $day) {
                $flag = psc_period_contains($sans_repas[$cid] ?? array(), $date);
                if ($flag) $flags[$cid] = true;
                $tariffs = psc_billing_tariffs($date);
                foreach (psc_billing_services($day, $flag, $regle, $date) as $svc) {
                    if (!isset($tariffs[$svc])) continue;
                    $price = round((float) $tariffs[$svc]['price'], 2);
                    $key = $svc . '|' . number_format($price, 2, '.', '');
                    if (!isset($lines[$key])) {
                        $lines[$key] = array('code' => $svc, 'label' => (string) $tariffs[$svc]['label'], 'price' => $price, 'from' => $date);
                    } elseif ($date < $lines[$key]['from']) {
                        $lines[$key]['from'] = $date;
                    }
                    $grid[$key][$cid] = ($grid[$key][$cid] ?? 0) + 1;
                    $has_data = true;
                }
            }
        }
        if (!$has_data) {
            return new WP_Error('no_data', __('Aucune inscription ce mois-ci.', 'periscolaire-registration'));
        }
        $lines = self::order_lines($lines);

        $total = 0.0;
        foreach ($grid as $key => $child_counts) {
            foreach ($child_counts as $cnt) {
                $total += $lines[$key]['price'] * $cnt;
            }
        }

        // Instantané de ce qui a servi au calcul : lignes, tarifs appliqués
        // et statut « sans repas » de chaque enfant. C'est lui, et non les
        // réglages courants, qui dira demain ce que portait la facture.
        $snapshot = self::build_snapshot($children, $grid, $lines, $flags, $total, $regle);

        $version = 1;
        if ($existing) {
            $invoice_id = (int) $existing->id;
            $version    = max(1, (int) $existing->version);
            $issued     = !empty($existing->sent_at);
            $changed    = self::snapshot_differs($existing, $snapshot, $total);

            // Facture émise et calcul inchangé : on ne réécrit RIEN, pas
            // même le PDF — son en-tête porte la date du jour, le régénérer
            // modifierait un document déjà remis à la famille.
            if ($issued && !$changed) {
                return $invoice_id;
            }

            if ($issued) {
                // Correction : la version remise à la famille est archivée
                // telle quelle, PDF compris, avant toute écriture.
                $archived = self::archive_version($existing);
                if (is_wp_error($archived)) return $archived;

                $version++;
                $wpdb->update($t_inv, array(
                    'total'      => $total,
                    'version'    => $version,
                    'lines_json' => wp_json_encode($snapshot),
                    'pdf_path'   => null,
                    // La nouvelle version n'a pas été envoyée : la famille
                    // détient encore la précédente.
                    'sent_at'    => null,
                    'created_at' => current_time('mysql'),
                ), array('id' => $invoice_id));

                if (class_exists('Psc_Audit')) {
                    Psc_Audit::log('facture.rectification', array(
                        'objet_type' => 'facture', 'objet_id' => $invoice_id, 'famille_id' => (int) $parent_id,
                        'meta' => array(
                            'mois'             => $mois,
                            'version_archivee' => $version - 1,
                            'nouvelle_version' => $version,
                            'total_precedent'  => (float) $existing->total,
                            'total_nouveau'    => $total,
                        ),
                        'resume' => sprintf(
                            __('Facture %s rectifiée : version %d archivée, version %d à envoyer.', 'periscolaire-registration'),
                            $mois, $version - 1, $version
                        ),
                    ));
                }
            } else {
                // Brouillon : recalcul en place, c'est son rôle.
                $wpdb->update($t_inv, array(
                    'total'      => $total,
                    'lines_json' => wp_json_encode($snapshot),
                    'created_at' => current_time('mysql'),
                ), array('id' => $invoice_id));
            }
        } else {
            $wpdb->insert($t_inv, array(
                'parent_id'  => $parent_id,
                'mois'       => $mois,
                'total'      => $total,
                'version'    => 1,
                'lines_json' => wp_json_encode($snapshot),
                'pdf_path'   => null,
                'sent_at'    => null,
                'created_at' => current_time('mysql'),
            ), array('%d', '%s', '%f', '%d', '%s', '%s', '%s', '%s'));
            $invoice_id = (int) $wpdb->insert_id;
        }

        // Generate PDF
        $pdf_path = self::pdf_path($mois, $parent_id);
        if (!wp_mkdir_p(dirname($pdf_path))) {
            return new WP_Error('mkdir_fail', __('Impossible de créer le répertoire des factures.', 'periscolaire-registration'));
        }

        $build_ok = self::build_pdf($parent, $mois, $children, $grid, $lines, $pdf_path, $invoice_id, $version);
        if (is_wp_error($build_ok)) {
            return $build_ok;
        }

        // Store relative path
        $rel_path = str_replace(trailingslashit(psc_private_dir()), '', $pdf_path);
        $wpdb->update($t_inv, array('pdf_path' => $rel_path), array('id' => $invoice_id), array('%s'), array('%d'));

        return $invoice_id;
    }

    /**
     * Lignes dans l'ordre de la grille des tarifs (puis par date d'effet) ;
     * une prestation facturée à deux tarifs dans le mois voit son libellé
     * précisé par la date à partir de laquelle chacun s'applique.
     */
    private static function order_lines(array $lines) {
        $order = array_flip(array_keys(psc_billing_tariffs()));
        uasort($lines, function ($a, $b) use ($order) {
            $oa = $order[$a['code']] ?? 99;
            $ob = $order[$b['code']] ?? 99;
            return $oa === $ob ? strcmp($a['from'], $b['from']) : $oa - $ob;
        });
        $per_code = array_count_values(array_column($lines, 'code'));
        foreach ($lines as $key => $line) {
            if ($per_code[$line['code']] > 1) {
                $lines[$key]['label'] .= ' ' . sprintf(__('(à partir du %s)', 'periscolaire-registration'), date_i18n('d/m', strtotime($line['from'])));
            }
        }
        return $lines;
    }

    /**
     * Instantané de la facture : lignes, tarifs appliqués et statut « sans
     * repas » de chaque enfant au moment de l'émission.
     *
     * C'est la réponse à la question « pourquoi cette facture affiche-t-elle
     * ce montant ? » un an plus tard, quand les tarifs et les statuts ont
     * changé. Sans lui, le seul moyen de la répondre serait de recalculer
     * avec les réglages du jour — c'est-à-dire de répondre faux.
     *
     * Ordre stable (code de service, puis enfant) : deux calculs identiques
     * doivent produire deux instantanés identiques, sans quoi la comparaison
     * de snapshot_differs() créerait des versions fantômes.
     */
    private static function build_snapshot($children, $grid, $lines, $flags, $total, $regle = null) {
        $names = array();
        $enfants = array();
        foreach ($children as $child) {
            $cid = (int) $child->id;
            $names[$cid] = trim($child->prenom . ' ' . $child->nom);
            $enfants[$cid] = array(
                'nom'                => $names[$cid],
                'cantine_sans_repas' => !empty($flags[$cid]) ? 1 : 0,
            );
        }
        ksort($enfants);

        // Clés « code|prix » triées : même ordre qu'avant P1-16 (par code)
        // quand chaque prestation n'a qu'un tarif dans le mois — une facture
        // inchangée garde exactement le même instantané.
        $lignes = array();
        $keys = array_keys($grid);
        sort($keys);
        foreach ($keys as $key) {
            $child_counts = $grid[$key];
            ksort($child_counts);
            $price = round((float) $lines[$key]['price'], 2);
            foreach ($child_counts as $cid => $count) {
                $lignes[] = array(
                    'service'       => (string) $lines[$key]['code'],
                    'libelle'       => (string) $lines[$key]['label'],
                    'enfant_id'     => (int) $cid,
                    'enfant'        => isset($names[$cid]) ? $names[$cid] : null,
                    'quantite'      => (int) $count,
                    'prix_unitaire' => $price,
                    'total'         => round($price * (int) $count, 2),
                );
            }
        }

        return array(
            'calcul'    => $regle === null ? psc_billing_rule_version() : (int) $regle,
            'genere_le' => current_time('mysql'),
            'enfants'   => $enfants,
            'lignes'    => $lignes,
            'total'     => round((float) $total, 2),
        );
    }

    /**
     * Partie facturante d'un instantané, à l'exclusion de la date de
     * génération : deux régénérations successives sans changement doivent
     * se comparer comme identiques.
     */
    private static function snapshot_signature(array $snapshot) {
        return wp_json_encode(array(
            'calcul'  => isset($snapshot['calcul']) ? $snapshot['calcul'] : null,
            'enfants' => isset($snapshot['enfants']) ? $snapshot['enfants'] : array(),
            'lignes'  => isset($snapshot['lignes']) ? $snapshot['lignes'] : array(),
            'total'   => isset($snapshot['total']) ? $snapshot['total'] : null,
        ));
    }

    /**
     * Le calcul a-t-il changé depuis la version en place ?
     *
     * Les factures émises avant l'introduction des instantanés n'en ont pas :
     * pour elles, seul le total peut être comparé. On ne fabrique pas
     * d'instantané rétroactif — il serait reconstruit avec les tarifs
     * d'aujourd'hui, donc faux par construction.
     */
    private static function snapshot_differs($existing, array $snapshot, $total) {
        $stored = isset($existing->lines_json) ? (string) $existing->lines_json : '';
        if ($stored === '') {
            return abs((float) $existing->total - (float) $total) >= 0.005;
        }

        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return true; // instantané illisible : on ne parie pas dessus
        }
        return self::snapshot_signature($decoded) !== self::snapshot_signature($snapshot);
    }

    /**
     * Archive la version en place avant de la remplacer : ligne recopiée
     * dans psc_invoice_versions, PDF déplacé sous un nom versionné.
     *
     * Le PDF est déplacé AVANT l'écriture en base, et un échec interrompt
     * tout : mieux vaut refuser la rectification que se retrouver avec une
     * archive qui référence un fichier écrasé à la seconde d'après. Le
     * document remis à la famille ne disparaît jamais.
     */
    private static function archive_version($existing) {
        global $wpdb;

        $version = max(1, (int) $existing->version);
        $archived_rel = null;

        if (!empty($existing->pdf_path)) {
            $current_abs = psc_private_path($existing->pdf_path);
            if ($current_abs && file_exists($current_abs)) {
                $dir = dirname($current_abs);
                $archived_abs = $dir . '/facture-' . (int) $existing->parent_id . '-v' . $version . '.pdf';

                // Une archive de même nom existe déjà : le numéro de version
                // ne serait plus unique, on refuse plutôt que d'écraser.
                if (file_exists($archived_abs)) {
                    return new WP_Error('psc_archive_exists', sprintf(
                        __('Une archive de la version %d existe déjà pour cette facture.', 'periscolaire-registration'),
                        $version
                    ));
                }
                if (!@rename($current_abs, $archived_abs)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                    return new WP_Error('psc_archive_failed', __('Impossible d’archiver le PDF de la facture précédente.', 'periscolaire-registration'));
                }
                $archived_rel = str_replace(trailingslashit(psc_private_dir()), '', $archived_abs);
            }
        }

        $inserted = $wpdb->insert(psc_table('invoice_versions'), array(
            'invoice_id'  => (int) $existing->id,
            'parent_id'   => (int) $existing->parent_id,
            'mois'        => (string) $existing->mois,
            'version'     => $version,
            'total'       => (float) $existing->total,
            'lines_json'  => isset($existing->lines_json) ? $existing->lines_json : null,
            'pdf_path'    => $archived_rel,
            'sent_at'     => $existing->sent_at,
            'created_at'  => $existing->created_at,
            'archived_at' => current_time('mysql'),
        ), array('%d', '%d', '%s', '%d', '%f', '%s', '%s', '%s', '%s', '%s'));

        if ($inserted === false) {
            return new WP_Error('psc_archive_failed', __('Impossible d’enregistrer la version archivée de la facture.', 'periscolaire-registration'));
        }

        return true;
    }

    /**
     * Versions archivées d'une facture, de la plus récente à la plus
     * ancienne. Les documents remis à la famille restent consultables même
     * après rectification.
     */
    public static function versions_for_invoice($invoice_id) {
        global $wpdb;
        $t = psc_table('invoice_versions');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE invoice_id = %d ORDER BY version DESC",
            (int) $invoice_id
        ));
    }

    /**
     * Mode debug de la suppression des factures : activé uniquement par
     * WP-CLI (`wp option update psc_invoice_debug_delete 1`), pour les
     * environnements de test. Désactivé par défaut, et jamais proposé à
     * l'écran : en production, une facture envoyée ne se supprime pas.
     */
    public static function debug_delete_enabled() {
        return (bool) get_option('psc_invoice_debug_delete', false);
    }

    /**
     * Supprime les factures d'un mois.
     *
     * Mode production (par défaut) : seules les factures jamais envoyées
     * sont supprimées. Une facture envoyée est une pièce reçue par la
     * famille : elle est conservée, de même qu'une facture rectifiée après
     * envoi (sa version reçue est archivée dans invoice_versions) et les
     * versions archivées elles-mêmes.
     *
     * Mode debug (cf. debug_delete_enabled()) : tout le mois est supprimé,
     * PDF et versions archivées compris.
     *
     * @return array|WP_Error ['deleted' => n, 'kept' => n, 'debug' => bool]
     */
    public static function delete_month($mois) {
        global $wpdb;

        if (!preg_match('/^\d{4}-\d{2}$/', $mois)) {
            return new WP_Error('invalid_month', __('Format de mois invalide.', 'periscolaire-registration'));
        }

        $t_inv  = psc_table('invoices');
        $t_invv = psc_table('invoice_versions');
        $debug  = self::debug_delete_enabled();

        if ($debug) {
            $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM $t_inv WHERE mois = %s", $mois)));
        } else {
            // Ni envoyée, ni porteuse d'une version déjà reçue par la famille.
            $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT i.id FROM $t_inv i
                 WHERE i.mois = %s AND i.sent_at IS NULL
                   AND NOT EXISTS (SELECT 1 FROM $t_invv v WHERE v.invoice_id = i.id)",
                $mois
            )));
        }
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_inv WHERE mois = %s", $mois));

        $paths = array();
        $deleted = 0;
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '%d'));
            // PDF à effacer, lus AVANT la suppression des lignes.
            $paths = $wpdb->get_col($wpdb->prepare(
                "SELECT pdf_path FROM $t_inv WHERE id IN ($ph) AND pdf_path IS NOT NULL AND pdf_path <> ''",
                $ids
            ));
            if ($debug) {
                // Versions archivées du mois : seulement en debug.
                $paths = array_merge($paths, $wpdb->get_col($wpdb->prepare(
                    "SELECT pdf_path FROM $t_invv WHERE mois = %s AND pdf_path IS NOT NULL AND pdf_path <> ''",
                    $mois
                )));
                $wpdb->query($wpdb->prepare("DELETE FROM $t_invv WHERE mois = %s", $mois));
            }
            $deleted = (int) $wpdb->query($wpdb->prepare("DELETE FROM $t_inv WHERE id IN ($ph)", $ids));
        }

        foreach ($paths as $rel) {
            $abs = psc_private_path($rel);
            if ($abs && file_exists($abs)) {
                @unlink($abs); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }
        // Le répertoire du mois, s'il est désormais vide, disparaît.
        $dir = psc_private_path('periscolaire/factures/' . $mois);
        if ($dir && is_dir($dir)) {
            @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        return array('deleted' => $deleted, 'kept' => max(0, $total - $deleted), 'debug' => $debug);
    }

    /**
     * Familles en prélèvement SEPA pour un mois, avec le montant de leur
     * facture : matière de l'export .ods du backoffice (la mairie saisit
     * les prélèvements une à une dans son outil bancaire — ce fichier lui
     * donne tout ce qu'il faut, une ligne par famille).
     *
     * Exige que les factures du mois soient générées : le montant vient
     * de la facture, pas d'un recalcul parallèle — la famille reçoit un
     * PDF et la banque un prélèvement du MÊME montant.
     *
     * @param string $mois 'Y-m'
     * @return array<int, array<string,mixed>>|WP_Error Liste vide si aucun
     *                          prélèvement ; WP_Error 'not_generated' si le
     *                          mois n'a pas été généré.
     */
    public static function sepa_rows($mois) {
        global $wpdb;

        if (!preg_match('/^\d{4}-\d{2}$/', $mois)) {
            return new WP_Error('invalid_month', __('Format de mois invalide.', 'periscolaire-registration'));
        }

        $t_inv = psc_table('invoices');
        $t_par = psc_table('parents');

        $generated = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t_inv WHERE mois = %s", $mois
        ));
        if (!$generated) {
            return new WP_Error('not_generated', __('Générez d\'abord les factures du mois.', 'periscolaire-registration'));
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.id AS invoice_id, i.total, i.created_at,
                    p.nom, p.prenom, p.email, p.sepa_iban, p.sepa_bic,
                    p.sepa_titulaire, p.sepa_adresse, p.sepa_code_postal,
                    p.sepa_ville, p.sepa_mandate_ref, p.sepa_reglement_accepted_at, p.sepa_country
             FROM $t_inv i
             JOIN $t_par p ON p.id = i.parent_id
             WHERE i.mois = %s AND p.payment_mode = 'prelevement' AND p.active = 1
             ORDER BY p.nom, p.prenom",
            $mois
        ));
        if (!$rows) return array();

        $out = array();
        foreach ($rows as $r) {
            // IBAN déchiffré : la banque a besoin du numéro complet. Une
            // clé de chiffrement tournée rend le déchiffrement impossible :
            // la cellule porte une mention visible, jamais un IBAN vide
            // qui passerait inaperçu dans un lot de prélèvements.
            $iban = psc_decrypt($r->sepa_iban);
            $out[] = array(
                'invoice_id'   => (int) $r->invoice_id,
                'titulaire'    => trim((string) $r->sepa_titulaire) !== '' ? $r->sepa_titulaire : trim($r->prenom . ' ' . $r->nom),
                'email'        => $r->email,
                'iban'         => $iban !== null ? $iban : __('ILLISIBLE — ressaisir (clé de chiffrement changée)', 'periscolaire-registration'),
                'bic'          => (string) $r->sepa_bic,
                'adresse'      => (string) $r->sepa_adresse,
                'code_postal'  => (string) $r->sepa_code_postal,
                'ville'        => (string) $r->sepa_ville,
                'mandate_ref'  => (string) $r->sepa_mandate_ref,
                'mandate_accepted_at' => (string) $r->sepa_reglement_accepted_at,
                'country'      => (string) $r->sepa_country,
                'montant_decimal' => (string) $r->total,
                'invoice_ref'  => 'FC-' . str_replace('-', '', $mois) . '-' . (int) $r->invoice_id,
                'mois_label'   => self::month_label($mois),
                'montant'      => (float) $r->total,
            );
        }
        return $out;
    }

    private static function sepa_export_headers() {
        return array(
            __('Titulaire du compte', 'periscolaire-registration'),
            __('E-mail', 'periscolaire-registration'),
            __('IBAN', 'periscolaire-registration'),
            __('BIC', 'periscolaire-registration'),
            __('Adresse', 'periscolaire-registration'),
            __('Code postal', 'periscolaire-registration'),
            __('Ville', 'periscolaire-registration'),
            __('Référence mandat SEPA', 'periscolaire-registration'),
            __('N° facture', 'periscolaire-registration'),
            __('Mois', 'periscolaire-registration'),
            __('Montant à prélever (€)', 'periscolaire-registration'),
            __('Objet du prélèvement', 'periscolaire-registration'),
        );
    }

    /** CSV UTF-8 avec BOM, séparateur français et protection contre les formules. */
    public static function build_sepa_csv($target, array $rows) {
        $stream = fopen($target, 'wb');
        if (!$stream) {
            return new WP_Error('csv_fail', __('Création du fichier d’export impossible.', 'periscolaire-registration'));
        }
        $ok = fwrite($stream, "\xEF\xBB\xBF") !== false;
        $ok = fputcsv($stream, self::sepa_export_headers(), ';', '"', '') !== false && $ok;
        foreach ($rows as $r) {
            $data = array(
                $r['titulaire'], $r['email'], $r['iban'], $r['bic'],
                $r['adresse'], $r['code_postal'], $r['ville'],
                $r['mandate_ref'], $r['invoice_ref'], $r['mois_label'],
                number_format($r['montant'], 2, ',', ''),
                __('Périscolaire — ', 'periscolaire-registration') . $r['mois_label'],
            );
            $ok = fputcsv($stream, array_map('psc_csv_escape', $data), ';', '"', '') !== false && $ok;
        }
        $ok = fclose($stream) && $ok;
        return $ok ? true : new WP_Error('csv_fail', __('Écriture du fichier d’export impossible.', 'periscolaire-registration'));
    }

    /**
     * Construit le classeur .ods des prélèvements du mois dans le fichier
     * temporaire donné — ZIP ODF minimal : mimetype non compressé en
     * première entrée, manifeste, contenu. Ouvrable nativement dans
     * LibreOffice Calc (cible) et Excel.
     *
     * @param string $target Chemin du fichier .ods à écrire.
     * @param array  $rows   Retour de sepa_rows().
     * @return true|WP_Error
     */
    public static function build_sepa_ods($target, array $rows) {
        $headers = self::sepa_export_headers();

        $data = array();
        foreach ($rows as $r) {
            $data[] = array(
                $r['titulaire'],
                $r['email'],
                $r['iban'],
                $r['bic'],
                $r['adresse'],
                $r['code_postal'],
                $r['ville'],
                $r['mandate_ref'],
                $r['invoice_ref'],
                $r['mois_label'],
                array('float' => $r['montant']),
                __('Périscolaire — ', 'periscolaire-registration') . $r['mois_label'],
            );
        }

        if (!class_exists('ZipArchive')) {
            return new WP_Error('no_zip', __('L\'extension PHP ZipArchive est requise pour générer l\'export.', 'periscolaire-registration'));
        }

        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('zip_fail', __('Création du fichier d\'export impossible.', 'periscolaire-registration'));
        }
        $zip->addFromString('mimetype', psc_ods_mimetype());
        if (method_exists($zip, 'setCompressionIndex')) {
            $zip->setCompressionIndex(0, ZipArchive::CM_STORE); // mimetype non compressé (convention ODF)
        }
        $zip->addFromString('META-INF/manifest.xml', psc_ods_manifest_xml());
        $zip->addFromString('content.xml', psc_ods_content_xml(
            __('Prélèvements', 'periscolaire-registration'),
            $headers,
            $data
        ));
        $zip->close();
        return true;
    }

    /**
     * Retourne la liste des factures pour un mois (avec nom et email famille).
     */
    public static function set_payment_received($invoice_id, $received) {
        global $wpdb;
        $table = psc_table('invoices');
        $parents = psc_table('parents');
        $invoice = $wpdb->get_row($wpdb->prepare("SELECT i.*, p.payment_mode FROM $table i JOIN $parents p ON p.id=i.parent_id WHERE i.id=%d", $invoice_id));
        if (!$invoice || $invoice->payment_mode !== 'autre') {
            return new WP_Error('invalid', __('Seules les factures chèque ou espèces peuvent être pointées.', 'periscolaire-registration'));
        }
        $result = $wpdb->update($table, array('payment_received_at' => $received ? ($invoice->payment_received_at ?: current_time('mysql')) : null), array('id' => $invoice_id));
        return $result === false ? new WP_Error('payment_failed', __('Enregistrement du paiement impossible.', 'periscolaire-registration')) : $invoice->mois;
    }

    /** Comptes cumulés jusqu'au mois inclus, y compris les familles inactives. */
    public static function family_accounts($month) {
        global $wpdb;
        $parents = psc_table('parents');
        $invoices = psc_table('invoices');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id AS family_id, p.nom, p.email, p.payment_mode,
                    i.id AS invoice_id, i.mois, i.total, i.payment_received_at
             FROM $parents p LEFT JOIN $invoices i ON i.parent_id=p.id AND i.mois <= %s
             ORDER BY p.nom, p.email, p.id, i.mois DESC", $month
        ));
        $accounts = array();
        foreach ($rows as $row) {
            $id = (int) $row->family_id;
            if (!isset($accounts[$id])) {
                $accounts[$id] = array('name' => $row->nom, 'email' => $row->email, 'paid' => 0, 'due' => 0, 'invoices' => array());
            }
            if (!$row->invoice_id) continue;
            $cents = (int) round((float) $row->total * 100);
            $paid = $row->payment_mode === 'prelevement' || !empty($row->payment_received_at) || $cents === 0;
            $accounts[$id][$paid ? 'paid' : 'due'] += $cents;
            $accounts[$id]['invoices'][] = array('month' => $row->mois, 'cents' => $cents, 'paid' => $paid,
                'direct_debit' => $row->payment_mode === 'prelevement', 'received_at' => $row->payment_received_at);
        }
        return $accounts;
    }

    public static function get_for_month($mois) {
        global $wpdb;
        $t_inv = psc_table('invoices');
        $t_par = psc_table('parents');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, p.nom AS parent_nom, p.email AS parent_email, p.payment_mode
             FROM $t_inv i
             JOIN $t_par p ON p.id = i.parent_id
             WHERE i.mois = %s
             ORDER BY p.nom, p.email",
            $mois
        ));
    }

    /**
     * Retourne les factures PUBLIÉES d'une famille, triées du mois le plus
     * récent au plus ancien. Une génération admin reste un brouillon tant
     * que son envoi n'a pas renseigné sent_at.
     */
    public static function get_for_parent($parent_id) {
        global $wpdb;
        $t_inv = psc_table('invoices');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t_inv WHERE parent_id = %d AND sent_at IS NOT NULL ORDER BY mois DESC",
            $parent_id
        ));
    }

    /**
     * Retourne une facture par son ID (avec nom et email famille).
     */
    public static function get($invoice_id) {
        global $wpdb;
        $t_inv = psc_table('invoices');
        $t_par = psc_table('parents');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT i.*, p.nom AS parent_nom, p.email AS parent_email, p.payment_mode
             FROM $t_inv i
             JOIN $t_par p ON p.id = i.parent_id
             WHERE i.id = %d",
            $invoice_id
        ));
    }

    /**
     * Envoie la facture par e-mail avec le PDF en pièce jointe, suivie par
     * Psc_Envois : le même formulaire soumis deux fois (même $lot) ne
     * renvoie pas une facture déjà acceptée ; « Renvoyer » ouvre un nouveau
     * lot. Retourne true si le mail est accepté, sinon WP_Error ('no_file',
     * 'mail_failed', 'not_found').
     */
    public static function send($invoice_id, $lot = '') {
        $invoice = self::get($invoice_id);
        if (!$invoice) {
            return new WP_Error('not_found', __('Facture introuvable.', 'periscolaire-registration'));
        }
        $bilan = Psc_Envois::lancer('facture', (int) $invoice_id, Psc_Envois::lot($lot), array((int) $invoice->parent_id));
        if ($bilan['total'] > 0 && $bilan[Psc_Envois::ACCEPTE] === $bilan['total']) {
            return true;
        }
        if (Psc_Envois::derniere_erreur('facture', (int) $invoice_id, $bilan['lot']) === 'no_file') {
            return new WP_Error('no_file', __('Le fichier PDF est introuvable. Regénérez la facture.', 'periscolaire-registration'));
        }
        return new WP_Error('mail_failed', __('L\'envoi du mail a échoué.', 'periscolaire-registration'));
    }

    /**
     * Expéditeur d'un envoi de facture (cf. Psc_Envois::sender()) : relit la
     * facture et l'adresse de la famille au moment de l'envoi, envoie le
     * PDF, puis date l'envoi. Si cette datation échoue, l'envoi reste
     * accepté dans Psc_Envois : la facture ne sera pas renvoyée en double.
     */
    public static function deliver($invoice_id, $famille_id = null) {
        $invoice = self::get($invoice_id);
        if (!$invoice) {
            return new WP_Error('not_found', __('Facture introuvable.', 'periscolaire-registration'));
        }

        $pdf_path = psc_private_path($invoice->pdf_path);

        if (!file_exists($pdf_path)) {
            return new WP_Error('no_file', __('Le fichier PDF est introuvable. Regénérez la facture.', 'periscolaire-registration'));
        }

        $nom         = $invoice->parent_nom ?: $invoice->parent_email;
        $month_label = self::month_label($invoice->mois);
        $commune     = get_option('blogname', __('la mairie', 'periscolaire-registration'));
        $site_name   = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        $subject = Psc_Email_Templates::subject('invoice', array(
            'mois'    => $month_label,
            'nom'     => $nom,
            'commune' => $commune,
            'total'   => number_format((float) $invoice->total, 2, ',', ' ') . ' €',
        ));

        $body_text = Psc_Email_Templates::body_html('invoice', array(
            'mois'    => $month_label,
            'nom'     => $nom,
            'commune' => $commune,
            'total'   => number_format((float) $invoice->total, 2, ',', ' ') . ' €',
        ));

        $body_html =
            '<h2 style="color:#24405C;font-family:Georgia,\'Times New Roman\',serif;font-weight:bold;font-size:17px;margin:0 0 16px;padding-bottom:8px;border-bottom:1px solid #E5DCC3;">'
            . __('Votre facture périscolaire — ', 'periscolaire-registration') . esc_html($month_label) . '</h2>'
            . '<p style="color:#1A1A1A;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;margin:0 0 20px;">' . $body_text . '</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;">'
            . '<tr><td style="background-color:#F5E7DC;border:1px solid #E5DCC3;border-left:4px solid #E08A5F;padding:16px 18px 16px 14px;font-family:Helvetica,Arial,sans-serif;font-size:14px;color:#1A1A1A;line-height:1.5;">'
            . __('<strong>Montant total :</strong> ', 'periscolaire-registration') . number_format((float) $invoice->total, 2, ',', ' ') . ' €'
            . '</td></tr></table>';

        ob_start();
        $title     = $subject;
        $body_html = $body_html;
        include PSC_PATH . 'templates/email/layout.php';
        $html = ob_get_clean();

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>',
        );

        $ok = wp_mail(
            $invoice->parent_email,
            $subject,
            $html,
            $headers,
            array($pdf_path)
        );

        if ($ok) {
            global $wpdb;
            $wpdb->update(
                psc_table('invoices'),
                array('sent_at' => current_time('mysql')),
                array('id' => $invoice_id),
                array('%s'),
                array('%d')
            );
        }

        return $ok ? true : new WP_Error('mail_failed', __('L\'envoi du mail a échoué.', 'periscolaire-registration'));
    }

    /**
     * Streame le PDF pour téléchargement admin.
     */
    public static function download($invoice_id) {
        $invoice = self::get($invoice_id);
        if (!$invoice) {
            wp_die(esc_html__('Facture introuvable.', 'periscolaire-registration'));
        }

        $pdf_path = psc_private_path($invoice->pdf_path);

        if (!file_exists($pdf_path)) {
            wp_die(esc_html__('Fichier PDF introuvable. Regénérez la facture.', 'periscolaire-registration'));
        }

        // Le PDF quitte le répertoire privé : c'est ici, au point de
        // service unique, que l'accès est journalisé (cf. psc_log_download()).
        psc_log_download('facture', $invoice->pdf_path);

        $slug     = sanitize_file_name($invoice->parent_nom ?: $invoice->parent_email);
        $filename = 'facture-' . $invoice->mois . '-' . $slug . '.pdf';

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($pdf_path));
        header('X-Content-Type-Options: nosniff');
        readfile($pdf_path); // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }

    /**
     * Libellé français d'un mois au format YYYY-MM.
     */
    public static function month_label($mois) {
        // Initialisation paresseuse : un initialisateur de variable static
        // doit être une expression constante avant PHP 8.3 — les appels __()
        // y sont donc interdits (fatal « Constant expression contains
        // invalid operations » en 7.4–8.2, la version requise est 7.4).
        static $mois_fr = null;
        if ($mois_fr === null) {
            $mois_fr = array(
                '01' => __('Janvier', 'periscolaire-registration'),   '02' => __('Février', 'periscolaire-registration'),  '03' => __('Mars', 'periscolaire-registration'),
                '04' => __('Avril', 'periscolaire-registration'),     '05' => __('Mai', 'periscolaire-registration'),       '06' => __('Juin', 'periscolaire-registration'),
                '07' => __('Juillet', 'periscolaire-registration'),   '08' => __('Août', 'periscolaire-registration'),      '09' => __('Septembre', 'periscolaire-registration'),
                '10' => __('Octobre', 'periscolaire-registration'),   '11' => __('Novembre', 'periscolaire-registration'),  '12' => __('Décembre', 'periscolaire-registration'),
            );
        }
        list($year, $month) = explode('-', $mois . '-01');
        return ($mois_fr[$month] ?? $month) . ' ' . $year;
    }

    /* ------------------------------------------------------------------ */

    private static function pdf_path($mois, $parent_id) {
        return psc_private_path(
            'periscolaire/factures/' . $mois
            . '/facture-' . (int) $parent_id . '.pdf'
        );
    }

    /** Encode une chaîne UTF-8 en ISO-8859-1 pour FPDF. */
    private static function enc($str) {
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string) $str);
    }

    /**
     * Formate un montant pour une cellule FPDF.
     * Le signe € est le caractère 0x80 (CP1252) directement — il ne doit pas
     * passer par iconv() qui ne le reconnaît pas en ISO-8859-1.
     */
    private static function price_cell($amount) {
        return number_format((float) $amount, 2, ',', ' ') . ' ' . chr(128);
    }

    /**
     * Dimensions dessinées d'un logo en mm, d'après sa boîte maximale :
     * pont vers psc_logo_fit_dimensions() qui n'a besoin que du ratio de
     * l'image source (pixels). Une image indéchiffrable tombe sur la boîte
     * entière — bornée par construction.
     *
     * @return array{0:float,1:float} [largeur, hauteur] en mm.
     */
    private static function logo_drawn_size($path, $max_w, $max_h) {
        $size = @getimagesize($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $w_px = is_array($size) && !empty($size[0]) ? $size[0] : 0;
        $h_px = is_array($size) && !empty($size[1]) ? $size[1] : 0;
        return psc_logo_fit_dimensions($w_px, $h_px, $max_w, $max_h);
    }

    /** Retourne une date au format "7 juillet 2026" (UTF-8, pour passer ensuite dans enc()). */
    private static function french_full_date($ts) {
        // Initialisation paresseuse, même raison que month_label() :
        // __() n'est pas une expression constante avant PHP 8.3.
        static $mois = null;
        if ($mois === null) {
            $mois = array(
                1=>__('janvier', 'periscolaire-registration'), 2=>__('février', 'periscolaire-registration'),   3=>__('mars', 'periscolaire-registration'),
                4=>__('avril', 'periscolaire-registration'),   5=>__('mai', 'periscolaire-registration'),        6=>__('juin', 'periscolaire-registration'),
                7=>__('juillet', 'periscolaire-registration'), 8=>__('août', 'periscolaire-registration'),       9=>__('septembre', 'periscolaire-registration'),
                10=>__('octobre', 'periscolaire-registration'),11=>__('novembre', 'periscolaire-registration'), 12=>__('décembre', 'periscolaire-registration'),
            );
        }
        $tz = wp_timezone();
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone($tz);
        return $dt->format('j') . ' ' . ($mois[(int) $dt->format('n')] ?? '') . ' ' . $dt->format('Y');
    }

    /**
     * Dessine une cellule de tableau (bordure + texte).
     * Utilisé pour les en-têtes bi-lignes : on dessine la bordure via Rect,
     * puis on positionne le texte manuellement à l'intérieur.
     */
    private static function tbl_header_cell(FPDF $pdf, $x, $y, $w, $h, $line1, $line2 = '') {
        $pdf->Rect($x, $y, $w, $h, 'FD'); // FD = remplissage + bordure
        $pdf->SetFont('Helvetica', 'B', 8);
        if ($line2 === '') {
            $pdf->SetXY($x, $y + ($h - 5) / 2);
            $pdf->Cell($w, 5, $line1, 0, 0, 'C');
        } else {
            $top = $y + ($h - 8) / 2;
            $pdf->SetXY($x, $top);
            $pdf->Cell($w, 4, $line1, 0, 0, 'C');
            $pdf->SetXY($x, $top + 4);
            $pdf->Cell($w, 4, $line2, 0, 0, 'C');
        }
    }

    /**
     * @param object   $parent     Row from wp_psc_parents
     * @param string   $mois       YYYY-MM
     * @param object[] $children   All children of this parent
     * @param array    $grid       $grid[service_code][child_id] = count
     * @param array    $services   psc_billing_tariffs() output
     * @param string   $path       Absolute filesystem path for the PDF
     * @param int      $invoice_id Used to build the invoice number YY-MM-NNN
     * @return true|WP_Error
     */
    private static function build_pdf($parent, $mois, $children, $grid, $services, $path, $invoice_id, $version = 1) {
        require_once PSC_PATH . 'includes/fpdf/fpdf.php';

        // Billing settings
        $org_intro   = get_option('psc_billing_org_intro',   '');
        $org_name    = get_option('psc_billing_org_name',    get_bloginfo('name'));
        $org_address = get_option('psc_billing_org_address', '');
        $org_phone   = get_option('psc_billing_org_phone',   '');
        $org_fax     = get_option('psc_billing_org_fax',     '');
        $org_email   = get_option('psc_billing_org_email',   '');
        $org_city    = get_option('psc_billing_org_city',    '');
        $footer_text = get_option('psc_billing_footer',      '');
        $logo_left_id  = (int) get_option('psc_billing_logo_left_id',  0);
        $logo_right_id = (int) get_option('psc_billing_logo_right_id', 0);

        list($year, $month_num) = explode('-', $mois);
        $invoice_num  = substr($year, 2) . '-' . $month_num . '-' . str_pad($invoice_id, 3, '0', STR_PAD_LEFT);
        // Une rectification porte un numéro distinct : deux documents de
        // montants différents ne doivent jamais circuler sous la même
        // référence, ni chez la famille ni à la comptabilité.
        if ((int) $version > 1) $invoice_num .= '-R' . (int) $version;
        $month_label  = self::month_label($mois);
        $nom_famille  = trim(($parent->nom ?? '') ?: $parent->email);
        $date_fr      = self::french_full_date((int) current_time('timestamp'));
        $city_date    = $org_city ? self::enc($org_city . __(', le ', 'periscolaire-registration') . $date_fr) : self::enc($date_fr);

        // Margins: left=20, right=20 → usable = 170mm
        $ml = 20;
        $mr = 20;
        $pw = 210 - $ml - $mr; // 170

        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetMargins($ml, 15, $mr);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        // ---- HEADER: logos (optional) + texte centré ----
        // Les logos sont BORNÉS en largeur ET en hauteur (proportions
        // conservées) : un blason portrait dessiné à largeur seule
        // débordait de l'en-tête et recouvrait le bloc d'adresse de la
        // famille. La hauteur réellement dessinée repousse le séparateur.
        $valid_exts   = array('JPG', 'JPEG', 'PNG', 'GIF');
        $logo_w       = 35; // largeur du slot de texte réservé de part et d'autre
        $logo_max_h   = 25; // hauteur maximale dessinée — jamais sous le séparateur
        $logo_l_path  = $logo_left_id  ? get_attached_file($logo_left_id)  : '';
        $logo_r_path  = $logo_right_id ? get_attached_file($logo_right_id) : '';
        $has_logo_l   = $logo_l_path && file_exists($logo_l_path)
                        && in_array(strtoupper(pathinfo($logo_l_path, PATHINFO_EXTENSION)), $valid_exts, true);
        $has_logo_r   = $logo_r_path && file_exists($logo_r_path)
                        && in_array(strtoupper(pathinfo($logo_r_path, PATHINFO_EXTENSION)), $valid_exts, true);

        $txt_x = $ml + ($has_logo_l ? $logo_w : 0);
        $txt_w = $pw  - ($has_logo_l ? $logo_w : 0) - ($has_logo_r ? $logo_w : 0);
        $y_hdr = $pdf->GetY();

        $logo_drawn_h = 0;
        if ($has_logo_l) {
            list($lw, $lh) = self::logo_drawn_size($logo_l_path, $logo_w, $logo_max_h);
            $pdf->Image($logo_l_path, $ml, $y_hdr, $lw, $lh);
            $logo_drawn_h = max($logo_drawn_h, $lh);
        }
        if ($has_logo_r) {
            list($rw, $rh) = self::logo_drawn_size($logo_r_path, $logo_w, $logo_max_h);
            $pdf->Image($logo_r_path, $ml + $pw - $rw, $y_hdr, $rw, $rh);
            $logo_drawn_h = max($logo_drawn_h, $rh);
        }

        $pdf->SetXY($txt_x, $y_hdr);
        if ($org_intro) {
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->MultiCell($txt_w, 5, self::enc($org_intro), 0, 'C');
            $pdf->SetX($txt_x);
        }
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->MultiCell($txt_w, 6, self::enc($org_name), 0, 'C');
        $pdf->SetFont('Helvetica', '', 9);
        if ($org_address) {
            $pdf->SetX($txt_x);
            $pdf->MultiCell($txt_w, 4, self::enc($org_address), 0, 'C');
        }
        if ($org_phone || $org_fax) {
            $tel_line = '';
            if ($org_phone) $tel_line = __('Tél : ', 'periscolaire-registration') . $org_phone;
            if ($org_fax)   $tel_line .= ($tel_line ? __(' – Télécopie : ', 'periscolaire-registration') : __('Télécopie : ', 'periscolaire-registration')) . $org_fax;
            $pdf->SetX($txt_x);
            $pdf->MultiCell($txt_w, 4, self::enc($tel_line), 0, 'C');
        }
        if ($org_email) {
            $pdf->SetX($txt_x);
            $pdf->MultiCell($txt_w, 4, self::enc($org_email), 0, 'C');
        }

        $pdf->SetY(max($pdf->GetY(), $y_hdr + $logo_drawn_h) + 4);

        // Séparateur horizontal
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($ml, $pdf->GetY(), 210 - $mr, $pdf->GetY());
        $pdf->Ln(8);

        // ---- BLOC ADRESSE : famille (gauche) | ville + date (droite) ----
        $y_addr = $pdf->GetY();
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetXY($ml, $y_addr);
        $pdf->Cell($pw / 2, 5, self::enc($nom_famille), 0, 2, 'L');
        if (!empty($parent->adresse)) {
            $pdf->SetX($ml);
            $pdf->Cell($pw / 2, 5, self::enc($parent->adresse), 0, 2, 'L');
        }
        if (!empty($parent->code_postal) || !empty($parent->ville)) {
            $pdf->SetX($ml);
            $pdf->Cell($pw / 2, 5, self::enc(trim(($parent->code_postal ?? '') . ' ' . ($parent->ville ?? ''))), 0, 2, 'L');
        }
        if (empty($parent->adresse) && empty($parent->ville)) {
            $pdf->SetX($ml);
            $pdf->Cell($pw / 2, 5, self::enc($parent->email), 0, 0, 'L');
        }

        // Ville + date sur la même ligne que le nom famille
        $pdf->SetXY($ml, $y_addr);
        $pdf->Cell($pw, 5, $city_date, 0, 0, 'R');

        $pdf->SetY($y_addr + 20);

        // ---- RÉFÉRENCE FACTURE ----
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetTextColor(0, 0, 0);
        // "FACTURE N°" en noir, numéro en rouge (comme le PDF de référence)
        $pdf->SetX($ml);
        $pdf->Write(5, self::enc(__('FACTURE N° ', 'periscolaire-registration')));
        $pdf->SetTextColor(180, 30, 30);
        $pdf->Write(5, self::enc($invoice_num));
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(9);

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell($pw, 5, self::enc(__('Prestation du mois de :', 'periscolaire-registration')), 0, 1, 'L');
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell($pw, 5, self::enc($month_label), 0, 1, 'L');
        $pdf->Ln(5);

        // ---- TABLEAU ----
        // Colonnes : nom (large) | tarif par prestation | nombre de prestations | total
        $cw = array(91, 28, 28, 23); // somme = 170

        // En-tête bi-ligne avec bordures manuelles
        $x0  = $ml;
        $y0  = $pdf->GetY();
        $hdr = 11; // hauteur de la ligne d'en-tête

        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.2);
        $pdf->SetTextColor(0, 0, 0);

        // En-tête tableau : #D3D3D3
        $pdf->SetFillColor(211, 211, 211);
        self::tbl_header_cell($pdf, $x0,                            $y0, $cw[0], $hdr, '');
        self::tbl_header_cell($pdf, $x0 + $cw[0],                   $y0, $cw[1], $hdr, self::enc(__('Tarif par', 'periscolaire-registration')),   self::enc(__('prestation', 'periscolaire-registration')));
        self::tbl_header_cell($pdf, $x0 + $cw[0] + $cw[1],          $y0, $cw[2], $hdr, self::enc(__('Nombre de', 'periscolaire-registration')),  self::enc(__('prestations', 'periscolaire-registration')));
        self::tbl_header_cell($pdf, $x0 + $cw[0] + $cw[1] + $cw[2], $y0, $cw[3], $hdr, self::enc(__('Total', 'periscolaire-registration')));

        $pdf->SetXY($x0, $y0 + $hdr);

        $grand_total = 0.0;
        $row_h       = 6;

        foreach ($services as $code => $svc) {
            // Un type de prestation sans AUCUNE occurrence ce mois-ci ne
            // figure pas : ni son bloc de lignes, ni ses enfants à zéro —
            // la facture ne liste que ce qui est réellement dû. (FSR et
            // les autres suivent la même règle.)
            if (empty($grid[$code])) continue;
            $price = (float) $svc['price'];

            // Ligne service : #E4E4E4
            $pdf->SetFillColor(228, 228, 228);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell($cw[0], $row_h, self::enc(' ' . $svc['label']), 1, 0, 'L', true);
            $pdf->Cell($cw[1], $row_h, self::price_cell($price),       1, 0, 'C', true);
            $pdf->Cell($cw[2], $row_h, '',                              1, 0, 'C', true);
            $pdf->Cell($cw[3], $row_h, '',                              1, 1, 'C', true);

            // Lignes enfant : fond blanc
            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetFont('Helvetica', '', 9);
            foreach ($children as $child) {
                $cnt         = isset($grid[$code][$child->id]) ? (int) $grid[$code][$child->id] : 0;
                $line_total  = $cnt * $price;
                $grand_total += $line_total;

                $child_label = '   ' . $child->nom . ' ' . $child->prenom;
                $cnt_display = $cnt > 0 ? (string) $cnt : '';

                $pdf->Cell($cw[0], $row_h, self::enc($child_label),       1, 0, 'L', true);
                $pdf->Cell($cw[1], $row_h, '',                             1, 0, 'C', true);
                $pdf->Cell($cw[2], $row_h, $cnt_display,                   1, 0, 'C', true);
                $pdf->Cell($cw[3], $row_h, self::price_cell($line_total), 1, 1, 'R', true);
            }
        }

        // Ligne total : #D3D3D3
        $pdf->SetFillColor(211, 211, 211);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell($cw[0] + $cw[1], $row_h, '',                              1, 0, 'C', true);
        $pdf->Cell($cw[2],           $row_h, __('TOTAL', 'periscolaire-registration'),   1, 0, 'R', true);
        $pdf->Cell($cw[3],           $row_h, self::price_cell($grand_total),  1, 1, 'R', true);

        // ---- PIED DE PAGE ----
        if ($footer_text && ($parent->payment_mode ?? 'autre') === 'prelevement') {
            $pdf->Ln(10);
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Write(5, self::enc($footer_text));
        }

        $pdf->Output('F', $path);
        return true;
    }
}
