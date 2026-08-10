<?php
if (!defined('ABSPATH')) exit;

/**
 * Mandat de prélèvement SEPA (PDF) remis au parent lors de sa demande
 * d'inscription en mode "prélèvement". Document temporaire : joint à
 * l'e-mail de confirmation puis supprimé (cf. Psc_Requests::handle_submit),
 * jamais persisté sur le serveur — il contient un IBAN en clair.
 */
class Psc_Sepa_Mandate {

    /**
     * Construit le PDF dans un fichier temporaire et renvoie son chemin
     * absolu (à supprimer par l'appelant après usage), ou null si l'ICS
     * n'est pas configuré côté mairie — un mandat sans ICS est incomplet,
     * mieux vaut ne rien joindre qu'un document invalide, et la génération
     * ne doit jamais faire échouer l'inscription elle-même.
     */
    public static function build_temp_pdf($rum, $debtor) {
        $ics = get_option('psc_billing_org_ics', '');
        if ($ics === '') return null;

        $path = trailingslashit(get_temp_dir())
            . 'psc-mandat-' . $rum . '-' . wp_generate_password(8, false, false) . '.pdf';

        try {
            self::build_pdf($rum, $ics, $debtor, $path);
        } catch (Exception $e) {
            return null;
        }

        return file_exists($path) ? $path : null;
    }

    private static function build_pdf($rum, $ics, $debtor, $path) {
        require_once PSC_PATH . 'includes/fpdf/fpdf.php';

        $org_name    = get_option('psc_billing_org_name',    get_bloginfo('name'));
        $org_address = get_option('psc_billing_org_address', '');
        $org_city    = get_option('psc_billing_org_city',    '');

        $ml = 20;
        $mr = 20;
        $pw = 210 - $ml - $mr; // 170

        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetMargins($ml, 15, $mr);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        // ---- Titre ----
        $pdf->SetFont('Helvetica', 'B', 15);
        $pdf->Cell($pw, 8, self::enc('MANDAT DE PRÉLÈVEMENT SEPA'), 0, 1, 'C');
        $pdf->Ln(2);

        // ---- Texte légal standard (formulation officielle CFONB/EPC) ----
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->MultiCell($pw, 4, self::enc(
            'En signant ce formulaire de mandat, vous autorisez (A) le créancier '
            . 'désigné ci-dessous à envoyer des instructions à votre banque pour '
            . 'débiter votre compte, et (B) votre banque à débiter votre compte '
            . 'conformément aux instructions du créancier. Vous bénéficiez du droit '
            . 'd\'être remboursé par votre banque selon les conditions décrites dans '
            . 'la convention que vous avez passée avec elle. Une demande de '
            . 'remboursement doit être présentée dans les 8 semaines suivant la date '
            . 'de débit de votre compte pour un prélèvement autorisé.'
        ), 0, 'J');
        $pdf->Ln(4);

        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($ml, $pdf->GetY(), 210 - $mr, $pdf->GetY());
        $pdf->Ln(6);

        // ---- Créancier ----
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell($pw, 5, self::enc('Créancier'), 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->Cell($pw, 5, self::enc($org_name), 0, 1, 'L');
        if ($org_address) $pdf->Cell($pw, 5, self::enc($org_address), 0, 1, 'L');
        if ($org_city)    $pdf->Cell($pw, 5, self::enc($org_city), 0, 1, 'L');
        $pdf->Cell($pw, 5, self::enc('Identifiant créancier SEPA (ICS) : ' . $ics), 0, 1, 'L');
        $pdf->Ln(2);

        // ---- Référence du mandat / type de paiement ----
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Write(5, self::enc('Référence unique du mandat (RUM) : '));
        $pdf->SetTextColor(180, 30, 30);
        $pdf->Write(5, self::enc($rum));
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(7);
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->Cell($pw, 5, self::enc('Type de paiement : Paiement récurrent'), 0, 1, 'L');
        $pdf->Ln(4);

        $pdf->Line($ml, $pdf->GetY(), 210 - $mr, $pdf->GetY());
        $pdf->Ln(6);

        // ---- Débiteur ----
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell($pw, 5, self::enc('Débiteur (titulaire du compte à débiter)'), 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->Cell($pw, 5, self::enc($debtor['titulaire']), 0, 1, 'L');
        if (!empty($debtor['adresse'])) $pdf->Cell($pw, 5, self::enc($debtor['adresse']), 0, 1, 'L');
        $ville_line = trim(($debtor['code_postal'] ?? '') . ' ' . ($debtor['ville'] ?? ''));
        if ($ville_line !== '') $pdf->Cell($pw, 5, self::enc($ville_line), 0, 1, 'L');
        $pdf->Ln(3);

        $pdf->Cell(30, 5, self::enc('IBAN :'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', 'B', 9.5);
        $pdf->Cell($pw - 30, 5, self::enc(self::format_iban($debtor['iban'])), 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->Cell(30, 5, self::enc('BIC :'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', 'B', 9.5);
        $pdf->Cell($pw - 30, 5, self::enc($debtor['bic']), 0, 1, 'L');
        $pdf->Ln(10);

        // ---- Signature ----
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->Cell($pw / 2, 5, self::enc('Fait à ................................................'), 0, 0, 'L');
        $pdf->Cell($pw / 2, 5, self::enc('le ....... / ....... / ..........'), 0, 1, 'L');
        $pdf->Ln(10);
        $pdf->Cell($pw, 5, self::enc('Signature du débiteur :'), 0, 1, 'L');
        $pdf->Ln(18);
        $pdf->Line($ml, $pdf->GetY(), $ml + 70, $pdf->GetY());
        $pdf->Ln(10);

        // ---- Note RGPD + rappel d'envoi ----
        $pdf->SetFont('Helvetica', 'I', 8);
        $pdf->MultiCell($pw, 4, self::enc(
            'Les informations contenues dans le présent mandat, qui doit être '
            . 'complété, sont destinées à n\'être utilisées par le créancier pour la '
            . 'gestion de sa relation avec son client. Elles pourront donner lieu à '
            . 'l\'exercice, par ce dernier, de ses droits d\'accès et de rectification '
            . 'auprès de l\'émetteur du mandat par voie postale.'
        ), 0, 'J');
        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->MultiCell($pw, 4, self::enc(
            'Merci d\'imprimer ce mandat, de le signer, puis de l\'adresser à votre '
            . 'banque afin d\'autoriser les prélèvements. Conservez-en une copie.'
        ), 0, 'J');

        $pdf->Output('F', $path);
    }

    /** Regroupe l'IBAN en blocs de 4 caractères pour la lisibilité. */
    private static function format_iban($iban) {
        return trim(chunk_split((string) $iban, 4, ' '));
    }

    /** Encode une chaîne UTF-8 en ISO-8859-1 pour FPDF. */
    private static function enc($str) {
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string) $str);
    }
}
