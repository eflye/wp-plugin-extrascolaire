# Périscolaire — Inscriptions

Plugin WordPress de gestion des services périscolaires municipaux : inscriptions des familles, planning annuel, garderies, cantine, midi sans repas, menus, commande fournisseur, pointage et facturation.

**Version documentée : 5.9.0 — schéma de données 4.5.0 — 10 septembre 2026.** WordPress 5.8 minimum, PHP 7.4 minimum. L’environnement de développement local utilise **Podman**.

Les familles utilisent un espace dédié sans compte WordPress. La mairie administre le service depuis le menu **Périscolaire**. Les intervenants disposent d’un écran de présence protégé par code. Aucun paiement en ligne ni émission de prélèvement bancaire n’est intégré.

## Sommaire

- [Fonctionnalités récentes](#fonctionnalités-récentes)
- [Côté familles](#côté-familles)
- [Côté mairie](#côté-mairie)
- [Listes intervenantes SIDSCM](#listes-intervenantes-sidscm)
- [Règles métier](#règles-métier)
- [Données et migrations](#données-et-migrations)
- [Sécurité](#sécurité)
- [Conservation des données](#conservation-des-données)
- [Installation](#installation)
- [Configuration](#configuration)
- [Développement local](#développement-local)
- [Structure du projet](#structure-du-projet)
- [Points ouverts / à valider](#points-ouverts--à-valider)

## Fonctionnalités récentes

- **5.9.0** : filet de sécurité du refactoring avec restauration MySQL isolée en CI, inventaire du schéma réel et compteurs anonymes des chemins legacy avant toute décision de retrait.
- **5.8.1** : correction du verrouillage du planning par assurance : seul l'enfant dont le justificatif est manquant, en attente ou refusé est bloqué ; les autres enfants de la fratrie restent modifiables.
- **5.8.0** : notifications navigateur optionnelles lorsqu'un espace famille reste ouvert, avec activation explicite par message et par navigateur ; correction des envois successifs du back-office.
- **5.7.0** : intégration complète des messages dans l'espace famille, avec digest compact sur le tableau de bord, boîte de réception responsive, badge actualisé, urgence et contrôle d'accès 403.
- **5.6.0** : messages descendants de la mairie vers les familles, ciblage figé, diffusion par e-mail en lots, programmation, suivi nominatif de la première consultation, relance des non-lecteurs, export CSV, badge famille, alerte urgente et accusé explicite.
- **5.5.0** : facturation libre — génération quand la mairie le veut, pour n'importe quel mois (passé, courant, futur), suppression du mois et régénération sans toucher au statut d'envoi ; export prélèvements SEPA du mois en fichier .ods (une ligne par famille : IBAN déchiffré, mandat, montant).
- **5.4.2 et 5.4.3** : allergies conservées sur tout le parcours d'approbation (écran mairie, approbation manuelle et automatique) et rapprochement contrôlé des demandes déjà approuvées ; présence du midi pointable pour les enfants sans repas (déclaration MSR, flag mairie, forfait flégué) ; sécurité — révocation durable des accès familles (époques de session), chiffrement bancaire sans repli en clair, anti-cache `no-store`.

- **5.2.0** : prestation « Midi sans repas » (MSR), indicateur mairie « Cantine sans repas » sur la fiche enfant, suppression du réglage de variante Planning.
- **5.1 à 5.1.2** : seul le planning avec rythme et exceptions figure au menu, sous le nom **Planning** ; le raccourci « Déclarer un jour » y conduit. L’ancien écran jour par jour reste disponible par URL de compatibilité.
- **5.0.5 à 5.0.9** : goûters dans les commandes fournisseur, e-mail ventilé par régime, pied de mail personnalisable, aperçu avant confirmation d’envoi, tests fournisseur et traces CI améliorés. Correction des libellés de jours de l’aperçu le 4 septembre.
- **5.0 à 5.0.4** : passage des trimestres au planning annuel, rythme hebdomadaire et exceptions, allergies alimentaires, migration des déclarations, adaptation des factures/listes/exports, amélioration des performances et des tests du planning.
- **Fin de la série 4.x** : portail famille remanié, onglet **Habilitations** pour la fratrie, confirmations en popin auto-masquée, autocomplétion BAN et validation des téléphones, codes postaux et naissances.

Le détail chronologique des versions est conservé dans [readme.txt](readme.txt). Ses anciennes sections de présentation et d’installation peuvent encore décrire des versions antérieures ; le présent README décrit le fonctionnement courant.

## Côté familles

### Première inscription

Le formulaire public comporte quatre étapes, contrôlées dans le navigateur et côté serveur :

1. **Coordonnées** : identité, e-mail, téléphone et adresse du foyer. Un second parent peut être ajouté avec ses coordonnées facultatives ; les valeurs renseignées sont validées.
2. **Enfants** : jusqu’à cinq enfants par demande, identité, classe, naissance, régime facultatif (sans porc et/ou sans viande), allergies éventuelles et assurance scolaire obligatoire par enfant (PDF, JPG ou PNG, 1 Mo maximum). Des personnes autorisées à récupérer les enfants peuvent être déclarées.
3. **Paiement** : chèque/espèces ou prélèvement SEPA. Dans ce dernier cas : titulaire, adresse, IBAN, BIC et acceptation du règlement de prélèvement. L’IBAN est validé par clé mod-97 et le BIC par son format.
4. **Règlement intérieur** : lecture et acceptation horodatée. Il s’agit d’une acceptation par case à cocher, pas d’une signature électronique qualifiée.

**Adresse BAN** : la recherche propose des adresses et remplit rue, code postal et ville. Le code postal et la ville restent visibles, en lecture seule après sélection. Une bascule rend la saisie manuelle possible ; elle est également disponible sans JavaScript. La recherche appelle l’API BAN depuis le navigateur.

Les téléphones français et codes postaux sont validés. La naissance doit correspondre à un enfant ayant au moins trois ans au **1er septembre de l’année civile en cours**, y compris pour une inscription pendant l’été.

**Allergies alimentaires** : une case révèle un champ facultatif, limité à 1 000 caractères, strictement alimentaire. La mairie est alertée à l’enregistrement d’une allergie non vide pour organiser si nécessaire un PAI. Aucun menu individualisé n’est proposé : l’enfant apporte son repas et son goûter.

Le rythme habituel n’est plus demandé lors de l’inscription initiale : il se déclare ensuite dans **Planning**.

### Confirmation et ouverture du compte

La famille confirme son adresse par un lien reçu par e-mail (trois jours par défaut, réglable). La demande ne rejoint la file mairie qu’après cette confirmation. La mairie accepte ou refuse, avec motif et notification possibles.

Une option de **validation automatique**, désactivée par défaut, ouvre directement l’espace famille après confirmation de l’adresse. L’approbation crée le foyer et les enfants dans une transaction. Pour le prélèvement, elle génère une référence de mandat et joint un PDF SEPA à l’e-mail ; le fichier temporaire est supprimé après l’envoi.

### Connexion sans mot de passe

Un lien reçu par e-mail ouvre l’espace famille : validité de 30 minutes par défaut, session de 12 heures. Le lien reste réutilisable durant sa validité pour supporter les passerelles de messagerie qui préouvrent les liens. La déconnexion révoque la session concernée ; un événement sensible (retrait ou remplacement du second parent, changement d’adresse du titulaire) révoque durablement TOUTES les sessions du foyer et le lien encore valable.

Le second parent peut se connecter avec sa propre adresse au même foyer et exercer les mêmes actions. Une adresse déjà utilisée par un autre foyer est refusée. Désactiver une famille en mairie coupe son accès, même avec une session ouverte.

### Tableau de bord

Vue d’ensemble des déclarations, montants, factures, menu et enfants, avec accès rapide au Planning et à l’ajout d’un enfant. Une visite guidée en cinq étapes s’affiche à la première connexion ; son état est partagé par le foyer.

La popin **Annulation prestations** permet de retirer plusieurs prestations encore modifiables, sur plusieurs jours. Une annulation portant sur une composante affichée d’un forfait annule le forfait entier ; la mairie reçoit un récapitulatif par jour concerné.

### Planning

Le planning porte sur **l’année scolaire**, sans trimestre. Il associe :

- Une **frise mensuelle** : jours déclarés pour la fratrie et estimation annuelle.
- Des **onglets enfants** : prénom, classe et compteurs du mois.
- Le **rythme habituel** : prestations récurrentes du lundi, mardi, jeudi et vendredi. Avec au moins deux enfants, « Appliquer ce rythme à toute la fratrie » copie ce rythme.
- Les **exceptions du mois** : ajouts ou retraits ponctuels. La grille distingue ce qui vient du rythme, les ajouts, les retraits et les jours verrouillés. Des commandes de colonne permettent les modifications en lot ; « Revenir au rythme » supprime les exceptions modifiables du mois.
- Les **récapitulatifs mensuels et annuels**, par enfant et pour la fratrie.

Chaque clic est enregistré en AJAX. Revenir à l’état du rythme supprime l’exception devenue inutile. Les jours fermés sont exclus du calcul. Un changement de rythme préserve les jours déjà verrouillés au lieu de modifier rétroactivement leurs déclarations.

Le préavis vaut **48 heures par défaut**, configurable pour l’année scolaire et contrôlé côté serveur. L’absence d’assurance à jour bloque les ajouts, tout en permettant les retraits dans la période modifiable.

« Valider et recevoir mon planning » envoie un récapitulatif annuel : rythme par enfant, écarts à venir et estimation. Les choix sont déjà enregistrés avant ce clic ; il déclenche l’e-mail, pas la sauvegarde.

Le menu affiche seulement **Planning**. L’ancien écran jour par jour reste accessible par URL et utilise les mêmes données. Le réglage de choix de variante a disparu en v5.2.

### Mes enfants et assurance

Tableau des enfants avec identité, classe, naissance, régime, allergies et statut actif/sorti. « Modifier » ouvre une popin de correction de l’identité, de la naissance et des allergies. L’affichage s’adapte aux petits écrans.

Le panneau **Assurance scolaire** indique l’état du document annuel, sa date et les actions de consultation, ajout ou remplacement. **Ajouter un enfant** demande aussi son assurance, dans la limite configurable du foyer (10 enfants par défaut). Un enfant sorti garde son historique mais n’est plus proposé au planning courant.

### Habilitations

Onglet dédié aux **personnes autorisées à récupérer les enfants à la garderie du soir**, pour toute la fratrie. Les parents proviennent de la fiche foyer ; les tiers sont gérés avec leurs coordonnées et leur lien avec la famille. L’ajout s’applique à tous les enfants actifs du foyer. La liste est présentée par enfant ; les modifications et retraits portent sur la ligne sélectionnée pour cet enfant.

Les confirmations s’affichent dans une popin qui se masque automatiquement. Les modifications restent historisées pour la mairie ; retirer une personne de la liste courante n’efface pas son historique.

### Autres onglets

- **Menu de la semaine** : navigation hebdomadaire en AJAX, avec URL synchronisée et retour à la semaine courante. Le menu est aussi consultable publiquement, sans connexion, avec indication des jours sans école.
- **Mes factures** : factures mensuelles, statut et téléchargement du PDF.
- **Mon profil** : identité, contacts, adresse et second parent. Un changement d’e-mail nécessite une confirmation à la nouvelle adresse ; l’ancienne reste active jusque-là.
- **Documents** : règlement intérieur et règlement de prélèvement en PDF, ou message si le document manque.
- **Réinscription** : onglet visible pendant la campagne mairie. Confirmation des enfants, classe proposée, nouvelle assurance et acceptation du règlement. Décocher un enfant ne le marque pas automatiquement sorti.

## Côté mairie

### Tableau de bord

Indicateurs de familles/enfants actifs et d’année scolaire du planning ; liste « À faire » pour les demandes en attente, menus et commandes fournisseur, avec accès aux écrans concernés.

### Années scolaires

Deux ensembles complémentaires coexistent :

- **Dossiers annuels** : année en préparation, active ou archivée ; classe, statut, assurance et acceptation du règlement par enfant. Une seule année de dossiers est active.
- **Configuration du planning** : clé d’année, dates, plages de vacances, jours fériés et préavis de 0 à 720 heures. Le planning utilise la configuration couvrant la date courante, sinon la plus récente.

Les plages de vacances personnalisées sont prioritaires ; sans elles, le calendrier importé sert de référence. Les fériés sont préremplis et peuvent être complétés ou retirés. Les jours d’école sont calculés ; il n’y a plus de gestion opérationnelle des trimestres.

**Passage d’année** : la mairie prépare la classe suivante selon la table de correspondance des Réglages, ajuste chaque proposition dans un récapitulatif puis confirme. Les enfants en fin de cycle peuvent être proposés en sortie. Cette bascule est manuelle.

**Réinscription** : campagne avec dates d’ouverture/fermeture, contrôle des dossiers et relances manuelles. Le dossier annuel et le rythme du planning sont des données distinctes.

### Calendrier scolaire

Import iCal officiel de la **zone C**, avec traitement de la fin exclusive des vacances. Les corrections manuelles sont préservées lors des réimports. La vue visuelle mois/semaine permet de consulter le calendrier et les fermetures, y compris par prestation.

Une fermeture avec des déclarations existantes présente son impact avant confirmation et notifie les familles concernées. Les jours et prestations fermés sont exclus du planning effectif et de la facturation.

### Demandes, familles et enfants

La mairie examine les coordonnées, dossiers enfants, allergies et informations de paiement, puis approuve ou refuse les demandes. Les IBAN sont masqués dans les listes et accessibles dans le formulaire de modification du foyer.

Les fiches familles permettent édition, envoi du lien de connexion, activation/désactivation et suppression avec confirmation. Les enfants sont rattachés au foyer, avec classe et assurance pour l’année sélectionnée, et peuvent être marqués actifs ou sortis. Les habilitations et leur historique sont consultables.

**Cantine sans repas (v5.2)** : l’indicateur mairie sur la fiche enfant est activable et retirable. Il convertit ses déclarations CANT en MSR lors de leur résolution, sans réécrire les choix de la famille : tarif MSR pour le midi seul, aucun repas fournisseur, présence maintenue dans la liste intervenants. Les garderies restent inchangées ; un forfait sélectionné est facturé au tarif « Forfait sans repas cantine » (9 € par défaut, configurable). Cet indicateur est distinct des allergies alimentaires.

### Présences déclarées et exports

Sélection d’une famille et d’un **mois** de l’année scolaire, puis corrections jour par jour par le modèle d’exceptions, **sans verrou de préavis pour la mairie**. Les corrections sont notifiées au foyer.

L’export CSV mensuel utilise les déclarations résolues, avec protection contre les formules de tableur. Il contient nom, prénom, classe, contact parent, date, jour et service.

### Menus cantine

Saisie hebdomadaire des menus du lundi, mardi, jeudi et vendredi. Le même contenu alimente le menu public et le portail. L’envoi aux familles actives ayant des enfants actifs est manuel ; aucun envoi périodique automatique.

### Commande fournisseur

Comptage des **repas de midi et goûters** à partir du planning effectif. Les goûters correspondent à la garderie du soir, y compris via un forfait réalisable. Un enfant avec allergie alimentaire est exclu des deux comptages, mais reste sur les listes de présence. MSR ne compte pas dans les repas commandés.

L’e-mail fournisseur présente un tableau par jour : **Standard / Sans porc / Végétarien**, total midi, goûters et total semaine. Chaque repas n’appartient qu’à une catégorie. Le libellé famille « Sans viande » devient « Végétarien » dans l’e-mail. Celui-ci n’est plus découpé par classe.

« Envoyer au fournisseur » ouvre une popin montrant le sujet et le rendu de l’e-mail. « Retour » ferme l’aperçu ; « Confirmer l’envoi » déclenche l’envoi. Les comptages et l’e-mail envoyé sont archivés pour conserver l’historique malgré les modifications ultérieures du planning.

### Facturation

Facturation **libre** : la mairie génère les factures quand elle le décide, pour n'importe quel mois (passé, courant ou futur — les montants futurs viennent des rythmes et exceptions déjà déclarés). Un PDF par famille, détail par enfant et prestation, puis envoi individuel ou groupé. Un mois entier (lignes et PDF) se supprime en un clic confirmé pour être régénéré ; la génération et l'envoi sont décorréliés — régénérer conserve le statut d'envoi, seul l'envoi (ou renvoi) met la date à jour. Les fichiers sont protégés et téléchargeables depuis l'espace famille.

Pour les familles en prélèvement, un **export SEPA du mois** (.ods OpenDocument, ouvrable dans LibreOffice) livre une ligne par famille : IBAN déchiffré, BIC, titulaire, adresse, référence de mandat, numéro de facture, montant du mois et objet du prélèvement. Le fichier est construit à la demande, jamais stocké, et son téléchargement est journalisé.

Le forfait n'est pas facturé en plus de ses composantes. MSR possède son propre tarif ; le flag mairie « Cantine sans repas » bascule le forfait au tarif FSR. Les montants du planning restent des estimations ; les règlements sont traités hors plugin.

### Modèles e-mails et réglages

Les sujets et corps des e-mails transactionnels sont personnalisables avec les variables proposées et réinitialisables. Les récapitulatifs utilisent l’année scolaire. La commande fournisseur a aussi un pied de mail facultatif et les variables `{{site}}`, `{{semaine}}`, `{{total}}`, `{{gouters}}`, `{{standard}}`, `{{sans_porc}}`, `{{vegetarien}}`. Un pied vide est masqué. Les gabarits utilisent du HTML en tables et des styles en ligne.

Réglages : tarifs des prestations et du forfait sans repas, préavis global de repli (la configuration annuelle prime), notifications mairie, coordonnées de facturation et créancier SEPA, documents PDF, validation automatique, campagne de réinscription et progression des classes, validité des liens, page et code SIDSCM. Le choix de variante Planning n’est plus proposé.

## Listes intervenantes SIDSCM

Page dédiée avec le shortcode `[periscolaire_sidscm]`, en plein écran, configurable dans Réglages. Le code d’accès est revérifié côté serveur à chaque appel ; vide, il désactive l’accès. Le navigateur le conserve localement jusqu’à « Verrouiller ». Les points d’entrée sont protégés par une limitation de fréquence.

Trois onglets : **Garderie matin, Cantine, Garderie soir**. Les enfants attendus proviennent du planning résolu. Un enfant est présent par défaut ; le pointage permet de signaler son absence et est conservé séparément dans `attendance`.

- **Vue Jour** : liste pointable et compteur de présents. À la garderie du soir, heure de départ indépendante du pointage et panneau des personnes autorisées, en lecture seule.
- **Vue Semaine** : tableau des enfants attendus par jour de service, en lecture seule. L’écran concerne la semaine courante, sans navigation historique.
- **Cantine** : allergies signalées en tête de liste et régimes affichés. Les enfants MSR, y compris via l’indicateur mairie, restent visibles avec « Midi sans repas ». Les enfants apportant leur repas sont distingués des couverts à commander.

Les listes excluent les jours sans école. Les habilitations sont relues depuis le foyer et les personnes autorisées ; elles ne sont pas une copie figée à l’inscription.

## Règles métier

| Code | Prestation | Tarif par défaut |
|---|---|---:|
| GM | Garderie matin | 1,85 € |
| CANT | Cantine | 5,80 € |
| GS | Garderie soir | 4,70 € |
| FORF | Forfait journée | 11,70 € |
| MSR | Midi sans repas | 1,00 € |
| FSR | Forfait sans repas cantine (appliqué automatiquement aux enfants flagués) | 9,00 € |

Tarifs modifiables dans Réglages.

- Service lundi, mardi, jeudi et vendredi, hors vacances, fériés et fermetures.
- Résolution : un jour fermé est exclu ; sinon l’exception prime sur le rythme. Facturation, listes, commandes et exports utilisent ce modèle commun.
- Préavis annuel de 48 h par défaut, mesuré depuis 00 h du jour de service ; 0 désactive le verrou. Modifier le rythme préserve les jours déjà verrouillés. La mairie peut corriger sans préavis.
- Assurance annuelle nécessaire pour ajouter des prestations.
- FORF couvre GM + CANT + GS. Une composante fermée rend le forfait irréalisable ; les prestations restantes sont résolues individuellement.
- À la saisie, MSR est compatible avec GM et GS mais exclusif de CANT et FORF. L’indicateur mairie convertit le midi à la résolution et applique le tarif « Forfait sans repas cantine » lorsqu’un forfait est sélectionné.
- Une allergie exclut des commandes repas/goûters ; elle ne constitue pas à elle seule l’indicateur de tarification MSR.
- La présence réelle est distincte de la déclaration facturable. Aucun remboursement automatique d’absence.

## Données et migrations

Les versions du plugin et du schéma sont distinctes. Les migrations sont exécutées par l’installateur. L’approbation d’une demande est transactionnelle ; les relations sont protégées par des clés étrangères et les services par des contraintes CHECK. Les contraintes manquantes sont signalées en administration et leur pose est retentée.

La migration v5 déduit un rythme des anciennes déclarations avec un seuil de 60 %, puis conserve les écarts. Les anciennes tables ne pilotent plus les déclarations courantes ; elles restent disponibles pour la vérification de migration. Le script `bin/verify-planning-migration.php` compare les lignes historiques à la résolution et signale divergences, anomalies et jours supplémentaires issus du rythme. Cette vérification ne remplace pas la validation d’un cycle de facturation.

Une exception devenue identique au rythme est supprimée. Les jours verrouillés sont préservés lors d’une modification du rythme. Les configurations, calendriers et fermetures sont préchargés et mis en cache pendant la requête pour limiter les lectures SQL répétées.

Le préfixe `wp_` ci-dessous dépend de l’installation WordPress.

| Table | Usage |
|---|---|
| `wp_psc_parents` | Foyers, contacts, accès et paiement |
| `wp_psc_children` | Identité, régime, allergies, indicateur cantine sans repas, statut |
| `wp_psc_school_years` | Années des dossiers d’inscription |
| `wp_psc_child_school_years` | Classe, inscription, règlement et assurance par année |
| `wp_psc_school_year` | Configuration du planning annuel : dates, vacances et préavis |
| `wp_psc_holidays` | Fériés du planning |
| `wp_psc_pattern` | Rythme par enfant, année, jour de semaine et service |
| `wp_psc_exception` | Ajout/retrait ponctuel par enfant, date et service |
| `wp_psc_school_calendar` | Calendrier importé et corrections manuelles |
| `wp_psc_service_closures` | Fermetures par prestation |
| `wp_psc_requests` | Demandes d’inscription |
| `wp_psc_pickup_persons` | Habilitations courantes et retraits |
| `wp_psc_pickup_history` | Historique des modifications d’habilitation |
| `wp_psc_attendance` | Présence réelle et heure de départ |
| `wp_psc_menus` | Menus hebdomadaires |
| `wp_psc_supplier_orders` | Commandes et e-mails archivés |
| `wp_psc_invoices` | Factures et chemins PDF |
| `wp_psc_registrations`, `wp_psc_trimestres`, `wp_psc_calendar_days` | Ancien modèle, conservé sur les installations migrées |

## Sécurité

### Messages aux familles et confidentialité

Pour les messages diffusés par la mairie, la commune conserve la date de première consultation pendant l'année scolaire en cours plus un an, uniquement afin de vérifier que l'information a bien été reçue. Cette donnée ne sert à aucun profilage et n'est pas réutilisée. Les e-mails ne contiennent aucun pixel de suivi : la lecture est enregistrée seulement lors de l'ouverture du message dans le portail ou via son lien nominatif.

- Contrôle d'accès systématique : chaque action d'administration vérifie **à la fois** la capacité de l'utilisateur (`psc_manage_periscolaire`, accordée par défaut aux administrateurs et aux éditeurs — voir [Capacité d'accès personnalisée](#capacité-daccès-personnalisée)) et un nonce WordPress (protection CSRF) — l'un ne remplace pas l'autre.
- Cloisonnement des données : un parent ne peut agir que sur ses propres enfants, contrôlé côté serveur à chaque requête.
- Requêtes SQL préparées (`$wpdb->prepare()`) systématiquement, y compris avec un nombre variable de paramètres.
- Échappement systématique des sorties (`esc_html`/`esc_attr`/`esc_url`).
- Jetons de connexion et de vérification stockés **hachés** (HMAC-SHA256), jamais en clair ; comparaison à temps constant (`hash_equals`).
- **Le jeton du lien de connexion transite par l'URL** — c'est inhérent au principe du lien magique. Son exposition est contenue à chaque étape : il est vérifié sur `init` (priorité 5), donc **avant que la moindre page ne soit rendue**, puis retiré de l'URL par une redirection ; il expire en 30 minutes (réglable) ; il n'est stocké qu'en haché. Il n'est **pas** à usage unique : les passerelles de sécurité des messageries (Outlook SafeLinks, Proofpoint…) pré-cliquent les liens des e-mails entrants pour les analyser, souvent avant même que le parent n'ouvre son mail — un jeton consommé au premier GET était donc dépensé par la passerelle, et le parent se voyait refuser l'accès en cliquant. Le lien reste donc réutilisable pendant toute sa fenêtre de validité : c'est la session ouverte (cookie signé, révocable) qui constitue le contrôle d'accès effectif, pas le caractère unique du lien. Il ne reste donc qu'une trace dans le **journal d'accès du serveur web**, où il arrive valable — pour 30 minutes au plus. Filtrer ce journal supposerait un accès à la configuration du serveur, dont on ne dispose pas en hébergement mutualisé : le risque résiduel est assumé, et aucune modification du code ne le réduirait.
- Limitation de fréquence (anti-spam / anti-énumération) sur les formulaires publics ; réponse identique qu'une adresse soit connue ou non. La fenêtre est **fixe** : son échéance est fixée à la première tentative et n'est pas repoussée par les suivantes, sans quoi un attaquant maintiendrait le compteur vivant indéfiniment et priverait durablement une famille visée de son lien de connexion. L'adresse IP est lue depuis `REMOTE_ADDR`, jamais depuis un en-tête fourni par le client (cf. [Derrière un répartiteur de charge](#derrière-un-répartiteur-de-charge-ou-un-cdn)).
- Champ honeypot sur le formulaire de demande d'inscription.
- Protection contre l'injection de formules CSV sur l'export.
- Sessions familles signées côté serveur (cookie `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS) — aucun mot de passe stocké.
- **La déconnexion invalide réellement la session.** Un cookie signé se vérifie sans rien consulter : le supprimer du navigateur n'en retire qu'une copie, et quiconque en détient une autre — poste partagé, profil synchronisé — pourrait s'en servir jusqu'à son expiration. Chaque session porte donc un identifiant propre, ajouté à une courte liste de révocation à la déconnexion. La liste ne grossit pas : chaque entrée s'efface en même temps que la session qu'elle invalide. L'identifiant étant propre à la session et non au foyer, un parent qui se déconnecte ne déconnecte pas l'autre — le compte est partagé entre les deux.
- **La révocation des accès est durable, pas dépendante d'un cache.** Un registre d'époques de session vit en base : chaque cookie porte l'époque de son ouverture, et tout événement sensible (retrait ou remplacement du second parent, changement d'adresse du titulaire) incrémente le compteur du foyer — toutes les sessions antérieures meurent instantanément, même copiées ailleurs, même si un cache externe évince une révocation individuelle, et le lien de connexion encore valable est effacé. Les cookies antérieurs à ce mécanisme restent acceptés en époque 0, dans la limite de leur durée de 12 h.
- **Aucun IBAN n'est jamais écrit en clair.** Sans primitive de chiffrement disponible, ou si le chiffrement échoue, l'enregistrement est REFUSÉ avec un message lisible (famille, demande publique) plutôt que d'écrire l'IBAN lisible en base ; la migration historique laisse les lignes en l'état et retente, idempotente.
- **Aucun intermédiaire ne conserve les pages du plugin.** Le portail authentifie hors WordPress et des URL portent des jetons : tout le rendu (portail famille, espace intervenants, wizard public) émet `Cache-Control: no-store, no-cache, must-revalidate, private` — un cache d'extension, un CDN ou un proxy ne peut plus servir le HTML d'une famille à une autre.
- **IBAN validé par clé de contrôle réelle** (mod-97, ISO 7064), BIC validé par format ; rejet côté serveur indépendant de la validation du navigateur.
- IBAN affiché **masqué** dans les listes du backoffice (seuls le pays et les 4 derniers caractères apparaissent) ; il n'apparaît en clair que dans le formulaire de modification d'une famille, où la mairie en a besoin pour saisir le prélèvement dans son outil bancaire.
- **IBAN chiffré au repos** (XSalsa20-Poly1305 via libsodium, repli AES-256-GCM) : une copie de la base — sauvegarde égarée, export SQL — ne livre aucune coordonnée bancaire exploitable. La clé vit dans `wp-config.php`, jamais en base. L'IBAN est également **effacé de la demande d'inscription dès son approbation**, puisqu'il a été reporté sur la fiche famille : il n'existe plus qu'à un seul endroit.
- Le PDF du mandat de prélèvement SEPA (contient l'IBAN en clair) n'est **jamais stocké sur le serveur** : généré en fichier temporaire, joint à l'e-mail, puis supprimé immédiatement.
- Justificatifs d'assurance scolaire limités à 1 Mo, formats PDF/JPG/PNG uniquement (vérifiés côté serveur, pas seulement par l'attribut `accept` du champ fichier).
- **Documents stockés hors du dossier des médias.** Justificatifs d'assurance et factures PDF sont écrits sous `wp-content/psc-private/` — jamais sous `wp-content/uploads/`, qui est systématiquement servi en HTTP : leurs noms étant prévisibles (`child-12.pdf`, `facture-7.pdf`), ils y auraient été téléchargeables par simple énumération d'URL. Ils ne sont accessibles que par les routes de téléchargement du plugin, après contrôle de session et d'appartenance. Le dossier reçoit un `.htaccess` et un `web.config` bloquants ; ces fichiers n'étant pas lus par toutes les configurations (nginx notamment les ignore), l'extension vérifie **depuis le navigateur de l'administrateur** que le dossier est réellement injoignable — seul point de vue qui reflète ce qu'un visiteur atteint vraiment — et affiche sinon un avertissement. Le correctif proposé ne demande aucun accès au serveur (cf. [Emplacement des documents](#emplacement-des-documents)).
- Fermer un jour du calendrier scolaire avec des inscriptions existantes exige une **confirmation explicite** après avertissement — pas de suppression accidentelle en un clic.

---

## Conservation des données

- Purge WP-Cron des demandes non vérifiées après 7 jours et traitées après 90 jours.
- Suppression d’un foyer/enfant avec nettoyage du planning, dossiers annuels, pointages, habilitations et documents associés. Les demandes historiques ne sont pas automatiquement rattachées au compte famille.
- L’historique des habilitations conserve les retraits, mais il est effacé lors de la suppression de l’enfant ; sa durée de conservation propre reste à définir par la mairie.
- Les allergies sont des données de santé facultatives, limitées à l’alimentation, restituées aux acteurs concernés par l’accueil et la restauration.
- Les IBAN sont collectés uniquement pour le prélèvement. Les e-mails passent par le transport WordPress configuré ; la recherche d’adresse transmet le texte saisi à l’API BAN depuis le navigateur. La saisie manuelle reste possible.
- Désactiver le plugin ne supprime pas les données. La désinstallation les efface uniquement si `PSC_REMOVE_DATA_ON_UNINSTALL` vaut `true` dans `wp-config.php` : tables, options/transients et documents du plugin.
- Adapter l’information des familles et les durées de conservation au registre des traitements de la commune.

## Installation

1. Placer le plugin dans `wp-content/plugins/periscolaire-registration/` et l’activer.
2. Dans **Périscolaire > Années scolaires**, vérifier le dossier annuel actif et la configuration du planning : dates, vacances, fériés, préavis.
3. Charger le calendrier officiel depuis le bloc d’import et vérifier les jours ouverts dans le calendrier scolaire.
4. Renseigner les tarifs, informations mairie/SEPA, notifications et documents PDF dans Réglages.
5. Créer une page contenant `[periscolaire_form]` et communiquer son URL aux familles.
6. Si nécessaire, créer une page `[periscolaire_sidscm]`, la sélectionner dans Réglages et définir son code d’accès.
7. Vérifier le transport des e-mails et la protection des documents avant ouverture du service.

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

Les vacances se configurent dans **Années scolaires** : les plages personnalisées du planning sont prioritaires ; à défaut, le calendrier importé est utilisé. Les corrections manuelles et fermetures par prestation complètent ce calcul. Voir [Calendrier scolaire](#calendrier-scolaire).

## Développement local

### Podman

Le projet tourne localement avec WordPress, MySQL 8 et Mailpit. Le nom des fichiers Compose reste `docker-compose.yml`, même avec Podman.

```bash
podman machine start
MAILPIT_ENABLED=true podman compose --profile mailpit up -d
podman ps
```

La première commande n’est nécessaire que si la machine Podman est arrêtée. Les services locaux habituels sont :

| Conteneur | Accès |
|---|---|
| `plugin-extrascolaire-wordpress-1` | http://localhost:8080 |
| `plugin-extrascolaire-db-1` | MySQL sur le réseau des conteneurs |
| `plugin-extrascolaire-mailpit-1` | http://localhost:8025 |

Le plugin, `mu-plugins/` et `theme/Archive/` sont montés depuis le dépôt ; les données WordPress et MySQL persistent dans des volumes. Ne pas retirer ces volumes pour un simple redémarrage.

Mailpit ne démarre pas avec un simple `podman compose up -d` : son profil doit être activé. `MAILPIT_ENABLED=true` doit également être transmis au conteneur WordPress pour que `mu-plugins/mailpit-smtp.php` y dirige les e-mails. La valeur de repli Compose est `false`.

### Thème et WP-CLI

Le thème « Montgeroult Familles » reste dans **`theme/Archive`** : WordPress utilise ce dossier comme identifiant. Pour afficher le masthead famille/intervenants, choisir le modèle **Espace Familles** dans les attributs de la page.

L’image WordPress ne fournit pas WP-CLI par défaut. Le setup Playwright télécharge le PHAR dans `.cache/` au besoin et le copie dans `/usr/local/bin/wp-cli.phar` du conteneur. Une fois disponible :

```bash
podman exec -u www-data plugin-extrascolaire-wordpress-1 php /usr/local/bin/wp-cli.phar --path=/var/www/html plugin get periscolaire-registration
```

Le montage de production est documenté dans [docs/self-hosting-docker.md](docs/self-hosting-docker.md) ; ses ports et son exposition de Mailpit diffèrent de l’environnement local.

### Vérifications

```bash
npm ci
npm run lint:js
composer install
composer phpstan
podman exec plugin-extrascolaire-wordpress-1 php /var/www/html/wp-content/plugins/periscolaire-registration/tests/unit/run.php
```

Les tests unitaires s’exécutent sans WordPress ni base : helpers bancaires, chiffrement et résolution du planning, dont forfait, MSR et conversion « Cantine sans repas ».

```bash
npx playwright install chromium
PSC_CONTAINER_ENGINE=podman PSC_WP_CONTAINER=plugin-extrascolaire-wordpress-1 npm run test:e2e
```

**Les E2E peuplent et modifient la base locale** : ils doivent viser une instance de développement. Mailpit est nécessaire pour lire les liens de connexion et les notifications. Le moteur et le conteneur ci-dessus sont déjà les valeurs locales par défaut.

Les scénarios couvrent le parcours parent, habilitations, passage d’année, SIDSCM, commande fournisseur, rythme/exceptions et absence de débordement horizontal. Les tests Planning vérifient le rendu et les lignes en base : suppression des exceptions inutiles, copie fratrie, retour au rythme, assurance et cohérence des deux écrans.

Le filet de sécurité complet et la décision de couverture sont consignés dans
[`docs/refactoring-safety-net.md`](docs/refactoring-safety-net.md). La
restauration isolée de la base locale se vérifie avec `npm run
test:backup-restore` ; le script ne doit viser qu'un environnement jetable ou
une copie prévue à cet effet.

`npm run demo:e2e` produit le parcours de démonstration. Les scénarios partagent l’instance et s’exécutent avec un seul worker. Le seed peut figer l’horloge via `psc_test_frozen_now` et `mu-plugins/psc-frozen-clock.php` ; un bandeau l’indique. Pour revenir à l’heure réelle :

```bash
podman exec -u www-data plugin-extrascolaire-wordpress-1 php /usr/local/bin/wp-cli.phar --path=/var/www/html option delete psc_test_frozen_now
```

Vérification de la migration historique, sans modification par le script de comparaison :

```bash
podman exec -u www-data plugin-extrascolaire-wordpress-1 php /usr/local/bin/wp-cli.phar --path=/var/www/html --require=/var/www/html/wp-content/plugins/periscolaire-registration/bin/verify-planning-migration.php verify-planning-migration
```

La CI comprend lint PHP et tests unitaires sur PHP 7.4 à 8.3, PHPStan niveau 3, ESLint et E2E sur une installation dédiée. Les échecs E2E disposent d’artefacts/traces et d’un résumé. Le workflow de release prépare le paquet distribuable.

## Structure du projet

| Chemin | Rôle |
|---|---|
| `periscolaire-registration.php` | Point d’entrée et version |
| `includes/class-psc-installer.php` | Schéma, migrations et contraintes |
| `includes/class-psc-planning.php` | Lecture/écriture et résolution du rythme et des exceptions |
| `includes/helpers/planning.php` | Règles pures de résolution et facturation |
| `includes/class-psc-school-year.php` | Configuration du planning et calcul des jours d’école |
| `includes/class-psc-school-years.php` | Dossiers annuels et passage d’année |
| `includes/class-psc-school-calendar.php` | Import calendrier et fermetures |
| `includes/class-psc-admin*.php` | Socle et contrôleurs mairie par domaine |
| `includes/class-psc-frontend*.php` | Socle et contrôleurs famille par domaine |
| `includes/class-psc-invoices.php`, `class-psc-supplier-orders.php`, `class-psc-sidscm.php` | Facturation, commandes et intervenants |
| `includes/helpers/` | Services, dates, accès, validations, fichiers, chiffrement, notifications admin |
| `templates/portal-planning-2.php` | Planning principal avec rythme et exceptions |
| `templates/portal-planning-1.php` | Ancien écran jour par jour conservé |
| `templates/portal-habilitations.php` | Habilitations de la fratrie |
| `templates/email/` | Gabarit commun et e-mail fournisseur |
| `assets/js/planning-2.js` | Interactions et re-rendu du planning |
| `assets/js/psc-ajax.js` | Transport AJAX partagé par les écrans |
| `assets/css/` | Styles portail, administration et intervenants |
| `tests/`, `playwright/`, `bin/` | Tests, setup, seeds et vérifications WP-CLI |
| `journeys/`, `helpers/` | Parcours et outils de démonstration |
| `theme/Archive/`, `mu-plugins/` | Thème du site et outils de l’environnement local |
| `.github/workflows/` | CI et release |
| `uninstall.php` | Effacement optionnel à la désinstallation |

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
