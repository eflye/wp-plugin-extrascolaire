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

## Tâches ponctuelles

Deux événements sont déclenchés à l'usage et ne sont pas récurrents : `psc_conversation_notify` regroupe les notifications d'un même échange, et `psc_send_message_emails` réalise l'envoi différé d'un message aux familles.

## Résultat attendu

Les tâches récurrentes apparaissent dans le planificateur WordPress et s'exécutent dans les délais attendus, y compris sur un site peu visité grâce au cron système.

## Pour aller plus loin

- [Installation et activation](installation-activation.md)
- [Sauvegarde et mise à jour](sauvegarde-mise-a-jour.md)
- [Données personnelles (RGPD)](../rgpd.md)
