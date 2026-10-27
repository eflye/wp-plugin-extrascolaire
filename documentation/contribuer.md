# Contribuer à la documentation

## Où vivent les sources

Écrivez les pages publiées dans `documentation/`. MkDocs publie uniquement ce dossier.

Ne créez pas de page dans `docs/` : ce dossier conserve les notes développeur (environnement local, contrats du planning, export SEPA), la feuille de route et les audits archivés ; il ne fait pas partie du site publié.

## Prévisualiser en local

Installez les dépendances, puis démarrez le serveur de prévisualisation à la racine du dépôt :

```bash
pip install -r requirements.txt
mkdocs serve
```

Ouvrez ensuite l'adresse indiquée par MkDocs dans votre navigateur. Avant de proposer une modification, exécutez `mkdocs build --strict`.

## Écrire une page

Appliquez ces règles à chaque page :

1. Rédigez en vouvoyant, avec des phrases courtes et le vocabulaire de la mairie.
2. Utilisez un seul titre de niveau 1, puis une hiérarchie de titres continue.
3. Reprenez les libellés de l'interface en gras, exactement comme ils s'affichent.
4. Utilisez `!!! tip` pour une astuce et `!!! warning` avant une action peu réversible.
5. Utilisez des données fictives, des liens explicites et un texte alternatif descriptif pour chaque image.

Ajoutez un lien vers le [glossaire](glossaire.md) à la première occurrence d'un terme métier. La page suit la structure des pages existantes : objectif, prérequis (« Avant de commencer »), étapes, résultat attendu et liens internes pour aller plus loin. Les chemins du back office reprennent les libellés du menu **Périscolaire** tels qu'ils s'affichent (par exemple **Périscolaire › Fiches familles**, **Périscolaire › Présences déclarées**).

## Régénérer les captures d'écran

Les captures versionnées sont dans `documentation/assets/screenshots/`. Leur nom est stable : conservez-le lorsqu'une page les utilise.

Depuis la racine du dépôt, exécutez :

```bash
npm run docs:screenshots
```

La commande recrée les 21 images à partir du jeu de données fictif prévu pour la documentation : commune de Montgeroult, foyer Rivière et personne autorisée Sophie Martin. Vérifiez visuellement les images modifiées avant de les versionner.
