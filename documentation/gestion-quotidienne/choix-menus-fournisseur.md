# Choix des menus du fournisseur

## Objectif

Répondez au fournisseur de repas, qui propose plusieurs plats par jour pour les semaines à venir, sans imprimer ni ressaisir son fichier : vous choisissez les plats à l'écran, la réponse part avec son fichier rempli, et les menus de cantine se remplissent d'eux-mêmes.

## Avant de commencer

Connectez-vous avec un rôle autorisé à gérer la cantine. Enregistrez sur votre poste le fichier Excel (`.xlsx`) reçu du fournisseur, sans l'ouvrir ni le convertir. Vérifiez dans **Périscolaire › Commande fournisseur**, onglet **Réglages**, l'**Adresse du fournisseur** et le **Nom de l'établissement** : ce nom est inscrit en face d'« Etablissement » sur chaque feuille du fichier renvoyé.

## Étapes

1. Ouvrez **Périscolaire › Menus**, onglet **Choix fournisseur**. Sous **Déposer le fichier du fournisseur**, choisissez le fichier reçu puis cliquez sur **Déposer le fichier**. Rien n'est envoyé à cette étape.
2. La page affiche une semaine par feuille du fichier. Pour chaque jour de cantine, retenez un plat par catégorie : **Entrée**, **Plat**, **Accompagnement**, **Fromage / Laitage**, **Dessert**. La grille reprend la présentation du fichier du fournisseur : une couleur par jour, un bandeau par catégorie, une case par plat. Cliquez n'importe où dans la case d'un plat pour le retenir ; la case retenue est encadrée. La suggestion de la diététicienne, en gras souligné, est cochée d'avance. Les plats BIO ont un fond vert, le plat végétarien du jour un fond vert pâle et la mention *végétarien*, les substitutions permanentes du fournisseur la mention *option*.
3. Cochez **Aucun** pour ne rien commander dans une catégorie, par exemple l'accompagnement d'un plat complet.

    !!! note
        Les mercredis, les vacances scolaires, les jours fériés et les fermetures enregistrées dans le calendrier ne sont pas proposés : leur colonne reste grisée, avec la mention « Pas de cantine », et une semaine entièrement fermée n'appelle aucun choix. Si une semaine devrait être ouverte, corrigez d'abord le [calendrier](../configuration/calendrier-vacances.md).

4. Cliquez sur **Enregistrer les choix** pour reprendre plus tard, ou sur **Enregistrer et vérifier avant l'envoi** quand tout est choisi.
5. Sur la page de vérification, relisez le destinataire, le nombre de plats cochés et le tableau de chaque semaine. Cliquez sur **Télécharger le fichier rempli pour le relire** si vous voulez voir le fichier tel que le fournisseur le recevra : une croix figure dans la colonne « Enf » en face de chaque plat retenu.
6. Sous chaque semaine, une phrase indique ce qu'il adviendra du menu : créé, remplacé s'il existait sans avoir été envoyé, ou laissé tel quel s'il a déjà été envoyé aux familles.
7. Cliquez sur **Envoyer la réponse au fournisseur**. Le fournisseur reçoit un e-mail avec son fichier rempli en pièce jointe. Pour corriger un choix avant l'envoi, cliquez sur **Modifier les choix**.
8. Une fois la réponse partie, les menus des semaines choisies sont remplis : chaque plat est rangé dans son type (entrée, plat, accompagnement, fromage / laitage, dessert), avec le logo Bio pour les plats que le fournisseur signale « BIO ». Ils restent des brouillons. Si un menu identique existait déjà sans type, ses types sont ajoutés sans rien changer à son texte. Ouvrez l'onglet **Menus de la semaine** pour les relire, les compléter et les [envoyer aux familles](menus-cantine.md).
9. Si un menu avait déjà été envoyé aux familles pour une des semaines, il n'est pas modifié. Pour le remplacer malgré tout, cliquez sur **Remplacer le menu du…** sous la semaine concernée, puis renvoyez-le aux familles.

    !!! warning
        Après l'envoi, les choix ne sont plus modifiables. Pour changer un plat, prévenez le fournisseur directement et corrigez le menu de la semaine à la main.

    !!! note
        Si l'envoi échoue, vos choix sont conservés et aucun menu n'est rempli. Vérifiez la messagerie du site, puis cliquez sur **Relancer l'envoi**.

## Résultat attendu

Le fournisseur a reçu son fichier rempli, la ligne du fichier indique **Réponse envoyée, menus remplis** dans **Fichiers reçus**, et les menus des semaines choisies sont prêts à être relus puis envoyés aux familles.

## Pour aller plus loin

- [Menus de cantine](menus-cantine.md)
- [Notes de changement du fournisseur](notes-changement-fournisseur.md) : les remplacements annoncés après coup par le fournisseur
- [Commande fournisseur](commande-fournisseur.md)
- [Modèles d'e-mails](../configuration/modeles-emails.md) : le texte de la réponse est le modèle **Réponse au fournisseur (choix des menus)**
- [Calendrier et vacances](../configuration/calendrier-vacances.md)
