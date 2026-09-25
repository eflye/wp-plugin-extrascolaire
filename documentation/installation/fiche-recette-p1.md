# Fiche de recette de l'hébergement

## Objectif

Constater, sur le serveur qui héberge réellement le service, ce que le plugin ne peut pas vérifier depuis son propre code. Cette fiche se remplit une fois à la mise en service, puis après tout changement d'hébergement, de version PHP ou de politique de sauvegarde.

## Avant de commencer

Chaque ligne part du statut **À vérifier**. Une ligne ne passe à **Vérifié** que si quelqu'un a constaté le résultat attendu et daté ce constat : ni la présence d'une fonctionnalité dans le plugin, ni une réponse de l'hébergeur par e-mail ne suffisent. Les lignes qui ne peuvent pas être vérifiées restent ouvertes et sont arbitrées avec le DPO et la mairie.

Renseignez en tête la date, la personne qui a conduit la recette et la version du plugin recettée.

## Socle technique

| Contrôle | Résultat attendu | Comment le constater | Qui | Statut |
| --- | --- | --- | --- | --- |
| Version de PHP | Version supportée par le plugin et encore maintenue en amont | **Outils › Santé du site › Infos** | Administrateur | À vérifier |
| Version de WordPress | Version supportée et à jour de ses correctifs de sécurité | **Tableau de bord › Mises à jour** | Administrateur | À vérifier |
| Base de données | Version du moteur, jeu de caractères, contraintes étrangères réellement créées | **Outils › Santé du site**, et absence de l'alerte du plugin sur les contraintes | Hébergeur | À vérifier |
| Extension de chiffrement | `sodium` ou OpenSSL disponible | Enregistrer un IBAN de test : l'enregistrement doit réussir, et échouer explicitement si la primitive manque | Administrateur | À vérifier |

## Transport et sessions

| Contrôle | Résultat attendu | Comment le constater | Qui | Statut |
| --- | --- | --- | --- | --- |
| TLS | Le site n'est joignable qu'en HTTPS, certificat valide et renouvelé automatiquement | Ouvrir l'URL en `http://` : redirection vers `https://` | Hébergeur | À vérifier |
| Cookies de session | Cookie de session famille marqué `Secure` et `HttpOnly` | Outils de développement du navigateur, onglet Application | Développement | À vérifier |
| Cache partagé | Deux familles connectées simultanément ne voient jamais la page de l'autre, et un visiteur anonyme ne reçoit aucune page de portail | Test à trois navigateurs derrière le cache réellement utilisé, puis purge du cache et nouvel essai | Hébergeur | À vérifier |

## Stockage des documents

| Contrôle | Résultat attendu | Comment le constater | Qui | Statut |
| --- | --- | --- | --- | --- |
| Emplacement du dossier privé | Dossier hors de la racine web, ou règle serveur explicite si c'est impossible | Voir [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md) pour déterminer le chemin réellement utilisé | Hébergeur | À vérifier |
| Inaccessibilité HTTP | Le fichier témoin `psc-probe.txt` n'est pas téléchargeable sans connexion | Ouvrir son URL dans une fenêtre de navigation privée, depuis une connexion extérieure au serveur : la réponse doit être un refus, pas le contenu du fichier | Hébergeur | À vérifier |
| Emplacements historiques | Aucun ancien dossier de documents ne reste servi après un déplacement | Même test sur les chemins utilisés par les versions précédentes | Hébergeur | À vérifier |
| Droits fichiers | Le serveur web peut écrire dans le dossier privé, et lui seul | Absence de l'alerte « dossier des documents inutilisable », puis contrôle des droits | Hébergeur | À vérifier |

!!! warning
    Ce contrôle est le seul qui atteste l'inaccessibilité des justificatifs d'assurance et des factures. Il se fait depuis l'extérieur du serveur : une vérification lancée depuis la machine elle-même ne prouve rien, et le plugin ne peut pas le faire à votre place.

## E-mails et tâches planifiées

| Contrôle | Résultat attendu | Comment le constater | Qui | Statut |
| --- | --- | --- | --- | --- |
| Envoi SMTP | Les liens de connexion et les factures arrivent réellement | Envoyer un lien de connexion à une adresse de test, vérifier la réception et l'absence de classement en indésirable | Administrateur | À vérifier |
| Expéditeur | Adresse d'expédition alignée sur le domaine, enregistrements d'authentification en place | Contrôle auprès de l'hébergeur de messagerie | Hébergeur | À vérifier |
| Exécution du cron | Les tâches quotidiennes s'exécutent même sans visite du site | Comparer la date de dernière exécution à celle attendue, sur deux jours consécutifs | Administrateur | À vérifier |
| Surveillance des échecs | Un échec de tâche ou d'envoi est visible par quelqu'un | Désigner la personne prévenue et le moyen de l'alerter | Mairie | À vérifier |

## Sauvegardes et restauration

| Contrôle | Résultat attendu | Comment le constater | Qui | Statut |
| --- | --- | --- | --- | --- |
| Périmètre | Base, dossier privé et clé de chiffrement sont sauvegardés | Inventaire des éléments réellement inclus, cf. [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md) | Hébergeur | À vérifier |
| Séparation de la clé | La clé est hors de la base (`wp-config.php` ou variable du conteneur), jamais stockée avec le dump de base | **Périscolaire › Maintenance**, étape 3 **Fait** (ou `wp psc chiffrement statut`) et aucune valeur à une ancienne clé (sinon : [Clé de chiffrement des IBAN](cle-chiffrement.md)) ; contrôle des emplacements et des accès | Hébergeur | À vérifier |
| Restauration | Un dossier de test se retrouve entier après restauration isolée : justificatif lisible, facture téléchargeable, IBAN déchiffrable | Restauration sur un environnement séparé, envoi d'e-mails neutralisé au préalable | Hébergeur | À vérifier |
| Objectifs | Perte de données maximale acceptée et délai de reprise arrêtés par écrit | Décision de la mairie, consignée | Mairie | À vérifier |

## Résultat attendu

Une fiche datée, nominative, dont chaque ligne porte un constat réel. Les lignes restées **À vérifier** sont les risques résiduels connus du service : elles se reportent dans le dossier de conformité plutôt que de se perdre.

Cette fiche ne conclut pas à une conformité au RGPD. Elle documente des vérifications techniques ; la base légale, les durées de conservation et l'analyse d'impact relèvent du DPO et de la mairie.

## Pour aller plus loin

- [Prérequis](prerequis.md)
- [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md)
- [Dépannage et FAQ](depannage-faq.md)
- [Données personnelles (RGPD)](../rgpd.md)
