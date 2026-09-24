# Documents (assurance)

## Objectif

Suivez le dépôt et la validation du justificatif d'assurance scolaire de chaque enfant actif.

## Avant de commencer

Connectez-vous à WordPress avec un rôle autorisé à gérer les familles. Demandez à la famille un fichier lisible au format accepté avant de vérifier son dossier.

## Étapes

1. La famille dépose ou remplace le justificatif depuis **Mes enfants** dans son portail. Le document est conservé dans l'espace privé du plugin et rattaché à l'année scolaire active.

   Le fichier est vérifié sur son **contenu**, pas seulement sur son nom : ce doit être un vrai PDF, JPEG ou PNG, complet et de 1 Mo au plus. Un fichier renommé « .pdf », tronqué ou illisible est refusé avec le message « Format de fichier non accepté (PDF, JPG ou PNG uniquement) », tout comme un PDF qui contient du JavaScript ou des fichiers cachés. Un dépôt refusé ne remplace jamais le justificatif précédent. Si une attestation d'assureur est refusée à tort, demandez à la famille de la réenregistrer en PDF depuis son logiciel, ou de la photographier.
2. Ouvrez **Périscolaire › Assurances scolaires**. Le tableau liste chaque enfant actif, l'état du document, la date du dépôt et l'action de revue.

   ![Liste des assurances scolaires affichant l'enfant, l'état du document, la date du dépôt et le bouton Examiner](../assets/screenshots/documents-assurance-liste.png)

3. Dans **Réglages**, choisissez le mode adapté à votre organisation : **Acceptation automatique après dépôt** valide immédiatement un fichier transmis ; **Revue par la mairie avant accès au planning** vous laisse examiner chaque document avant d'autoriser le planning.
4. En mode manuel, cliquez sur **Examiner** pour l'enfant concerné, puis sur **Ouvrir le document dans un nouvel onglet**. Ajoutez un **Message à la famille** si le document doit être remplacé.
5. Cliquez sur **Valider l’assurance** si le justificatif est conforme. S'il ne l'est pas, cliquez sur **Refuser — demander un remplacement** et indiquez obligatoirement le motif à transmettre à la famille.
6. Contrôlez ensuite l'état affiché dans la liste. Un justificatif manquant ou refusé bloque le planning pour l'enfant concerné jusqu'à la réception et l'acceptation d'un nouveau document.

## Résultat attendu

Chaque enfant actif possède un justificatif identifiable et accepté pour l'année en cours, ou la famille sait précisément quel document remplacer avant de pouvoir déclarer son planning.

## Pour aller plus loin

- [Modération des inscriptions](moderation-inscriptions.md)
- [Régimes alimentaires](../configuration/regimes-alimentaires.md)
- [Année scolaire](../configuration/annee-scolaire.md)
