# Déployer une nouvelle version

## Objectif

Installez une nouvelle version du plugin sur le serveur qui héberge le service, à partir de l'archive ZIP publiée, avec un retour arrière possible à chaque étape.

Le serveur de production reçoit **uniquement l'archive ZIP** de la Release. Il ne reçoit jamais le dépôt Git, ni les fichiers de l'environnement de développement.

## Avant de commencer

- Vous disposez d'un accès administrateur WordPress, ou d'un accès WP-CLI au serveur.
- Vous savez restaurer la base, le dossier privé et la clé de chiffrement : voir [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md).
- Choisissez un créneau calme : hors ouverture de la réinscription, hors veille de facturation, jamais pendant l'envoi des factures.

!!! warning "Ce qui ne doit jamais aller sur le serveur de production"
    - le dossier `mu-plugins/` du dépôt : `mailpit-smtp.php` détourne tous les e-mails vers une boîte de test, et `psc-frozen-clock.php` fige l'heure du plugin ;
    - les fichiers `docker-compose*.yml`, `.env`, `.env.*` et leurs mots de passe de développement ;
    - les dossiers `bin/`, `tests/`, `docs/` et `documentation/` : scripts de test et de peuplement, dont certains **détruisent** les tables ;
    - un `wp-config.php` de développement (`WP_DEBUG` actif, sels ou clés de test) ;
    - l'option `psc_invoice_debug_delete` : ce mode de test autorise la suppression de factures déjà envoyées.

    L'archive publiée ne contient déjà que le code du plugin (`includes/`, `templates/`, `assets/` et les fichiers racine). N'y ajoutez rien.

## Étapes

1. **Récupérez l'archive.** Sur la page des [versions publiées](https://github.com/eflye/wp-plugin-extrascolaire/releases), téléchargez `periscolaire-registration-X.Y.Z.zip` et `SHA256SUMS`. L'archive `montgeroult-familles-X.Y.Z.zip` contient le thème du site : ne la déployez que si le site utilise ce thème.
2. **Vérifiez son intégrité.** Placez `SHA256SUMS` à côté des archives, puis lancez `sha256sum -c SHA256SUMS` (sous macOS : `shasum -a 256 -c SHA256SUMS`). Chaque ligne doit afficher `OK`. Une archive qui ne correspond pas ne s'installe pas.
3. **Lisez les notes de version.** Le journal des modifications (`readme.txt`, rubrique **Changelog**) signale les changements visibles par la mairie et les familles, et les réglages à revoir après la mise à jour. Pour un saut de plusieurs versions, voir aussi [Notes de mise à jour](notes-mise-a-jour.md), qui détaille les contrôles propres à certaines migrations.
4. **Relevez la version actuelle.** Notez la version affichée dans **Extensions** et la version du schéma de la base, affichée à l'étape **2. Base de données** de **Périscolaire › Maintenance** (ou `wp option get psc_db_version` avec WP-CLI). Ces deux valeurs décident du retour arrière (étape 9).
5. **Sauvegardez.** Base de données, dossier privé et clé de chiffrement, selon [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md). Ne poursuivez pas sans une sauvegarde dont vous savez qu'elle se restaure.
6. **Installez l'archive.** Deux méthodes équivalentes :

    - depuis WordPress : **Extensions › Ajouter une extension › Téléverser une extension**, choisissez l'archive, puis confirmez le remplacement de la version installée ;
    - avec WP-CLI : `wp plugin install periscolaire-registration-X.Y.Z.zip --force`.

    Ne supprimez pas l'extension pour la réinstaller : si `PSC_REMOVE_DATA_ON_UNINSTALL` est déclarée dans `wp-config.php`, la suppression efface toutes les données du service, base et documents.
7. **Laissez la mise à jour du schéma se faire.** Ouvrez une page du backoffice. La base est mise à jour automatiquement, étape par étape ; aucune commande n'est à lancer. Vérifiez ensuite :

    - **Extensions** affiche la nouvelle version ;
    - l'étape **2. Base de données** de **Périscolaire › Maintenance** (ou `wp option get psc_db_version`) affiche la version de schéma attendue, si les notes de version en annoncent une nouvelle ;
    - aucune alerte rouge « la mise à jour de la base de données est incomplète » ni « des documents n'ont pas pu être déplacés » n'apparaît. Si c'est le cas, voir [Dépannage et FAQ](depannage-faq.md) : les étapes réussies sont conservées, et la mise à jour reprend à l'étape en cause.
8. **Recettez.** En moins de dix minutes, sur un foyer de test :

    - le **Tableau de bord** s'affiche, sans alerte nouvelle ;
    - un lien de connexion arrive par e-mail, et le portail famille s'ouvre ;
    - un justificatif d'assurance et une facture existants se téléchargent ;
    - le planning d'un enfant de test s'enregistre, puis s'annule ;
    - le **Journal d'audit** contient la ligne « Schéma de la base mis à jour » si le schéma a changé.

    Après un changement d'hébergement ou de version de PHP, déroulez aussi la [fiche de recette de l'hébergement](fiche-recette-p1.md).
9. **En cas de problème, revenez en arrière.**

    - **Si la version du schéma n'a pas changé** (étape 4 et étape 7 identiques), réinstallez simplement l'archive précédente avec la même méthode qu'à l'étape 6.
    - **Si le schéma a changé**, l'archive précédente seule ne suffit pas : son code ne connaît pas la nouvelle structure de la base. Restaurez ensemble la base et le dossier privé sauvegardés à l'étape 5, puis réinstallez l'archive précédente. Les saisies faites depuis la sauvegarde sont perdues : prévenez la mairie avant.

## Résultat attendu

La nouvelle version est installée à partir d'une archive vérifiée, la base est à jour sans alerte, les documents restent téléchargeables et la recette est faite. Une sauvegarde restaurable permet de revenir en arrière.

## Pour aller plus loin

- [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md)
- [Versions et dépendances](versions-dependances.md)
- [Fiche de recette de l'hébergement](fiche-recette-p1.md)
- [Dépannage et FAQ](depannage-faq.md)
