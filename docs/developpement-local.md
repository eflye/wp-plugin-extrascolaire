# Développement local et tests

Guide développeur, non publié sur le site de documentation. La mise en production suit une autre procédure : voir [Déployer une nouvelle version](../documentation/installation/deploiement-zip.md).

## Quatre environnements, quatre usages

| Environnement | Ce qu'il reçoit | Données | À quoi il sert |
| --- | --- | --- | --- |
| **Stack de travail** (Podman, `:8080`) | Le dépôt, monté dans le conteneur | Fictives, conservées d'une session à l'autre | Développer, essayer à la main |
| **Stack de test** (Podman, `:8081`, `bin/test-stack.sh`) | Le même dépôt, monté dans un second conteneur | Fictives, écrasées par chaque suite | Lancer Playwright et `bin/verify-*.php` sans toucher à la stack de travail |
| **Instance de test** ([auto-hébergement Docker](self-hosting-docker.md), base jetable) | Le dépôt ou une archive | Fictives | Bêta-test, essai de mise à jour, restauration |
| **Serveur de production** | L'archive ZIP de la Release, et elle seule | Réelles | Le service |

Rien ne passe du poste de développement à la production en dehors de l'archive publiée : ni `mu-plugins/`, ni `.env`, ni `docker-compose*.yml`, ni `bin/`, ni un `wp-config.php` de développement.

## La pile locale

`docker-compose.yml`, utilisé par `podman compose` :

| Service | Accès | Rôle |
| --- | --- | --- |
| `wordpress` | http://localhost:8080 (boucle locale seulement) | WordPress, PHP 8.3, `WP_DEBUG` actif |
| `db` | interne | MySQL 8 |
| `mailpit` (profil `mailpit`) | http://localhost:8025 (boucle locale seulement) | Capture tous les e-mails |

Le dépôt est monté dans `wp-content/plugins/periscolaire-registration`, comme `mu-plugins/` et le thème `theme/Archive`. Une modification du dépôt est donc visible immédiatement, sans copie.

```bash
podman machine start
MAILPIT_ENABLED=true podman compose --profile mailpit up -d
npm ci
```

Rien n'est installé sur l'hôte à part Node : PHP, Composer et WP-CLI s'exécutent dans le conteneur.

```bash
# WP-CLI
podman exec plugin-extrascolaire-wordpress-1 php /usr/local/bin/wp-cli.phar --allow-root --path=/var/www/html <commande>
```

`playwright/global-setup.ts` copie `wp-cli.phar` dans le conteneur au premier lancement des tests, puis peuple la base avec `bin/seed-journey.php`.

### Les mu-plugins de développement

Montés uniquement par `docker-compose.yml`, jamais présents dans l'archive :

- `mu-plugins/mailpit-smtp.php` détourne tous les `wp_mail()` vers Mailpit, lorsque `MAILPIT_ENABLED=true` ;
- `mu-plugins/psc-frozen-clock.php` fige l'heure du plugin sur l'option `psc_test_frozen_now`, posée par le peuplement. Sans elle, les scénarios qui dépendent du délai de modification échouent selon la date du jour.

### Pièges connus

- **Écrire les fichiers en place.** Un outil qui remplace le fichier (`sed -i`, `git stash`) change son inode, et le montage Podman peut continuer à servir l'ancien contenu un moment.
- **`/tmp` du Mac n'est pas visible du conteneur.** Pour un fichier à passer au conteneur, utilisez `.cache/` du dépôt (ignoré par Git).
- **Ne lancez pas les suites sur la stack de travail.** `npx playwright test` nu seede le conteneur `plugin-extrascolaire-wordpress-1` : familles, année active et horloge sont remplacées (le `globalSetup` l'annonce avant de le faire). Utilisez la [stack de test](#la-stack-de-test).
- **Réinitialiser la stack de travail :** `podman compose --profile mailpit down -v` (supprime les volumes, donc la base locale), puis `up -d`, `wp core install` sous `-u www-data` (admin/admin), activation du thème `Archive` et du plugin, et page « Espace familles » avec `[periscolaire_form]` comme page d'accueil — ou, plus simple, `bin/test-stack.sh up` fait tout cela pour la stack de test.

## La stack de test

`bin/test-stack.sh` monte une seconde instance depuis le même `docker-compose.yml`, sous le nom de projet compose `psc-test` et sur d'autres ports (`PSC_WP_PORT`, `PSC_MAILPIT_PORT`). Les volumes sont préfixés par le projet : la base est séparée sans rien déclarer. Le dépôt y est monté en bind comme sur la stack de travail, donc c'est bien le code en cours d'édition qui est testé.

| Service | Accès |
| --- | --- |
| `wordpress` | http://localhost:8081 (admin / admin) |
| `mailpit` | http://localhost:8026 (toujours actif) |

```bash
npm run test:local:up      # démarre, installe WordPress et active l'extension (idempotent)
npm run test:local         # toute la suite Playwright, profil test, sur cette stack
npm run test:local -- tests/invoices.spec.ts   # un seul fichier
npm run verify:local migrations                # bin/verify-migrations.php, sans risque
bin/test-stack.sh wp option get psc_db_version # WP-CLI sous www-data
npm run test:local:down    # détruit conteneurs et volumes
bin/test-stack.sh reset    # base neuve (down puis up) : à faire avant une suite complète
```

Les scénarios supposent une base neuve, comme en CI : certains laissent des traces (passage d'année, factures du mois déjà générées) qui font échouer le passage suivant. Un seul fichier peut se rejouer sans `reset` ; la suite complète, non.

`up` reproduit les étapes d'installation de `.github/workflows/e2e.yml` (même utilisateur `www-data`, `WP_ENVIRONMENT_TYPE local`, thème, extension, page d'accueil). Pour viser la stack depuis un autre outil, `bin/test-stack.sh env` affiche les quatre variables lues par `helpers/env.ts` : `PSC_CONTAINER_ENGINE`, `PSC_WP_CONTAINER`, `PSC_APP_BASE`, `PSC_MAILPIT_API`. Sans elles, scénarios et `globalSetup` visent la stack de travail et la CI.

Les deux stacks tournent en même temps ; la stack de test peut rester arrêtée (`podman compose -p psc-test stop`) entre deux sessions et repartir avec `up`.

## Catalogue de traduction

`languages/periscolaire-registration.pot` est le catalogue source des ~2 300 chaînes de l'extension, généré par `bin/make-pot.sh` (`wp i18n make-pot` dans le conteneur, sans WordPress chargé). La CI (`e2e.yml`, étape « Catalogue de traduction à jour ») échoue s'il ne reflète plus le code : après avoir ajouté ou modifié une chaîne, lancez `bin/make-pot.sh` et committez le fichier. Une chaîne avec `%s` doit être précédée d'un commentaire `/* translators: … */`, sinon la génération l'annonce. Aucune traduction compilée n'est embarquée ; un `.mo` déposé dans `languages/` (ou dans `wp-content/languages/plugins/`) serait chargé.

## Tests

| Commande | Ce qu'elle vérifie |
| --- | --- |
| `npm run test:local` | Parcours de bout en bout et axe, profil `test`, sur la stack de test. |
| `npx playwright test` | Idem sur la stack de travail (projets `test` et `demo`). **Écrase la base locale.** |
| `npm run lint:js` | ESLint sur `assets/js` |
| `podman exec -w /var/www/html/wp-content/plugins/periscolaire-registration plugin-extrascolaire-wordpress-1 php vendor/bin/phpstan analyse --memory-limit=2G` | PHPStan niveau 3 |
| `podman exec plugin-extrascolaire-wordpress-1 php /var/www/html/wp-content/plugins/periscolaire-registration/tests/unit/run.php` | Tests unitaires sans WordPress |
| `podman run --rm -v "$PWD:/app:ro" docker.io/library/php:7.4-cli php -l /app/<fichier>` | Syntaxe au plancher PHP 7.4 (le conteneur local est en 8.3) |
| `PSC_CONTAINER_ENGINE=podman npm run test:backup-restore` | Restauration d'un dump dans une base distincte (`bin/verify-database-backup.sh`) |

### Scripts `bin/verify-*.php`

Ils jouent le rôle de tests unitaires, avec WordPress chargé. La CI les lance **sous `www-data`**, comme le serveur web : certains comportements (déménagement du dossier privé, droits de fichiers) n'existent pas sous root.

```bash
# Sur la stack de test (recommandé)
npm run verify:local <nom>

# Sur la stack de travail
podman exec -u www-data -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache plugin-extrascolaire-wordpress-1 \
  php /usr/local/bin/wp-cli.phar --path=/var/www/html \
  --require=/var/www/html/wp-content/plugins/periscolaire-registration/bin/verify-<nom>.php verify-<nom>
```

`bin/verify-channel-contracts.php` confronte les canaux (commande fournisseur, avis de fermeture, annulation de classe, facture) à la table de décision des [contrats d'une journée](contrats-planning.md).

Ils purgent leurs données avant et après. **Exception : `bin/verify-migrations.php` détruit le schéma du plugin** pour rejouer une montée depuis le schéma 4.15.0 (version 5.28.0, la plus ancienne prise en charge). Ne le lancez jamais sur la stack de travail : `npm run verify:local migrations` le joue sur la stack de test, comme la CI.

Chaque nouvelle vérification se valide par mutation : réintroduire le défaut corrigé doit faire échouer au moins une vérification.

## Modes de test

### Suppression des factures envoyées (mode debug)

« Supprimer les factures du mois » ne supprime que les factures jamais envoyées. Pour recommencer un scénario de facturation sur une instance de test, le mode debug autorise la suppression de toutes les factures du mois, envoyées comprises :

```bash
# Activer
podman exec plugin-extrascolaire-wordpress-1 php /usr/local/bin/wp-cli.phar --allow-root --path=/var/www/html option update psc_invoice_debug_delete 1
# Vérifier (1 = actif ; erreur « Could not get » = inactif)
podman exec plugin-extrascolaire-wordpress-1 php /usr/local/bin/wp-cli.phar --allow-root --path=/var/www/html option get psc_invoice_debug_delete
# Désactiver
podman exec plugin-extrascolaire-wordpress-1 php /usr/local/bin/wp-cli.phar --allow-root --path=/var/www/html option delete psc_invoice_debug_delete
```

Sur un serveur, la même commande s'écrit `wp option update psc_invoice_debug_delete 1`. Tant que le mode est actif, une alerte rouge s'affiche sur les écrans du plugin. Ne l'activez jamais en production : la procédure de déploiement le signale parmi ce qui ne doit pas atteindre le serveur. Documentation côté mairie : [Factures PDF](../documentation/facturation/factures-pdf.md), étape 5.

### Clé de chiffrement des IBAN

`wp psc chiffrement statut | generer-cle | rechiffrer [--dry-run]` (classe `Psc_Key_Rotation`), ou **Périscolaire › Maintenance** (`Psc_Admin_Maintenance`). En local, sans `PSC_ENCRYPTION_KEY` (constante ou variable d'environnement), la clé vient de l'option `secret_key` en base. Pour l'essayer : `podman exec -e PSC_ENCRYPTION_KEY=… plugin-extrascolaire-wordpress-1 php /usr/local/bin/wp-cli.phar …`, ou `wp --exec="define('PSC_ENCRYPTION_KEY','…');"`. Procédure côté exploitant : [Clé de chiffrement des IBAN](../documentation/installation/cle-chiffrement.md). Le script `bin/verify-key-rotation.php` simule les clés par le filtre `psc_encryption_secrets` et ne touche qu'à ses propres lignes.

## Publier une version

1. Mettre à jour `PSC_VERSION`, l'en-tête du fichier principal et `Stable tag`, puis ajouter le **Changelog** dans `readme.txt`.
2. Ouvrir une PR, attendre la CI (lint PHP 7.4 à 8.3, PHPStan, ESLint, Playwright, vérifications autonomes), fusionner.
3. Poser le tag `vX.Y.Z` sur `main` et le pousser. `release.yml` relance lint et E2E, construit l'archive, la fume dans un WordPress vierge puis par-dessus la version précédente, et publie les deux archives avec `SHA256SUMS`.

L'archive ne contient que `periscolaire-registration.php`, `index.php`, `uninstall.php`, `LICENSE`, `readme.txt`, `README.md`, `includes/`, `templates/`, `assets/` et `languages/`. Toute nouvelle entrée racine suivie par Git doit être ajoutée au paquet ou à la liste d'exclusion de `release.yml`, faute de quoi la publication échoue.
