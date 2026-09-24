# Sauvegarde et mise à jour

## Objectif

Protégez les données du service et appliquez les mises à jour du plugin sans perdre les inscriptions ni les documents privés.

## Avant de commencer

Vous devez disposer d'un accès à la base de données, aux fichiers WordPress et au répertoire privé configuré par l'instance. Testez la restauration d'une sauvegarde sur un environnement isolé avant une opération majeure.

## Étapes

1. Sauvegardez la base de données WordPress, qui contient les foyers, enfants, plannings, factures, messages et réglages. Vérifiez que le dump peut être restauré dans une base distincte ; le script `bin/verify-database-backup.sh` automatise cette comparaison sans toucher à la base source.
2. Sauvegardez également le dossier des documents privés (justificatifs d'assurance et factures PDF). Identifiez d'abord le chemin réellement utilisé par votre instance :

    - la valeur de `PSC_PRIVATE_DIR` si cette constante est déclarée dans `wp-config.php` ;
    - sinon `psc-private`, placé **à côté** de la racine WordPress et non dedans, pour qu'aucune URL ne puisse l'atteindre ;
    - sinon `wp-content/uploads/psc-private`, emplacement de repli utilisé lorsque le dossier parent de la racine n'est pas accessible en écriture.

    Incluez ce chemin dans la sauvegarde et protégez ses copies au même niveau que les documents eux-mêmes.

3. Sauvegardez la clé qui déchiffre les coordonnées bancaires, et conservez-la **séparément du dump de base**. Il s'agit de la constante `PSC_ENCRYPTION_KEY` de `wp-config.php` lorsqu'elle est déclarée ; à défaut, la clé est dérivée des sels WordPress du même fichier.

    !!! danger
        Une base restaurée sans cette clé — ou après une régénération des sels WordPress — rend les IBAN déjà enregistrés illisibles : les familles devront les ressaisir. Inversement, ne stockez jamais la clé et le dump dans la même archive ni sous le même accès : c'est leur séparation qui protège les IBAN en cas de fuite du dump seul.

4. Avant une mise à jour de version majeure, mettez le site en maintenance si nécessaire, réalisez ces trois sauvegardes et conservez un point de retour vérifié.
5. Téléchargez la nouvelle Release, puis remplacez les fichiers du dossier du plugin dans `wp-content/plugins/periscolaire-registration/`. Ne supprimez pas la base ni le dossier privé pendant cette opération.
6. Rechargez une page WordPress après le remplacement. La méthode `maybe_upgrade()` détecte la version du schéma et applique automatiquement les migrations et les nouvelles tables ; aucune commande manuelle de migration n'est requise.
7. Contrôlez le tableau de bord, les réglages, un portail famille de test et la présence des documents après la mise à jour. En cas de problème, restaurez les fichiers et la sauvegarde validée, puis contactez la personne qui maintient l'instance.
8. Testez périodiquement la restauration complète sur un environnement isolé : base, dossier privé et clé. Neutralisez l'envoi d'e-mails avant de démarrer cet environnement, faute de quoi la restauration expédie de vrais liens de connexion aux familles. Vérifiez sur un dossier de test qu'un justificatif s'ouvre, qu'une facture se télécharge et qu'un IBAN enregistré reste déchiffrable.

## Résultat attendu

La nouvelle version est chargée, la base est migrée automatiquement, les fichiers privés sont toujours accessibles de façon protégée et un retour arrière reste possible grâce aux sauvegardes vérifiées — base, dossier privé et clé de chiffrement comprises.

## Pour aller plus loin

- [Prérequis](prerequis.md)
- [Tâches planifiées](taches-planifiees.md)
- [Données personnelles (RGPD)](../rgpd.md)
