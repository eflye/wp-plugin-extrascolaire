=== Périscolaire - Inscriptions ===
Contributors: mairie
Tags: périscolaire, mairie, inscription, cantine, garderie
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 4.18.0
License: GPLv2 or later

== Description ==

Remplace le fichier calendrier rempli à la main pour l'inscription aux services
périscolaires (Garderie Matin, Cantine, Garderie Soir, Forfait journée).

Une famille non connue de la mairie dépose une demande d'inscription en ligne
(règlement intérieur, justificatif d'assurance scolaire par enfant et, si
elle règle par prélèvement, un mandat SEPA). Une fois la demande validée par
la mairie, la famille se connecte sans mot de passe (lien reçu par e-mail) à
son espace personnel : elle y coche jour par jour les prestations souhaitées
pour chacun de ses enfants — chaque case cochée est enregistrée immédiatement,
pas de bouton "Envoyer" à chercher, pas de fichier à renvoyer par e-mail —
et y suit aussi ses factures, ses justificatifs d'assurance et son profil.

La mairie centralise tout dans un backoffice dédié (menu "Périscolaire" dans
l'administration WordPress) : tableau de bord avec liste de tâches, calendrier
scolaire (import automatique zone C), trimestres, modération des demandes,
familles, enfants (avec bascule de classe automatique à la rentrée), menus de
cantine, commande fournisseur hebdomadaire, facturation mensuelle en PDF, et
export CSV pour la comptabilité.

Aucun compte WordPress n'est nécessaire côté famille. Aucun paiement en ligne
n'est intégré : le mode de paiement (chèque/espèces ou prélèvement SEPA) est
déclaré à l'inscription, les prélèvements réels restent traités par la mairie
via sa banque.

Documentation complète des fonctionnalités : voir README.md à la racine du
dépôt du plugin.

== Installation ==

1. Copier le dossier `periscolaire-registration` dans `wp-content/plugins/`.
2. Activer le plugin depuis "Extensions" dans l'administration WordPress
   (les tables nécessaires sont créées automatiquement).
3. Aller dans "Périscolaire > Calendrier scolaire" et cliquer sur "Charger
   le calendrier officiel" (vacances scolaires zone C).
4. Aller dans "Périscolaire > Trimestres" et créer le trimestre en cours
   (dates de début et de fin), puis cliquer sur "Activer".
5. Ajuster les tarifs et déposer les documents PDF (règlement intérieur,
   règlement de prélèvement) dans "Périscolaire > Réglages".
6. Créer une page sur le site (ex. "Inscription périscolaire") et y insérer
   le shortcode : [periscolaire_form]
7. Communiquer le lien de cette page aux familles. Aucun compte WordPress
   n'est nécessaire côté famille : la première demande se fait depuis cette
   page publique, puis la connexion se fait par lien reçu par e-mail (voir
   "Version 2.0.0" ci-dessous).

== Notes importantes ==

- Un seul trimestre peut être "actif" à la fois : c'est celui qui est
  affiché aux parents. Les données des trimestres précédents restent
  consultables dans le backoffice.
- L'accès au backoffice ("Périscolaire" dans le menu d'administration)
  nécessite la capacité WordPress "manage_options", c'est-à-dire un compte
  Administrateur. Si la personne qui gère le périscolaire au quotidien n'a
  pas ce rôle, il faudra soit lui créer un compte Administrateur, soit
  adapter le plugin pour utiliser une capacité personnalisée.
- Il n'y a pas de paiement en ligne intégré : les tarifs affichés sont
  informatifs, la facturation reste gérée par la mairie via l'export CSV.
- Chaque parent ne voit que ses propres enfants et ne peut modifier que
  leurs inscriptions (vérifié côté serveur, pas seulement côté affichage).

== Points ouverts / à valider ==

- Textes légaux (règlement intérieur, règlement de prélèvement) retranscrits
  depuis les documents fournis par la mairie : à comparer mot pour mot avec
  les originaux avant publication.
- Acceptation par case à cocher, pas signature électronique qualifiée.
- Aucun export bancaire SEPA (fichier pain.008) : les mandats sont stockés et
  consultables dans le backoffice, mais la mairie doit encore les saisir
  manuellement dans son outil bancaire pour lancer les prélèvements.
- Pas de paiement en ligne : les tarifs affichés restent indicatifs.
- WP-Cron (purge RGPD, bascule annuelle de classe) dépend des visites du
  site : prévoir un cron système sur un site peu fréquenté.

== Sécurité (version 1.1.0) ==

Les mesures suivantes sont appliquées :

- Contrôle d'accès systématique : chaque action d'administration vérifie
  la capacité de l'utilisateur ET un nonce (protection CSRF). Les deux sont
  nécessaires : le nonce prouve l'intention, la capacité prouve le droit.
- Cloisonnement des données : un parent ne peut modifier que les
  inscriptions de ses propres enfants. Le contrôle est fait côté serveur à
  chaque requête, pas seulement à l'affichage.
- Les modifications ne sont acceptées que sur le trimestre ACTIF et sur un
  jour ouvert : impossible de modifier un trimestre clos en rejouant une
  requête.
- Toutes les requêtes SQL utilisent $wpdb->prepare(), y compris celles
  comportant un nombre variable de paramètres.
- Échappement des sorties (esc_html / esc_attr / esc_url) sur l'ensemble
  des templates.
- wp_unslash() avant nettoyage des données POST : indispensable pour que
  les noms comportant une apostrophe soient enregistrés correctement.
- Validation stricte du format des dates et liste blanche des codes de
  service : toute valeur inattendue est rejetée.
- Protection contre l'injection de formules CSV : un nom saisi par un
  parent ne peut pas déclencher l'exécution de code à l'ouverture de
  l'export dans Excel.
- Redirections via wp_safe_redirect() (pas de redirection hors du site).
- Limites de volume : nombre d'enfants par compte, durée maximale d'un
  trimestre.
- Les scripts et le jeton de sécurité ne sont chargés que sur la page
  contenant réellement le formulaire.
- Fichiers index.php dans chaque répertoire (anti-listing).

== Suppression des données (RGPD) ==

Ce plugin stocke des données concernant des mineurs (nom, prénom, classe,
présences). Ces données doivent être conservées le temps nécessaire à la
gestion du service et à la facturation, puis supprimées.

Par défaut, supprimer le plugin ne détruit PAS les données, afin d'éviter
une perte irréversible lors d'une manipulation involontaire. Pour que la
suppression du plugin efface réellement les tables, ajouter dans
wp-config.php avant de supprimer le plugin :

    define('PSC_REMOVE_DATA_ON_UNINSTALL', true);

Points à traiter côté mairie, hors plugin :
- Mentionner ce traitement dans le registre des traitements de la commune.
- Informer les familles (finalité, durée de conservation, droits d'accès
  et de rectification) sur la page d'inscription.
- Le site doit être en HTTPS : sans cela, identifiants et données
  transitent en clair.

== Accès pour un agent non-administrateur ==

Par défaut le backoffice requiert la capacité "manage_options"
(Administrateur). Pour donner l'accès à un rôle dédié sans accorder les
pleins pouvoirs sur le site, ajouter dans le fichier functions.php du
thème ou dans une extension dédiée :

    add_filter('psc_manage_capability', function () {
        return 'psc_manage_periscolaire';
    });

puis attribuer cette capacité au rôle voulu (via un gestionnaire de rôles
tel que Members ou User Role Editor).

== Version 2.0.0 — accès des familles sans compte WordPress ==

CHANGEMENT MAJEUR : les familles ne sont plus des utilisateurs WordPress.

Fonctionnement :
1. La mairie enregistre les adresses e-mail des familles dans
   Périscolaire > Familles (les enfants sont rattachés ensuite dans
   Périscolaire > Enfants).
2. Le parent saisit son adresse sur la page publique et reçoit un lien
   valable 30 minutes, à usage unique.
3. Ce lien ouvre une session de 12 heures, gérée par un cookie signé.
   Aucun mot de passe n'est créé ni transmis.

Il n'y a pas d'inscription libre : une adresse inconnue ne reçoit rien.
C'est volontaire — sans cela, n'importe qui pourrait créer des entrées
dans une base contenant des données d'enfants.

Mesures de sécurité propres à ce mécanisme :
- Jetons stockés hachés (HMAC-SHA256) : une fuite de la base ne permet
  pas de se connecter.
- Comparaison à temps constant (hash_equals) contre les attaques
  temporelles.
- Le jeton est retiré de l'URL par redirection après usage, pour ne pas
  rester dans l'historique du navigateur.
- Message identique que l'adresse soit connue ou non (protection contre
  l'énumération des familles inscrites).
- Limitation de fréquence : 3 demandes par adresse / 15 min, 10 par
  adresse IP / heure.
- Cookie de session signé, HttpOnly, SameSite=Lax.
- Une famille désactivée par la mairie perd l'accès immédiatement, même
  si sa session est encore ouverte.

== Délai de modification (48 h par défaut) ==

Les familles peuvent modifier leur planning jusqu'à un certain nombre
d'heures avant le jour concerné (48 par défaut, réglable dans
Périscolaire > Réglages ; 0 pour désactiver).

- Le décompte part de 00h00 du jour de service, ce qui couvre la
  garderie du matin.
- Le calcul respecte le fuseau horaire du site, et non celui du serveur.
- Le contrôle est appliqué CÔTÉ SERVEUR : griser la case dans le
  navigateur ne suffirait pas.
- La mairie n'est jamais soumise à ce verrou et peut corriger à tout
  moment depuis le backoffice.

== Confirmation par e-mail ==

Les inscriptions étant enregistrées à chaque clic, un e-mail par case
cochée serait ingérable. Un bouton « Valider et recevoir mon planning »
envoie donc à la demande un récapitulatif complet : jours, prestations,
totaux par service et montant indicatif.

La mairie peut recevoir une copie de chaque validation (option dans les
Réglages).

IMPORTANT — envoi des e-mails : le plugin utilise wp_mail(). Sur beaucoup
d'hébergements mutualisés, les messages partent en indésirables ou ne
partent pas du tout sans configuration SMTP. Comme le lien de connexion
transite par e-mail, cette configuration conditionne le fonctionnement
même du service. À faire vérifier par l'administrateur du site, avec un
envoi de test réel avant ouverture aux familles.

== Version 2.1.0 — demandes d'inscription avec modération ==

Une famille inconnue de la mairie peut désormais déposer une demande
depuis la page publique, sans obtenir d'accès automatique.

Parcours en trois temps :

1. Le parent remplit le formulaire « Première inscription ? » : adresse
   e-mail, nom, téléphone, et le ou les enfants concernés.
2. Il reçoit un e-mail et doit confirmer son adresse. Tant qu'il ne l'a
   pas fait, la demande N'APPARAÎT PAS dans le backoffice.
3. Une fois l'adresse confirmée, la demande rejoint la file de
   modération (Périscolaire > Demandes) et la mairie est prévenue par
   e-mail. Elle valide ou refuse.

Pourquoi cette étape de confirmation : sans elle, un robot pourrait
remplir la file de modération avec des adresses inventées, et la mairie
passerait son temps à trier du bruit. Ici, seules des adresses réelles
atteignent le backoffice.

À la validation, la famille et ses enfants sont créés automatiquement et
le parent reçoit immédiatement son lien d'accès. Les noms et classes
sont modifiables par la mairie avant validation : les informations
saisies par le demandeur sont déclaratives.

En cas de refus, la mairie peut saisir un motif et choisir (ou non)
d'en informer le demandeur.

Protections du formulaire public :
- Confirmation d'adresse obligatoire avant modération.
- Limitation de fréquence : 5 demandes par adresse IP et par heure,
  3 par adresse e-mail et par jour.
- Champ piège (honeypot) masqué, rempli par de nombreux robots.
- Une adresse déjà enregistrée ne crée pas de demande : le parent reçoit
  directement son lien de connexion, et le message affiché reste
  identique dans tous les cas (protection contre l'énumération).
- Les données saisies sont nettoyées à l'enregistrement ET revalidées à
  l'affichage dans le backoffice : une saisie hostile ne peut pas
  s'exécuter sur le poste de l'agent qui modère.
- Nombre d'enfants borné, longueurs de champs bornées.

Conservation des données (RGPD) :
- Une demande non confirmée est supprimée automatiquement au bout de
  7 jours.
- Une demande traitée (validée ou refusée) est supprimée au bout de
  90 jours.
- Ces purges reposent sur le planificateur de tâches de WordPress
  (WP-Cron), déclenché par les visites du site. Sur un site peu
  fréquenté, prévoir un cron système si la ponctualité importe.
- Le formulaire affiche une mention d'information sur le traitement des
  données ; adaptez-la au registre des traitements de la commune.

== Évolutions depuis la 2.1.0 : portail famille, assurance scolaire, facturation, commande fournisseur ==

Le formulaire de demande d'inscription est devenu un parcours en 4 étapes
(Coordonnées, Enfants, Paiement, Règlement) : impossible de passer à l'étape
suivante tant qu'elle n'est pas complète, avec revalidation côté serveur.
Prénom et nom sont désormais des champs séparés. Un mandat de prélèvement
SEPA au format PDF est généré et joint à l'e-mail de confirmation quand la
famille choisit le prélèvement (jamais stocké sur le serveur, l'IBAN y
figurant en clair).

Chaque enfant déclaré doit désormais avoir un justificatif d'assurance
scolaire (PDF/JPG/PNG, 1 Mo maximum), dès la demande d'inscription et à
chaque rentrée ensuite. Un justificatif manquant bloque l'ajout d'un
nouveau jour de cantine/garderie pour l'enfant concerné (jamais de blocage
rétroactif sur un jour déjà déclaré).

Une fois connectée, la famille accède à "Mon Espace Famille", un portail à
onglets : Tableau de bord (résumé de la période en cours, prochaine facture,
raccourci "Annulation prestations" pour annuler rapidement une ou plusieurs
prestations déjà cochées sans repasser par le calendrier), Cantine &
Garderie (le calendrier, désormais avec infobulles sur les abréviations de
prestation), Menu de la semaine, Mes enfants (fiche en lecture seule +
correction du prénom/nom/naissance, suivi et dépôt du justificatif
d'assurance), Mes factures (téléchargement PDF) et Mon profil (état civil,
coordonnées, changement d'e-mail avec confirmation par lien) ainsi que
Documents (règlement intérieur et règlement de prélèvement en PDF).

Côté mairie : un Tableau de bord (nouvelle page d'accueil du backoffice)
affiche les statistiques clés et une liste "À faire" (demandes en attente,
statut du menu et de la commande fournisseur de la semaine prochaine). Une
nouvelle page "Commande fournisseur" calcule chaque semaine le nombre de
repas par classe à partir des inscriptions réelles et envoie ce décompte au
prestataire de restauration sur action manuelle (jamais automatique),
archivant un instantané figé de chaque envoi. La génération des factures
mensuelles est désormais bloquée pour un mois en cours (non terminé), pour
éviter une facture incomplète. Chaque année à la rentrée, une tâche
planifiée fait automatiquement progresser la classe des enfants actifs et
désactive ceux qui terminaient le CM2, sans action manuelle de la mairie.
