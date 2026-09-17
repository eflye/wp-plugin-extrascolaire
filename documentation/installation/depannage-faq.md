# Dépannage et FAQ

## Pourquoi l'alerte indique-t-elle que le dossier des documents est inutilisable ?

Le répertoire privé n'existe pas ou n'est pas accessible en écriture. Vérifiez `PSC_PRIVATE_DIR` dans `wp-config.php`, le dossier parent et les droits du processus PHP. Tant que ce point n'est pas corrigé, les justificatifs et les factures ne peuvent ni être enregistrés ni téléchargés.

## Pourquoi les documents des familles semblent-ils accessibles sans connexion ?

L'hébergement expose probablement le répertoire privé à partir d'une URL publique, ou le serveur ignore les garde-fous `.htaccess`/`web.config`. Déplacez `PSC_PRIVATE_DIR` hors de la racine web ou ajoutez une règle équivalente dans la configuration du serveur, puis testez une URL de document en navigation privée.

## Pourquoi l'alerte indique-t-elle que les contraintes de base de données ne sont pas posées ?

L'hébergement a refusé une modification de schéma, ou des données existantes empêchent la pose d'une contrainte. Consultez la liste affichée dans l'alerte, corrigez les données concernées et vérifiez les quotas et le moteur de table. Le plugin retente automatiquement à chaque ouverture du backoffice.

## Pourquoi le journal d'audit signale-t-il un problème ?

Une écriture du journal a échoué ou une action n'est pas classée dans le registre. Cliquez sur **Voir le journal filtré**, vérifiez le fichier de repli `journal-acces.log` et corrigez la cause d'accès à la base ou l'action inconnue avant de remettre le compteur à zéro avec **Remettre le compteur à zéro**.

## Pourquoi les e-mails ne partent-ils pas ?

Vérifiez le relais et le test réel décrits dans [Envoi des e-mails (SMTP)](emails-smtp.md). Sans transport fonctionnel, les liens de connexion, les notifications et les factures ne peuvent pas parvenir aux familles.
