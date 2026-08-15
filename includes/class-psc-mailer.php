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
        return '<table cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">'
            . '<tr><td style="background-color:#23478B;border-radius:4px;padding:12px 28px;">'
            . '<a href="' . esc_url($url) . '" '
            . 'style="color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none;display:inline-block;">'
            . esc_html($label) . '</a>'
            . '</td></tr></table>';
    }

    protected static function info_box($html) {
        return '<div style="background:#f0f4fb;border-left:4px solid #23478B;border-radius:0 4px 4px 0;'
            . 'padding:14px 18px;margin:20px 0;font-size:14px;color:#444;line-height:1.6;">'
            . $html . '</div>';
    }

    protected static function warning_box($html) {
        return '<div style="background:#fff8e1;border-left:4px solid #f5a623;border-radius:0 4px 4px 0;'
            . 'padding:14px 18px;margin:20px 0;font-size:14px;color:#555;line-height:1.6;">'
            . $html . '</div>';
    }

    protected static function h2($text) {
        return '<h2 style="color:#23478B;font-size:17px;margin:0 0 16px;padding-bottom:8px;'
            . 'border-bottom:2px solid #e8edf5;">' . esc_html($text) . '</h2>';
    }

    protected static function p($text, $style = '') {
        return '<p style="color:#444;font-size:14px;line-height:1.7;margin:0 0 12px;' . $style . '">'
            . nl2br(esc_html($text)) . '</p>';
    }

    /* ------------------------------------------------------------------ */
    /* Lien de connexion parent                                             */
    /* ------------------------------------------------------------------ */

    public static function send_login_link($parent, $url, $context = 'login') {
        $site    = self::site_name();
        $minutes = (int) (psc_login_link_ttl() / MINUTE_IN_SECONDS);

        $tpl_key   = ($context === 'approved') ? 'login_approved' : 'login_link';
        $btn_label = ($context === 'approved') ? 'Accéder à mon espace' : 'Me connecter';
        $h2_label  = ($context === 'approved') ? 'Votre compte est activé ✓' : 'Votre lien de connexion';

        $subject = Psc_Email_Templates::subject($tpl_key, array('site' => $site));
        $intro   = Psc_Email_Templates::body_html($tpl_key, array('site' => $site, 'minutes' => $minutes));

        $body = self::h2($h2_label)
            . '<p style="color:#444;font-size:14px;line-height:1.7;margin:0 0 12px;">' . $intro . '</p>'
            . self::btn($url, $btn_label)
            . self::info_box(
                '<strong>⏱ Durée de validité :</strong> ce lien expire dans <strong>' . $minutes . ' minutes</strong> '
                . 'et ne peut être utilisé qu\'une seule fois.'
            )
            . self::p('Si vous n\'êtes pas à l\'origine de cette demande, vous pouvez ignorer ce message en toute sécurité.');

        return self::send($parent->email, $subject, self::layout($body, $subject));
    }

    /**
     * Confirmation envoyée à la NOUVELLE adresse lors d'une demande de
     * changement d'e-mail depuis "Mon profil" (cf. Psc_Parents::request_email_change) —
     * l'ancienne adresse reste active tant que ce lien n'a pas été cliqué.
     */
    public static function send_email_change_confirmation($parent, $new_email, $url) {
        $site    = self::site_name();
        $subject = sprintf('[%s] Confirmez votre nouvelle adresse e-mail', $site);

        $body = self::h2('Confirmez votre nouvelle adresse e-mail')
            . self::p(sprintf(
                'Vous avez demandé à utiliser %s comme nouvelle adresse de connexion à votre espace famille.',
                $new_email
            ))
            . self::btn($url, 'Confirmer cette adresse')
            . self::info_box(
                '<strong>⏱ Ce lien est valable 3 jours.</strong><br>'
                . 'Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : votre adresse actuelle '
                . 'reste inchangée et pleinement fonctionnelle.'
            );

        return self::send($new_email, $subject, self::layout($body, $subject));
    }

    /* ------------------------------------------------------------------ */
    /* Récapitulatif planning                                               */
    /* ------------------------------------------------------------------ */

    public static function send_recap($parent, $trimestre, $children, $reg_map, $services, $diff_added = array(), $diff_removed = array()) {
        $site    = self::site_name();
        $subject = Psc_Email_Templates::subject('recap', array('site' => $site, 'trimestre' => $trimestre->label));
        $intro   = Psc_Email_Templates::body_html('recap', array('site' => $site, 'trimestre' => $trimestre->label));

        $body = self::h2('Confirmation de votre planning');
        $body .= '<p style="color:#444;font-size:14px;line-height:1.7;margin:0 0 12px;">' . $intro . '</p>';

        // Bloc diff uniquement s'il y a des changements depuis le dernier récap
        if (!empty($diff_added) || !empty($diff_removed)) {
            $child_index = array();
            foreach ($children as $c) $child_index[(int) $c->id] = $c;
            $body .= self::h2('Modifications depuis votre dernier récapitulatif');
            $body .= self::_build_diff_table($diff_added, $diff_removed, $child_index, $services);
        }

        $tables = self::_build_planning_tables($children, $reg_map, $services, 'months');
        $body  .= $tables['html'];

        if ($tables['has_any'] && count($children) > 1) {
            $body .= '<div style="background:#23478B;border-radius:4px;padding:14px 20px;margin:16px 0;">'
                   . '<p style="margin:0;color:#ffffff;font-size:16px;font-weight:bold;">'
                   . 'Montant indicatif total : ' . number_format($tables['grand_total'], 2, ',', ' ') . ' €'
                   . '</p></div>';
        }

        $body .= self::warning_box(
            'Ce montant est donné <strong>à titre indicatif</strong>. '
            . 'La facturation définitive est établie par la mairie.'
        );

        if (psc_lock_hours() > 0) {
            $body .= self::p(
                'Vous pouvez modifier votre planning jusqu\'à ' . psc_lock_hours()
                . ' heures avant chaque jour concerné.'
            );
        }

        $body .= self::btn(self::form_page_url(), 'Modifier mon planning');

        $html = self::layout($body, $subject);
        $sent = self::send($parent->email, $subject, $html);

        if ($sent && psc_notify_mairie_enabled()) {
            $names = array();
            foreach ($children as $c) $names[] = $c->prenom . ' ' . $c->nom;
            $mairie_body = self::h2('Planning validé')
                . self::info_box(
                    '<strong>Famille :</strong> ' . esc_html($parent->email) . '<br>'
                    . '<strong>Enfant(s) :</strong> ' . esc_html(implode(', ', $names))
                )
                . $body;
            self::send(
                psc_mairie_email(),
                sprintf('[%s] Planning validé — %s', $site, implode(', ', $names)),
                self::layout($mairie_body)
            );
        }

        return $sent;
    }

    public static function send_admin_correction($parent, $trimestre, $children, $reg_map, $services, $diff_added = array(), $diff_removed = array()) {
        $site    = self::site_name();
        $subject = sprintf('[%s] Votre planning périscolaire a été mis à jour — %s', $site, $trimestre->label);

        $child_index = array();
        foreach ($children as $c) $child_index[(int) $c->id] = $c;

        $body  = self::h2('Modifications apportées par la mairie');
        $body .= self::_build_diff_table($diff_added, $diff_removed, $child_index, $services);

        $body .= self::h2('Récapitulatif complet — ' . esc_html($trimestre->label));

        $tables = self::_build_planning_tables($children, $reg_map, $services, 'totals');
        $body  .= $tables['html'];

        if ($tables['has_any'] && count($children) > 1) {
            $body .= '<div style="background:#23478B;border-radius:4px;padding:14px 20px;margin:16px 0;">'
                   . '<p style="margin:0;color:#ffffff;font-size:16px;font-weight:bold;">'
                   . 'Montant indicatif total : ' . number_format($tables['grand_total'], 2, ',', ' ') . ' €'
                   . '</p></div>';
        }

        $body .= self::warning_box(
            'Ce montant est donné <strong>à titre indicatif</strong>. '
            . 'La facturation définitive est établie par la mairie.'
        );
        $body .= self::btn(self::form_page_url(), 'Consulter mon planning');

        return self::send($parent->email, $subject, self::layout($body, $subject));
    }

    /**
     * Rendu HTML du tableau de diff (ajouts / suppressions).
     * Partagé entre le récap parent et la correction admin.
     */
    private static function _build_diff_table($diff_added, $diff_removed, $child_index, $services) {
        if (empty($diff_added) && empty($diff_removed)) {
            return '<p style="color:#888;font-size:14px;font-style:italic;margin:0 0 24px;">Aucune modification.</p>';
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
               . '<th style="background:#e8edf5;color:#23478B;padding:7px 10px;text-align:left;border:1px solid #d0d8e8;">Date</th>'
               . '<th style="background:#e8edf5;color:#23478B;padding:7px 10px;text-align:left;border:1px solid #d0d8e8;">Enfant</th>'
               . '<th style="background:#e8edf5;color:#23478B;padding:7px 10px;text-align:left;border:1px solid #d0d8e8;">Prestation</th>'
               . '<th style="background:#e8edf5;color:#23478B;padding:7px 10px;text-align:center;border:1px solid #d0d8e8;">Modification</th>'
               . '</tr></thead><tbody>';

        foreach ($diff_rows as $row) {
            list($cid, $date, $svc) = explode('|', $row['key']);
            $child     = isset($child_index[(int) $cid]) ? $child_index[(int) $cid] : null;
            $child_lbl = $child ? esc_html($child->prenom . ' ' . $child->nom) : '';
            $svc_lbl   = isset($services[$svc]) ? esc_html($services[$svc]['label']) : esc_html($svc);
            $date_lbl  = esc_html(psc_day_label($date) . ' ' . date_i18n('d/m/Y', strtotime($date)));

            if ($row['type'] === 'add') {
                $badge = '<span style="background:#d4edda;color:#155724;padding:2px 8px;border-radius:3px;font-weight:bold;font-size:12px;">+ Ajout</span>';
                $bg    = '#f6fff8';
            } else {
                $badge = '<span style="background:#f8d7da;color:#721c24;padding:2px 8px;border-radius:3px;font-weight:bold;font-size:12px;">− Suppression</span>';
                $bg    = '#fff8f8';
            }

            $html .= '<tr style="background:' . $bg . ';">'
                   . '<td style="padding:6px 10px;border:1px solid #e5e9f0;white-space:nowrap;">' . $date_lbl . '</td>'
                   . '<td style="padding:6px 10px;border:1px solid #e5e9f0;">' . $child_lbl . '</td>'
                   . '<td style="padding:6px 10px;border:1px solid #e5e9f0;">' . $svc_lbl . '</td>'
                   . '<td style="padding:6px 10px;border:1px solid #e5e9f0;text-align:center;">' . $badge . '</td>'
                   . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * Construit les tableaux de planning par enfant.
     *
     * $mode :
     *   'days'   — tableau jour par jour (récap classique)
     *   'months' — récap par mois avec comptes par prestation (récap parent)
     *   'totals' — totaux uniquement, sans détail par jour/mois (correction admin)
     */
    private static function _build_planning_tables($children, $reg_map, $services, $mode = 'days') {
        $grand_total = 0.0;
        $has_any     = false;
        $html        = '';

        foreach ($children as $child) {
            $child_classe = Psc_School_Years::classe_for($child->id);
            $child_label = strtoupper($child->prenom . ' ' . $child->nom)
                . ($child_classe ? ' <span style="color:#666;font-weight:normal;font-size:13px;">(' . esc_html($child_classe) . ')</span>' : '');

            $html .= '<div style="margin:24px 0;">';
            $html .= '<h3 style="font-size:15px;color:#23478B;margin:0 0 10px;padding:8px 12px;'
                   . 'background:#f0f4fb;border-radius:4px;">' . $child_label . '</h3>';

            $dates = array();
            foreach ($reg_map as $key => $v) {
                list($cid, $date, $service) = explode('|', $key);
                if ((int) $cid !== (int) $child->id) continue;
                $dates[$date][] = $service;
            }
            ksort($dates);

            if (empty($dates)) {
                $html .= '<p style="color:#888;font-size:14px;font-style:italic;margin:0;">Aucune inscription enregistrée.</p>';
                $html .= '</div>';
                continue;
            }

            $has_any     = true;
            $child_total = 0.0;
            $counts      = array_fill_keys(psc_allowed_services(), 0);

            foreach ($dates as $date => $servs) {
                foreach (psc_allowed_services() as $code) {
                    if (!in_array($code, $servs, true)) continue;
                    $counts[$code]++;
                    $child_total += (float) $services[$code]['price'];
                }
            }

            if ($mode === 'days') {
                $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-bottom:12px;font-size:13px;">';
                $html .= '<thead><tr>'
                       . '<th style="background:#e8edf5;color:#23478B;padding:7px 10px;text-align:left;border:1px solid #d0d8e8;">Date</th>'
                       . '<th style="background:#e8edf5;color:#23478B;padding:7px 10px;text-align:left;border:1px solid #d0d8e8;">Jour</th>'
                       . '<th style="background:#e8edf5;color:#23478B;padding:7px 10px;text-align:left;border:1px solid #d0d8e8;">Prestations</th>'
                       . '</tr></thead><tbody>';
                $alt = false;
                foreach ($dates as $date => $servs) {
                    $labels = array();
                    foreach (psc_allowed_services() as $code) {
                        if (in_array($code, $servs, true)) $labels[] = $services[$code]['label'];
                    }
                    $bg    = $alt ? '#f8f9fb' : '#ffffff';
                    $html .= '<tr style="background:' . $bg . ';">'
                           . '<td style="padding:6px 10px;border:1px solid #e5e9f0;white-space:nowrap;">' . date_i18n('d/m/Y', strtotime($date)) . '</td>'
                           . '<td style="padding:6px 10px;border:1px solid #e5e9f0;white-space:nowrap;">' . esc_html(psc_day_label($date)) . '</td>'
                           . '<td style="padding:6px 10px;border:1px solid #e5e9f0;">' . esc_html(implode(', ', $labels)) . '</td>'
                           . '</tr>';
                    $alt = !$alt;
                }
                $html .= '</tbody></table>';
            }

            if ($mode === 'months') {
                // Regrouper par mois
                $by_month = array();
                foreach ($dates as $date => $servs) {
                    $by_month[substr($date, 0, 7)][$date] = $servs;
                }

                foreach ($by_month as $ym => $month_dates) {
                    $month_label  = ucfirst(date_i18n('F Y', strtotime($ym . '-01')));
                    $month_counts = array_fill_keys(psc_allowed_services(), 0);
                    $month_total  = 0.0;

                    foreach ($month_dates as $servs) {
                        foreach (psc_allowed_services() as $code) {
                            if (!in_array($code, $servs, true)) continue;
                            $month_counts[$code]++;
                            $month_total += (float) $services[$code]['price'];
                        }
                    }

                    $html .= '<p style="font-size:13px;font-weight:bold;color:#444;margin:12px 0 4px;'
                           . 'border-bottom:1px solid #e8edf5;padding-bottom:4px;">' . esc_html($month_label) . '</p>';
                    $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;margin-bottom:4px;">';

                    foreach (psc_allowed_services() as $code) {
                        if ($month_counts[$code] === 0) continue;
                        $st    = $month_counts[$code] * (float) $services[$code]['price'];
                        $html .= '<tr>'
                               . '<td style="padding:3px 10px 3px 16px;color:#555;">' . esc_html($services[$code]['label']) . '</td>'
                               . '<td style="padding:3px 10px;color:#555;text-align:center;width:50px;">' . $month_counts[$code] . ' j.</td>'
                               . '<td style="padding:3px 10px;color:#555;text-align:right;width:70px;">' . number_format($services[$code]['price'], 2, ',', ' ') . ' €</td>'
                               . '<td style="padding:3px 10px;color:#333;text-align:right;font-weight:bold;width:80px;">' . number_format($st, 2, ',', ' ') . ' €</td>'
                               . '</tr>';
                    }

                    $html .= '<tr style="border-top:1px solid #d0d8e8;">'
                           . '<td colspan="3" style="padding:4px 10px 4px 16px;color:#555;font-style:italic;">Sous-total ' . esc_html($month_label) . '</td>'
                           . '<td style="padding:4px 10px;color:#555;text-align:right;font-weight:bold;">' . number_format($month_total, 2, ',', ' ') . ' €</td>'
                           . '</tr>';
                    $html .= '</table>';
                }
            }

            // Ligne totaux par enfant (tous modes)
            $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;margin-bottom:8px;' . ($mode === 'totals' ? '' : 'margin-top:8px;') . '">';
            if ($mode === 'totals') {
                foreach (psc_allowed_services() as $code) {
                    if ($counts[$code] === 0) continue;
                    $st    = $counts[$code] * (float) $services[$code]['price'];
                    $html .= '<tr>'
                           . '<td style="padding:4px 10px;color:#555;">' . esc_html($services[$code]['label']) . '</td>'
                           . '<td style="padding:4px 10px;color:#555;text-align:center;">' . $counts[$code] . ' j.</td>'
                           . '<td style="padding:4px 10px;color:#555;text-align:right;">' . number_format($services[$code]['price'], 2, ',', ' ') . ' €</td>'
                           . '<td style="padding:4px 10px;color:#333;text-align:right;font-weight:bold;">' . number_format($st, 2, ',', ' ') . ' €</td>'
                           . '</tr>';
                }
            }
            $html .= '<tr style="border-top:2px solid #23478B;">'
                   . '<td colspan="3" style="padding:7px 10px;font-weight:bold;color:#23478B;">Montant indicatif</td>'
                   . '<td style="padding:7px 10px;font-weight:bold;color:#23478B;text-align:right;">' . number_format($child_total, 2, ',', ' ') . ' €</td>'
                   . '</tr>';
            $html .= '</table>';
            $html .= '</div>';

            $grand_total += $child_total;
        }

        return array('html' => $html, 'grand_total' => $grand_total, 'has_any' => $has_any);
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

        $body = self::h2('Menu de la semaine du ' . $semaine_label)
            . '<p style="color:#444;font-size:14px;line-height:1.7;margin:0 0 12px;">' . $intro . '</p>';

        $body .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:14px;margin:16px 0;">';
        $has_content = false;
        foreach (Psc_Menus::jour_labels() as $key => $label) {
            $content = trim((string) $menu->$key);
            if ($content === '') continue;
            $has_content = true;
            $body .= '<tr>'
                . '<td style="background:#e8edf5;color:#23478B;font-weight:bold;padding:8px 12px;'
                . 'border:1px solid #d0d8e8;width:110px;vertical-align:top;white-space:nowrap;">' . esc_html($label) . '</td>'
                . '<td style="padding:8px 12px;border:1px solid #e5e9f0;">' . nl2br(esc_html($content)) . '</td>'
                . '</tr>';
        }
        $body .= '</table>';
        if (!$has_content) {
            $body .= '<p style="color:#888;font-size:14px;font-style:italic;">Menu non encore renseigné pour cette semaine.</p>';
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
        ));
        $intro = Psc_Email_Templates::body_html('supplier_order', array(
            'site'    => $site,
            'semaine' => $semaine_label,
            'total'   => $data['total'],
        ));

        $body = self::h2('Commande cantine — semaine du ' . $semaine_label)
            . '<p style="color:#444;font-size:14px;line-height:1.7;margin:0 0 20px;">' . $intro . '</p>';

        // Seuls les jours d'école réellement ouverts cette semaine-là
        // figurent dans $data['jours'] (Psc_Supplier_Orders::compute_counts).
        $all_labels = Psc_Supplier_Orders::jour_labels();
        $jours      = array_keys($data['jours']);

        $body .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;margin:16px 0;">';
        $body .= '<thead><tr>'
            . '<th style="background:#e8edf5;color:#23478B;padding:7px 10px;text-align:left;border:1px solid #d0d8e8;">Classe</th>';
        foreach ($jours as $jour) {
            $body .= '<th style="background:#e8edf5;color:#23478B;padding:7px 10px;text-align:center;border:1px solid #d0d8e8;">'
                . esc_html($all_labels[$jour]) . '<br><small>' . esc_html(date_i18n('d/m', strtotime($data['jours'][$jour]))) . '</small></th>';
        }
        $body .= '<th style="background:#d3d9ea;color:#23478B;padding:7px 10px;text-align:center;border:1px solid #d0d8e8;">Total</th>';
        $body .= '</tr></thead><tbody>';

        if (empty($data['classes'])) {
            $body .= '<tr><td colspan="' . (count($jours) + 2) . '" style="padding:10px;color:#888;font-style:italic;border:1px solid #e5e9f0;">Aucun repas de cantine déclaré cette semaine.</td></tr>';
        }

        foreach ($data['classes'] as $code => $label) {
            $body .= '<tr>'
                . '<td style="padding:6px 10px;border:1px solid #e5e9f0;font-weight:bold;">' . esc_html($label) . '</td>';
            foreach ($jours as $jour) {
                $n = $data['counts'][$code][$jour] ?? 0;
                $body .= '<td style="padding:6px 10px;border:1px solid #e5e9f0;text-align:center;">' . ($n > 0 ? $n : '—') . '</td>';
            }
            $body .= '<td style="padding:6px 10px;border:1px solid #e5e9f0;text-align:center;font-weight:bold;">' . (int) $data['totaux_classe'][$code] . '</td>';
            $body .= '</tr>';
        }

        $body .= '<tr style="background:#f0f4fb;">'
            . '<td style="padding:7px 10px;border:1px solid #d0d8e8;font-weight:bold;">TOTAL</td>';
        foreach ($jours as $jour) {
            $body .= '<td style="padding:7px 10px;border:1px solid #d0d8e8;text-align:center;font-weight:bold;">' . (int) $data['totaux_jour'][$jour] . '</td>';
        }
        $body .= '<td style="padding:7px 10px;border:1px solid #d0d8e8;text-align:center;font-weight:bold;color:#23478B;">' . (int) $data['total'] . '</td>';
        $body .= '</tr>';
        $body .= '</tbody></table>';

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
        $subject   = sprintf('[%s] Jour sans école le %s : vos inscriptions ont été retirées', $site, $date_lbl);

        $body = self::h2('Un jour d\'école a été annulé')
            . self::p(sprintf(
                'La mairie vient de fermer le %s (%s). Il n\'y a donc ni périscolaire ni cantine ce jour-là.',
                $date_lbl, $label
            ));

        $items_list = '<ul style="margin:8px 0;padding-left:20px;">';
        foreach ($fam['items'] as $item) {
            $svc_lbl = isset($services[$item->service]) ? $services[$item->service]['label'] : $item->service;
            $items_list .= '<li style="color:#444;font-size:14px;margin-bottom:4px;">'
                . esc_html($item->child_prenom . ' ' . $item->child_nom) . ' — ' . esc_html($svc_lbl)
                . '</li>';
        }
        $items_list .= '</ul>';

        $body .= '<p style="color:#444;font-size:14px;font-weight:bold;margin:16px 0 6px;">Inscriptions retirées :</p>'
            . $items_list
            . self::warning_box('Ces prestations ne seront <strong>pas facturées</strong>.')
            . self::btn(self::form_page_url(), 'Consulter mon planning');

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
        $subject  = sprintf('[%s] Cantine annulée le %s (%s)', $site, $date_lbl, $classe_label);

        $body = self::h2('Cantine annulée')
            . self::p(sprintf(
                "La cantine est annulée le %s pour la classe %s.\nMotif : %s",
                $date_lbl, $classe_label, $reason
            ));

        $items_list = '<ul style="margin:8px 0;padding-left:20px;">';
        foreach ($fam['items'] as $item) {
            $items_list .= '<li style="color:#444;font-size:14px;margin-bottom:4px;">'
                . esc_html($item->child_prenom . ' ' . $item->child_nom) . '</li>';
        }
        $items_list .= '</ul>';

        $body .= '<p style="color:#444;font-size:14px;font-weight:bold;margin:16px 0 6px;">Enfant(s) concerné(s) :</p>'
            . $items_list
            . self::warning_box('Cette prestation ne sera <strong>pas facturée</strong>.')
            . self::btn(self::form_page_url(), 'Consulter mon planning');

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
        $subject  = sprintf('[%s] Absence signalée : %s le %s', $site, $child_name, $date_lbl);

        $svc_labels = psc_services();
        $svc_list = implode(', ', array_map(
            function ($s) use ($svc_labels) { return isset($svc_labels[$s]) ? $svc_labels[$s]['label'] : $s; },
            $services
        ));

        $body = self::h2('Absence signalée par une famille')
            . self::info_box(
                '<strong>Famille :</strong> ' . esc_html($parent->nom ?: $parent->email) . ' (' . esc_html($parent->email) . ')<br>'
                . '<strong>Enfant :</strong> ' . esc_html($child_name) . '<br>'
                . '<strong>Jour :</strong> ' . esc_html($date_lbl) . '<br>'
                . '<strong>Prestations annulées :</strong> ' . esc_html($svc_list)
            )
            . self::warning_box('Ces prestations ne seront <strong>pas facturées</strong>.')
            . self::btn(admin_url('admin.php?page=psc_children'), 'Voir les enfants');

        return self::send(psc_mairie_email(), $subject, self::layout($body, $subject));
    }

    /* ------------------------------------------------------------------ */
    /* Demandes d'inscription                                               */
    /* ------------------------------------------------------------------ */

    public static function send_request_verification($email, $url, $attachments = array()) {
        $site    = self::site_name();
        $subject = Psc_Email_Templates::subject('request_verify', array('site' => $site));
        $intro   = Psc_Email_Templates::body_html('request_verify', array('site' => $site));

        $body = self::h2('Confirmez votre demande d\'inscription')
            . '<p style="color:#444;font-size:14px;line-height:1.7;margin:0 0 12px;">' . $intro . '</p>'
            . self::btn($url, 'Confirmer ma demande')
            . self::info_box(
                '<strong>⏱ Ce lien est valable 3 jours.</strong><br>'
                . 'Votre demande sera ensuite examinée par la mairie, qui vous contactera par e-mail.'
            )
            . ($attachments ? self::info_box(
                '<strong>📎 Mandat de prélèvement SEPA joint.</strong><br>'
                . 'Merci de l\'imprimer, de le signer, puis de l\'adresser à votre banque.'
            ) : '')
            . self::p(
                'Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : aucune donnée ne sera transmise.'
            );

        return self::send($email, $subject, self::layout($body, $subject), $attachments);
    }

    public static function notify_mairie_new_request($req, $children) {
        $site    = self::site_name();
        $subject = Psc_Email_Templates::subject('notify_mairie', array('site' => $site));
        $intro   = Psc_Email_Templates::body_html('notify_mairie', array('site' => $site));

        $req_family_name = trim(($req->prenom ?? '') . ' ' . ($req->nom ?? ''));

        $contact = '<strong>E-mail :</strong> ' . esc_html($req->email) . '<br>';
        if ($req_family_name !== '') $contact .= '<strong>Famille :</strong> ' . esc_html($req_family_name) . '<br>';
        if ($req->telephone)         $contact .= '<strong>Téléphone :</strong> ' . esc_html($req->telephone) . '<br>';

        $children_list = '<ul style="margin:8px 0;padding-left:20px;">';
        foreach ($children as $c) {
            $badges = array();
            if (!empty($c['sans_porc'])) $badges[] = 'sans porc';
            if (!empty($c['vegan']))     $badges[] = 'sans viande';
            $children_list .= '<li style="color:#444;font-size:14px;margin-bottom:4px;">'
                . esc_html($c['prenom'] . ' ' . $c['nom'])
                . ($c['classe'] ? ' <span style="color:#888;">(' . esc_html($c['classe']) . ')</span>' : '')
                . ($badges ? ' <span style="color:#888;">— ' . esc_html(implode(', ', $badges)) . '</span>' : '')
                . '</li>';
        }
        $children_list .= '</ul>';

        $body = self::h2('Nouvelle demande d\'inscription')
            . '<p style="color:#444;font-size:14px;line-height:1.7;margin:0 0 12px;">' . $intro . '</p>'
            . self::info_box($contact)
            . '<p style="color:#444;font-size:14px;font-weight:bold;margin:16px 0 6px;">Enfant(s) déclaré(s) :</p>'
            . $children_list;

        if ($req->message) {
            $body .= '<p style="color:#444;font-size:14px;font-weight:bold;margin:16px 0 6px;">Message du parent :</p>'
                   . '<blockquote style="margin:0;padding:12px 16px;border-left:3px solid #ccc;'
                   . 'background:#f9f9f9;color:#555;font-size:14px;line-height:1.6;">'
                   . nl2br(esc_html($req->message))
                   . '</blockquote>';
        }

        $body .= self::warning_box(
            'Les informations ci-dessus sont déclaratives et doivent être vérifiées avant validation.'
        )
        . self::btn(admin_url('admin.php?page=psc_requests'), 'Traiter la demande');

        return self::send(psc_mairie_email(), $subject, self::layout($body, $subject));
    }

    public static function send_request_rejected($email, $note = '') {
        $site    = self::site_name();
        $subject = Psc_Email_Templates::subject('request_rejected', array('site' => $site));
        $intro   = Psc_Email_Templates::body_html('request_rejected', array('site' => $site));

        $body = self::h2('Votre demande d\'inscription')
            . '<p style="color:#444;font-size:14px;line-height:1.7;margin:0 0 12px;">' . $intro . '</p>';

        if ($note !== '') {
            $body .= '<p style="color:#444;font-size:14px;font-weight:bold;margin:16px 0 6px;">Motif communiqué :</p>'
                   . '<blockquote style="margin:0;padding:12px 16px;border-left:3px solid #f5a623;'
                   . 'background:#fff8e1;color:#555;font-size:14px;line-height:1.6;">'
                   . nl2br(esc_html($note))
                   . '</blockquote>';
        }

        return self::send($email, $subject, self::layout($body, $subject));
    }
}
