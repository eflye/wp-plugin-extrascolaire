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
                'label'   => __('Lien de connexion', 'periscolaire-registration'),
                'subject' => __('[{{site}}] Votre lien d\'accès aux inscriptions périscolaires', 'periscolaire-registration'),
                'body'    => __("Voici votre lien d'accès au formulaire d'inscription périscolaire. Cliquez sur le bouton ci-dessous pour vous connecter.", 'periscolaire-registration'),
                'vars'    => array('{{site}}', '{{minutes}}'),
                'note'    => __('Le bouton de connexion et la durée de validité sont ajoutés automatiquement.', 'periscolaire-registration'),
            ),
            'login_approved' => array(
                'label'   => __('Compte activé (après approbation)', 'periscolaire-registration'),
                'subject' => __('[{{site}}] Votre accès aux inscriptions périscolaires est activé', 'periscolaire-registration'),
                'body'    => __("Votre demande d'inscription a été validée par la mairie. Vous pouvez dès maintenant accéder à votre espace et planifier vos inscriptions.", 'periscolaire-registration'),
                'vars'    => array('{{site}}'),
                'note'    => __('Le bouton d\'accès est ajouté automatiquement.', 'periscolaire-registration'),
            ),
            'recap' => array(
                'label'   => __('Récapitulatif du planning', 'periscolaire-registration'),
                'subject' => __('[{{site}}] Votre planning périscolaire — {{annee}}', 'periscolaire-registration'),
                'body'    => __("Voici le récapitulatif de vos déclarations pour l'année scolaire : {{annee}}.", 'periscolaire-registration'),
                'vars'    => array('{{site}}', '{{annee}}'),
                'note'    => __('Le rythme habituel de chaque enfant, les écarts à venir, l\'estimation annuelle et le lien de modification sont ajoutés automatiquement.', 'periscolaire-registration'),
            ),
            'food_allergy' => array(
                'label'   => __('Alerte mairie — allergies alimentaires (PAI)', 'periscolaire-registration'),
                'subject' => __('[{{site}}] Allergie alimentaire déclarée — {{child}}', 'periscolaire-registration'),
                'body'    => __("Une allergie alimentaire a été déclarée pour {{child}}. Conformément à l'engagement fait à la famille, le service périscolaire la contactera si un PAI (projet d'accueil individualisé) doit être mis en place.", 'periscolaire-registration'),
                'vars'    => array('{{site}}', '{{child}}'),
                'note'    => __('La description saisie par la famille et les coordonnées de contact sont ajoutées automatiquement.', 'periscolaire-registration'),
            ),
            'request_verify' => array(
                'label'   => __('Vérification de demande d\'inscription', 'periscolaire-registration'),
                'subject' => __('[{{site}}] Confirmez votre demande d\'inscription périscolaire', 'periscolaire-registration'),
                'body'    => __("Une demande d'inscription au service périscolaire vient d'être déposée avec cette adresse e-mail.\n\nPour la transmettre à la mairie, confirmez votre adresse en cliquant sur le bouton ci-dessous.", 'periscolaire-registration'),
                'vars'    => array('{{site}}'),
                'note'    => __('Le bouton de confirmation et la durée de validité sont ajoutés automatiquement.', 'periscolaire-registration'),
            ),
            'request_rejected' => array(
                'label'   => __('Rejet de demande d\'inscription', 'periscolaire-registration'),
                'subject' => __('[{{site}}] Suite à votre demande d\'inscription périscolaire', 'periscolaire-registration'),
                'body'    => __("Votre demande d'inscription au service périscolaire n'a pas pu être validée en l'état.\n\nPour toute précision, contactez directement la mairie.", 'periscolaire-registration'),
                'vars'    => array('{{site}}'),
                'note'    => __('Le motif de rejet est ajouté automatiquement s\'il est renseigné.', 'periscolaire-registration'),
            ),
            'notify_mairie' => array(
                'label'   => __('Notification mairie (nouvelle demande)', 'periscolaire-registration'),
                'subject' => __('[{{site}}] Nouvelle demande d\'inscription périscolaire', 'periscolaire-registration'),
                'body'    => __("Une nouvelle demande d'inscription vient d'être confirmée et attend votre validation.", 'periscolaire-registration'),
                'vars'    => array('{{site}}'),
                'note'    => __('Les coordonnées de la famille, la liste des enfants et le lien vers le backoffice sont ajoutés automatiquement.', 'periscolaire-registration'),
            ),
            'weekly_menu' => array(
                'label'   => __('Menu de cantine hebdomadaire', 'periscolaire-registration'),
                'subject' => __('[{{site}}] Menu de la cantine — semaine du {{semaine}}', 'periscolaire-registration'),
                'body'    => __("Voici le menu de la cantine pour la semaine du {{semaine}}.", 'periscolaire-registration'),
                'vars'    => array('{{site}}', '{{semaine}}'),
                'note'    => __('Le détail des repas jour par jour est ajouté automatiquement.', 'periscolaire-registration'),
            ),
            'supplier_order' => array(
                'label'   => __('Commande fournisseur (cantine)', 'periscolaire-registration'),
                'subject' => __('[{{site}}] Commande cantine — semaine du {{semaine}} ({{total}} repas)', 'periscolaire-registration'),
                'body'    => __("Merci de bien vouloir prévoir {{total}} repas pour la semaine du {{semaine}}, selon le détail par classe ci-dessous.", 'periscolaire-registration'),
                'vars'    => array('{{site}}', '{{semaine}}', '{{total}}'),
                'note'    => __('Le tableau du nombre de repas par classe et par jour est ajouté automatiquement.', 'periscolaire-registration'),
            ),
            'invoice' => array(
                'label'   => __('Envoi de facture', 'periscolaire-registration'),
                'subject' => __('Facture périscolaire — {{mois}}', 'periscolaire-registration'),
                'body'    => __("Veuillez trouver en pièce jointe votre facture de services périscolaires pour le mois de {{mois}}.\n\nPour toute question, n'hésitez pas à contacter le secrétariat de {{commune}}.", 'periscolaire-registration'),
                'vars'    => array('{{mois}}', '{{nom}}', '{{commune}}', '{{total}}'),
                'note'    => __('Le montant total est affiché dans un encadré. La facture PDF est jointe au message.', 'periscolaire-registration'),
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
