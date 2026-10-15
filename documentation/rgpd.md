# Données personnelles (RGPD)

## Objectif

Traitez les demandes d'accès et d'effacement des familles avec les outils de confidentialité natifs de WordPress, en respectant les durées de conservation applicables.

## Avant de commencer

Utilisez un compte administrateur WordPress et vérifiez l'adresse e-mail exacte du foyer. Les familles du plugin n'ont pas de compte WordPress : elles sont retrouvées par leur adresse e-mail.

## Notice affichée aux familles

Une courte notice de confidentialité apparaît au formulaire d'inscription et dans **Mes enfants**. Paramétrez-la dans **Périscolaire › Réglages**, rubrique **Confidentialité** :

- **Responsable du traitement** : le nom de la collectivité. Tant qu'il est vide, les familles lisent « Collectivité (à adapter) » et une alerte le rappelle dans le back office.
- **E-mail du DPO** et **E-mail pour exercer ses droits** : l'adresse affichée aux familles pour exercer leurs droits.
- **Adresse de la notice complète** : ajoute le lien « Lire la notice de confidentialité ».

L'**Aperçu** montre la notice exactement telle que les familles la voient, avec les valeurs enregistrées. Faites valider son contenu par le DPO.

## Étapes

1. Ouvrez **Outils › Exporter les données personnelles**, saisissez l'adresse e-mail du responsable, puis lancez la demande. Le plugin ajoute ses données à l'export natif.
2. Contrôlez l'archive produite : elle contient le profil du foyer, les enfants, la scolarité par année, le planning, les présences et heures pointées, les personnes autorisées et l'historique de leurs modifications, les factures envoyées, les échanges avec la mairie (texte des messages compris), les demandes d'inscription, les messages de la mairie reçus et les consultations de l'espace famille par la mairie. Le signalement alimentaire est restitué (aucun texte libre d'allergie n'est plus conservé depuis la mise à jour 4.20.0) ; l'IBAN reste partiellement masqué. Si l'adresse saisie est celle du **second parent**, l'archive se limite à ses propres coordonnées, aux enfants et à ses demandes : les coordonnées du titulaire et les factures n'y figurent pas.

   ![Outil WordPress « Exporter les données personnelles » avec le champ d'adresse e-mail et le bouton d'envoi de la demande](assets/screenshots/rgpd-export-wp.png)

3. Pour une demande d'effacement, ouvrez **Outils › Effacer les données personnelles**, saisissez la même adresse e-mail et confirmez l'opération.

   !!! warning
       L'effacement est irréversible. L'outil WordPress anonymise le foyer et supprime ses coordonnées et données opérationnelles. Les factures sont conservées lorsqu'une obligation comptable s'applique et gardent une référence technique vers une ligne anonymisée. La suppression manuelle depuis **Périscolaire › Familles** suit exactement la même règle : elle ne détruit aucune facture, et anonymise la fiche au lieu de la supprimer tant qu'il en reste une.

4. Informez la famille du périmètre réellement effacé. Précisez que les factures déjà émises sont conservées, et qu'elles portent toujours l'identité sous laquelle elles ont été établies : une pièce comptable ne se réécrit pas, sans quoi elle perdrait la valeur probante qui justifie précisément sa conservation. Cette identité disparaît avec la facture, au terme de la durée de conservation retenue. Si nécessaire, relisez le texte suggéré par le plugin dans la page WordPress **Réglages › Confidentialité** avant de le publier ; il n'est jamais publié automatiquement. (Ne pas confondre avec la rubrique **Confidentialité** de **Périscolaire › Réglages**, qui paramètre la notice courte, cf. ci-dessus.)
5. Laissez la purge automatique traiter les enfants partis : chaque jour, la fiche d'un enfant qui n'est inscrit ni à l'année active ni à l'année en préparation est purgée sans action humaine, 400 jours après la plus récente de ces deux dates : sa sortie en cours d'année (**Marquer sorti**), ou la fin de la dernière année où il était inscrit. Un enfant non réinscrit est donc traité comme un enfant sorti en fin d'année. Un enfant qui n'a jamais été rattaché à une année (ou dont l'année a été supprimée) est purgé 400 jours après la création de sa fiche.

   Le même jour, un foyer **sans aucun enfant** et sans activité depuis 400 jours (création, dernière connexion, dernière facture, dernier message échangé) est supprimé comme depuis **Périscolaire › Familles** : s'il a des factures, sa fiche est anonymisée et les factures sont conservées telles quelles. Une demande d'inscription restée **en attente** est supprimée au bout de 180 jours.

   Les échanges avec la mairie sont supprimés 395 jours après leur dernier message, **pièces jointes comprises** : le fichier déposé est effacé du disque en même temps que le message, comme lors de la suppression ou de l'effacement d'un foyer.

6. Pour connaître le volume que chaque purge supprimera à son prochain passage, l'administrateur peut produire le rapport de rétention (`Psc_Retention::run()`, en WP-CLI). Ce rapport est une **simulation** : il ne supprime rien. Les purges elles-mêmes tournent chaque jour, chacune sur sa propre tâche planifiée.

## Durées de conservation appliquées

Ces durées sont celles que le plugin applique réellement, chaque jour, sans action humaine. Elles restent à valider par le DPO (voir plus bas).

| Données | Durée | Point de départ | Tâche planifiée |
| --- | --- | --- | --- |
| Demande d'inscription non confirmée | 7 jours | dépôt | Nettoyage des demandes d'inscription |
| Demande d'inscription validée ou refusée | 90 jours | décision | Nettoyage des demandes d'inscription |
| Demande d'inscription restée en attente | 180 jours | dépôt | Nettoyage des demandes d'inscription |
| Fiche enfant (planning, présences, personnes autorisées et historique, allergies, justificatifs d'assurance) | 400 jours | sortie ou fin de la dernière année d'inscription ; création de la fiche pour un enfant sans année | Purge des enfants sortis |
| Foyer sans enfant | 400 jours | dernière activité (connexion, facture, message) | Purge des enfants sortis |
| Échanges avec la mairie, pièces jointes comprises | 395 jours | dernier message | Purge des échanges avec les familles |
| Consultations de l'espace famille par la mairie | 365 jours | début de la consultation | Nettoyage des consultations « en tant que » |
| Journal d'audit | 1 095 / 365 / 180 jours selon le niveau (critique / normal / volumineux) | action | Purge du journal d'audit |
| Factures (versions archivées et PDF compris) | 10 ans révolus (réglable, 10 ans minimum) ; jamais réécrites avant | fin de l'année de facturation | Purge des enfants sortis, si **Périscolaire › Réglages › Confidentialité › Conservation des factures** est cochée (désactivée par défaut). Une fiche anonymisée qui ne porte plus ni facture ni enfant est supprimée en même temps. |

## Qui voit quoi

Dans le backoffice, chaque agent n'accède qu'aux domaines cochés sur son profil. Les allergies ne s'affichent qu'aux personnes habilitées aux **Données de santé**. Le rôle Éditeur de WordPress n'a aucun accès aux dossiers. Voir [Accès des agents de la mairie](installation/acces-agents.md).

Côté familles, chaque foyer ne voit et ne modifie que ses propres données. Des tests automatiques le vérifient à chaque version : une famille qui tente d'atteindre les enfants, documents ou factures d'une autre est refusée.

Les e-mails de notification des échanges annoncent par défaut un nouveau message, sans en recopier le texte. La mairie peut activer la recopie dans **Périscolaire › Réglages** (**Texte des messages dans les e-mails**) : la messagerie de la famille en conserve alors une copie, hors du plugin, que ni les purges ni l'effacement n'atteignent. Le bouton de ces e-mails connecte la famille pendant 72 heures ; le jeton est conservé haché, supprimé à son expiration, quand l'accès du foyer est révoqué (changement d'adresse, retrait ou remplacement du second parent) et avec son dossier.

## Preuve des règlements approuvés

Chaque acceptation du règlement intérieur ou du règlement du prélèvement enregistre la **version** approuvée : le texte exact affiché à la famille et, s'il était en ligne, une copie privée du PDF complémentaire, avec leur empreinte. Une nouvelle version se crée d'elle-même quand le texte (mise à jour de l'extension) ou le PDF (**Réglages › Documents**) change. Les acceptations passées gardent la leur, qui ne peut plus être supprimée. La version figure dans l'export des données d'une famille. Le circuit de signature et d'archivage du mandat SEPA reste à valider par la mairie.

## Ce que le plugin ne décide pas

Les points suivants ne sont tenus par aucun réglage ni aucun test : ils demandent une décision ou un constat, et restent ouverts tant que personne ne les a tranchés et datés.

| Sujet | Qui décide |
| --- | --- |
| Base légale des traitements, et durées de conservation par catégorie | DPO, avec le service d'archives pour le sort final |
| Nécessité d'une analyse d'impact (AIPD), et sa réalisation le cas échéant | DPO |
| Registre des traitements, contrats de sous-traitance, procédure de violation de données | Mairie et DPO |
| Qualification des descriptions d'allergie collectées avant le signalement minimal | DPO |
| Relecture de la notice de confidentialité, coordonnées réelles, information des tiers | DPO |
| Procédure de vérification d'identité du demandeur et délai de réponse | Mairie |
| Liste des agents habilités, en particulier aux données de santé, et sa revue périodique | Mairie |
| Circuit PAI et responsabilités lors de l'échange oral sur l'alimentation | Mairie |
| Qualification comptable des factures PDF et procédure de correction | Service facturation |
| Inaccessibilité réelle des documents, cache, sauvegardes et restauration | Hébergeur, via la [fiche de recette](installation/fiche-recette-p1.md) |

Un relevé des garanties techniques réellement en place peut être produit à tout moment par la personne qui maintient l'instance : il distingue ce qu'un test vérifie de ce qui relève de cette liste.

## Résultat attendu

La demande est traitée par l'outil WordPress, l'export restitue les métadonnées autorisées sans secrets en clair, et l'effacement respecte la règle de conservation des factures configurée par la mairie. La base légale, les durées définitives et la recette de l'hébergement ne sont pas déterminées par le plugin.

## Pour aller plus loin

- [Démarrage rapide](demarrage-rapide.md)
- [Documents (assurance)](gestion-quotidienne/documents-assurance.md)
- [Sauvegarde et mise à jour](installation/sauvegarde-mise-a-jour.md)
