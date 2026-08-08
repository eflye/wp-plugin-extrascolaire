<?php
if (!defined('ABSPATH')) exit;

/**
 * Gestion des modèles d'e-mails personnalisables.
 *
 * Chaque modèle possède un sujet et un corps (texte brut avec variables
 * {{placeholder}}). Le rendu HTML (layout, boutons, tableaux) est assuré
 * par Psc_Mailer ; seul le texte rédactionnel est ici personnalisable.
 */
class Psc_Email_Templates {

    const OPTION = 'psc_email_templates';

    /**
     * Définitions par défaut de tous les modèles.
     * 'vars' : liste des {{placeholders}} disponibles pour le rédacteur.
     * 'note' : ce qui est ajouté automatiquement (non modifiable).
     */
    public static function defaults() {
        return array(
            'login_link' => array(
                'label'   => 'Lien de connexion',
                'subject' => '[{{site}}] Votre lien d\'accès aux inscriptions périscolaires',
                'body'    => "Voici votre lien d'accès au formulaire d'inscription périscolaire. Cliquez sur le bouton ci-dessous pour vous connecter.",
                'vars'    => array('{{site}}', '{{minutes}}'),
                'note'    => 'Le bouton de connexion et la durée de validité sont ajoutés automatiquement.',
            ),
            'login_approved' => array(
                'label'   => 'Compte activé (après approbation)',
                'subject' => '[{{site}}] Votre accès aux inscriptions périscolaires est activé',
                'body'    => "Votre demande d'inscription a été validée par la mairie. Vous pouvez dès maintenant accéder à votre espace et planifier vos inscriptions.",
                'vars'    => array('{{site}}'),
                'note'    => 'Le bouton d\'accès est ajouté automatiquement.',
            ),
            'recap' => array(
                'label'   => 'Récapitulatif du planning',
                'subject' => '[{{site}}] Confirmation de votre planning périscolaire — {{trimestre}}',
                'body'    => "Voici le récapitulatif de vos inscriptions pour : {{trimestre}}",
                'vars'    => array('{{site}}', '{{trimestre}}'),
                'note'    => 'Le tableau des inscriptions, les sous-totaux et le lien de modification sont ajoutés automatiquement.',
            ),
            'request_verify' => array(
                'label'   => 'Vérification de demande d\'inscription',
                'subject' => '[{{site}}] Confirmez votre demande d\'inscription périscolaire',
                'body'    => "Une demande d'inscription au service périscolaire vient d'être déposée avec cette adresse e-mail.\n\nPour la transmettre à la mairie, confirmez votre adresse en cliquant sur le bouton ci-dessous.",
                'vars'    => array('{{site}}'),
                'note'    => 'Le bouton de confirmation et la durée de validité sont ajoutés automatiquement.',
            ),
            'request_rejected' => array(
                'label'   => 'Rejet de demande d\'inscription',
                'subject' => '[{{site}}] Suite à votre demande d\'inscription périscolaire',
                'body'    => "Votre demande d'inscription au service périscolaire n'a pas pu être validée en l'état.\n\nPour toute précision, contactez directement la mairie.",
                'vars'    => array('{{site}}'),
                'note'    => 'Le motif de rejet est ajouté automatiquement s\'il est renseigné.',
            ),
            'notify_mairie' => array(
                'label'   => 'Notification mairie (nouvelle demande)',
                'subject' => '[{{site}}] Nouvelle demande d\'inscription périscolaire',
                'body'    => "Une nouvelle demande d'inscription vient d'être confirmée et attend votre validation.",
                'vars'    => array('{{site}}'),
                'note'    => 'Les coordonnées de la famille, la liste des enfants et le lien vers le backoffice sont ajoutés automatiquement.',
            ),
            'weekly_menu' => array(
                'label'   => 'Menu de cantine hebdomadaire',
                'subject' => '[{{site}}] Menu de la cantine — semaine du {{semaine}}',
                'body'    => "Voici le menu de la cantine pour la semaine du {{semaine}}.",
                'vars'    => array('{{site}}', '{{semaine}}'),
                'note'    => 'Le détail des repas jour par jour est ajouté automatiquement.',
            ),
            'invoice' => array(
                'label'   => 'Envoi de facture',
                'subject' => 'Facture périscolaire — {{mois}}',
                'body'    => "Veuillez trouver en pièce jointe votre facture de services périscolaires pour le mois de {{mois}}.\n\nPour toute question, n'hésitez pas à contacter le secrétariat de {{commune}}.",
                'vars'    => array('{{mois}}', '{{nom}}', '{{commune}}', '{{total}}'),
                'note'    => 'Le montant total est affiché dans un encadré. La facture PDF est jointe au message.',
            ),
        );
    }

    /**
     * Retourne tous les modèles, avec les valeurs personnalisées si elles existent.
     */
    public static function get_all() {
        $defaults = self::defaults();
        $saved    = get_option(self::OPTION, array());
        if (!is_array($saved)) {
            $saved = array();
        }
        foreach ($defaults as $key => &$tpl) {
            if (!empty($saved[$key]['subject'])) {
                $tpl['subject'] = $saved[$key]['subject'];
            }
            if (!empty($saved[$key]['body'])) {
                $tpl['body'] = $saved[$key]['body'];
            }
            $tpl['customized'] = !empty($saved[$key]);
        }
        return $defaults;
    }

    /**
     * Retourne un seul modèle (avec override éventuel).
     */
    public static function get($key) {
        $all = self::get_all();
        return isset($all[$key]) ? $all[$key] : null;
    }

    /**
     * Retourne le sujet interpolé avec les variables fournies.
     */
    public static function subject($key, $vars = array()) {
        $tpl = self::get($key);
        return $tpl ? self::interpolate($tpl['subject'], $vars) : '';
    }

    /**
     * Retourne le corps interpolé, converti en HTML (sauts de ligne → <br>).
     */
    public static function body_html($key, $vars = array()) {
        $tpl = self::get($key);
        if (!$tpl) {
            return '';
        }
        $text = self::interpolate($tpl['body'], $vars);
        // Échappement puis conversion des sauts de ligne.
        return nl2br(esc_html($text));
    }

    /**
     * Sauvegarde tous les modèles envoyés depuis le formulaire admin.
     */
    public static function save(array $input) {
        $defaults = self::defaults();
        $clean    = array();
        foreach ($defaults as $key => $def) {
            if (!isset($input[$key])) {
                continue;
            }
            $subject = isset($input[$key]['subject'])
                ? sanitize_text_field(wp_unslash($input[$key]['subject']))
                : '';
            $body = isset($input[$key]['body'])
                ? sanitize_textarea_field(wp_unslash($input[$key]['body']))
                : '';
            // Ne stocker que si différent du défaut (évite de polluer l'option).
            if ($subject !== $def['subject'] || $body !== $def['body']) {
                $clean[$key] = compact('subject', 'body');
            }
        }
        update_option(self::OPTION, $clean);
    }

    /**
     * Remet un modèle (ou tous) aux valeurs par défaut.
     */
    public static function reset($key = null) {
        if ($key) {
            $saved = get_option(self::OPTION, array());
            unset($saved[$key]);
            update_option(self::OPTION, $saved);
        } else {
            delete_option(self::OPTION);
        }
    }

    /* ------------------------------------------------------------------ */

    private static function interpolate($text, array $vars) {
        foreach ($vars as $placeholder => $value) {
            $text = str_replace('{{' . $placeholder . '}}', (string) $value, $text);
        }
        return $text;
    }
}
