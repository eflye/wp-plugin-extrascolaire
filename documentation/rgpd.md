# Données personnelles (RGPD)

## Objectif

Traitez les demandes d'accès et d'effacement des familles avec les outils de confidentialité natifs de WordPress, en respectant les durées de conservation applicables.

## Avant de commencer

Utilisez un compte administrateur WordPress et vérifiez l'adresse e-mail exacte du foyer. Les familles du plugin n'ont pas de compte WordPress : elles sont retrouvées par leur adresse e-mail.

## Étapes

1. Ouvrez **Outils › Exporter les données personnelles**, saisissez l'adresse e-mail du responsable, puis lancez la demande. Le plugin ajoute ses données à l'export natif.
2. Contrôlez l'archive produite : elle contient le profil du foyer, les enfants, la scolarité par année, le planning, les personnes autorisées, les factures envoyées et les échanges avec la mairie. Les données sensibles sont protégées dans l'export technique : l'IBAN est partiellement masqué, la présence d'une allergie est signalée sans son détail et le contenu des conversations n'est pas recopié.

   ![Outil WordPress « Exporter les données personnelles » avec le champ d'adresse e-mail et le bouton d'envoi de la demande](assets/screenshots/rgpd-export-wp.png)

3. Pour une demande d'effacement, ouvrez **Outils › Effacer les données personnelles**, saisissez la même adresse e-mail et confirmez l'opération.

   !!! warning
       L'effacement est irréversible. L'outil WordPress anonymise le foyer et supprime ses coordonnées et données opérationnelles. Les factures sont conservées lorsqu'une obligation comptable s'applique et gardent une référence technique vers une ligne anonymisée. Vérifiez le périmètre avec la mairie avant toute suppression manuelle depuis le backoffice.

4. Informez la famille du périmètre réellement effacé et, si nécessaire, relisez le texte suggéré dans **Réglages › Confidentialité** avant de le publier. Ce texte n'est jamais publié automatiquement.
5. Laissez la purge automatique traiter les enfants sortis : chaque jour, les fiches d'enfants marqués **Sorti** depuis plus de 400 jours sont purgées sans action humaine. Cette purge ne supprime pas le foyer ni les factures conservées.

6. Avant d'activer une nouvelle durée, faites exécuter le rapport de rétention en mode **simulation** par l'administrateur (WP-CLI ou outil d'administration qui l'exposera). Les catégories non validées par la mairie/DPO restent bloquées et le rapport indique les volumes examinés, conservés et les erreurs éventuelles. La validation des durées, du circuit PAI, de l'AIPD et des archives reste manuelle.

## Résultat attendu

La demande est traitée par l'outil WordPress, l'export restitue les métadonnées autorisées sans secrets en clair, et l'effacement respecte la règle de conservation des factures configurée par la mairie. La base légale, les durées définitives et la recette de l'hébergement ne sont pas déterminées par le plugin.

## Pour aller plus loin

- [Démarrage rapide](demarrage-rapide.md)
- [Documents (assurance)](gestion-quotidienne/documents-assurance.md)
- [Sauvegarde et mise à jour](installation/sauvegarde-mise-a-jour.md)
