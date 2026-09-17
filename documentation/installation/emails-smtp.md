# Envoi des e-mails (SMTP)

## Objectif

Assurez la délivrabilité des e-mails du plugin avant d'ouvrir le service aux familles.

## Avant de commencer

Vous devez pouvoir configurer WordPress ou demander cette intervention à votre hébergeur. Préparez une adresse d'expédition autorisée par le domaine du site et une boîte de réception de test.

## Étapes

1. Retenez que le plugin utilise `wp_mail()`. Sur beaucoup d'hébergements mutualisés, cette fonction s'appuie sur `mail()` de PHP, ce qui peut envoyer les messages en indésirables ou les bloquer.
2. Configurez un relais SMTP fourni par votre hébergeur, ou installez un plugin SMTP tiers maintenu par votre équipe. Le plugin Périscolaire n'impose aucun fournisseur particulier.
3. Vérifiez que le nom de domaine, l'adresse d'expédition et les mécanismes d'authentification demandés par le relais sont correctement configurés (DNS, chiffrement et identifiants selon les règles de l'hébergeur).
4. Réalisez un envoi de test réel depuis WordPress, puis vérifiez la réception, les en-têtes et les liens dans la boîte cible. Testez notamment un lien de connexion famille sans mot de passe avant toute ouverture publique.
5. Après chaque changement d'hébergement ou de relais, refaites ce test et surveillez les journaux d'erreur d'envoi.

   !!! warning
       Sans e-mail fonctionnel, aucune famille ne peut se connecter : le lien d'accès est transmis uniquement par e-mail. N'ouvrez pas le service tant qu'un test réel n'a pas abouti.

## Résultat attendu

Les messages de connexion, de notification, de menu et de facturation arrivent dans la boîte de test et leurs liens fonctionnent avant la mise en service.

## Pour aller plus loin

- [Prérequis](prerequis.md)
- [Installation et activation](installation-activation.md)
- [Tâches planifiées](taches-planifiees.md)
