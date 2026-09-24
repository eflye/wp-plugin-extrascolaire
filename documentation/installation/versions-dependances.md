# Versions et dépendances

## Objectif

Savoir sur quelles versions de PHP, WordPress et MySQL faire tourner le site de production, et quelles bibliothèques le plugin embarque.

## Deux niveaux à ne pas confondre

- **Compatibilité minimale** : `Requires PHP: 7.4` et `Requires at least: 5.8`. Le code reste valide sous PHP 7.4 (contrôlé à chaque modification par l'intégration continue, de PHP 7.4 à 8.3), pour qu'un hébergement ancien ne casse pas à la mise à jour.
- **Production maintenue** : ce qu'il faut réellement faire tourner. PHP 7.4, 8.0 et 8.1 ne reçoivent plus de correctifs de sécurité du projet PHP ([versions supportées](https://www.php.net/supported-versions.php), [versions abandonnées](https://www.php.net/eol.php)). Un site qui traite des données d'enfants doit tourner sur une version encore maintenue.

## Versions recommandées pour la production

| Composant | Recommandé | Testé en continu |
|---|---|---|
| PHP | 8.2 ou 8.3 (maintenues) | 7.4 → 8.3 (syntaxe), 8.3 (parcours complets) |
| WordPress | dernière version stable | dernière version stable (image `wordpress:latest`) |
| MySQL | 8.0 ou plus récent | 8.0 |

Relevé de l'environnement de test au 24/09/2026 : PHP 8.3.33, WordPress 7.1, MySQL 8.0.46.

## Calendrier de maintenance

- **À chaque version publiée** : l'intégration continue rejoue le lint PHP 7.4 → 8.3, l'analyse statique, les parcours complets et la mise à jour depuis la version précédente, avant toute publication.
- **Chaque semestre** : relever les versions PHP, WordPress et MySQL du serveur de la mairie auprès de l'hébergeur, et les comparer au tableau ci-dessus. Une version de PHP qui sort du support de sécurité doit être remplacée dans le semestre.
- **À chaque nouvelle version majeure de PHP** : l'ajouter à la matrice de lint (`.github/workflows/lint.yml`), puis relever `Tested up to` dans `readme.txt` une fois les parcours complets verts.

## Dépendances

### Embarquées dans le plugin (livrées sur le serveur)

| Dépendance | Version | Licence | Usage |
|---|---|---|---|
| FPDF | 1.9 | licence FPDF (libre, usage et modification permis) | génération des factures PDF |
| Public Sans (police) | 2.001 | SIL Open Font License | interface du portail des familles |

Aucune dépendance Composer ni npm n'est livrée : le plugin fonctionne avec PHP et WordPress seuls.

### Outillage de développement (jamais livré)

- **Composer** : PHPStan (analyse statique).
- **npm** : Playwright et axe-core (parcours et accessibilité), ESLint.
- **WP-CLI** : téléchargé à chaque exécution de l'intégration continue ; son empreinte SHA-512 publiée est vérifiée avant usage.
- **Actions GitHub** : versions majeures épinglées (`actions/checkout@v4`, `shivammathur/setup-php@v2`, `softprops/action-gh-release@v2`…).

## Surveillance des avis de sécurité

L'intégration continue refuse une modification si `composer audit` ou `npm audit` (niveau élevé) signale un avis sur l'outillage. FPDF n'a pas de flux d'avis automatisé : son site et ses notes de version sont à consulter lors de la revue semestrielle.

## Pour aller plus loin

- [Prérequis](prerequis.md)
- [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md)
