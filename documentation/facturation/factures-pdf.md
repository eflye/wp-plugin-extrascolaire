# Factures PDF

## Objectif

Générez les factures mensuelles, envoyez-les aux familles et exportez les données nécessaires au suivi des règlements.

## Avant de commencer

Connectez-vous avec un rôle autorisé à gérer la facturation. Vérifiez que les inscriptions du mois sont finalisées et que les coordonnées e-mail des familles sont à jour.

## Étapes

1. Ouvrez **Périscolaire › Factures**, puis choisissez le **Mois :** à traiter dans la liste.
2. Cliquez sur **Générer / Regénérer les factures de** suivi du mois choisi.

   !!! note
       Une facture déjà envoyée n'est jamais réécrite en silence. Si son calcul n'a pas changé, la régénération ne la touche pas. S'il a changé (tarif modifié, statut « cantine sans repas »…), la version reçue par la famille est archivée telle quelle, PDF compris, et une nouvelle version repart « à envoyer » sous un numéro distinct (suffixe -R2). Elle est signalée « Rectifiée — version 2 » dans la liste.

   ![Écran Factures avec le choix du mois et le bouton Générer / Regénérer les factures de](../assets/screenshots/factures-generer.png)

3. Dans la liste des factures, utilisez **Télécharger** pour contrôler un PDF, puis **Envoyer** ou **Renvoyer** pour une famille précise. Le bouton **Envoyer toutes les factures non envoyées (…)** traite en une fois les factures qui ne sont pas encore parties.

   Le message qui suit donne le bilan exact : « Toutes les factures ont été envoyées (n) », ou « n facture(s) envoyée(s), m échec(s) ». Une facture en échec reste **Non envoyée**, avec la mention **Échec du dernier envoi**. Une fois la messagerie vérifiée, cliquez de nouveau sur **Envoyer toutes les factures non envoyées** : seules les factures en échec repartent. Un double clic n'envoie jamais une facture deux fois.

4. Ouvrez **Exports par mois**, choisissez le **Mois à exporter**, puis cliquez sur **Choisir** si nécessaire. Utilisez **Export général (.csv)** pour le suivi complet, ou **Export prélèvements (SEPA, .csv/.ods)** pour les familles en prélèvement.

   ![Bloc « Exports par mois » avec les boutons Export général et Export prélèvements aux formats CSV et ODS](../assets/screenshots/factures-exports.png)

5. Pour supprimer toutes les factures du mois sélectionné, cliquez sur **Supprimer les factures du mois** et confirmez.

   !!! warning
       Cette suppression est irréversible : les PDF sont effacés, y compris ceux déjà envoyés aux familles.

## Résultat attendu

Chaque famille dispose d'une facture PDF correspondant au mois choisi, l'état d'envoi est visible et les exports mensuels sont disponibles dans le format adapté.

## Pour aller plus loin

- [Mandats SEPA](mandats-sepa.md)
- [Services et tarifs](../configuration/services-tarifs.md)
- [Démarrage rapide](../demarrage-rapide.md)
