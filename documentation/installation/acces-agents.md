# Accès des agents de la mairie

## Objectif

Donnez à chaque agent l'accès aux seuls écrans périscolaires dont il a besoin, sans droits d'administration du site.

## Principe

Le plugin ne crée pas de rôle WordPress dédié. Les accès se donnent **personne par personne**, sous forme d'**habilitations** cochées sur le profil WordPress de l'agent. Elles se cumulent :

| Habilitation | Ce qu'elle ouvre |
|---|---|
| Familles, enfants et assurances | familles, enfants, demandes d'inscription, assurances, personnes autorisées |
| Planning, menus et présences | menus, commande fournisseur, présences déclarées |
| Facturation et prélèvements | factures, état des comptes, exports de prélèvement |
| Messages aux familles | messages et échanges avec les familles |
| Configuration du service | année scolaire, calendrier, modèles d'e-mails, réglages, habilitations des autres agents |
| Données de santé (allergies) | affichage des allergies dans les écrans où elles figurent |
| Journal d'audit | journal des actions et des consultations |

Chaque écran, et chaque action, vérifie côté serveur l'habilitation de son domaine. Un agent qui n'a que la facturation ne voit que la facturation.

## Étapes

1. Ouvrez **Utilisateurs** et créez le compte de l'agent, ou ouvrez son compte existant. Donnez-lui le rôle **Abonné** : c'est le rôle qui accorde le moins de droits sur le reste du site. Il ne pourra ni modifier des pages, ni toucher aux réglages de WordPress.
2. En bas de son profil, dans **Habilitations périscolaires**, cochez les domaines dont il a besoin, puis enregistrez. Cette rubrique n'est visible que par une personne habilitée à la configuration du service (un administrateur, par défaut).
3. À sa prochaine connexion, l'agent voit le menu **Périscolaire** avec les seuls écrans correspondants. Il arrive directement sur son premier écran autorisé.

!!! warning "Rôle Éditeur"
    Depuis la version 5.20.0, le rôle **Éditeur** n'a plus aucun accès au périscolaire : modifier les pages du site ne donne aucun droit sur les dossiers d'enfants. Un agent qui travaillait avec un compte Éditeur doit recevoir ses habilitations sur son profil. Son rôle n'a pas besoin de changer.

## Points d'attention

- **Données de santé** : les allergies ne s'affichent qu'avec l'habilitation **Données de santé**, même pour une personne qui gère les dossiers des familles. Sans elle, la mention « Accès restreint » apparaît pour chaque enfant, sans révéler lesquels sont concernés. Ne l'accordez qu'aux agents qui en ont besoin pour la sécurité des enfants.
- **Réservé aux administrateurs** : le tableau de bord, qui réunit tous les domaines, et la consultation de l'espace d'une famille.
- **Revue périodique** : relisez la liste des agents habilités au moins une fois par an, et à chaque départ ou changement de poste. Retirez les habilitations devenues inutiles.

## Résultat attendu

Chaque agent n'accède qu'aux écrans de sa mission. Les données de santé ne sont visibles que des personnes explicitement habilitées.

## Pour aller plus loin

- [Installation et activation](installation-activation.md)
- [Données personnelles (RGPD)](../rgpd.md)
