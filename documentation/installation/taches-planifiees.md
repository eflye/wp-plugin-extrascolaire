# Tâches planifiées

## Objectif

Surveillez les tâches automatiques du plugin et vérifiez que WP-Cron les exécute régulièrement.

## Fonctionnement de WP-Cron

WP-Cron se déclenche lors des visites du site et non à une heure fixe. Sur un site peu fréquenté, configurez un vrai cron système qui appelle `wp-cron.php` afin de ne pas retarder les purges et les envois.

## Tâches récurrentes

| Tâche | Fréquence | Rôle |
|---|---|---|
| `psc_purge_audit_log` | quotidienne | purge le journal d'audit selon la durée de rétention par catégorie |
| `psc_purge_conversations` | quotidienne | purge les échanges anciens |
| `psc_cleanup_impersonations` | quotidienne | referme les consultations d'espace famille expirées |
| `psc_send_scheduled_messages` | horaire | envoie les messages programmés arrivés à échéance |
| `psc_cleanup_message_receipts` | quotidienne | nettoie les preuves de lecture anciennes |
| `psc_purge_departed_children` | quotidienne | anonymise les enfants sortis depuis plus de 400 jours (RGPD) |
| `psc_cleanup_requests` | quotidienne | supprime les demandes d'inscription non confirmées (7 jours) ou traitées (90 jours) |
| `psc_envois_reprise` | toutes les 15 minutes | reprend les envois de menus, factures et commandes fournisseur restés en attente depuis plus de 10 minutes (coupure de la messagerie, page interrompue) ; les échecs, eux, ne se relancent qu'à la main |

## Alerte de retard

Si une tâche récurrente du plugin a plus de six heures de retard, un avis rouge **« les tâches planifiées ne s'exécutent plus »** s'affiche sur le tableau de bord de WordPress et sur celui de Périscolaire. Il liste les tâches concernées et le retard de la plus ancienne. Tant que l'avis reste affiché, les purges prévues par la politique de conservation et la reprise des envois sont à l'arrêt.

C'est le cas typique d'un WordPress en conteneur : WP-Cron compte sur les visites et sur un appel du site vers lui-même, qui échoue souvent dans un conteneur. La solution est de faire appeler `wp-cron.php` par l'hébergement, par exemple toutes les cinq minutes depuis la machine hôte :

```bash
*/5 * * * * curl -fsS -o /dev/null https://votre-site.fr/wp-cron.php?doing_wp_cron
```

Ajoutez ensuite `define('DISABLE_WP_CRON', true);` à `wp-config.php` (ou la variable d'environnement équivalente de votre image) pour que les visites ne le déclenchent plus en double. L'avis disparaît au passage suivant du cron, une fois les tâches rattrapées.

## Tâches ponctuelles

Deux événements sont déclenchés à l'usage et ne sont pas récurrents : `psc_conversation_notify` regroupe les notifications d'un même échange, et `psc_send_message_emails` réalise l'envoi différé d'un message aux familles.

## Résultat attendu

Les tâches récurrentes apparaissent dans le planificateur WordPress et s'exécutent dans les délais attendus, y compris sur un site peu visité grâce au cron système.

## Pour aller plus loin

- [Installation et activation](installation-activation.md)
- [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md)
- [Données personnelles (RGPD)](../rgpd.md)
