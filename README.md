# Périscolaire — Inscriptions

Plugin WordPress pour la gestion complète des services périscolaires municipaux : garderie matin, cantine, garderie soir, forfait journée, menus de cantine, commande fournisseur, calendrier scolaire et facturation.

Remplace les fichiers papier/Excel remplis à la main par un site accessible aux familles, avec un backoffice centralisé pour la mairie.

> **Document de travail.** Ce README couvre l'ensemble des fonctionnalités actuelles, façade famille et façade mairie, pour faciliter la relecture et la remontée de retours. Voir [Points ouverts / à valider](#points-ouverts--à-valider) en fin de document.

---

## En un coup d'œil

*Pour qui découvre le plugin : ce qu'il fait, en une vingtaine de lignes. Le détail de chaque point suit plus bas dans le document.*

**Côté familles**
- Inscription en ligne en 4 étapes (coordonnées, enfants, paiement, règlement intérieur), avec justificatif d'assurance scolaire par enfant et déclaration des personnes autorisées à venir le récupérer.
- Connexion **sans mot de passe** : un lien reçu par e-mail suffit — le second parent facultatif peut se connecter avec sa propre adresse et accède au même compte.
- **Mon Espace Famille** : calendrier de présence par enfant et par jour (mis à jour immédiatement), annulation rapide de prestations déjà déclarées, suivi des factures, menu de la semaine, gestion du profil et des enfants, réinscription annuelle encadrée par une fenêtre dédiée, popin de découverte en 5 étapes à la première connexion.
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

Une famille dépose une demande d'inscription en ligne (avec acceptation du règlement intérieur, justificatif d'assurance scolaire par enfant et, si elle règle par prélèvement, un mandat SEPA). Une fois validée par la mairie, elle se connecte sans mot de passe (lien reçu par e-mail) pour accéder à **« Mon Espace Famille »** : déclarer jour par jour les prestations souhaitées pour chacun de ses enfants, suivre ses factures, gérer ses enfants et son profil. La mairie pilote tout depuis un menu **Périscolaire** dédié dans l'administration WordPress : tableau de bord, années scolaires/trimestres/calendrier scolaire, demandes et présences déclarées, familles, enfants, menus de cantine, commande fournisseur, factures, modèles d'e-mails, réglages.

Aucun compte WordPress n'est nécessaire côté famille. Aucun paiement en ligne n'est intégré : le mode de paiement (chèque/espèces ou prélèvement SEPA) est déclaré à l'inscription, mais les prélèvements réels restent traités par la mairie via sa banque.

---

## Côté familles

### 1. Première inscription

Formulaire public (« Première inscription ? »), présenté comme un parcours en 4 étapes annoncées au parent (« Coordonnées », « Enfants », « Paiement », « Règlement ») — impossible de passer à l'étape suivante tant que l'étape en cours n'est pas complète (validation native du navigateur **et** revalidation côté serveur, pour parer un envoi direct qui contournerait le formulaire) :

1. **Coordonnées** — e-mail, prénom, nom, téléphone, adresse, code postal, ville : tous obligatoires. Un bouton *Ajouter un second parent* révèle un bloc facultatif (prénom, nom, e-mail, téléphone) : aucun champ n'y est requis, mais un e-mail ou un téléphone renseigné doit être valide (même contrôle que pour le premier parent) sous peine de rejeter la soumission. Le second parent, s'il est renseigné, devient un contact du même foyer et rejoint automatiquement la liste des personnes autorisées à récupérer les enfants — au départ de la garderie du soir uniquement (voir « Mon profil » ci-dessous).
2. **Enfants** — jusqu'à **5 enfants par demande** (prénom, nom, classe, date de naissance, régime alimentaire — sans porc et/ou sans viande — tous obligatoires pour chaque ligne renseignée), avec pour chacun un **justificatif d'assurance scolaire obligatoire** (PDF, JPG ou PNG, 1 Mo maximum) et, facultativement, une ou plusieurs **personnes autorisées à le récupérer en fin de garderie du soir** (prénom, nom, téléphone, lien avec l'enfant, indicateur pièce d'identité — sans effet sur la cantine ni la garderie du matin) — saisies dès cette étape mais modifiables ensuite à tout moment depuis « Mes enfants ».
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

Une fois enregistrée par la mairie, la famille saisit son e-mail sur la page publique et reçoit un lien de connexion à usage unique (valable 30 minutes par défaut, réglable — voir [Réglages](#réglages)). Aucun mot de passe à créer ni à retenir. La session ouverte dure 12 heures (cookie signé, non modifiable côté client).

### 4. Mon Espace Famille

Une fois connectée, la famille arrive sur un portail à onglets (barre latérale) :

**Popin de découverte** — à la toute première connexion (pour l'un ou l'autre parent du foyer, cf. ci-dessus), une popin en 5 étapes présente l'essentiel de l'espace (déclarer un jour, gérer les enfants et les personnes autorisées, mettre à jour son profil, consulter factures et documents), avec navigation *Suivant*/*Précédent* et un bouton *Passer* pour la fermer immédiatement. Elle ne s'affiche qu'une seule fois : la fermer (à la fin ou via *Passer*) l'enregistre définitivement pour le foyer entier — un second parent qui se connecte après ne la revoit pas si le titulaire l'a déjà fermée.

#### Tableau de bord

#### Tableau de bord

Vue d'ensemble : jours et montant déclarés pour la période en cours, prochaine facture (montant/statut), menu de cantine de la semaine, résumé « Mes enfants ». Trois raccourcis : *Déclarer un jour*, *Ajouter un enfant*, et **Annulation prestations**.

**Annulation prestations** — popin accessible depuis le tableau de bord pour annuler rapidement une ou plusieurs prestations déjà déclarées, **sans naviguer jusqu'au calendrier**. La liste proposée est par **prestation** et non par jour : un forfait journée est affiché comme 3 lignes (Garderie matin / Cantine / Garderie soir) pour la lisibilité, mais reste indivisible — cocher n'importe laquelle des trois annule le forfait entier. Plusieurs prestations, sur plusieurs jours, peuvent être cochées et annulées en une seule confirmation ; la mairie reçoit un e-mail récapitulatif par jour concerné. Seules les prestations encore modifiables (délai de préavis non dépassé) sont proposées.

#### Cantine & Garderie

Calendrier présenté mois par mois (accordéon), un bloc par enfant. Chaque case cochée (Garderie Matin / Cantine / Garderie Soir / Forfait journée — l'en-tête de chaque colonne porte une infobulle rappelant le nom complet de la prestation) est enregistrée **immédiatement** en AJAX — pas de bouton « Envoyer » à chercher, pas de fichier à renvoyer par e-mail. Les jours fermés (week-ends, mercredis, vacances scolaires, jours fériés) n'apparaissent pas dans la grille. Le total (jours + montant) se recalcule en direct par enfant et par mois.

Un verrou de modification (48 h par défaut, réglable) grise les cases trop proches de la date concernée — contrôlé aussi côté serveur, pas seulement par l'affichage grisé. L'absence de justificatif d'assurance à jour bloque l'**ajout** d'un nouveau jour pour l'enfant concerné (une case déjà cochée reste décochable : pas de blocage rétroactif).

Un bouton **« Tout »** par colonne de service coche en un clic tous les jours encore déclarables du mois pour l'enfant concerné (les jours déjà verrouillés ne sont jamais touchés) ; si la colonne est déjà entièrement cochée, le bouton passe en « Retirer » pour tout décocher. Indépendant par enfant et par mois, même enregistrement automatique que les cases individuelles.

Un bouton « Valider et recevoir mon planning » envoie à la demande un récapitulatif complet par e-mail (jours, prestations, totaux par service, montant indicatif — la facturation définitive reste de la responsabilité de la mairie).

#### Menu de la semaine

Même contenu que le widget public (voir [Menu de cantine — accès libre](#menu-de-cantine--accès-libre)), mais dans le portail connecté, avec la même navigation semaine précédente/suivante. Dans le portail, la navigation ←/→ et « Revenir à cette semaine » se fait en AJAX (rechargement du seul bloc menu, sans rechargement de page ni perte de position de défilement) ; l'URL reste synchronisée (`?psc_semaine=...`) pour permettre de partager un lien vers une semaine précise.

#### Mes enfants

Tableau **en lecture seule** — Prénom, Nom, Classe, Naissance, Régime (badge, ou « — » si aucun), Actif (badge) — avec un bouton **Modifier** par ligne ouvrant une popin pour corriger une faute de frappe sur le prénom, le nom ou la date de naissance d'un enfant déjà onboardé. Sous 640px, ce tableau (comme celui de l'assurance scolaire ci-dessous et celui des personnes autorisées) passe en liste de cartes empilées — un libellé par ligne plutôt qu'un défilement horizontal.

En dessous, le panneau **Assurance scolaire [année scolaire en cours]** liste chaque enfant actif avec son statut (badge « Fournie » avec date + lien de consultation, ou « Manquante ») et un bouton *Remplacer*/*Ajouter* ouvrant une popin d'envoi de fichier (PDF, JPG ou PNG, 1 Mo maximum).

Puis le panneau **Personnes autorisées à récupérer les enfants — garderie du soir** : par enfant actif, la liste des personnes pouvant venir le chercher au départ de la garderie du soir (sans effet sur la cantine ni la garderie du matin) — prénom, nom, téléphone, lien avec l'enfant (champ libre avec suggestions), indicateur « présentera une pièce d'identité » — avec **Modifier**/**Retirer** par ligne et un bouton *Ajouter une personne*. Le retrait est un retrait de la liste, pas une suppression : toute modification (ajout, modification, retrait) est conservée dans un historique consultable par la mairie, jamais par la famille elle-même. Un responsable ne voit et ne modifie que la liste de ses propres enfants.

Enfin, le panneau **Ajouter un enfant** permet de rattacher un nouvel enfant à la famille (prénom, nom, classe, date de naissance, régime, justificatif d'assurance obligatoire dès la création), dans la limite d'un nombre maximum configurable (10 par défaut). Un enfant marqué **sorti** par la mairie n'apparaît plus dans le calendrier ni dans le planning, mais reste visible ici et garde tout son historique d'inscriptions passées.

#### Réinscription (onglet visible seulement pendant la campagne)

Onglet affiché uniquement pendant la fenêtre de réinscription ouverte par la mairie (voir [Années scolaires](#années-scolaires)) — invisible le reste de l'année. Pour chaque enfant actif : classe actuelle et classe proposée pour l'année suivante (déduite de la table de correspondance des classes), une case à cocher pour confirmer ou retirer l'enfant, un nouveau justificatif d'assurance à déposer pour chaque enfant confirmé, et une acceptation du règlement intérieur valable pour toute la famille. Un enfant décoché n'est pas sorti pour autant : il n'est simplement pas réinscrit pour l'année suivante, et reste modifiable jusqu'à la fermeture de la fenêtre.

#### Mes factures

Liste des factures mensuelles de la famille (mois, montant, statut envoyée/en attente, téléchargement du PDF).

#### Mon profil

État civil (prénom, nom), coordonnées (téléphone mobile/fixe, e-mail) et adresse du foyer. Un changement d'adresse e-mail nécessite une **confirmation par lien envoyé sur la nouvelle adresse** avant de prendre effet (annulable tant qu'il est en attente) ; l'ancienne adresse reste active jusque-là. La fiche de chaque enfant se modifie depuis « Mes enfants », pas ici.

Panneau **Second parent (facultatif)** : bouton *Ajouter un second parent* si absent, sinon formulaire prénom/nom/e-mail/téléphone pré-rempli avec *Enregistrer* et *Retirer* — mêmes règles qu'à l'inscription (aucun champ requis, format e-mail/téléphone contrôlé s'il est renseigné). Ajouter ou retirer le second parent l'ajoute ou le retire immédiatement de la liste des personnes autorisées ci-dessous.

Dès qu'une adresse e-mail est renseignée, le second parent peut se connecter à cet espace **avec sa propre adresse** (page d'accueil, « Se connecter à l'espace famille ») : il accède au même compte, aux mêmes enfants, et peut agir exactement comme le titulaire (déclarer un jour, ajouter un enfant, modifier le profil...) — ce n'est pas un second compte, seulement une seconde adresse qui ouvre la même session. Chaque parent reçoit son lien de connexion à sa propre adresse. Une adresse déjà utilisée par un autre foyer (comme titulaire ou comme second parent) est refusée, pour ne jamais faire pointer une même adresse vers deux comptes différents.

Panneau **Personnes autorisées à récupérer les enfants — garderie du soir** (vue par foyer, pas par enfant ; sans effet sur la cantine ni la garderie du matin) : les deux parents y figurent toujours, avec l'étiquette « Parent », non retirables depuis cette liste — cette entrée n'est jamais une ligne stockée, elle est recalculée à chaque affichage depuis la fiche foyer. Les autres personnes (bouton *Ajouter une personne autorisée* : prénom, nom, lien de parenté, téléphone) sont ajoutées d'un coup à tous les enfants actifs du foyer, et retirables ligne par ligne — un retrait ici retire la personne de tous les enfants auxquels elle était rattachée. Cette liste dédoublonnée complète, sans le remplacer, le panneau par enfant de « Mes enfants ».

#### Documents

Téléchargement du règlement intérieur et du règlement concernant le prélèvement automatique, au format PDF (mis en ligne par la mairie depuis Réglages) — message explicite si un document n'a pas encore été déposé.

### 5. Menu de cantine — accès libre

Un widget affiché sur la page publique, **sans connexion requise**, présente le menu de la semaine (lundi/mardi/jeudi/vendredi — pas de menu le mercredi, pas de cantine ce jour-là). Navigation semaine précédente/suivante façon calendrier, avec retour rapide à la semaine en cours. Pendant les vacances scolaires ou tout autre jour sans école, le widget affiche « Pas d'école cette semaine » à la place du menu.

---

## Côté mairie (backoffice)

Menu **Périscolaire** dans l'administration WordPress, avec treize sections :

### Tableau de bord

Page d'accueil du backoffice : nombre de familles actives, d'enfants actifs, trimestre actif (et sa date de fin), et une liste **« À faire »** signalant en un coup d'œil les demandes d'inscription en attente, le statut du menu de cantine de la semaine prochaine (saisi / envoyé / pas encore saisi) et celui de la commande fournisseur correspondante — chaque ligne renvoie directement vers l'écran concerné.

### Années scolaires

Une année scolaire chapeaute les trimestres et porte, pour chaque enfant, sa classe et son statut d'inscription **de cette année-là** (table `wp_psc_child_school_years`) : la classe n'est plus une valeur unique sur la fiche enfant, elle s'historise année par année. Une seule année peut être **active** à la fois (même principe que les trimestres) ; une année **en préparation** existe le temps de monter le passage d'année ou une campagne de réinscription ; une année **archivée** reste consultable en lecture seule.

Le libellé et les dates de début/fin de chaque année restent modifiables directement dans le tableau (champs éditables en ligne, bouton *Enregistrer*) — utile pour corriger une faute de frappe ou ajuster une date sans tout recréer. Une année peut aussi être **supprimée** (bouton *Supprimer*, confirmation obligatoire), sauf l'année active — il faut d'abord en activer une autre. La suppression retire les inscriptions d'enfants propres à cette année (classe, justificatif d'assurance de cette année-là, avec son fichier) ; les trimestres qui lui étaient rattachés ne sont pas supprimés (ils portent inscriptions, présences et menus, bien plus qu'un simple regroupement par année), seulement détachés.

**Passage d'année** : depuis l'année active, prépare l'année suivante en proposant pour chaque enfant actif sa classe montée d'un niveau, d'après une **table de correspondance configurable dans Réglages** (utile pour une école à classes multi-niveaux, où la progression n'est pas un simple PS→MS→...→CM2). Un écran de récapitulatif liste chaque enfant avec sa classe actuelle et la classe proposée, modifiable ligne par ligne avant confirmation ; les enfants en fin de cycle sont proposés « Sortie » et basculent au statut sorti. Rien n'est écrit en base tant que la mairie n'a pas explicitement confirmé le récapitulatif.

Un enfant peut aussi être marqué **sorti** individuellement à tout moment (déménagement en cours d'année, par exemple), depuis [Enfants](#enfants) — pas seulement via le passage d'année automatique de fin de cycle.

**Réinscription** : pendant la fenêtre configurée dans Réglages, les familles connectées voient un onglet « Réinscription » dédié pour confirmer chaque enfant, déposer un nouveau justificatif d'assurance et réaccepter le règlement intérieur pour l'année suivante (voir [Réinscription](#réinscription-onglet-visible-seulement-pendant-la-campagne)). Aucune relance automatique : la mairie déclenche manuellement, si besoin, un e-mail de rappel aux familles n'ayant pas encore réinscrit leurs enfants.

### Trimestres

Création d'un trimestre (libellé, dates, année scolaire de rattachement) : le calendrier se génère automatiquement, jour par jour, avec fermeture par défaut des week-ends, des mercredis, des jours fériés et des vacances scolaires (zone C, voir [Calendrier scolaire](#calendrier-scolaire)). Un seul trimestre peut être actif à la fois : c'est celui visible par les familles. Un formulaire permet aussi de fermer manuellement une plage de dates (fermeture exceptionnelle non couverte par le calendrier scolaire officiel) — **attention**, contrairement à la fermeture d'un jour depuis [Calendrier scolaire](#calendrier-scolaire), celle-ci n'avertit pas si des familles ont déjà déclaré des présences sur la période.

Libellé, dates et année scolaire de rattachement restent modifiables directement dans le tableau (champs éditables en ligne, bouton *Enregistrer*). Modifier les dates régénère le calendrier du trimestre sur la nouvelle période — les jours déjà couverts retrouvent leur statut recalculé automatiquement (week-end, mercredi, vacances, férié), donc une fermeture ponctuelle ajoutée à la main sur un jour resté dans la période peut être réinitialisée ; les jours qui sortent de la période réduite ne sont eux jamais supprimés (approche non destructive). Un trimestre peut aussi être **supprimé** (bouton *Supprimer*, confirmation obligatoire), mais jamais le trimestre actif (il faut d'abord en activer un autre) ni un trimestre qui porte déjà des présences déclarées par une famille — à la différence d'une année scolaire, le trimestre est l'unité opérationnelle réelle, sa suppression avec des données réelles serait bien plus destructrice.

### Calendrier scolaire

Le périscolaire suit le calendrier scolaire officiel de la **zone C** (Créteil, Montpellier, Paris, Toulouse, Versailles), publié par le ministère de l'Éducation nationale — pas d'école, pas de périscolaire, pas de cantine, pas de menu.

- **Chargement automatique** : un bouton télécharge et importe le flux iCal officiel (`education.gouv.fr/vacances`), zone C uniquement, et ferme les jours concernés dans tous les trimestres. Le flux distingue proprement le dernier jour de vacances du jour de reprise (`DTEND` exclusif dans le flux officiel) — un jour de reprise n'est jamais fermé par erreur.
- **Liste lisible** : les jours fermés sont regroupés par période contiguë (ex. « 17/10/2026 → 01/11/2026 — Vacances de la Toussaint — 16 jours — Import officiel »).
- **Correction manuelle exceptionnelle** : un jour peut être fermé (formation des enseignants, fermeture ponctuelle...) ou rouvert à la main. Une correction manuelle n'est **jamais** écrasée par un rechargement ultérieur du calendrier officiel.
- **Fermer un jour où des familles ont déjà déclaré des présences déclenche un écran d'avertissement** (nombre d'inscriptions et de familles concernées, détail par enfant/prestation) avant toute action. Une fois confirmé : les inscriptions concernées sont supprimées (elles ne seront jamais facturées) et chaque famille concernée reçoit un e-mail listant précisément ce qui a été retiré.

### Demandes d'inscription

File de modération des nouvelles familles (voir [Première inscription](#1-première-inscription)). Pour chaque demande en attente : coordonnées déclarées (prénom, nom, etc.), statut d'acceptation du règlement intérieur, mode de paiement choisi et — si prélèvement — titulaire, adresse, **IBAN partiellement masqué** (`FR14 •••• •••• 2606`), BIC et statut d'acceptation du règlement de prélèvement. Les nom/prénom/classe des enfants sont modifiables avant validation (informations déclaratives, à vérifier). Refus possible avec motif optionnel, notifiable ou non au demandeur.

**Validation automatique** (Réglages > Demandes d'inscription, désactivée par défaut) : si activée, dès que la famille clique le lien de confirmation reçu par e-mail, son compte est créé **et elle est connectée immédiatement** — redirigée directement dans son espace famille, sans avoir à attendre ni à aller chercher un second e-mail avec un lien d'accès. La demande n'apparaît alors jamais dans cette file, aucune information saisie n'est relue par la mairie avant l'ouverture de l'accès (justificatif d'assurance compris). En cas d'échec (aucun enfant valide déclaré), la demande retombe automatiquement dans le circuit normal de modération — la famille reçoit alors, une fois la demande validée manuellement par la mairie, un e-mail avec un lien de connexion à usage unique.

### Présences déclarées

Vue de correction (anciennement « Inscriptions » dans le menu — renommée pour ne plus se confondre avec « Demandes d'inscription » juste au-dessus, deux écrans très différents) : sélection d'une famille et d'une période, grille identique à celle vue par le parent, modifiable directement par la mairie (utile pour une inscription reçue par téléphone ou papier). Chaque enregistrement envoie une notification à la famille. Export CSV du récapitulatif par trimestre (protégé contre l'injection de formules Excel).

### Familles

Liste de toutes les familles, avec mode de paiement et IBAN masqué en un coup d'œil. Édition complète par famille : coordonnées, mode de paiement, informations SEPA (IBAN/BIC revalidés à l'enregistrement), référence de mandat. Envoi ou renvoi du lien de connexion, activation/désactivation d'accès (une famille désactivée perd l'accès immédiatement, même en session ouverte).

**Suppression définitive** : à la différence de la désactivation (réversible, l'accès seul est coupé), le bouton *Supprimer* efface complètement la famille — enfants, inscriptions, pointage SIDSCM, personnes autorisées à récupérer (liste courante et historique), justificatifs d'assurance et factures, y compris les fichiers PDF correspondants sur le serveur. Confirmation explicite obligatoire avant toute suppression ; les demandes d'inscription historiques (`wp_psc_requests`) ne sont pas concernées, elles ne sont pas rattachées au compte famille.

### Enfants

Liste de tous les enfants avec leur famille de rattachement, leur régime cantine (sans porc / sans viande), leur classe **pour l'année scolaire sélectionnée** (sélecteur d'année, année active par défaut) et le statut de leur justificatif d'assurance scolaire. Les enfants sortis sont masqués par défaut ; une case « Afficher les enfants sortis » les révèle. Rattachement d'un nouvel enfant à une famille existante (la mairie tient cette liste — les familles ne peuvent pas créer un enfant sans rattachement), et action **Marquer sorti**/**Marquer actif** par ligne.

La progression de classe d'une année sur l'autre se fait via le [passage d'année](#années-scolaires), toujours déclenché manuellement par la mairie — aucune tâche planifiée, aucune bascule automatique en arrière-plan.

Chaque enfant a un bouton **Personnes autorisées**, vers une fiche dédiée (hors menu, non listée dans la navigation) affichant la liste courante des personnes pouvant venir le récupérer au départ de la garderie du soir (nom, téléphone, lien, pièce d'identité — sans effet sur la cantine ni la garderie du matin), et l'**historique complet** des ajouts/modifications/retraits (date, auteur, source famille/mairie). Cette fiche est en **lecture seule** côté mairie : c'est la famille qui gère la liste depuis « Mes enfants » ; la mairie la consulte, notamment pour l'animateur en fin de garderie.

### Menus cantine

Saisie du menu semaine par semaine (lundi/mardi/jeudi/vendredi, pas de mercredi). Chaque semaine reste un brouillon tant qu'elle n'a pas été explicitement envoyée — **aucun envoi automatique, aucune tâche planifiée** : c'est toujours un clic volontaire de la mairie (« Envoyer aux familles ») qui déclenche l'e-mail, adressé à toutes les familles actives ayant au moins un enfant actif. Le même menu alimente aussi le widget public et l'onglet « Menu de la semaine » du portail famille.

### Commande fournisseur

Comptage hebdomadaire du nombre de repas de cantine à prévoir, **par classe**, calculé à partir des inscriptions réellement déclarées (lundi/mardi/jeudi/vendredi). Comme pour les menus, l'envoi au prestataire de restauration par e-mail est **toujours un clic volontaire** de la mairie, jamais automatique. Chaque envoi archive un instantané figé (comptage et e-mail effectivement envoyés à ce moment-là) : l'historique reste exact même si des inscriptions changent après coup.

### Factures

Facturation **mensuelle**. Sélection d'un mois ayant des inscriptions, génération en un clic d'un PDF par famille (tarif par prestation, détail par enfant, total). **Un mois en cours (non terminé) ne peut pas être facturé** — le bouton de génération n'apparaît que pour un mois déjà écoulé, pour éviter une facture incomplète si des inscriptions changent encore. Envoi individuel ou en masse par e-mail, avec pièce jointe PDF. Les PDF sont stockés hors de portée d'accès direct par URL.

### Modèles e-mails

Personnalisation du sujet et du corps de chaque e-mail transactionnel (lien de connexion, récapitulatif, facture, menu, demandes...), avec variables (`{{site}}`, `{{nom}}`, `{{trimestre}}`...) et réinitialisation possible au texte par défaut.

Les treize types d'e-mails envoyés par le plugin (lien de connexion, compte activé, changement d'adresse, récapitulatif de planning + notification mairie, correction admin, menu hebdomadaire, commande fournisseur, jour fermé, cantine annulée, absence signalée, demande d'inscription + notification + rejet, facture) partagent tous le même gabarit HTML (`templates/email/layout.php`) et les mêmes blocs réutilisables (bouton, encadré, tableau — `Psc_Mailer`) : bandeau vert forêt `#2D4A3E` avec « Mairie de Montgeroult » en doré, titre en police serif, corps en sans-serif, bouton doré, encadrés beiges à coins droits, pied de page gris — cohérent avec l'identité visuelle du site. Mise en page 100 % tables HTML et styles en ligne (aucune feuille de style externe, aucune police web chargée), pour un rendu fiable sur les clients de messagerie qui ignorent `<style>` ou les polices personnalisées (Outlook desktop en tête) — Fraunces/Work Sans y basculent automatiquement sur leurs équivalents serif/sans-serif standards.

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
- **Durée de validité des liens envoyés par e-mail** : deux réglages distincts — le **lien de connexion** à usage unique (30 minutes par défaut, 5 min à 24 h), utilisé pour se connecter à l'espace famille et pour l'e-mail reçu à la validation manuelle d'une demande ; le **lien de confirmation par e-mail** (3 jours par défaut, 1 à 30 jours), utilisé pour confirmer une nouvelle demande d'inscription ou un changement d'adresse e-mail depuis « Mon profil ». Le second n'ouvre pas de session, il valide seulement une adresse — une durée plus longue y est donc sans risque.
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

**Personnes autorisées** (onglet Garderie soir uniquement — absent des onglets Garderie matin et
Cantine, où cette information n'a pas lieu d'être) : en vue Jour, chaque ligne enfant porte un
bouton compact *Autorisés* (replié par défaut, pour ne pas surcharger une liste qui peut compter
beaucoup d'enfants) qui
déplie sous la ligne un panneau **en lecture seule** — nom, lien (Parent/Grand-parent/Personne de
confiance...), téléphone de chaque personne autorisée à venir chercher cet enfant. Plusieurs
lignes peuvent être dépliées en même temps, chaque bouton ne repliant que la sienne. La liste est
toujours recalculée à l'affichage (parents, second parent éventuel, tiers ajoutés depuis « Mon
profil » ou « Mes enfants » côté famille) — jamais une copie figée à l'inscription. Aucune
modification n'est possible depuis cet écran : la gestion reste entièrement côté famille. En vue
Semaine, le même bouton renvoie vers la vue Jour du premier jour où l'enfant est attendu, avec son
panneau déjà déplié.

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

- Contrôle d'accès systématique : chaque action d'administration vérifie **à la fois** la capacité de l'utilisateur (`psc_manage_periscolaire`, accordée par défaut aux administrateurs et aux éditeurs — voir [Capacité d'accès personnalisée](#capacité-daccès-personnalisée)) et un nonce WordPress (protection CSRF) — l'un ne remplace pas l'autre.
- Cloisonnement des données : un parent ne peut agir que sur ses propres enfants, contrôlé côté serveur à chaque requête.
- Requêtes SQL préparées (`$wpdb->prepare()`) systématiquement, y compris avec un nombre variable de paramètres.
- Échappement systématique des sorties (`esc_html`/`esc_attr`/`esc_url`).
- Jetons de connexion et de vérification stockés **hachés** (HMAC-SHA256), jamais en clair ; comparaison à temps constant (`hash_equals`).
- **Le jeton du lien de connexion transite par l'URL** — c'est inhérent au principe du lien magique. Son exposition est contenue à chaque étape : il est consommé sur `init` (priorité 5), donc **avant que la moindre page ne soit rendue**, puis retiré de l'URL par une redirection ; il est à usage unique et expire en 30 minutes (réglable) ; il n'est stocké qu'en haché. Vérifié sur l'instance de test : le jeton n'apparaît dans aucune réponse HTTP, ni dans la page finale, et n'existe en clair dans aucune colonne de la base. Il ne reste donc qu'une trace dans le **journal d'accès du serveur web**, où il arrive déjà dépensé. Filtrer ce journal supposerait un accès à la configuration du serveur, dont on ne dispose pas en hébergement mutualisé : le risque résiduel est assumé, et aucune modification du code ne le réduirait.
- Limitation de fréquence (anti-spam / anti-énumération) sur les formulaires publics ; réponse identique qu'une adresse soit connue ou non. La fenêtre est **fixe** : son échéance est fixée à la première tentative et n'est pas repoussée par les suivantes, sans quoi un attaquant maintiendrait le compteur vivant indéfiniment et priverait durablement une famille visée de son lien de connexion. L'adresse IP est lue depuis `REMOTE_ADDR`, jamais depuis un en-tête fourni par le client (cf. [Derrière un répartiteur de charge](#derrière-un-répartiteur-de-charge-ou-un-cdn)).
- Champ honeypot sur le formulaire de demande d'inscription.
- Protection contre l'injection de formules CSV sur l'export.
- Sessions familles signées côté serveur (cookie `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS) — aucun mot de passe stocké.
- **La déconnexion invalide réellement la session.** Un cookie signé se vérifie sans rien consulter : le supprimer du navigateur n'en retire qu'une copie, et quiconque en détient une autre — poste partagé, profil synchronisé — pourrait s'en servir jusqu'à son expiration. Chaque session porte donc un identifiant propre, ajouté à une courte liste de révocation à la déconnexion. La liste ne grossit pas : chaque entrée s'efface en même temps que la session qu'elle invalide. L'identifiant étant propre à la session et non au foyer, un parent qui se déconnecte ne déconnecte pas l'autre — le compte est partagé entre les deux.
- **IBAN validé par clé de contrôle réelle** (mod-97, ISO 7064), BIC validé par format ; rejet côté serveur indépendant de la validation du navigateur.
- IBAN affiché **masqué** dans les listes du backoffice (seuls le pays et les 4 derniers caractères apparaissent) ; il n'apparaît en clair que dans le formulaire de modification d'une famille, où la mairie en a besoin pour saisir le prélèvement dans son outil bancaire.
- **IBAN chiffré au repos** (XSalsa20-Poly1305 via libsodium, repli AES-256-GCM) : une copie de la base — sauvegarde égarée, export SQL — ne livre aucune coordonnée bancaire exploitable. La clé vit dans `wp-config.php`, jamais en base. L'IBAN est également **effacé de la demande d'inscription dès son approbation**, puisqu'il a été reporté sur la fiche famille : il n'existe plus qu'à un seul endroit.
- Le PDF du mandat de prélèvement SEPA (contient l'IBAN en clair) n'est **jamais stocké sur le serveur** : généré en fichier temporaire, joint à l'e-mail, puis supprimé immédiatement.
- Justificatifs d'assurance scolaire limités à 1 Mo, formats PDF/JPG/PNG uniquement (vérifiés côté serveur, pas seulement par l'attribut `accept` du champ fichier).
- **Documents stockés hors du dossier des médias.** Justificatifs d'assurance et factures PDF sont écrits sous `wp-content/psc-private/` — jamais sous `wp-content/uploads/`, qui est systématiquement servi en HTTP : leurs noms étant prévisibles (`child-12.pdf`, `facture-7.pdf`), ils y auraient été téléchargeables par simple énumération d'URL. Ils ne sont accessibles que par les routes de téléchargement du plugin, après contrôle de session et d'appartenance. Le dossier reçoit un `.htaccess` et un `web.config` bloquants ; ces fichiers n'étant pas lus par toutes les configurations (nginx notamment les ignore), l'extension vérifie **depuis le navigateur de l'administrateur** que le dossier est réellement injoignable — seul point de vue qui reflète ce qu'un visiteur atteint vraiment — et affiche sinon un avertissement. Le correctif proposé ne demande aucun accès au serveur (cf. [Emplacement des documents](#emplacement-des-documents)).
- Fermer un jour du calendrier scolaire avec des inscriptions existantes exige une **confirmation explicite** après avertissement — pas de suppression accidentelle en un clic.

---

## Conformité RGPD

- Suppression automatique des demandes non vérifiées après 7 jours, des demandes traitées après 90 jours (WP-Cron).
- Les données bancaires (IBAN/BIC) ne sont collectées que si la famille choisit le prélèvement, et uniquement dans ce but.
- Aucune donnée n'est envoyée à un tiers : le mandat SEPA est stocké pour un traitement manuel par la mairie via sa banque, il n'y a pas d'intégration bancaire automatisée.
- **Effacement complet à la désinstallation**, activé via `define('PSC_REMOVE_DATA_ON_UNINSTALL', true);` dans `wp-config.php` — désactivé par défaut pour éviter une perte accidentelle, une simple désactivation du plugin ne supprimant jamais rien. Lorsqu'il est activé, la suppression du plugin efface **toutes** les tables, **toutes** les options et transients, **et les fichiers déposés** (justificatifs d'assurance, factures PDF), y compris ceux restés à leur emplacement d'avant la version 4.28.0. Rien n'est énuméré à la main dans `uninstall.php` : tables et options sont découvertes par leur préfixe, de sorte qu'un ajout futur soit purgé sans qu'on ait à y penser — une liste manuelle avait précédemment dérivé, laissant 10 tables sur 16 et 24 options sur 30 derrière elle.
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

### Clé de chiffrement des coordonnées bancaires

Les IBAN sont chiffrés avant d'être écrits en base. La clé n'est **jamais** stockée en base : elle est lue depuis `wp-config.php`, ce qui est précisément ce qui rend une copie de la base inexploitable.

Par défaut, elle est dérivée des sels WordPress — aucune configuration n'est nécessaire pour que le chiffrement fonctionne. Mais **régénérer les sels rend alors les IBAN enregistrés illisibles** (ils devront être ressaisis par la mairie). Pour s'en prémunir, déclarer une clé dédiée, avant la première saisie d'un IBAN si possible :

```php
// wp-config.php — à conserver précieusement et à sauvegarder hors de la base.
define('PSC_ENCRYPTION_KEY', 'coller-ici-une-longue-chaine-aleatoire');
```

Si un IBAN devient indéchiffrable (clé perdue ou modifiée), le champ s'affiche vide dans la fiche famille : rien n'est perdu d'autre, la mairie ressaisit l'IBAN et l'enregistrement repart normalement.

### Emplacement des documents

Les justificatifs d'assurance et les factures sont écrits sous `wp-content/psc-private/`, protégé par un `.htaccess`. Cela suffit sous Apache, mais **certains hébergements ignorent ces fichiers** : les documents redeviennent alors téléchargeables par simple énumération d'URL. L'extension le détecte depuis le navigateur de l'administrateur et affiche une alerte.

Le correctif ne demande **aucun accès au serveur** — utile en hébergement mutualisé, où la configuration d'Apache ou de nginx n'est pas modifiable. Il suffit de déclarer un dossier situé **hors de la racine web**, ce qui le rend inatteignable par construction plutôt que par convention :

```php
// wp-config.php — sur un mutualisé, la racine web est souvent .../www/,
// et son dossier parent convient.
define('PSC_PRIVATE_DIR', dirname(ABSPATH) . '/psc-private');
```

Les documents déjà déposés sont **déplacés automatiquement** au chargement suivant : le suivi porte sur le chemin lui-même, pas sur un numéro de version, de sorte qu'ajouter ou modifier la constante déménage bien l'existant. Sans cela, la correction n'aurait protégé que les dépôts à venir. Aucune écriture SQL n'est nécessaire, les chemins étant enregistrés en relatif.

Si le chemin déclaré n'est pas inscriptible (dossier parent inexistant, droits refusés), une alerte le signale explicitement dans l'administration — plutôt que de laisser les téléchargements échouer sans explication. Retirer la ligne rétablit l'emplacement par défaut, et rapatrie les fichiers.

### Derrière un répartiteur de charge ou un CDN

La limitation de fréquence s'appuie sur `REMOTE_ADDR`, seule valeur que l'appelant ne choisit pas. Un en-tête `X-Forwarded-For` est envoyé par le client lui-même : s'y fier sans précaution donnerait à un attaquant une adresse différente à chaque requête, donc un contournement complet de la limitation. Il est donc **ignoré par défaut**.

Si le site est servi derrière un répartiteur ou un CDN, `REMOTE_ADDR` est celui de l'intermédiaire — identique pour tous les visiteurs. Désigner alors explicitement l'en-tête à lire :

```php
// wp-config.php
define('PSC_CLIENT_IP_HEADER', 'HTTP_X_FORWARDED_FOR');
define('PSC_TRUSTED_PROXIES', 1); // nombre d'intermédiaires, 1 par défaut
```

L'adresse retenue est la **dernière** de la liste, pas la première : un intermédiaire ajoute à la fin l'adresse qu'il constate, et tout ce qui précède a pu être fabriqué par le client. Un attaquant qui envoie `X-Forwarded-For: 9.9.9.9` se voit donc toujours compté sur sa vraie adresse.

Sans cette configuration, si l'adresse reste indéterminable, les limites par IP sont **levées** plutôt qu'appliquées à un seau commun : dans le cas contraire, les premiers visiteurs épuiseraient le quota de tous les autres et la protection anti-abus se transformerait en panne générale. Les limites par adresse e-mail continuent de s'appliquer.

### Capacité d'accès personnalisée

Le backoffice (menu Périscolaire, réglages, validation des demandes...) est protégé par une capacité WordPress dédiée (`psc_manage_periscolaire`), et non `manage_options` — un membre de la mairie n'a donc pas besoin d'être Administrateur complet du site (thèmes, extensions, réglages WordPress) pour gérer le périscolaire. Cette capacité est accordée automatiquement, à l'activation et à chaque mise à jour du plugin, aux rôles **Administrateur** et **Éditeur** (`Psc_Installer::sync_roles()`) : il suffit d'attribuer le rôle Éditeur au membre de la mairie concerné, sans configuration supplémentaire. Les mises à jour du plugin lui-même restent réservées à l'Administrateur, comme pour toute extension WordPress.

Pour changer les rôles qui reçoivent la capacité par défaut (par exemple, ne pas l'accorder aux éditeurs, ou l'ajouter à un rôle personnalisé) :

```php
add_filter('psc_manage_default_roles', fn() => array('administrator', 'gestionnaire_periscolaire'));
```

Pour utiliser une capacité entièrement différente (cas avancé, gestion manuelle des rôles) :

```php
add_filter('psc_manage_capability', fn() => 'gerer_periscolaire');
```

Dans ce dernier cas, retourner un tableau vide via `psc_manage_default_roles` désactive l'attribution automatique, pour gérer soi-même qui possède cette capacité.

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

### Thème du site (`theme/Archive`)

Le thème WordPress du site (nom affiché « Montgeroult Familles », dossier `Archive` — conserver ce
nom de dossier : WordPress l'utilise comme identifiant du thème actif en base, le renommer
désactiverait le thème) est monté depuis `theme/Archive/` dans les deux `docker-compose*.yml`,
exactement comme `mu-plugins/`. Il porte la page famille (`page-espace-familles.php` — masthead
« Espace familles » / « Espace intervenants », voir [Listes intervenantes SIDSCM](#listes-intervenantes-sidscm))
et l'en-tête général du site.

Pour qu'une page WordPress affiche ce masthead, sélectionner le modèle **« Espace Familles »**
dans ses Attributs de page (Modèle) — réglage propre à chaque installation (dev/prod), non
versionné : à refaire manuellement sur chaque environnement où la page famille est créée.

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
