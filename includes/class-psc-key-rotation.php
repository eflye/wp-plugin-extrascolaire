<?php
if (!defined('ABSPATH')) exit;

/**
 * Rechiffrement des données bancaires avec la clé courante.
 *
 * Sert à sortir la clé de la base (déclarer PSC_ENCRYPTION_KEY dans
 * wp-config.php alors que les IBAN ont été chiffrés avec le secret tiré de
 * l'option secret_key) et à toute rotation ultérieure entre deux
 * constantes (PSC_ENCRYPTION_KEY_PREVIOUS). Pendant la transition, le site
 * lit les deux clés (cf. psc_encryption_secrets()) : rien ne devient
 * illisible entre la déclaration de la constante et le rechiffrement.
 *
 * Trois emplacements chiffrés : parents.sepa_iban, requests.sepa_iban et
 * l'option psc_billing_org_iban (IBAN du créancier). Chaque valeur est
 * relue, rechiffrée avec la clé courante si une autre clé l'avait
 * chiffrée, et réécrite seulement si elle n'a pas changé entre-temps.
 * Une valeur illisible avec toutes les clés connues n'est jamais touchée.
 */
class Psc_Key_Rotation {

    const OPTION = 'psc_billing_org_iban';

    /**
     * Parcourt les valeurs chiffrées et, hors simulation, rechiffre celles
     * qui ne sont pas à la clé courante (y compris un IBAN hérité resté en
     * clair).
     *
     * @param bool       $dry_run Simulation : compte sans rien écrire.
     * @param array|null $scope   Restriction (vérifications) : ['parents' => ids,
     *                            'requests' => ids, 'option' => bool] ; null = tout.
     * @return array{courant:int, ancienne_cle:int, clair:int, rechiffre:int, illisible:int, erreur:int, illisibles:string[], par_origine:array}
     */
    public static function run($dry_run = false, $scope = null) {
        global $wpdb;
        $current = psc_encryption_key_source();
        $report = array(
            'courant' => 0, 'ancienne_cle' => 0, 'clair' => 0, 'rechiffre' => 0,
            'illisible' => 0, 'erreur' => 0, 'illisibles' => array(), 'par_origine' => array(),
        );

        $handle = function ($label, $value, callable $write) use (&$report, $current, $dry_run) {
            list($plain, $source) = psc_decrypt_with_source($value);
            if ($plain === null) {
                $report['illisible']++;
                $report['illisibles'][] = $label;
                return;
            }
            $report['par_origine'][$source] = ($report['par_origine'][$source] ?? 0) + 1;
            if ($source === $current) {
                $report['courant']++;
                return;
            }
            $report[$source === 'clair' ? 'clair' : 'ancienne_cle']++;
            if ($dry_run) return;

            $encrypted = psc_encrypt($plain);
            if (is_wp_error($encrypted) || !$write($encrypted)) {
                $report['erreur']++;
                return;
            }
            $report['rechiffre']++;
        };

        foreach (array('parents', 'requests') as $table) {
            if (is_array($scope) && empty($scope[$table])) continue;
            $t = psc_table($table);
            $where = "sepa_iban IS NOT NULL AND sepa_iban <> ''";
            if (is_array($scope)) {
                $where .= ' AND id IN (' . implode(',', array_map('intval', (array) $scope[$table])) . ')';
            }
            foreach ((array) $wpdb->get_results("SELECT id, sepa_iban FROM $t WHERE $where ORDER BY id") as $row) {
                $handle("$table#{$row->id}", $row->sepa_iban, function ($encrypted) use ($wpdb, $t, $row) {
                    // Écriture conditionnelle : une valeur modifiée entre la
                    // lecture et l'écriture (famille qui change son IBAN) n'est
                    // pas écrasée par l'ancienne.
                    return false !== $wpdb->query($wpdb->prepare(
                        "UPDATE $t SET sepa_iban = %s WHERE id = %d AND sepa_iban = %s",
                        $encrypted, (int) $row->id, $row->sepa_iban
                    ));
                });
            }
        }

        if (!is_array($scope) || !empty($scope['option'])) {
            $org = get_option(self::OPTION, '');
            if ($org !== '' && $org !== null && $org !== false) {
                $handle('option ' . self::OPTION, $org, function ($encrypted) {
                    return update_option(self::OPTION, $encrypted);
                });
            }
        }

        if (!$dry_run && ($report['rechiffre'] || $report['erreur']) && class_exists('Psc_Audit')) {
            Psc_Audit::log('systeme.rechiffrement', array(
                'objet_type' => 'reglage',
                'meta'       => array(
                    'cle' => $current, 'rechiffre' => $report['rechiffre'],
                    'illisible' => $report['illisible'], 'erreur' => $report['erreur'],
                ),
                'resume'     => sprintf(
                    /* translators: 1: valeurs rechiffrées, 2: valeurs illisibles */
                    __('Données bancaires rechiffrées avec la clé courante : %1$d valeur(s), %2$d illisible(s).', 'periscolaire-registration'),
                    $report['rechiffre'], $report['illisible']
                ),
            ));
        }
        return $report;
    }
}

if (defined('WP_CLI') && WP_CLI) {

    /**
     * Clé de chiffrement des données bancaires (IBAN des familles, des
     * demandes et du créancier).
     */
    class Psc_Key_Rotation_Command {

        /**
         * Indique d'où vient la clé courante et à quelle clé chaque valeur est chiffrée.
         *
         * ## EXAMPLES
         *
         *     wp psc chiffrement statut
         */
        public function statut() {
            $source = psc_encryption_key_source();
            WP_CLI::log($source === 'constante'
                ? 'Clé courante : constante PSC_ENCRYPTION_KEY (wp-config.php), hors de la base.'
                : 'Clé courante : option secret_key, EN BASE. Un dump de la base suffit à déchiffrer les IBAN.');

            $r = Psc_Key_Rotation::run(true);
            WP_CLI::log(sprintf('Valeurs à la clé courante ......... %d', $r['courant']));
            WP_CLI::log(sprintf('Valeurs à une ancienne clé ........ %d', $r['ancienne_cle']));
            WP_CLI::log(sprintf('IBAN hérités en clair .............. %d', $r['clair']));
            WP_CLI::log(sprintf('Valeurs illisibles ................. %d', $r['illisible']));
            foreach ($r['illisibles'] as $label) WP_CLI::log("  - $label");

            if ($source !== 'constante') {
                WP_CLI::warning('Déclarez PSC_ENCRYPTION_KEY (wp psc chiffrement generer-cle), puis lancez wp psc chiffrement rechiffrer.');
            } elseif ($r['ancienne_cle'] || $r['clair']) {
                WP_CLI::warning('Des valeurs ne sont pas à la clé courante : lancez wp psc chiffrement rechiffrer.');
            } else {
                WP_CLI::success('Toutes les valeurs lisibles sont chiffrées avec la clé de wp-config.php.');
            }
        }

        /**
         * Rechiffre avec la clé courante toutes les valeurs chiffrées avec une ancienne clé.
         *
         * ## OPTIONS
         *
         * [--dry-run]
         * : Compte ce qui serait rechiffré, sans rien écrire.
         *
         * ## EXAMPLES
         *
         *     wp psc chiffrement rechiffrer --dry-run
         *     wp psc chiffrement rechiffrer
         */
        public function rechiffrer($args, $assoc_args) {
            $dry = !empty($assoc_args['dry-run']);
            if (psc_encryption_key_source() !== 'constante') {
                WP_CLI::error('PSC_ENCRYPTION_KEY n’est pas déclarée : rechiffrer garderait la clé en base. Déclarez-la d’abord (wp psc chiffrement generer-cle).');
            }
            $r = Psc_Key_Rotation::run($dry);
            $todo = $r['ancienne_cle'] + $r['clair'];
            WP_CLI::log(sprintf('%s : %d valeur(s) à rechiffrer, %d déjà à la clé courante.', $dry ? 'Simulation' : 'Rechiffrement', $todo, $r['courant']));
            if (!$dry) WP_CLI::log(sprintf('Rechiffrées : %d ; en erreur : %d.', $r['rechiffre'], $r['erreur']));
            if ($r['illisible']) {
                WP_CLI::warning(sprintf('%d valeur(s) illisible(s) avec toutes les clés connues, laissée(s) intactes :', $r['illisible']));
                foreach ($r['illisibles'] as $label) WP_CLI::log("  - $label");
            }
            if ($r['erreur']) WP_CLI::error('Rechiffrement incomplet : relancez la commande (les valeurs déjà rechiffrées ne sont pas retouchées).');
            WP_CLI::success($dry ? 'Simulation terminée, rien n’a été écrit.' : 'Rechiffrement terminé.');
        }

        /**
         * Génère une clé aléatoire et affiche la ligne à ajouter dans wp-config.php.
         *
         * La clé n'est enregistrée nulle part : copiez-la dans wp-config.php et
         * dans un coffre de mots de passe, jamais avec les sauvegardes de la base.
         *
         * @subcommand generer-cle
         */
        public function generer_cle() {
            WP_CLI::log("define( 'PSC_ENCRYPTION_KEY', '" . bin2hex(random_bytes(32)) . "' );");
            WP_CLI::log('À placer dans wp-config.php, avant la ligne « That\'s all, stop editing! ».');
        }
    }

    WP_CLI::add_command('psc chiffrement', 'Psc_Key_Rotation_Command');
}
