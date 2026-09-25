# Sauvegarde et mise à jour

## Objectif

Protégez les données du service (base, documents privés et clé de chiffrement) et gardez un point de retour vérifié avant chaque mise à jour du plugin.

## Avant de commencer

Vous devez disposer d'un accès à la base de données, aux fichiers WordPress et au répertoire privé configuré par l'instance. Testez la restauration d'une sauvegarde sur un environnement isolé avant une opération majeure.

## Étapes

1. Sauvegardez la base de données WordPress, qui contient les foyers, enfants, plannings, factures, messages et réglages. Vérifiez que le dump peut être restauré dans une base distincte, sans jamais le recharger dans la base de production.
2. Sauvegardez également le dossier des documents privés (justificatifs d'assurance et factures PDF). Identifiez d'abord le chemin réellement utilisé par votre instance :

    - la valeur de `PSC_PRIVATE_DIR` si cette constante est déclarée dans `wp-config.php` ;
    - sinon `psc-private`, placé **à côté** de la racine WordPress et non dedans, pour qu'aucune URL ne puisse l'atteindre ;
    - sinon `wp-content/uploads/psc-private`, emplacement de repli utilisé lorsque le dossier parent de la racine n'est pas accessible en écriture.

    Incluez ce chemin dans la sauvegarde et protégez ses copies au même niveau que les documents eux-mêmes.

3. Sauvegardez la clé qui déchiffre les coordonnées bancaires, et conservez-la **séparément du dump de base**. Il s'agit de la constante `PSC_ENCRYPTION_KEY` de `wp-config.php`. Vérifiez qu'elle est bien déclarée avec `wp psc chiffrement statut`.

    Si elle ne l'est pas, la clé est tirée de l'option `secret_key`, **enregistrée dans la base** : le dump contient alors de quoi déchiffrer les IBAN, et aucune séparation n'est possible. Sortez la clé de la base en suivant [Clé de chiffrement des IBAN](cle-chiffrement.md).

    !!! danger
        Une base restaurée sans la constante `PSC_ENCRYPTION_KEY` qui a chiffré ses IBAN rend ceux-ci illisibles : les familles devront les ressaisir. Inversement, ne stockez jamais la clé et le dump dans la même archive ni sous le même accès : c'est leur séparation qui protège les IBAN en cas de fuite du dump seul.

4. Avant chaque mise à jour, réalisez ces trois sauvegardes et conservez un point de retour vérifié. La procédure de mise à jour elle-même, du téléchargement de l'archive au retour arrière, est décrite dans [Déployer une nouvelle version](deploiement-zip.md).

   La mise à jour de la base se fait par étapes, et chaque étape n'est enregistrée qu'une fois terminée sans erreur. Si une étape échoue (droits de modification des tables refusés, délai dépassé, processus interrompu), les étapes précédentes restent acquises : une alerte rouge « la mise à jour de la base de données est incomplète » nomme l'étape en cause, et une nouvelle tentative reprend à cette étape à chaque ouverture du backoffice. Côté public, une nouvelle tentative a lieu au plus toutes les 5 minutes.

   Changer d'emplacement de documents (`PSC_PRIVATE_DIR`) déplace les fichiers au chargement suivant. Le nouvel emplacement n'est retenu qu'une fois tous les fichiers déplacés. Un fichier présent aux deux endroits avec un contenu différent, ou un dossier non modifiable, est laissé en place. Aucun fichier n'est supprimé, et l'alerte « des documents n'ont pas pu être déplacés » l'indique jusqu'à la résolution.
5. Testez périodiquement la restauration complète sur un environnement isolé : base, dossier privé et clé. Neutralisez l'envoi d'e-mails avant de démarrer cet environnement, faute de quoi la restauration expédie de vrais liens de connexion aux familles. Vérifiez sur un dossier de test qu'un justificatif s'ouvre, qu'une facture se télécharge et qu'un IBAN enregistré reste déchiffrable.

## Résultat attendu

Les trois éléments nécessaires à une restauration (base, dossier privé et clé de chiffrement) sont sauvegardés séparément, et leur restauration a été vérifiée sur un environnement isolé. Une mise à jour peut donc toujours être annulée.

## Pour aller plus loin

- [Déployer une nouvelle version](deploiement-zip.md)
- [Clé de chiffrement des IBAN](cle-chiffrement.md)
- [Prérequis](prerequis.md)
- [Tâches planifiées](taches-planifiees.md)
- [Données personnelles (RGPD)](../rgpd.md)
