# Périscolaire — Inscriptions

Plugin WordPress pour la gestion des inscriptions aux services périscolaires municipaux (garderie matin, cantine, garderie soir, forfait journée).

Remplace les fichiers calendriers remplis à la main par un formulaire en ligne accessible aux familles, avec un backoffice centralisé pour la mairie.

---

## Fonctionnalités

### Côté familles
- Connexion sans compte WordPress — accès par lien e-mail à usage unique (30 min)
- Calendrier interactif pour planifier les inscriptions jour par jour
- Récapitulatif du planning envoyé par e-mail à la demande
- Modification possible jusqu'à N heures avant le jour concerné (configurable)
- Formulaire de demande d'inscription pour les nouvelles familles

### Côté mairie (backoffice)
- Gestion des trimestres avec génération automatique du calendrier (week-ends et jours fériés exclus)
- Fermeture manuelle de plages de dates (vacances, fermetures exceptionnelles)
- File de modération des demandes d'inscription (approbation / rejet avec motif)
- Gestion des familles et des enfants
- Vue récapitulative des inscriptions par trimestre avec export CSV
- **Génération de factures PDF mensuelles** par famille, envoyables par e-mail en un clic
- E-mails transactionnels en HTML avec layout responsive

### Sécurité
- Protection CSRF (nonces WordPress sur tous les formulaires)
- Requêtes SQL préparées (`$wpdb->prepare()`)
- Échappement systématique des sorties
- Jetons de connexion hachés (HMAC-SHA256)
- Limitation de fréquence (anti-spam / anti-énumération)
- Champ honeypot sur le formulaire de demande
- Protection contre l'injection de formules CSV
- Sessions signées côté serveur (cookie `SameSite=Lax`, `HttpOnly`, `Secure`)

### Conformité RGPD
- Suppression automatique des demandes non vérifiées après 7 jours
- Suppression automatique des demandes traitées après 90 jours
- Aucun mot de passe stocké — authentification par jeton temporaire uniquement

---

## Prérequis

- WordPress 5.8+
- PHP 7.4+
- Extension PHP : `openssl` (pour `random_bytes()`), `iconv` (pour la génération PDF)

---

## Installation

1. Télécharger le dépôt et placer le dossier dans `wp-content/plugins/`
2. Activer le plugin depuis **Extensions > Extensions installées**
3. Un menu **Périscolaire** apparaît dans la barre latérale de l'administration
4. Créer un premier trimestre dans **Périscolaire > Trimestres**
5. Créer une page WordPress et y insérer le shortcode `[periscolaire_form]`
6. Partager l'URL de cette page aux familles

---

## Configuration

### Tarifs
**Périscolaire > Réglages > Tarifs** — définir le prix unitaire de chaque prestation.

### Délai de modification
**Périscolaire > Réglages** — nombre d'heures avant le jour concerné en-deçà duquel les familles ne peuvent plus modifier leur planning (0 = pas de verrou, max 720 h).

### Notifications mairie
Activer la copie e-mail à la mairie lors de chaque validation de planning par une famille.

### Capacité d'accès personnalisée
Par défaut, seuls les administrateurs WordPress (`manage_options`) accèdent au backoffice. Pour donner accès à un rôle dédié sans droits admin complets :

```php
add_filter('psc_manage_capability', fn() => 'gerer_periscolaire');
```

### Nombre maximum d'enfants par famille
```php
add_filter('psc_max_children_per_user', fn() => 5);
```

---

## Facturation

1. Aller dans **Périscolaire > Factures**
2. Sélectionner le mois
3. Cliquer sur **Générer les factures** — un PDF est créé pour chaque famille ayant des inscriptions ce mois
4. Envoyer les factures par e-mail individuellement ou en masse

Les PDFs sont stockés dans `wp-content/uploads/periscolaire/factures/YYYY-MM/` et ne sont pas accessibles directement par URL.

---

## Développement local

Un environnement de test complet est disponible via [Podman](https://podman.io/) (ou Docker) :

```bash
podman compose up -d
```

Cela démarre :
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

Créer le fichier `mu-plugins/mailpit-smtp.php` dans votre installation WordPress locale :

```php
<?php
add_filter('wp_mail_from', fn() => 'wordpress@periscolaire.local');
add_filter('wp_mail_from_name', fn() => 'WordPress Local');
add_action('phpmailer_init', function($phpmailer) {
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
│   ├── helpers.php                 # Fonctions utilitaires
│   ├── class-psc-installer.php     # Création / migration des tables
│   ├── class-psc-admin.php         # Backoffice WordPress
│   ├── class-psc-frontend.php      # Formulaire parent
│   ├── class-psc-mailer.php        # Tous les e-mails (HTML)
│   ├── class-psc-parents.php       # Authentification familles
│   ├── class-psc-requests.php      # Demandes d'inscription
│   ├── class-psc-invoices.php      # Génération PDF et envoi factures
│   └── fpdf/                       # Bibliothèque FPDF (génération PDF)
├── templates/
│   ├── email/layout.php            # Layout HTML commun pour les e-mails
│   ├── admin-*.php                 # Vues backoffice
│   └── frontend-*.php              # Vues formulaire parent
└── assets/
    ├── css/                        # Styles admin et frontend
    └── js/                         # Scripts frontend
```

### Tables en base de données

| Table | Description |
|---|---|
| `wp_psc_trimestres` | Périodes (trimestres) avec dates de début/fin |
| `wp_psc_calendar_days` | Jours du calendrier (ouvert/fermé) par trimestre |
| `wp_psc_parents` | Comptes familles (sans lien avec les users WordPress) |
| `wp_psc_children` | Enfants rattachés à une famille |
| `wp_psc_registrations` | Inscriptions jour × enfant × prestation |
| `wp_psc_requests` | Demandes d'inscription en attente de validation |
| `wp_psc_invoices` | Factures générées (métadonnées + chemin PDF) |

---

## Licence

Ce plugin est distribué sous licence [GNU General Public License v2](LICENSE).

La bibliothèque [FPDF](http://www.fpdf.org/) incluse dans `includes/fpdf/` est distribuée sous licence libre (permission d'utilisation, modification et distribution sans restriction).
