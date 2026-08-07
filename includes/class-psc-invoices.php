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

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.jour_date, r.service, c.nom AS child_nom, c.prenom AS child_prenom
             FROM $t_reg r
             JOIN $t_child c ON c.id = r.child_id
             WHERE c.parent_id = %d
               AND DATE_FORMAT(r.jour_date, '%%Y-%%m') = %s
             ORDER BY r.jour_date, c.nom, r.service",
            $parent_id, $mois
        ));

        if (empty($rows)) {
            return new WP_Error('no_data', 'Aucune inscription ce mois-ci.');
        }

        $services = psc_services();
        $total    = 0.0;
        foreach ($rows as $r) {
            if (isset($services[$r->service])) {
                $total += $services[$r->service]['price'];
            }
        }

        $pdf_path = self::pdf_path($mois, $parent_id);
        if (!wp_mkdir_p(dirname($pdf_path))) {
            return new WP_Error('mkdir_fail', 'Impossible de créer le répertoire des factures.');
        }

        $build_ok = self::build_pdf($parent, $mois, $rows, $services, $pdf_path);
        if (is_wp_error($build_ok)) {
            return $build_ok;
        }

        $upload_dir = wp_upload_dir();
        $rel_path   = str_replace(trailingslashit($upload_dir['basedir']), '', $pdf_path);

        $t_inv    = psc_table('invoices');
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_inv WHERE parent_id = %d AND mois = %s",
            $parent_id, $mois
        ));

        if ($existing) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $t_inv SET total = %f, pdf_path = %s, created_at = %s, sent_at = NULL WHERE id = %d",
                $total, $rel_path, current_time('mysql'), $existing
            ));
            return (int) $existing;
        }

        $wpdb->insert($t_inv, array(
            'parent_id'  => $parent_id,
            'mois'       => $mois,
            'total'      => $total,
            'pdf_path'   => $rel_path,
            'sent_at'    => null,
            'created_at' => current_time('mysql'),
        ), array('%d', '%s', '%f', '%s', '%s', '%s'));

        return (int) $wpdb->insert_id;
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

    private static function build_pdf($parent, $mois, $rows, $services, $path) {
        require_once PSC_PATH . 'includes/fpdf/fpdf.php';

        $commune     = get_option('blogname', 'Commune');
        $month_label = self::month_label($mois);
        $nom         = $parent->nom ?: $parent->email;
        $invoice_num = 'F-' . str_replace('-', '', $mois) . '-' . $parent->id;
        $emit_date   = date_i18n('d/m/Y');

        // Comptage et sous-totaux par service
        $subtotals = array();
        $counts    = array();
        foreach ($rows as $r) {
            $price = isset($services[$r->service]) ? (float) $services[$r->service]['price'] : 0.0;
            $subtotals[$r->service] = ($subtotals[$r->service] ?? 0.0) + $price;
            $counts[$r->service]    = ($counts[$r->service] ?? 0) + 1;
        }
        $grand_total = array_sum($subtotals);

        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(20, 15, 20);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        // ---- En-tête ----
        $pdf->SetFillColor(35, 75, 135);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Helvetica', 'B', 15);
        $pdf->Cell(0, 11, self::enc('FACTURE PÉRISCOLAIRE'), 0, 1, 'C', true);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 7, self::enc($commune . '  —  ' . $month_label), 0, 1, 'C', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);

        // ---- Bloc identité ----
        $pdf->SetFillColor(245, 247, 250);
        $pdf->SetFont('Helvetica', '', 9);
        $col_w = 85;
        $pdf->Cell($col_w, 6, self::enc('Famille : ' . $nom),           1, 0, 'L', true);
        $pdf->Cell(0,      6, self::enc('N° facture : ' . $invoice_num), 1, 1, 'R', true);
        $pdf->Cell($col_w, 6, self::enc('Email : ' . $parent->email),    1, 0, 'L', true);
        $pdf->Cell(0,      6, self::enc('Émise le : ' . $emit_date),     1, 1, 'R', true);
        $pdf->Ln(6);

        // ---- En-tête tableau ----
        $w = array(26, 20, 58, 44, 22); // Date | Jour | Enfant | Prestation | Montant
        $pdf->SetFillColor(35, 75, 135);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->Cell($w[0], 7, self::enc('Date'),        1, 0, 'C', true);
        $pdf->Cell($w[1], 7, self::enc('Jour'),        1, 0, 'C', true);
        $pdf->Cell($w[2], 7, self::enc('Enfant'),      1, 0, 'C', true);
        $pdf->Cell($w[3], 7, self::enc('Prestation'),  1, 0, 'C', true);
        $pdf->Cell($w[4], 7, self::enc('Montant'),     1, 1, 'R', true);

        // ---- Lignes ----
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 8);
        $fill = false;
        foreach ($rows as $r) {
            $price = isset($services[$r->service]) ? (float) $services[$r->service]['price'] : 0.0;
            $label = isset($services[$r->service]) ? $services[$r->service]['label'] : $r->service;
            $bg    = $fill ? array(248, 249, 252) : array(255, 255, 255);
            $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);

            $pdf->Cell($w[0], 6, date('d/m/Y', strtotime($r->jour_date)),           1, 0, 'C', true);
            $pdf->Cell($w[1], 6, self::enc(psc_day_label($r->jour_date)),            1, 0, 'C', true);
            $pdf->Cell($w[2], 6, self::enc($r->child_prenom . ' ' . $r->child_nom), 1, 0, 'L', true);
            $pdf->Cell($w[3], 6, self::enc($label),                                  1, 0, 'L', true);
            $pdf->Cell($w[4], 6, number_format($price, 2, ',', ' ') . ' E',          1, 1, 'R', true);
            $fill = !$fill;
        }

        // ---- Sous-totaux ----
        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetFillColor(240, 243, 248);
        $right_w = $w[4];
        $left_w  = array_sum($w) - $right_w;
        foreach ($subtotals as $code => $st) {
            $label = isset($services[$code]) ? $services[$code]['label'] : $code;
            $cnt   = $counts[$code] ?? 0;
            $pdf->Cell($left_w, 6, self::enc($label . ' × ' . $cnt . ' jour' . ($cnt > 1 ? 's' : '')), 0, 0, 'R');
            $pdf->Cell($right_w, 6, number_format($st, 2, ',', ' ') . ' E', 1, 1, 'R', true);
        }

        // ---- Total ----
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetFillColor(35, 75, 135);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell($left_w, 8, self::enc('TOTAL À RÉGLER'), 0, 0, 'R');
        $pdf->Cell($right_w, 8, number_format($grand_total, 2, ',', ' ') . ' E', 1, 1, 'R', true);

        // ---- Pied de page ----
        $pdf->SetTextColor(120, 120, 120);
        $pdf->SetFont('Helvetica', 'I', 8);
        $pdf->Ln(8);
        $pdf->MultiCell(0, 5, self::enc(
            'Merci de régler cette facture auprès du secrétariat de ' . $commune . '. '
            . 'Pour toute question, contactez-nous par e-mail ou en mairie.'
        ), 0, 'C');

        $pdf->Output('F', $path);
        return true;
    }
}
