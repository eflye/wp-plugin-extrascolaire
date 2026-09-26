# Services et tarifs

## Objectif

Définissez le prix de chaque [service](../glossaire.md#service) et le délai qui verrouille les modifications du planning des familles.

## Avant de commencer

Connectez-vous avec un rôle autorisé à gérer les réglages. Décidez des montants facturés par la collectivité et du nombre d'heures de préavis nécessaire avant chaque jour de prestation.

## Étapes

1. Ouvrez **Périscolaire › Réglages**, puis repérez **Tarifs des prestations**. Le tableau donne, pour chaque prestation, le **tarif du jour** et l'historique des tarifs, y compris ceux à venir. Les tarifs par défaut sont : **Garderie Matin (GM)** 1,85 €, **Cantine (CANT)** 5,80 €, **Garderie Soir (GS)** 4,70 €, **Forfait journée (FORF)** 11,70 €, **Cantine sans repas (MSR)** 1,00 € et **Forfait sans repas cantine (FSR)** 9,00 €. Le [forfait journée](../glossaire.md#forfait-journee) regroupe la garderie du matin, la cantine et la garderie du soir ; le forfait sans repas est une variante de facturation. Aucun paiement en ligne n'est déclenché par le plugin.
2. Pour changer un prix, remplissez **Nouveau tarif** : choisissez la **Prestation**, saisissez le **Tarif** en euros et la date **À partir du**, puis cliquez sur **Enregistrer le tarif**. Le nouveau prix s'applique à partir de cette date ; les jours précédents gardent l'ancien. La facture d'un mois applique à chaque jour le tarif de ce jour-là : un changement en cours de mois donne deux lignes, la seconde précisée « (à partir du jj/mm) ».

    - Un tarif **à venir** peut être supprimé depuis l'historique. Un tarif déjà entré en vigueur ne peut pas l'être : il a servi à facturer. Pour le corriger, enregistrez un nouveau tarif à la même date.
    - Une date passée corrige les factures **pas encore envoyées** de la période. Une facture déjà envoyée n'est rectifiée qu'à sa prochaine génération, avec archivage de la version remise à la famille.

   ![Écran « Tarifs des prestations » affichant les six lignes de tarifs et leurs montants en euros](../assets/screenshots/services-tarifs.png)

   !!! note "Comment une journée est facturée"
        La même règle sert aux factures, aux estimations du portail, aux récapitulatifs envoyés aux familles, à l'export CSV et aux effectifs du calendrier :

        - une journée est **complète** quand l'enfant a la garderie du matin, le midi (cantine, ou cantine sans repas) et la garderie du soir, quelle que soit la façon dont la famille les a déclarés (forfait ou cases séparées) ;
        - une journée complète est facturée au prix du **forfait journée**, ou du **forfait sans repas** pour un enfant « cantine sans repas », sans jamais dépasser la somme des trois prestations au tarif unitaire ;
        - sinon, chaque prestation de la journée est facturée à son tarif. Un forfait dont la famille retire une prestation, ou dont la mairie ferme une prestation, est donc facturé comme les prestations restantes, sans cumul.

        Avec les tarifs par défaut : forfait 11,70 € ; forfait avec cantine retirée 6,55 € (GM + GS) ; trois prestations cochées séparément 11,70 €. Le forfait sans repas (9,00 €) coûte plus que ses prestations (GM + cantine sans repas + GS = 7,55 €) : c'est alors ce montant qui s'applique. Pour qu'il serve, fixez-le sous la somme de ses prestations.

        Une facture déjà envoyée garde la règle sous laquelle elle a été établie : un changement de règle ne la rectifie pas.

3. Repérez **Délai de modification**, puis renseignez **Préavis minimum** en heures. La valeur par défaut est de 48 heures ; mettez 0 pour désactiver le verrouillage général.
4. Cliquez sur **Enregistrer les réglages**. Au-delà du délai avant le jour concerné, les familles ne peuvent plus modifier leur planning ni utiliser **Annulation / signalement d'absence**. La mairie peut toujours corriger une réservation depuis le backoffice.

   Le délai se compte depuis le début du jour concerné (minuit), en heures réellement écoulées. Le planning des familles affiche l'heure limite exacte (« Modifiable jusqu'au… ») : c'est bien l'instant où le jour se verrouille. Les week-ends de changement d'heure, cette limite peut tomber à 23:00 ou à 01:00 au lieu de minuit.
5. Si nécessaire, ajustez aussi le préavis propre à l'année active depuis la page [Année scolaire](annee-scolaire.md) : ce réglage de planning s'applique alors à cette année.

## Résultat attendu

Les familles voient les tarifs à jour et ne peuvent plus modifier une réservation après le délai défini, tandis que la mairie conserve sa capacité de correction.

## Pour aller plus loin

- [Année scolaire](annee-scolaire.md)
- [Calendrier et vacances](calendrier-vacances.md)
- [Factures PDF](../facturation/factures-pdf.md)
