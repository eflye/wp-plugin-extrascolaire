# Plan de refactoring

## 1. Résumé

Le projet est un plugin WordPress 5.8+ en PHP 7.4+, organisé par domaines mais encore concentré dans plusieurs classes volumineuses. Le chemin de démarrage et les hooks sont explicites ; aucun fichier PHP applicatif manifestement orphelin n'a été trouvé. Les principaux gains rapides sont l'extraction de requêtes et d'agrégations dupliquées, puis la décomposition de deux méthodes de plus de 200 lignes. Le schéma courant compte 17 tables, avec des index globalement cohérents pour le planning, mais les recherches sur le second parent et le nettoyage périodique des demandes ne sont pas correctement couvertes. Les clés étrangères sont ajoutées après `dbDelta`, mais plusieurs relations historiques et d'audit restent sans contrainte. Les tables legacy ne doivent pas être supprimées sans inventaire en production et période d'observation. ESLint et le test bancaire navigateur passent ; PHPStan, le lint PHP, les tests PHP/WordPress et les E2E complets n'ont pas pu être exécutés faute de runtime PHP local et d'une instance WordPress vérifiée.

| Catégorie | CERTAIN | PROBABLE | À VÉRIFIER | Total |
|---|---:|---:|---:|---:|
| Code mort | 0 | 0 | 2 | 2 |
| Factorisation | 4 | 1 | 0 | 5 |
| Base de données | 2 | 2 | 1 | 5 |
| **Total** | **6** | **3** | **3** | **12** |

## 2. Cartographie

### Stack, modules et points d'entrée

- **Runtime** : plugin WordPress 5.8+, PHP >= 7.4 (`periscolaire-registration.php:3-10`, `composer.json:5-10`), MySQL/MariaDB via `$wpdb` et `dbDelta` (`includes/class-psc-installer.php:902-926`, `includes/class-psc-installer.php:1262`). JavaScript navigateur sans bundler ; Playwright et ESLint sont les seules dépendances Node (`package.json:6-16`).
- **Bootstrap** : `periscolaire-registration.php:21-63` charge les helpers et toutes les classes ; `periscolaire-registration.php:65-87` branche activation, désactivation et initialisation `plugins_loaded`.
- **Administration** : `includes/class-psc-admin*.php` déclare menus, écrans, handlers `admin_post_*` et AJAX ; les vues sont dans `templates/admin-*.php`.
- **Portail famille/public** : `includes/class-psc-frontend*.php`, `includes/class-psc-parents.php` et `includes/class-psc-requests.php`; shortcodes `[periscolaire_form]` et `[periscolaire_sidscm]` (`includes/class-psc-frontend.php:19-33`, `includes/class-psc-sidscm.php:31-47`).
- **Métier** : planning, années/calendrier scolaire, inscriptions, factures/SEPA, menus/commandes fournisseur, assurances et habilitations dans `includes/class-psc-*.php`; helpers transverses dans `includes/helpers/*.php`.
- **Persistance** : schéma et migrations dans `includes/class-psc-installer.php`; SQL direct regroupé dans les classes métier. Tâche cron quotidienne `psc_cleanup_requests` (`includes/class-psc-requests.php:23-46`).
- **UI** : PHP dans `templates/`, CSS/JS dans `assets/`; thème local distinct dans `theme/Archive/`, monté par les deux fichiers Compose (`docker-compose.yml:37`, `docker-compose.prod.yml:61`).
- **Outillage** : scripts WP-CLI de seed/vérification dans `bin/`; orchestration Playwright dans `playwright/`; workflows dans `.github/workflows/`.

### Tests et commandes

| Contrôle | Preuve / commande | Résultat de l'audit |
|---|---|---|
| Analyse PHP | `composer phpstan`; configuration niveau 3 sur `includes` (`composer.json:15-16`, `phpstan.neon:1-18`) | Non exécutée : `composer` absent ; `vendor/bin/phpstan` non exécutable. |
| Syntaxe PHP | `find … -name '*.php' … php -l` | Non exécutée : `php` absent sur l'hôte. |
| Tests PHP | `tests/unit/run.php` et `tests/integration/*.php` | Présents, couverture non mesurée ; dépendent de PHP/WordPress. |
| Lint JS | `npm run lint:js` (`package.json:9`) | Passe, aucune erreur. |
| Banque navigateur | `npm run test:banking` (`package.json:10`) | Passe hors bac à sable : 4 formulaires × 1 066 cas. |
| E2E | `npm run test:e2e` (`package.json:7`) | Non lancé : `playwright.config.ts:29-40` documente une base WordPress partagée et séquentielle ; état de l'instance non vérifié. |
| CI | `.github/workflows/lint.yml`, `.github/workflows/e2e.yml`, `.github/workflows/release.yml` | Trois workflows présents ; couverture de lignes/branches non configurée dans les manifestes lus. |

### Schéma reconstitué

Source : `includes/class-psc-installer.php:926-1208`. Toutes les tables ont `id BIGINT UNSIGNED AUTO_INCREMENT` comme PK. `NULL` ci-dessous signifie que la colonne est nullable ; les autres sont `NOT NULL`.

| Table | Colonnes (hors `id`) | Index / contraintes déclarés |
|---|---|---|
| `school_years` | `label VARCHAR(20)`, `date_debut DATE`, `date_fin DATE`, `statut VARCHAR(20)`, `created_at DATETIME` | `KEY statut` |
| `school_year` | `year_key VARCHAR(9)`, `date_start/end DATE`, `vacation_ranges LONGTEXT NULL`, `lock_hours SMALLINT NULL`, timestamps | `UNIQUE year_key` |
| `holidays` | `year_key VARCHAR(9)`, `jour_date DATE`, `label VARCHAR(191) NULL` | `UNIQUE (year_key,jour_date)` |
| `pattern` | `child_id BIGINT`, `school_year VARCHAR(9)`, `weekday TINYINT`, `service_code VARCHAR(10)`, timestamps | `UNIQUE (child_id,school_year,weekday,service_code)`, `KEY school_year`, FK enfant |
| `exception` | `child_id BIGINT`, `jour_date DATE`, `service_code VARCHAR(10)`, `value TINYINT`, `created_at` | `UNIQUE (child_id,jour_date,service_code)`, `KEY jour_date`, FK enfant |
| `parents` | identité/contact, jetons, statut, données SEPA, second parent, timestamps | `UNIQUE email`, `KEY active` |
| `children` | `parent_id`, identité, naissance, régimes/allergies, `statut`, `created_at` | `KEY parent_id`, `KEY statut`, FK parent |
| `child_school_years` | `child_id`, `school_year_id NULL`, classe/statut, assurance et revue | `UNIQUE (child_id,school_year_id)`, `KEY school_year_id`, 2 FK |
| `requests` | identité/contact, `children_json`, vérification/statut, SEPA, second parent, dates | `KEY status`, `KEY email` |
| `invoices` | `parent_id`, `mois CHAR(7)`, `total DECIMAL(10,2)`, paiement/PDF/envoi/création | `UNIQUE (parent_id,mois)`, `KEY mois`, `KEY parent_id` |
| `menus` | semaine, quatre jours `TEXT NULL`, origine, dates | `UNIQUE semaine_debut` |
| `school_calendar` | date, libellé, fermeture, source, timestamps | `UNIQUE jour_date` |
| `supplier_orders` | semaine, `counts_json TEXT`, total, e-mail/sujet/corps, envoi | `KEY semaine_debut` |
| `pickup_persons` | `child_id`, identité/contact/statut, retrait, timestamps | `KEY child_id`, `KEY statut`, FK enfant |
| `pickup_history` | `child_id`, `pickup_person_id`, action/snapshot/source/acteurs/date | `KEY child_id`, `KEY pickup_person_id`; FK enfant seulement |
| `attendance` | `child_id`, date, service, présence/heures/pointage | `UNIQUE (child_id,jour_date,service)`, `KEY jour_date` |
| `service_closures` | date, service, libellé, timestamps | `UNIQUE (jour_date,service)` |

Les tables `trimestres`, `calendar_days` et `registrations` ne sont créées que pendant une montée depuis une version antérieure à 4.0 (`includes/class-psc-installer.php:1210-1274`). La liste des FK effectivement tentées se trouve dans `includes/class-psc-installer.php:334-353`; leur présence réelle en production n'a pas été inspectée.

## 3. Constats

### Code mort

| ID | Localisation | Constat | Preuve | Confiance | Impact |
|---|---|---|---|---|---|
| CM-01 | `includes/class-psc-installer.php:1210-1259`; `includes/class-psc-planning.php:1106-1335` | Les trois tables legacy et leurs routines de migration/vérification ne servent plus aux installations neuves, mais peuvent encore être requises lors d'une montée ancienne ou pour un cycle de facturation. | La condition `< 4.0.0` est explicite (`includes/class-psc-installer.php:1265-1274`); les usages ont été cherchés dans tout le dépôt avec `rg` et subsistent dans les migrations, les vérificateurs et `Psc_Planning`. Aucun inventaire de versions de production ni de lignes restantes n'est disponible. | À VÉRIFIER | Dette de migration et surface SQL ; suppression prématurée risquée. |
| CM-02 | `includes/class-psc-frontend.php:242-316`; `README.md:31` | L'ancien écran de planning « jour par jour » est annoncé comme compatibilité et pourrait devenir retirable après mesure des URL réellement utilisées. | Les définitions d'onglets conservent deux variantes (`includes/class-psc-frontend.php:242-316`) et la documentation promet encore l'accès par URL (`README.md:31`). Les usages externes, favoris et liens tiers sont invérifiables depuis le dépôt. | À VÉRIFIER | Réduction de surface UI/maintenance, avec risque de casser des liens enregistrés. |

### Factorisation

| ID | Localisation | Constat | Preuve | Confiance | Impact |
|---|---|---|---|---|---|
| FA-01 | `includes/class-psc-school-calendar.php:373-455`; `includes/class-psc-school-calendar.php:622-677` | La même requête « enfants actifs + parents actifs » et la même agrégation par famille apparaissent dans trois parcours. Extraire `active_children_with_parent()` puis `group_declared_services_by_family($dates,$services)`. | Requête identique aux lignes 378-385, 441-448 et 627-634 ; boucles d'agrégation homologues aux lignes 392-423 et 640-670. | CERTAIN | Moins de dérive fonctionnelle et de SQL dupliqué ; risque faible si tests de caractérisation. |
| FA-02 | `includes/class-psc-requests.php:205-495` | `handle_submit()` (~291 lignes) mélange routage HTTP, validation, uploads, chiffrement, persistance et e-mail. Extraire des validateurs/DTO puis `persist_pending_request()` et `send_verification()`. | Début de méthode ligne 205, construction persistée lignes 426-462, génération/envoi lignes 467-493. | CERTAIN | Lisibilité, testabilité et rollback métier ; risque moyen car flux public sensible. |
| FA-03 | `includes/class-psc-invoices.php:726-939` | `build_pdf()` (~214 lignes) mélange lecture des options, mise en page, calcul des totaux et I/O. Extraire un modèle de facture pur et un renderer FPDF par sections. | Options lignes 729-739, composition lignes 741-883, calcul lignes 885-927, écriture lignes 929-937. | CERTAIN | Tests unitaires des montants et évolution du rendu ; risque moyen sur fidélité PDF. |
| FA-04 | `assets/css/portal.css:1-2159`; `assets/css/frontend.css:1-667` | Les feuilles front totalisent 2 826 lignes et mêlent composants, pages et responsive ; établir un inventaire de sélecteurs puis scinder par composants sans changer l'ordre de cascade. | Tailles mesurées par `wc -l`; les deux fichiers sont chargés par `Psc_Frontend::assets()` (`includes/class-psc-frontend.php:79-237`). | PROBABLE | Maintenance CSS ; risque moyen de régression visuelle, donc captures requises. |
| FA-05 | `includes/class-psc-installer.php:47-139`; `includes/class-psc-installer.php:605-901` | La classe d'installation orchestre version, fichiers privés, nettoyage, DDL et neuf migrations historiques. Extraire progressivement un registre de migrations versionnées, sans réécriture globale. | `maybe_upgrade()` appelle les migrations et la création (`includes/class-psc-installer.php:47-139`); les migrations occupent notamment `includes/class-psc-installer.php:605-901`. | CERTAIN | Réduit le risque des prochaines migrations ; risque élevé si l'ordre/idempotence change. |

### Base de données

| ID | Localisation | Constat | Preuve | Confiance | Impact |
|---|---|---|---|---|---|
| DB-01 | `includes/class-psc-parents.php:41-53`; `includes/class-psc-installer.php:984-1020` | `second_parent_email` est recherché à chaque connexion mais n'a aucun index, contrairement à `email`. Ajouter un index après vérification de cardinalité. | Requête `email = %s OR second_parent_email = %s` aux lignes 49-51 ; schéma : `UNIQUE email` et `KEY active` seulement aux lignes 1018-1020. | CERTAIN | Accélère l'authentification lorsque la table grandit ; faible coût d'écriture. |
| DB-02 | `includes/class-psc-requests.php:55-76`; `includes/class-psc-installer.php:1061-1095` | Le cron filtre par `(status, created_at)` puis `(status, decided_at)`, mais la table n'a que `KEY status`. Ajouter deux index composites après `EXPLAIN`. | Requêtes lignes 59-65 et suppressions lignes 71-76 ; index déclarés lignes 1092-1094. | CERTAIN | Évite des scans pendant le nettoyage ; volume attendu inconnu. |
| DB-03 | `includes/class-psc-installer.php:334-353`; `includes/class-psc-installer.php:1169-1197` | `pickup_history.pickup_person_id` et `attendance.child_id` sont indexés/référentiels par usage mais absents de `foreign_key_map()`. Étudier des FK `ON DELETE` cohérentes avec la politique d'historique. | Le mapping FK ne couvre que huit relations (`includes/class-psc-installer.php:334-353`); les colonnes/index sont définis lignes 1169-1197. Le bon comportement de suppression pour l'historique n'est pas explicite. | PROBABLE | Intégrité accrue ; risque de perte d'audit si une cascade est choisie à tort. |
| DB-04 | `includes/class-psc-installer.php:984-1020`; `includes/class-psc-parents.php:41-53` | Le code suppose qu'une adresse de second parent ne crée pas d'ambiguïté inter-foyers, mais la base ne peut pas exprimer l'unicité croisée entre `email` et `second_parent_email`. Évaluer une table normalisée `parent_emails(parent_id,email,role)` avec `UNIQUE(email)`. | Le commentaire métier et la recherche sur les deux colonnes sont aux lignes 41-51 ; seule la colonne principale est `UNIQUE` aux lignes 1018-1020. Les validations applicatives ne ferment pas une course concurrente. | PROBABLE | Garantit l'identité de connexion ; migration sensible aux doublons et sessions. |
| DB-05 | `includes/class-psc-installer.php:926-1208`; `includes/class-psc-installer.php:386-425` | Le schéma déclaré ne prouve pas le schéma réel : les FK sont tolérantes à l'échec et enregistrées dans une option. Capturer `SHOW CREATE TABLE`, volumes et `psc_constraints_missing` avant toute migration. | `ensure_foreign_keys()` supprime les erreurs SQL et collecte les échecs (`includes/class-psc-installer.php:386-425`). Aucune connexion à la BDD cible n'a été fournie. | À VÉRIFIER | Condition préalable à tout DDL sûr. |

## 4. Plan step by step

### [ ] STEP-01 — Établir le filet de sécurité
- **Catégorie** : Sécurité
- **Constats liés** : FA-01, FA-02, FA-03, FA-04, FA-05, DB-01, DB-02, DB-03, DB-04, DB-05
- **Prérequis** : aucun
- **Fichiers concernés** : `tests/`, `.github/workflows/lint.yml`, `.github/workflows/e2e.yml`, `composer.json`, `package.json`
- **Actions** :
  1. Sauvegarder la base cible et tester la restauration sur une instance isolée.
  2. Installer un runtime PHP compatible et exécuter PHPStan, lint PHP, tests unitaires/intégration, ESLint, test bancaire et E2E.
  3. Ajouter la mesure de couverture PHP/JS ou, à défaut, documenter précisément les scénarios couverts.
  4. Exiger ces contrôles verts avant chaque étape suivante.
- **Vérification** : `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, lint PHP, `npm run lint:js`, `npm run test:banking`, `npm run test:e2e`; zéro échec et restauration testée.
- **Risque** : faible, uniquement outillage et caractérisation.
- **Rollback** : revenir sur le commit de tests/CI ; aucune donnée métier modifiée.
- **Effort** : M (< 1 j)
- **Commit suggéré** : `test(ci): establish refactoring safety net`

### [ ] STEP-02 — Inventorier le schéma réel et les usages legacy
- **Catégorie** : Investigation
- **Constats liés** : CM-01, CM-02, DB-05
- **Prérequis** : STEP-01
- **Fichiers concernés** : `includes/class-psc-installer.php`, `includes/class-psc-planning.php`, documentation d'exploitation
- **Actions** :
  1. Exporter `SHOW CREATE TABLE` et `SHOW INDEX` pour toutes les tables `psc_*`, lire `psc_db_version` et `psc_constraints_missing`.
  2. Compter les lignes et dernières écritures des tables legacy ; journaliser pendant au moins un cycle de facturation les accès aux trois tables et à l'ancienne URL de planning.
  3. Exécuter `EXPLAIN ANALYZE` sur les requêtes DB-01/DB-02 avec des volumes représentatifs.
  4. Décider humainement d'une durée de compatibilité ; ne supprimer encore ni code, ni table.
- **Vérification** : rapport daté contenant schéma, volumes, plans d'exécution, version minimale réellement déployée et statistiques d'URL.
- **Risque** : faible ; attention aux données personnelles dans les logs, ne conserver que des compteurs.
- **Rollback** : désactiver la journalisation et purger les logs techniques selon la politique de rétention.
- **Effort** : M (< 1 j)
- **Commit suggéré** : `chore(db): inventory production schema and legacy usage`

### [ ] STEP-03 — Retirer uniquement la compatibilité prouvée inactive
- **Catégorie** : Code mort
- **Constats liés** : CM-01, CM-02
- **Prérequis** : STEP-02
- **Fichiers concernés** : `includes/class-psc-installer.php`, `includes/class-psc-planning.php`, `includes/class-psc-frontend.php`, tests et documentation associés
- **Actions** :
  1. N'ouvrir cette étape que si STEP-02 prouve zéro installation < 4.0, zéro ligne/lecture legacy pendant la période choisie et zéro trafic sur l'ancienne UI.
  2. Retirer séparément les routines legacy puis l'ancienne UI, un commit par sous-ensemble.
  3. Pour les tables, conserver d'abord une version sans accès applicatif et observer encore avant tout `DROP`.
- **Vérification** : suite complète verte ; `rg` ne trouve plus de référence applicative ; compteurs d'accès restent nuls après déploiement canari.
- **Risque** : élevé, compatibilité externe impossible à prouver depuis le dépôt seul.
- **Rollback** : redéployer le commit précédent ; restaurer la sauvegarde si un DDL ultérieur a été autorisé.
- **Effort** : L (> 1 j)
- **Commit suggéré** : `refactor(legacy): remove verified inactive compatibility paths`

### [ ] STEP-04 — Extraire les requêtes du calendrier
- **Catégorie** : Factorisation
- **Constats liés** : FA-01
- **Prérequis** : STEP-01
- **Fichiers concernés** : `includes/class-psc-school-calendar.php`, tests unitaires/intégration calendrier
- **Actions** :
  1. Ajouter des tests de caractérisation pour jour, plage et fermeture de service.
  2. Extraire la requête commune des enfants/parents actifs.
  3. Extraire l'agrégation pure par famille et paramétrer dates/services.
  4. Remplacer les trois implémentations sans changer la forme de retour publique.
- **Vérification** : tests calendrier et E2E verts ; résultats avant/après identiques sur un jeu seedé.
- **Risque** : faible, refactoring local sous caractérisation.
- **Rollback** : revert du commit, aucun schéma modifié.
- **Effort** : M (< 1 j)
- **Commit suggéré** : `refactor(calendar): centralize affected-family queries`

### [ ] STEP-05 — Décomposer la soumission publique
- **Catégorie** : Factorisation
- **Constats liés** : FA-02
- **Prérequis** : STEP-04
- **Fichiers concernés** : `includes/class-psc-requests.php`, `tests/request-approval.spec.ts`, tests PHP dédiés
- **Actions** :
  1. Caractériser chaque erreur/redirection, upload, chiffrement et resoumission.
  2. Extraire validation et normalisation dans des fonctions pures retournant données ou `WP_Error`.
  3. Extraire persistance et e-mail en conservant nonces, limites de débit et réponse anti-énumération.
  4. Garder `handle_submit()` comme orchestrateur HTTP mince.
- **Vérification** : tests de validation PHP et `tests/request-approval.spec.ts` verts ; mêmes lignes créées et mêmes redirections.
- **Risque** : moyen, point d'entrée public et données bancaires.
- **Rollback** : revert du commit ; aucune migration.
- **Effort** : L (> 1 j)
- **Commit suggéré** : `refactor(requests): split submission validation and persistence`

### [ ] STEP-06 — Séparer le modèle et le rendu des factures
- **Catégorie** : Factorisation
- **Constats liés** : FA-03
- **Prérequis** : STEP-05
- **Fichiers concernés** : `includes/class-psc-invoices.php`, tests factures, fixtures PDF
- **Actions** :
  1. Tester les totaux, numéros, libellés et cas SEPA indépendamment du PDF.
  2. Construire un modèle de facture immuable depuis parent, mois, enfants et grille.
  3. Extraire les sections FPDF (en-tête, adresse, tableau, pied) sans changer les coordonnées.
  4. Comparer visuellement des PDF de référence et leurs valeurs extraites.
- **Vérification** : tests de montants verts ; comparaison PDF approuvée ; `tests/invoices.spec.ts` vert.
- **Risque** : moyen, risque de dérive visuelle ou d'arrondi.
- **Rollback** : revert du commit et régénération des PDF concernés si nécessaire.
- **Effort** : L (> 1 j)
- **Commit suggéré** : `refactor(invoices): separate invoice model from pdf rendering`

### [ ] STEP-07 — Modulariser les styles du portail
- **Catégorie** : Factorisation
- **Constats liés** : FA-04
- **Prérequis** : STEP-06
- **Fichiers concernés** : `assets/css/portal.css`, `assets/css/frontend.css`, chargement d'assets, tests visuels
- **Actions** :
  1. Inventorier sélecteurs, collisions et règles mortes avec couverture DOM sur tous les écrans.
  2. Extraire tokens, composants et pages en conservant strictement l'ordre de cascade.
  3. Charger un bundle concaténé ou une liste ordonnée sans requêtes inutiles.
  4. Comparer captures desktop/mobile avant/après.
- **Vérification** : zéro différence visuelle non approuvée ; ESLint/E2E verts ; aucun sélecteur supprimé sans preuve DOM.
- **Risque** : moyen, cascade globale.
- **Rollback** : restaurer les deux feuilles monolithiques et leur chargement.
- **Effort** : L (> 1 j)
- **Commit suggéré** : `refactor(css): split portal styles by component`

### [ ] STEP-08 — Introduire un registre de migrations
- **Catégorie** : Factorisation
- **Constats liés** : FA-05
- **Prérequis** : STEP-07
- **Fichiers concernés** : `includes/class-psc-installer.php`, nouveau répertoire de migrations, `bin/verify-migrations.php`
- **Actions** :
  1. Caractériser toutes les trajectoires de versions supportées avec copies de schémas anciens.
  2. Extraire une migration à la fois dans un objet/fichier versionné et idempotent.
  3. Conserver l'ordre exact, la mise à jour de `psc_db_version` et les doubles passes `dbDelta` tant que les tests ne prouvent pas leur retrait.
  4. Documenter verrouillage, reprise après échec et journal de migration.
- **Vérification** : `bin/verify-migrations.php` et tests depuis chaque version supportée verts ; seconde exécution sans changement.
- **Risque** : élevé, chemin d'upgrade critique.
- **Rollback** : revenir au dispatcher monolithique ; ne jamais décrémenter automatiquement la version de schéma.
- **Effort** : L (> 1 j)
- **Commit suggéré** : `refactor(db): introduce ordered migration registry`

### [ ] STEP-09 — Ajouter les index de lecture observés
- **Catégorie** : BDD
- **Constats liés** : DB-01, DB-02
- **Prérequis** : STEP-02, STEP-08
- **Fichiers concernés** : migration nouvelle, `includes/class-psc-installer.php`, tests de migration
- **Actions** :
  1. Confirmer noms et absence des index par `SHOW INDEX`, volumes et plans avant migration.
  2. Expand : ajouter `KEY second_parent_email (second_parent_email)`, `KEY status_created (status,created_at)` et `KEY status_decided (status,decided_at)` via DDL en ligne supporté par la version cible.
  3. Observer temps, verrous et plans ; aucun contract n'est requis.
  4. SQL cible : `ALTER TABLE <prefix>psc_parents ADD INDEX second_parent_email (second_parent_email);` puis les deux `ALTER TABLE <prefix>psc_requests ADD INDEX …`.
- **Vérification** : `SHOW INDEX`; `EXPLAIN ANALYZE` utilise les nouveaux index ; suite complète verte et latence non régressée.
- **Risque** : moyen, durée/verrou dépend du volume et du moteur.
- **Rollback** : seulement après observation, `ALTER TABLE … DROP INDEX <nom>` un index à la fois ; les données restent intactes.
- **Effort** : M (< 1 j)
- **Commit suggéré** : `perf(db): index parent login and request cleanup`

### [ ] STEP-10 — Renforcer l'intégrité des relations d'audit
- **Catégorie** : BDD
- **Constats liés** : DB-03
- **Prérequis** : STEP-09
- **Fichiers concernés** : migration nouvelle, `includes/class-psc-installer.php`, tests suppressions/historique
- **Actions** :
  1. Compter les orphelins de `attendance.child_id` et `pickup_history.pickup_person_id` avec `LEFT JOIN … WHERE ref.id IS NULL`.
  2. Décider avec le métier si l'historique doit survivre : préférer `RESTRICT` ou rendre la référence nullable avec `ON DELETE SET NULL` plutôt qu'une cascade non validée.
  3. Expand : nettoyer/mettre à part les orphelins après export, puis ajouter les FK choisies en fenêtre contrôlée.
  4. Observer les suppressions ; contracter uniquement les anciens garde-fous devenus redondants dans un commit ultérieur.
- **Vérification** : zéro orphelin ; `information_schema.KEY_COLUMN_USAGE` contient les contraintes ; tests de suppression conformes à la décision métier.
- **Risque** : élevé, sémantique de conservation et verrous DDL.
- **Rollback** : retirer uniquement la contrainte, jamais les données d'historique exportées ; restaurer depuis sauvegarde si nettoyage erroné.
- **Effort** : L (> 1 j)
- **Commit suggéré** : `refactor(db): enforce audited relationship integrity`

### [ ] STEP-11 — Normaliser les adresses de connexion parentales
- **Catégorie** : BDD
- **Constats liés** : DB-04
- **Prérequis** : STEP-10
- **Fichiers concernés** : migration nouvelle, `includes/class-psc-parents.php`, `includes/class-psc-requests.php`, formulaires parent, tests sessions
- **Actions** :
  1. Rechercher collisions entre les deux colonnes et résoudre chaque cas manuellement.
  2. Expand : créer `psc_parent_emails(id, parent_id, email VARCHAR(191), role VARCHAR(20), active TINYINT, UNIQUE(email), KEY parent_id, FK parent_id ON DELETE CASCADE)`.
  3. Migrer par lots et double-écrire ; vérifier comptes, sessions et liens de confirmation.
  4. Basculer les lectures sur la nouvelle table, observer au moins un cycle métier, puis arrêter les écritures anciennes.
  5. Contract : ne proposer le retrait des anciennes colonnes qu'après requêtes de comptage, logs sans divergence et sauvegarde restaurable.
- **Vérification** : aucune adresse dupliquée ; authentification primaire/secondaire et révocations E2E vertes ; comparateur ancien/nouveau à zéro divergence.
- **Risque** : élevé, identité et accès aux données familiales.
- **Rollback** : maintenir les colonnes sources pendant toute l'observation, rebasculer les lectures et arrêter la double-écriture ; supprimer la nouvelle table seulement dans une migration ultérieure validée.
- **Effort** : L (> 1 j)
- **Commit suggéré** : `refactor(auth): normalize parent login addresses`

## 5. Questions ouvertes

1. Quelles versions du plugin et du schéma sont réellement encore déployées, et existe-t-il des sites devant monter depuis une version < 4.0 ?
2. Quels sont les volumes, le moteur/version MySQL ou MariaDB, les fenêtres de maintenance et les contraintes d'ALTER en production ?
3. Quelle durée d'observation couvre un cycle de facturation représentatif avant retrait de la compatibilité legacy ?
4. L'historique des personnes autorisées doit-il survivre à la suppression de la personne ou de l'enfant, et pendant quelle durée légale ?
5. Une adresse e-mail peut-elle légitimement appartenir à plusieurs foyers, ou l'unicité globale est-elle une règle métier ferme ?
6. Quelle référence visuelle fait foi pour les factures et le portail, et peut-elle être intégrée aux tests de non-régression ?
7. Quelle couverture minimale et quels workflows doivent bloquer une release ?
8. L'ancienne URL de planning fait-elle partie d'une API publique garantie, ou peut-elle être dépréciée avec redirection et date de fin ?

### Contrôle final

- Chaque constat possède une localisation, une preuve et une confiance.
- Les prérequis ne pointent que vers des étapes antérieures.
- CM-01 et CM-02 restent des investigations conditionnelles ; aucune suppression n'est prescrite sans preuve d'inactivité.
- Aucun `DROP` n'est proposé sans comptage, sauvegarde, observation et étape séparée de contract.
