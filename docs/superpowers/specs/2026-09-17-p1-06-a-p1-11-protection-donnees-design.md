# P1-01 à P1-18 — Sécurité, protection des données et intégrité automatisables

## Objectif

Compléter les protections techniques liées aux accès intervenants, habilitations, stockage, allergies, information des personnes, conservation, exercice des droits, journalisation, justificatifs, temps, facturation et migrations, sans prétendre déterminer la base légale, remplacer le DPO ou certifier l’hébergement distant.

Le résultat doit être déployable sur l’installation WordPress actuelle, compatible PHP 7.4+/WordPress 5.8+, et testable uniquement avec des données fictives.

## Périmètre

### Inclus

- P1-01 : accès intervenants individuels, sessions expirantes et révocation par personne.
- P1-02 : capacités WordPress séparées et contrôles serveur par domaine métier.
- P1-04 : garde-fous applicatifs du répertoire privé et sonde de recette HTTP.
- P1-06 : collecte minimale des allergies, consentement distinct et traçable, suppression du détail médical des flux qui n’en ont pas besoin.
- P1-07 : notice de confidentialité paramétrable, affichable au formulaire public et au portail, avec liens vers les droits et le contact configuré.
- P1-08 : politique de rétention explicite par catégorie, simulation puis purge idempotente des données applicatives et fichiers associés, avec rapport d’exécution.
- P1-09 : export et effacement WordPress couvrant les données effectivement stockées, procédure serveur pour les fichiers et conservation des factures conforme à la règle métier décidée.
- P1-11 : journal d’audit des opérations sensibles, identité minimale, rédaction des valeurs sensibles, intégrité chaînée, contrôle d’accès, rétention et alerte d’échec.
- P1-13 à P1-17 : écritures de justificatifs vérifiées, référentiel de temps unifié, calcul de facturation partagé, factures historisées et migrations idempotentes.
- P1-18 : contrôles automatisables de prérequis et rapport de recette exportable, sans exécuter de changement sur le serveur distant.

### Hors périmètre et validations manuelles

- P1-10 (registre, AIPD, contrats, procédure d’incident) reste un livrable de la mairie/DPO ; le code fournit seulement les informations et rapports nécessaires.
- P1-04/P1-18 (stockage, sauvegardes, restauration et configuration du serveur distant) restent à recetter sur l’hébergement réel.
- Le plugin ne choisit pas la base légale de l’article 6/9, la durée comptable, le statut juridique d’une facture ni l’opportunité d’un hébergement HDS.
- Aucun effacement automatique ne sera activé pour une catégorie dont la durée n’a pas été validée par la mairie/DPO ; le mode simulation est le défaut.

## Architecture retenue

Les classes existantes restent les frontières publiques :

- `Psc_Privacy` expose les hooks WordPress d’export/effacement et construit la notice à partir d’un jeu de réglages filtrable.
- `Psc_Retention` orchestre les tâches planifiées, délègue les suppressions à des handlers par catégorie et produit un rapport sans contenu sensible.
- `Psc_Audit` est l’unique point d’écriture du journal. Un registre déclaratif décrit l’action, sa catégorie, son niveau de sensibilité et le résumé sûr à conserver.

Les handlers sont idempotents : une seconde exécution ne recrée pas d’erreur et ne supprime pas une donnée hors périmètre. Toute opération de fichier vérifie l’appartenance au répertoire privé et conserve la source tant que la cible n’est pas validée.

### Habilitations composables

Les habilitations sont des capacités indépendantes attribuables directement à un utilisateur WordPress. Une personne peut cumuler plusieurs capacités (familles, présence, facturation, santé, audit, configuration) ; les rôles ne sont que des ensembles initiaux pratiques et ne sont jamais exclusifs. Une interface sur la fiche utilisateur permet de cocher/décocher chaque capacité, et chaque écran ou endpoint vérifie la capacité correspondante côté serveur.

## Données et flux

### Allergies

Une allergie est une donnée facultative, strictement alimentaire, limitée à la longueur existante. Le formulaire exige un consentement dédié uniquement lorsqu’une valeur non vide est nouvelle ou modifiée ; le consentement est horodaté avec le fuseau WordPress et l’acteur (famille ou mairie). Les notifications opérationnelles transmettent un identifiant d’enfant et un lien authentifié plutôt que le texte médical lorsque le détail n’est pas indispensable.

Le texte affiché précise que le PAI et la base légale doivent être traités avec la mairie/DPO. Le code ne transforme jamais un régime alimentaire en donnée religieuse.

### Notice de confidentialité

Un réglage administrable contient le nom de la collectivité, le contact DPO, le contact d’exercice des droits et les liens publics. Des valeurs par défaut clairement marquées « à adapter » sont proposées. Le formulaire public et le portail affichent la notice ou un lien vers la page publiée ; aucune case de consentement globale n’est ajoutée pour remplacer l’information.

### Conservation et droits

Les catégories minimales sont : demandes non confirmées, demandes traitées, enfants sortis, planning/présences, personnes autorisées, allergies, conversations, journaux d’audit, fichiers privés et factures. Chaque catégorie possède un point de départ, une durée, un mode (`simulation`/`exécution`) et une justification affichée dans le rapport. Les factures et leurs métadonnées sont exclues de l’effacement lorsqu’une obligation de conservation s’applique ; l’identité opérationnelle est alors anonymisée selon la règle validée.

L’export WordPress restitue les champs texte et les métadonnées utiles, jamais les secrets (jetons, IBAN en clair ou empreintes). L’effaceur retourne les éléments supprimés, conservés et en erreur, avec un identifiant de suivi audité.

### Audit

Chaque entrée contient : horodatage UTC, identifiant de requête, acteur (type/id/libellé minimal), action déclarée, résultat, objet et identifiants techniques, résumé, adresse IP selon la politique validée, canal et empreinte chaînée. Les valeurs sensibles sont remplacées par des marqueurs ou des empreintes irréversibles avant écriture. Une panne d’audit ne bloque pas l’action métier mais incrémente un compteur et écrit un repli technique sans données sensibles.

Le backoffice filtre par période, catégorie, acteur, famille/enfant et résultat ; l’export est réservé à la capacité dédiée et chaque consultation/export est lui-même audité. La vérification d’intégrité et la purge respectent la chaîne sans réécrire l’historique conservé.

## Interfaces attendues

- `Psc_Privacy::privacy_settings()` retourne les réglages normalisés de notice et de contact.
- `Psc_Privacy::export_family_data($email, $page)` et `erase_family_data($email, $page)` restent conformes aux contrats WordPress et ne divulguent pas les secrets.
- `Psc_Retention::run($mode = 'simulation', $now = null)` retourne un rapport structuré (`categories`, `examined`, `removed`, `retained`, `errors`, `next_run`).
- `Psc_Audit::log($action, $args)` est le seul écrivain ; `Psc_Audit::verify_chain($from, $to)` retourne un résultat booléen et le premier écart.
- `psc_audit_action_registry()` et les filtres de rétention/notice sont documentés et couverts par des tests de contrat.

## Sécurité et erreurs

- Vérifier nonce, capacité, appartenance famille et mode simulation côté serveur ; ne jamais se fier à un champ caché.
- Refuser une purge si la durée est absente, négative ou non validée ; journaliser le refus sans la donnée ciblée.
- Ne jamais inclure dans les logs, rapports, exports ou messages d’erreur : token, IBAN complet, description d’allergie ou contenu de conversation.
- Les échecs partiels sont visibles, rejouables et n’avancent pas un marqueur de rétention tant que la postcondition n’est pas vérifiée.

## Tests et critères d’acceptation

1. Tests unitaires : consentement nouveau/modifié, notice normalisée, rédaction des secrets, règles de durée et rapports déterministes.
2. Tests d’intégration WordPress : export/effacement d’un foyer fictif avec second parent, enfant, planning, assurance, facture et conversation ; factures conservées et parent anonymisé conformément au contrat.
3. Tests de rétention : simulation sans écriture, exécution idempotente, panne SQL/disque, horloge figée et reprise.
4. Tests d’audit : registre exhaustif des actions, chaîne valide/invalide, acteur correct, accès refusé, export et purge audités.
5. Lint PHP, tests unitaires existants et tests d’intégration passent ; aucune donnée réelle ni e-mail sortant n’est utilisé.

## Déploiement et validations externes

Le déploiement active d’abord la simulation et un rapport administrateur. La mairie/DPO valide ensuite les durées, le texte final, le circuit PAI, l’AIPD et les règles d’archives. L’administrateur/hébergeur recette le répertoire privé, les sauvegardes, la clé de chiffrement, le cron et une restauration isolée. L’exécution automatique n’est activée qu’après ces validations manuelles, conservées comme preuves datées.
