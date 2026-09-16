# Plan de publication de la documentation administrateur (GitHub Pages)

Dépôt : `eflye/wp-plugin-extrascolaire`. Ce plan est produit par l'architecte documentation ; il ne contient aucune rédaction de page. Chaque étape est conçue pour être exécutée sans qu'aucune décision de conception reste à prendre.

---

## A. Mode d'emploi pour l'exécutant

Donnez ce gabarit à l'exécutant pour chaque étape, en remplaçant `NN` par le numéro réel :

```
Exécute l'étape NN de docs-plan/PLAN.md.
1. Lis la section « C. Fiche de style » puis l'étape NN en entier.
2. Lis uniquement les fichiers listés dans « Fichiers à lire ».
3. Crée ou modifie uniquement les fichiers listés dans « Fichiers à créer/modifier ».
4. Vérifie chaque critère d'acceptation et affiche le résultat des commandes.
5. Fais le commit avec le message indiqué.
6. Arrête-toi et rends compte : fichiers touchés, résultats des vérifications, questions éventuelles.
```

Deux adaptations valables pour **toutes** les étapes, décidées ici pour ne pas être rediscutées à chaque fois :

- **Emplacement des sources** : le dossier `docs/` existe déjà à la racine du dépôt et contient de la documentation **développeur** sans rapport avec ce chantier (`docs/audits/`, `docs/images/`, `docs/self-hosting-docker.md`, `docs/schema-inventory-2026-09-10.md`, `docs/refactoring-safety-net.md`, `docs/export-pain008.md`, `docs/menu-labels.md`). Conflit avéré → conformément à la consigne de repli, **toutes les pages MkDocs vivent dans `documentation/`**, pas dans `docs/`. `mkdocs.yml` reste à la racine du dépôt. Ne renommez jamais un fichier existant de `docs/` : il continue sa vie telle quelle.
- **Captures d'écran** : en conséquence, leur chemin est `documentation/assets/screenshots/`, pas `docs/assets/screenshots/`.

---

## B. Synthèse de l'audit

| Domaine | Élément | Référence | Statut |
|---|---|---|---|
| Menu admin | 6 sections, 16 entrées cliquables sous « Périscolaire » | `includes/class-psc-admin.php:90-176` | Implémenté |
| Réglages | Aucun usage de l'API `register_setting()` — 13 blocs `get_option()`/`update_option()` sur la page Réglages, écrits par un unique handler | `templates/admin-settings.php` (13×`<h2>`, listés en étape par étape ci-dessous) ; `includes/class-psc-admin-config.php:16-127` (`handle_save_settings`) | Implémenté |
| Réglages fournisseur | Séparés de Réglages depuis la réorg. du menu, onglet dédié | `includes/class-psc-admin-cantine.php:19-30` (`handle_save_supplier_settings`) ; `templates/admin-supplier-settings.php` | Implémenté |
| Constantes `wp-config.php` | `PSC_PRIVATE_DIR`, `PSC_ENCRYPTION_KEY`, `PSC_CLIENT_IP_HEADER`, `PSC_TRUSTED_PROXIES`, `PSC_REMOVE_DATA_ON_UNINSTALL` | `includes/helpers/files.php:39` ; `includes/helpers/crypto.php:26` ; `includes/helpers/throttle.php:102,107` ; `uninstall.php:16,58` | Implémenté, aucune n'est obligatoire |
| Envoi d'e-mails | `wp_mail()` natif, aucune configuration SMTP fournie par le plugin | `includes/class-psc-mailer.php:21,73` ; avertissement déjà rédigé en `readme.txt:272-276` | Implémenté (dépend de l'hébergement) |
| Tâches planifiées | 6 tâches récurrentes + 2 évènements ponctuels | Voir tableau détaillé en étape 20 | Implémenté |
| Rôles / capabilities | Aucun rôle créé (`add_role`) ; 4 capabilities ajoutées à des rôles existants | `includes/class-psc-installer.php:31-61` ; `includes/helpers/core.php:27-38` | Implémenté |
| Modération des demandes | Approbation / refus, auto-approbation optionnelle | `includes/class-psc-requests.php:28-29` ; `includes/class-psc-admin-requests.php:10` ; `templates/admin-requests.php` | Implémenté |
| Factures PDF | Génération, régénération, suppression, envoi, exports CSV/ODS | `includes/class-psc-admin-invoices.php:12-17` ; `templates/admin-factures.php` | Implémenté |
| Mandats SEPA | Acceptation et téléchargement du mandat côté famille ; export pain.008 et identité créancier côté mairie | `includes/class-psc-frontend-profil.php:103` ; `includes/class-psc-sepa-mandate.php` ; `includes/class-psc-admin-invoices.php:17` ; `docs/export-pain008.md` (source à réutiliser) | Implémenté |
| Import iCal zone C | Import automatique (URL ministère) + import manuel (fichier .ics) + corrections manuelles de jours | `includes/class-psc-admin-school-years.php:36-37,279-323` ; `includes/class-psc-school-calendar.php:13-31,160` | Implémenté |
| Purge RGPD | Export/effacement natifs WordPress, texte de politique de confidentialité suggéré, consentement dédié allergies, purge auto. des enfants sortis | `includes/class-psc-privacy.php:21-22,61` ; `includes/class-psc-retention.php:22-28` ; `readme.txt` § RGPD | Implémenté |
| Régimes alimentaires | Champs par enfant (sans porc / sans viande / allergies + consentement dédié), pas d'écran de configuration globale | `includes/class-psc-frontend-enfants.php` ; `templates/portal-enfants.php`, `templates/guest-request.php`, `templates/admin-children.php` | Implémenté (par enfant, pas un réglage global — nav ajustée en conséquence, cf. section D) |
| Modèles d'e-mails | Écran dédié, réinitialisation possible | `templates/admin-email-templates.php` ; `includes/class-psc-admin-config.php:11-13` | Implémenté |
| Annulations / absences | Auto-annulation famille (portail), lecture et correction mairie | `templates/portal-dashboard.php` ; `includes/class-psc-frontend-inscriptions.php:56` ; `templates/admin-inscriptions.php` | Implémenté |
| Menus de cantine | Saisie, aperçu qualité (bio/Label Rouge), envoi | `templates/admin-menus.php` ; `includes/class-psc-admin-cantine.php:10-11` ; `docs/menu-labels.md` (note dev existante, sans rapport avec les libellés de menu WP Admin) | Implémenté |
| Documents (assurance) | Dépôt famille, revue mairie (auto ou manuelle) | `templates/admin-assurances.php` ; `includes/class-psc-frontend-documents.php:16` | Implémenté |
| Entité année scolaire | Table dédiée, calendrier, promotion de classe, réinscription | `includes/class-psc-school-years.php:331` (`apply_promotion`) ; `includes/class-psc-frontend-reinscription.php:26,43` | **Implémenté** |
| Trimestre | Concept retiré : facturation mensuelle, planning continu (dates + vacances + fériés) ; tables historiques détachées, conservées pour l'intégrité des anciennes données | `includes/class-psc-installer.php:296,308-310,638-700` | **Retiré** (glossaire à formuler en conséquence, cf. Hors périmètre v1) |
| Multi-responsables par foyer | Second parent : champs dédiés, ajout/retrait depuis le portail | `includes/class-psc-frontend-profil.php:18-21,149-` | **Implémenté** |
| Personnes autorisées | Table et écran dédiés, historique | `includes/class-psc-pickup-persons.php:17` ; `includes/class-psc-admin-familles.php:299` | **Implémenté** |
| Outillage Playwright | `playwright.config.ts` (projets test/demo), `playwright/global-setup.ts` (seed via WP-CLI + podman/docker exec), helpers de connexion admin/famille dans chaque spec | `playwright.config.ts:22-45` ; `playwright/global-setup.ts:1-40` ; ex. `loginAsAdmin()` dans `tests/school-year-promotion.spec.ts:109-115` | Réutilisable tel quel comme patron pour le script de captures |
| README | Section « Documentation » déjà présente | `README.md:122-128` | À compléter (étape 33) |
| Packaging | Pas de `.gitattributes`/`.distignore` : exclusion à la main via une liste `EXCLUSIONS` dans le workflow de release | `.github/workflows/release.yml:70,73` | Mécanisme identifié, à étendre (étape 03) |
| CI existante | 3 workflows (`lint.yml`, `e2e.yml`, `release.yml`), aucun ne construit ni ne déploie de documentation | `.github/workflows/*.yml` | À créer (étape 02) |

---

## C. Fiche de style (lue par l'exécutant à chaque étape)

- **Ton** : vouvoiement, phrases courtes, vocabulaire de la mairie plutôt que vocabulaire technique (« la fiche de l'enfant », pas « l'enregistrement en base »).
- **Formulations affirmatives et orientées action** : « Cliquez sur… », « Renseignez… », jamais « il est possible de… ».
- **Libellés d'interface en gras**, recopiés à l'identique du code (respectez majuscules, accents, apostrophes typographiques `'` telles qu'affichées — pas l'apostrophe droite du code PHP).
- **Structure type d'une page fonctionnelle**, dans cet ordre, avec ces titres H2 exacts :
  1. `## Objectif` (une phrase)
  2. `## Avant de commencer` (prérequis, droits nécessaires)
  3. `## Étapes` (liste numérotée, une action par étape, capture si utile juste après l'étape qu'elle illustre)
  4. `## Résultat attendu`
  5. `## Pour aller plus loin` (liens internes seulement)
- **Un seul H1 par page** (le titre défini par `# ...` en première ligne du fichier) ; les sections ci-dessus sont toutes des H2 (`##`), leurs sous-parties éventuelles des H3 (`###`) — jamais de saut de niveau (pas de H3 sans H2 parent).
- **Admonitions MkDocs** (syntaxe `!!! tip` / `!!! warning`) :
  - `tip` pour les astuces ;
  - `warning` pour toute action irréversible ou peu réversible : purge RGPD, suppression d'une facture ou d'une famille, envoi de factures, suppression d'un jour de calendrier avec inscriptions.
- **Glossaire de référence** — une définition unique par terme, à réutiliser à l'identique partout (liens vers `glossaire.md#<ancre>` à la première occurrence de chaque terme sur une page) :

  | Terme | Définition |
  |---|---|
  | Foyer | Le compte d'une famille dans le plugin : un parent titulaire, éventuellement un second parent, et les enfants qui y sont rattachés. |
  | Responsable | Un parent identifié sur le foyer (titulaire ou second parent). |
  | Autorité parentale | La qualité juridique qui permet à un responsable d'inscrire un enfant et de décider pour lui ; le plugin ne la vérifie pas, il présume qu'un responsable saisi l'a. |
  | Responsable de facturation | Le parent titulaire du foyer : c'est à son nom et à son adresse e-mail que les factures sont émises. |
  | Enfant | Une fiche enfant rattachée à un foyer, avec sa classe, son régime alimentaire et son année scolaire en cours. |
  | Classe | Le niveau scolaire d'un enfant (de PS à CM2), utilisé pour la montée de classe automatique. |
  | Année scolaire | La période de référence (rentrée à rentrée suivante) qui porte le calendrier, les vacances et les inscriptions ; une seule année est « active » à la fois. |
  | Trimestre | Notion retirée du plugin : la facturation est mensuelle et le planning continu (dates de l'année scolaire, vacances, jours fériés) ne découpe plus l'année en trimestres. Le terme ne désigne plus qu'un historique conservé pour les anciennes données. |
  | Service | Une prestation périscolaire facturable : garderie du matin, cantine, garderie du soir. |
  | Forfait journée | Un tarif unique regroupant garderie du matin + cantine + garderie du soir pour une journée, au lieu de facturer chaque service séparément. |
  | Réservation | La déclaration, par une famille, qu'un enfant utilisera un service un jour donné. |
  | Modération | La validation ou le refus, par la mairie, d'une demande d'inscription déposée par une famille inconnue du service. |
  | Absence | Le retrait d'une réservation déjà déclarée, signalé par la famille avant la date limite de modification. |
  | Mandat SEPA | L'autorisation signée par un responsable de facturation permettant à la mairie de prélever les factures sur son compte bancaire. |
  | Personne autorisée | Une personne, hors responsables du foyer, habilitée à venir chercher un enfant. |

- **Accessibilité (RGAA)** :
  - texte alternatif descriptif sur chaque capture (jamais « capture d'écran », toujours ce qu'elle montre : `![Formulaire d'ajout d'une année scolaire avec les champs Libellé, Date de début et Date de fin](...)`) ;
  - hiérarchie de titres continue (un seul H1, pas de saut de niveau) ;
  - intitulés de liens explicites (jamais « cliquez ici ») ;
  - l'information n'est jamais portée par la seule couleur (pas de « le bouton vert »).
- **Données** : exclusivement fictives dans les exemples et les captures. Jeu de données de référence (utilisé partout, y compris dans les captures — cf. étape 04) :
  - Commune : **Montgeroult** (nom déjà utilisé comme exemple dans le code du plugin, `templates/admin-settings.php:181`) ;
  - Foyer titulaire : **Camille Rivière**, `camille.riviere@example.invalid` ;
  - Second parent : **Hugo Rivière**, `hugo.riviere@example.invalid` ;
  - Enfants : **Noé Rivière** (CP), **Alma Rivière** (CE2) ;
  - Personne autorisée : **Sophie Martin** (grand-mère) ;
  - Aucun de ces noms ne doit apparaître ailleurs que dans le jeu de données fictif et les pages qui l'illustrent.

---

## D. Arborescence cible du site

Base de la consigne, ajustée sur deux points justifiés par l'audit (section B) :

1. **« Année scolaire et trimestres » → « Année scolaire »** : le concept de trimestre est retiré du plugin (facturation mensuelle, planning continu) — cf. glossaire et Hors périmètre v1. Documenter une page « trimestres » laisserait croire à une fonctionnalité active.
2. **« Régimes alimentaires » reste sous Configuration mais n'est pas un écran de réglages** : il n'existe pas de liste de régimes configurable globalement. La page documente les champs saisis par enfant (portail famille et écran **Enfants**) — c'est une page de référence, pas un mode d'emploi d'un écran de configuration.

Les intitulés de section (Configuration, Gestion au quotidien, Facturation, Cycle annuel, Installation & maintenance) sont de purs regroupements de navigation `mkdocs.yml` : ils ne portent pas de page d'index dédiée, seulement des pages filles.

```
Accueil                                        documentation/index.md
Démarrage rapide                               documentation/demarrage-rapide.md
Configuration
  Année scolaire                               documentation/configuration/annee-scolaire.md
  Calendrier et vacances                       documentation/configuration/calendrier-vacances.md
  Services et tarifs                           documentation/configuration/services-tarifs.md
  Régimes alimentaires                         documentation/configuration/regimes-alimentaires.md
  Modèles d'e-mails                            documentation/configuration/modeles-emails.md
Gestion au quotidien
  Tableau de bord                              documentation/gestion-quotidienne/tableau-de-bord.md
  Modération des inscriptions                  documentation/gestion-quotidienne/moderation-inscriptions.md
  Annulations et absences                      documentation/gestion-quotidienne/annulations-absences.md
  Menus de cantine                             documentation/gestion-quotidienne/menus-cantine.md
  Documents (assurance)                        documentation/gestion-quotidienne/documents-assurance.md
  Personnes autorisées                         documentation/gestion-quotidienne/personnes-autorisees.md
Facturation
  Factures PDF                                 documentation/facturation/factures-pdf.md
  Mandats SEPA                                 documentation/facturation/mandats-sepa.md
Cycle annuel
  Campagne de réinscription                    documentation/cycle-annuel/reinscription.md
  Passage de classe                            documentation/cycle-annuel/passage-de-classe.md
Données personnelles (RGPD)                    documentation/rgpd.md
Installation & maintenance (public technique)
  Prérequis                                    documentation/installation/prerequis.md
  Installation et activation                   documentation/installation/installation-activation.md
  Envoi des e-mails (SMTP)                      documentation/installation/emails-smtp.md
  Tâches planifiées                            documentation/installation/taches-planifiees.md
  Sauvegarde et mise à jour                    documentation/installation/sauvegarde-mise-a-jour.md
  Dépannage et FAQ                             documentation/installation/depannage-faq.md
Glossaire                                      documentation/glossaire.md
Contribuer à la documentation                  documentation/contribuer.md
```

---

## E. Étapes numérotées

### Règle de croissance du `nav:` de mkdocs.yml

`mkdocs build --strict` échoue si un fichier `.md` existe sans figurer dans `nav`, ou si `nav` référence un fichier absent. **`nav` grandit donc d'une seule entrée par étape de contenu, ajoutée dans le même commit que la page qu'elle référence** — jamais toute l'arborescence d'un coup. Chaque étape de contenu indique la ligne exacte à ajouter et son point d'insertion (juste après l'entrée créée par l'étape précédente de la même section, ou création de la section si c'est sa première page). L'ordre des étapes ci-dessous suit exactement l'ordre de la section D : en les exécutant dans l'ordre, l'insertion est toujours « à la fin de la section en cours ».

### Étape 01 — Socle MkDocs
- Objectif : avoir un site MkDocs Material qui compile en strict avec une page d'accueil provisoire.
- Prérequis : aucune.
- Fichiers à lire : `README.md:1-20` ; `.github/workflows/lint.yml` (style de commentaires du dépôt).
- Fichiers à créer/modifier : `mkdocs.yml` (racine) ; `requirements.txt` (racine) ; `documentation/index.md`.
- Instructions :
  1. Créez `requirements.txt` à la racine (pas dans `documentation/`, qui est publié tel quel par MkDocs) avec exactement :
     ```
     mkdocs==1.6.1
     mkdocs-material==9.7.7
     ```
     Si `pip install -r requirements.txt` échoue parce qu'une de ces versions n'est plus disponible sur PyPI, remplacez-la par le dernier correctif de la même série mineure (1.6.x / 9.7.x) et mentionnez ce changement dans le compte rendu de l'étape — ne montez jamais de version majeure sans validation.
  2. Créez `mkdocs.yml` à la racine avec :
     - `site_name: Périscolaire — Documentation administrateur`
     - `site_url: https://eflye.github.io/wp-plugin-extrascolaire/`
     - `repo_url: https://github.com/eflye/wp-plugin-extrascolaire`
     - `docs_dir: documentation`
     - `theme.name: material`, `theme.language: fr`
     - palette claire/sombre avec bascule (deux entrées `palette:`, `media: "(prefers-color-scheme: light)"` et `"(prefers-color-scheme: dark)"`, couleur `primary: indigo` dans les deux — contraste conforme AA vérifié par le thème Material par défaut, ne changez pas la couleur sans revalider le contraste) ;
     - `theme.features:` avec au minimum `navigation.instant`, `navigation.tracking`, `navigation.top`, `search.suggest`, `search.highlight`, `content.code.copy` ;
     - `plugins: [search: {lang: fr}]` (recherche en français) ;
     - `markdown_extensions:` avec `admonition`, `pymdownx.details`, `attr_list`, `md_in_html`, `toc: {permalink: true}` ;
     - `nav:` contenant pour l'instant une seule entrée : `- Accueil: index.md`.
  3. Créez `documentation/index.md` :
     ```markdown
     # Périscolaire — Documentation administrateur

     Documentation à l'usage du secrétariat de mairie qui utilise le plugin Périscolaire au quotidien.

     Cette page d'accueil est provisoire ; son contenu définitif est écrit à l'étape 07 du plan de publication.
     ```
- Contenu attendu : n/a (page technique provisoire, remplacée à l'étape 07).
- Captures : aucune.
- Critères d'acceptation :
  ```
  pip install -r requirements.txt
  mkdocs build --strict
  ```
  Sortie attendue : `mkdocs build --strict` se termine sans ligne `WARNING` ni `ERROR`, un dossier `site/` est créé.
- Message de commit : `build: initialise MkDocs Material (socle du site)`
- Point d'arrêt : non (le point d'arrêt du groupe Socle + CI a lieu après l'étape 02).

### Étape 02 — Intégration continue (build + déploiement GitHub Pages)
- Objectif : construire la documentation sur chaque pull request et la déployer sur GitHub Pages à chaque push sur `main`.
- Prérequis : étape 01 terminée.
- Fichiers à lire : `.github/workflows/lint.yml` (style : concurrency, matrice, commentaires en tête de fichier) ; `.github/workflows/release.yml:1-25` (style des `permissions:`).
- Fichiers à créer/modifier : `.github/workflows/docs.yml`.
- Instructions :
  1. Créez un unique workflow avec deux jobs.
  2. Déclencheurs : `pull_request` (branches `["**"]`, filtré aux chemins `documentation/**`, `mkdocs.yml`, `requirements.txt`) et `push` sur `main` (mêmes filtres de chemins).
  3. `concurrency: group: docs-${{ github.ref }}`, `cancel-in-progress: true` (même motif que `lint.yml`).
  4. Job `build` (toujours exécuté, PR et push) :
     - `actions/checkout@v4` ;
     - `actions/setup-python@v5` avec `python-version: "3.12"` ;
     - `pip install -r requirements.txt` ;
     - `mkdocs build --strict` ;
     - sur push `main` uniquement (`if: github.ref == 'refs/heads/main'`) : `actions/upload-pages-artifact@v3` avec `path: site`.
  5. Job `deploy` (seulement sur push `main`, `needs: build`, `if: github.ref == 'refs/heads/main'`) :
     - `environment: name: github-pages, url: ${{ steps.deployment.outputs.page_url }}` ;
     - `permissions:` du job (pas du workflow entier) : `pages: write`, `id-token: write` ; le job `build`, lui, n'a besoin d'aucune permission élevée (`contents: read` implicite suffit, ne rien déclarer) ;
     - une seule étape : `actions/deploy-pages@v4`, avec `id: deployment`.
  6. Épinglez chaque action à son tag de version majeure exact ci-dessus (déjà la convention du dépôt dans `lint.yml`/`e2e.yml`) — n'utilisez pas de SHA complet, ce n'est pas la convention ici.
  7. Commentaire en tête de fichier expliquant pourquoi deux jobs (build vérifiable sur PR sans permissions d'écriture ; déploiement séparé, seul à porter `pages: write`), sur le modèle des en-têtes de `e2e.yml`.
- Contenu attendu : n/a (fichier YAML).
- Captures : aucune.
- Critères d'acceptation :
  1. `python -c "import yaml, sys; yaml.safe_load(open('.github/workflows/docs.yml'))"` ne lève aucune exception (validité YAML).
  2. Après le commit et le push de cette étape (fait par Etienne, cf. section F pour l'activation préalable de Pages) : le workflow « Documentation » apparaît dans l'onglet Actions du dépôt, le job `build` réussit sur la pull request, le job `deploy` réussit sur `main` et l'URL `https://eflye.github.io/wp-plugin-extrascolaire/` affiche la page d'accueil provisoire de l'étape 01.
- Message de commit : `ci: build et déploie la documentation sur GitHub Pages`
- Point d'arrêt : **oui** — vérifiez avec Etienne que Settings › Pages est configuré (section F) et que le premier déploiement réussit réellement avant de continuer.

### Étape 03 — Exclusion de la documentation du paquet distribué
- Objectif : garantir que `documentation/`, `docs-plan/`, `mkdocs.yml`, `requirements.txt` et l'outillage de captures n'atterrissent jamais dans le zip du plugin.
- Prérequis : étape 02 terminée.
- Fichiers à lire : `.github/workflows/release.yml:55-77` (étapes « Prépare le dossier du plugin » et « Vérifie la complétude du paquet »).
- Fichiers à créer/modifier : `.github/workflows/release.yml`.
- Instructions :
  1. Ce dépôt n'utilise ni `.gitattributes` (`export-ignore`) ni `.distignore` : la liste `EXCLUSIONS` codée en dur à la ligne 70 de `release.yml` est le seul mécanisme de packaging. N'introduisez pas un second mécanisme : complétez cette liste.
  2. Ajoutez à la chaîne `EXCLUSIONS` (en conservant l'ordre alphabétique déjà en place) : `documentation`, `docs-plan`, `mkdocs.yml`, `requirements.txt`, et le dossier créé à l'étape 04 pour le script de captures (`scripts`, si l'étape 04 le crée à cet emplacement — vérifiez le nom exact retenu à l'étape 04 et ajustez cette liste en conséquence si besoin).
  3. Ne touchez à aucune autre ligne du fichier.
- Contenu attendu : n/a.
- Captures : aucune.
- Critères d'acceptation :
  ```
  git ls-files | cut -d/ -f1 | sort -u
  ```
  Chaque entrée listée qui n'est pas déjà copiée dans le paquet (`cp` de l'étape « Prépare le dossier du plugin ») doit apparaître dans `EXCLUSIONS` — vérifiez à l'œil que `documentation`, `docs-plan`, `mkdocs.yml`, `requirements.txt` y figurent désormais. Le test complet (téléchargement du zip, absence de `documentation/`) ne s'exécute qu'au prochain tag de version : ce n'est pas bloquant pour cette étape.
- Message de commit : `build: exclut la documentation du paquet distribué du plugin`
- Point d'arrêt : non.

### Étape 04 — Script de captures d'écran
- Objectif : produire, à la demande, toutes les captures d'écran utilisées par la documentation, à partir d'un jeu de données entièrement fictif.
- Prérequis : étape 03 terminée.
- Fichiers à lire : `playwright.config.ts` (en entier) ; `playwright/global-setup.ts` (en entier) ; `bin/seed-school-year-promotion.php` (en entier, comme patron d'un script de seed autonome) ; `tests/school-year-promotion.spec.ts:39-115` (patron de connexion admin/famille et de lecture WP-CLI) ; `helpers/mailpit.ts` (en entier) ; `package.json`.
- Fichiers à créer/modifier : `bin/seed-docs-screenshots.php` (nouveau) ; `playwright.screenshots.config.ts` (racine, nouveau) ; `scripts/screenshots/global-setup.ts` (nouveau) ; `scripts/screenshots/capture.spec.ts` (nouveau) ; `package.json` (ajout d'un script npm) ; les 21 fichiers PNG listés ci-dessous dans `documentation/assets/screenshots/`.
- Instructions :
  1. `bin/seed-docs-screenshots.php` : sur le modèle exact de `bin/seed-school-year-promotion.php` (commande WP-CLI dédiée, purge-et-recrée à chaque exécution). Il crée, avec le jeu de données fictif de la fiche de style (Camille et Hugo Rivière, Noé et Alma Rivière, Sophie Martin, commune Montgeroult) :
     - une année scolaire active couvrant la date du jour, avec quelques jours fériés/vacances ;
     - le foyer Rivière avec ses deux enfants inscrits, régimes variés (Noé : sans porc ; Alma : allergie déclarée et consentie — "Arachides, réaction cutanée") ;
     - Sophie Martin comme personne autorisée sur Noé ;
     - une demande d'inscription tierce encore « en attente » (pour la capture de modération) ;
     - un menu de cantine saisi pour la semaine prochaine ;
     - une facture générée et une non envoyée (pour les captures de facturation) ;
     - un identifiant créancier SEPA fictif renseigné dans les options (pour la capture d'export pain.008) ;
     - un justificatif d'assurance déposé pour Noé, en attente de revue.
  2. `playwright.screenshots.config.ts` : config Playwright minimale et séparée de `playwright.config.ts` (pas de troisième `project` dans le fichier existant : son `globalSetup` réseederait inutilement le parcours `parent-connu`, sans rapport avec les captures). `testDir: './scripts/screenshots'`, `globalSetup: require.resolve('./scripts/screenshots/global-setup')`, viewport `1280x800`, `headless: true`.
  3. `scripts/screenshots/global-setup.ts` : même mécanique que `playwright/global-setup.ts` (podman/docker exec de WP-CLI) mais exécute `bin/seed-docs-screenshots.php` au lieu de `bin/seed-journey.php`.
  4. `scripts/screenshots/capture.spec.ts` : un test Playwright par écran, connexion admin via le même formulaire que `loginAsAdmin()` (`tests/school-year-promotion.spec.ts:109-115`), connexion famille via lien magique capturé dans Mailpit (`helpers/mailpit.ts`, comme `loginAsFamily()` dans le même fichier aux lignes 117-133) quand la capture est côté portail. Chaque test fait un `page.screenshot({ path: 'documentation/assets/screenshots/<nom-exact>.png' })` après avoir amené l'écran dans l'état demandé. Liste exacte des captures à produire (nom de fichier — écran WP Admin ou portail — état à atteindre avant la capture) :
     1. `dashboard-a-faire.png` — `admin.php?page=psc_dashboard` — section **À faire** visible avec au moins une ligne non traitée.
     2. `menu-periscolaire-sections.png` — n'importe quel écran du plugin — sous-menu **Périscolaire** déplié dans la barre latérale, les 6 intitulés de section visibles.
     3. `annee-scolaire-creer.png` — `admin.php?page=psc_school_calendar_v2&tab=historique` — bloc **Créer une année scolaire**.
     4. `annee-scolaire-liste.png` — même écran — tableau **Années existantes**.
     5. `calendrier-vue-mois.png` — `admin.php?page=psc_school_calendar_v2` (onglet Calendrier par défaut) — vue du mois courant.
     6. `calendrier-import-officiel.png` — onglet Historique — bloc d'import du calendrier officiel.
     7. `services-tarifs.png` — `admin.php?page=psc_settings` — bloc **Tarifs des prestations**.
     8. `regimes-case-allergie.png` — `?psc_tab=enfants` (portail, connecté comme Camille Rivière) — case **Cet enfant a une allergie alimentaire** cochée avec le champ et la case de consentement visibles.
     9. `modeles-emails-liste.png` — `admin.php?page=psc_email_templates` — écran complet.
     10. `moderation-demandes-attente.png` — `admin.php?page=psc_requests` — section **En attente** avec la demande fictive.
     11. `annulation-absence-famille.png` — portail famille, tableau de bord — bouton d'annulation d'une prestation déclarée.
     12. `menus-cantine-saisie.png` — `admin.php?page=psc_menus` — formulaire de saisie rempli pour la semaine du seed.
     13. `documents-assurance-liste.png` — `admin.php?page=psc_assurances` — tableau de suivi avec le justificatif de Noé.
     14. `personnes-autorisees-liste.png` — `admin.php?page=psc_pickup_persons` — ligne de Sophie Martin.
     15. `factures-generer.png` — `admin.php?page=psc_factures` — bouton **Générer / Regénérer les factures de**.
     16. `factures-exports.png` — même écran — bloc **Exports par mois**.
     17. `sepa-export-pain008.png` — même écran — bloc d'export du fichier pain.008.
     18. `reinscription-fenetre.png` — `admin.php?page=psc_settings` — bloc **Fenêtre de réinscription**.
     19. `passage-classe-recapitulatif.png` — `admin.php?page=psc_passage_annee` — récapitulatif avec Noé et Alma.
     20. `rgpd-export-wp.png` — écran natif WordPress `admin.php?page=export-personal-data` — formulaire avec l'adresse de Camille Rivière saisie.
     21. `plugins-activation.png` — écran natif WordPress `plugins.php` — ligne du plugin Périscolaire, activé.
  5. Ajoutez dans `package.json` → `scripts` : `"docs:screenshots": "playwright test --config=playwright.screenshots.config.ts"`.
  6. Exécutez `npm run docs:screenshots` une première fois pour produire les 21 fichiers, puis committez les images : ce sont des fichiers binaires versionnés, régénérés à la demande par cette même commande (pas régénérés automatiquement en CI).
- Contenu attendu : n/a (outillage).
- Captures : les 21 fichiers listés ci-dessus, tous dans `documentation/assets/screenshots/`.
- Critères d'acceptation :
  ```
  npm run docs:screenshots
  ls documentation/assets/screenshots/ | wc -l
  ```
  Les 21 fichiers existent, chacun un PNG non vide (`file documentation/assets/screenshots/*.png` ne rapporte aucune ligne « empty »).
- Message de commit : `build: script de captures d'écran Playwright pour la documentation`
- Point d'arrêt : non.

### Étape 05 — Glossaire
- Objectif : publier la page de référence des 15 termes du métier, utilisée par toutes les autres pages.
- Prérequis : étape 04 terminée.
- Fichiers à lire : section C (tableau du glossaire) de ce plan ; `includes/class-psc-installer.php:296,308-310,638-700` (justification de la définition de « Trimestre »).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/glossaire.md`.
- Instructions :
  1. Reprenez le tableau du glossaire de la section C **mot pour mot**, dans le même ordre.
  2. Chaque terme est un titre `##` suivi d'une seule ancre implicite (celle générée par `toc.permalink`) — pas d'ancre manuelle à écrire.
  3. Aucun exemple, aucune illustration : une page de référence courte, une définition par terme, rien de plus.
  4. Nav : ajoutez `- Glossaire: glossaire.md` juste avant `- Contribuer à la documentation: contribuer.md` (qui n'existe pas encore — créez donc l'entrée Glossaire seule pour l'instant, en dernière position du `nav:`).
- Contenu attendu : un seul H1 (`# Glossaire`), puis un `##` par terme dans l'ordre du tableau de la section C, texte de la définition recopié à l'identique.
- Captures : aucune.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute le glossaire`
- Point d'arrêt : non.

### Étape 06 — Contribuer à la documentation
- Objectif : expliquer à un futur contributeur comment écrire une page conforme et régénérer les captures.
- Prérequis : étape 05 terminée.
- Fichiers à lire : section C de ce plan (fiche de style, en entier) ; `scripts/screenshots/capture.spec.ts` (créé à l'étape 04) ; `mkdocs.yml`.
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/contribuer.md`.
- Instructions :
  1. Expliquez, dans l'ordre : où vivent les sources (`documentation/`, jamais `docs/`), comment prévisualiser en local (`pip install -r requirements.txt` puis `mkdocs serve`), le rappel des règles de la fiche de style (ton, structure de page, admonitions, glossaire, accessibilité, données fictives) sans les recopier en entier — un lien vers ce plan (`docs-plan/PLAN.md` n'est pas publié : renvoyez plutôt vers un résumé de 5 lignes des règles), comment ajouter/retirer une capture (`npm run docs:screenshots`, nommage stable, jeu de données fictif de la section C).
  2. Précisez explicitement que `docs-plan/` et `docs/` (dossier historique développeur) ne font pas partie du site publié.
  3. Nav : ajoutez `- Contribuer à la documentation: contribuer.md` en toute dernière position du `nav:`.
- Contenu attendu : `## Où vivent les sources`, `## Prévisualiser en local`, `## Écrire une page`, `## Régénérer les captures d'écran`.
- Captures : aucune.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Contribuer à la documentation`
- Point d'arrêt : non.

### Étape 07 — Accueil (remplace la page provisoire)
- Objectif : donner au secrétariat une porte d'entrée claire vers les six familles de tâches de la documentation.
- Prérequis : étape 06 terminée.
- Fichiers à lire : `README.md:1-26` (ton et présentation du plugin, à réutiliser en plus court et pour le bon public) ; ce plan, section D (arborescence).
- Fichiers à créer/modifier : `documentation/index.md` (remplace le contenu provisoire de l'étape 01).
- Instructions :
  1. Cette page **n'est pas** une page fonctionnelle : elle n'a pas de section Objectif/Avant de commencer/Étapes/Résultat attendu — c'est une exception à la structure type de la fiche de style, indiquée ici explicitement pour ne pas être réinterprétée.
  2. Contenu : un paragraphe d'accroche (à qui s'adresse cette documentation, en une phrase : au secrétariat de mairie qui utilise le plugin Périscolaire) ; un rappel très court de ce que couvre le plugin (inscriptions, planning, cantine, facturation) sans détailler ; six blocs, un par section de la navigation (Configuration, Gestion au quotidien, Facturation, Cycle annuel, Données personnelles, Installation & maintenance), chacun une phrase + un lien vers sa première page ; un dernier lien explicite vers **Démarrage rapide**.
  3. Aucune capture sur cette page : elle ne montre aucun écran en particulier.
- Contenu attendu : H1 (`# Périscolaire — Documentation administrateur`), un `##` par bloc de section (six `##`), un lien vers Démarrage rapide en clôture.
- Captures : aucune.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: écrit la page d'accueil`
- Point d'arrêt : **oui** — faites valider le ton et la structure par Etienne sur cette première page réelle avant de poursuivre les 23 pages de contenu suivantes.

### Étape 08 — Démarrage rapide
- Objectif : donner au secrétariat une check-list de mise en service et de rentrée scolaire.
- Prérequis : étape 07 terminée.
- Fichiers à lire : `templates/admin-dashboard.php` (en entier) ; `includes/class-psc-admin.php:` méthodes `dashboard_stats()` et `dashboard_todos()` (chercher ces deux noms dans le fichier) ; `documentation/assets/screenshots/dashboard-a-faire.png`, `menu-periscolaire-sections.png` (déjà produites, étape 04).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/demarrage-rapide.md`.
- Instructions :
  1. Structure type de la fiche de style, complète.
  2. La check-list de rentrée reprend exactement les lignes possibles de **À faire** au tableau de bord (demandes en attente, menu de cantine non envoyé, commande fournisseur non envoyée, année scolaire absente ou proche de sa fin) — ne pas en inventer d'autres.
  3. Mentionnez que **Démarrage rapide** renvoie ensuite vers Configuration (première utilisation) puis Gestion au quotidien (usage courant), sans dupliquer leur contenu.
  4. Nav : insérez `- Démarrage rapide: demarrage-rapide.md` juste après `- Accueil: index.md` et avant `Configuration:` (qui n'existe pas encore : à créer à l'étape 09).
- Contenu attendu : structure type ; dans **Étapes**, une check-list numérotée correspondant à la liste "À faire" réelle du tableau de bord.
- Captures : `dashboard-a-faire.png`, `menu-periscolaire-sections.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Démarrage rapide`
- Point d'arrêt : non.

### Étape 09 — Année scolaire
- Objectif : expliquer comment créer, activer et configurer l'année scolaire en cours.
- Prérequis : étape 08 terminée.
- Fichiers à lire : `templates/admin-annees.php` (en entier) ; `includes/class-psc-admin-school-years.php:34-65` (créer/activer/archiver/modifier/supprimer une année) ; `includes/class-psc-school-year.php` (notion de configuration du planning : dates, vacances, préavis).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/configuration/annee-scolaire.md`.
- Instructions :
  1. Structure type complète.
  2. Couvrez, dans cet ordre : créer une année (formulaire **Créer une année scolaire**, champs **Libellé**, **Date de début**, **Date de fin**) ; activer une année (bouton **Activer**, rend l'année visible des familles — une seule active à la fois) ; archiver (bouton **Archiver**) ; modifier une ligne existante (bouton **Enregistrer**) ; supprimer (bouton **Supprimer**, avertissement irréversible) ; configurer le planning de l'année active (préavis de modification, plages de vacances).
  3. Admonition `warning` sur la suppression d'une année.
  4. Renvoi « Pour aller plus loin » vers **Calendrier et vacances** et **Passage de classe**.
  5. Nav : créez la section `Configuration:` avec sa première entrée : `- Configuration:` suivi de `- Année scolaire: configuration/annee-scolaire.md`, insérée juste après `Démarrage rapide` et avant `Glossaire`.
- Contenu attendu : structure type ; libellés exacts **Créer une année scolaire**, **Libellé**, **Date de début**, **Date de fin**, **Activer**, **Archiver**, **Enregistrer**, **Supprimer**, **Années existantes**.
- Captures : `annee-scolaire-creer.png`, `annee-scolaire-liste.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Année scolaire`
- Point d'arrêt : non.

### Étape 10 — Calendrier et vacances
- Objectif : expliquer l'import du calendrier scolaire officiel (zone C) et les corrections manuelles de jours.
- Prérequis : étape 09 terminée.
- Fichiers à lire : `templates/admin-calendar-v2.php` (en entier) ; `templates/partials/import-school-calendar.php` (en entier) ; `includes/class-psc-admin-school-years.php:265-323` (`handle_import_school_calendar`, `handle_upload_school_calendar`) ; `includes/class-psc-school-calendar.php:1-35,160-` (URL officielle, import).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/configuration/calendrier-vacances.md`.
- Instructions :
  1. Structure type complète.
  2. Couvrez : la vue mensuelle/hebdomadaire (onglet **Calendrier**) et sa légende de couleurs (reformulez la légende sans se fier à la seule couleur : nommez chaque état — Ouvert, Fermé, Prestation fermée, Hors année scolaire) ; l'import automatique depuis le calendrier officiel du ministère ; l'import manuel d'un fichier `.ics` quand le serveur n'a pas d'accès sortant ; la fermeture manuelle d'un jour ou d'une plage de dates (onglet **Historique**, bloc **Corriger un jour manuellement**), avec l'avertissement que des inscriptions existantes seront supprimées et les familles prévenues par e-mail.
  3. Admonition `warning` sur la fermeture d'une plage de dates avec inscriptions existantes.
  4. Nav : ajoutez `- Calendrier et vacances: configuration/calendrier-vacances.md` sous `Configuration:`, juste après `Année scolaire`.
- Contenu attendu : structure type ; libellés exacts **Charger le calendrier officiel**, **Fermer**, **Réouvrir ce jour**, onglets **Calendrier** / **Historique**.
- Captures : `calendrier-vue-mois.png`, `calendrier-import-officiel.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Calendrier et vacances`
- Point d'arrêt : non.

### Étape 11 — Services et tarifs
- Objectif : expliquer comment fixer les tarifs des prestations et le délai de modification du planning.
- Prérequis : étape 10 terminée.
- Fichiers à lire : `templates/admin-settings.php:1-37` ; `includes/helpers/services.php` (en entier : les 5 services + le forfait sans repas cantine) ; `includes/class-psc-admin-config.php:32-43`.
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/configuration/services-tarifs.md`.
- Instructions :
  1. Structure type complète.
  2. Couvrez les 5 services (Garderie Matin, Cantine, Garderie Soir, Forfait journée, Cantine sans repas) et leurs tarifs, puis le préavis minimum de modification (heures avant le jour concerné, 48h par défaut) et son effet (les familles ne peuvent plus annuler après ce délai, la mairie le peut toujours).
  3. Utilisez les termes du glossaire **service** et **forfait journée** avec des liens vers leurs définitions.
  4. Nav : ajoutez `- Services et tarifs: configuration/services-tarifs.md` sous `Configuration:`, après `Calendrier et vacances`.
- Contenu attendu : structure type ; libellés exacts **Tarifs des prestations**, **Préavis minimum**, noms des 5 services recopiés du code.
- Captures : `services-tarifs.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Services et tarifs`
- Point d'arrêt : non.

### Étape 12 — Régimes alimentaires
- Objectif : expliquer ce que sont les champs de régime et d'allergie d'un enfant, et qui peut les renseigner.
- Prérequis : étape 11 terminée.
- Fichiers à lire : `templates/portal-enfants.php` (bloc allergie, chercher `allergy`) ; `templates/guest-request.php` (bloc allergie du formulaire d'inscription) ; `templates/admin-children.php` ; `includes/class-psc-frontend-enfants.php` (validation, consentement).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/configuration/regimes-alimentaires.md`.
- Instructions :
  1. Structure type, avec un ajustement explicite dans **Objectif** : il n'existe pas d'écran de réglages pour les régimes — cette page documente des champs saisis ailleurs (inscription, fiche enfant), pas une configuration.
  2. Couvrez les 3 champs (**Sans porc**, **Sans viande**, allergie alimentaire en texte libre) et la case de consentement dédiée à l'allergie (donnée de santé : une case distincte de la description doit être cochée, horodatée). Précisez qui peut renseigner ces champs : la famille (inscription ou portail), ou la mairie depuis **Enfants**.
  3. Admonition `tip` : rappelez que l'allergie reste strictement alimentaire (aucun menu différencié, la famille fournit son propre repas).
  4. Nav : ajoutez `- Régimes alimentaires: configuration/regimes-alimentaires.md` sous `Configuration:`, après `Services et tarifs`.
- Contenu attendu : structure type ; libellé exact **Cet enfant a une allergie alimentaire**.
- Captures : `regimes-case-allergie.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Régimes alimentaires`
- Point d'arrêt : non.

### Étape 13 — Modèles d'e-mails
- Objectif : expliquer comment personnaliser le contenu des e-mails envoyés automatiquement aux familles.
- Prérequis : étape 12 terminée.
- Fichiers à lire : `templates/admin-email-templates.php` (en entier) ; `includes/class-psc-admin-config.php:11-13` ; `includes/class-psc-email-templates.php` (liste des modèles disponibles, méthode de réinitialisation).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/configuration/modeles-emails.md`.
- Instructions :
  1. Structure type complète.
  2. Listez les modèles réellement modifiables (tels que trouvés dans le fichier lu), les variables interpolées disponibles (ex. `{{semaine}}`), et le bouton de réinitialisation à un modèle par défaut.
  3. Admonition `tip` : tester l'e-mail avant envoi massif si un mécanisme d'aperçu existe (vérifiez dans le fichier lu ; sinon, ne pas l'inventer).
  4. Nav : ajoutez `- Modèles d'e-mails: configuration/modeles-emails.md` sous `Configuration:`, en dernière position de cette section (après `Régimes alimentaires`).
- Contenu attendu : structure type ; libellé exact du bouton d'enregistrement (relevé dans le fichier, ex. **Enregistrer tous les modèles**) et de réinitialisation.
- Captures : `modeles-emails-liste.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Modèles d'e-mails`
- Point d'arrêt : non.

### Étape 14 — Tableau de bord
- Objectif : expliquer ce que montre le tableau de bord et comment s'en servir chaque jour.
- Prérequis : étape 13 terminée.
- Fichiers à lire : `templates/admin-dashboard.php` (en entier) ; `includes/class-psc-admin.php`, méthodes `dashboard_stats()` et `dashboard_todos()`.
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/gestion-quotidienne/tableau-de-bord.md`.
- Instructions :
  1. Structure type complète.
  2. Distinguez clairement les deux blocs : les indicateurs globaux (familles actives, enfants actifs, année scolaire) qui ne demandent aucune action, et la liste **À faire** qui, elle, appelle une action et renvoie vers l'écran concerné.
  3. Ne dupliquez pas la check-list de rentrée de **Démarrage rapide** : renvoyez-y plutôt en **Pour aller plus loin**.
  4. Nav : créez la section `Gestion au quotidien:` avec sa première entrée `- Tableau de bord: gestion-quotidienne/tableau-de-bord.md`, insérée juste après `Configuration:` et avant `Glossaire`.
- Contenu attendu : structure type ; libellé exact **À faire**.
- Captures : `dashboard-a-faire.png` (déjà utilisée à l'étape 08 — c'est normal, la même image illustre deux pages différentes).
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Tableau de bord`
- Point d'arrêt : non.

### Étape 15 — Modération des inscriptions
- Objectif : expliquer comment approuver ou refuser une demande d'inscription déposée par une famille inconnue.
- Prérequis : étape 14 terminée.
- Fichiers à lire : `templates/admin-requests.php` (en entier) ; `includes/class-psc-admin-requests.php` (en entier) ; `includes/class-psc-requests.php:524-620` (`maybe_verify`, auto-approbation).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/gestion-quotidienne/moderation-inscriptions.md`.
- Instructions :
  1. Structure type complète.
  2. Couvrez : ce qu'une famille a déjà fait avant d'arriver dans cette liste (confirmation d'e-mail) ; la revue d'une demande **En attente** (les informations affichées, la correction possible du prénom/nom/classe avant validation, jamais celle du justificatif d'assurance ni de l'allergie déclarée) ; approuver, refuser (avec motif) ; le réglage d'auto-approbation (renvoyez vers Réglages sans le documenter en détail ici, il vit dans `templates/admin-settings.php`, hors nav de cette page).
  3. Utilisez le terme du glossaire **modération** avec lien.
  4. Nav : ajoutez `- Modération des inscriptions: gestion-quotidienne/moderation-inscriptions.md` sous `Gestion au quotidien:`, après `Tableau de bord`.
- Contenu attendu : structure type ; libellés exacts **En attente**, **Demandes traitées**, boutons d'approbation/refus relevés dans le fichier.
- Captures : `moderation-demandes-attente.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Modération des inscriptions`
- Point d'arrêt : non.

### Étape 16 — Annulations et absences
- Objectif : expliquer comment une famille signale une absence et ce que la mairie peut encore corriger.
- Prérequis : étape 15 terminée.
- Fichiers à lire : `templates/portal-dashboard.php` (bloc d'annulation, chercher `cancel_absence`) ; `includes/class-psc-frontend-inscriptions.php:56-` (`handle_cancel_absence`) ; `templates/admin-inscriptions.php` (en entier).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/gestion-quotidienne/annulations-absences.md`.
- Instructions :
  1. Structure type complète.
  2. Cette page a deux publics dans un seul écran de destination : expliquez d'abord ce que la famille fait elle-même (auto-annulation avant le délai de modification, cf. **Services et tarifs**), puis ce que la mairie voit et peut encore corriger depuis **Présences déclarées** (la mairie n'est jamais soumise au délai).
  3. Renvoyez vers **Services et tarifs** pour la notion de préavis, sans la répéter.
  4. Nav : ajoutez `- Annulations et absences: gestion-quotidienne/annulations-absences.md` sous `Gestion au quotidien:`, après `Modération des inscriptions`.
- Contenu attendu : structure type ; utilisez le terme du glossaire **absence** avec lien.
- Captures : `annulation-absence-famille.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Annulations et absences`
- Point d'arrêt : non.

### Étape 17 — Menus de cantine
- Objectif : expliquer comment saisir et envoyer le menu de la cantine.
- Prérequis : étape 16 terminée.
- Fichiers à lire : `templates/admin-menus.php` (en entier) ; `includes/class-psc-admin-cantine.php:18-39,67-87` (`handle_save_menu`, `handle_send_menu`, `page_menus`) ; `docs/menu-labels.md` (dev, à reformuler pour ce public — ne pas citer `Psc_Menus::parse_menu_lines()` ni aucun nom de fonction).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/gestion-quotidienne/menus-cantine.md`.
- Instructions :
  1. Structure type complète.
  2. Couvrez : la saisie d'un plat par jour, la convention de saisie des labels qualité (reformulez `docs/menu-labels.md` en langage mairie : un astérisque après le nom du plat pour le label Bio, deux pour Label Rouge — sans jamais citer de nom de fonction ni de fichier de code) ; l'aperçu avant enregistrement ; l'envoi du menu aux familles.
  3. Nav : ajoutez `- Menus de cantine: gestion-quotidienne/menus-cantine.md` sous `Gestion au quotidien:`, après `Annulations et absences`.
- Contenu attendu : structure type ; libellés exacts des boutons d'enregistrement et d'envoi relevés dans le fichier.
- Captures : `menus-cantine-saisie.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Menus de cantine`
- Point d'arrêt : non.

### Étape 18 — Documents (assurance)
- Objectif : expliquer le dépôt et la revue des justificatifs d'assurance scolaire.
- Prérequis : étape 17 terminée.
- Fichiers à lire : `templates/admin-assurances.php` (en entier) ; `includes/class-psc-frontend-documents.php:16-` (`handle_parent_upload_assurance`) ; `templates/admin-settings.php:138-146` (mode de validation automatique ou manuelle).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/gestion-quotidienne/documents-assurance.md`.
- Instructions :
  1. Structure type complète.
  2. Couvrez les deux modes de validation (**Acceptation automatique après dépôt** / **Revue par la mairie avant accès au planning** — libellés exacts de `templates/admin-settings.php:141-142`), l'écran de suivi des justificatifs, et l'effet d'un justificatif manquant ou refusé (planning bloqué pour l'enfant concerné).
  3. Nav : ajoutez `- Documents (assurance): gestion-quotidienne/documents-assurance.md` sous `Gestion au quotidien:`, après `Menus de cantine`.
- Contenu attendu : structure type ; libellés exacts des deux modes de validation ci-dessus.
- Captures : `documents-assurance-liste.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Documents (assurance)`
- Point d'arrêt : non.

### Étape 19 — Personnes autorisées
- Objectif : expliquer comment gérer la liste des personnes autorisées à récupérer un enfant.
- Prérequis : étape 18 terminée.
- Fichiers à lire : `includes/class-psc-pickup-persons.php` (en entier) ; `includes/class-psc-admin-familles.php:299-` (`page_pickup_persons`) ; le template qu'elle inclut (à identifier en lisant `page_pickup_persons`, probablement `templates/admin-pickup-persons.php` — vérifiez le nom exact dans le code, ne le devinez pas).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/gestion-quotidienne/personnes-autorisees.md`.
- Instructions :
  1. Structure type complète.
  2. Couvrez : ajouter une personne autorisée depuis la fiche d'un enfant (renvoyez vers **Configuration > … Enfants** n'existe pas dans la nav — dites plutôt « depuis la fiche de l'enfant, dans l'écran Enfants du portail ou de la mairie », sans lien cassé) ; consulter l'historique des retraits ; qui peut faire cette modification (famille ou mairie — vérifiez dans le fichier lu).
  3. Utilisez le terme du glossaire **personne autorisée** avec lien.
  4. Nav : ajoutez `- Personnes autorisées: gestion-quotidienne/personnes-autorisees.md` sous `Gestion au quotidien:`, en dernière position de cette section (après `Documents (assurance)`).
- Contenu attendu : structure type.
- Captures : `personnes-autorisees-liste.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Personnes autorisées`
- Point d'arrêt : non.

### Étape 20 — Factures PDF
- Objectif : expliquer comment générer, envoyer et exporter les factures mensuelles.
- Prérequis : étape 19 terminée.
- Fichiers à lire : `templates/admin-factures.php` (en entier) ; `includes/class-psc-admin-invoices.php:12-17` (actions enregistrées) ; `includes/class-psc-invoices.php` (méthode de calcul, chercher `month_label`).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/facturation/factures-pdf.md`.
- Instructions :
  1. Structure type complète.
  2. Couvrez, dans l'ordre du fichier lu : choisir le mois ; générer/régénérer les factures (le statut d'envoi est conservé à la régénération) ; envoyer une facture ou toutes ; les exports par mois (CSV général, CSV/ODS prélèvements) ; supprimer les factures d'un mois.
  3. Admonitions `warning` sur **Générer / Regénérer les factures de** (remplace les PDF existants) et sur **Supprimer les factures du mois** (irréversible, y compris celles déjà envoyées).
  4. Nav : créez la section `Facturation:` avec sa première entrée `- Factures PDF: facturation/factures-pdf.md`, insérée après `Gestion au quotidien:` et avant `Glossaire`.
- Contenu attendu : structure type ; libellés exacts **Générer / Regénérer les factures de**, **Supprimer les factures du mois**, **Exports par mois**, **Export général (.csv)**, **Export prélèvements (SEPA, .csv/.ods)**.
- Captures : `factures-generer.png`, `factures-exports.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Factures PDF`
- Point d'arrêt : non.

### Étape 21 — Mandats SEPA
- Objectif : expliquer le mandat de prélèvement côté famille et l'export du fichier de prélèvement (pain.008) côté mairie.
- Prérequis : étape 20 terminée.
- Fichiers à lire : `docs/export-pain008.md` (en entier — dev, à reformuler pour ce public) ; `includes/class-psc-frontend-profil.php:95-110` (génération du mandat) ; `templates/admin-factures.php` (bloc pain.008, chercher `pain008`) ; `templates/admin-settings.php:166-` (identité créancier : IBAN, BIC, ICS).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/facturation/mandats-sepa.md`.
- Instructions :
  1. Structure type complète.
  2. Ce que fait la famille (accepte le mandat lors de son inscription ou depuis son profil, télécharge son mandat signé) est **informatif seulement** : la mairie ne réalise pas cette étape. Détaillez ensuite ce que la mairie fait réellement : renseigner l'identité créancier (IBAN, BIC, référence ICS) une seule fois dans Réglages, puis exporter le fichier pain.008 chaque mois en choisissant la date de prélèvement convenue avec la banque.
  3. Admonition `tip` : le téléchargement du fichier pain.008 ne transmet aucun ordre à la banque, c'est un fichier à déposer soi-même sur l'espace bancaire.
  4. Utilisez le terme du glossaire **mandat SEPA** avec lien.
  5. Nav : ajoutez `- Mandats SEPA: facturation/mandats-sepa.md` sous `Facturation:`, après `Factures PDF`.
- Contenu attendu : structure type ; libellé exact **Export fichier pain.008**, **Date de prélèvement**.
- Captures : `sepa-export-pain008.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Mandats SEPA`
- Point d'arrêt : non.

### Étape 22 — Campagne de réinscription
- Objectif : expliquer comment ouvrir la fenêtre de réinscription annuelle et ce que voient les familles.
- Prérequis : étape 21 terminée.
- Fichiers à lire : `templates/admin-settings.php:343-355` (fenêtre de réinscription) ; `includes/class-psc-frontend-reinscription.php` (en entier) ; `templates/portal-reinscription.php` (en entier).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/cycle-annuel/reinscription.md`.
- Instructions :
  1. Structure type complète.
  2. Couvrez : ouvrir/fermer la fenêtre de réinscription (dates de début/fin dans Réglages) ; ce qu'une famille voit et confirme pendant cette fenêtre (par enfant, avec le nouveau justificatif d'assurance et le règlement) ; le cas d'un enfant en fin de cycle qui ne peut pas être réinscrit ; ce qui se passe si une famille ne fait rien (pas de sortie automatique, cf. le fichier lu).
  3. Nav : créez la section `Cycle annuel:` avec sa première entrée `- Campagne de réinscription: cycle-annuel/reinscription.md`, insérée après `Facturation:` et avant `Glossaire`.
- Contenu attendu : structure type ; utilisez le terme du glossaire **année scolaire** avec lien.
- Captures : `reinscription-fenetre.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Campagne de réinscription`
- Point d'arrêt : non.

### Étape 23 — Passage de classe
- Objectif : expliquer comment faire monter tous les enfants actifs vers l'année scolaire suivante.
- Prérequis : étape 22 terminée.
- Fichiers à lire : `templates/admin-annees.php` (bloc **Passage à l'année suivante**) ; `templates/admin-passage-annee.php` (en entier) ; `includes/class-psc-school-years.php:257-364` (calcul du plan, `apply_promotion`) ; `templates/admin-settings.php:325-341` (table de correspondance des classes).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/cycle-annuel/passage-de-classe.md`.
- Instructions :
  1. Structure type complète.
  2. Couvrez : la préparation (choisir l'année de départ et l'année cible, un récapitulatif s'affiche, rien n'est encore écrit) ; la correction ligne par ligne d'une classe proposée avant confirmation ; la confirmation définitive ; le cas de fin de cycle (l'enfant sort, aucune inscription n'est créée dans l'année cible) ; la table de correspondance des classes personnalisable dans Réglages.
  3. Admonition `warning` : la confirmation écrit réellement en base, contrairement au récapitulatif.
  4. Nav : ajoutez `- Passage de classe: cycle-annuel/passage-de-classe.md` sous `Cycle annuel:`, après `Campagne de réinscription`.
- Contenu attendu : structure type ; libellés exacts **Préparer le passage d'année**, **Confirmer**, utilisez le terme du glossaire **classe** avec lien.
- Captures : `passage-classe-recapitulatif.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Passage de classe`
- Point d'arrêt : non.

### Étape 24 — Données personnelles (RGPD)
- Objectif : expliquer les droits d'accès et d'effacement des familles, et ce qui est conservé malgré une demande d'effacement.
- Prérequis : étape 23 terminée.
- Fichiers à lire : `readme.txt` section `== RGPD ==` (en entier) ; `includes/class-psc-privacy.php` (en entier) ; `includes/class-psc-retention.php` (en entier).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/rgpd.md`.
- Instructions :
  1. Structure type complète.
  2. Couvrez : où trouver les outils natifs WordPress (Outils > Exporter/Effacer les données personnelles), comment retrouver un foyer par e-mail (ils n'ont pas de compte WordPress), ce que l'export contient, ce que l'effacement fait réellement (anonymise le foyer, ne supprime jamais les factures) et pourquoi (obligation légale de conservation de 10 ans) ; la purge automatique des enfants sortis depuis plus de 400 jours, sans action humaine requise.
  3. Admonition `warning` sur l'effacement (irréversible) et sur le fait qu'il ne supprime pas les factures.
  4. Nav : ajoutez `- Données personnelles (RGPD): rgpd.md` juste après `Cycle annuel:` et avant `Installation & maintenance:` (à créer à l'étape 25).
- Contenu attendu : structure type ; libellés exacts **Exporter les données personnelles**, **Effacer les données personnelles** (menus natifs WordPress, pas ceux du plugin).
- Captures : `rgpd-export-wp.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Données personnelles (RGPD)`
- Point d'arrêt : non.

### Étape 25 — Prérequis (public technique)
- Objectif : lister ce qu'il faut avant d'installer le plugin.
- Prérequis : étape 24 terminée.
- Fichiers à lire : `periscolaire-registration.php:1-11` (en-tête du plugin : versions requises) ; `composer.json` (`require.php`) ; `docs/self-hosting-docker.md:1-30` (source à réutiliser, adaptée — pas copiée telle quelle : ce guide est spécifique à Docker/Podman, la nouvelle page doit rester générique à tout hébergement).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/installation/prerequis.md`.
- Instructions :
  1. Cette page et les cinq suivantes s'adressent au public technique (personne qui héberge/maintient le WordPress) : le ton peut nommer des éléments techniques (constantes PHP, cron système) sans les traduire en vocabulaire mairie — la fiche de style s'applique toujours pour la structure, les admonitions et l'accessibilité, pas pour le choix du vocabulaire.
  2. Listez : version WordPress minimale, version PHP minimale, l'extension PHP requise par le chiffrement (cherchez dans `includes/helpers/crypto.php` si `sodium` ou `openssl` est requis ou simplement recommandé), l'obligation d'un accès HTTPS (le lien de connexion des familles transite par e-mail).
  3. Nav : créez la section `Installation & maintenance:` avec sa première entrée `- Prérequis: installation/prerequis.md`, insérée après `Données personnelles (RGPD)` et avant `Glossaire`.
- Contenu attendu : structure type.
- Captures : aucune.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Prérequis`
- Point d'arrêt : non.

### Étape 26 — Installation et activation
- Objectif : expliquer l'installation du plugin et sa première activation.
- Prérequis : étape 25 terminée.
- Fichiers à lire : `periscolaire-registration.php:75-93` (`register_activation_hook`, `register_deactivation_hook`) ; `includes/class-psc-installer.php:9-19` (`activate()`) ; `documentation/assets/screenshots/plugins-activation.png` (déjà produite, étape 04).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/installation/installation-activation.md`.
- Instructions :
  1. Structure type complète, public technique (cf. étape 25).
  2. Couvrez : dépôt du plugin (zip publié en Release GitHub, ou dossier `wp-content/plugins/`), activation depuis **Extensions**, ce que l'activation crée automatiquement (tables, capacités accordées aux rôles administrateur et éditeur, planification des tâches — renvoyez vers **Tâches planifiées** sans les détailler ici) ; comment donner l'accès à un rôle dédié sans droits d'administrateur complet (cf. `readme.txt`, section « Accès pour un agent non-administrateur » — lisez-la et reformulez-la).
  3. Nav : ajoutez `- Installation et activation: installation/installation-activation.md` sous `Installation & maintenance:`, après `Prérequis`.
- Contenu attendu : structure type ; libellé exact **Extensions** (menu natif WordPress).
- Captures : `plugins-activation.png`.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Installation et activation`
- Point d'arrêt : non.

### Étape 27 — Envoi des e-mails (SMTP)
- Objectif : expliquer pourquoi les e-mails du plugin peuvent ne pas partir sans configuration SMTP, et comment la faire.
- Prérequis : étape 26 terminée.
- Fichiers à lire : `readme.txt`, paragraphe commençant par « IMPORTANT — envoi des e-mails » (chercher ce texte) ; `includes/class-psc-mailer.php:1-25`.
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/installation/emails-smtp.md`.
- Instructions :
  1. Structure type complète, public technique.
  2. Reformulez le paragraphe du `readme.txt` : le plugin utilise `wp_mail()`, qui repose sur la fonction mail() de PHP sur beaucoup d'hébergements mutualisés — insuffisant pour une délivrabilité fiable. Recommandez l'installation d'un plugin SMTP tiers ou la configuration de constantes SMTP par l'hébergeur, **sans nommer un plugin SMTP précis** (le dépôt n'en impose aucun). Insistez sur le test réel avant l'ouverture aux familles : le lien de connexion sans mot de passe transite uniquement par e-mail.
  3. Admonition `warning` : sans e-mail fonctionnel, aucune famille ne peut se connecter.
  4. Nav : ajoutez `- Envoi des e-mails (SMTP): installation/emails-smtp.md` sous `Installation & maintenance:`, après `Installation et activation`.
- Contenu attendu : structure type.
- Captures : aucune.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Envoi des e-mails (SMTP)`
- Point d'arrêt : non.

### Étape 28 — Tâches planifiées
- Objectif : lister les tâches automatiques du plugin et alerter sur leur dépendance à WP-Cron.
- Prérequis : étape 27 terminée.
- Fichiers à lire : `includes/class-psc-audit.php:38-41` ; `includes/class-psc-conversations.php:438-441,449-452` ; `includes/class-psc-impersonation.php:34-37` ; `includes/class-psc-messages.php:169-172,195-198,205-213,269-272` ; `includes/class-psc-retention.php:26-29` ; `includes/class-psc-requests.php:38-41`.
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/installation/taches-planifiees.md`.
- Instructions :
  1. Structure type complète, public technique. Cette page n'a pas de section **Avant de commencer** au sens « droits nécessaires » ; remplacez-la par un rappel court : WP-Cron se déclenche aux visites du site, pas à heure fixe — sur un site peu fréquenté, un vrai cron système appelant `wp-cron.php` est recommandé.
  2. Tableau des tâches récurrentes, une ligne par tâche, colonnes Tâche / Fréquence / Rôle :
     | Tâche | Fréquence | Rôle |
     |---|---|---|
     | `psc_purge_audit_log` | quotidienne | purge le journal d'audit selon la durée de rétention par catégorie |
     | `psc_purge_conversations` | quotidienne | purge les échanges anciens |
     | `psc_cleanup_impersonations` | quotidienne | referme les consultations d'espace famille expirées |
     | `psc_send_scheduled_messages` | horaire | envoie les messages programmés arrivés à échéance |
     | `psc_cleanup_message_receipts` | quotidienne | nettoie les preuves de lecture anciennes |
     | `psc_purge_departed_children` | quotidienne | anonymise les enfants sortis depuis plus de 400 jours (RGPD) |
     | `psc_cleanup_requests` | quotidienne | supprime les demandes d'inscription non confirmées (7 jours) ou traitées (90 jours) |
  3. Mentionnez à part les deux tâches ponctuelles (non récurrentes, déclenchées à l'usage) : `psc_conversation_notify` (regroupe les notifications d'un même échange) et `psc_send_message_emails` (envoi différé d'un message aux familles) — pas de fréquence à leur donner, elles ne sont pas dans le tableau.
  4. Nav : ajoutez `- Tâches planifiées: installation/taches-planifiees.md` sous `Installation & maintenance:`, après `Envoi des e-mails (SMTP)`.
- Contenu attendu : structure type ; le tableau ci-dessus recopié tel quel.
- Captures : aucune.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Tâches planifiées`
- Point d'arrêt : non.

### Étape 29 — Sauvegarde et mise à jour
- Objectif : expliquer quoi sauvegarder et comment mettre à jour le plugin sans perte de données.
- Prérequis : étape 28 terminée.
- Fichiers à lire : `bin/verify-database-backup.sh` (en entier) ; `.github/workflows/release.yml:55-77` (composition du paquet, pour savoir ce qu'une mise à jour remplace) ; `includes/class-psc-installer.php`, méthode `maybe_upgrade()` (chercher ce nom).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/installation/sauvegarde-mise-a-jour.md`.
- Instructions :
  1. Structure type complète, public technique.
  2. Couvrez : ce qui doit être sauvegardé (base de données, dossier des documents privés dont l'emplacement est configurable via `PSC_PRIVATE_DIR`) ; la procédure de mise à jour (remplacement des fichiers du plugin, la base se met à jour seule au chargement suivant via `maybe_upgrade()` — aucune commande manuelle) ; recommandation de sauvegarder avant toute mise à jour de version majeure.
  3. Nav : ajoutez `- Sauvegarde et mise à jour: installation/sauvegarde-mise-a-jour.md` sous `Installation & maintenance:`, après `Tâches planifiées`.
- Contenu attendu : structure type.
- Captures : aucune.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Sauvegarde et mise à jour`
- Point d'arrêt : non.

### Étape 30 — Dépannage et FAQ
- Objectif : rassembler les pannes déjà documentées ailleurs dans le dépôt en une seule page de dépannage.
- Prérequis : étape 29 terminée.
- Fichiers à lire : `includes/class-psc-admin.php`, méthodes `notice_private_dir_exposed()`, `notice_db_constraints()`, `notice_audit_health()` (les trois alertes déjà affichées dans l'admin) ; `documentation/installation/emails-smtp.md` (créée à l'étape 27, pour ne pas dupliquer son contenu, seulement y renvoyer).
- Fichiers à créer/modifier : `mkdocs.yml` (nav) ; `documentation/installation/depannage-faq.md`.
- Instructions :
  1. Structure type adaptée : remplacez **Étapes** par une suite de questions, chacune un titre `##` (pas `###` : le H1 de la page doit être suivi directement de H2, jamais d'un saut de niveau — cf. accessibilité, section C) ; **Résultat attendu** n'a pas de sens ici, retirez-le également — seule cette page déroge à la structure type, en plus de l'Accueil (étape 07).
  2. Reprenez, une question par alerte trouvée dans le fichier lu, en langage mairie : documents des familles accessibles sans connexion ; contrainte de base de données non posée ; problème du journal d'audit. Ajoutez une question sur les e-mails qui ne partent pas, avec un lien vers **Envoi des e-mails (SMTP)** plutôt qu'une réponse dupliquée.
  3. Nav : ajoutez `- Dépannage et FAQ: installation/depannage-faq.md` sous `Installation & maintenance:`, en dernière position de cette section (après `Sauvegarde et mise à jour`).
- Contenu attendu : H1, puis un `##` par question.
- Captures : aucune.
- Critères d'acceptation : `mkdocs build --strict` sans avertissement.
- Message de commit : `docs: ajoute la page Dépannage et FAQ`
- Point d'arrêt : non.

### Étape 31 — Contrôle qualité
- Objectif : vérifier les liens, l'accessibilité du site généré, et la cohérence du glossaire sur l'ensemble des 26 pages publiées.
- Prérequis : étape 30 terminée.
- Fichiers à lire : `mkdocs.yml` (nav complète) ; `documentation/glossaire.md`.
- Fichiers à créer/modifier : `package.json` (ajout d'une dépendance et d'un script) ; `scripts/docs-a11y/check.mjs` (nouveau) ; corrections ponctuelles dans les pages de `documentation/` si l'audit en révèle (mêmes fichiers que ceux touchés aux étapes 07 à 30, selon ce que trouve l'audit).
- Instructions :
  1. **Liens** : `mkdocs build --strict` couvre déjà les liens internes et les images manquantes (c'est le critère commun depuis l'étape 01) — relancez-le une dernière fois sur l'ensemble du site, aucune page ne doit manquer à l'appel.
  2. **Accessibilité automatisée** : ajoutez `@axe-core/playwright` en dépendance de développement (`npm install --save-dev @axe-core/playwright`, dernière version 4.x publiée au moment de l'exécution). Créez `scripts/docs-a11y/check.mjs` : lance `chromium` (déjà installé via `npx playwright install`), sert le dossier `site/` construit sur `http://localhost:8000` (`python3 -m http.server 8000 --directory site`, à démarrer et arrêter dans le script ou juste avant/après selon ce qui est le plus simple à écrire), visite chacune des 26 pages listées dans `mkdocs.yml` → `nav`, exécute `@axe-core/playwright` avec les règles par défaut, échoue (code de sortie non nul) si une violation de niveau `serious` ou `critical` est trouvée sur une page, affiche le détail de chaque violation trouvée sinon.
  3. Ajoutez dans `package.json` → `scripts` : `"docs:a11y": "mkdocs build --strict && node scripts/docs-a11y/check.mjs"`.
  4. Corrigez toute violation `serious`/`critical` remontée, dans le fichier `.md` concerné (contraste, alternative textuelle manquante, hiérarchie de titres) — jamais en désactivant une règle axe.
  5. **Cohérence du glossaire** : pour chacun des 15 termes de `documentation/glossaire.md`, `grep -rn "<terme>" documentation/*.md documentation/**/*.md` et relisez chaque occurrence : le sens employé doit correspondre exactement à la définition du glossaire. Corrigez toute page qui emploierait un terme dans un sens différent (ex. « responsable » utilisé pour désigner autre chose qu'un parent du foyer).
- Contenu attendu : n/a (script + corrections).
- Captures : aucune (pas de nouvelle capture ; les corrections éventuelles ne changent pas le nommage des fichiers existants).
- Critères d'acceptation :
  ```
  mkdocs build --strict
  npx playwright install --with-deps chromium
  npm run docs:a11y
  ```
  Les trois commandes se terminent avec un code de sortie 0, aucune violation `serious`/`critical` n'est rapportée.
- Message de commit : `ci: ajoute le contrôle d'accessibilité automatisé de la documentation`
- Point d'arrêt : non.

### Étape 32 — Raccordement
- Objectif : rendre la documentation trouvable depuis le README et signaler son existence dans l'administration du plugin, en attendant validation.
- Prérequis : étape 31 terminée.
- Fichiers à lire : `README.md:122-128` (section Documentation existante) ; `includes/class-psc-admin.php`, méthode `page_dashboard()` et le template qu'elle inclut, `templates/admin-dashboard.php`.
- Fichiers à créer/modifier : `README.md` ; `templates/admin-dashboard.php`.
- Instructions :
  1. Dans `README.md`, section `## Documentation` (ligne 122), ajoutez **en première ligne de la liste**, avant « Historique des versions » : `- [Documentation administrateur](https://eflye.github.io/wp-plugin-extrascolaire/)`.
  2. Dans `templates/admin-dashboard.php`, ajoutez un lien vers le site, visible en haut de l'écran **Tableau de bord**, sous une forme clairement marquée comme provisoire : un paragraphe `<p>` avec le texte « Documentation administrateur (lien à valider) » suivi du lien vers `https://eflye.github.io/wp-plugin-extrascolaire/`, ouverture dans un nouvel onglet (`target="_blank" rel="noopener"`). N'utilisez pas de constante ni d'option pour cette URL : un lien en dur, à retirer la mention « à valider » une fois qu'Etienne confirme l'URL définitive (domaine personnalisé éventuel, cf. section F).
  3. Ne touchez à aucun autre fichier.
- Contenu attendu : n/a (liens).
- Captures : aucune.
- Critères d'acceptation :
  ```
  php -l templates/admin-dashboard.php
  grep -n "wp-plugin-extrascolaire.github.io\|eflye.github.io" README.md templates/admin-dashboard.php
  ```
  Le lint PHP passe, les deux fichiers contiennent bien le lien.
- Message de commit : `docs: ajoute le lien vers la documentation (README, tableau de bord — à valider)`
- Point d'arrêt : **oui**, avant même de commencer cette étape (elle ne s'exécute qu'après accord explicite d'Etienne, cf. section G).

---

## F. Actions manuelles pour Etienne

Le dépôt est **public** (vérifié : `private: false`) : GitHub Pages via Actions fonctionne sans plan payant particulier, cette question est donc déjà réglée. Restent, avant l'étape 02 :

1. **Settings › Pages › Source** : sélectionner **GitHub Actions** (pas « Deploy from a branch »). Sans ce réglage, `actions/deploy-pages@v4` échoue.
2. **Settings › Environments** : après le premier run du workflow, un environnement `github-pages` est créé automatiquement — vérifier qu'aucune règle de protection (reviewers requis, délai d'attente) n'y bloque un déploiement automatique sur `main`, sauf si un délai de relecture est explicitement souhaité pour ce site.
3. **Nom de domaine personnalisé** (optionnel, à décider — cf. section G) : si retenu, dans Settings › Pages, renseigner le domaine, ajouter le fichier `CNAME` correspondant (MkDocs le gère via l'option `mkdocs.yml` → à ajouter alors, hors du périmètre de ce plan tel quel) ; côté registrar (OVH mentionné en contexte), créer un enregistrement DNS `CNAME` pointant vers `eflye.github.io` ; cocher **Enforce HTTPS** une fois le certificat émis (peut prendre jusqu'à 24 h après la création du DNS).
4. Vérifier, après l'étape 02, que le premier déploiement est bien accessible à `https://eflye.github.io/wp-plugin-extrascolaire/` avant de donner le feu vert au reste du plan (point d'arrêt de l'étape 02).

---

## G. Décisions à valider

1. **Fin de vie annoncée de Material for MkDocs** : le projet entre en fin de vie le **5 novembre 2026** (maintenance limitée aux correctifs de sécurité critiques jusqu'à cette date, plus aucune évolution ensuite). Les mêmes auteurs développent **Zensical** comme successeur, visant la compatibilité avec les projets Material existants. *Recommandation* : conserver Material for MkDocs pour cette première publication (c'est la consigne donnée), mais inscrire dès maintenant une tâche de suivi pour réévaluer une migration vers Zensical d'ici mi-2027, une fois le projet sorti de sa phase de démarrage. Ne pas migrer préventivement maintenant : Zensical est trop récent pour être le premier choix d'un site de production. — [Annonce de fin de vie](https://github.com/squidfunk/mkdocs-material/issues/8523), [présentation de Zensical](https://squidfunk.github.io/mkdocs-material/blog/2026/02/18/mkdocs-2.0/).
2. **Nom de domaine personnalisé** : garder l'URL par défaut `eflye.github.io/wp-plugin-extrascolaire/`, ou en pointer un dédié (ex. `doc.perisco-montgeroult.fr` chez OVH) ? *Recommandation* : garder l'URL par défaut pour cette v1 — un domaine dédié ajoute une dépendance DNS externe pour un gain d'usage marginal tant que le lien est distribué via le README et l'admin du plugin plutôt que communiqué de vive voix aux familles (qui n'ont de toute façon pas accès à cette documentation, réservée aux agents).
3. **Portée de la page « Régimes alimentaires »** : le plan la documente comme une page de référence sur des champs saisis ailleurs, faute d'écran de configuration dédié dans le code actuel. *Recommandation* : conserver cette portée réduite — inventer un écran de configuration qui n'existe pas irait à l'encontre de la règle de périmètre du plan.
4. **Lien « à valider » dans le tableau de bord (étape 32)** : ajouté en dur dans `templates/admin-dashboard.php`, sans réglage pour le désactiver. *Recommandation* : le garder ainsi pour la v1 (un lien statique dans un template déjà versionné, cohérent avec le reste du plugin qui n'a pas de mécanisme de « liens externes configurables ») — mais le point d'arrêt avant l'étape 32 est précisément là pour qu'Etienne confirme vouloir ce lien avant qu'il apparaisse dans l'interface d'une secrétaire de mairie.
5. **Granularité des captures d'écran** : 21 captures proposées (étape 04), une par écran clé plutôt qu'une par action détaillée. *Recommandation* : rester à ce niveau pour la v1 — une capture par sous-étape multiplierait leur nombre par 3 ou 4 sans gain de compréhension proportionnel, et chacune devient une image à régénérer à chaque changement d'interface.

---

## H. Hors périmètre v1

| Fonctionnalité | Statut constaté | Référence |
|---|---|---|
| Trimestre (comme concept actif) | Retiré : facturation mensuelle, planning continu. Tables historiques détachées, conservées uniquement pour l'intégrité des anciennes données. | `includes/class-psc-installer.php:296,308-310,638-700` |
| Réglages de régimes alimentaires configurables globalement | Absent : les régimes sont des champs par enfant, pas une liste administrable. | Aucun écran trouvé dans `templates/admin-*.php` pour une liste de régimes ; champs uniquement dans `templates/portal-enfants.php`, `templates/guest-request.php`, `templates/admin-children.php`. |
| Rôle dédié « secrétaire » créé par le plugin | Absent : aucun `add_role()` dans le code. Les capacités sont ajoutées aux rôles existants (administrateur, éditeur) ; un rôle nommé `gestionnaire_periscolaire` n'est reconnu que s'il est créé par un autre moyen (extension de gestion des rôles). | `includes/class-psc-installer.php:31-61` (recherche de `add_role(` sans résultat) |
| Configuration SMTP par le plugin | Absent : le plugin utilise `wp_mail()` sans fournir de constante ni d'écran SMTP propres. | `includes/class-psc-mailer.php:21,73` |
| Export PDF dédié pour les menus de cantine | Absent, confirmé par une note développeur existante. | `docs/menu-labels.md`, paragraphe « Il n'existe pas d'export PDF dédié aux menus » |

---

## Livrable et arrêt

Seul `docs-plan/PLAN.md` est créé à cette phase, avec le commit `docs: plan de la documentation administrateur`. Aucune autre création de fichier tant qu'Etienne n'a pas validé ce plan.
