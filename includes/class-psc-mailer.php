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
    protected static function send($to, $subject, $html) {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::site_name() . ' <' . get_option('admin_email') . '>',
        );
        return wp_mail($to, $subject, $html, $headers);
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

        if ($context === 'approved') {
            $subject = sprintf('[%s] Votre accès aux inscriptions périscolaires est activé', $site);
            $intro   = 'Votre demande d\'inscription a été validée par la mairie. '
                     . 'Vous pouvez dès maintenant accéder à votre espace et planifier vos inscriptions.';
            $btn_label = 'Accéder à mon espace';
        } else {
            $subject   = sprintf('[%s] Votre lien d\'accès aux inscriptions périscolaires', $site);
            $intro     = 'Voici votre lien d\'accès au formulaire d\'inscription périscolaire. '
                       . 'Cliquez sur le bouton ci-dessous pour vous connecter.';
            $btn_label = 'Me connecter';
        }

        $body = self::h2($context === 'approved' ? 'Votre compte est activé ✓' : 'Votre lien de connexion')
            . self::p($intro)
            . self::btn($url, $btn_label)
            . self::info_box(
                '<strong>⏱ Durée de validité :</strong> ce lien expire dans <strong>' . $minutes . ' minutes</strong> '
                . 'et ne peut être utilisé qu\'une seule fois.'
            )
            . self::p('Si vous n\'êtes pas à l\'origine de cette demande, vous pouvez ignorer ce message en toute sécurité.');

        return self::send($parent->email, $subject, self::layout($body, $subject));
    }

    /* ------------------------------------------------------------------ */
    /* Récapitulatif planning                                               */
    /* ------------------------------------------------------------------ */

    public static function send_recap($parent, $trimestre, $children, $reg_map, $services) {
        $site    = self::site_name();
        $subject = sprintf('[%s] Confirmation de votre planning périscolaire — %s', $site, $trimestre->label);

        $grand_total = 0.0;
        $has_any     = false;
        $body        = self::h2('Confirmation de votre planning')
            . self::p('Voici le récapitulatif de vos inscriptions pour : ' . $trimestre->label);

        foreach ($children as $child) {
            $child_label = strtoupper($child->prenom . ' ' . $child->nom)
                . ($child->classe ? ' <span style="color:#666;font-weight:normal;font-size:13px;">(' . esc_html($child->classe) . ')</span>' : '');

            $body .= '<div style="margin:24px 0;">';
            $body .= '<h3 style="font-size:15px;color:#23478B;margin:0 0 10px;padding:8px 12px;'
                   . 'background:#f0f4fb;border-radius:4px;">' . $child_label . '</h3>';

            $dates = array();
            foreach ($reg_map as $key => $v) {
                list($cid, $date, $service) = explode('|', $key);
                if ((int) $cid !== (int) $child->id) continue;
                $dates[$date][] = $service;
            }
            ksort($dates);

            if (empty($dates)) {
                $body .= '<p style="color:#888;font-size:14px;font-style:italic;margin:0;">Aucune inscription enregistrée.</p>';
                $body .= '</div>';
                continue;
            }

            $has_any     = true;
            $child_total = 0.0;
            $counts      = array_fill_keys(psc_allowed_services(), 0);

            // Tableau des jours
            $body .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-bottom:12px;font-size:13px;">';
            $body .= '<thead><tr>'
                   . '<th style="background:#e8edf5;color:#23478B;padding:7px 10px;text-align:left;border:1px solid #d0d8e8;">Date</th>'
                   . '<th style="background:#e8edf5;color:#23478B;padding:7px 10px;text-align:left;border:1px solid #d0d8e8;">Jour</th>'
                   . '<th style="background:#e8edf5;color:#23478B;padding:7px 10px;text-align:left;border:1px solid #d0d8e8;">Prestations</th>'
                   . '</tr></thead><tbody>';

            $alt = false;
            foreach ($dates as $date => $servs) {
                $labels = array();
                foreach (psc_allowed_services() as $code) {
                    if (!in_array($code, $servs, true)) continue;
                    $labels[] = $services[$code]['label'];
                    $counts[$code]++;
                    $child_total += (float) $services[$code]['price'];
                }
                $bg = $alt ? '#f8f9fb' : '#ffffff';
                $body .= '<tr style="background:' . $bg . ';">'
                       . '<td style="padding:6px 10px;border:1px solid #e5e9f0;white-space:nowrap;">' . date_i18n('d/m/Y', strtotime($date)) . '</td>'
                       . '<td style="padding:6px 10px;border:1px solid #e5e9f0;white-space:nowrap;">' . esc_html(psc_day_label($date)) . '</td>'
                       . '<td style="padding:6px 10px;border:1px solid #e5e9f0;">' . esc_html(implode(', ', $labels)) . '</td>'
                       . '</tr>';
                $alt = !$alt;
            }
            $body .= '</tbody></table>';

            // Sous-totaux
            $body .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;margin-bottom:8px;">';
            foreach (psc_allowed_services() as $code) {
                if ($counts[$code] === 0) continue;
                $st    = $counts[$code] * (float) $services[$code]['price'];
                $body .= '<tr>'
                       . '<td style="padding:4px 10px;color:#555;">' . esc_html($services[$code]['label']) . '</td>'
                       . '<td style="padding:4px 10px;color:#555;text-align:center;">' . $counts[$code] . ' j.</td>'
                       . '<td style="padding:4px 10px;color:#555;text-align:right;">' . number_format($services[$code]['price'], 2, ',', ' ') . ' €</td>'
                       . '<td style="padding:4px 10px;color:#333;text-align:right;font-weight:bold;">' . number_format($st, 2, ',', ' ') . ' €</td>'
                       . '</tr>';
            }
            $body .= '<tr style="border-top:2px solid #23478B;">'
                   . '<td colspan="3" style="padding:7px 10px;font-weight:bold;color:#23478B;">Montant indicatif</td>'
                   . '<td style="padding:7px 10px;font-weight:bold;color:#23478B;text-align:right;">' . number_format($child_total, 2, ',', ' ') . ' €</td>'
                   . '</tr>';
            $body .= '</table>';
            $body .= '</div>';

            $grand_total += $child_total;
        }

        if ($has_any && count($children) > 1) {
            $body .= '<div style="background:#23478B;border-radius:4px;padding:14px 20px;margin:16px 0;">'
                   . '<p style="margin:0;color:#ffffff;font-size:16px;font-weight:bold;">'
                   . 'Montant indicatif total : ' . number_format($grand_total, 2, ',', ' ') . ' €'
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
            foreach ($children as $c) {
                $names[] = $c->prenom . ' ' . $c->nom;
            }
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

    /* ------------------------------------------------------------------ */
    /* Demandes d'inscription                                               */
    /* ------------------------------------------------------------------ */

    public static function send_request_verification($email, $url) {
        $site    = self::site_name();
        $subject = sprintf('[%s] Confirmez votre demande d\'inscription périscolaire', $site);

        $body = self::h2('Confirmez votre demande d\'inscription')
            . self::p(
                'Une demande d\'inscription au service périscolaire vient d\'être déposée avec cette adresse e-mail.'
            )
            . self::p(
                'Pour la transmettre à la mairie, confirmez votre adresse en cliquant sur le bouton ci-dessous.'
            )
            . self::btn($url, 'Confirmer ma demande')
            . self::info_box(
                '<strong>⏱ Ce lien est valable 3 jours.</strong><br>'
                . 'Votre demande sera ensuite examinée par la mairie, qui vous contactera par e-mail.'
            )
            . self::p(
                'Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : aucune donnée ne sera transmise.'
            );

        return self::send($email, $subject, self::layout($body, $subject));
    }

    public static function notify_mairie_new_request($req, $children) {
        $site    = self::site_name();
        $subject = sprintf('[%s] Nouvelle demande d\'inscription périscolaire', $site);

        $contact = '<strong>E-mail :</strong> ' . esc_html($req->email) . '<br>';
        if ($req->nom)       $contact .= '<strong>Famille :</strong> ' . esc_html($req->nom) . '<br>';
        if ($req->telephone) $contact .= '<strong>Téléphone :</strong> ' . esc_html($req->telephone) . '<br>';

        $children_list = '<ul style="margin:8px 0;padding-left:20px;">';
        foreach ($children as $c) {
            $children_list .= '<li style="color:#444;font-size:14px;margin-bottom:4px;">'
                . esc_html($c['prenom'] . ' ' . $c['nom'])
                . ($c['classe'] ? ' <span style="color:#888;">(' . esc_html($c['classe']) . ')</span>' : '')
                . '</li>';
        }
        $children_list .= '</ul>';

        $body = self::h2('Nouvelle demande d\'inscription')
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
        $subject = sprintf('[%s] Suite à votre demande d\'inscription périscolaire', $site);

        $body = self::h2('Votre demande d\'inscription')
            . self::p('Votre demande d\'inscription au service périscolaire n\'a pas pu être validée en l\'état.');

        if ($note !== '') {
            $body .= '<p style="color:#444;font-size:14px;font-weight:bold;margin:16px 0 6px;">Motif communiqué :</p>'
                   . '<blockquote style="margin:0;padding:12px 16px;border-left:3px solid #f5a623;'
                   . 'background:#fff8e1;color:#555;font-size:14px;line-height:1.6;">'
                   . nl2br(esc_html($note))
                   . '</blockquote>';
        }

        $body .= self::p('Pour toute précision ou pour soumettre une nouvelle demande, contactez directement la mairie.');

        return self::send($email, $subject, self::layout($body, $subject));
    }
}
