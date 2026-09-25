# Dépannage et FAQ

## Pourquoi l'alerte indique-t-elle que le dossier des documents est inutilisable ?

Le répertoire privé n'existe pas ou n'est pas accessible en écriture. Vérifiez `PSC_PRIVATE_DIR` dans `wp-config.php`, le dossier parent et les droits du processus PHP. Tant que ce point n'est pas corrigé, les justificatifs et les factures ne peuvent ni être enregistrés ni téléchargés.

## Pourquoi les documents des familles semblent-ils accessibles sans connexion ?

L'hébergement expose probablement le répertoire privé à partir d'une URL publique, ou le serveur ignore les garde-fous `.htaccess`/`web.config`. Déplacez `PSC_PRIVATE_DIR` hors de la racine web ou ajoutez une règle équivalente dans la configuration du serveur, puis testez une URL de document en navigation privée.

## L'alerte indique que la mise à jour de la base de données est incomplète

Une étape de la mise à jour du schéma a rencontré une erreur SQL. Les étapes précédentes sont conservées, et la version n'avance pas au-delà de la dernière étape réussie. Une nouvelle tentative reprend à l'étape indiquée à chaque ouverture du backoffice. L'alerte donne l'étape, la nature de la requête et la table, sans aucune donnée personnelle. Si elle persiste, vérifiez avec l'hébergeur les droits de modification des tables (`ALTER`, `CREATE`), le quota et les délais d'exécution. Le **Journal d'audit** trace l'arrêt (« systeme.montee_de_version_echec ») puis la réussite (« systeme.montee_de_version »).

## L'alerte indique que des documents n'ont pas pu être déplacés

Après un changement de `PSC_PRIVATE_DIR`, ou lors de la sortie de l'ancien dossier `uploads/periscolaire`, certains fichiers sont restés à l'ancien emplacement. Aucun fichier n'est supprimé, mais ces documents ne peuvent pas être téléchargés tant qu'ils ne sont pas au nouvel emplacement. Deux causes possibles :

- **Même nom, contenu différent aux deux endroits :** comparez les deux fichiers, conservez le bon au nouvel emplacement et supprimez l'autre copie.
- **Droits insuffisants :** le processus PHP doit pouvoir modifier les deux dossiers.

Rechargez ensuite une page du backoffice : le déplacement reprend et l'alerte disparaît dès qu'il est complet. Tant que des fichiers restent dans `uploads/periscolaire`, un fichier `.htaccess` y interdit l'accès direct.

## Pourquoi l'alerte indique-t-elle que les contraintes de base de données ne sont pas posées ?

L'hébergement a refusé une modification de schéma, ou des données existantes empêchent la pose d'une contrainte. Consultez la liste affichée dans l'alerte, corrigez les données concernées et vérifiez les quotas et le moteur de table. Le plugin retente automatiquement à chaque ouverture du backoffice.

## L'alerte signale « Unicité de l'e-mail du second parent » : que faire ?

Plusieurs foyers partagent la même adresse de second parent. Or cette adresse sert au second parent à se connecter, elle doit donc désigner un seul foyer. Ouvrez **Périscolaire › Familles**, retrouvez les foyers concernés et corrigez ou retirez l'adresse en double. L'alerte disparaît d'elle-même à l'ouverture suivante du backoffice.

## Un agent ne voit plus le menu Périscolaire, ou seulement une partie

Depuis la version 5.20.0, l'accès se donne par habilitations sur le profil de chaque agent, et le rôle Éditeur n'a plus aucun accès. Cochez les habilitations de l'agent, cf. [Accès des agents de la mairie](acces-agents.md). Le tableau de bord reste réservé aux administrateurs.

## Des allergies affichent « Accès restreint »

L'agent n'a pas l'habilitation **Données de santé**. C'est voulu : accordez-la uniquement aux personnes qui en ont besoin pour la sécurité des enfants.

## Un menu, une facture ou une commande fournisseur est indiqué « en échec »

L'e-mail n'a pas été accepté par le serveur d'envoi. Vérifiez d'abord la messagerie ([Envoi des e-mails (SMTP)](emails-smtp.md)), puis relancez depuis l'écran concerné : **Relancer les échecs** pour un menu, **Envoyer toutes les factures non envoyées** pour les factures, **Relancer l'envoi** pour une commande fournisseur. Seuls les envois en échec repartent, sans doublon.

## Une famille ne parvient pas à déposer son attestation d'assurance

Le fichier est vérifié sur son contenu : il doit s'agir d'un vrai PDF, JPEG ou PNG, complet, de 1 Mo au plus. Un fichier renommé, abîmé, trop lourd, ou un PDF contenant du JavaScript est refusé. Demandez à la famille de réenregistrer le document en PDF, ou de le photographier.

## L'adresse du calendrier scolaire n'est pas enregistrée

Elle doit être une adresse web publique (`http` ou `https`). Une adresse qui désigne le serveur lui-même ou un réseau interne est refusée, et l'adresse précédente est conservée. Laissez le champ vide pour utiliser l'adresse officielle du ministère.

## Le passage d'année affiche « rien n'a été modifié »

Une écriture a échoué et le passage a été entièrement annulé : aucun enfant n'est à moitié promu. Réglez la cause (base de données, hébergement), puis confirmez de nouveau depuis le récapitulatif, qui est resté disponible.

## Les familles lisent « Collectivité (à adapter) »

Le responsable du traitement n'est pas renseigné. Ouvrez **Périscolaire › Réglages**, rubrique **Confidentialité**, et remplissez au moins **Responsable du traitement**. Contrôlez l'**Aperçu** avant d'enregistrer.

## Pourquoi certaines factures ne sont-elles pas supprimées ?

Une facture déjà envoyée à une famille, ou rectifiée après envoi, ne se supprime pas : seules les factures jamais envoyées le sont. Pour un environnement de test, voir le mode debug décrit dans [Factures PDF](../facturation/factures-pdf.md).

## Une alerte rouge signale le « mode debug de la facturation »

L'option WP-CLI `psc_invoice_debug_delete` est active : les factures envoyées peuvent être supprimées. Sur un site de production, désactivez-la avec `wp option delete psc_invoice_debug_delete`.

## Pourquoi le journal d'audit signale-t-il un problème ?

Une écriture du journal a échoué ou une action n'est pas classée dans le registre. Cliquez sur **Voir le journal filtré**, vérifiez le fichier de repli `journal-acces.log` et corrigez la cause d'accès à la base ou l'action inconnue avant de remettre le compteur à zéro avec **Remettre le compteur à zéro**.

## Pourquoi les e-mails ne partent-ils pas ?

Vérifiez le relais et le test réel décrits dans [Envoi des e-mails (SMTP)](emails-smtp.md). Sans transport fonctionnel, les liens de connexion, les notifications et les factures ne peuvent pas parvenir aux familles.
