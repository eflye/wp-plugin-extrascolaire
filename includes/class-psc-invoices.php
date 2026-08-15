<?php
if (!defined('ABSPATH')) exit;

class Psc_Invoices {

    /**
     * Retourne les mois (YYYY-MM) pour lesquels des inscriptions existent.
     */
    public static function months_with_data() {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT DISTINCT DATE_FORMAT(jour_date, '%Y-%m') AS mois
             FROM " . psc_table('registrations') . "
             ORDER BY mois DESC"
        );
    }

    /**
     * Génère les factures PDF pour toutes les familles actives ayant des
     * inscriptions sur le mois donné. Retourne le nombre de factures créées.
     */
    public static function generate_month($mois) {
        global $wpdb;

        if (!preg_match('/^\d{4}-\d{2}$/', $mois)) {
            return new WP_Error('invalid_month', 'Format de mois invalide.');
        }

        // Un mois en cours peut encore recevoir des inscriptions/annulations
        // jusqu'à son dernier jour : générer la facture avant qu'il soit
        // terminé risquerait de la rendre incomplète ou incorrecte.
        if ($mois >= current_time('Y-m')) {
            return new WP_Error('month_not_finished', 'Ce mois n\'est pas encore terminé : les factures ne peuvent pas encore être générées.');
        }

        $t_reg   = psc_table('registrations');
        $t_child = psc_table('children');
        $t_par   = psc_table('parents');

        $parent_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.id
             FROM $t_reg r
             JOIN $t_child c ON c.id = r.child_id
             JOIN $t_par   p ON p.id = c.parent_id
             WHERE DATE_FORMAT(r.jour_date, '%%Y-%%m') = %s
               AND p.active = 1",
            $mois
        ));

        $count = 0;
        foreach ($parent_ids as $parent_id) {
            $result = self::generate_one((int) $parent_id, $mois);
            if (!is_wp_error($result)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Génère (ou regénère) la facture PDF d'une famille pour un mois donné.
     * Regénérer réinitialise la date d'envoi.
     * Retourne l'ID de la facture en base, ou WP_Error.
     */
    public static function generate_one($parent_id, $mois) {
        global $wpdb;

        $parent = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('parents') . ' WHERE id = %d',
            $parent_id
        ));
        if (!$parent) {
            return new WP_Error('no_parent', 'Famille introuvable.');
        }

        $t_reg   = psc_table('registrations');
        $t_child = psc_table('children');

        // All children of this parent (shown in PDF even with 0 registrations)
        $children = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t_child WHERE parent_id = %d ORDER BY nom, prenom",
            $parent_id
        ));
        if (empty($children)) {
            return new WP_Error('no_children', 'Aucun enfant pour cette famille.');
        }

        // Registrations for this month: service + child_id
        $regs = $wpdb->get_results($wpdb->prepare(
            "SELECT r.service, c.id AS child_id
             FROM $t_reg r
             JOIN $t_child c ON c.id = r.child_id
             WHERE c.parent_id = %d
               AND DATE_FORMAT(r.jour_date, '%%Y-%%m') = %s",
            $parent_id, $mois
        ));
        if (empty($regs)) {
            return new WP_Error('no_data', 'Aucune inscription ce mois-ci.');
        }

        // Build grid[service_code][child_id] = count
        $grid = array();
        foreach ($regs as $r) {
            if (!isset($grid[$r->service])) {
                $grid[$r->service] = array();
            }
            $grid[$r->service][$r->child_id] = ($grid[$r->service][$r->child_id] ?? 0) + 1;
        }

        $services = psc_services();

        // Compute total from grid
        $total = 0.0;
        foreach ($grid as $code => $child_counts) {
            if (!isset($services[$code])) continue;
            $price = (float) $services[$code]['price'];
            foreach ($child_counts as $cnt) {
                $total += $price * $cnt;
            }
        }

        // Upsert DB record first to get invoice_id (needed for the invoice number on the PDF)
        $t_inv    = psc_table('invoices');
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_inv WHERE parent_id = %d AND mois = %s",
            $parent_id, $mois
        ));

        if ($existing) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $t_inv SET total = %f, created_at = %s, sent_at = NULL WHERE id = %d",
                $total, current_time('mysql'), $existing
            ));
            $invoice_id = (int) $existing;
        } else {
            $wpdb->insert($t_inv, array(
                'parent_id'  => $parent_id,
                'mois'       => $mois,
                'total'      => $total,
                'pdf_path'   => null,
                'sent_at'    => null,
                'created_at' => current_time('mysql'),
            ), array('%d', '%s', '%f', '%s', '%s', '%s'));
            $invoice_id = (int) $wpdb->insert_id;
        }

        // Generate PDF
        $pdf_path = self::pdf_path($mois, $parent_id);
        if (!wp_mkdir_p(dirname($pdf_path))) {
            return new WP_Error('mkdir_fail', 'Impossible de créer le répertoire des factures.');
        }

        $build_ok = self::build_pdf($parent, $mois, $children, $grid, $services, $pdf_path, $invoice_id);
        if (is_wp_error($build_ok)) {
            return $build_ok;
        }

        // Store relative path
        $upload_dir = wp_upload_dir();
        $rel_path   = str_replace(trailingslashit($upload_dir['basedir']), '', $pdf_path);
        $wpdb->update($t_inv, array('pdf_path' => $rel_path), array('id' => $invoice_id), array('%s'), array('%d'));

        return $invoice_id;
    }

    /**
     * Retourne la liste des factures pour un mois (avec nom et email famille).
     */
    public static function get_for_month($mois) {
        global $wpdb;
        $t_inv = psc_table('invoices');
        $t_par = psc_table('parents');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, p.nom AS parent_nom, p.email AS parent_email
             FROM $t_inv i
             JOIN $t_par p ON p.id = i.parent_id
             WHERE i.mois = %s
             ORDER BY p.nom, p.email",
            $mois
        ));
    }

    /**
     * Retourne toutes les factures d'une famille, triées du mois le plus
     * récent au plus ancien. Utilisé par l'espace famille (accès à ses
     * propres factures uniquement — le contrôle d'appartenance est fait
     * par l'appelant).
     */
    public static function get_for_parent($parent_id) {
        global $wpdb;
        $t_inv = psc_table('invoices');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t_inv WHERE parent_id = %d ORDER BY mois DESC",
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
            "SELECT i.*, p.nom AS parent_nom, p.email AS parent_email
             FROM $t_inv i
             JOIN $t_par p ON p.id = i.parent_id
             WHERE i.id = %d",
            $invoice_id
        ));
    }

    /**
     * Envoie la facture par e-mail avec le PDF en pièce jointe.
     */
    public static function send($invoice_id) {
        $invoice = self::get($invoice_id);
        if (!$invoice) {
            return new WP_Error('not_found', 'Facture introuvable.');
        }

        $upload_dir = wp_upload_dir();
        $pdf_path   = trailingslashit($upload_dir['basedir']) . $invoice->pdf_path;

        if (!file_exists($pdf_path)) {
            return new WP_Error('no_file', 'Le fichier PDF est introuvable. Regénérez la facture.');
        }

        $nom         = $invoice->parent_nom ?: $invoice->parent_email;
        $month_label = self::month_label($invoice->mois);
        $commune     = get_option('blogname', 'la mairie');
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
            '<h2 style="color:#23478B;font-size:17px;margin:0 0 16px;padding-bottom:8px;border-bottom:2px solid #e8edf5;">'
            . 'Votre facture périscolaire — ' . esc_html($month_label) . '</h2>'
            . '<p style="color:#444;font-size:14px;line-height:1.7;margin:0 0 20px;">' . $body_text . '</p>'
            . '<div style="background:#f0f4fb;border-left:4px solid #23478B;border-radius:0 4px 4px 0;padding:14px 18px;margin:20px 0;font-size:14px;color:#444;line-height:1.6;">'
            . '<strong>Montant total :</strong> ' . number_format((float) $invoice->total, 2, ',', ' ') . ' €'
            . '</div>';

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

        return $ok ? true : new WP_Error('mail_failed', 'L\'envoi du mail a échoué.');
    }

    /**
     * Streame le PDF pour téléchargement admin.
     */
    public static function download($invoice_id) {
        $invoice = self::get($invoice_id);
        if (!$invoice) {
            wp_die(esc_html__('Facture introuvable.', 'periscolaire-registration'));
        }

        $upload_dir = wp_upload_dir();
        $pdf_path   = trailingslashit($upload_dir['basedir']) . $invoice->pdf_path;

        if (!file_exists($pdf_path)) {
            wp_die(esc_html__('Fichier PDF introuvable. Regénérez la facture.', 'periscolaire-registration'));
        }

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
        static $mois_fr = array(
            '01' => 'Janvier',   '02' => 'Février',  '03' => 'Mars',
            '04' => 'Avril',     '05' => 'Mai',       '06' => 'Juin',
            '07' => 'Juillet',   '08' => 'Août',      '09' => 'Septembre',
            '10' => 'Octobre',   '11' => 'Novembre',  '12' => 'Décembre',
        );
        list($year, $month) = explode('-', $mois . '-01');
        return ($mois_fr[$month] ?? $month) . ' ' . $year;
    }

    /* ------------------------------------------------------------------ */

    private static function pdf_path($mois, $parent_id) {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir'])
            . 'periscolaire/factures/' . $mois
            . '/facture-' . (int) $parent_id . '.pdf';
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

    /** Retourne une date au format "7 juillet 2026" (UTF-8, pour passer ensuite dans enc()). */
    private static function french_full_date($ts) {
        static $mois = array(
            1=>'janvier', 2=>'février',   3=>'mars',
            4=>'avril',   5=>'mai',        6=>'juin',
            7=>'juillet', 8=>'août',       9=>'septembre',
            10=>'octobre',11=>'novembre', 12=>'décembre',
        );
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
     * @param array    $services   psc_services() output
     * @param string   $path       Absolute filesystem path for the PDF
     * @param int      $invoice_id Used to build the invoice number YY-MM-NNN
     * @return true|WP_Error
     */
    private static function build_pdf($parent, $mois, $children, $grid, $services, $path, $invoice_id) {
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
        $month_label  = self::month_label($mois);
        $nom_famille  = trim(($parent->nom ?? '') ?: $parent->email);
        $date_fr      = self::french_full_date((int) current_time('timestamp'));
        $city_date    = $org_city ? self::enc($org_city . ', le ' . $date_fr) : self::enc($date_fr);

        // Margins: left=20, right=20 → usable = 170mm
        $ml = 20;
        $mr = 20;
        $pw = 210 - $ml - $mr; // 170

        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetMargins($ml, 15, $mr);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        // ---- HEADER: logos (optional) + texte centré ----
        $valid_exts   = array('JPG', 'JPEG', 'PNG', 'GIF');
        $logo_w       = 35;
        $logo_l_path  = $logo_left_id  ? get_attached_file($logo_left_id)  : '';
        $logo_r_path  = $logo_right_id ? get_attached_file($logo_right_id) : '';
        $has_logo_l   = $logo_l_path && file_exists($logo_l_path)
                        && in_array(strtoupper(pathinfo($logo_l_path, PATHINFO_EXTENSION)), $valid_exts, true);
        $has_logo_r   = $logo_r_path && file_exists($logo_r_path)
                        && in_array(strtoupper(pathinfo($logo_r_path, PATHINFO_EXTENSION)), $valid_exts, true);

        $txt_x = $ml + ($has_logo_l ? $logo_w : 0);
        $txt_w = $pw  - ($has_logo_l ? $logo_w : 0) - ($has_logo_r ? $logo_w : 0);
        $y_hdr = $pdf->GetY();

        if ($has_logo_l) $pdf->Image($logo_l_path, $ml, $y_hdr, $logo_w, 0);
        if ($has_logo_r) $pdf->Image($logo_r_path, $ml + $pw - $logo_w, $y_hdr, $logo_w, 0);

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
            if ($org_phone) $tel_line = 'Tél : ' . $org_phone;
            if ($org_fax)   $tel_line .= ($tel_line ? ' – Télécopie : ' : 'Télécopie : ') . $org_fax;
            $pdf->SetX($txt_x);
            $pdf->MultiCell($txt_w, 4, self::enc($tel_line), 0, 'C');
        }
        if ($org_email) {
            $pdf->SetX($txt_x);
            $pdf->MultiCell($txt_w, 4, self::enc($org_email), 0, 'C');
        }

        $pdf->SetY(max($pdf->GetY(), $y_hdr + ($has_logo_l || $has_logo_r ? $logo_w * 0.6 : 0)) + 4);

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
        $pdf->Write(5, self::enc('FACTURE N° '));
        $pdf->SetTextColor(180, 30, 30);
        $pdf->Write(5, self::enc($invoice_num));
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(9);

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell($pw, 5, self::enc('Prestation du mois de :'), 0, 1, 'L');
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
        self::tbl_header_cell($pdf, $x0 + $cw[0],                   $y0, $cw[1], $hdr, self::enc('Tarif par'),   self::enc('prestation'));
        self::tbl_header_cell($pdf, $x0 + $cw[0] + $cw[1],          $y0, $cw[2], $hdr, self::enc('Nombre de'),  self::enc('prestations'));
        self::tbl_header_cell($pdf, $x0 + $cw[0] + $cw[1] + $cw[2], $y0, $cw[3], $hdr, self::enc('Total'));

        $pdf->SetXY($x0, $y0 + $hdr);

        $grand_total = 0.0;
        $row_h       = 6;

        foreach ($services as $code => $svc) {
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
        $pdf->Cell($cw[2],           $row_h, 'TOTAL',                         1, 0, 'R', true);
        $pdf->Cell($cw[3],           $row_h, self::price_cell($grand_total),  1, 1, 'R', true);

        // ---- PIED DE PAGE ----
        if ($footer_text) {
            $pdf->Ln(10);
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Write(5, self::enc($footer_text));
        }

        $pdf->Output('F', $path);
        return true;
    }
}
