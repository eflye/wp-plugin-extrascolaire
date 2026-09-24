# Prérequis

## Objectif

Vérifiez l'environnement WordPress et PHP avant d'installer le plugin sur un site d'hébergement.

## Avant de commencer

Vous devez pouvoir administrer le site WordPress, installer une extension et configurer son hébergement HTTPS. Préparez également un transport d'e-mails fiable pour les liens de connexion des familles.

## Étapes

1. Vérifiez que WordPress est en version **5.8** ou ultérieure. Le plugin déclare `Requires at least: 5.8`.
2. Vérifiez que PHP est en version **7.4** ou ultérieure, conformément à `Requires PHP: 7.4` et à la contrainte du projet. C'est un minimum de compatibilité : en production, utilisez une version de PHP encore maintenue (8.2 ou 8.3), cf. [Versions et dépendances](versions-dependances.md).
3. Activez une primitive de chiffrement PHP : **libsodium** (`sodium_crypto_secretbox`) est utilisée en priorité ; **OpenSSL** (`openssl_encrypt` en AES-256-GCM) constitue le repli accepté. Au moins l'une des deux extensions doit être disponible pour enregistrer les IBAN ; sinon le plugin refuse l'enregistrement plutôt que de stocker une donnée bancaire en clair.
4. Servez le site exclusivement en HTTPS. Les liens de connexion sans mot de passe et les données personnelles transitent par le navigateur ; un certificat TLS valide est donc obligatoire avant l'ouverture aux familles.
5. Vérifiez les extensions complémentaires nécessaires aux fonctions que vous activerez : **ZipArchive** pour les exports ODS et **DOM/libxml** pour l'export bancaire `pain.008`. Elles ne sont pas requises pour afficher les écrans de base, mais les exports correspondants échoueront sans elles.
6. Configurez un transport d'e-mails fiable (SMTP ou équivalent chez l'hébergeur) et testez-le avant toute mise en service. Le plugin s'appuie sur `wp_mail()` ; la page [Envoi des e-mails (SMTP)](emails-smtp.md) détaille ce point.

## Résultat attendu

Le serveur respecte les versions minimales, dispose de libsodium ou OpenSSL, sert WordPress en HTTPS et possède les extensions nécessaires aux exports prévus.

## Pour aller plus loin

- [Installation et activation](installation-activation.md)
- [Versions et dépendances](versions-dependances.md)
- [Envoi des e-mails (SMTP)](emails-smtp.md)
- [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md)
