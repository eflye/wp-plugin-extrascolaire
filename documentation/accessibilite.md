# Accessibilité

## Objectif

Savoir ce qui a été vérifié sur l'accessibilité du portail des familles, ce qui a été corrigé, et ce qui reste à contrôler par une personne.

## Ce qui est vérifié automatiquement à chaque modification

La suite `tests/a11y-parcours.spec.ts` tourne dans l'intégration continue. Une régression bloque donc la publication :

- **Contrôle automatique axe (WCAG 2.1 niveaux A et AA)** : l'accueil visiteur (connexion, demande d'inscription) et chacun des écrans du portail (tableau de bord, planning, menus, messages, enfants, personnes autorisées, factures, profil, documents, réinscription) doivent passer sans aucune violation.
- **Zoom à 400 %** : chaque page est affichée sur 320 pixels de large, et aucune ne doit défiler horizontalement (critère 1.4.10).
- **Clavier seul** : corriger le planning d'un enfant en n'utilisant que le clavier. On atteint l'onglet de l'enfant et une case avec Tab, on les active avec Entrée et Espace, puis l'écriture est vérifiée en base. On demande aussi un lien de connexion au clavier.
- **Écrans de la mairie** : les écrans d'envoi (menus, factures, commande fournisseur) et les réglages ont leur propre contrôle axe.

## Contrôle du 24/09/2026 : corrections apportées

| Défaut relevé | Écrans | Correction |
|---|---|---|
| Texte secondaire gris à 3,3–3,8:1 | presque tous | teinte assombrie, même couleur : au moins 4,9:1 sur tous les fonds |
| Orange utilisé comme texte (2,6:1) | accueil, planning | variante « encre » de l'orange (au moins 4,6:1) |
| Libellés de la barre latérale (3,0–3,4:1) | tout le portail | éclaircis, au moins 4,5:1 sur le fond bleu |
| Slogan du site (4,1:1 en 10 px) | en-tête du thème | orange éclairci, au moins 4,5:1 |
| Champs sans libellé associé | ajout d'un enfant, profil | vrais libellés reliés à leur champ (17 champs) |
| Lien vide « revenir au rythme » | planning | masqué tant qu'il n'y a rien à réinitialiser |

## Ce qui reste à contrôler par une personne

Les outils automatiques ne détectent qu'une partie des défauts. À faire par le référent accessibilité de la commune, ou par un prestataire :

1. **Lecteur d'écran** : dérouler l'inscription et la correction du planning avec NVDA (Windows) et VoiceOver (iPhone ou Mac). Vérifier que l'état de chaque case (déclarée, retirée, verrouillée) et les messages d'erreur sont bien annoncés.
2. **Parcours d'inscription complet au clavier** : l'assistant en plusieurs étapes, jusqu'à l'envoi de la demande, pièces jointes comprises.
3. **Mobile réel** : l'inscription et le planning sur un téléphone, avec un grand corps de texte.
4. **Obligations de la commune** : confirmer avec son responsable le niveau d'obligation, puis établir la déclaration d'accessibilité du site, qui est à la charge de la commune.

## Pour aller plus loin

- [Données personnelles (RGPD)](rgpd.md)
