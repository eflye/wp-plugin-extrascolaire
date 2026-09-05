<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1><?php esc_html_e('Réglages', 'periscolaire-registration'); ?></h1>
<?php
psc_admin_notice_map(array(
    'saved' => array('success', __('Tarifs enregistrés.', 'periscolaire-registration')),
), $psc_msg);
?>

<div class="psc-box">
<h2><?php esc_html_e('Tarifs des prestations', 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e('Ces tarifs sont affichés aux familles dans le formulaire. Ils ne déclenchent aucun paiement en ligne (le service reste géré par la mairie).', 'periscolaire-registration'); ?></p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_save_settings'); ?>
<input type="hidden" name="action" value="psc_save_settings">
<table class="form-table">
<?php foreach ($services as $code => $s): ?>
<tr>
<th><label for="psc-price-<?php echo esc_attr($code); ?>"><?php echo esc_html($s['label']); ?> (<?php echo esc_html($code); ?>)</label></th>
<td><input id="psc-price-<?php echo esc_attr($code); ?>" type="text" inputmode="decimal" name="price_<?php echo esc_attr($code); ?>" value="<?php echo esc_attr(number_format($s['price'], 2, ',', '')); ?>"> €</td>
</tr>
<?php endforeach; ?>
</table>
</table>

<h2><?php esc_html_e('Délai de modification', 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e('Au-delà de ce délai avant le jour concerné, les familles ne peuvent plus modifier leur planning en ligne, y compris via le bouton "Annulation / signalement d\'absence" du tableau de bord. La mairie, elle, reste toujours en mesure de corriger depuis ce backoffice.', 'periscolaire-registration'); ?></p>
<table class="form-table">
<tr>
<th><label for="psc-lock"><?php esc_html_e('Préavis minimum', 'periscolaire-registration'); ?></label></th>
<td>
  <input id="psc-lock" type="number" name="lock_hours" min="0" max="720" step="1"
         value="<?php echo esc_attr(psc_lock_hours()); ?>" class="small-text"> <?php esc_html_e('heures', 'periscolaire-registration'); ?>
  <p class="description"><?php esc_html_e('48 heures par défaut. Mettre 0 pour désactiver totalement le verrouillage. Ce délai peut aussi être ajusté par année scolaire (Périscolaire > Années scolaires).', 'periscolaire-registration'); ?></p>
</td>
</tr>
</table>

<h2><?php esc_html_e('Notifications', 'periscolaire-registration'); ?></h2>
<table class="form-table">
<tr>
<th><?php esc_html_e('Copie à la mairie', 'periscolaire-registration'); ?></th>
<td>
  <label>
    <input type="checkbox" name="notify_mairie" value="1" <?php checked(psc_notify_mairie_enabled()); ?>>
    <?php esc_html_e('Recevoir une copie de chaque planning validé par une famille', 'periscolaire-registration'); ?>
  </label>
</td>
</tr>
<tr>
<th><label for="psc-mairie-mail"><?php esc_html_e('Adresse de la mairie', 'periscolaire-registration'); ?></label></th>
<td>
  <input id="psc-mairie-mail" type="email" name="mairie_email" class="regular-text"
         value="<?php echo esc_attr(get_option('psc_mairie_email', '')); ?>"
         placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
  <p class="description"><?php esc_html_e("Laisser vide pour utiliser l'adresse d'administration du site.", 'periscolaire-registration'); ?></p>
</td>
</tr>
</table>

<h2><?php esc_html_e("Demandes d'inscription", 'periscolaire-registration'); ?></h2>
<table class="form-table">
<tr>
<th><?php esc_html_e('Validation automatique', 'periscolaire-registration'); ?></th>
<td>
  <label>
    <input type="checkbox" name="auto_approve_requests" value="1" <?php checked(psc_auto_approve_requests_enabled()); ?>>
    <?php esc_html_e("Donner accès à l'espace famille dès la confirmation d'adresse e-mail, sans relecture par la mairie", 'periscolaire-registration'); ?>
  </label>
  <p class="description">
    <?php esc_html_e('Désactivé par défaut : chaque demande reste soumise à la modération de la mairie (Périscolaire', 'periscolaire-registration'); ?>
    &gt; <?php esc_html_e("Demandes) avant de créer la famille et ses enfants. Si activé, la famille est créée et le parent est connecté immédiatement à son espace dès qu'il confirme son adresse (redirection directe, sans attendre un second e-mail) — les informations saisies (dont le justificatif d'assurance) ne sont alors jamais relues avant l'ouverture de l'accès.", 'periscolaire-registration'); ?>
  </p>
</td>
</tr>
<tr>
<th><label for="psc-login-ttl"><?php esc_html_e('Lien de connexion', 'periscolaire-registration'); ?></label></th>
<td>
  <input id="psc-login-ttl" type="number" name="login_link_ttl_minutes" min="5" max="1440" step="1"
         value="<?php echo esc_attr((int) get_option('psc_login_link_ttl_minutes', 30)); ?>" class="small-text"> <?php esc_html_e('minutes', 'periscolaire-registration'); ?>
  <p class="description">
    <?php esc_html_e("Durée de validité du lien de connexion envoyé par e-mail (30 minutes par défaut). S'applique aussi au lien reçu par une famille dont la demande vient d'être validée par la mairie.", 'periscolaire-registration'); ?>
  </p>
</td>
</tr>
<tr>
<th><label for="psc-email-confirm-ttl"><?php esc_html_e('Lien de confirmation par e-mail', 'periscolaire-registration'); ?></label></th>
<td>
  <input id="psc-email-confirm-ttl" type="number" name="email_confirmation_ttl_days" min="1" max="30" step="1"
         value="<?php echo esc_attr((int) get_option('psc_email_confirmation_ttl_days', 3)); ?>" class="small-text"> <?php esc_html_e('jours', 'periscolaire-registration'); ?>
  <p class="description">
    <?php esc_html_e('Durée de validité des liens qui confirment une adresse e-mail — nouvelle demande d\'inscription ou changement d\'adresse depuis "Mon profil" (3 jours par défaut). Ces liens ne connectent pas directement à l\'espace famille, ils valident seulement une adresse.', 'periscolaire-registration'); ?>
  </p>
</td>
</tr>
</table>

<h2><?php esc_html_e('Fournisseur de repas', 'periscolaire-registration'); ?></h2>
<table class="form-table">
<tr>
<th><label for="psc-supplier-mail"><?php esc_html_e('Adresse du fournisseur', 'periscolaire-registration'); ?></label></th>
<td>
  <input id="psc-supplier-mail" type="email" name="supplier_email" class="regular-text"
         value="<?php echo esc_attr(get_option('psc_supplier_email', '')); ?>"
         placeholder="cuisine@prestataire.example">
  <p class="description"><?php esc_html_e('Destinataire de la commande hebdomadaire (Périscolaire', 'periscolaire-registration'); ?> &gt; <?php esc_html_e('Commande fournisseur).', 'periscolaire-registration'); ?></p>
</td>
</tr>
</table>

<h2><?php esc_html_e('Calendrier scolaire', 'periscolaire-registration'); ?></h2>
<table class="form-table">
<tr>
<th><label for="psc-ics-url"><?php esc_html_e('URL du calendrier officiel', 'periscolaire-registration'); ?></label></th>
<td>
  <input id="psc-ics-url" type="url" name="school_calendar_ics_url" class="regular-text"
         value="<?php echo esc_attr(get_option('psc_school_calendar_ics_url', '')); ?>"
         placeholder="<?php echo esc_attr(Psc_School_Calendar::ICS_URL); ?>">
  <p class="description">
      <?php esc_html_e("Laisser vide pour utiliser l'URL par défaut du ministère. Utilisée par le bouton « Charger le calendrier officiel » (Périscolaire", 'periscolaire-registration'); ?>
      &gt; <?php esc_html_e('Calendrier scolaire).', 'periscolaire-registration'); ?>
  </p>
</td>
</tr>
</table>
<?php
$logo_left_id  = (int) get_option('psc_billing_logo_left_id', 0);
$logo_right_id = (int) get_option('psc_billing_logo_right_id', 0);
?>
<h2><?php esc_html_e('Facturation', 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e('Ces informations apparaissent sur les factures PDF envoyées aux familles.', 'periscolaire-registration'); ?></p>
<table class="form-table">
<tr>
<th><label for="psc-org-intro"><?php esc_html_e("Ligne d'introduction", 'periscolaire-registration'); ?></label></th>
<td>
    <input id="psc-org-intro" type="text" name="org_intro" class="large-text"
        value="<?php echo esc_attr(get_option('psc_billing_org_intro', '')); ?>"
        placeholder="<?php esc_attr_e("Ex : Syndicat Intercommunal d'Intérêt Scolaire de", 'periscolaire-registration'); ?>">
    <p class="description"><?php esc_html_e('Texte en petit au-dessus du nom (optionnel).', 'periscolaire-registration'); ?></p>
</td>
</tr>
<tr>
<th><label for="psc-org-name"><?php esc_html_e("Nom de l'organisme", 'periscolaire-registration'); ?></label></th>
<td>
    <input id="psc-org-name" type="text" name="org_name" class="large-text"
        value="<?php echo esc_attr(get_option('psc_billing_org_name', '')); ?>"
        placeholder="<?php esc_attr_e('Ex : COURCELLES / MONTGEROULT', 'periscolaire-registration'); ?>">
    <p class="description"><?php esc_html_e("Affiché en gras et grand dans l'en-tête.", 'periscolaire-registration'); ?></p>
</td>
</tr>
<tr>
<th><label for="psc-org-address"><?php esc_html_e('Siège social / Adresse', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-org-address" type="text" name="org_address" class="large-text"
    value="<?php echo esc_attr(get_option('psc_billing_org_address', '')); ?>"
    placeholder="<?php esc_attr_e('Ex : Siège social : rue de la Vallée 95650 Montgeroult', 'periscolaire-registration'); ?>"></td>
</tr>
<tr>
<th><label for="psc-org-phone"><?php esc_html_e('Téléphone', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-org-phone" type="text" name="org_phone" class="regular-text"
    value="<?php echo esc_attr(get_option('psc_billing_org_phone', '')); ?>"></td>
</tr>
<tr>
<th><label for="psc-org-fax"><?php esc_html_e('Télécopie', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-org-fax" type="text" name="org_fax" class="regular-text"
    value="<?php echo esc_attr(get_option('psc_billing_org_fax', '')); ?>"></td>
</tr>
<tr>
<th><label for="psc-org-email"><?php esc_html_e('E-mail de contact', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-org-email" type="email" name="org_email" class="regular-text"
    value="<?php echo esc_attr(get_option('psc_billing_org_email', '')); ?>"></td>
</tr>
<tr>
<th><label for="psc-org-city"><?php esc_html_e('Commune', 'periscolaire-registration'); ?></label></th>
<td>
    <input id="psc-org-city" type="text" name="org_city" class="regular-text"
        value="<?php echo esc_attr(get_option('psc_billing_org_city', '')); ?>"
        placeholder="<?php esc_attr_e('Ex : Montgeroult', 'periscolaire-registration'); ?>">
    <p class="description"><?php esc_html_e('Utilisée dans "Montgeroult, le 7 août 2026" en haut de la facture.', 'periscolaire-registration'); ?></p>
</td>
</tr>
<tr>
<th><label for="psc-org-ics"><?php esc_html_e('Identifiant créancier SEPA (ICS)', 'periscolaire-registration'); ?></label></th>
<td>
    <input id="psc-org-ics" type="text" name="org_ics" class="regular-text"
        value="<?php echo esc_attr(get_option('psc_billing_org_ics', '')); ?>"
        placeholder="<?php esc_attr_e('Ex : FR15ZZZ612780', 'periscolaire-registration'); ?>">
    <p class="description"><?php esc_html_e("Affiché sur le mandat de prélèvement SEPA proposé aux familles lors de l'inscription.", 'periscolaire-registration'); ?></p>
</td>
</tr>
<tr>
<th><?php esc_html_e('Logo gauche', 'periscolaire-registration'); ?></th>
<td>
    <div id="psc-logo-left-preview" style="margin-bottom:6px;">
        <?php if ($logo_left_id) echo wp_get_attachment_image($logo_left_id, 'thumbnail', false, array('style' => 'max-height:60px;max-width:120px;')); ?>
    </div>
    <input type="hidden" name="logo_left_id" id="psc-logo-left-id" value="<?php echo esc_attr($logo_left_id ?: ''); ?>">
    <button type="button" class="button psc-media-select" data-target="psc-logo-left-id" data-preview="psc-logo-left-preview">
        <?php echo $logo_left_id ? esc_html__('Changer', 'periscolaire-registration') : esc_html__('Choisir une image', 'periscolaire-registration'); ?>
    </button>
    <?php if ($logo_left_id): ?>
    <button type="button" class="button psc-media-remove" data-target="psc-logo-left-id" data-preview="psc-logo-left-preview"><?php esc_html_e('Supprimer', 'periscolaire-registration'); ?></button>
    <?php endif; ?>
</td>
</tr>
<tr>
<th><?php esc_html_e('Logo droit', 'periscolaire-registration'); ?></th>
<td>
    <div id="psc-logo-right-preview" style="margin-bottom:6px;">
        <?php if ($logo_right_id) echo wp_get_attachment_image($logo_right_id, 'thumbnail', false, array('style' => 'max-height:60px;max-width:120px;')); ?>
    </div>
    <input type="hidden" name="logo_right_id" id="psc-logo-right-id" value="<?php echo esc_attr($logo_right_id ?: ''); ?>">
    <button type="button" class="button psc-media-select" data-target="psc-logo-right-id" data-preview="psc-logo-right-preview">
        <?php echo $logo_right_id ? esc_html__('Changer', 'periscolaire-registration') : esc_html__('Choisir une image', 'periscolaire-registration'); ?>
    </button>
    <?php if ($logo_right_id): ?>
    <button type="button" class="button psc-media-remove" data-target="psc-logo-right-id" data-preview="psc-logo-right-preview"><?php esc_html_e('Supprimer', 'periscolaire-registration'); ?></button>
    <?php endif; ?>
</td>
</tr>
<tr>
<th><label for="psc-billing-footer"><?php esc_html_e('Texte pied de page', 'periscolaire-registration'); ?></label></th>
<td>
    <input id="psc-billing-footer" type="text" name="footer" class="large-text"
           value="<?php echo esc_attr(get_option('psc_billing_footer', '')); ?>"
           placeholder="<?php esc_attr_e('Ex : Cette somme sera prélevée le 15 du mois suivant.', 'periscolaire-registration'); ?>">
    <p class="description"><?php esc_html_e('Affiché en bas de chaque facture PDF.', 'periscolaire-registration'); ?></p>
</td>
</tr>
</table>

<?php
$doc_ri_id = (int) get_option('psc_doc_reglement_interieur_id', 0);
$doc_rp_id = (int) get_option('psc_doc_reglement_prelevement_id', 0);
?>
<h2><?php esc_html_e('Documents', 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e("Mis à disposition des familles au format PDF dans leur espace connecté (onglet « Documents »), en complément du texte affiché lors de l'inscription.", 'periscolaire-registration'); ?></p>
<table class="form-table">
<tr>
<th><?php esc_html_e('Règlement intérieur', 'periscolaire-registration'); ?></th>
<td>
    <div id="psc-doc-ri-preview" style="margin-bottom:6px;">
        <?php if ($doc_ri_id): $url = wp_get_attachment_url($doc_ri_id); ?>
        <?php if ($url): ?><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html(basename($url)); ?></a><?php endif; ?>
        <?php endif; ?>
    </div>
    <input type="hidden" name="doc_reglement_interieur_id" id="psc-doc-ri-id" value="<?php echo esc_attr($doc_ri_id ?: ''); ?>">
    <button type="button" class="button psc-media-select-doc" data-target="psc-doc-ri-id" data-preview="psc-doc-ri-preview">
        <?php echo $doc_ri_id ? esc_html__('Changer', 'periscolaire-registration') : esc_html__('Choisir un fichier PDF', 'periscolaire-registration'); ?>
    </button>
    <?php if ($doc_ri_id): ?>
    <button type="button" class="button psc-media-remove" data-target="psc-doc-ri-id" data-preview="psc-doc-ri-preview"><?php esc_html_e('Supprimer', 'periscolaire-registration'); ?></button>
    <?php endif; ?>
</td>
</tr>
<tr>
<th><?php esc_html_e('Règlement prélèvement automatique', 'periscolaire-registration'); ?></th>
<td>
    <div id="psc-doc-rp-preview" style="margin-bottom:6px;">
        <?php if ($doc_rp_id): $url = wp_get_attachment_url($doc_rp_id); ?>
        <?php if ($url): ?><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html(basename($url)); ?></a><?php endif; ?>
        <?php endif; ?>
    </div>
    <input type="hidden" name="doc_reglement_prelevement_id" id="psc-doc-rp-id" value="<?php echo esc_attr($doc_rp_id ?: ''); ?>">
    <button type="button" class="button psc-media-select-doc" data-target="psc-doc-rp-id" data-preview="psc-doc-rp-preview">
        <?php echo $doc_rp_id ? esc_html__('Changer', 'periscolaire-registration') : esc_html__('Choisir un fichier PDF', 'periscolaire-registration'); ?>
    </button>
    <?php if ($doc_rp_id): ?>
    <button type="button" class="button psc-media-remove" data-target="psc-doc-rp-id" data-preview="psc-doc-rp-preview"><?php esc_html_e('Supprimer', 'periscolaire-registration'); ?></button>
    <?php endif; ?>
</td>
</tr>
</table>

<h2><?php esc_html_e("Passage d'année : progression des classes", 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e("Classe suivante proposée pour chaque classe lors d'un passage d'année (Périscolaire", 'periscolaire-registration'); ?> &gt; <?php esc_html_e("Années scolaires). Modifiable ligne par ligne au moment du passage d'année lui-même — cette table ne sert que de proposition par défaut. Utile si l'école a des classes à plusieurs niveaux.", 'periscolaire-registration'); ?></p>
<table class="form-table">
<?php foreach (Psc_School_Years::classe_options() as $code => $label): if ($code === '') continue; ?>
<tr>
<th><label for="psc-prog-<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?></label></th>
<td>
<select id="psc-prog-<?php echo esc_attr($code); ?>" name="progression_<?php echo esc_attr($code); ?>">
<?php foreach (Psc_School_Years::classe_options() as $next_code => $next_label): if ($next_code === '') continue; ?>
<option value="<?php echo esc_attr($next_code); ?>" <?php selected($psc_classe_progression[$code] ?? '', $next_code); ?>><?php echo esc_html($next_label); ?></option>
<?php endforeach; ?>
<option value="sortie" <?php selected($psc_classe_progression[$code] ?? '', 'sortie'); ?>><?php esc_html_e('Sortie (fin de cycle périscolaire)', 'periscolaire-registration'); ?></option>
</select>
</td>
</tr>
<?php endforeach; ?>
</table>

<h2><?php esc_html_e('Fenêtre de réinscription', 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e("Pendant cette période, un onglet « Réinscription » apparaît dans l'espace connecté des familles pour confirmer chaque enfant pour l'année scolaire en préparation. Laisser vide pour ne jamais afficher cet onglet.", 'periscolaire-registration'); ?></p>
<table class="form-table">
<tr>
<th><label for="psc-reins-debut"><?php esc_html_e('Ouverture', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-reins-debut" type="date" name="reinscription_debut" value="<?php echo esc_attr(get_option('psc_reinscription_debut', '')); ?>"></td>
</tr>
<tr>
<th><label for="psc-reins-fin"><?php esc_html_e('Fermeture', 'periscolaire-registration'); ?></label></th>
<td><input id="psc-reins-fin" type="date" name="reinscription_fin" value="<?php echo esc_attr(get_option('psc_reinscription_fin', '')); ?>"></td>
</tr>
</table>

<h2><?php esc_html_e('Listes intervenantes SIDSCM', 'periscolaire-registration'); ?></h2>
<p>
  <?php esc_html_e("Page publique dédiée pour les intervenants sur le terrain (garderie/cantine), protégée par un simple code d'accès — pas de compte à créer. Insérez le shortcode", 'periscolaire-registration'); ?>
  <code>[periscolaire_sidscm]</code>
  <?php esc_html_e("sur une page WordPress dédiée pour l'activer.", 'periscolaire-registration'); ?>
</p>
<table class="form-table">
<tr>
<th><label for="psc-sidscm-page"><?php esc_html_e('Page "Accès intervenants"', 'periscolaire-registration'); ?></label></th>
<td>
  <?php
  wp_dropdown_pages(array(
      'name'              => 'sidscm_page_id',
      'id'                => 'psc-sidscm-page',
      'selected'          => (int) get_option('psc_sidscm_page_id', 0),
      'show_option_none'  => __('Détection automatique (page contenant [periscolaire_sidscm])', 'periscolaire-registration'),
      'option_none_value' => 0,
  ));
  ?>
  <p class="description">
    <?php esc_html_e('Page WordPress contenant le shortcode', 'periscolaire-registration'); ?>
    <code>[periscolaire_sidscm]</code><?php esc_html_e(', vers laquelle pointe le lien "Espace intervenants" affiché sur la page famille. Laissez sur détection automatique si une seule page utilise ce shortcode.', 'periscolaire-registration'); ?>
  </p>
</td>
</tr>
<tr>
<th><label for="psc-sidscm-code"><?php esc_html_e("Code d'accès intervenantes SIDSCM", 'periscolaire-registration'); ?></label></th>
<td>
  <input id="psc-sidscm-code" type="text" name="sidscm_access_code" class="regular-text" maxlength="40"
         value="<?php echo esc_attr(get_option('psc_sidscm_access_code', '')); ?>"
         placeholder="<?php esc_attr_e('ex : SIDSCM2026', 'periscolaire-registration'); ?>" autocomplete="off">
  <p class="description">
    <?php esc_html_e("Laisser vide désactive complètement l'accès à cette page (personne ne peut la déverrouiller). La comparaison ignore la casse. Ce code n'est pas un mot de passe individuel : communiquez-le directement aux intervenants concernés.", 'periscolaire-registration'); ?>
  </p>
</td>
</tr>
</table>

<?php submit_button(__('Enregistrer', 'periscolaire-registration')); ?>
</form>
</div>

<script>
(function() {
    document.querySelectorAll('.psc-media-select').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetId  = this.dataset.target;
            var previewId = this.dataset.preview;
            var frame = wp.media({ title: '<?php echo esc_js(__('Choisir un logo', 'periscolaire-registration')); ?>', multiple: false, library: { type: 'image' } });
            frame.on('select', function() {
                var att = frame.state().get('selection').first().toJSON();
                document.getElementById(targetId).value = att.id;
                document.getElementById(previewId).innerHTML =
                    '<img src="' + att.url + '" style="max-height:60px;max-width:120px;">';
            });
            frame.open();
        });
    });
    document.querySelectorAll('.psc-media-select-doc').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetId  = this.dataset.target;
            var previewId = this.dataset.preview;
            var frame = wp.media({ title: '<?php echo esc_js(__('Choisir un document PDF', 'periscolaire-registration')); ?>', multiple: false, library: { type: 'application/pdf' } });
            frame.on('select', function() {
                var att = frame.state().get('selection').first().toJSON();
                document.getElementById(targetId).value = att.id;
                document.getElementById(previewId).innerHTML =
                    '<a href="' + att.url + '" target="_blank" rel="noopener">' + att.filename + '</a>';
            });
            frame.open();
        });
    });
    document.querySelectorAll('.psc-media-remove').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById(this.dataset.target).value = '';
            document.getElementById(this.dataset.preview).innerHTML = '';
        });
    });
})();
</script>

<div class="psc-box">
<h2><?php esc_html_e('Envoi des e-mails', 'periscolaire-registration'); ?></h2>
<p>
  <?php esc_html_e("Ce plugin utilise la fonction d'envoi standard de WordPress. Sur beaucoup d'hébergements, les messages partent en indésirables ou ne partent pas du tout sans configuration SMTP. Comme les familles reçoivent leur lien de connexion par e-mail, cette configuration est", 'periscolaire-registration'); ?>
  <strong><?php esc_html_e('indispensable au bon fonctionnement', 'periscolaire-registration'); ?></strong>
  <?php esc_html_e(" : faites installer une extension SMTP par l'administrateur du site et testez un envoi réel.", 'periscolaire-registration'); ?>
</p>
</div>
</div>
