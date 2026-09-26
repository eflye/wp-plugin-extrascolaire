<?php
/**
 * Textes des règlements approuvés par les familles (P2-14) — une seule
 * source, affichée par l'inscription, la réinscription et le profil. Ce
 * qui est rendu ici est exactement ce que la version enregistrée à chaque
 * acceptation conserve (Psc_Document_Versions) : toute modification de ces
 * textes crée d'elle-même une nouvelle version.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/** Types de règlement suivis, avec l'option du PDF complémentaire. */
function psc_reglement_types() {
    return array(
        'reglement_interieur'   => array('label' => __('Règlement intérieur', 'periscolaire-registration'), 'option' => 'psc_doc_reglement_interieur_id'),
        'reglement_prelevement' => array('label' => __('Règlement concernant le prélèvement automatique', 'periscolaire-registration'), 'option' => 'psc_doc_reglement_prelevement_id'),
    );
}

/** Texte du règlement intérieur (HTML échappé, contenu de l'encadré). */
function psc_reglement_interieur_html() {
    ob_start();
    ?>
<h4><?php esc_html_e('1 – Préambule', 'periscolaire-registration'); ?></h4>
<p><?php esc_html_e("La demi-pension, la garderie et l'étude sont des services communaux réservés aux enfants scolarisés à l'école maternelle et élémentaire. Ces services fonctionnent pendant l'année scolaire.", 'periscolaire-registration'); ?></p>

<h4><?php esc_html_e('2 – Fonctionnement', 'periscolaire-registration'); ?></h4>
<p><strong><?php esc_html_e('Inscription', 'periscolaire-registration'); ?></strong> <?php esc_html_e("— L'inscription de chaque enfant est obligatoire pour être accueilli aux différents temps périscolaires. Toute modification d'inscription doit être signalée au responsable du périscolaire.", 'periscolaire-registration'); ?></p>
<p><strong><?php esc_html_e('Engagement', 'periscolaire-registration'); ?></strong> <?php esc_html_e('— Les inscriptions sont fermes et définitives pour l\'année scolaire, que ce soit pour la demi-pension ou la garderie. Cet engagement permet de bénéficier d\'un tarif annuel avantageux.', 'periscolaire-registration'); ?></p>
<p><strong><?php esc_html_e('Facturation', 'periscolaire-registration'); ?></strong> <?php esc_html_e('— Le paiement des prestations s\'effectue par prélèvement, par chèque ou en espèces à terme échu. Il n\'y aura pas de remboursement pour absence de l\'enfant, sauf fermeture de l\'établissement, sortie scolaire, hospitalisation ou maladie de plus de 3 jours médicalement justifiée (justificatif à fournir dans les deux premiers jours d\'absence ; une franchise de deux jours sera appliquée dans ces deux derniers cas).', 'periscolaire-registration'); ?></p>
<p><strong><?php esc_html_e('Tarifs', 'periscolaire-registration'); ?></strong> <?php esc_html_e('— Les tarifs sont déterminés par délibération pour l\'année scolaire et sont identiques pour les enfants de maternelle et d\'élémentaire. Aucun calcul de quotient familial n\'est appliqué.', 'periscolaire-registration'); ?></p>
<p><strong><?php esc_html_e('Repas', 'periscolaire-registration'); ?></strong> <?php esc_html_e('— Les repas sont fournis par un prestataire de service. En cas de prescription médicale d\'un régime particulier, un certificat médical doit être fourni.', 'periscolaire-registration'); ?></p>
<p><strong><?php esc_html_e('Encadrement', 'periscolaire-registration'); ?></strong> <?php esc_html_e('— Pendant les temps périscolaires, les enfants sont placés sous la surveillance exclusive du personnel. Aucune autre personne n\'est admise lors des services.', 'periscolaire-registration'); ?></p>
<p><strong><?php esc_html_e('Traitement médical', 'periscolaire-registration'); ?></strong> <?php esc_html_e('— En cas de nécessité absolue dûment constatée par une ordonnance médicale, le personnel donnera à l\'enfant les remèdes prescrits, selon les indications écrites des parents.', 'periscolaire-registration'); ?></p>

<h4><?php esc_html_e('3 – Discipline', 'periscolaire-registration'); ?></h4>
<p><?php esc_html_e('Les enfants suivent les mêmes règles qu\'à l\'école (maladie, situations d\'urgence, sorties). En cas de problème disciplinaire grave ou répété, toute sanction nécessaire au bon fonctionnement pourra être prise.', 'periscolaire-registration'); ?></p>

<h4><?php esc_html_e('4 – Responsabilité', 'periscolaire-registration'); ?></h4>
<p><?php esc_html_e('Dès que vous êtes présents dans les services du périscolaire, votre ou vos enfants sont sous votre responsabilité. Pour des raisons de sécurité, il est préférable de ne pas s\'attarder dans les locaux pendant les heures de surveillance.', 'periscolaire-registration'); ?></p>
    <?php
    return trim(ob_get_clean());
}

/** Texte du règlement concernant le prélèvement automatique (HTML échappé). */
function psc_reglement_prelevement_html() {
    ob_start();
    ?>
<p><?php esc_html_e('Vous avez opté pour le mode de paiement par prélèvement, ce service est gratuit. Les montants dus au titre de la cantine, garderie seront prélevés automatiquement sur le compte que vous avez désigné dans les conditions suivantes :', 'periscolaire-registration'); ?></p>
<p><?php esc_html_e('Le montant de la facture mensuelle sera prélevé à terme échu le 5 du mois suivant ou à défaut, le premier jour ouvrable suivant le 5.', 'periscolaire-registration'); ?></p>
<p><?php esc_html_e('En cas de rejet du prélèvement, les frais bancaires correspondants seront à votre charge et seront imputés sur la facture suivante.', 'periscolaire-registration'); ?></p>
<p><?php esc_html_e("Les rejets feront l'objet de rappels émis par la mairie et devront être réglés par chèque ou espèces dans les meilleurs délais. Sans régularisation de votre part dans les 15 jours suivant l'émission du rappel, le dossier sera automatiquement transmis au Trésor Public pour un recouvrement contentieux.", 'periscolaire-registration'); ?></p>
<p><?php esc_html_e('En cas de changement de domiciliation bancaire, il sera nécessaire de remplir un nouveau mandat de prélèvement SEPA accompagné d\'un nouveau RIB.', 'periscolaire-registration'); ?></p>
<p><?php esc_html_e('Vous pouvez à tout moment décider de mettre fin au prélèvement automatique, sur simple demande écrite à la mairie. Le montant des factures échues sera alors à payer par chèque ou espèces dès leur réception.', 'periscolaire-registration'); ?></p>
    <?php
    return trim(ob_get_clean());
}

/** Texte d'un type de règlement. */
function psc_reglement_html($type) {
    return $type === 'reglement_prelevement' ? psc_reglement_prelevement_html() : psc_reglement_interieur_html();
}

/**
 * Empreinte d'une version de règlement (fonction pure) : le texte affiché
 * et l'empreinte du PDF complémentaire (absent : chaîne vide).
 */
function psc_document_version_hash($texte, $pdf_sha256 = null) {
    return hash('sha256', (string) $texte . '|' . (string) $pdf_sha256);
}

/**
 * Lien vers le PDF complémentaire d'un règlement, s'il est en ligne : il
 * fait partie de la version approuvée, il doit donc être à portée de main
 * là où la famille coche la case.
 */
function psc_reglement_pdf_link_html($type) {
    $types = psc_reglement_types();
    if (!isset($types[$type])) return '';
    $id = (int) get_option($types[$type]['option'], 0);
    $url = $id ? wp_get_attachment_url($id) : '';
    if (!$url) return '';
    return '<p class="psc-reglement-pdf"><a href="' . esc_url($url) . '" target="_blank" rel="noopener" data-testid="' . esc_attr($type) . '-pdf">'
        . esc_html(sprintf(__('%s (PDF, nouvel onglet)', 'periscolaire-registration'), $types[$type]['label'])) . '</a></p>';
}
