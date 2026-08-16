# Périscolaire — Inscriptions

Plugin WordPress pour la gestion complète des services périscolaires municipaux : garderie matin, cantine, garderie soir, forfait journée, menus de cantine, commande fournisseur, calendrier scolaire et facturation.

Remplace les fichiers papier/Excel remplis à la main par un site accessible aux familles, avec un backoffice centralisé pour la mairie.

> **Document de travail.** Ce README couvre l'ensemble des fonctionnalités actuelles, façade famille et façade mairie, pour faciliter la relecture et la remontée de retours. Voir [Points ouverts / à valider](#points-ouverts--à-valider) en fin de document.

---

## En un coup d'œil

*Pour qui découvre le plugin : ce qu'il fait, en une vingtaine de lignes. Le détail de chaque point suit plus bas dans le document.*

**Côté familles**
- Inscription en ligne en 4 étapes (coordonnées, enfants, paiement, règlement intérieur), avec justificatif d'assurance scolaire par enfant et déclaration des personnes autorisées à venir le récupérer.
- Connexion **sans mot de passe** : un lien reçu par e-mail suffit.
- **Mon Espace Famille** : calendrier de présence par enfant et par jour (mis à jour immédiatement), annulation rapide de prestations déjà déclarées, suivi des factures, menu de la semaine, gestion du profil et des enfants, réinscription annuelle encadrée par une fenêtre dédiée.
- Menu de cantine consultable publiquement, sans connexion.

**Côté mairie**
- Backoffice complet (menu **Périscolaire**) : tableau de bord, demandes d'inscription, familles, enfants, factures, menus, commande fournisseur, modèles d'e-mails, réglages.
- Modération des demandes manuelle par défaut, avec une option de **validation automatique**.
- **Années scolaires** avec passage d'année assisté (classe suivante proposée automatiquement, modifiable avant confirmation) et campagne de réinscription.
- **Calendrier scolaire zone C** importé automatiquement (vacances, jours fériés), avec corrections manuelles ponctuelles.
- **Facturation mensuelle** : PDF généré en un clic à partir des inscriptions réellement déclarées.
- Historique inviolable des personnes autorisées à récupérer un enfant (ajouts/modifications/retraits tracés, jamais supprimés).

**Sur le terrain**
- Écran **Listes intervenantes SIDSCM** : qui est attendu aujourd'hui par service (garderie matin, cantine, garderie soir), pointage réel de présence et heure de départ — accès par simple code, sans compte WordPress.

---

## Sommaire

- [En un coup d'œil](#en-un-coup-dœil)
- [Vue d'ensemble](#vue-densemble)
- [Côté familles](#côté-familles)
- [Côté mairie](#côté-mairie-backoffice)
- [Listes intervenantes SIDSCM](#listes-intervenantes-sidscm)
- [Règles métier](#règles-métier)
- [Sécurité](#sécurité)
- [Conformité RGPD](#conformité-rgpd)
- [Installation](#installation)
- [Configuration](#configuration)
- [Développement local](#développement-local)
- [Structure du projet](#structure-du-projet)
- [Points ouverts / à valider](#points-ouverts--à-valider)

---

## Vue d'ensemble

Une famille dépose une demande d'inscription en ligne (avec acceptation du règlement intérieur, justificatif d'assurance scolaire par enfant et, si elle règle par prélèvement, un mandat SEPA). Une fois validée par la mairie, elle se connecte sans mot de passe (lien reçu par e-mail) pour accéder à **« Mon Espace Famille »** : déclarer jour par jour les prestations souhaitées pour chacun de ses enfants, suivre ses factures, gérer ses enfants et son profil. La mairie pilote tout depuis un menu **Périscolaire** dédié dans l'administration WordPress : tableau de bord, calendrier scolaire, trimestres, demandes, familles, enfants, factures, menus de cantine, commande fournisseur, modèles d'e-mails, réglages.

Aucun compte WordPress n'est nécessaire côté famille. Aucun paiement en ligne n'est intégré : le mode de paiement (chèque/espèces ou prélèvement SEPA) est déclaré à l'inscription, mais les prélèvements réels restent traités par la mairie via sa banque.

---

## Côté familles

### 1. Première inscription

Formulaire public (« Première inscription ? »), présenté comme un parcours en 4 étapes annoncées au parent (« Coordonnées », « Enfants », « Paiement », « Règlement ») — impossible de passer à l'étape suivante tant que l'étape en cours n'est pas complète (validation native du navigateur **et** revalidation côté serveur, pour parer un envoi direct qui contournerait le formulaire) :

1. **Coordonnées** — e-mail, prénom, nom, téléphone, adresse, code postal, ville : tous obligatoires.
2. **Enfants** — jusqu'à **5 enfants par demande** (prénom, nom, classe, date de naissance, régime alimentaire — sans porc et/ou sans viande — tous obligatoires pour chaque ligne renseignée), avec pour chacun un **justificatif d'assurance scolaire obligatoire** (PDF, JPG ou PNG, 1 Mo maximum) et, facultativement, une ou plusieurs **personnes autorisées à le récupérer** (prénom, nom, téléphone, lien avec l'enfant, indicateur pièce d'identité) — saisies dès cette étape mais modifiables ensuite à tout moment depuis « Mes enfants ».
3. **Paiement** — chèque/espèces (par défaut) ou prélèvement automatique SEPA. En sélectionnant le prélèvement, un bloc supplémentaire apparaît :
   - Créancier affiché automatiquement (nom de la commune + identifiant créancier SEPA, définis dans les réglages) ;
   - Mandat : titulaire du compte, adresse (recopiable en un clic depuis l'adresse familiale), IBAN, BIC ;
   - Règlement concernant le prélèvement (texte intégral) + case à cocher d'approbation obligatoire.
4. **Règlement intérieur** — texte intégral affiché dans le formulaire (horaires, engagement trimestriel, facturation, discipline, responsabilité...), avec une case à cocher obligatoire d'approbation. *Acceptation = case cochée, horodatée en base — ce n'est pas une signature électronique qualifiée.*

L'IBAN est validé par clé de contrôle (norme ISO 7064 mod-97, comme un vrai IBAN) et le BIC par son format ; toute valeur invalide est rejetée **côté serveur**, indépendamment de la validation du navigateur.

Un champ piège invisible (honeypot) et une double limitation de fréquence (par IP et par e-mail) protègent le formulaire contre les robots.

### 2. Confirmation d'adresse puis modération

Le parent reçoit un e-mail de confirmation (lien valable 3 jours). Tant qu'il n'a pas cliqué, la demande **n'apparaît pas** dans le backoffice — ceci empêche un robot de remplir la file de modération avec des adresses inventées. Une fois confirmée, la mairie est notifiée et examine la demande (voir [Demandes d'inscription](#demandes-dinscription)). À la validation, la famille et ses enfants sont créés automatiquement, y compris le mode de paiement et — si applicable — les informations du mandat SEPA (avec une référence de mandat générée automatiquement) ; un **PDF du mandat de prélèvement SEPA** est alors généré et joint à l'e-mail envoyé au parent (document éphémère, jamais stocké sur le serveur puisqu'il contient un IBAN en clair — supprimé aussitôt après l'envoi) ; le parent reçoit aussitôt son lien d'accès.

### 3. Connexion sans mot de passe

Une fois enregistrée par la mairie, la famille saisit son e-mail sur la page publique et reçoit un lien de connexion à usage unique (valable 30 minutes). Aucun mot de passe à créer ni à retenir. La session ouverte dure 12 heures (cookie signé, non modifiable côté client).

### 4. Mon Espace Famille

Une fois connectée, la famille arrive sur un portail à onglets (barre latérale) :

#### Tableau de bord

Vue d'ensemble : jours et montant déclarés pour la période en cours, prochaine facture (montant/statut), menu de cantine de la semaine, résumé « Mes enfants ». Trois raccourcis : *Déclarer un jour*, *Ajouter un enfant*, et **Annulation prestations**.

**Annulation prestations** — popin accessible depuis le tableau de bord pour annuler rapidement une ou plusieurs prestations déjà déclarées, **sans naviguer jusqu'au calendrier**. La liste proposée est par **prestation** et non par jour : un forfait journée est affiché comme 3 lignes (Garderie matin / Cantine / Garderie soir) pour la lisibilité, mais reste indivisible — cocher n'importe laquelle des trois annule le forfait entier. Plusieurs prestations, sur plusieurs jours, peuvent être cochées et annulées en une seule confirmation ; la mairie reçoit un e-mail récapitulatif par jour concerné. Seules les prestations encore modifiables (délai de préavis non dépassé) sont proposées.

#### Cantine & Garderie

Calendrier présenté mois par mois (accordéon), un bloc par enfant. Chaque case cochée (Garderie Matin / Cantine / Garderie Soir / Forfait journée — l'en-tête de chaque colonne porte une infobulle rappelant le nom complet de la prestation) est enregistrée **immédiatement** en AJAX — pas de bouton « Envoyer » à chercher, pas de fichier à renvoyer par e-mail. Les jours fermés (week-ends, mercredis, vacances scolaires, jours fériés) n'apparaissent pas dans la grille. Le total (jours + montant) se recalcule en direct par enfant et par mois.

Un verrou de modification (48 h par défaut, réglable) grise les cases trop proches de la date concernée — contrôlé aussi côté serveur, pas seulement par l'affichage grisé. L'absence de justificatif d'assurance à jour bloque l'**ajout** d'un nouveau jour pour l'enfant concerné (une case déjà cochée reste décochable : pas de blocage rétroactif).

Un bouton « Valider et recevoir mon planning » envoie à la demande un récapitulatif complet par e-mail (jours, prestations, totaux par service, montant indicatif — la facturation définitive reste de la responsabilité de la mairie).

#### Menu de la semaine

Même contenu que le widget public (voir [Menu de cantine — accès libre](#menu-de-cantine--accès-libre)), mais dans le portail connecté, avec la même navigation semaine précédente/suivante.

#### Mes enfants

Tableau **en lecture seule** — Prénom, Nom, Classe, Naissance, Régime (badge, ou « — » si aucun), Actif (badge) — avec un bouton **Modifier** par ligne ouvrant une popin pour corriger une faute de frappe sur le prénom, le nom ou la date de naissance d'un enfant déjà onboardé.

En dessous, le panneau **Assurance scolaire [année scolaire en cours]** liste chaque enfant actif avec son statut (badge « Fournie » avec date + lien de consultation, ou « Manquante ») et un bouton *Remplacer*/*Ajouter* ouvrant une popin d'envoi de fichier (PDF, JPG ou PNG, 1 Mo maximum).

Puis le panneau **Personnes autorisées à récupérer les enfants** : par enfant actif, la liste des personnes pouvant venir le chercher en fin de garderie (prénom, nom, téléphone, lien avec l'enfant — champ libre avec suggestions, indicateur « présentera une pièce d'identité »), avec **Modifier**/**Retirer** par ligne et un bouton *Ajouter une personne*. Le retrait est un retrait de la liste, pas une suppression : toute modification (ajout, modification, retrait) est conservée dans un historique consultable par la mairie, jamais par la famille elle-même. Un responsable ne voit et ne modifie que la liste de ses propres enfants.

Enfin, le panneau **Ajouter un enfant** permet de rattacher un nouvel enfant à la famille (prénom, nom, classe, date de naissance, régime, justificatif d'assurance obligatoire dès la création), dans la limite d'un nombre maximum configurable (10 par défaut). Un enfant marqué **sorti** par la mairie n'apparaît plus dans le calendrier ni dans le planning, mais reste visible ici et garde tout son historique d'inscriptions passées.

#### Réinscription (onglet visible seulement pendant la campagne)

Onglet affiché uniquement pendant la fenêtre de réinscription ouverte par la mairie (voir [Années scolaires](#années-scolaires)) — invisible le reste de l'année. Pour chaque enfant actif : classe actuelle et classe proposée pour l'année suivante (déduite de la table de correspondance des classes), une case à cocher pour confirmer ou retirer l'enfant, un nouveau justificatif d'assurance à déposer pour chaque enfant confirmé, et une acceptation du règlement intérieur valable pour toute la famille. Un enfant décoché n'est pas sorti pour autant : il n'est simplement pas réinscrit pour l'année suivante, et reste modifiable jusqu'à la fermeture de la fenêtre.

#### Mes factures

Liste des factures mensuelles de la famille (mois, montant, statut envoyée/en attente, téléchargement du PDF).

#### Mon profil

État civil (prénom, nom), coordonnées (téléphone mobile/fixe, e-mail) et adresse du foyer. Un changement d'adresse e-mail nécessite une **confirmation par lien envoyé sur la nouvelle adresse** avant de prendre effet (annulable tant qu'il est en attente) ; l'ancienne adresse reste active jusque-là. La fiche de chaque enfant se modifie depuis « Mes enfants », pas ici.

#### Documents

Téléchargement du règlement intérieur et du règlement concernant le prélèvement automatique, au format PDF (mis en ligne par la mairie depuis Réglages) — message explicite si un document n'a pas encore été déposé.

### 5. Menu de cantine — accès libre

Un widget affiché sur la page publique, **sans connexion requise**, présente le menu de la semaine (lundi/mardi/jeudi/vendredi — pas de menu le mercredi, pas de cantine ce jour-là). Navigation semaine précédente/suivante façon calendrier, avec retour rapide à la semaine en cours. Pendant les vacances scolaires ou tout autre jour sans école, le widget affiche « Pas d'école cette semaine » à la place du menu.

---

## Côté mairie (backoffice)

Menu **Périscolaire** dans l'administration WordPress, avec treize sections :

### Tableau de bord

Page d'accueil du backoffice : nombre de familles actives, d'enfants actifs, trimestre actif (et sa date de fin), et une liste **« À faire »** signalant en un coup d'œil les demandes d'inscription en attente, le statut du menu de cantine de la semaine prochaine (saisi / envoyé / pas encore saisi) et celui de la commande fournisseur correspondante — chaque ligne renvoie directement vers l'écran concerné.

### Inscriptions

Vue de correction : sélection d'une famille et d'une période, grille identique à celle vue par le parent, modifiable directement par la mairie (utile pour une inscription reçue par téléphone ou papier). Chaque enregistrement envoie une notification à la famille. Export CSV du récapitulatif par trimestre (protégé contre l'injection de formules Excel).

### Demandes d'inscription

File de modération des nouvelles familles (voir [Première inscription](#1-première-inscription)). Pour chaque demande en attente : coordonnées déclarées (prénom, nom, etc.), statut d'acceptation du règlement intérieur, mode de paiement choisi et — si prélèvement — titulaire, adresse, **IBAN partiellement masqué** (`FR14 •••• •••• 2606`), BIC et statut d'acceptation du règlement de prélèvement. Les nom/prénom/classe des enfants sont modifiables avant validation (informations déclaratives, à vérifier). Refus possible avec motif optionnel, notifiable ou non au demandeur.

**Validation automatique** (Réglages > Demandes d'inscription, désactivée par défaut) : si activée, une famille accède directement à son espace dès qu'elle confirme son adresse e-mail — la demande n'apparaît alors jamais dans cette file, aucune information saisie n'est relue par la mairie avant l'ouverture de l'accès (justificatif d'assurance compris). En cas d'échec (aucun enfant valide déclaré), la demande retombe automatiquement dans le circuit normal de modération.

### Années scolaires

Une année scolaire chapeaute les trimestres et porte, pour chaque enfant, sa classe et son statut d'inscription **de cette année-là** (table `wp_psc_child_school_years`) : la classe n'est plus une valeur unique sur la fiche enfant, elle s'historise année par année. Une seule année peut être **active** à la fois (même principe que les trimestres) ; une année **en préparation** existe le temps de monter le passage d'année ou une campagne de réinscription ; une année **archivée** reste consultable en lecture seule, jamais supprimée.

**Passage d'année** : depuis l'année active, prépare l'année suivante en proposant pour chaque enfant actif sa classe montée d'un niveau, d'après une **table de correspondance configurable dans Réglages** (utile pour une école à classes multi-niveaux, où la progression n'est pas un simple PS→MS→...→CM2). Un écran de récapitulatif liste chaque enfant avec sa classe actuelle et la classe proposée, modifiable ligne par ligne avant confirmation ; les enfants en fin de cycle sont proposés « Sortie » et basculent au statut sorti. Rien n'est écrit en base tant que la mairie n'a pas explicitement confirmé le récapitulatif.

Un enfant peut aussi être marqué **sorti** individuellement à tout moment (déménagement en cours d'année, par exemple), depuis [Enfants](#enfants) — pas seulement via le passage d'année automatique de fin de cycle.

**Réinscription** : pendant la fenêtre configurée dans Réglages, les familles connectées voient un onglet « Réinscription » dédié pour confirmer chaque enfant, déposer un nouveau justificatif d'assurance et réaccepter le règlement intérieur pour l'année suivante (voir [Réinscription](#réinscription-onglet-visible-seulement-pendant-la-campagne)). Aucune relance automatique : la mairie déclenche manuellement, si besoin, un e-mail de rappel aux familles n'ayant pas encore réinscrit leurs enfants.

### Trimestres

Création d'un trimestre (libellé, dates, année scolaire de rattachement) : le calendrier se génère automatiquement, jour par jour, avec fermeture par défaut des week-ends, des mercredis, des jours fériés et des vacances scolaires (zone C, voir [Calendrier scolaire](#calendrier-scolaire)). Un seul trimestre peut être actif à la fois : c'est celui visible par les familles. Un formulaire permet aussi de fermer manuellement une plage de dates (fermeture exceptionnelle non couverte par le calendrier scolaire officiel).

### Calendrier scolaire

Le périscolaire suit le calendrier scolaire officiel de la **zone C** (Créteil, Montpellier, Paris, Toulouse, Versailles), publié par le ministère de l'Éducation nationale — pas d'école, pas de périscolaire, pas de cantine, pas de menu.

- **Chargement automatique** : un bouton télécharge et importe le flux iCal officiel (`education.gouv.fr/vacances`), zone C uniquement, et ferme les jours concernés dans tous les trimestres. Le flux distingue proprement le dernier jour de vacances du jour de reprise (`DTEND` exclusif dans le flux officiel) — un jour de reprise n'est jamais fermé par erreur.
- **Liste lisible** : les jours fermés sont regroupés par période contiguë (ex. « 17/10/2026 → 01/11/2026 — Vacances de la Toussaint — 16 jours — Import officiel »).
- **Correction manuelle exceptionnelle** : un jour peut être fermé (formation des enseignants, fermeture ponctuelle...) ou rouvert à la main. Une correction manuelle n'est **jamais** écrasée par un rechargement ultérieur du calendrier officiel.
- **Fermer un jour où des familles ont déjà déclaré des présences déclenche un écran d'avertissement** (nombre d'inscriptions et de familles concernées, détail par enfant/prestation) avant toute action. Une fois confirmé : les inscriptions concernées sont supprimées (elles ne seront jamais facturées) et chaque famille concernée reçoit un e-mail listant précisément ce qui a été retiré.

### Familles

Liste de toutes les familles, avec mode de paiement et IBAN masqué en un coup d'œil. Édition complète par famille : coordonnées, mode de paiement, informations SEPA (IBAN/BIC revalidés à l'enregistrement), référence de mandat. Envoi ou renvoi du lien de connexion, activation/désactivation d'accès (une famille désactivée perd l'accès immédiatement, même en session ouverte).

### Enfants

Liste de tous les enfants avec leur famille de rattachement, leur régime cantine (sans porc / sans viande), leur classe **pour l'année scolaire sélectionnée** (sélecteur d'année, année active par défaut) et le statut de leur justificatif d'assurance scolaire. Les enfants sortis sont masqués par défaut ; une case « Afficher les enfants sortis » les révèle. Rattachement d'un nouvel enfant à une famille existante (la mairie tient cette liste — les familles ne peuvent pas créer un enfant sans rattachement), et action **Marquer sorti**/**Marquer actif** par ligne.

La progression de classe d'une année sur l'autre se fait via le [passage d'année](#années-scolaires), toujours déclenché manuellement par la mairie — aucune tâche planifiée, aucune bascule automatique en arrière-plan.

Chaque enfant a un bouton **Personnes autorisées**, vers une fiche dédiée (hors menu, non listée dans la navigation) affichant la liste courante des personnes pouvant venir le récupérer en fin de garderie (nom, téléphone, lien, pièce d'identité), et l'**historique complet** des ajouts/modifications/retraits (date, auteur, source famille/mairie). Cette fiche est en **lecture seule** côté mairie : c'est la famille qui gère la liste depuis « Mes enfants » ; la mairie la consulte, notamment pour l'animateur en fin de garderie.

### Menus cantine

Saisie du menu semaine par semaine (lundi/mardi/jeudi/vendredi, pas de mercredi). Chaque semaine reste un brouillon tant qu'elle n'a pas été explicitement envoyée — **aucun envoi automatique, aucune tâche planifiée** : c'est toujours un clic volontaire de la mairie (« Envoyer aux familles ») qui déclenche l'e-mail, adressé à toutes les familles actives ayant au moins un enfant actif. Le même menu alimente aussi le widget public et l'onglet « Menu de la semaine » du portail famille.

### Commande fournisseur

Comptage hebdomadaire du nombre de repas de cantine à prévoir, **par classe**, calculé à partir des inscriptions réellement déclarées (lundi/mardi/jeudi/vendredi). Comme pour les menus, l'envoi au prestataire de restauration par e-mail est **toujours un clic volontaire** de la mairie, jamais automatique. Chaque envoi archive un instantané figé (comptage et e-mail effectivement envoyés à ce moment-là) : l'historique reste exact même si des inscriptions changent après coup.

### Factures

Facturation **mensuelle**. Sélection d'un mois ayant des inscriptions, génération en un clic d'un PDF par famille (tarif par prestation, détail par enfant, total). **Un mois en cours (non terminé) ne peut pas être facturé** — le bouton de génération n'apparaît que pour un mois déjà écoulé, pour éviter une facture incomplète si des inscriptions changent encore. Envoi individuel ou en masse par e-mail, avec pièce jointe PDF. Les PDF sont stockés hors de portée d'accès direct par URL.

### Modèles e-mails

Personnalisation du sujet et du corps de chaque e-mail transactionnel (lien de connexion, récapitulatif, facture, menu, demandes...), avec variables (`{{site}}`, `{{nom}}`, `{{trimestre}}`...) et réinitialisation possible au texte par défaut.

### Réglages

- **Tarifs** par prestation (Garderie Matin, Cantine, Garderie Soir, Forfait journée).
- **Délai de modification** avant chaque jour concerné (0 à 720 h).
- **Notification mairie** — copie de chaque validation de planning parent.
- **Adresse e-mail** de la mairie pour les notifications.
- **Documents PDF** : dépôt du règlement intérieur et du règlement de prélèvement, consultables par les familles dans l'onglet « Documents ».
- **Informations de facturation** : intitulé, adresse, téléphone, fax, e-mail, commune, logos gauche/droit (utilisés dans le PDF de facture), texte de pied de page, **identifiant créancier SEPA (ICS)** affiché aux familles sur le mandat de prélèvement.
- **Table de correspondance des classes** : un sélecteur par classe vers sa classe suivante (ou « Sortie »), utilisée par le [passage d'année](#années-scolaires) — non figée sur PS→MS→...→CM2, adaptable à une école à classes multi-niveaux.
- **Fenêtre de réinscription** : dates d'ouverture/fermeture de la campagne annuelle, qui contrôlent la visibilité de l'onglet « Réinscription » côté famille.
- **Validation automatique des demandes d'inscription** (désactivée par défaut) : voir [Demandes d'inscription](#demandes-dinscription).
- **Page « Accès intervenants » et code d'accès SIDSCM** : voir [Listes intervenantes SIDSCM](#listes-intervenantes-sidscm).

---

## Listes intervenantes SIDSCM

Page publique dédiée pour les intervenants sur le terrain (garderie/cantine) — aucun compte
WordPress à créer. Activée en insérant le shortcode `[periscolaire_sidscm]` sur une page
WordPress (même principe que `[periscolaire_form]`) ; la page prend tout l'écran, sans
l'en-tête/titre habituel du thème, pensée comme un outil de consultation rapide plutôt qu'une
page de contenu. La page à utiliser est configurable dans Réglages (**Page « Accès
intervenants »**) — à défaut, la première page publiée contenant le shortcode est détectée
automatiquement.

**Accès** : protégé par un code unique configuré dans Réglages (« Code d'accès intervenantes
SIDSCM », vide par défaut = accès désactivé pour tout le monde). Volontairement léger — pas de
compte individuel, pas de session WordPress — mais le code est **revérifié côté serveur à
chaque appel** (liste des enfants, pointage) : rien n'est envoyé au navigateur tant qu'il n'a
pas été validé, contrairement à une simple bascule d'affichage côté client. Le code saisi une
fois est retenu par le navigateur (stockage local) pour ne pas le ressaisir à chaque visite ; le
bouton « Verrouiller » l'oublie immédiatement.

**Contenu** : par service (Garderie Matin, Cantine, Garderie Soir) et par jour, la liste des
enfants réellement inscrits (mêmes inscriptions que « Cantine & Garderie » côté famille, aucune
saisie de planning propre à cet écran) — classe, badge allergie/régime **uniquement sur
l'onglet Cantine**, et une case à cocher de présence réelle (26×26 px minimum, pensée pour un
usage tactile). Un enfant est **présent par défaut** tant qu'il n'a pas été explicitement
décoché : l'intervenant ne pointe que les absences plutôt que de cocher toute la liste. Le
pointage est persisté (table `wp_psc_attendance`, horodatée) pour constituer un historique de
présence réel, distinct des inscriptions déclarées.

Deux vues, comme dans « Cantine & Garderie » : **Jour** (liste pointable, compteur « X / Y
présents » mis à jour en direct) et **Semaine** (tableau enfants × jours, lecture seule, point
plein si l'enfant est attendu ce jour-là pour le service actif). Seuls les **jours réellement en
service** apparaissent (lundi/mardi/jeudi/vendredi hors vacances, jours fériés et fermetures
ponctuelles) — jamais le mercredi, jamais un jour fermé, cohérent avec le reste du plugin. Chaque
bouton de jour affiche sa date (ex. « Lundi 17/08 »), calculée dynamiquement à partir du lundi de
la semaine en cours. Toujours la semaine réellement en cours ; pas de navigation vers une autre
semaine sur cet écran (outil du jour même, pas un historique à parcourir — l'historique de
pointage existe en base pour un usage ultérieur, mais rien ne l'affiche encore ici). L'en-tête
rappelle également la date du jour en toutes lettres.

**Heure de départ (Garderie soir)** : sur l'onglet Garderie soir, en vue Jour uniquement, un champ
« Départ » permet de saisir l'heure de départ réelle de chaque enfant. Persistée sur la même ligne
`wp_psc_attendance` (enfant × jour × service) que le pointage de présence, mais sur une colonne
dédiée (`departure_time`) qui ne touche jamais `present` — les deux pointages (présence, départ)
peuvent être saisis indépendamment sans s'écraser l'un l'autre.

---

## Règles métier

- Le périscolaire (garderie et cantine) fonctionne **lundi, mardi, jeudi, vendredi** — jamais le mercredi.
- Le calendrier suit les vacances scolaires de la **zone C**, jours fériés compris.
- Un seul trimestre est actif à la fois ; les trimestres précédents restent consultables.
- Une inscription à une prestation, pour un trimestre, est **ferme et définitive** une fois le délai de modification dépassé.
- La facturation est **mensuelle**, calculée à partir des inscriptions réellement déclarées (jour × enfant × prestation), et ne peut être générée qu'une fois le mois écoulé.
- Aucun remboursement automatique en cas d'absence, hors cas prévus par le règlement intérieur (fermeture, sortie scolaire, hospitalisation, maladie de plus de 3 jours justifiée).
- Un justificatif d'assurance scolaire à jour est requis pour ajouter un nouveau jour de cantine/garderie ; il se renouvelle chaque année scolaire.
- Un forfait journée (GM + Cantine + GS) est une prestation indivisible : impossible d'en annuler une partie seulement.

---

## Sécurité

- Contrôle d'accès systématique : chaque action d'administration vérifie **à la fois** la capacité de l'utilisateur (`manage_options` par défaut) et un nonce WordPress (protection CSRF) — l'un ne remplace pas l'autre.
- Cloisonnement des données : un parent ne peut agir que sur ses propres enfants, contrôlé côté serveur à chaque requête.
- Requêtes SQL préparées (`$wpdb->prepare()`) systématiquement, y compris avec un nombre variable de paramètres.
- Échappement systématique des sorties (`esc_html`/`esc_attr`/`esc_url`).
- Jetons de connexion et de vérification stockés **hachés** (HMAC-SHA256), jamais en clair ; comparaison à temps constant (`hash_equals`).
- Limitation de fréquence (anti-spam / anti-énumération) sur les formulaires publics ; réponse identique qu'une adresse soit connue ou non.
- Champ honeypot sur le formulaire de demande d'inscription.
- Protection contre l'injection de formules CSV sur l'export.
- Sessions familles signées côté serveur (cookie `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS) — aucun mot de passe stocké.
- **IBAN validé par clé de contrôle réelle** (mod-97, ISO 7064), BIC validé par format ; rejet côté serveur indépendant de la validation du navigateur.
- IBAN affiché **masqué** dans les listes du backoffice (seuls le pays et les 4 derniers caractères apparaissent).
- Le PDF du mandat de prélèvement SEPA (contient l'IBAN en clair) n'est **jamais stocké sur le serveur** : généré en fichier temporaire, joint à l'e-mail, puis supprimé immédiatement.
- Justificatifs d'assurance scolaire limités à 1 Mo, formats PDF/JPG/PNG uniquement (vérifiés côté serveur, pas seulement par l'attribut `accept` du champ fichier), stockés hors de portée d'accès direct par URL.
- Fermer un jour du calendrier scolaire avec des inscriptions existantes exige une **confirmation explicite** après avertissement — pas de suppression accidentelle en un clic.

---

## Conformité RGPD

- Suppression automatique des demandes non vérifiées après 7 jours, des demandes traitées après 90 jours (WP-Cron).
- Les données bancaires (IBAN/BIC) ne sont collectées que si la famille choisit le prélèvement, et uniquement dans ce but.
- Aucune donnée n'est envoyée à un tiers : le mandat SEPA est stocké pour un traitement manuel par la mairie via sa banque, il n'y a pas d'intégration bancaire automatisée.
- Suppression des données du plugin à la désinstallation possible via `define('PSC_REMOVE_DATA_ON_UNINSTALL', true);` dans `wp-config.php` (désactivé par défaut, pour éviter une perte accidentelle).
- Le formulaire d'inscription affiche une mention d'information sur le traitement des données — à adapter au registre des traitements de la commune.
- Les coordonnées des **personnes autorisées à récupérer un enfant** sont des données personnelles de tiers (elles n'ont pas de compte) : leur suppression d'un enfant supprimé (bouton « Supprimer » de Périscolaire > Enfants) est déjà implémentée, liste courante et historique compris. **Point ouvert, non tranché ici** : la durée de conservation propre à l'historique `wp_psc_pickup_history` (distincte de celle de la fiche enfant elle-même, puisqu'il documente un fait passé à visée probatoire) reste à décider par la mairie — aucune purge automatisée de ce journal n'est implémentée.

---

## Installation

1. Télécharger le dépôt et placer le dossier dans `wp-content/plugins/`.
2. Activer le plugin depuis **Extensions**.
3. Le menu **Périscolaire** apparaît dans l'administration.
4. Aller dans **Périscolaire > Calendrier scolaire** et cliquer sur « Charger le calendrier officiel ».
5. Créer un premier trimestre dans **Périscolaire > Trimestres**, puis l'activer.
6. Renseigner les informations de facturation (dont l'identifiant créancier SEPA) et déposer les documents PDF (règlement intérieur, règlement de prélèvement) dans **Périscolaire > Réglages**.
7. Créer une page WordPress et y insérer le shortcode `[periscolaire_form]`.
8. Partager l'URL de cette page aux familles.

---

## Configuration

### Capacité d'accès personnalisée

Par défaut, seuls les administrateurs WordPress (`manage_options`) accèdent au backoffice. Pour donner accès à un rôle dédié :

```php
add_filter('psc_manage_capability', fn() => 'gerer_periscolaire');
```

### Nombre maximum d'enfants par famille

Ce plafond s'applique à l'ajout d'enfants **après** l'inscription initiale (depuis « Mes enfants » ou le backoffice) — il vaut **10** par défaut :

```php
add_filter('psc_max_children_per_user', fn() => 5);
```

La demande d'inscription initiale, elle, est limitée à 5 enfants par soumission, valeur fixe non filtrable (protection anti-abus sur un formulaire public).

### Calendrier des vacances scolaires

Le calendrier officiel est chargé depuis le backoffice (voir [Calendrier scolaire](#calendrier-scolaire)). Pour l'ajuster par le code (cas exceptionnel) :

```php
add_filter('psc_zone_c_vacations', function ($vacations) {
    // $vacations est un tableau de [date_debut, date_fin, libellé]
    return $vacations;
});
```

---

## Développement local

Environnement complet via [Podman](https://podman.io/) (ou Docker) :

```bash
podman compose up -d
```

Démarre :
- WordPress sur **http://localhost:8080**
- [Mailpit](https://mailpit.axllent.org/) (capture des e-mails), si démarré avec le profil dédié — voir ci-dessous

### Installation automatique de WordPress

```bash
podman exec <nom-container-wordpress> bash -c "
  wp core install \
    --url='http://localhost:8080' \
    --title='Test Périscolaire' \
    --admin_user='admin' \
    --admin_password='admin' \
    --admin_email='admin@test.local' \
    --allow-root
  wp plugin activate periscolaire-registration --allow-root
"
```

### Capture des e-mails (Mailpit)

En local, Mailpit est derrière un profil compose dédié — il ne démarre pas avec un simple `up -d` :

```bash
podman compose --profile mailpit up -d mailpit
```

Interface sur **http://localhost:8025**. Activé côté WordPress via `mu-plugins/mailpit-smtp.php`, qui redirige tous les `wp_mail()` vers Mailpit **uniquement si** la variable d'environnement `MAILPIT_ENABLED=true` est positionnée pour le conteneur WordPress (déjà le cas dans `docker-compose.yml` local) :

```php
<?php
if (getenv('MAILPIT_ENABLED') !== 'true') return;

add_filter('wp_mail_from', fn() => 'wordpress@periscolaire.local');
add_filter('wp_mail_from_name', fn() => 'WordPress Local');
add_action('phpmailer_init', function ($phpmailer) {
    $phpmailer->isSMTP();
    $phpmailer->Host     = 'mailpit';
    $phpmailer->Port     = 1025;
    $phpmailer->SMTPAuth = false;
});
```

Pour un déploiement de démonstration/production via `docker-compose.prod.yml` (voir [docs/self-hosting-docker.md](docs/self-hosting-docker.md)), Mailpit n'est pas derrière un profil et son port hôte est **8087** (pas 8025).

---

## Structure du projet

```
periscolaire-registration/
├── periscolaire-registration.php   # Point d'entrée du plugin
├── uninstall.php                   # Nettoyage à la désinstallation
├── includes/
│   ├── helpers.php                 # Fonctions utilitaires (dates, IBAN/BIC, sécurité...)
│   ├── class-psc-installer.php     # Création / migration des tables
│   ├── class-psc-admin.php         # Backoffice WordPress (toutes les routes admin_post_*)
│   ├── class-psc-frontend.php      # Portail famille connecté + page publique (menu, login)
│   ├── class-psc-mailer.php        # Tous les e-mails (HTML, layout commun)
│   ├── class-psc-parents.php       # Authentification familles (sans mot de passe)
│   ├── class-psc-requests.php      # Demandes d'inscription (modération)
│   ├── class-psc-invoices.php      # Génération PDF et envoi factures
│   ├── class-psc-sepa-mandate.php  # PDF du mandat de prélèvement SEPA (éphémère)
│   ├── class-psc-menus.php         # Menus de cantine hebdomadaires
│   ├── class-psc-supplier-orders.php  # Commande fournisseur hebdomadaire (repas par classe)
│   ├── class-psc-school-calendar.php  # Calendrier scolaire zone C (import iCal + corrections manuelles)
│   ├── class-psc-email-templates.php  # Modèles d'e-mails personnalisables
│   ├── class-psc-school-years.php  # Années scolaires, passage d'année
│   ├── class-psc-pickup-persons.php   # Personnes autorisées à récupérer un enfant
│   ├── class-psc-sidscm.php        # Écran intervenantes SIDSCM (shortcode + AJAX)
│   └── fpdf/                       # Bibliothèque FPDF (génération PDF)
├── templates/
│   ├── email/layout.php            # Layout HTML commun pour les e-mails
│   ├── admin-*.php                 # Vues backoffice (dont admin-dashboard.php)
│   ├── portal-*.php                # Vues du portail famille connecté (7 onglets + Réinscription, conditionnel)
│   ├── sidscm.php                  # Écran intervenantes SIDSCM (plein écran, code d'accès)
│   └── frontend-*.php, guest-*.php # Vues page publique / visiteur non connecté
└── assets/
    ├── css/                        # Styles admin et frontend/portail
    └── js/                         # Scripts frontend, portail, visiteur
```

### Tables en base de données

| Table | Description |
|---|---|
| `wp_psc_school_years` | Années scolaires (label, dates, statut préparation/active/archivée) |
| `wp_psc_trimestres` | Périodes (trimestres) avec dates de début/fin, rattachées à une année scolaire |
| `wp_psc_calendar_days` | Jours du calendrier (ouvert/fermé + motif) par trimestre |
| `wp_psc_parents` | Comptes familles (état civil, mode de paiement, IBAN/BIC, référence de mandat SEPA) |
| `wp_psc_children` | Enfants rattachés à une famille (état civil, régime cantine, statut actif/sorti) |
| `wp_psc_child_school_years` | Inscription d'un enfant pour une année scolaire donnée : classe, statut, acceptation du règlement, justificatif d'assurance |
| `wp_psc_pickup_persons` | Liste **courante** des personnes autorisées à récupérer un enfant (soft-delete via `statut` actif/retiré, jamais de suppression physique) |
| `wp_psc_pickup_history` | Journal **en ajout seul** des modifications de cette liste (instantané de la personne, auteur, source famille/mairie, date) — jamais modifié ni supprimé par aucun code du plugin |
| `wp_psc_registrations` | Inscriptions jour × enfant × prestation |
| `wp_psc_requests` | Demandes d'inscription (règlement, mode de paiement, mandat SEPA déclarés) |
| `wp_psc_invoices` | Factures mensuelles générées (métadonnées + chemin PDF) |
| `wp_psc_menus` | Menus de cantine hebdomadaires |
| `wp_psc_supplier_orders` | Historique des commandes fournisseur envoyées (comptage figé par semaine) |
| `wp_psc_school_calendar` | Jours fermés zone C (import officiel + corrections manuelles) |
| `wp_psc_attendance` | Pointage réel de présence par l'écran [Listes intervenantes SIDSCM](#listes-intervenantes-sidscm) (enfant × jour × service, horodaté) |

---

## Points ouverts / à valider

Liste non exhaustive de ce qui mérite un retour avant mise en production :

- **Textes légaux** (règlement intérieur, règlement de prélèvement) retranscrits depuis les documents Word fournis par la mairie — à comparer mot pour mot avec les originaux avant publication.
- **Acceptation par case à cocher, pas signature électronique qualifiée** : à valider que ce niveau suffit pour la mairie (le SIDISCM demandait historiquement une signature papier).
- **Aucun export bancaire SEPA (fichier `pain.008`)** : les mandats sont stockés et consultables dans le backoffice, mais la mairie doit encore les saisir manuellement dans son outil bancaire pour lancer les prélèvements.
- **Pas de paiement en ligne** : les tarifs affichés restent indicatifs, la facturation réelle (chèque/espèces/prélèvement bancaire) est gérée hors plugin.
- **WP-Cron** (purge RGPD des demandes) dépend des visites du site — prévoir un cron système sur un site peu fréquenté.
- **Envoi d'e-mails** : le plugin utilise `wp_mail()`. Sans configuration SMTP, les messages partent souvent en indésirables ou pas du tout — à tester en conditions réelles avant ouverture aux familles, le lien de connexion en dépend entièrement.
- **Durée de conservation de `wp_psc_pickup_history`** : cet historique des personnes autorisées à récupérer un enfant est purgé quand l'enfant l'est (bouton Supprimer), mais aucune durée de conservation propre (distincte de la fiche enfant) n'est définie ni appliquée automatiquement — à trancher avec la mairie.

---

## Licence

Ce plugin est distribué sous licence [GNU General Public License v2](LICENSE).

La bibliothèque [FPDF](http://www.fpdf.org/) incluse dans `includes/fpdf/` est distribuée sous licence libre (permission d'utilisation, modification et distribution sans restriction).
