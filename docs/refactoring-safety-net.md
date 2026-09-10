# Filet de sécurité du refactoring

Ce document fixe les contrôles obligatoires avant chaque étape du
`REFACTORING_PLAN.md`. Une étape ne doit pas être fusionnée si l'un de ces
contrôles échoue.

## Portes de qualité

| Contrôle | Commande | Périmètre |
|---|---|---|
| Syntaxe PHP | workflow `PHP Lint`, PHP 7.4 à 8.3 | Tous les fichiers PHP hors FPDF tiers |
| Analyse statique | `composer phpstan` | Sources de `includes/`, niveau 3 |
| Tests PHP purs | `tests/unit/run.php`, `sepa-export.php`, `menu-labels.php` | Helpers, chiffrement, banque, planning, SEPA et libellés |
| JavaScript | `npm run lint:js` | Toutes les ressources de `assets/js/` |
| Banque navigateur | `npm run test:banking` | IBAN/BIC sur les quatre formulaires et 1 066 cas |
| Intégration WordPress | scripts `bin/verify-*.php` du workflow E2E | Migrations, promotion annuelle et historique des habilitations |
| Parcours navigateur | `npm run test:e2e` | Portail famille, back-office et intervenants sur WordPress/MySQL réels |
| Sauvegarde/restauration | `bin/verify-database-backup.sh` | Dump transactionnel, restauration isolée, comparaison exacte, suppression de la copie |
| Paquet distribuable | workflow de release | Installation et activation du ZIP sur un WordPress vierge |

## Couverture fonctionnelle assumée

La couverture en lignes n'est pas retenue comme porte de qualité à ce stade :
le code WordPress repose largement sur des hooks, des redirections et des
appels `$wpdb`, pour lesquels un pourcentage sans harnais WordPress instrumenté
serait trompeur. La couverture est donc suivie par scénarios caractérisés :

- inscription initiale, validation de demande et famille déjà connue ;
- connexion famille, profil, second parent et personnes habilitées ;
- enfants, assurances par enfant, revue mairie et remplacement ;
- planning 1 et 2, rythme, exceptions, forfait, fratrie et verrous ;
- année scolaire, promotion, calendrier, menus et commande fournisseur ;
- présences intervenants, facturation, prélèvement SEPA et format pain.008 ;
- messages mairie, ciblage, lecture, canaux et notifications dans l'onglet ouvert ;
- affichage responsive et absence de débordement horizontal.

Tout bug corrigé doit ajouter ou renforcer un scénario du domaine concerné.
Une mesure chiffrée pourra être introduite lorsque les tests PHP disposeront
d'un bootstrap WordPress instrumentable sans réduire la matrice fonctionnelle.

## Sauvegarde et restauration

Le script refuse d'utiliser la même base comme source et destination. Il crée
une base isolée nommée `wordpress_step01_restore` par défaut, y importe un dump
transactionnel, redumpe cette copie et compare schéma, index et données après
canonisation de la seule déclaration redondante de charset produite par MySQL.
Les dumps temporaires sont en mode `0600` et supprimés à la sortie ; la base de
restauration est également supprimée.

Cette vérification automatisée porte sur l'instance jetable de CI. Avant une
opération sur une instance réelle, l'exploitant doit toujours réaliser la
sauvegarde propre à cette instance et tester sa restauration dans un serveur
isolé avec les mêmes versions MySQL/MariaDB. Aucun dump contenant des données
familiales ne doit être ajouté au dépôt ou publié comme artefact CI.
