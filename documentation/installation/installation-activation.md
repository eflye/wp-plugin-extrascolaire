# Installation et activation

## Objectif

Installez le plugin sur WordPress et activez ses tables, capacités et tâches initiales.

## Avant de commencer

Vérifiez les [prérequis](prerequis.md), disposez d'un accès administrateur WordPress et sauvegardez la base avant une première activation sur un site existant.

## Étapes

1. Téléchargez le fichier ZIP de la Release GitHub, ou copiez le dossier du plugin dans `wp-content/plugins/` sur le serveur.
2. Dans WordPress, ouvrez **Extensions**, repérez **Périscolaire — Inscriptions**, puis cliquez sur **Activer**.

   ![Liste WordPress des extensions avec Périscolaire — Inscriptions et le lien Activer](../assets/screenshots/plugins-activation.png)

3. À l'activation, le plugin crée ou met à jour ses tables, accorde les capacités de gestion aux rôles administrateur et éditeur, et programme ses tâches automatiques. Consultez [Tâches planifiées](taches-planifiees.md) pour le suivi de ces tâches.
4. Pour donner l'accès à un agent sans lui confier les droits d'administrateur, remplacez la capacité de gestion par `psc_manage_periscolaire` via le filtre `psc_manage_capability` dans le `functions.php` du thème ou une extension dédiée, puis attribuez cette capacité au rôle voulu avec votre gestionnaire de rôles.
5. Ouvrez **Périscolaire** pour vérifier que le menu et le tableau de bord sont accessibles, puis configurez l'année scolaire et les réglages avant l'ouverture aux familles.

## Résultat attendu

L'extension est active, ses tables et capacités sont disponibles, les tâches sont planifiées et le rôle métier choisi peut accéder au backoffice sans privilèges excessifs.

## Pour aller plus loin

- [Prérequis](prerequis.md)
- [Envoi des e-mails (SMTP)](emails-smtp.md)
- [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md)
