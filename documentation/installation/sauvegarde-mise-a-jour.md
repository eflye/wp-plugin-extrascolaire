# Sauvegarde et mise à jour

## Objectif

Protégez les données du service et appliquez les mises à jour du plugin sans perdre les inscriptions ni les documents privés.

## Avant de commencer

Vous devez disposer d'un accès à la base de données, aux fichiers WordPress et au répertoire privé configuré par l'instance. Testez la restauration d'une sauvegarde sur un environnement isolé avant une opération majeure.

## Étapes

1. Sauvegardez la base de données WordPress, qui contient les foyers, enfants, plannings, factures, messages et réglages. Vérifiez que le dump peut être restauré dans une base distincte ; le script `bin/verify-database-backup.sh` automatise cette comparaison sans toucher à la base source.
2. Sauvegardez également le dossier des documents privés (justificatifs d'assurance et factures PDF). Son emplacement est configurable par la constante `PSC_PRIVATE_DIR` ; incluez le chemin réellement utilisé par votre instance dans la sauvegarde et protégez ses copies.
3. Avant une mise à jour de version majeure, mettez le site en maintenance si nécessaire, réalisez ces deux sauvegardes et conservez un point de retour vérifié.
4. Téléchargez la nouvelle Release, puis remplacez les fichiers du dossier du plugin dans `wp-content/plugins/periscolaire-registration/`. Ne supprimez pas la base ni le dossier privé pendant cette opération.
5. Rechargez une page WordPress après le remplacement. La méthode `maybe_upgrade()` détecte la version du schéma et applique automatiquement les migrations et les nouvelles tables ; aucune commande manuelle de migration n'est requise.
6. Contrôlez le tableau de bord, les réglages, un portail famille de test et la présence des documents après la mise à jour. En cas de problème, restaurez les fichiers et la sauvegarde validée, puis contactez la personne qui maintient l'instance.

## Résultat attendu

La nouvelle version est chargée, la base est migrée automatiquement, les fichiers privés sont toujours accessibles de façon protégée et un retour arrière reste possible grâce aux sauvegardes vérifiées.

## Pour aller plus loin

- [Prérequis](prerequis.md)
- [Tâches planifiées](taches-planifiees.md)
- [Données personnelles (RGPD)](../rgpd.md)
