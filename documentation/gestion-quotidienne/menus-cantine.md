# Menus de cantine

## Objectif

Saisissez le menu de chaque jour ouvert, vérifiez son aperçu, puis envoyez-le aux familles.

## Avant de commencer

Connectez-vous avec un rôle autorisé à gérer la cantine. Préparez les plats de la prochaine semaine ouverte et, si besoin, le texte sur l'origine de la viande.

## Étapes

1. Ouvrez **Périscolaire › Menus** et choisissez la semaine dans **Semaine du**. Les jours de vacances ou de fermeture ne sont pas proposés.
2. Renseignez un plat par ligne dans le champ de chaque jour. Pour afficher un label qualité, ajoutez une étoile `*` après le nom du plat pour le label Bio, ou deux étoiles `**` pour le label Label Rouge. Sans étoile, aucun label n'est affiché.
3. Contrôlez l'aperçu qui se met à jour sous chaque champ : les étoiles disparaissent du texte et le logo correspondant apparaît. Ajoutez l'**Origine de la viande** si cette information doit être communiquée.

   ![Formulaire de saisie d'un menu avec un plat par jour, l'aperçu des labels qualité et le champ Origine de la viande](../assets/screenshots/menus-cantine-saisie.png)

4. Cliquez sur **Enregistrer le menu** pour une nouvelle semaine, ou sur **Enregistrer les modifications** pour un menu existant. L'enregistrement reste un brouillon : il n'envoie aucun e-mail.
5. Dans **Menus enregistrés**, vérifiez que les jours sont renseignés et que la colonne **Envoi** indique **Non envoyé**. Cliquez sur **Envoyer aux familles**, puis confirmez l'envoi à toutes les familles actives.
6. Contrôlez la colonne **Envoi**. Le menu n'est marqué envoyé (✔ et date) que si **toutes** les familles l'ont reçu. Si certains e-mails ont échoué, la ligne affiche le bilan du dernier envoi, par exemple « 42/45 accepté(s), 3 échec(s), 0 en attente ».
7. En cas d'échec, vérifiez la messagerie du site, puis cliquez sur **Relancer les échecs**. Seules les familles qui n'ont pas reçu le menu sont relancées : les autres ne le reçoivent pas une seconde fois.
8. Pour corriger un menu déjà enregistré, cliquez sur **Modifier**, enregistrez les modifications, puis utilisez **Renvoyer** si un nouvel e-mail doit partir. **Renvoyer** envoie volontairement une nouvelle fois à toutes les familles ; un double clic, lui, n'envoie rien en double.

   !!! note
       « Accepté » signifie que l'e-mail a été confié au serveur d'envoi, pas qu'il est arrivé dans la boîte de la famille. Les envois restés en attente (coupure de la messagerie) sont repris automatiquement, cf. [Tâches planifiées](../installation/taches-planifiees.md).

## Résultat attendu

Le menu de la semaine est lisible dans le portail famille et l'e-mail, les labels Bio ou Label Rouge sont correctement signalés, et l'envoi n'est daté que lorsqu'il a été accepté pour toutes les familles.

## Pour aller plus loin

- [Calendrier et vacances](../configuration/calendrier-vacances.md)
- [Annulations et absences](annulations-absences.md)
- [Commande fournisseur](commande-fournisseur.md)
- [Tableau de bord](tableau-de-bord.md)
