=== Périscolaire - Inscriptions ===
Contributors: mairie
Tags: périscolaire, mairie, inscription, cantine, garderie
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 5.21.0
License: GPLv2 or later

== Description ==

Remplace le fichier calendrier rempli à la main pour l'inscription aux services
périscolaires (Garderie Matin, Cantine, Garderie Soir, Forfait journée).

Une famille non connue de la mairie dépose une demande d'inscription en ligne
(règlement intérieur et, si
elle règle par prélèvement, un mandat SEPA). Une fois la demande validée par
la mairie, la famille se connecte sans mot de passe (lien reçu par e-mail) à
son espace personnel. Elle dépose l'assurance de chaque enfant depuis Planning ;
l'accès au calendrier est débloqué après acceptation automatique ou revue par
la mairie (Périscolaire > Réglages). Les pièces se consultent et se valident
dans Périscolaire > Assurances scolaires, avec une visionneuse PDF.
Dans le calendrier, elle déclare le RYTHME HABITUEL de chacun de ses
enfants pour toute l'année scolaire, puis ajuste jour par jour (exceptions)
quand un imprévu survient — chaque case cochée est enregistrée immédiatement,
pas de bouton "Envoyer" à chercher, pas de fichier à renvoyer par e-mail —
et y suit aussi ses factures, ses justificatifs d'assurance et son profil.

La mairie centralise tout dans un backoffice dédié (menu "Périscolaire" dans
l'administration WordPress) : tableau de bord avec liste de tâches, calendrier
scolaire (import automatique zone C), configuration de l'année scolaire
(dates, vacances, fériés), modération des demandes, familles, enfants (avec
bascule de classe automatique à la rentrée), menus de cantine, commande
fournisseur hebdomadaire, facturation mensuelle en PDF, et export CSV pour la
comptabilité.

Depuis une fiche famille, un agent habilité peut aussi ouvrir exactement son
espace en lecture seule pendant 30 minutes afin de diagnostiquer un problème,
sans demander le lien de connexion et sans pouvoir modifier les données. Chaque
consultation est motivée, journalisée et visible de façon anonymisée par la
famille si le réglage de transparence est actif.

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

== RGPD ==

Ce plugin stocke des données concernant des mineurs (identité, classe,
présences, planning, personnes autorisées, justificatifs d'assurance),
une catégorie particulière de données (allergies alimentaires, traitées
comme une information de santé) et des coordonnées bancaires.

Intégration aux outils natifs de confidentialité de WordPress :
- Outils > Exporter les données personnelles retrouve un foyer par
  adresse e-mail (les familles n'ont pas de compte WordPress) et exporte
  l'intégralité de ses données : profil, IBAN déchiffré, enfants,
  allergies, scolarité par année, planning, personnes autorisées,
  factures envoyées, échanges avec la mairie.
- Outils > Effacer les données personnelles applique le droit à
  l'effacement — cf. « Durées de conservation » ci-dessous pour son
  périmètre exact (les factures n'en font pas partie).
- Réglages > Confidentialité propose un texte suggéré (finalités,
  catégories de données, durées de conservation, droits) à relire et
  publier sur la page de confidentialité du site — rien n'est publié
  automatiquement.

Consentement pour les allergies (donnée de santé) : la case « cet enfant
a une allergie alimentaire » rend la description obligatoire mais n'est
pas un consentement — une case dédiée, distincte, doit être cochée
explicitement (page d'inscription, portail famille), horodatée en base.
Elle n'est redemandée à une correction ultérieure que si la description
change ou n'a jamais été consentie (une simple faute de frappe sur le nom
d'un enfant ne remet pas en cause un consentement déjà valablement donné).

Durées de conservation — deux régimes distincts, à ne pas confondre :
- Fiche enfant (identité, allergies, planning, personnes autorisées) :
  purgée automatiquement (cron quotidien) 400 jours après le passage à
  « sorti » (dernière classe, déménagement, retrait), un délai qui
  couvre la fin de l'année scolaire en cours quel que soit le moment de
  la sortie. Aucune obligation légale ne justifie une conservation plus
  longue : c'est le principe de minimisation du RGPD (art. 5.1.e) qui
  s'applique, pas une durée choisie arbitrairement. Réglable par filtre
  (psc_children_retention_days) pour l'intégrateur qui en aurait besoin.
- Factures : conservées dix ans même après une demande d'effacement RGPD
  sur le foyer, car l'obligation légale de conservation des pièces
  comptables (Code de commerce, art. L123-22) prévaut ici sur le droit à
  l'effacement (RGPD art. 17§3-b — l'effacement ne s'applique pas quand
  le traitement reste nécessaire au respect d'une obligation légale).
  Concrètement, l'effaceur RGPD ne supprime jamais la ligne « famille » :
  il l'anonymise sur place (nom, coordonnées, IBAN supprimés) pour que
  chaque facture conserve une référence valide sans exposer l'identité
  de la famille au-delà du nécessaire — plutôt que de la supprimer, ce
  qui laisserait des factures orphelines pendant dix ans.
- Journal d'audit et échanges famille/mairie : durées de rétention et
  purge automatique déjà en place, documentées plus haut (cf. le
  changelog des versions correspondantes) et non modifiées ici.

Suppression totale du plugin (hors cycle de vie ci-dessus) : par défaut,
supprimer le plugin ne détruit PAS les données, afin d'éviter une perte
irréversible lors d'une manipulation involontaire. Pour que la
suppression du plugin efface réellement les données, ajouter dans
wp-config.php avant de supprimer le plugin :

    define('PSC_REMOVE_DATA_ON_UNINSTALL', true);

L'effacement porte alors sur la totalité : toutes les tables du plugin,
toutes ses options et transients, ainsi que les fichiers déposés par les
familles (justificatifs d'assurance, factures PDF), factures comprises —
cette bascule est un choix explicite et déclaré de la mairie, pas un
effacement RGPD au cas par cas, elle n'est donc pas soumise à la même
retenue. Les tables et options de WordPress lui-même ne sont jamais
touchées.

Points à traiter côté mairie, hors plugin :
- Mentionner ce traitement dans le registre des traitements de la
  commune, en s'appuyant sur le texte suggéré (Réglages > Confidentialité).
- Le site doit être en HTTPS : sans cela, identifiants et données
  transitent en clair.

== Accès pour un agent non-administrateur ==

Par défaut, seuls les administrateurs accèdent au backoffice
périscolaire. Le rôle Éditeur n'y a aucun accès : modifier le contenu du
site ne donne aucun droit sur les dossiers d'enfants.

Un agent reçoit ses habilitations une à une, sur son profil WordPress
(Utilisateurs > Profil > Habilitations périscolaires), sans droits
d'administration du site. Elles se cumulent :
- Familles, enfants et assurances (psc_manage_families)
- Planning, menus et présences (psc_manage_presence)
- Facturation et prélèvements (psc_manage_billing)
- Messages aux familles (psc_manage_messages)
- Configuration du service (psc_manage_config)
- Données de santé — allergies (psc_view_health)
- Journal d'audit (psc_view_audit)

Chaque écran et chaque action exige l'habilitation de son domaine. Les
allergies ne s'affichent qu'avec « Données de santé », même pour une
personne qui gère les dossiers des familles. Le tableau de bord, qui
réunit tous les domaines, reste réservé aux administrateurs.

Pour rendre l'accès complet à d'autres rôles (ancien comportement pour
les éditeurs), ajouter dans une extension dédiée :

    add_filter('psc_manage_default_roles', function () {
        return array('administrator', 'editor');
    });

puis désactiver et réactiver l'extension : c'est l'activation qui
réattribue les capacités aux rôles.

== Version 2.0.0 — accès des familles sans compte WordPress ==

CHANGEMENT MAJEUR : les familles ne sont plus des utilisateurs WordPress.

Fonctionnement :
1. La mairie enregistre les adresses e-mail des familles dans
   Périscolaire > Familles (les enfants sont rattachés ensuite dans
   Périscolaire > Enfants).
2. Le parent saisit son adresse sur la page publique et reçoit un lien
   valable 30 minutes.
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

== Changelog ==

La trace lisible entre deux mises à jour, côté mairie : le garde-fou de la
release (tag refusé s'il ne correspond pas à PSC_VERSION) garantit la
numérotation, il ne reste qu'à tenir cette section à chaque tag. L'historique
complet, commit par commit, reste dans le dépôt git.

= 5.21.0 =
* L'heure limite de modification affichée aux familles est de nouveau exacte. Le message « Modifiable jusqu'au… » annonçait une échéance une heure trop tôt en hiver et deux en été ; il indique désormais l'instant réellement contrôlé. Les week-ends de changement d'heure, cette échéance peut tomber à 23:00 ou 01:00 plutôt qu'à minuit : c'est bien l'heure à laquelle le jour se verrouille.
* Pour un enfant en « cantine sans repas », le planning montre enfin ce qui est facturé : sa cantine apparaît en midi sans repas, que la famille peut cocher ou décocher. Jusqu'ici, la case cantine restait fermée et le midi sans repas vide, alors que ce midi était facturé sans pouvoir être retiré.
* Une prestation fermée un jour donné ne peut plus être ajoutée ce jour-là : la famille reçoit le message habituel au lieu d'une inscription sans effet, qui reprenait vie si la prestation rouvrait.
* Deux validations simultanées d'une même demande d'inscription (double clic, deux agents) ne créent plus les enfants en double, et un refus ne peut plus écraser une validation faite au même moment. L'alerte alimentation ne part plus pour un enfant dont la création a été annulée.
* Un clic sur le planning est désormais enregistré en entier ou pas du tout : une panne au milieu d'un changement de rythme ne laisse plus un rythme à moitié effacé, et la famille voit un message d'erreur au lieu d'un faux « enregistré ».
* Une même adresse e-mail ne peut plus ouvrir deux foyers, même lorsque deux enregistrements ont lieu au même moment. **Après la mise à jour**, si deux foyers partagent déjà la même adresse de second parent, une alerte s'affiche dans le backoffice : corrigez la fiche concernée dans Familles, l'alerte disparaît d'elle-même.

= 5.20.0 =
* Le rôle Éditeur de WordPress n'a plus aucun accès au périscolaire. Modifier les pages du site ne donne désormais aucun droit sur les dossiers d'enfants ; jusqu'ici, un éditeur pouvait encore ouvrir l'espace de n'importe quelle famille. **Avant la mise à jour**, repérez les agents de la mairie qui travaillent avec un compte Éditeur : ils perdront l'accès et devront recevoir leurs habilitations sur leur profil WordPress (Habilitations périscolaires).
* Les habilitations périscolaires fonctionnent enfin une à une : une personne habilitée à la seule facturation voit la facturation, et rien d'autre. Auparavant, chaque écran exigeait l'accès complet, et une habilitation partielle ne servait à rien.
* Les allergies ne s'affichent plus qu'aux personnes qui ont l'habilitation « Données de santé », y compris pour celles qui gèrent les dossiers des familles. Sans elle, la mention « Accès restreint » apparaît pour chaque enfant, sans révéler lesquels sont concernés.
* La publication d'une version attend désormais le résultat des tests automatiques, vérifie la mise à jour depuis la version précédente et joint un fichier de sommes de contrôle aux archives.

= 5.19.0 =
* Une facture déjà envoyée ne change plus toute seule. Jusqu'ici, modifier un tarif ou passer un enfant en « cantine sans repas » puis régénérer réécrivait les factures passées, y compris celles déjà reçues par les familles — sans que rien ne le signale. Le calcul qui a produit une facture (lignes, tarifs appliqués, statut de chaque enfant) est désormais conservé avec elle. Régénérer une facture envoyée dont rien n'a changé ne touche plus à rien ; si le montant change, la version reçue par la famille est archivée telle quelle, PDF compris, et la nouvelle repart « à envoyer » sous un numéro distinct (suffixe -R2).
* La suppression d'une famille depuis Familles ne détruit plus ses factures. Elle suit la même règle que l'effacement RGPD : les pièces comptables sont conservées et la fiche est anonymisée à la place, pour qu'aucune facture ne se retrouve rattachée à un dossier disparu. Une famille sans facture reste supprimée entièrement.
* Ajoute une fiche de recette de l'hébergement à la documentation : ce qu'il faut constater sur le serveur lui-même (inaccessibilité réelle des documents, cache, cron, sauvegardes, restauration), que le plugin ne peut pas vérifier depuis son propre code.
* Met la documentation en accord avec le signalement alimentaire minimal introduit en 5.17.0.
* Bascule la recherche d'adresse du formulaire d'inscription vers le service de géocodage de la Géoplateforme (IGN). L'ancienne adresse de l'API annonçait sa propre fin depuis le 31 janvier 2026 et pouvait cesser de répondre sans préavis, ce qui aurait privé les familles de l'autocomplétion. Les données restent celles de la Base Adresse Nationale et la saisie manuelle reste disponible ; rien ne change pour la famille, hormis le nom du service indiqué sous le champ.
* Corrige la correction d'un enfant depuis l'espace familles : enregistrer la fiche effaçait le signalement alimentaire déjà déclaré. La case est désormais présente dans la fenêtre de modification, pré-cochée selon la déclaration existante.
* L'espace intervenants s'ouvre de nouveau avec le code partagé tant qu'aucun intervenant individuel n'est enregistré ; il renvoyait à la saisie du code juste après l'avoir accepté.
* Dans Planning 2, cocher ou décocher un jour du rythme habituel laisse l'affichage sur le mois consulté, au lieu de ramener au mois en cours.
* L'objet du courriel envoyé à la mairie lors d'un signalement alimentaire ne mentionne plus d'allergie : il annonce seulement un échange à prévoir, sans information de santé visible dans une boîte de réception.
* Une commande WP-CLI lancée en root par l'hébergeur ne peut plus déplacer les documents des familles vers un dossier que le serveur web ne peut pas écrire.

= 5.18.0 =
* Finalise les protections techniques P1-13, P1-14, P1-15 et P1-17 : uploads avec reprise, horloge de verrou cohérente, export sans repas aligné et migrations protégées contre les conflits/concurrences.

= 5.17.0 =
* Remplace la collecte de détails d'allergie par un signalement minimal : la famille demande un échange oral avec la mairie, qui décide ensuite du statut « cantine sans repas ». Aucun détail médical n'est demandé ni transmis dans ce parcours.

= 5.16.0 =
* Réorganise le sous-menu Périscolaire, jusqu'ici 17 entrées à plat : six sections (À traiter, Cantine & garderie, Familles, Facturation, Communication, Configuration), avec les demandes d'inscription et les échanges familles non traités en tête, portant désormais un compteur.
* Fusionne « Calendrier scolaire en cours » et « Années scolaires » en une seule page « Année scolaire » à onglets ; l'ancienne adresse continue de fonctionner (redirection automatique).
* Déplace l'adresse du fournisseur de repas depuis Réglages vers un onglet dédié de Commande fournisseur.
* Renomme plusieurs entrées pour lever des ambiguïtés : Messages devient Messages aux familles, Demandes devient Demandes d'inscription, Facturation (la liste) devient Factures.

= 5.15.0 =
* Intègre les outils natifs de confidentialité de WordPress : Outils > Exporter les données personnelles et Outils > Effacer les données personnelles retrouvent un foyer par e-mail (les familles n'ont pas de compte WordPress) et couvrent profil, enfants, planning, factures et échanges. L'effacement anonymise la fiche famille plutôt que de la supprimer, pour que les factures restent conservées dix ans comme l'exige la loi comptable, sans laisser de facture orpheline.
* Suggère automatiquement un texte pour la page de confidentialité (Réglages > Confidentialité), à relire et publier par un administrateur.
* Exige désormais une case de consentement dédiée, distincte et horodatée avant d'enregistrer une allergie alimentaire déclarée par une famille (page d'inscription et portail famille) — une donnée de santé, pas une simple case à cocher parmi d'autres.
* Purge automatiquement, chaque jour, la fiche d'un enfant sorti du service depuis plus de 400 jours (identité, planning, personnes autorisées, allergies) : plus aucun dossier ne traîne indéfiniment une fois la relation terminée.

= 5.14.1 =
* Corrige la direction « L'Habit de Marianne » : le fond de page et les encarts secondaires n'avaient pas suivi le refroidissement de teinte validé (crème/papier chauds oubliés lors du déploiement 5.14.0), et le bandeau de délai / la bulle famille gardaient une bordure pleine au lieu du liseré prévu.

= 5.14.0 =
* Refond le système visuel du portail famille, de la messagerie et de l'écran intervenants : direction « L'Habit de Marianne », proche de la discipline du Système de Design de l'État sans en reprendre la police ni la charte — une seule famille de caractères (Public Sans, auto-hébergée), aucun corps italique, coins carrés, ombres décoratives remplacées par une bordure basse nette sur les éléments actionnables.
* Documente le contexte produit (PRODUCT.md) et le système de design (DESIGN.md) pour guider les prochains écrans.

= 5.13.0 =
* Refond le design des échanges familles–mairie : vrais onglets avec badges de non-lus, liste des échanges avec filtres et statuts (réponse reçue, en attente, lu par la mairie, clos), fil de conversation en bulles asymétriques, et formulaire « Écrire à la mairie » avec objet de routage, enfant concerné et pièce jointe.
* Ajoute un délai de réponse et un numéro d'urgence configurables, affichés aux familles sur l'écran des échanges et sur le formulaire.

= 5.12.0 =
* Ajoute un journal d'audit du plugin : chaque action sensible (agents, familles, système) est tracée dans une table chaînée par empreinte cryptographique, avec rédaction des données sensibles (IBAN, jeton, allergies…) et un écran d'administration dédié (filtres, export CSV/ODS, vérification d'intégrité).
* Applique une durée de rétention par niveau de sensibilité, avec purge quotidienne automatique et anonymisation de l'historique lors de la suppression d'une famille.

= 5.11.0 =
* Ajoute des conversations privées entre une famille et la mairie, en plus des diffusions descendantes existantes : une famille peut écrire à la mairie, répondre à une diffusion quand celle-ci l'autorise, et la mairie peut ouvrir, répondre, clore ou rouvrir un échange.
* Notifie par e-mail les nouveaux messages d'un échange, sans jamais révéler leur contenu, avec un regroupement des envois rapprochés.
* Purge automatiquement les échanges anciens et supprime les conversations d'une famille supprimée.

= 5.10.1 =
* Permet aux agents habilités de consulter temporairement le portail exact d’une famille active, en lecture seule stricte.
* Journalise le motif, le début et la fin de chaque consultation, avec expiration automatique et historique sur la fiche famille.
* Neutralise les écritures et effets de bord de lecture : messages non marqués comme lus, contrôles désactivés et aucune notification navigateur.
* Informe la famille dans Mon profil avec une trace anonymisée et configurable, conservée douze mois à l’écran et purgée après 365 jours.
* Ajoute les tests de sécurité, de session, d’expiration, de déconnexion, de transparence et d’accessibilité du parcours.

= 5.10.0 =
* Ajoute la table de traçabilité des consultations et la capacité WordPress dédiée.
* Classe exhaustivement les actions du portail famille pour préparer leur exécution en lecture seule.

= 5.9.0 =
* Ajoute une preuve automatisée de sauvegarde et restauration MySQL dans une base isolée avant les migrations de CI.
* Documente le filet de sécurité, la couverture fonctionnelle et l'inventaire du schéma observé.
* Ajoute des compteurs techniques anonymes pour mesurer les usages legacy avant toute suppression future.

= 5.8.1 =
* Corrige le verrouillage du planning pour les familles ayant plusieurs enfants : seule la fiche de l'enfant sans assurance valide est bloquée.
* Les autres enfants restent modifiables et la copie du rythme ignore les enfants dont l'assurance n'est pas valide.
* Conserve l'enfant sélectionné après le dépôt ou le remplacement de son justificatif.

= 5.8.0 =
* Ajoute un canal de notification navigateur optionnel, actif uniquement pendant que l'espace famille reste ouvert.
* L'e-mail et la notification navigateur sont désormais décochés par défaut pour chaque nouveau message.
* Corrige le faux doublon qui empêchait de publier deux messages successifs.
* Simplifie le pied de lecture en conservant uniquement la date de première consultation.

= 5.7.0 =
* Intègre une boîte de réception responsive et un digest compact des messages dans l'espace famille.
* Actualise immédiatement le badge et les urgences après lecture, avec accusé explicite et contrôle d'accès 403.
* Réordonne le back-office pour placer Messages sous le tableau de bord et le calendrier juste avant les années scolaires.

= 5.6.1 =
* Corrige la construction de l'archive de publication après l'ajout du plan de refactorisation au dépôt.

= 5.6.0 =
* Ajout du module « Messages aux familles » : diffusion ciblée, e-mail par lots, programmation, pièces jointes par lien et suivi nominatif de la première consultation.
* Nouvel onglet Messages dans l'espace famille, badge de non-lus, bandeau urgent et accusé de prise de connaissance.
* Ajout des écrans de suivi, relance et export CSV, avec capacité WordPress dédiée et transparence RGPD.

= 5.5.18 =
* Menus : labels Bio et Label Rouge, logos, aperçu de saisie et légende.
* Première inscription : dépôt de la demande sans justificatif d'assurance.
* Planning : dépôt des assurances, blocage jusqu'à acceptation pour tous les
  enfants actifs, contrôle des modifications côté serveur.
* Mairie : visionneuse PDF, validation ou refus motivé des justificatifs ;
  acceptation automatique (par défaut) ou revue manuelle dans Réglages.
* Les documents déjà fournis restent acceptés pour leur année scolaire.

= 5.5.17 =
* Intervenants : la vue Semaine propose la semaine en cours et les huit
  suivantes, avec des colonnes datées et séparées pour chaque service.
* Le pointage reste limité à la semaine en cours ; le retour à la vue Jour
  recharge cette semaine.
* Mon profil : retirer le second parent conserve la session qui effectue
  l’action tout en révoquant les autres sessions et anciens liens d’accès.

= 5.5.16 =
* Mon profil : l’ajout d’un second parent conserve la session en cours ;
  le remplacement ou le retrait d’un accès existant reste révocable.
* Habilitations : retire la notion de pièce d’identité des formulaires,
  listes et traitements, avec nettoyage des anciennes données en base.
* Ajoute « Frère / Sœur » aux suggestions de lien avec l’enfant.
* Tests des habilitations, de la migration et des sessions du second parent.

= 5.5.15 =
* Première inscription : vérifie les pièces jointes avant l’envoi final
  et revient à l’étape Enfants si un fichier est absent, vide ou illisible.
* Justificatifs : distingue fichier manquant, taille dépassée, transfert
  incomplet et erreur serveur ; ajoute un diagnostic technique sans contenu
  des documents dans les logs.
* Ajoute des tests navigateur et PHP sur la réception des justificatifs.

= 5.5.14 =
* Familles : permet de supprimer une demande en attente de confirmation,
  avec confirmation préalable et suppression de ses justificatifs.
* Vérifie que la demande est toujours non confirmée avant sa suppression
  depuis la liste des familles, puis revient sur cette liste.

= 5.5.13 =
* Familles : affiche les demandes non confirmées avec le statut
  « Attente de confirmation » et leur date de dépôt.
* Ces demandes quittent cet état après confirmation de l’adresse e-mail ;
  leur affichage ne donne aucun accès anticipé au portail.

= 5.5.12 =
* Première inscription : retire la saisie des personnes autorisées ;
  leur gestion reste disponible dans Habilitations après inscription.
* Planning : déplace et raccourcit le rappel du délai dans l’étape 2,
  en conservant la valeur paramétrable en gras.

= 5.5.11 =
* Première inscription : affiche le libellé Date de naissance et précise
  le format PDF demandé pour l’assurance scolaire.
* Personnes autorisées : corrige la validation des téléphones et des champs
  incomplets ainsi que les doublons après suppression puis ajout.
* Retire la case de présentation d’une pièce d’identité des formulaires.
* Planning : renomme l’étape 2 « Ajuster le planning » et souligne le délai
  paramétrable de modification, également rappelé à la première connexion.

= 5.5.10 =
* Planning : après retrait exceptionnel d’un forfait du rythme habituel,
  permet de sélectionner et conserver G.M., Cant. et G.S. individuellement.
* Ajoute un test navigateur avec vérification en base et des tests unitaires
  couvrant l’ajout, le retrait des services et le retour au forfait.

= 5.5.9 =
* Facturation : regroupe les exports par mois et ajoute les CSV des
  prélèvements et de l’ensemble des factures.
* Paiements : pointage reçu / non reçu des chèques et espèces par facture
  mensuelle, avec conservation de la date de réception.
* État des comptes familles : détail des factures payées et non payées,
  total payé et somme due jusqu’au mois en cours ; prélèvements considérés
  payés par défaut selon le mode de paiement actuel de la famille.

= 5.5.8 =
* Factures : export XML des prélèvements SEPA CORE pain.008.001.02,
  avec date de prélèvement, compte créancier et validation du schéma XSD.
* Mandats : utilise la date d’acceptation enregistrée comme date de signature ;
  bloque l’export si des données obligatoires sont absentes ou invalides.
* IBAN : renforce les contrôles dans tous les formulaires, notamment la clé
  RIB française, les structures nationales et les contrôles britanniques.
* Ajoute des tests bancaires partagés PHP/navigateur et des tests d’export SEPA.

= 5.5.7 =
* Inscription et passage au prélèvement : validation de l’IBAN et du BIC
  dans le navigateur avant de poursuivre, en complément des contrôles serveur.
* Adresse du titulaire identique au foyer : champs recopiés, grisés et en
  lecture seule tant que la case est cochée ; synchronisation à l’inscription.
* Tableau de bord famille : affiche l’origine de la viande sous le menu
  de la semaine, lorsqu’elle est renseignée.

= 5.5.6 =
* Mon profil : le choix du mode de paiement reste réversible entre « Chèque ou
  espèces » et « Prélèvement automatique » tant que le formulaire SEPA n'a pas
  été validé.
* Espace intervenants : saisie et conservation de l'heure d'arrivée pour la
  garderie du matin, sans modifier le pointage de présence.
* Espace intervenants : le régime alimentaire de chaque enfant apparaît sur
  les listes cantine, en vue Jour et Semaine (Standard, Sans porc ou Sans
  viande).

= 5.5.5 =
* Mon profil : sélectionner le prélèvement retire immédiatement l'état actif
  de la carte « Chèque ou espèces ».
* Planning famille : « Appliquer ce rythme à toute la fratrie » utilise
  immédiatement les cases qui viennent d'être cochées, sans rechargement,
  pour G.M., Cantine, G.S. et Forfait.

= 5.5.4 =
* Planning famille : « Cantine sans repas » est proposée uniquement aux
  enfants flaggés par la mairie ; les autres utilisent exclusivement la
  cantine avec repas. La règle est également contrôlée côté serveur.
* Mon profil : une famille réglant par chèque ou espèces peut activer le
  prélèvement SEPA après saisie et validation des informations obligatoires.
  L'IBAN est chiffré et le mandat est envoyé par e-mail lorsque disponible.
* Factures : une facture générée reste invisible et inaccessible dans
  l'espace famille jusqu'à son envoi par la mairie.

= 5.5.3 =
* Facture PDF : la mention de prélèvement le 5 du mois suivant apparaît
  uniquement pour les familles réglant par prélèvement automatique SEPA.
* Menu de la semaine : la mention « Origine de la viande » apparaît aussi
  sur les anciens menus, dans l'espace famille connecté et la vue publique.

= 5.5.2 =
* Facture PDF : suppression de la mention nominative « Cantine sans repas »
  au-dessus du tableau. Les prestations et tarifs du tableau sont conservés.
* Test de non-régression sur le contenu du PDF.

= 5.5.1 =
* Facture PDF : un type de prestation sans aucune occurrence du mois ne
  figure plus — ni son bloc, ni ses lignes à zéro ; la facture ne liste
  que ce qui est réellement dû (le total est inchangé).
* Facture PDF : les logos sont bornés en largeur ET en hauteur
  (proportions conservées) — un blason portrait ne débordait plus de
  l'en-tête et ne recouvrait plus le bloc d'adresse de la famille ; la
  hauteur réellement dessinée repousse le séparateur.

= 5.5.0 =
* Facturation libre : les factures se génèrent quand la mairie le décide,
  pour N'IMPORTE QUEL mois — passé, courant ou futur (les montants futurs
  viennent des rythmes et exceptions déjà déclarés). Sélection par
  déclarations réelles du mois : un enfant sorti depuis reste facturé sur
  son mois passé, une famille sans déclaration n'a rien à facturer.
* Nouvelle suppression du mois : toutes les factures d'un mois (lignes et
  PDF du répertoire privé) s'effacent en un clic confirmé, y compris les
  déjà envoyées — la régénération repart de zéro.
* Génération et envoi décorréliés : régénérer une facture remplace son
  calcul et son PDF sans toucher à son statut d'envoi ; seul l'envoi (ou
  le renvoi) met la date à jour.
* Nouvel export prélèvements (SEPA) : fichier .ods (OpenDocument, natif
  LibreOffice, lisible dans Excel) construit en PHP pur sans dépendance —
  une ligne par famille en prélèvement avec IBAN déchiffré, BIC,
  titulaire, adresse, référence de mandat, numéro de facture, montant du
  mois et objet du prélèvement. Fichier téléchargé à la demande, jamais
  stocké, téléchargement journalisé ; un IBAN illisible (clé de
  chiffrement tournée) apparaît en clair dans la ligne comme « à
  ressaisir ».

= 5.4.3 =
* Sécurité — révocation réelle des accès familles (P1 de l'audit) :
  retrait ou remplacement du second parent, changement d'adresse du
  titulaire — chaque événement tue immédiatement et durablement toutes
  les sessions ouvertes du foyer et le lien de connexion encore valable
  (registre d'époques de session en base, plus dépendant des transients
  évinçables). Les cookies antérieurs restent acceptés jusqu'au premier
  événement, dans la limite de leur durée de 12 h.
* Sécurité — coordonnées bancaires : sans primitive de chiffrement
  disponible, ou si le chiffrement échoue, l'enregistrement est refusé
  avec un message lisible au lieu d'écrire l'IBAN en clair (famille,
  demande publique, migration).
* Confidentialité — les pages du plugin (portail famille, espace
  intervenants, wizard public) émettent Cache-Control: no-store : aucun
  cache partagé (extension, CDN, proxy) ne doit conserver un rendu qui
  authentifie hors WordPress ou porte des jetons.

= 5.4.2 =
* Inscriptions publiques — les allergies ne sont plus perdues à la
  validation : la relecture d'une demande (écran mairie et approbation
  automatique) restitue désormais l'allergie déclarée, la fiche enfant
  la porte, l'alerte PAI part et les listes intervenants/commande
  fournisseur en tiennent compte.
* Demandes d'inscription — rapprochement contrôlé : les demandes déjà
  approuvées dont la fiche enfant est restée sans allergie sont listées
  sur l'écran mairie ; un report en un clic complète les fiches vides
  (jamais une fiche déjà renseignée) et déclenche le rappel PAI.
* Espace intervenants — un enfant présent à midi sans repas (déclaration
  MSR ou flag mairie « cantine sans repas », y compris au forfait) est
  désormais pointable sur l'onglet cantine : la présence est affichée
  ET acceptée par le contrôle serveur ; le forfait d'un enfant flégué
  décrit son midi comme « sans repas » (facturation FSR inchangée).

= 5.4.1 =
* Planning cantine2 : pour les enfants « Cantine sans repas », la colonne
  Cantine est masquée dans le rythme habituel et les exceptions du mois.
* Affichage des colonnes G.M., Cantine sans repas, G.S. et Forfait sans
  repas, avec le tarif sans repas configuré dans les Réglages.
* Les colonnes et tarifs s’adaptent au changement d’enfant sans rechargement.
* Test navigateur couvrant les deux profils et le changement d’enfant.

= 5.4.0 =
* Menus : champ « Origine de la viande » prérempli dans le backoffice,
  modifiable par semaine. La mention figure sous le menu public, dans
  le portail et dans l'e-mail ; une valeur vide la masque.
* Schéma 4.3.0 : ajout de la colonne origine_viande aux menus.
  Les anciens menus restent inchangés avant leur enregistrement.
* Enfants marqués « Cantine sans repas » : sélection Cantine désactivée
  dans le planning, ajouts refusés côté serveur, y compris en lot.
  La copie du rythme à la fratrie adapte Cantine en Cantine sans repas
  pour les enfants concernés. Le libellé de la prestation est harmonisé.
* Tests navigateur et intégration du champ menu, avec e-mails interceptés.

= 5.3.0 =
* Nouveau tarif « Forfait sans repas cantine » : 9 € par défaut, modifiable
  dans les Réglages avec les autres tarifs. Il s'applique automatiquement
  lorsqu'un enfant marqué « Cantine sans repas » déclare un forfait,
  sans cumul avec les prestations couvertes.
* Les estimations mensuelles et annuelles et les nouvelles factures
  utilisent ce tarif. Le PDF précise le statut « Cantine sans repas ».
* Le statut apparaît dans Mes enfants et sur l'enfant actif du Planning,
  y compris après un changement d'onglet enfant.
* Tests unitaires et scénario navigateur avec vérification du PDF.

= 5.2.1 =
* Correction du forfait avec repas : les prestations couvertes (garderie
  matin, cantine et garderie soir) ne sont plus facturées en supplément
  du forfait. Le traitement des journées sans repas reste inchangé.
* Test de régression sur la résolution du forfait et son montant.

= 5.2.0 =
* Nouvelle prestation « Midi sans repas » : l'enfant est présent sur le
  créneau du midi sans y déjeuner (repas fourni par la famille), facturée
  1,00 € (tarif éditable dans Réglages). Elle s'exclut de la cantine et du
  forfait — un même midi, l'enfant déjeune à la cantine ou y est sans
  repas, jamais les deux — et reste compatible avec les garderies matin
  et soir. Le rythme et les exceptions la traitent comme les autres
  prestations (colonne « S. repas » du Planning) ; la commande fournisseur
  ne la compte dans aucune colonne repas, et la liste intervenants de la
  cantine affiche l'enfant avec la mention « Midi sans repas ».
* Enfants (backoffice) — case « Cantine sans repas » : la mairie peut
  flaguer un enfant une fois pour toutes ; ses déclarations de cantine
  valent alors « midi sans repas » (facturation au tarif MSR, aucun repas
  commandé au fournisseur, mention sur la liste intervenants), sans
  toucher aux déclarations de la famille. Retirable à tout moment.
* Réglages — le bloc « Écran Planning des familles » disparaît : les deux
  variantes de planning restent exposées comme par défaut, le réglage
  n'avait plus d'effet utile.

= 5.1.2 =
* Tableau de bord : « Déclarer un jour » pointe vers l'écran Planning
  (rythme + exceptions) — l'entrée de menu des familles ; et le libellé de
  la première carte devient « Année scolaire » (les totaux sont annuels
  depuis la v5).
* Espace famille — correctif d'affichage : sur la carte « Accès rapide »
  (fond or), les liens passaient en orange au survol (règle a:hover du
  thème) et « Ajouter un enfant » devenait illisible — le texte reste
  noir au survol, avec un fond blanc comme repère sur le bouton contour.

= 5.1.1 =
* Portail — l'écran « Planning » (rythme + exceptions) s'appelle
  simplement « Planning » dans le menu, et sa bannière de bascule vers
  Planning - 1 disparaît : l'écran secondaire reste atteignable par son
  URL directe (?psc_tab=cantine) et par le lien « Déclarer un jour » du
  tableau de bord.

= 5.1.0 =
* Portail — le menu ne propose plus le lien « Planning - 1 » quand les
  deux variantes sont exposées : « Planning - 2 » porte la navigation,
  et Planning - 1 reste accessible via sa bascule depuis Planning - 2
  et son URL directe (?psc_tab=cantine). Variante seule : le lien
  « Planning » revient dans le menu comme avant. Le lien « Déclarer un
  jour » du tableau de bord pointe toujours Planning - 1.

= 5.0.9 =
* Tests — le scénario « commande fournisseur » codait en dur le nom du
  conteneur Docker au lieu de lire PSC_WP_CONTAINER (posé par le workflow
  après identification du conteneur) : sur CI, le projet compose s'appelle
  différemment du poste de développement, et TOUTE la commande de seed
  échouait (« no such container »), faisant échouer le scénario et son
  suivant. Comme les autres specs, le nom vient désormais de
  l'environnement avec repli local.
* CI — diagnostic : les artefacts E2E sont de nouveau téléchargeables
  (upload-artifact@v4 exclut par défaut les dossiers cachés comme
  .playwright/) et un résumé liste les scénarios en échec en fin de step.

= 5.0.8 =
* Tests — commande fournisseur : le scénario du pied de mail n'asserte plus
  le nom du site ({{site}} interpolé avec le titre de l'installation, qui
  diffère entre la CI et le poste de développement) mais des variables
  déterministes ; le seed réinitialise le modèle « Commande fournisseur »
  avant chaque exécution — la personnalisation laissée par une exécution
  précédente ne pollue plus l'assertion du pied par défaut.

= 5.0.7 =
* Backoffice — commande fournisseur : le bouton « Envoyer au fournisseur »
  ouvre désormais une popin de confirmation qui affiche l'e-mail EXACT qui
  partira (sujet + rendu autonome dans une iframe), avant tout envoi.
  « Retour » referme sans rien envoyer ; « Confirmer l'envoi » soumet.
  Le rendu est calculé côté serveur à l'affichage de la page (même code
  que l'envoi et l'archive), la popin remplace l'ancien confirm()
  natif — plus de confirmation aveugle.

= 5.0.6 =
* Refonte de l'e-mail de commande fournisseur (maquette Email Commande
  Fournisseur.dc) : un seul tableau, une ligne par jour de service,
  ventilation par régime — Standard / Sans porc / Végétarien (les trois
  mutuellement exclusifs, chaque enfant inscrit au déjeuner compte pour
  un seul) — chapeau « Repas de midi » couvrant les quatre colonnes,
  Total midi accentué, goûters détachés, ligne Total semaine. Plus aucun
  découpage par classe : le fournisseur livre pour l'établissement. Les
  jours sans inscrit affichent 0, pas un tiret.
* Mise en forme e-mail allégée : carte blanche 600px sur fond sable,
  CSS inline, tableaux imbriqués, attributs align — Outlook Windows,
  Gmail web et Apple Mail. Le libellé « sans viande » (famille) devient
  « Végétarien » à la génération (vocabulaire fournisseur).
* Pied de mail configurable sur le modèle « Commande fournisseur » :
  nouveau champ dans Modèles e-mails, variables {{site}} {{semaine}}
  {{total}} {{gouters}} {{standard}} {{sans_porc}} {{vegetarien}},
  vide → le bloc et son filet ne sont pas rendus.
* Variables du sujet et du corps enrichies : {{standard}}, {{sans_porc}},
  {{vegetarien}} rejoignent {{site}} {{semaine}} {{total}} {{gouters}}.

= 5.0.5 =
* Commande fournisseur : l'e-mail emporte désormais aussi les GOÛTERS —
  une seconde grille classe × jour sous celle des repas, servie aux
  enfants attendus à la garderie du soir (déclaration directe ou forfait
  réalisable, cf. filtre psc_gouter_services). Les enfants porteurs d'une
  allergie alimentaire apportent aussi leur goûter : exclus des deux
  comptages, maintenus sur les listes de présence. L'aperçu backoffice,
  le bouton d'envoi (X repas + Y goûters) et l'e-mail archivé suivent ;
  le gabarit « Commande fournisseur » gagne la variable {{gouters}}.
* Suite E2E commande fournisseur : jeu de données étendu (garderie du
  soir pour les deux enfants) et assertions goûters (aperçu, e-mail,
  historique).

= 5.0.4 =
* Performance — Planning - 2 : un clic dans « Étape 1 » lançait plusieurs
  centaines de requêtes SQL identiques (la configuration de l'année était
  relue en base pour chaque enfant × date × prestation) — d'où la latence
  perçue, proportionnelle au ping de la base. La configuration d'année, ses
  plages de vacances, les fermetures du calendrier (importées et manuelles)
  et les fermetures par prestation sont désormais résolues en mémoire par
  requête (il n'existe qu'une à trois lignes de configuration) ; les
  calculs par date (clé d'année, jour de semaine) sont remontés hors des
  boucles enfant × prestation ; le calendrier de toute l'année étendue aux
  bornes de ses mois est préchargé en 2 requêtes avant le re-rendu.
  Mesuré : ≈ 900 requêtes par clic → ≈ 45, la latency serveur retombe à
  quelques dizaines de millisecondes sur une base distante. Aucun
  changement de comportement : les mêmes caches sont vidés après chaque
  écriture (mairie, migration, tests), l'invariant et les tests E2E
  (rendu + base) restent identiques.

= 5.0.3 =
* Inscription initiale : le bloc « Rythme habituel prévu » disparaît de
  l'étape Enfants — le rythme se déclare depuis l'espace famille, où il est
  modifiable toute l'année (et une saisie à l'inscription aurait figé des
  choix encore approximatifs au moment de la demande).
* Autocomplétion d'adresse : la sélection d'une ligne remplit désormais des
  champs code postal et ville VISIBLES (lecture seule en mode recherche —
  ils sont déduits de la ligne retenue ; la bascule vers la saisie
  manuelle les rend éditables). Ils étaient remplis mais masqués, donnant
  l'impression que la completion « ne remplissait rien ».
* Planning - 2 : le message « N jour(s) déjà transmis à la cantine ont été
  figés » disparaît — le figeage des jours verrouillés est un comportement
  attendu (ces jours sont grisés comme non modifiables), une notice ici
  n'était jamais comprise.
* Planning - 2 : « Appliquer ce rythme à toute la fratrie » n'apparaît que
  si la famille compte au moins deux enfants.

= 5.0.2 =
* Correction — Planning - 2 fonctionnel : la grille du rythme ne se mettait
  JAMAIS à jour après un clic (l'état renvoyé par l'AJAX portait un niveau
  d'imbrication que le client ne lisait pas) — cocher, décocher, copier vers
  la fratrie semblaient sans effet à l'écran alors que la base écrivait.
* Correction : « Tout / Aucun » d'une colonne effaçait le tableau au
  re-rendu (le mois renvoyé par le lot était vide — mauvaise clé de tableau
  côté serveur).
* Correction : sur Planning - 1, un retrait exceptionnel laissait la case
  cochée alors que l'état effectif était faux — l'affichage suit désormais
  la résolution (l'exception gagne, dans un sens comme dans l'autre).
* Correction : le rythme habituel n'exige plus l'assurance scolaire (il est
  posé dès l'inscription sans cette exigence) ; l'assurance ne bloque que
  l'AJOUT NET d'une exception — un retrait et le retour au rythme passent
  toujours, l'invariant ne peut plus laisser de lignes résiduelles.
* Tests : nouvelle suite E2E durcie tests/planning-2.spec.ts (9 scénarios) —
  chaque interaction vérifie le rendu ET l'état en base (invariant « cocher
  puis décocher ne laisse aucune ligne », copie fratrie, Tout/Aucun,
  revenir au rythme, cohérence Planning - 1 ↔ Planning - 2, cas enfant sans
  assurance) ; seed dédiée bin/seed-planning-2.php.

= 5.0.1 =
* Correction : cliquer « Planning - 2 » depuis le menu affichait « Aucun
  enfant n'est encore rattaché » alors que le foyer en a — la section était
  rendue à chaque chargement (la navigation d'onglets bascule localement,
  sans recharger la page) mais ses données n'étaient peuplées que lorsque
  l'onglet était actif. Les deux écrans Planning (et leurs sections) sont
  désormais peuplés dès que leur variante est exposée.
* Correction : sur Planning - 1, cocher Cantine puis Garderie Matin
  retirait la Cantine (cascade de l'ancien modèle appliquée à tort entre
  prestations compatibles) — la mutualisation ne concerne que le forfait,
  qui recouvre les trois prestations.
* Correction : les totaux du bandeau fratrie et de l'enfant suivaient
  désormais l'état serveur après chaque enregistrement (réalignement suite
  au réaffichage des cases).
* Tests : scénarios E2E et seeds mis à jour au modèle v5 (parcours parent,
  SIDSCM — la seed déclare explicitement la semaine courante, commande
  fournisseur — vérifications via la résolution, plus l'ancienne table).

= 5.0.0 =
* NOUVEAU MODÈLE — Fin du trimestre : tout le portail passe à l'année scolaire
  (school_year '2026-2027'). Tables de configuration administrables
  psc_school_year (dates, plages de vacances, délai de modification) et
  psc_holidays (jours fériés à exclure, pré-remplis). Les jours d'école sont
  CALCULÉS, jamais stockés : lundi, mardi, jeudi, vendredi, moins vacances et
  fériés.
* NOUVEAU MODÈLE — Déclaration « rythme habituel + exceptions » : une ligne
  de psc_pattern = « cet enfant mange à la cantine tous les mardis de
  l'année » (max 16 par enfant) ; psc_exception porte les écarts ponctuels
  (ajout ou retrait). Source de vérité unique psc_is_declared() : facturation,
  listes intervenants, effectifs cantine et exports mairie passent tous par
  elle. Invariant en écriture : jamais d'exception dont la valeur égale le
  rythme — cocher puis décocher supprime la ligne.
* Verrou 48 h sur les deux écritures : exception refusée côté serveur à moins
  de 48 h ; un changement de rythme matérialise les jours déjà verrouillés en
  exceptions figées (il ne repropage jamais rétroactivement sur le mardi déjà
  transmis à la cantine).
* Migration idempotente depuis l'ancienne table (seuil ≥ 60 % par jour de
  semaine), test bloquant inclus (bin/verify-planning-migration.php) :
  psc_is_declared() doit renvoyer exactement le même résultat que l'ancienne
  table sur toutes les lignes historiques. L'ancienne table reste en lecture
  seule le temps d'un cycle de facturation.
* Écran Planning — deux variantes livrées en parallèle pour que la mairie
  tranche sur pièces, un réglage (Réglages > Périscolaire) permet de n'exposer
  que l'une sans redéploiement. Les deux lisent et écrivent le même modèle :
  une saisie faite dans l'une se retrouve dans l'autre.
  - Planning - 1 : saisie jour par jour, navigation mois par mois, récap
    fratrie du mois au-dessus des tableaux.
  - Planning - 2 : frise de l'année (onze mois, contrôle de complétude),
    onglets enfants, rythme habituel + « Appliquer ce rythme à toute la
    fratrie », exceptions du mois avec origine lisible (issu du rythme, ajout,
    retrait, verrouillé), récapitulatif fratrie mois et année.
* Enregistrement automatique AJAX (psc_toggle_pattern, psc_toggle_exception,
  psc_apply_pattern_to_siblings, psc_reset_month_exceptions, psc_load_month) :
  nonce + vérification serveur de l'appartenance de l'enfant au foyer sur
  chaque point d'entrée ; seul le mois affiché est rendu, la frise lit un
  compteur groupé.
* Champ enfant « Allergies alimentaires » : champ libre strictement
  alimentaire, facultatif, 1 000 caractères max, échappé à l'affichage.
  Saisie à l'inscription (étape Enfants, y compris lignes ajoutées
  dynamiquement), dans « Mes enfants » (ajout et édition). La mairie est
  alertée à l'enregistrement d'une allergie non vide pour déclencher la prise
  de contact PAI. Restitutions : colonne dédiée dans « Mes enfants », remontée
  en tête de la liste cantine intervenants (mention « apporte son repas — à ne
  pas compter dans les couverts »), colonne allergies dans l'export effectifs
  cantine, exclusion de ces enfants du comptage des repas commandés (ils
  restent sur les listes de présence).
* Inscription initiale : l'étape « Enfants » gagne la saisie du rythme
  habituel — la famille arrive avec une année pré-remplie.
* Admin mairie : gestion de l'année scolaire du planning (dates, plages de
  vacances, fériés, préavis) sur l'écran Années scolaires ; écran Présences
  déclarées refondu (mois par mois, corrections sans verrou) ; export CSV des
  déclarations par mois ; calendrier v2 recalculé.
* E-mails : « Valider et recevoir mon planning » envoie un récapitulatif
  annuel (rythme par enfant, écarts à venir, estimation annuelle) ; libellés
  ramenés de « trimestre » à « année scolaire », règlement intérieur inclus.

= 4.38.1 =
* Correction : les confirmations de gestion des personnes autorisées
  (ajout, modification, retrait) s'affichent désormais en popin fermable
  (petite croix) qui disparaît d'elle-même, au lieu d'un bandeau restant
  affiché en permanence.

= 4.38.0 =
* Portail familles : le menu « Cantine & Garderie » devient « Planning »
  (titre de la page : « Planning cantine & garderie »).
* Nouvel onglet « Habilitations » : la liste des personnes autorisées à
  récupérer les enfants à la garderie du soir y est gérée en un seul
  endroit, à la place de « Mes enfants » et « Mon profil ».
* « Ajouter une personne » déclare désormais la personne pour tous les
  enfants du foyer d'un coup ; la modification et le retrait restent
  possibles ligne par ligne, avec le même historique consultable par la
  mairie.

= 4.37.0 =
* Portail familles : la vue connectée adopte la maquette Family Portal v2 —
  la bascule « Espace familles / Espace intervenants » s'affiche en haut à
  droite de la colonne de contenu et le bandeau « Service périscolaire »
  disparaît une fois connectée (il reste la vitrine visiteur, ramenée à un
  fût de 920px).
* Mise en page : la sidebar bleue descend en continu jusqu'au pied de page
  (fini la hauteur figée et la barre collante), le portail démarre juste
  sous l'en-tête du site et il n'y a plus de défilement au-delà du contenu.

= 4.36.0 =
* Architecture : la classe monolithique Psc_Frontend (49 méthodes, espace
  famille, inscription invité et vues publiques confondus) est décomposée
  sur le modèle de l'administration — un socle commun, un concentrateur et
  sept classes par domaine (tableau de bord, enfants, inscriptions,
  profil, personnes autorisées, réinscription, documents/menus). Aucun
  changement de comportement, même shortcode, mêmes routes AJAX.
* Internationalisation : tout le texte visible passe désormais par le
  Text Domain « periscolaire-registration », jusqu'ici déclaré sans jamais
  être utilisé — templates (espaces famille/invité, backoffice, e-mails,
  espace intervenants), messages des classes et libellés servis au
  JavaScript. Le plugin est prêt pour une traduction sans retoucher le
  code ; l'affichage français reste strictement identique.

= 4.35.0 =
* Fiabilité : l'approbation d'une demande d'inscription (création du foyer,
  des enfants et de l'année scolaire) s'exécute dans une transaction SQL —
  un échec au milieu ne laisse plus de foyer à moitié créé.
* Données : les contraintes SQL refusées par certains hébergeurs sont
  signalées en administration (santé du schéma) et retentées à la mise à
  jour suivante ; une montée de version bloquée ne s'affiche plus comme un
  succès.
* Pointage : le pointage des intervenants refuse un jour non ouvert ou un
  enfant non inscrit au service visé (les données fausses n'entraient plus
  dans les statistiques) ; chaque téléchargement de justificatif ou de
  facture est journalisé dans le répertoire privé (qui, quoi, quand).
* Accessibilité : les cinq popins du plugin ont la sémantique de dialogue
  complète (rôle dialog, aria-modal, focus piégé, fermeture par Échap).
* Qualité : analyse statique PHPStan niveau 3 sur tout le code (zéro
  erreur) ; tests unitaires des validations IBAN/BIC et du chiffrement
  bancaire, rejoués en intégration continue de PHP 7.4 à 8.3 ; scénario
  E2E de l'écran des intervenants (déverrouillage, pointage, départ) et
  scénario de migration depuis le schéma 2.4 en CI — ce dernier a permis
  de corriger une mise à jour « par bonds » qui perdait une colonne des
  factures.
* Outillage : notices d'administration factorisées dans un helper unique
  (la classe des avertissements des écrans menus, factures et commande
  fournisseur est corrigée au passage) ; ESLint minimal sur les écrans.

= 4.34.0 =
* Sécurité : le code d'accès de l'écran des intervenants (SIDSCM) ne se
  brute-force plus — limitation de fréquence sur les quatre appels, mauvais
  codes plafonnés à 20 par heure et par adresse IP ; les chemins des
  justificatifs sont contraints au répertoire privé (traversée de chemin et
  liens symboliques rejetés).
* Performance : la commande fournisseur compte la cantine en une seule
  requête ; l'état du calendrier scolaire est mis en cache le temps d'une
  requête.
* Accessibilité : les totaux (jours déclarés, repas pointés) sont annoncés
  aux lecteurs d'écran ; le mouvement réduit du système est respecté ; les
  contrastes des textes dorés sont conformes.
* Outillage : les vérifications PHP tournent dans l'intégration continue ;
  l'archive de release est fumée dans un WordPress vierge avant publication
  et la liste de ses fichiers est vérifiée contre le dépôt ; les runs
  d'intégration continue périmés sont annulés.

= 4.33.0 =
* Le lien de connexion résiste aux scanners d'URL (l'exposition du jeton
  dans l'URL, documentée en 4.31.0, est désormais atténuée).

= 4.32.1 =
* Regroupe « Sans porc » et « Sans viande » sous un libellé unique
  « Régime alimentaire » sur les cases de régime.
* Tests : l'assertion du total de repas ne dépend plus de la date d'exécution.

= 4.32.0 =
* Revue de conception, sans changement fonctionnel : l'administration est
  éclatée en une classe par domaine, les helpers découpés par domaine, la
  composition du trimestre et les assurances ont chacune leur classe, et une
  seule mécanique d'appel AJAX sert les quatre écrans.

= 4.31.0 =
* Audit de sécurité : jetons anti-CSRF liés à la famille connectée, exposition
  des justificatifs corrigée sans accès serveur, la déconnexion invalide
  réellement la session, limitation de fréquence fiabilisée (fenêtre fixe et
  adresse IP), nom de l'enfant échappé dans les e-mails de planning.
* Revue du modèle de données : clés étrangères et index par date, un seul
  point de contrôle pour les prestations, cohérence du trimestre.
* Performance : le calendrier n'interroge plus la base jour par jour.

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
