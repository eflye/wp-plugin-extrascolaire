=== Périscolaire - Inscriptions ===
Contributors: mairie
Tags: périscolaire, mairie, inscription, cantine, garderie
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 5.0.0
License: GPLv2 or later

== Description ==

Remplace le fichier calendrier rempli à la main pour l'inscription aux services
périscolaires (Garderie Matin, Cantine, Garderie Soir, Forfait journée).

Une famille non connue de la mairie dépose une demande d'inscription en ligne
(règlement intérieur, justificatif d'assurance scolaire par enfant et, si
elle règle par prélèvement, un mandat SEPA). Une fois la demande validée par
la mairie, la famille se connecte sans mot de passe (lien reçu par e-mail) à
son espace personnel : elle y déclare le RYTHME HABITUEL de chacun de ses
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
présences, justificatifs d'assurance) et des coordonnées bancaires. Ces
données doivent être conservées le temps nécessaire à la gestion du service
et à la facturation, puis supprimées.

Par défaut, supprimer le plugin ne détruit PAS les données, afin d'éviter
une perte irréversible lors d'une manipulation involontaire. Pour que la
suppression du plugin efface réellement les données, ajouter dans
wp-config.php avant de supprimer le plugin :

    define('PSC_REMOVE_DATA_ON_UNINSTALL', true);

L'effacement porte alors sur la totalité : toutes les tables du plugin,
toutes ses options et transients, ainsi que les fichiers déposés par les
familles (justificatifs d'assurance, factures PDF). Les tables et options
de WordPress lui-même ne sont jamais touchées.

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
