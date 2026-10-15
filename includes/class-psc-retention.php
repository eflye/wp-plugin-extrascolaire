<?php
if (!defined('ABSPATH')) exit;

/**
 * Cycle de vie RGPD des fiches enfants après la fin de la relation avec le
 * service périscolaire (dernière classe, déménagement, retrait manuel par
 * la famille ou par la mairie — cf. Psc_School_Years::mark_sorti()).
 *
 * Sans ce mécanisme, une fiche enfant (identité, allergies/santé, planning,
 * personnes autorisées) reste en base indéfiniment après le départ, ce
 * qu'aucune obligation légale ne justifie ici — à la différence des
 * factures, dont Psc_Privacy documente la conservation décennale distincte
 * (Code de commerce). Le délai par défaut (400 jours, filtrable) couvre,
 * quel que soit le moment de l'année où l'enfant sort, la fin de l'année
 * scolaire en cours et une marge estivale avant purge — le temps qu'un
 * différend ou une inscription tardive d'un cadet de la même famille
 * puisse encore s'appuyer sur ce dossier.
 */
class Psc_Retention {

    /**
     * Rapport de rétention (RGPD-14) : SIMULATION uniquement. Pour chaque
     * catégorie active du registre (psc_retention_policies()), compte les
     * lignes que sa prochaine purge supprimerait, sans rien écrire. Les
     * purges elles-mêmes restent exécutées par leurs tâches planifiées.
     *
     * @return array{mode: string, categories: array, examined: int, removed: int, retained: int, errors: array, next_run: string}
     */
    public static function run($mode = 'simulation', $now = null) {
        $now = $now === null ? time() : (int) $now;
        $report = array('mode' => 'simulation', 'categories' => array(), 'examined' => 0, 'removed' => 0, 'retained' => 0, 'errors' => array(), 'next_run' => gmdate('c', $now + DAY_IN_SECONDS));

        foreach (psc_retention_policies() as $key => $policy) {
            $entry = array(
                'key' => $key, 'label' => $policy['label'], 'days' => $policy['days'], 'hook' => $policy['hook'],
                'examined' => 0, 'removed' => 0, 'retained' => 0,
                'status' => empty($policy['enabled']) ? 'sans purge propre' : 'simulation',
                'reason' => $policy['reason'],
            );
            if (!empty($policy['enabled'])) {
                try {
                    $entry['examined'] = self::count_due($key, (int) $policy['days'], $now);
                } catch (\Throwable $e) {
                    $report['errors'][] = $key;
                }
            }
            $report['categories'][] = $entry;
            $report['examined'] += $entry['examined'];
        }
        return $report;
    }

    /** Lignes que la prochaine purge de la catégorie supprimerait (même critère que la purge). */
    private static function count_due($key, $days, $now) {
        global $wpdb;
        // Colonnes en heure du site (current_time('mysql')) : seuil dans la même horloge.
        $cutoff = wp_date('Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS);
        $t_req = psc_table('requests');
        switch ($key) {
            case 'requests_unconfirmed':
                return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_req WHERE status = 'unverified' AND created_at < %s", $cutoff));
            case 'requests_processed':
                return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_req WHERE status IN ('approved','rejected') AND decided_at < %s", $cutoff));
            case 'requests_pending':
                return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_req WHERE status = 'pending' AND created_at < %s", $cutoff));
            case 'departed_children':
                return self::count_departed_children($now);
            case 'childless_families':
                return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM (' . self::childless_families_sql() . ') x', $cutoff));
            case 'private_files':
                $t_cy = psc_table('child_school_years');
                $t_years = psc_table('school_years');
                return (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $t_cy cy INNER JOIN $t_years y ON y.id = cy.school_year_id
                     WHERE y.date_fin < %s AND y.id <> %d AND cy.assurance_file_path IS NOT NULL",
                    wp_date('Y-m-d', $now), (int) Psc_School_Years::active_id()
                ));
            case 'invoices':
                return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . psc_table('invoices') . ' WHERE mois < %s', self::invoice_retention_cutoff_month($now)));
            case 'conversations':
                return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . psc_table('conversations') . ' WHERE dernier_message_at < %s', $cutoff));
            case 'audit_log':
                $total = 0;
                foreach (array_keys(psc_audit_retention_defaults()) as $niveau) {
                    $actions = psc_audit_actions_by_niveau($niveau);
                    if (!$actions) continue;
                    $total += (int) $wpdb->get_var($wpdb->prepare(
                        'SELECT COUNT(*) FROM ' . psc_table('audit_log') . ' WHERE purgee_le IS NULL AND horodatage < %s AND action IN (' . implode(',', array_fill(0, count($actions), '%s')) . ')',
                        array_merge(array(gmdate('Y-m-d H:i:s', $now - psc_audit_retention_days($niveau) * DAY_IN_SECONDS)), $actions)
                    ));
                }
                return $total;
        }
        return 0;
    }

    public static function init() {
        add_action('psc_purge_departed_children', array(__CLASS__, 'handle_purge_cron'), 10, 0);
        self::ensure_crons();
    }

    /**
     * Tâche quotidienne : enfants sortis, puis foyers sans enfant — un foyer
     * vidé de son dernier enfant suit la même horloge, à partir de sa
     * dernière activité (RGPD-01).
     */
    public static function handle_purge_cron() {
        self::purge_departed_children();
        self::purge_childless_families();
        self::purge_expired_invoices();
        self::purge_past_year_insurance();
    }

    /**
     * Attestations d'assurance des années scolaires terminées (RGPD-11,
     * décision du 15/10/2026) : une attestation ne sert que l'année qu'elle
     * couvre. Supprimée du disque dès que son année est finie (date_fin
     * passée), sauf pour l'année active tant que la mairie ne l'a pas
     * remplacée. La ligne d'inscription est conservée, sans plus aucune
     * trace du document.
     *
     * @return int Nombre d'attestations supprimées.
     */
    public static function purge_past_year_insurance($now = null, $limit = 500) {
        global $wpdb;
        $now = $now === null ? time() : (int) $now;
        $today = wp_date('Y-m-d', $now);
        $t_cy = psc_table('child_school_years');
        $t_years = psc_table('school_years');
        $active = (int) Psc_School_Years::active_id();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT cy.id, cy.assurance_file_path FROM $t_cy cy
             INNER JOIN $t_years y ON y.id = cy.school_year_id
             WHERE y.date_fin < %s AND y.id <> %d AND cy.assurance_file_path IS NOT NULL
             ORDER BY cy.id LIMIT %d",
            $today, $active, max(1, (int) $limit)
        ));
        $done = 0;
        $dirs = array();
        foreach ((array) $rows as $row) {
            $abs = psc_private_path($row->assurance_file_path);
            if ($abs && file_exists($abs)) {
                @unlink($abs); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
            if ($abs) $dirs[dirname($abs)] = true;
            $updated = $wpdb->update($t_cy, array(
                'assurance_file_path'         => null,
                'assurance_original_filename' => null,
                'assurance_uploaded_at'       => null,
                'assurance_revision'          => '',
                'assurance_reviewed_at'       => null,
                'assurance_reviewed_by'       => null,
                'assurance_review_note'       => null,
            ), array('id' => (int) $row->id));
            if ($updated !== false) $done++;
        }
        foreach (array_keys($dirs) as $dir) {
            // Répertoire de l'année retiré s'il est désormais vide.
            if (is_dir($dir)) @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        if ($done) {
            Psc_Audit::log('systeme.purge', array(
                'objet_type' => 'enfant',
                'meta'       => array('portee' => 'attestations_annees_passees', 'attestations' => $done),
                /* translators: %d: nombre d'attestations supprimées */
                'resume'     => sprintf(_n('%d attestation d’assurance d’une année scolaire terminée supprimée.', '%d attestations d’assurance d’années scolaires terminées supprimées.', $done, 'periscolaire-registration'), $done),
            ));
        }
        return $done;
    }

    /** Purge des factures activée par la mairie (désactivée par défaut, RGPD-08). */
    public static function invoice_retention_enabled() {
        return (bool) get_option('psc_invoice_retention_enabled', 0);
    }

    /** Années révolues de conservation des factures : 10 au minimum (pièces comptables). */
    public static function invoice_retention_years() {
        return max(10, (int) get_option('psc_invoice_retention_years', 10));
    }

    /**
     * Premier mois CONSERVÉ : les factures des mois antérieurs ont dépassé
     * la durée de conservation. Les années de facturation sont comptées
     * révolues (une facture de 2015 se purge à partir du 1er janvier 2026
     * pour 10 ans), comme les pièces comptables d'un exercice clos.
     */
    public static function invoice_retention_cutoff_month($now = null) {
        $now = $now === null ? time() : (int) $now;
        $year = (int) wp_date('Y', $now) - self::invoice_retention_years();
        return sprintf('%04d-01', $year);
    }

    /**
     * Purge des factures arrivées au terme de leur conservation (RGPD-08) :
     * lignes, versions archivées et PDF, puis fiches parent anonymisées qui
     * ne portent plus ni facture ni enfant. Une facture n'est jamais réécrite
     * avant ce terme ; elle disparaît en entier une fois le terme atteint.
     * Ne fait rien tant que la mairie n'a pas activé la purge.
     *
     * @return array{invoices: int, versions: int, families: int}
     */
    public static function purge_expired_invoices($now = null, $limit = 500) {
        global $wpdb;
        $done = array('invoices' => 0, 'versions' => 0, 'families' => 0);
        if (!self::invoice_retention_enabled()) return $done;

        $cutoff = self::invoice_retention_cutoff_month($now);
        $t_inv  = psc_table('invoices');
        $t_invv = psc_table('invoice_versions');

        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $t_inv WHERE mois < %s ORDER BY id LIMIT %d", $cutoff, max(1, (int) $limit)
        )));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '%d'));
            // Chemins lus AVANT la suppression des lignes.
            $paths = array_merge(
                $wpdb->get_col($wpdb->prepare("SELECT pdf_path FROM $t_inv WHERE id IN ($ph) AND pdf_path IS NOT NULL AND pdf_path <> ''", $ids)),
                $wpdb->get_col($wpdb->prepare("SELECT pdf_path FROM $t_invv WHERE invoice_id IN ($ph) AND pdf_path IS NOT NULL AND pdf_path <> ''", $ids))
            );
            $months = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT mois FROM $t_inv WHERE id IN ($ph)", $ids));
            $done['versions'] = (int) $wpdb->query($wpdb->prepare("DELETE FROM $t_invv WHERE invoice_id IN ($ph)", $ids));
            $done['invoices'] = (int) $wpdb->query($wpdb->prepare("DELETE FROM $t_inv WHERE id IN ($ph)", $ids));
            foreach ($paths as $rel) {
                $abs = psc_private_path($rel);
                if ($abs && file_exists($abs)) {
                    @unlink($abs); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }
            }
            foreach ($months as $mois) {
                // Répertoire du mois retiré s'il est désormais vide.
                $dir = psc_private_path('periscolaire/factures/' . $mois);
                if ($dir && is_dir($dir)) {
                    @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }
            }
        }

        // Fiches anonymisées conservées pour leurs seules factures : plus
        // de raison d'exister une fois la dernière facture purgée.
        $t_par = psc_table('parents');
        $t_ch  = psc_table('children');
        $done['families'] = (int) $wpdb->query(
            "DELETE p FROM $t_par p
             WHERE p.email LIKE 'famille-supprimee-%'
               AND NOT EXISTS (SELECT 1 FROM $t_inv i WHERE i.parent_id = p.id)
               AND NOT EXISTS (SELECT 1 FROM $t_ch c WHERE c.parent_id = p.id)"
        );

        if ($done['invoices'] || $done['families']) {
            Psc_Audit::log('systeme.purge', array(
                'objet_type' => 'facture',
                'meta'       => array('portee' => 'factures_expirees', 'factures' => $done['invoices'], 'versions' => $done['versions'], 'familles' => $done['families']),
                'resume'     => sprintf(
                    /* translators: 1: nombre de factures, 2: nombre de fiches anonymisées, 3: premier mois conservé (AAAA-MM) */
                    __('Purge des factures expirées : %1$d facture(s) et %2$d fiche(s) anonymisée(s) supprimées (antérieures à %3$s).', 'periscolaire-registration'),
                    $done['invoices'], $done['families'], $cutoff
                ),
            ));
        }
        return $done;
    }

    public static function ensure_crons() {
        if (!wp_next_scheduled('psc_purge_departed_children')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'psc_purge_departed_children');
        }
    }

    /**
     * Purge quotidienne : enfants marqués sortis depuis plus longtemps que
     * le délai de conservation. Réutilise Psc_Admin_Familles::purge_child()
     * — le même chemin, déjà exhaustif, qu'une suppression manuelle par la
     * mairie ou que l'effaceur RGPD (Psc_Privacy) — pour qu'un seul endroit
     * sache purger un enfant. La fiche famille (parents) n'est pas touchée :
     * une fratrie encore active, ou des factures à conserver, peuvent y être
     * rattachées.
     */
    public static function purge_departed_children($now = null) {
        global $wpdb;
        $retention_days = max(1, (int) apply_filters('psc_children_retention_days', 400));
        $now = $now === null ? time() : (int) $now;
        // sorti_le et date_fin sont en heure du site : seuil dans la même horloge (PRA-14).
        $cutoff = wp_date('Y-m-d H:i:s', $now - $retention_days * DAY_IN_SECONDS);

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT c.id, c.parent_id FROM ' . psc_table('children') . ' c WHERE ' . self::departed_sql(),
            $cutoff
        ));
        if (!$rows) return 0;

        foreach ($rows as $row) {
            Psc_Admin_Familles::purge_child((int) $row->id);

            Psc_Audit::log('systeme.purge', array(
                'objet_type' => 'enfant',
                'objet_id'   => (int) $row->id,
                'famille_id' => (int) $row->parent_id,
                'enfant_id'  => (int) $row->id,
                // Sans identité (RGPD-04) : la fiche vient d'être effacée,
                // le journal n'en garde que l'identifiant.
                'resume'     => sprintf(
                    /* translators: 1: identifiant de l'enfant, 2: durée de conservation en jours */
                    __('Fiche enfant #%1$d purgée automatiquement (sortie du service depuis plus de %2$d jours, conservation RGPD expirée).', 'periscolaire-registration'),
                    (int) $row->id, $retention_days
                ),
            ));
        }

        return count($rows);
    }

    /**
     * Foyers sans enfant et sans activité depuis la durée de conservation
     * des enfants (RGPD-01) : supprimés par Psc_Admin_Familles::delete_family(),
     * qui anonymise la fiche au lieu de la supprimer tant qu'il reste une
     * facture (jamais réécrite ni supprimée). L'activité est la plus
     * récente de : création, dernière connexion, dernière facture
     * (création ou envoi), dernier message échangé. Un foyer déjà anonymisé
     * n'est plus examiné. Au plus $limit foyers par passage.
     *
     * @return int Nombre de foyers traités.
     */
    public static function purge_childless_families($now = null, $limit = 200) {
        global $wpdb;
        $retention_days = max(1, (int) apply_filters('psc_children_retention_days', 400));
        $now = $now === null ? time() : (int) $now;
        // Colonnes en heure du site (current_time('mysql')) : seuil dans la même horloge.
        $cutoff = wp_date('Y-m-d H:i:s', $now - $retention_days * DAY_IN_SECONDS);

        $ids = $wpdb->get_col($wpdb->prepare(self::childless_families_sql() . ' LIMIT %d', $cutoff, max(1, (int) $limit)));
        $done = 0;
        foreach ($ids as $id) {
            $result = Psc_Admin_Familles::delete_family((int) $id);
            if ($result === null) continue;
            $done++;
            Psc_Audit::log('systeme.purge', array(
                'objet_type' => 'famille',
                'objet_id'   => (int) $id,
                'meta'       => array('portee' => 'foyer_sans_enfant', 'factures_conservees' => (int) $result['invoices_retained']),
                'resume'     => sprintf(
                    /* translators: 1: identifiant de la famille, 2: durée de conservation en jours */
                    __('Famille #%1$d sans enfant purgée automatiquement (aucune activité depuis plus de %2$d jours).', 'periscolaire-registration'),
                    (int) $id, $retention_days
                ),
            ));
        }
        return $done;
    }

    /** Requête des foyers à purger (placeholder %s : seuil d'activité, heure du site). */
    private static function childless_families_sql() {
        $t_par  = psc_table('parents');
        $t_ch   = psc_table('children');
        $t_inv  = psc_table('invoices');
        $t_conv = psc_table('conversations');
        return "SELECT p.id FROM $t_par p
            WHERE NOT EXISTS (SELECT 1 FROM $t_ch c WHERE c.parent_id = p.id)
              AND p.email NOT LIKE 'famille-supprimee-%%'
              AND GREATEST(
                    p.created_at,
                    COALESCE(p.last_login, '1000-01-01'),
                    COALESCE((SELECT MAX(GREATEST(i.created_at, COALESCE(i.sent_at, '1000-01-01'))) FROM $t_inv i WHERE i.parent_id = p.id), '1000-01-01'),
                    COALESCE((SELECT MAX(cv.dernier_message_at) FROM $t_conv cv WHERE cv.family_id = p.id), '1000-01-01')
                  ) < %s
            ORDER BY p.id";
    }

    private static function count_departed_children($now) {
        global $wpdb;
        $retention_days = max(1, (int) apply_filters('psc_children_retention_days', 400));
        $cutoff = wp_date('Y-m-d H:i:s', (int) $now - $retention_days * DAY_IN_SECONDS);
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . psc_table('children') . ' c WHERE ' . self::departed_sql(),
            $cutoff
        ));
    }

    /**
     * Enfant parti du service depuis avant le seuil (placeholder %s) : il
     * n'est inscrit ni à l'année active ni à celle en préparation, et
     * l'horloge de conservation — la plus récente de sa dernière sortie en
     * cours d'année et de la fin de la dernière année où il était inscrit —
     * est dépassée. Un enfant sans aucune ligne d'année datée (ajouté hors
     * année active, ou dont l'année a été supprimée) prend pour horloge la
     * création de sa fiche (RGPD-01).
     */
    private static function departed_sql() {
        $t_cy    = psc_table('child_school_years');
        $t_years = psc_table('school_years');
        return 'NOT ' . Psc_School_Years::inscrit_ouvert_sql('c.id') . "
            AND COALESCE(
                (SELECT NULLIF(GREATEST(
                        COALESCE(MAX(cyr.sorti_le), '1000-01-01'),
                        COALESCE(MAX(IF(cyr.statut = 'inscrit', CONCAT(yr.date_fin, ' 23:59:59'), NULL)), '1000-01-01')), '1000-01-01')
                 FROM $t_cy cyr INNER JOIN $t_years yr ON yr.id = cyr.school_year_id
                 WHERE cyr.child_id = c.id),
                c.created_at) < %s";
    }
}
