<?php
if (!defined('ABSPATH')) exit;

class Psc_Mailer {

    public static function form_page_url() {
        $id = (int) get_option('psc_form_page_id', 0);
        if ($id && get_post_status($id) === 'publish') {
            return get_permalink($id);
        }

        $found = get_transient('psc_form_page_lookup');
        if ($found === false) {
            $found = 0;
            $pages = get_posts(array(
                'post_type'   => 'page',
                'post_status' => 'publish',
                'numberposts' => 50,
                's'           => 'periscolaire_form',
            ));
            foreach ($pages as $p) {
                if (has_shortcode($p->post_content, 'periscolaire_form')) {
                    $found = $p->ID;
                    break;
                }
            }
            set_transient('psc_form_page_lookup', $found, DAY_IN_SECONDS);
        }

        return $found ? get_permalink($found) : home_url('/');
    }

    protected static function site_name() {
        return wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    }

    /**
     * Enveloppe un fragment HTML dans le layout e-mail commun.
     */
    protected static function layout($body_html, $title = '') {
        $site_name = self::site_name();
        ob_start();
        include PSC_PATH . 'templates/email/layout.php';
        return ob_get_clean();
    }

    /**
     * Envoi HTML avec repli texte brut dans les en-têtes.
     */
    protected static function send($to, $subject, $html, $attachments = array()) {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::site_name() . ' <' . get_option('admin_email') . '>',
        );
        return wp_mail($to, $subject, $html, $headers, $attachments);
    }

    /* ------------------------------------------------------------------ */
    /* Styles réutilisables (inline CSS)                                   */
    /* ------------------------------------------------------------------ */

    protected static function btn($url, $label) {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">'
            . '<tr><td style="background-color:#E08A5F;padding:14px 32px;">'
            . '<a href="' . esc_url($url) . '" '
            . 'style="color:#1A1A1A;font-family:Helvetica,Arial,sans-serif;font-size:12px;font-weight:bold;'
            . 'letter-spacing:0.06em;text-transform:uppercase;text-decoration:none;display:inline-block;">'
            . esc_html($label) . '</a>'
            . '</td></tr></table>';
    }

    protected static function info_box($html) {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;">'
            . '<tr><td style="background-color:#F5E7DC;border:1px solid #E5DCC3;padding:16px 18px;'
            . 'font-family:Helvetica,Arial,sans-serif;font-size:14px;color:#1A1A1A;line-height:1.5;">'
            . $html . '</td></tr></table>';
    }

    /**
     * Même charte que info_box (pas de couleur d'alerte hors palette du
     * site) : seul un accent doré à gauche le distingue visuellement.
     */
    protected static function warning_box($html) {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;">'
            . '<tr><td style="background-color:#F5E7DC;border:1px solid #E5DCC3;border-left:4px solid #E08A5F;'
            . 'padding:16px 18px 16px 14px;font-family:Helvetica,Arial,sans-serif;font-size:14px;color:#1A1A1A;line-height:1.5;">'
            . $html . '</td></tr></table>';
    }

    protected static function h2($text) {
        return '<h2 style="color:#24405C;font-family:Georgia,\'Times New Roman\',serif;font-weight:bold;font-size:17px;'
            . 'margin:0 0 14px;padding-bottom:8px;border-bottom:1px solid #E5DCC3;">' . esc_html($text) . '</h2>';
    }

    protected static function p($text, $style = '') {
        return '<p style="color:#1A1A1A;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;margin:0 0 12px;' . $style . '">'
            . nl2br(esc_html($text)) . '</p>';
    }

    /* ------------------------------------------------------------------ */
    /* Lien de connexion parent                                             */
    /* ------------------------------------------------------------------ */

    public static function send_login_link($to_email, $url, $context = 'login') {
        $site    = self::site_name();
        $minutes = (int) (psc_login_link_ttl() / MINUTE_IN_SECONDS);

        $tpl_key   = ($context === 'approved') ? 'login_approved' : 'login_link';
        $btn_label = ($context === 'approved') ? __('Accéder à mon espace', 'periscolaire-registration') : __('Me connecter', 'periscolaire-registration');
        $h2_label  = ($context === 'approved') ? __('Votre compte est activé ✓', 'periscolaire-registration') : __('Votre lien de connexion', 'periscolaire-registration');

        $subject = Psc_Email_Templates::subject($tpl_key, array('site' => $site));
        $intro   = Psc_Email_Templates::body_html($tpl_key, array('site' => $site, 'minutes' => $minutes));

        $body = self::h2($h2_label)
            . '<p style="color:#1A1A1A;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;margin:0 0 12px;">' . $intro . '</p>'
            . self::btn($url, $btn_label)
            . self::info_box(
                __('<strong>⏱ Durée de validité :</strong> ce lien expire dans <strong>', 'periscolaire-registration') . $minutes . __(' minutes</strong>.', 'periscolaire-registration')
            )
            . self::p(__('Si vous n\'êtes pas à l\'origine de cette demande, vous pouvez ignorer ce message en toute sécurité.', 'periscolaire-registration'));

        return self::send($to_email, $subject, self::layout($body, $subject));
    }

    /**
     * Confirmation envoyée à la NOUVELLE adresse lors d'une demande de
     * changement d'e-mail depuis "Mon profil" (cf. Psc_Parents::request_email_change) —
     * l'ancienne adresse reste active tant que ce lien n'a pas été cliqué.
     */
    public static function send_email_change_confirmation($parent, $new_email, $url) {
        $site    = self::site_name();
        $subject = sprintf(__('[%s] Confirmez votre nouvelle adresse e-mail', 'periscolaire-registration'), $site);
        $days    = (int) round(psc_email_confirmation_ttl() / DAY_IN_SECONDS);

        $body = self::h2(__('Confirmez votre nouvelle adresse e-mail', 'periscolaire-registration'))
            . self::p(sprintf(
                __('Vous avez demandé à utiliser %s comme nouvelle adresse de connexion à votre espace famille.', 'periscolaire-registration'),
                $new_email
            ))
            . self::btn($url, __('Confirmer cette adresse', 'periscolaire-registration'))
            . self::info_box(
                __('<strong>⏱ Ce lien est valable ', 'periscolaire-registration') . $days . __(' jour', 'periscolaire-registration') . ($days > 1 ? __('s', 'periscolaire-registration') : '') . '.</strong><br>'
                . __('Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : votre adresse actuelle ', 'periscolaire-registration')
                . __('reste inchangée et pleinement fonctionnelle.', 'periscolaire-registration')
            );

        return self::send($new_email, $subject, self::layout($body, $subject));
    }

    /* ------------------------------------------------------------------ */
    /* Récapitulatif planning                                               */
    /* ------------------------------------------------------------------ */

    /**
     * « Valider et recevoir mon planning » : récapitulatif ANNUEL.
     * Contenu : rythme habituel de chaque enfant (la grille posée pour
     * l'année), écarts à venir (exceptions) et estimation annuelle — la
     * facturation étant mensuelle, l'estimation annuelle reste indicative.
     */
    public static function send_recap($parent, $year, $children, $summary, $patterns, $upcoming, $services) {
        $site    = self::site_name();
        $subject = Psc_Email_Templates::subject('recap', array('site' => $site, 'annee' => $year->year_key));
        $intro   = Psc_Email_Templates::body_html('recap', array('site' => $site, 'annee' => $year->year_key));
        $year_key = $year->year_key;

        $body = self::h2(__('Votre planning — année scolaire ', 'periscolaire-registration') . esc_html($year_key));
        $body .= '<p style="color:#1A1A1A;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;margin:0 0 12px;">' . $intro . '</p>';

        $jours = array(1 => __('Lundi', 'periscolaire-registration'), 2 => __('Mardi', 'periscolaire-registration'), 4 => __('Jeudi', 'periscolaire-registration'), 5 => __('Vendredi', 'periscolaire-registration'));

        foreach ($children as $child) {
            $cid = (int) $child->id;
            $child_classe = Psc_School_Years::classe_for($cid);
            $child_label = esc_html(mb_strtoupper($child->prenom . ' ' . $child->nom, 'UTF-8'))
                . ($child_classe ? ' <span style="color:#8B8279;font-weight:normal;font-size:13px;">(' . esc_html($child_classe) . ')</span>' : '');

            $body .= '<div style="margin:24px 0;">';
            $body .= '<h3 style="font-size:15px;font-family:Georgia,\'Times New Roman\',serif;font-weight:bold;color:#24405C;margin:0 0 10px;padding:8px 12px;background-color:#F5E7DC;">' . $child_label . '</h3>';

            // Rythme habituel.
            $pats = isset($patterns[$cid][$year_key]) ? $patterns[$cid][$year_key] : array();
            $has_pattern = false;
            $rhythm_rows = '';
            foreach ($jours as $wd => $jour_label) {
                $labels = array();
                foreach (psc_allowed_services() as $code) {
                    if (!empty($pats[$wd][$code])) $labels[] = $services[$code]['label'];
                }
                if (!$labels) continue;
                $has_pattern = true;
                $rhythm_rows .= '<tr>'
                    . '<td style="padding:6px 10px;border:1px solid #E5DCC3;white-space:nowrap;">' . esc_html($jour_label) . '</td>'
                    . '<td style="padding:6px 10px;border:1px solid #E5DCC3;">' . esc_html(implode(', ', $labels)) . '</td>'
                    . '</tr>';
            }

            $body .= '<p style="font-size:13px;font-weight:bold;color:#1A1A1A;margin:0 0 6px;">' . esc_html(__('Rythme habituel', 'periscolaire-registration')) . '</p>';
            if ($has_pattern) {
                $body .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-bottom:12px;font-size:13px;">'
                    . '<thead><tr>'
                    . '<th style="background-color:#F5E7DC;color:#24405C;padding:7px 10px;text-align:left;border:1px solid #E5DCC3;">' . __('Jour', 'periscolaire-registration') . '</th>'
                    . '<th style="background-color:#F5E7DC;color:#24405C;padding:7px 10px;text-align:left;border:1px solid #E5DCC3;">' . __('Prestations', 'periscolaire-registration') . '</th>'
                    . '</tr></thead><tbody>' . $rhythm_rows . '</tbody></table>';
            } else {
                $body .= '<p style="color:#8B8279;font-size:13px;font-style:italic;margin:0 0 12px;">' . __('Aucun rythme habituel déclaré : chaque jour est déclaré à l\'unité.', 'periscolaire-registration') . '</p>';
            }

            // Écarts à venir pour cet enfant.
            $child_exceptions = isset($upcoming[$cid]) ? $upcoming[$cid] : array();
            $body .= '<p style="font-size:13px;font-weight:bold;color:#1A1A1A;margin:0 0 6px;">' . esc_html(__('Écarts à venir', 'periscolaire-registration')) . '</p>';
            if ($child_exceptions) {
                $exc_rows = '';
                foreach ($child_exceptions as $exc) {
                    $svc_lbl = isset($services[$exc['service']]) ? $services[$exc['service']]['label'] : $exc['service'];
                    $type = $exc['value'] ? __('Ajout exceptionnel', 'periscolaire-registration') : __('Retrait exceptionnel', 'periscolaire-registration');
                    $exc_rows .= '<tr>'
                        . '<td style="padding:6px 10px;border:1px solid #E5DCC3;white-space:nowrap;">' . esc_html(psc_day_label($exc['date']) . ' ' . date_i18n('d/m/Y', strtotime($exc['date']))) . '</td>'
                        . '<td style="padding:6px 10px;border:1px solid #E5DCC3;">' . esc_html($svc_lbl) . '</td>'
                        . '<td style="padding:6px 10px;border:1px solid #E5DCC3;">' . esc_html($type) . '</td>'
                        . '</tr>';
                }
                $body .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-bottom:12px;font-size:13px;">'
                    . '<thead><tr>'
                    . '<th style="background-color:#F5E7DC;color:#24405C;padding:7px 10px;text-align:left;border:1px solid #E5DCC3;">' . __('Date', 'periscolaire-registration') . '</th>'
                    . '<th style="background-color:#F5E7DC;color:#24405C;padding:7px 10px;text-align:left;border:1px solid #E5DCC3;">' . __('Prestation', 'periscolaire-registration') . '</th>'
                    . '<th style="background-color:#F5E7DC;color:#24405C;padding:7px 10px;text-align:left;border:1px solid #E5DCC3;">' . __('Type', 'periscolaire-registration') . '</th>'
                    . '</tr></thead><tbody>' . $exc_rows . '</tbody></table>';
            } else {
                $body .= '<p style="color:#8B8279;font-size:13px;font-style:italic;margin:0 0 12px;">' . __('Aucun écart à venir : les mois à venir suivent le rythme habituel.', 'periscolaire-registration') . '</p>';
            }

            // Estimation annuelle de cet enfant.
            $year_child = isset($summary['year']['per_child'][$cid]) ? $summary['year']['per_child'][$cid] : array('days' => 0, 'amount' => 0.0);
            $body .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;margin-bottom:8px;">'
                . '<tr style="border-top:2px solid #24405C;">'
                . '<td style="padding:7px 10px;font-weight:bold;color:#24405C;">' . esc_html(__('Estimation annuelle', 'periscolaire-registration')) . ' — ' . (int) $year_child['days'] . ' ' . esc_html(_n('jour', 'jours', (int) $year_child['days'], 'periscolaire-registration')) . '</td>'
                . '<td style="padding:7px 10px;font-weight:bold;color:#24405C;text-align:right;">' . number_format((float) $year_child['amount'], 2, ',', ' ') . ' €</td>'
                . '</tr></table>';
            $body .= '</div>';
        }

        // Total famille.
        $body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0;"><tr><td style="background-color:#24405C;padding:14px 20px;">'
            . '<p style="margin:0;color:#ffffff;font-size:16px;font-weight:bold;">'
            . __('Estimation annuelle de la famille : ', 'periscolaire-registration') . number_format((float) $summary['year']['amount'], 2, ',', ' ') . ' €'
            . '</p></td></tr></table>';

        $body .= self::warning_box(
            __('Ce montant est donné <strong>à titre indicatif</strong>. ', 'periscolaire-registration')
            . __('La facturation définitive est établie par la mairie, mois par mois, d\'après les déclarations effectives.', 'periscolaire-registration')
        );

        if (psc_lock_hours() > 0) {
            $body .= self::p(
                __('Vous pouvez modifier votre planning jusqu\'à ', 'periscolaire-registration') . psc_lock_hours()
                . __(' heures avant chaque jour concerné.', 'periscolaire-registration')
            );
        }

        $body .= self::btn(self::form_page_url(), __('Modifier mon planning', 'periscolaire-registration'));

        $html = self::layout($body, $subject);
        $sent = self::send($parent->email, $subject, $html);

        if ($sent && psc_notify_mairie_enabled()) {
            $names = array();
            foreach ($children as $c) $names[] = $c->prenom . ' ' . $c->nom;
            $mairie_body = self::h2(__('Planning validé', 'periscolaire-registration'))
                . self::info_box(
                    __('<strong>Famille :</strong> ', 'periscolaire-registration') . esc_html($parent->email) . '<br>'
                    . __('<strong>Enfant(s) :</strong> ', 'periscolaire-registration') . esc_html(implode(', ', $names))
                )
                . $body;
            self::send(
                psc_mairie_email(),
                sprintf(__('[%s] Planning validé — %s', 'periscolaire-registration'), $site, implode(', ', $names)),
                self::layout($mairie_body)
            );
        }

        return $sent;
    }

    /**
     * Correction apportée par la mairie : tableau des modifications, le
     * reste de l'année suit le rythme habituel déjà en place.
     */
    public static function send_admin_correction($parent, $year_key, $children, $services, $diff_added = array(), $diff_removed = array()) {
        $site    = self::site_name();
        $subject = sprintf(__('[%s] Votre planning périscolaire a été mis à jour — %s', 'periscolaire-registration'), $site, $year_key);

        $child_index = array();
        foreach ($children as $c) $child_index[(int) $c->id] = $c;

        $body  = self::h2(__('Modifications apportées par la mairie', 'periscolaire-registration'));
        $body .= self::_build_diff_table($diff_added, $diff_removed, $child_index, $services);
        $body .= self::p(__('Le reste de l\'année suit le rythme habituel déjà déclaré. Vous pouvez consulter votre planning complet depuis votre espace famille.', 'periscolaire-registration'));
        $body .= self::btn(self::form_page_url(), __('Consulter mon planning', 'periscolaire-registration'));

        return self::send($parent->email, $subject, self::layout($body, $subject));
    }

    /**
     * Alerte mairie : une allergie alimentaire vient d'être enregistrée
     * (ajout d'enfant, édition, ou approbation d'une demande d'inscription).
     * Déclenche la prise de contact PAI promise à la famille.
     * $previous : valeur précédente (null si aucune) — l'alerte part sur un
     * contenu nouveau ou modifié, jamais sur une saisie inchangée.
     */
    public static function notify_food_allergy($parent, $child_id, $allergies, $previous = null) {
        $site = self::site_name();

        $child = null;
        global $wpdb;
        $child = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('children') . ' WHERE id = %d', (int) $child_id
        ));
        $child_name = $child ? trim($child->prenom . ' ' . $child->nom) : ('#' . (int) $child_id);

        $subject = Psc_Email_Templates::subject('food_allergy', array('site' => $site, 'child' => $child_name));
        $intro   = Psc_Email_Templates::body_html('food_allergy', array('site' => $site, 'child' => $child_name));

        $body = self::h2(__('Allergie alimentaire déclarée', 'periscolaire-registration'));
        $body .= '<p style="color:#1A1A1A;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;margin:0 0 12px;">' . $intro . '</p>';
        $body .= self::info_box(
            '<strong>' . esc_html(__('Enfant :', 'periscolaire-registration')) . '</strong> ' . esc_html($child_name) . '<br>'
            . '<strong>' . esc_html(__('Famille :', 'periscolaire-registration')) . '</strong> ' . esc_html(trim($parent->prenom . ' ' . $parent->nom)) . ' — ' . esc_html($parent->email) . '<br>'
            . '<strong>' . esc_html(__('Classe :', 'periscolaire-registration')) . '</strong> ' . esc_html($child ? Psc_School_Years::classe_for($child->id) : '—') . '<br>'
            . '<strong>' . esc_html(__('Description saisie :', 'periscolaire-registration')) . '</strong><br>' . nl2br(esc_html($allergies))
        );
        if ($previous !== null && $previous !== '') {
            $body .= self::p(__('Description précédemment enregistrée : ', 'periscolaire-registration') . '« ' . $previous . ' »');
        }
        $body .= self::warning_box(
            __('Rappel de la promesse de service : ', 'periscolaire-registration')
            . __('la mairie prend l\'initiative du contact, et aucun menu différencié n\'est proposé — l\'enfant déjeune à la cantine avec son propre repas fourni par la famille.', 'periscolaire-registration')
        );
        $body .= self::btn(admin_url('admin.php?page=psc_children'), __('Ouvrir la fiche enfant', 'periscolaire-registration'));

        return self::send(psc_mairie_email(), $subject, self::layout($body, $subject));
    }

    /**
     * Rendu HTML du tableau de diff (ajouts / suppressions).
     * Partagé entre le récap parent et la correction admin.
     */
    private static function _build_diff_table($diff_added, $diff_removed, $child_index, $services) {
        if (empty($diff_added) && empty($diff_removed)) {
            return '<p style="color:#8B8279;font-size:14px;font-style:italic;margin:0 0 24px;">' . __('Aucune modification.', 'periscolaire-registration') . '</p>';
        }

        $diff_rows = array();
        foreach ($diff_added   as $key) $diff_rows[] = array('key' => $key, 'type' => 'add');
        foreach ($diff_removed as $key) $diff_rows[] = array('key' => $key, 'type' => 'remove');

        usort($diff_rows, function ($a, $b) {
            list($ca, $da) = explode('|', $a['key']);
            list($cb, $db) = explode('|', $b['key']);
            return ($da . $ca) <=> ($db . $cb);
        });

        $html  = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;margin-bottom:24px;">';
        $html .= '<thead><tr>'
               . '<th style="background-color:#F5E7DC;color:#24405C;padding:7px 10px;text-align:left;border:1px solid #E5DCC3;">' . __('Date', 'periscolaire-registration') . '</th>'
               . '<th style="background-color:#F5E7DC;color:#24405C;padding:7px 10px;text-align:left;border:1px solid #E5DCC3;">' . __('Enfant', 'periscolaire-registration') . '</th>'
               . '<th style="background-color:#F5E7DC;color:#24405C;padding:7px 10px;text-align:left;border:1px solid #E5DCC3;">' . __('Prestation', 'periscolaire-registration') . '</th>'
               . '<th style="background-color:#F5E7DC;color:#24405C;padding:7px 10px;text-align:center;border:1px solid #E5DCC3;">' . __('Modification', 'periscolaire-registration') . '</th>'
               . '</tr></thead><tbody>';

        foreach ($diff_rows as $row) {
            list($cid, $date, $svc) = explode('|', $row['key']);
            $child     = isset($child_index[(int) $cid]) ? $child_index[(int) $cid] : null;
            $child_lbl = $child ? esc_html($child->prenom . ' ' . $child->nom) : '';
            $svc_lbl   = isset($services[$svc]) ? esc_html($services[$svc]['label']) : esc_html($svc);
            $date_lbl  = esc_html(psc_day_label($date) . ' ' . date_i18n('d/m/Y', strtotime($date)));

            if ($row['type'] === 'add') {
                $badge = '<span style="background-color:#EAF1EA;color:#4E6C8D;padding:2px 8px;font-weight:bold;font-size:12px;">' . __('+ Ajout', 'periscolaire-registration') . '</span>';
                $bg    = '#F7FAF7';
            } else {
                $badge = '<span style="background-color:#F5E7E7;color:#9E4A4A;padding:2px 8px;font-weight:bold;font-size:12px;">' . __('− Suppression', 'periscolaire-registration') . '</span>';
                $bg    = '#FBF6F6';
            }

            $html .= '<tr style="background:' . $bg . ';">'
                   . '<td style="padding:6px 10px;border:1px solid #E5DCC3;white-space:nowrap;">' . $date_lbl . '</td>'
                   . '<td style="padding:6px 10px;border:1px solid #E5DCC3;">' . $child_lbl . '</td>'
                   . '<td style="padding:6px 10px;border:1px solid #E5DCC3;">' . $svc_lbl . '</td>'
                   . '<td style="padding:6px 10px;border:1px solid #E5DCC3;text-align:center;">' . $badge . '</td>'
                   . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /* ------------------------------------------------------------------ */
    /* Menu de cantine hebdomadaire                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Un seul menu, identique pour toutes les familles (les préférences
     * sans_porc/vegan ne changent pas ce message — cf. Psc_Menus::send()).
     */
    public static function send_weekly_menu($parent, $menu) {
        $site          = self::site_name();
        $semaine_label = date_i18n('d/m/Y', strtotime($menu->semaine_debut));

        $subject = Psc_Email_Templates::subject('weekly_menu', array('site' => $site, 'semaine' => $semaine_label));
        $intro   = Psc_Email_Templates::body_html('weekly_menu', array('site' => $site, 'semaine' => $semaine_label));

        $body = self::h2(__('Menu de la semaine du ', 'periscolaire-registration') . $semaine_label)
            . '<p style="color:#1A1A1A;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;margin:0 0 12px;">' . $intro . '</p>';

        $body .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:14px;margin:16px 0;">';
        $has_content = false;
        foreach (Psc_Menus::jour_labels() as $key => $label) {
            $content = trim((string) $menu->$key);
            if ($content === '') continue;
            $has_content = true;
            $body .= '<tr>'
                . '<td style="background-color:#F5E7DC;color:#24405C;font-weight:bold;padding:8px 12px;'
                . 'border:1px solid #E5DCC3;width:110px;vertical-align:top;white-space:nowrap;">' . esc_html($label) . '</td>'
                . '<td style="padding:8px 12px;border:1px solid #E5DCC3;">' . nl2br(esc_html($content)) . '</td>'
                . '</tr>';
        }
        $body .= '</table>';
        if (!$has_content) {
            $body .= '<p style="color:#8B8279;font-size:14px;font-style:italic;">' . __('Menu non encore renseigné pour cette semaine.', 'periscolaire-registration') . '</p>';
        }

        return self::send($parent->email, $subject, self::layout($body, $subject));
    }

    /* ------------------------------------------------------------------ */
    /* Commande fournisseur (cantine)                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Construit et envoie la commande fournisseur hebdomadaire (nombre de
     * repas par classe et par jour). $data est le tableau retourné par
     * Psc_Supplier_Orders::compute_counts(). Renvoie un tableau
     * ('sent' => bool, 'subject' => string, 'html' => string) : le sujet
     * et le corps sont renvoyés même en cas d'échec d'envoi, pour
     * permettre à l'appelant de diagnostiquer sans reconstruire l'e-mail.
     */
    public static function send_supplier_order($supplier_email, $data) {
        $site          = self::site_name();
        $semaine_label = date_i18n('d/m/Y', strtotime($data['semaine_debut']));

        $subject = Psc_Email_Templates::subject('supplier_order', array(
            'site'    => $site,
            'semaine' => $semaine_label,
            'total'   => $data['total'],
            'gouters' => isset($data['total_gouters']) ? (int) $data['total_gouters'] : 0,
        ));
        $intro = Psc_Email_Templates::body_html('supplier_order', array(
            'site'    => $site,
            'semaine' => $semaine_label,
            'total'   => $data['total'],
            'gouters' => isset($data['total_gouters']) ? (int) $data['total_gouters'] : 0,
        ));

        $body = self::h2(__('Commande cantine & goûters — semaine du ', 'periscolaire-registration') . $semaine_label)
            . '<p style="color:#1A1A1A;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;margin:0 0 20px;">' . $intro . '</p>';

        // Seuls les jours d'école réellement ouverts cette semaine-là
        // figurent dans $data['jours'] (Psc_Supplier_Orders::compute_counts).
        $all_labels = Psc_Supplier_Orders::jour_labels();
        $jours      = array_keys($data['jours']);

        // Une table par ARTICLE commandé (repas, puis goûters), même grille
        // classe × jour — le fournisseur prépare les deux livraisons
        // côte à côte. Les clés manquantes (commandes archivées avant les
        // goûters) rendent une table absente, jamais une erreur.
        $articles = array(
            array(
                'titre'     => __('Repas', 'periscolaire-registration'),
                'counts'    => $data['counts'],
                'jour'      => $data['totaux_jour'],
                'classe'    => $data['totaux_classe'],
                'total'     => $data['total'],
                'testid'    => 'repas',
            ),
        );
        if (isset($data['gouters'])) {
            $articles[] = array(
                'titre'     => __('Goûters', 'periscolaire-registration'),
                'counts'    => $data['gouters'],
                'jour'      => $data['gouters_jour'],
                'classe'    => $data['gouters_classe'],
                'total'     => $data['total_gouters'],
                'testid'    => 'gouters',
            );
        }

        foreach ($articles as $article) {
            $body .= '<h3 style="color:#24405C;font-family:Georgia,\'Times New Roman\',serif;font-size:15px;'
                . 'margin:18px 0 0;">' . esc_html($article['titre']) . '</h3>';
            $body .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;margin:8px 0 16px;">';
            $body .= '<thead><tr>'
                . '<th style="background-color:#F5E7DC;color:#24405C;padding:7px 10px;text-align:left;border:1px solid #E5DCC3;">' . __('Classe', 'periscolaire-registration') . '</th>';
            foreach ($jours as $jour) {
                $body .= '<th style="background-color:#F5E7DC;color:#24405C;padding:7px 10px;text-align:center;border:1px solid #E5DCC3;">'
                    . esc_html($all_labels[$jour]) . '<br><small>' . esc_html(date_i18n('d/m', strtotime($data['jours'][$jour]))) . '</small></th>';
            }
            $body .= '<th style="background-color:#E5DCC3;color:#24405C;padding:7px 10px;text-align:center;border:1px solid #E5DCC3;">' . __('Total', 'periscolaire-registration') . '</th>';
            $body .= '</tr></thead><tbody>';

            $body .= '<tr><td colspan="' . (count($jours) + 2) . '" style="padding:6px 10px;color:#8B8279;font-style:italic;border:1px solid #E5DCC3;">'
                . ($article['total'] > 0
                    ? __('Enfants concernés : les cases « — » signifient aucun pour cette classe et ce jour.', 'periscolaire-registration')
                    : __('Aucun déclaré cette semaine.', 'periscolaire-registration'))
                . '</td></tr>';

            foreach ($data['classes'] as $code => $label) {
                $body .= '<tr>'
                    . '<td style="padding:6px 10px;border:1px solid #E5DCC3;font-weight:bold;">' . esc_html($label) . '</td>';
                foreach ($jours as $jour) {
                    $n = $article['counts'][$code][$jour] ?? 0;
                    $body .= '<td style="padding:6px 10px;border:1px solid #E5DCC3;text-align:center;">' . ($n > 0 ? $n : '—') . '</td>';
                }
                $body .= '<td style="padding:6px 10px;border:1px solid #E5DCC3;text-align:center;font-weight:bold;">' . (int) $article['classe'][$code] . '</td>';
                $body .= '</tr>';
            }

            $body .= '<tr style="background-color:#F5E7DC;">'
                . '<td style="padding:7px 10px;border:1px solid #E5DCC3;font-weight:bold;">' . esc_html(mb_strtoupper($article['titre'], 'UTF-8')) . '</td>';
            foreach ($jours as $jour) {
                $body .= '<td style="padding:7px 10px;border:1px solid #E5DCC3;text-align:center;font-weight:bold;">' . (int) $article['jour'][$jour] . '</td>';
            }
            $body .= '<td style="padding:7px 10px;border:1px solid #E5DCC3;text-align:center;font-weight:bold;color:#24405C;">' . (int) $article['total'] . '</td>';
            $body .= '</tr>';
            $body .= '</tbody></table>';
        }

        // Les enfants porteurs d'une allergie alimentaire apportent leur
        // repas et leur goûter fournis par la famille : ils restent sur la
        // liste de présence (SIDSCM) mais ne sont comptés dans aucune
        // commande (cf. Psc_Supplier_Orders::compute_counts).
        $body .= self::info_box(
            __('Rappel : les enfants porteurs d\'une allergie alimentaire apportent leur repas et leur goûter fournis par la famille — ils ne sont comptés dans aucun de ces effectifs, mais figurent sur les listes de présence avec la mention « apporte son repas ».', 'periscolaire-registration')
        );

        $html = self::layout($body, $subject);
        $sent = self::send($supplier_email, $subject, $html);

        return array('sent' => (bool) $sent, 'subject' => $subject, 'html' => $html);
    }

    /* ------------------------------------------------------------------ */
    /* Fermeture d'un jour (calendrier scolaire)                            */
    /* ------------------------------------------------------------------ */

    /**
     * Prévient une famille que des inscriptions qu'elle avait déclarées
     * ont été retirées parce que le jour concerné vient d'être fermé
     * (vacances scolaires, formation des enseignants, fermeture
     * exceptionnelle...). $fam : array('email'=>, 'nom'=>, 'items'=>
     * [objets avec service, child_nom, child_prenom]).
     */
    public static function send_day_closed($fam, $date_str, $label) {
        $site      = self::site_name();
        $date_lbl  = psc_day_label($date_str) . ' ' . date_i18n('d/m/Y', strtotime($date_str));
        $services  = psc_services();
        $subject   = sprintf(__('[%s] Jour sans école le %s : vos inscriptions ont été retirées', 'periscolaire-registration'), $site, $date_lbl);

        $body = self::h2(__('Un jour d\'école a été annulé', 'periscolaire-registration'))
            . self::p(sprintf(
                __('La mairie vient de fermer le %s (%s). Il n\'y a donc ni périscolaire ni cantine ce jour-là.', 'periscolaire-registration'),
                $date_lbl, $label
            ));

        $items_list = '<ul style="margin:8px 0;padding-left:20px;">';
        foreach ($fam['items'] as $item) {
            $svc_lbl = isset($services[$item->service]) ? $services[$item->service]['label'] : $item->service;
            $items_list .= '<li style="color:#1A1A1A;font-size:14px;margin-bottom:4px;">'
                . esc_html($item->child_prenom . ' ' . $item->child_nom) . ' — ' . esc_html($svc_lbl)
                . '</li>';
        }
        $items_list .= '</ul>';

        $body .= '<p style="color:#1A1A1A;font-size:14px;font-weight:bold;margin:16px 0 6px;">' . __('Inscriptions retirées :', 'periscolaire-registration') . '</p>'
            . $items_list
            . self::warning_box(__('Ces prestations ne seront <strong>pas facturées</strong>.', 'periscolaire-registration'))
            . self::btn(self::form_page_url(), __('Consulter mon planning', 'periscolaire-registration'));

        return self::send($fam['email'], $subject, self::layout($body, $subject));
    }

    /**
     * Prévient une famille que la cantine de son/ses enfant(s) a été
     * annulée pour un jour précis, à l'échelle d'une classe entière
     * (sortie scolaire, fermeture ponctuelle...). $fam : array('email'=>,
     * 'nom'=>, 'items'=> [objets avec child_nom, child_prenom]).
     */
    public static function send_cantine_cancelled($fam, $date_str, $classe_label, $reason) {
        $site     = self::site_name();
        $date_lbl = psc_day_label($date_str) . ' ' . date_i18n('d/m/Y', strtotime($date_str));
        $subject  = sprintf(__('[%s] Cantine annulée le %s (%s)', 'periscolaire-registration'), $site, $date_lbl, $classe_label);

        $body = self::h2(__('Cantine annulée', 'periscolaire-registration'))
            . self::p(sprintf(
                __("La cantine est annulée le %s pour la classe %s.\nMotif : %s", 'periscolaire-registration'),
                $date_lbl, $classe_label, $reason
            ));

        $items_list = '<ul style="margin:8px 0;padding-left:20px;">';
        foreach ($fam['items'] as $item) {
            $items_list .= '<li style="color:#1A1A1A;font-size:14px;margin-bottom:4px;">'
                . esc_html($item->child_prenom . ' ' . $item->child_nom) . '</li>';
        }
        $items_list .= '</ul>';

        $body .= '<p style="color:#1A1A1A;font-size:14px;font-weight:bold;margin:16px 0 6px;">' . __('Enfant(s) concerné(s) :', 'periscolaire-registration') . '</p>'
            . $items_list
            . self::warning_box(__('Cette prestation ne sera <strong>pas facturée</strong>.', 'periscolaire-registration'))
            . self::btn(self::form_page_url(), __('Consulter mon planning', 'periscolaire-registration'));

        return self::send($fam['email'], $subject, self::layout($body, $subject));
    }

    /**
     * Prévient une famille qu'une seule prestation (garderie matin, cantine
     * ou garderie soir — pas une classe entière) a été fermée pour un jour
     * précis, à l'échelle de toute la structure (calendrier scolaire v2).
     * $fam : array('email'=>, 'nom'=>, 'items'=> [objets avec child_nom, child_prenom]).
     */
    public static function send_service_closed($fam, $date_str, $service_label, $label) {
        $site     = self::site_name();
        $date_lbl = psc_day_label($date_str) . ' ' . date_i18n('d/m/Y', strtotime($date_str));
        $subject  = sprintf(__('[%s] %s fermée le %s', 'periscolaire-registration'), $site, $service_label, $date_lbl);

        $body = self::h2($service_label . __(' fermée', 'periscolaire-registration'))
            . self::p(sprintf(
                __('La mairie vient de fermer %s le %s (%s).', 'periscolaire-registration'),
                $service_label, $date_lbl, $label
            ));

        $items_list = '<ul style="margin:8px 0;padding-left:20px;">';
        foreach ($fam['items'] as $item) {
            $items_list .= '<li style="color:#1A1A1A;font-size:14px;margin-bottom:4px;">'
                . esc_html($item->child_prenom . ' ' . $item->child_nom) . '</li>';
        }
        $items_list .= '</ul>';

        $body .= '<p style="color:#1A1A1A;font-size:14px;font-weight:bold;margin:16px 0 6px;">' . __('Enfant(s) concerné(s) :', 'periscolaire-registration') . '</p>'
            . $items_list
            . self::warning_box(__('Cette prestation ne sera <strong>pas facturée</strong>.', 'periscolaire-registration'))
            . self::btn(self::form_page_url(), __('Consulter mon planning', 'periscolaire-registration'));

        return self::send($fam['email'], $subject, self::layout($body, $subject));
    }

    /**
     * Prévient une famille dont un enfant est en Forfait journée que ce
     * forfait a été remplacé, pour un jour précis, par les prestations
     * restantes suite à la fermeture d'une des 3 prestations (le forfait
     * n'est jamais facturé "moins un service", cf. close_service() dans
     * Psc_School_Calendar).
     */
    public static function send_forfait_downgraded($fam, $date_str, $closed_service_label, $remaining_service_labels) {
        $site           = self::site_name();
        $date_lbl       = psc_day_label($date_str) . ' ' . date_i18n('d/m/Y', strtotime($date_str));
        $subject        = sprintf(__('[%s] Forfait journée modifié le %s', 'periscolaire-registration'), $site, $date_lbl);
        $remaining_list = implode(__(' et ', 'periscolaire-registration'), $remaining_service_labels);

        $body = self::h2(__('Forfait journée modifié', 'periscolaire-registration'))
            . self::p(sprintf(
                __('La mairie a fermé %s le %s. Le forfait journée de votre/vos enfant(s) a été remplacé ce jour-là par : %s.', 'periscolaire-registration'),
                $closed_service_label, $date_lbl, $remaining_list !== '' ? $remaining_list : __('aucune prestation restante', 'periscolaire-registration')
            ));

        $items_list = '<ul style="margin:8px 0;padding-left:20px;">';
        foreach ($fam['items'] as $item) {
            $items_list .= '<li style="color:#1A1A1A;font-size:14px;margin-bottom:4px;">'
                . esc_html($item->child_prenom . ' ' . $item->child_nom) . '</li>';
        }
        $items_list .= '</ul>';

        $body .= '<p style="color:#1A1A1A;font-size:14px;font-weight:bold;margin:16px 0 6px;">' . __('Enfant(s) concerné(s) :', 'periscolaire-registration') . '</p>'
            . $items_list
            . self::info_box(__('Le tarif appliqué ce jour-là est ajusté en conséquence (', 'periscolaire-registration') . esc_html($remaining_list !== '' ? $remaining_list : __('aucune prestation', 'periscolaire-registration')) . __(' au lieu du forfait complet).', 'periscolaire-registration'))
            . self::btn(self::form_page_url(), __('Consulter mon planning', 'periscolaire-registration'));

        return self::send($fam['email'], $subject, self::layout($body, $subject));
    }

    /**
     * Prévient la mairie qu'une famille a signalé une absence depuis son
     * espace (bouton "Annulation / signalement d'absence" du tableau de
     * bord) — $services : tableau des codes de prestation retirés (GM,
     * CANT, GS, FORF).
     */
    public static function notify_absence_cancelled($parent, $child, $date, $services) {
        $site     = self::site_name();
        $date_lbl = psc_day_label($date) . ' ' . date_i18n('d/m/Y', strtotime($date));
        $child_name = trim($child->prenom . ' ' . $child->nom);
        $subject  = sprintf(__('[%s] Absence signalée : %s le %s', 'periscolaire-registration'), $site, $child_name, $date_lbl);

        $svc_labels = psc_services();
        $svc_list = implode(', ', array_map(
            function ($s) use ($svc_labels) { return isset($svc_labels[$s]) ? $svc_labels[$s]['label'] : $s; },
            $services
        ));

        $body = self::h2(__('Absence signalée par une famille', 'periscolaire-registration'))
            . self::info_box(
                __('<strong>Famille :</strong> ', 'periscolaire-registration') . esc_html($parent->nom ?: $parent->email) . ' (' . esc_html($parent->email) . ')<br>'
                . __('<strong>Enfant :</strong> ', 'periscolaire-registration') . esc_html($child_name) . '<br>'
                . __('<strong>Jour :</strong> ', 'periscolaire-registration') . esc_html($date_lbl) . '<br>'
                . __('<strong>Prestations annulées :</strong> ', 'periscolaire-registration') . esc_html($svc_list)
            )
            . self::warning_box(__('Ces prestations ne seront <strong>pas facturées</strong>.', 'periscolaire-registration'))
            . self::btn(admin_url('admin.php?page=psc_children'), __('Voir les enfants', 'periscolaire-registration'));

        return self::send(psc_mairie_email(), $subject, self::layout($body, $subject));
    }

    /* ------------------------------------------------------------------ */
    /* Demandes d'inscription                                               */
    /* ------------------------------------------------------------------ */

    public static function send_request_verification($email, $url, $attachments = array()) {
        $site    = self::site_name();
        $subject = Psc_Email_Templates::subject('request_verify', array('site' => $site));
        $intro   = Psc_Email_Templates::body_html('request_verify', array('site' => $site));
        $days    = (int) round(psc_email_confirmation_ttl() / DAY_IN_SECONDS);

        $body = self::h2(__('Confirmez votre demande d\'inscription', 'periscolaire-registration'))
            . '<p style="color:#1A1A1A;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;margin:0 0 12px;">' . $intro . '</p>'
            . self::btn($url, __('Confirmer ma demande', 'periscolaire-registration'))
            . self::info_box(
                __('<strong>⏱ Ce lien est valable ', 'periscolaire-registration') . $days . __(' jour', 'periscolaire-registration') . ($days > 1 ? __('s', 'periscolaire-registration') : '') . '.</strong><br>'
                . __('Votre demande sera ensuite examinée par la mairie, qui vous contactera par e-mail.', 'periscolaire-registration')
            )
            . ($attachments ? self::info_box(
                __('<strong>📎 Mandat de prélèvement SEPA joint.</strong><br>', 'periscolaire-registration')
                . __('Merci de l\'imprimer, de le signer, puis de l\'adresser à votre banque.', 'periscolaire-registration')
            ) : '')
            . self::p(
                __('Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : aucune donnée ne sera transmise.', 'periscolaire-registration')
            );

        return self::send($email, $subject, self::layout($body, $subject), $attachments);
    }

    public static function notify_mairie_new_request($req, $children) {
        $site    = self::site_name();
        $subject = Psc_Email_Templates::subject('notify_mairie', array('site' => $site));
        $intro   = Psc_Email_Templates::body_html('notify_mairie', array('site' => $site));

        $req_family_name = trim(($req->prenom ?? '') . ' ' . ($req->nom ?? ''));

        $contact = __('<strong>E-mail :</strong> ', 'periscolaire-registration') . esc_html($req->email) . '<br>';
        if ($req_family_name !== '') $contact .= __('<strong>Famille :</strong> ', 'periscolaire-registration') . esc_html($req_family_name) . '<br>';
        if ($req->telephone)         $contact .= __('<strong>Téléphone :</strong> ', 'periscolaire-registration') . esc_html($req->telephone) . '<br>';

        $children_list = '<ul style="margin:8px 0;padding-left:20px;">';
        foreach ($children as $c) {
            $badges = array();
            if (!empty($c['sans_porc'])) $badges[] = __('sans porc', 'periscolaire-registration');
            if (!empty($c['vegan']))     $badges[] = __('sans viande', 'periscolaire-registration');
            // Allergie alimentaire déclarée : alerte PAI dans le même mail
            // — le service doit prendre contact, la promesse est faite au
            // parent dès la demande.
            if (!empty($c['food_allergies'])) $badges[] = __('ALLERGIE ALIMENTAIRE : ', 'periscolaire-registration') . $c['food_allergies'];
            $children_list .= '<li style="color:#1A1A1A;font-size:14px;margin-bottom:4px;">'
                . esc_html($c['prenom'] . ' ' . $c['nom'])
                . (!empty($c['classe']) ? ' <span style="color:#8B8279;">(' . esc_html($c['classe']) . ')</span>' : '')
                . ($badges ? ' <span style="color:#8B8279;">— ' . esc_html(implode(', ', $badges)) . '</span>' : '')
                . '</li>';
        }
        $children_list .= '</ul>';

        $body = self::h2(__('Nouvelle demande d\'inscription', 'periscolaire-registration'))
            . '<p style="color:#1A1A1A;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;margin:0 0 12px;">' . $intro . '</p>'
            . self::info_box($contact)
            . '<p style="color:#1A1A1A;font-size:14px;font-weight:bold;margin:16px 0 6px;">' . __('Enfant(s) déclaré(s) :', 'periscolaire-registration') . '</p>'
            . $children_list;

        if ($req->message) {
            $body .= '<p style="color:#1A1A1A;font-size:14px;font-weight:bold;margin:16px 0 6px;">' . __('Message du parent :', 'periscolaire-registration') . '</p>'
                   . '<blockquote style="margin:0;padding:12px 16px;border:1px solid #E5DCC3;'
                   . 'background-color:#F5E7DC;color:#1A1A1A;font-size:14px;line-height:1.5;">'
                   . nl2br(esc_html($req->message))
                   . '</blockquote>';
        }

        $body .= self::warning_box(
            __('Les informations ci-dessus sont déclaratives et doivent être vérifiées avant validation.', 'periscolaire-registration')
        )
        . self::btn(admin_url('admin.php?page=psc_requests'), __('Traiter la demande', 'periscolaire-registration'));

        return self::send(psc_mairie_email(), $subject, self::layout($body, $subject));
    }

    public static function send_request_rejected($email, $note = '') {
        $site    = self::site_name();
        $subject = Psc_Email_Templates::subject('request_rejected', array('site' => $site));
        $intro   = Psc_Email_Templates::body_html('request_rejected', array('site' => $site));

        $body = self::h2(__('Votre demande d\'inscription', 'periscolaire-registration'))
            . '<p style="color:#1A1A1A;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;margin:0 0 12px;">' . $intro . '</p>';

        if ($note !== '') {
            $body .= '<p style="color:#1A1A1A;font-size:14px;font-weight:bold;margin:16px 0 6px;">' . __('Motif communiqué :', 'periscolaire-registration') . '</p>'
                   . '<blockquote style="margin:0;padding:12px 16px;border:1px solid #E5DCC3;'
                   . 'background-color:#F5E7DC;color:#1A1A1A;font-size:14px;line-height:1.5;">'
                   . nl2br(esc_html($note))
                   . '</blockquote>';
        }

        return self::send($email, $subject, self::layout($body, $subject));
    }
}
