# Périscolaire — Inscriptions

Plugin WordPress pour la gestion complète des services périscolaires municipaux : garderie matin, cantine, garderie soir, forfait journée, menus de cantine, calendrier scolaire et facturation.

Remplace les fichiers papier/Excel remplis à la main par un site accessible aux familles, avec un backoffice centralisé pour la mairie.

> **Document de travail.** Ce README couvre l'ensemble des fonctionnalités actuelles, façade famille et façade mairie, pour faciliter la relecture et la remontée de retours. Voir [Points ouverts / à valider](#points-ouverts--à-valider) en fin de document.

---

## Sommaire

- [Vue d'ensemble](#vue-densemble)
- [Côté familles](#côté-familles)
- [Côté mairie](#côté-mairie-backoffice)
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

Une famille dépose une demande d'inscription en ligne (avec acceptation du règlement intérieur et, si elle règle par prélèvement, un mandat SEPA). Une fois validée par la mairie, elle se connecte sans mot de passe (lien reçu par e-mail) pour déclarer, jour par jour, les prestations souhaitées pour chacun de ses enfants. La mairie pilote tout depuis un menu **Périscolaire** dédié dans l'administration WordPress : calendrier scolaire, trimestres, demandes, familles, enfants, factures, menus de cantine, modèles d'e-mails, réglages.

Aucun compte WordPress n'est nécessaire côté famille. Aucun paiement en ligne n'est intégré : le mode de paiement (chèque/espèces ou prélèvement SEPA) est déclaré à l'inscription, mais les prélèvements réels restent traités par la mairie via sa banque.

---

## Côté familles

### 1. Première inscription

Formulaire public (« Première inscription ? ») en une seule page, en trois temps annoncés au parent :

1. **Coordonnées et enfants** — e-mail, nom, téléphone, adresse, un ou plusieurs enfants (prénom, nom, classe). Jusqu'à 5 enfants par demande.
2. **Règlement intérieur** — texte intégral affiché dans le formulaire (horaires, engagement trimestriel, facturation, discipline, responsabilité...), avec une case à cocher obligatoire d'approbation. *Acceptation = case cochée, horodatée en base — ce n'est pas une signature électronique qualifiée.*
3. **Mode de paiement** — chèque/espèces (par défaut) ou prélèvement automatique SEPA. En sélectionnant le prélèvement, un bloc supplémentaire apparaît :
   - Créancier affiché automatiquement (nom de la commune + identifiant créancier SEPA, définis dans les réglages) ;
   - Mandat : titulaire du compte, adresse (recopiable en un clic depuis l'adresse familiale), IBAN, BIC ;
   - Règlement concernant le prélèvement (texte intégral) + case à cocher d'approbation obligatoire.

L'IBAN est validé par clé de contrôle (norme ISO 7064 mod-97, comme un vrai IBAN) et le BIC par son format ; toute valeur invalide est rejetée **côté serveur**, indépendamment de la validation du navigateur.

Un champ piège invisible (honeypot) et une double limitation de fréquence (par IP et par e-mail) protègent le formulaire contre les robots.

### 2. Confirmation d'adresse puis modération

Le parent reçoit un e-mail de confirmation (lien valable 3 jours). Tant qu'il n'a pas cliqué, la demande **n'apparaît pas** dans le backoffice — ceci empêche un robot de remplir la file de modération avec des adresses inventées. Une fois confirmée, la mairie est notifiée et examine la demande (voir [Demandes d'inscription](#demandes-dinscription)). À la validation, la famille et ses enfants sont créés automatiquement, y compris le mode de paiement et — si applicable — les informations du mandat SEPA (avec une référence de mandat générée automatiquement) ; le parent reçoit aussitôt son lien d'accès.

### 3. Connexion sans mot de passe

Une fois enregistrée par la mairie, la famille saisit son e-mail sur la page publique et reçoit un lien de connexion à usage unique (valable 30 minutes). Aucun mot de passe à créer ni à retenir. La session ouverte dure 12 heures (cookie signé, non modifiable côté client).

### 4. Planning interactif

Calendrier présenté mois par mois, un bloc par enfant. Chaque case cochée (Garderie Matin / Cantine / Garderie Soir / Forfait journée) est enregistrée **immédiatement** — pas de bouton « Envoyer » à chercher, pas de fichier à renvoyer par e-mail. Les jours fermés (week-ends, mercredis, vacances scolaires, jours fériés) n'apparaissent pas dans la grille.

Un verrou de modification (48 h par défaut, réglable) grise les cases trop proches de la date concernée — contrôlé aussi côté serveur, pas seulement par l'affichage grisé.

Un bouton « Valider et recevoir mon planning » envoie à la demande un récapitulatif complet par e-mail (jours, prestations, totaux par service, montant indicatif — la facturation définitive reste de la responsabilité de la mairie).

### 5. Gestion des enfants (libre-service)

Depuis son espace, une famille peut ajouter un enfant (dans la limite d'un nombre maximum configurable) et déclarer, pour chacun, des préférences alimentaires cantine — **sans porc** et/ou **végétarien** — visibles par la mairie dans la liste des enfants (pour transmission au prestataire de restauration).

### 6. Menu de cantine — accès libre

Un widget affiché sur la page publique, **sans connexion requise**, présente le menu de la semaine (lundi/mardi/jeudi/vendredi — pas de menu le mercredi, pas de cantine ce jour-là). Navigation semaine précédente/suivante façon calendrier, avec retour rapide à la semaine en cours. Pendant les vacances scolaires ou tout autre jour sans école, le widget affiche « Pas d'école cette semaine » à la place du menu.

---

## Côté mairie (backoffice)

Menu **Périscolaire** dans l'administration WordPress, avec neuf sections :

### Inscriptions

Vue de correction : sélection d'une famille et d'une période, grille identique à celle vue par le parent, modifiable directement par la mairie (utile pour une inscription reçue par téléphone ou papier). Chaque enregistrement envoie une notification à la famille. Export CSV du récapitulatif par trimestre (protégé contre l'injection de formules Excel).

### Trimestres

Création d'un trimestre (libellé + dates) : le calendrier se génère automatiquement, jour par jour, avec fermeture par défaut des week-ends, des mercredis, des jours fériés et des vacances scolaires (zone C, voir [Calendrier scolaire](#calendrier-scolaire)). Un seul trimestre peut être actif à la fois : c'est celui visible par les familles. Un formulaire permet aussi de fermer manuellement une plage de dates (fermeture exceptionnelle non couverte par le calendrier scolaire officiel).

### Demandes d'inscription

File de modération des nouvelles familles (voir [Première inscription](#1-première-inscription)). Pour chaque demande en attente : coordonnées déclarées, statut d'acceptation du règlement intérieur, mode de paiement choisi et — si prélèvement — titulaire, adresse, **IBAN partiellement masqué** (`FR14 •••• •••• 2606`), BIC et statut d'acceptation du règlement de prélèvement. Les nom/prénom/classe des enfants sont modifiables avant validation (informations déclaratives, à vérifier). Refus possible avec motif optionnel, notifiable ou non au demandeur.

### Familles

Liste de toutes les familles, avec mode de paiement et IBAN masqué en un coup d'œil. Édition complète par famille : coordonnées, mode de paiement, informations SEPA (IBAN/BIC revalidés à l'enregistrement), référence de mandat. Envoi ou renvoi du lien de connexion, activation/désactivation d'accès (une famille désactivée perd l'accès immédiatement, même en session ouverte).

### Enfants

Liste de tous les enfants avec leur famille de rattachement et leur régime cantine (sans porc / végétarien). Rattachement d'un nouvel enfant à une famille existante (la mairie tient cette liste — les familles ne peuvent pas créer un enfant sans rattachement).

### Factures

Facturation **mensuelle**. Sélection d'un mois ayant des inscriptions, génération en un clic d'un PDF par famille (tarif par prestation, détail par enfant, total). Envoi individuel ou en masse par e-mail, avec pièce jointe PDF. Les PDF sont stockés hors de portée d'accès direct par URL.

### Menus cantine

Saisie du menu semaine par semaine (lundi/mardi/jeudi/vendredi, pas de mercredi). Chaque semaine reste un brouillon tant qu'elle n'a pas été explicitement envoyée — **aucun envoi automatique, aucune tâche planifiée** : c'est toujours un clic volontaire de la mairie (« Envoyer aux familles ») qui déclenche l'e-mail, adressé à toutes les familles actives ayant au moins un enfant actif. Le même menu alimente aussi le widget public (voir [Menu de cantine](#6-menu-de-cantine--accès-libre)).

### Calendrier scolaire

Le périscolaire suit le calendrier scolaire officiel de la **zone C** (Créteil, Montpellier, Paris, Toulouse, Versailles), publié par le ministère de l'Éducation nationale — pas d'école, pas de périscolaire, pas de cantine, pas de menu.

- **Chargement automatique** : un bouton télécharge et importe le flux iCal officiel (`education.gouv.fr/vacances`), zone C uniquement, et ferme les jours concernés dans tous les trimestres. Le flux distingue proprement le dernier jour de vacances du jour de reprise (`DTEND` exclusif dans le flux officiel) — un jour de reprise n'est jamais fermé par erreur.
- **Liste lisible** : les jours fermés sont regroupés par période contiguë (ex. « 17/10/2026 → 01/11/2026 — Vacances de la Toussaint — 16 jours — Import officiel »).
- **Correction manuelle exceptionnelle** : un jour peut être fermé (formation des enseignants, fermeture ponctuelle...) ou rouvert à la main. Une correction manuelle n'est **jamais** écrasée par un rechargement ultérieur du calendrier officiel.
- **Fermer un jour où des familles ont déjà déclaré des présences déclenche un écran d'avertissement** (nombre d'inscriptions et de familles concernées, détail par enfant/prestation) avant toute action. Une fois confirmé : les inscriptions concernées sont supprimées (elles ne seront jamais facturées) et chaque famille concernée reçoit un e-mail listant précisément ce qui a été retiré.

### Modèles e-mails

Personnalisation du sujet et du corps de chaque e-mail transactionnel (lien de connexion, récapitulatif, facture, menu, demandes...), avec variables (`{{site}}`, `{{nom}}`, `{{trimestre}}`...) et réinitialisation possible au texte par défaut.

### Réglages

- **Tarifs** par prestation (Garderie Matin, Cantine, Garderie Soir, Forfait journée).
- **Délai de modification** avant chaque jour concerné (0 à 720 h).
- **Notification mairie** — copie de chaque validation de planning parent.
- **Adresse e-mail** de la mairie pour les notifications.
- **Informations de facturation** : intitulé, adresse, téléphone, fax, e-mail, commune, logos gauche/droit (utilisés dans le PDF de facture), texte de pied de page, **identifiant créancier SEPA (ICS)** affiché aux familles sur le mandat de prélèvement.

---

## Règles métier

- Le périscolaire (garderie et cantine) fonctionne **lundi, mardi, jeudi, vendredi** — jamais le mercredi.
- Le calendrier suit les vacances scolaires de la **zone C**, jours fériés compris.
- Un seul trimestre est actif à la fois ; les trimestres précédents restent consultables.
- Une inscription à une prestation, pour un trimestre, est **ferme et définitive** une fois le délai de modification dépassé.
- La facturation est **mensuelle**, calculée à partir des inscriptions réellement déclarées (jour × enfant × prestation).
- Aucun remboursement automatique en cas d'absence, hors cas prévus par le règlement intérieur (fermeture, sortie scolaire, hospitalisation, maladie de plus de 3 jours justifiée).

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
- Fermer un jour du calendrier scolaire avec des inscriptions existantes exige une **confirmation explicite** après avertissement — pas de suppression accidentelle en un clic.

---

## Conformité RGPD

- Suppression automatique des demandes non vérifiées après 7 jours, des demandes traitées après 90 jours (WP-Cron).
- Les données bancaires (IBAN/BIC) ne sont collectées que si la famille choisit le prélèvement, et uniquement dans ce but.
- Aucune donnée n'est envoyée à un tiers : le mandat SEPA est stocké pour un traitement manuel par la mairie via sa banque, il n'y a pas d'intégration bancaire automatisée.
- Suppression des données du plugin à la désinstallation possible via `define('PSC_REMOVE_DATA_ON_UNINSTALL', true);` dans `wp-config.php` (désactivé par défaut, pour éviter une perte accidentelle).
- Le formulaire d'inscription affiche une mention d'information sur le traitement des données — à adapter au registre des traitements de la commune.

---

## Installation

1. Télécharger le dépôt et placer le dossier dans `wp-content/plugins/`.
2. Activer le plugin depuis **Extensions**.
3. Le menu **Périscolaire** apparaît dans l'administration.
4. Aller dans **Périscolaire > Calendrier scolaire** et cliquer sur « Charger le calendrier officiel ».
5. Créer un premier trimestre dans **Périscolaire > Trimestres**, puis l'activer.
6. Renseigner les informations de facturation (dont l'identifiant créancier SEPA) dans **Périscolaire > Réglages**.
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

```php
add_filter('psc_max_children_per_user', fn() => 5);
```

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
- [Mailpit](https://mailpit.axllent.org/) (capture des e-mails) sur **http://localhost:8025**

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

Fichier `mu-plugins/mailpit-smtp.php` :

```php
<?php
add_filter('wp_mail_from', fn() => 'wordpress@periscolaire.local');
add_filter('wp_mail_from_name', fn() => 'WordPress Local');
add_action('phpmailer_init', function ($phpmailer) {
    $phpmailer->isSMTP();
    $phpmailer->Host     = 'mailpit';
    $phpmailer->Port     = 1025;
    $phpmailer->SMTPAuth = false;
});
```

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
│   ├── class-psc-frontend.php      # Page publique (planning, menu, login)
│   ├── class-psc-mailer.php        # Tous les e-mails (HTML, layout commun)
│   ├── class-psc-parents.php       # Authentification familles (sans mot de passe)
│   ├── class-psc-requests.php      # Demandes d'inscription (modération)
│   ├── class-psc-invoices.php      # Génération PDF et envoi factures
│   ├── class-psc-menus.php         # Menus de cantine hebdomadaires
│   ├── class-psc-school-calendar.php  # Calendrier scolaire zone C (import iCal + corrections manuelles)
│   ├── class-psc-email-templates.php  # Modèles d'e-mails personnalisables
│   └── fpdf/                       # Bibliothèque FPDF (génération PDF)
├── templates/
│   ├── email/layout.php            # Layout HTML commun pour les e-mails
│   ├── admin-*.php                 # Vues backoffice
│   └── frontend-*.php              # Vues page publique
└── assets/
    ├── css/                        # Styles admin et frontend
    └── js/                         # Scripts frontend
```

### Tables en base de données

| Table | Description |
|---|---|
| `wp_psc_trimestres` | Périodes (trimestres) avec dates de début/fin |
| `wp_psc_calendar_days` | Jours du calendrier (ouvert/fermé + motif) par trimestre |
| `wp_psc_parents` | Comptes familles (mode de paiement, IBAN/BIC, référence de mandat SEPA) |
| `wp_psc_children` | Enfants rattachés à une famille (dont régime cantine) |
| `wp_psc_registrations` | Inscriptions jour × enfant × prestation |
| `wp_psc_requests` | Demandes d'inscription (règlement, mode de paiement, mandat SEPA déclarés) |
| `wp_psc_invoices` | Factures mensuelles générées (métadonnées + chemin PDF) |
| `wp_psc_menus` | Menus de cantine hebdomadaires |
| `wp_psc_school_calendar` | Jours fermés zone C (import officiel + corrections manuelles) |

---

## Points ouverts / à valider

Liste non exhaustive de ce qui mérite un retour avant mise en production :

- **Textes légaux** (règlement intérieur, règlement de prélèvement) retranscrits depuis les documents Word fournis par la mairie — à comparer mot pour mot avec les originaux avant publication.
- **Acceptation par case à cocher, pas signature électronique qualifiée** : à valider que ce niveau suffit pour la mairie (le SIDISCM demandait historiquement une signature papier).
- **Aucun export bancaire SEPA (fichier `pain.008`)** : les mandats sont stockés et consultables dans le backoffice, mais la mairie doit encore les saisir manuellement dans son outil bancaire pour lancer les prélèvements.
- **Pas de paiement en ligne** : les tarifs affichés restent indicatifs, la facturation réelle (chèque/espèces/prélèvement bancaire) est gérée hors plugin.
- **WP-Cron** (purge RGPD des demandes) dépend des visites du site — prévoir un cron système sur un site peu fréquenté.
- **Envoi d'e-mails** : le plugin utilise `wp_mail()`. Sans configuration SMTP, les messages partent souvent en indésirables ou pas du tout — à tester en conditions réelles avant ouverture aux familles, le lien de connexion en dépend entièrement.

---

## Licence

Ce plugin est distribué sous licence [GNU General Public License v2](LICENSE).

La bibliothèque [FPDF](http://www.fpdf.org/) incluse dans `includes/fpdf/` est distribuée sous licence libre (permission d'utilisation, modification et distribution sans restriction).
