# Développement local et tests

Guide développeur, non publié sur le site de documentation. La mise en production suit une autre procédure : voir [Déployer une nouvelle version](../documentation/installation/deploiement-zip.md).

## Trois environnements, trois usages

| Environnement | Ce qu'il reçoit | Données | À quoi il sert |
| --- | --- | --- | --- |
| **Poste de développement** (Podman) | Le dépôt, monté dans le conteneur | Fictives, modifiées par les tests | Développer, lancer les tests |
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
- **La base locale dérive.** Les suites de bout en bout la modifient (année active, passage d'année, données de documentation). Des échecs locaux qui n'existent pas en CI viennent souvent de là ; la CI part d'une base neuve et fait foi.
- **Réinitialiser l'environnement :** `podman compose --profile mailpit down -v` (supprime les volumes, donc la base locale), puis `up -d`, `wp core install` sous `-u www-data` (admin/admin), activation du thème `Archive` et du plugin, et page « Espace familles » avec `[periscolaire_form]` comme page d'accueil.

## Tests

| Commande | Ce qu'elle vérifie |
| --- | --- |
| `npx playwright test` | Parcours de bout en bout et axe, projets `test` et `demo`. Modifie la base locale. |
| `npm run lint:js` | ESLint sur `assets/js` |
| `podman exec -w /var/www/html/wp-content/plugins/periscolaire-registration plugin-extrascolaire-wordpress-1 php vendor/bin/phpstan analyse --memory-limit=2G` | PHPStan niveau 3 |
| `podman exec plugin-extrascolaire-wordpress-1 php /var/www/html/wp-content/plugins/periscolaire-registration/tests/unit/run.php` | Tests unitaires sans WordPress |
| `podman run --rm -v "$PWD:/app:ro" docker.io/library/php:7.4-cli php -l /app/<fichier>` | Syntaxe au plancher PHP 7.4 (le conteneur local est en 8.3) |
| `PSC_CONTAINER_ENGINE=podman npm run test:backup-restore` | Restauration d'un dump dans une base distincte (`bin/verify-database-backup.sh`) |

### Scripts `bin/verify-*.php`

Ils jouent le rôle de tests unitaires, avec WordPress chargé. La CI les lance **sous `www-data`**, comme le serveur web : certains comportements (déménagement du dossier privé, droits de fichiers) n'existent pas sous root.

```bash
podman exec -u www-data -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache plugin-extrascolaire-wordpress-1 \
  php /usr/local/bin/wp-cli.phar --path=/var/www/html \
  --require=/var/www/html/wp-content/plugins/periscolaire-registration/bin/verify-<nom>.php verify-<nom>
```

`bin/verify-channel-contracts.php` confronte les canaux (commande fournisseur, avis de fermeture, annulation de classe, facture) à la table de décision des [contrats d'une journée](contrats-planning.md).

Ils purgent leurs données avant et après. **Exception : `bin/verify-migrations.php` détruit le schéma du plugin** pour rejouer une montée depuis 2.4.9. Ne le lancez jamais sur la base locale : utilisez une base jetable, que le conteneur WordPress choisit par la variable `WORDPRESS_DB_NAME`.

```bash
podman exec plugin-extrascolaire-db-1 sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS psc_scratch; GRANT ALL ON psc_scratch.* TO \"wordpress\"@\"%\";"'
S="podman exec -e WORDPRESS_DB_NAME=psc_scratch plugin-extrascolaire-wordpress-1 php /usr/local/bin/wp-cli.phar --allow-root --path=/var/www/html"
$S core install --url=http://localhost:8080 --title=Scratch --admin_user=admin --admin_password=admin --admin_email=scratch@example.invalid --skip-email
$S plugin activate periscolaire-registration
$S --require=/var/www/html/wp-content/plugins/periscolaire-registration/bin/verify-migrations.php verify-migrations
```

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

L'archive ne contient que `periscolaire-registration.php`, `index.php`, `uninstall.php`, `LICENSE`, `readme.txt`, `README.md`, `includes/`, `templates/` et `assets/`. Toute nouvelle entrée racine suivie par Git doit être ajoutée au paquet ou à la liste d'exclusion de `release.yml`, faute de quoi la publication échoue.
