<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Réglages</h1>
<?php if (!empty($psc_msg) && $psc_msg === 'saved'): ?>
<div class="notice notice-success is-dismissible"><p>Tarifs enregistrés.</p></div>
<?php endif; ?>

<div class="psc-box">
<h2>Tarifs des prestations</h2>
<p>Ces tarifs sont affichés aux familles dans le formulaire. Ils ne déclenchent aucun paiement en ligne (le service reste géré par la mairie).</p>
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

<h2>Délai de modification</h2>
<p>Au-delà de ce délai avant le jour concerné, les familles ne peuvent plus modifier leur planning en ligne, y compris via le bouton "Annulation / signalement d'absence" du tableau de bord. La mairie, elle, reste toujours en mesure de corriger depuis ce backoffice.</p>
<table class="form-table">
<tr>
<th><label for="psc-lock">Préavis minimum</label></th>
<td>
  <input id="psc-lock" type="number" name="lock_hours" min="0" max="720" step="1"
         value="<?php echo esc_attr(psc_lock_hours()); ?>" class="small-text"> heures
  <p class="description">48 heures par défaut. Mettre 0 pour désactiver totalement le verrouillage.</p>
</td>
</tr>
</table>

<h2>Notifications</h2>
<table class="form-table">
<tr>
<th>Copie à la mairie</th>
<td>
  <label>
    <input type="checkbox" name="notify_mairie" value="1" <?php checked(psc_notify_mairie_enabled()); ?>>
    Recevoir une copie de chaque planning validé par une famille
  </label>
</td>
</tr>
<tr>
<th><label for="psc-mairie-mail">Adresse de la mairie</label></th>
<td>
  <input id="psc-mairie-mail" type="email" name="mairie_email" class="regular-text"
         value="<?php echo esc_attr(get_option('psc_mairie_email', '')); ?>"
         placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
  <p class="description">Laisser vide pour utiliser l'adresse d'administration du site.</p>
</td>
</tr>
</table>

<h2>Demandes d'inscription</h2>
<table class="form-table">
<tr>
<th>Validation automatique</th>
<td>
  <label>
    <input type="checkbox" name="auto_approve_requests" value="1" <?php checked(psc_auto_approve_requests_enabled()); ?>>
    Donner accès à l'espace famille dès la confirmation d'adresse e-mail, sans relecture par la mairie
  </label>
  <p class="description">
    Désactivé par défaut : chaque demande reste soumise à la modération de la mairie
    (Périscolaire &gt; Demandes) avant de créer la famille et ses enfants. Si activé, la
    famille est créée et le parent est connecté immédiatement à son espace dès qu'il confirme
    son adresse (redirection directe, sans attendre un second e-mail) — les informations
    saisies (dont le justificatif d'assurance) ne sont alors jamais relues avant l'ouverture
    de l'accès.
  </p>
</td>
</tr>
<tr>
<th><label for="psc-login-ttl">Lien de connexion</label></th>
<td>
  <input id="psc-login-ttl" type="number" name="login_link_ttl_minutes" min="5" max="1440" step="1"
         value="<?php echo esc_attr((int) get_option('psc_login_link_ttl_minutes', 30)); ?>" class="small-text"> minutes
  <p class="description">
    Durée de validité du lien à usage unique envoyé pour se connecter à l'espace famille
    (30 minutes par défaut). S'applique aussi au lien reçu par une famille dont la demande
    vient d'être validée par la mairie.
  </p>
</td>
</tr>
<tr>
<th><label for="psc-email-confirm-ttl">Lien de confirmation par e-mail</label></th>
<td>
  <input id="psc-email-confirm-ttl" type="number" name="email_confirmation_ttl_days" min="1" max="30" step="1"
         value="<?php echo esc_attr((int) get_option('psc_email_confirmation_ttl_days', 3)); ?>" class="small-text"> jours
  <p class="description">
    Durée de validité des liens qui confirment une adresse e-mail — nouvelle demande
    d'inscription ou changement d'adresse depuis "Mon profil" (3 jours par défaut). Ces liens
    ne connectent pas directement à l'espace famille, ils valident seulement une adresse.
  </p>
</td>
</tr>
</table>

<h2>Fournisseur de repas</h2>
<table class="form-table">
<tr>
<th><label for="psc-supplier-mail">Adresse du fournisseur</label></th>
<td>
  <input id="psc-supplier-mail" type="email" name="supplier_email" class="regular-text"
         value="<?php echo esc_attr(get_option('psc_supplier_email', '')); ?>"
         placeholder="cuisine@prestataire.example">
  <p class="description">Destinataire de la commande hebdomadaire (Périscolaire &gt; Commande fournisseur).</p>
</td>
</tr>
</table>

<h2>Calendrier scolaire</h2>
<table class="form-table">
<tr>
<th><label for="psc-ics-url">URL du calendrier officiel</label></th>
<td>
  <input id="psc-ics-url" type="url" name="school_calendar_ics_url" class="regular-text"
         value="<?php echo esc_attr(get_option('psc_school_calendar_ics_url', '')); ?>"
         placeholder="<?php echo esc_attr(Psc_School_Calendar::ICS_URL); ?>">
  <p class="description">
      Laisser vide pour utiliser l'URL par défaut du ministère. Utilisée par le bouton
      « Charger le calendrier officiel » (Périscolaire &gt; Calendrier scolaire).
  </p>
</td>
</tr>
</table>
<?php
$logo_left_id  = (int) get_option('psc_billing_logo_left_id', 0);
$logo_right_id = (int) get_option('psc_billing_logo_right_id', 0);
?>
<h2>Facturation</h2>
<p>Ces informations apparaissent sur les factures PDF envoyées aux familles.</p>
<table class="form-table">
<tr>
<th><label for="psc-org-intro">Ligne d'introduction</label></th>
<td>
    <input id="psc-org-intro" type="text" name="org_intro" class="large-text"
        value="<?php echo esc_attr(get_option('psc_billing_org_intro', '')); ?>"
        placeholder="Ex : Syndicat Intercommunal d'Intérêt Scolaire de">
    <p class="description">Texte en petit au-dessus du nom (optionnel).</p>
</td>
</tr>
<tr>
<th><label for="psc-org-name">Nom de l'organisme</label></th>
<td>
    <input id="psc-org-name" type="text" name="org_name" class="large-text"
        value="<?php echo esc_attr(get_option('psc_billing_org_name', '')); ?>"
        placeholder="Ex : COURCELLES / MONTGEROULT">
    <p class="description">Affiché en gras et grand dans l'en-tête.</p>
</td>
</tr>
<tr>
<th><label for="psc-org-address">Siège social / Adresse</label></th>
<td><input id="psc-org-address" type="text" name="org_address" class="large-text"
    value="<?php echo esc_attr(get_option('psc_billing_org_address', '')); ?>"
    placeholder="Ex : Siège social : rue de la Vallée 95650 Montgeroult"></td>
</tr>
<tr>
<th><label for="psc-org-phone">Téléphone</label></th>
<td><input id="psc-org-phone" type="text" name="org_phone" class="regular-text"
    value="<?php echo esc_attr(get_option('psc_billing_org_phone', '')); ?>"></td>
</tr>
<tr>
<th><label for="psc-org-fax">Télécopie</label></th>
<td><input id="psc-org-fax" type="text" name="org_fax" class="regular-text"
    value="<?php echo esc_attr(get_option('psc_billing_org_fax', '')); ?>"></td>
</tr>
<tr>
<th><label for="psc-org-email">E-mail de contact</label></th>
<td><input id="psc-org-email" type="email" name="org_email" class="regular-text"
    value="<?php echo esc_attr(get_option('psc_billing_org_email', '')); ?>"></td>
</tr>
<tr>
<th><label for="psc-org-city">Commune</label></th>
<td>
    <input id="psc-org-city" type="text" name="org_city" class="regular-text"
        value="<?php echo esc_attr(get_option('psc_billing_org_city', '')); ?>"
        placeholder="Ex : Montgeroult">
    <p class="description">Utilisée dans "Montgeroult, le 7 août 2026" en haut de la facture.</p>
</td>
</tr>
<tr>
<th><label for="psc-org-ics">Identifiant créancier SEPA (ICS)</label></th>
<td>
    <input id="psc-org-ics" type="text" name="org_ics" class="regular-text"
        value="<?php echo esc_attr(get_option('psc_billing_org_ics', '')); ?>"
        placeholder="Ex : FR15ZZZ612780">
    <p class="description">Affiché sur le mandat de prélèvement SEPA proposé aux familles lors de l'inscription.</p>
</td>
</tr>
<tr>
<th>Logo gauche</th>
<td>
    <div id="psc-logo-left-preview" style="margin-bottom:6px;">
        <?php if ($logo_left_id) echo wp_get_attachment_image($logo_left_id, 'thumbnail', false, array('style' => 'max-height:60px;max-width:120px;')); ?>
    </div>
    <input type="hidden" name="logo_left_id" id="psc-logo-left-id" value="<?php echo esc_attr($logo_left_id ?: ''); ?>">
    <button type="button" class="button psc-media-select" data-target="psc-logo-left-id" data-preview="psc-logo-left-preview">
        <?php echo $logo_left_id ? 'Changer' : 'Choisir une image'; ?>
    </button>
    <?php if ($logo_left_id): ?>
    <button type="button" class="button psc-media-remove" data-target="psc-logo-left-id" data-preview="psc-logo-left-preview">Supprimer</button>
    <?php endif; ?>
</td>
</tr>
<tr>
<th>Logo droit</th>
<td>
    <div id="psc-logo-right-preview" style="margin-bottom:6px;">
        <?php if ($logo_right_id) echo wp_get_attachment_image($logo_right_id, 'thumbnail', false, array('style' => 'max-height:60px;max-width:120px;')); ?>
    </div>
    <input type="hidden" name="logo_right_id" id="psc-logo-right-id" value="<?php echo esc_attr($logo_right_id ?: ''); ?>">
    <button type="button" class="button psc-media-select" data-target="psc-logo-right-id" data-preview="psc-logo-right-preview">
        <?php echo $logo_right_id ? 'Changer' : 'Choisir une image'; ?>
    </button>
    <?php if ($logo_right_id): ?>
    <button type="button" class="button psc-media-remove" data-target="psc-logo-right-id" data-preview="psc-logo-right-preview">Supprimer</button>
    <?php endif; ?>
</td>
</tr>
<tr>
<th><label for="psc-billing-footer">Texte pied de page</label></th>
<td>
    <input id="psc-billing-footer" type="text" name="footer" class="large-text"
           value="<?php echo esc_attr(get_option('psc_billing_footer', '')); ?>"
           placeholder="Ex : Cette somme sera prélevée le 15 du mois suivant.">
    <p class="description">Affiché en bas de chaque facture PDF.</p>
</td>
</tr>
</table>

<?php
$doc_ri_id = (int) get_option('psc_doc_reglement_interieur_id', 0);
$doc_rp_id = (int) get_option('psc_doc_reglement_prelevement_id', 0);
?>
<h2>Documents</h2>
<p>Mis à disposition des familles au format PDF dans leur espace connecté (onglet « Documents »), en complément du texte affiché lors de l'inscription.</p>
<table class="form-table">
<tr>
<th>Règlement intérieur</th>
<td>
    <div id="psc-doc-ri-preview" style="margin-bottom:6px;">
        <?php if ($doc_ri_id): $url = wp_get_attachment_url($doc_ri_id); ?>
        <?php if ($url): ?><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html(basename($url)); ?></a><?php endif; ?>
        <?php endif; ?>
    </div>
    <input type="hidden" name="doc_reglement_interieur_id" id="psc-doc-ri-id" value="<?php echo esc_attr($doc_ri_id ?: ''); ?>">
    <button type="button" class="button psc-media-select-doc" data-target="psc-doc-ri-id" data-preview="psc-doc-ri-preview">
        <?php echo $doc_ri_id ? 'Changer' : 'Choisir un fichier PDF'; ?>
    </button>
    <?php if ($doc_ri_id): ?>
    <button type="button" class="button psc-media-remove" data-target="psc-doc-ri-id" data-preview="psc-doc-ri-preview">Supprimer</button>
    <?php endif; ?>
</td>
</tr>
<tr>
<th>Règlement prélèvement automatique</th>
<td>
    <div id="psc-doc-rp-preview" style="margin-bottom:6px;">
        <?php if ($doc_rp_id): $url = wp_get_attachment_url($doc_rp_id); ?>
        <?php if ($url): ?><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html(basename($url)); ?></a><?php endif; ?>
        <?php endif; ?>
    </div>
    <input type="hidden" name="doc_reglement_prelevement_id" id="psc-doc-rp-id" value="<?php echo esc_attr($doc_rp_id ?: ''); ?>">
    <button type="button" class="button psc-media-select-doc" data-target="psc-doc-rp-id" data-preview="psc-doc-rp-preview">
        <?php echo $doc_rp_id ? 'Changer' : 'Choisir un fichier PDF'; ?>
    </button>
    <?php if ($doc_rp_id): ?>
    <button type="button" class="button psc-media-remove" data-target="psc-doc-rp-id" data-preview="psc-doc-rp-preview">Supprimer</button>
    <?php endif; ?>
</td>
</tr>
</table>

<h2>Passage d'année : progression des classes</h2>
<p>Classe suivante proposée pour chaque classe lors d'un passage d'année (Périscolaire &gt; Années scolaires). Modifiable ligne par ligne au moment du passage d'année lui-même — cette table ne sert que de proposition par défaut. Utile si l'école a des classes à plusieurs niveaux.</p>
<table class="form-table">
<?php foreach (Psc_School_Years::classe_options() as $code => $label): if ($code === '') continue; ?>
<tr>
<th><label for="psc-prog-<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?></label></th>
<td>
<select id="psc-prog-<?php echo esc_attr($code); ?>" name="progression_<?php echo esc_attr($code); ?>">
<?php foreach (Psc_School_Years::classe_options() as $next_code => $next_label): if ($next_code === '') continue; ?>
<option value="<?php echo esc_attr($next_code); ?>" <?php selected($psc_classe_progression[$code] ?? '', $next_code); ?>><?php echo esc_html($next_label); ?></option>
<?php endforeach; ?>
<option value="sortie" <?php selected($psc_classe_progression[$code] ?? '', 'sortie'); ?>>Sortie (fin de cycle périscolaire)</option>
</select>
</td>
</tr>
<?php endforeach; ?>
</table>

<h2>Fenêtre de réinscription</h2>
<p>Pendant cette période, un onglet « Réinscription » apparaît dans l'espace connecté des familles pour confirmer chaque enfant pour l'année scolaire en préparation. Laisser vide pour ne jamais afficher cet onglet.</p>
<table class="form-table">
<tr>
<th><label for="psc-reins-debut">Ouverture</label></th>
<td><input id="psc-reins-debut" type="date" name="reinscription_debut" value="<?php echo esc_attr(get_option('psc_reinscription_debut', '')); ?>"></td>
</tr>
<tr>
<th><label for="psc-reins-fin">Fermeture</label></th>
<td><input id="psc-reins-fin" type="date" name="reinscription_fin" value="<?php echo esc_attr(get_option('psc_reinscription_fin', '')); ?>"></td>
</tr>
</table>

<h2>Listes intervenantes SIDSCM</h2>
<p>
  Page publique dédiée pour les intervenants sur le terrain (garderie/cantine), protégée par un
  simple code d'accès — pas de compte à créer. Insérez le shortcode <code>[periscolaire_sidscm]</code>
  sur une page WordPress dédiée pour l'activer.
</p>
<table class="form-table">
<tr>
<th><label for="psc-sidscm-page">Page "Accès intervenants"</label></th>
<td>
  <?php
  wp_dropdown_pages(array(
      'name'              => 'sidscm_page_id',
      'id'                => 'psc-sidscm-page',
      'selected'          => (int) get_option('psc_sidscm_page_id', 0),
      'show_option_none'  => 'Détection automatique (page contenant [periscolaire_sidscm])',
      'option_none_value' => 0,
  ));
  ?>
  <p class="description">
    Page WordPress contenant le shortcode <code>[periscolaire_sidscm]</code>, vers laquelle pointe
    le lien "Espace intervenants" affiché sur la page famille. Laissez sur détection automatique
    si une seule page utilise ce shortcode.
  </p>
</td>
</tr>
<tr>
<th><label for="psc-sidscm-code">Code d'accès intervenantes SIDSCM</label></th>
<td>
  <input id="psc-sidscm-code" type="text" name="sidscm_access_code" class="regular-text" maxlength="40"
         value="<?php echo esc_attr(get_option('psc_sidscm_access_code', '')); ?>"
         placeholder="ex : SIDSCM2026" autocomplete="off">
  <p class="description">
    Laisser vide désactive complètement l'accès à cette page (personne ne peut la déverrouiller).
    La comparaison ignore la casse. Ce code n'est pas un mot de passe individuel : communiquez-le
    directement aux intervenants concernés.
  </p>
</td>
</tr>
</table>

<?php submit_button('Enregistrer'); ?>
</form>
</div>

<script>
(function() {
    document.querySelectorAll('.psc-media-select').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetId  = this.dataset.target;
            var previewId = this.dataset.preview;
            var frame = wp.media({ title: 'Choisir un logo', multiple: false, library: { type: 'image' } });
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
            var frame = wp.media({ title: 'Choisir un document PDF', multiple: false, library: { type: 'application/pdf' } });
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
<h2>Envoi des e-mails</h2>
<p>
  Ce plugin utilise la fonction d'envoi standard de WordPress. Sur beaucoup
  d'hébergements, les messages partent en indésirables ou ne partent pas du tout
  sans configuration SMTP. Comme les familles reçoivent leur lien de connexion
  par e-mail, cette configuration est <strong>indispensable au bon fonctionnement</strong> :
  faites installer une extension SMTP par l'administrateur du site et testez un envoi réel.
</p>
</div>
</div>
