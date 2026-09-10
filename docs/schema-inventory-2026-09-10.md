# Inventaire STEP-02 — 10 septembre 2026

## Portée et limites

Cet inventaire décrit l'instance locale jetable après la suite E2E. Il ne
contient aucune donnée personnelle. Il ne prouve ni la version minimale ni le
schéma d'une instance réellement déployée : ces deux éléments devront être
relevés séparément sur chaque cible avant toute décision de suppression.

## Version et structure observées

- Version du plugin au dépôt : `5.8.1` ; schéma déclaré et observé : `4.5.0`.
- `psc_constraints_missing` : tableau vide.
- **19 tables `wp_psc_*`** présentes ; toutes utilisent InnoDB et
  `utf8mb4_unicode_520_ci`.
- Tables legacy absentes : `wp_psc_trimestres`, `wp_psc_calendar_days` et
  `wp_psc_registrations`. C'est le résultat attendu d'une installation neuve,
  pas une preuve concernant les instances mises à niveau.
- Sept clés étrangères `ON DELETE CASCADE` sont réellement présentes :
  enfants→parents, dossiers annuels→enfants/années, patterns→enfants,
  exceptions→enfants, habilitations→enfants et historique→enfants.
- Les définitions `SHOW CREATE TABLE` et `SHOW INDEX` confirment les écarts de
  l'audit : aucun index sur `parents.second_parent_email`, aucun composite
  `(status, created_at)` ou `(status, decided_at)` sur `requests`, aucune FK
  sur `attendance.child_id` ni `pickup_history.pickup_person_id`.

## Volumes locaux exacts

| Table | Lignes | Table | Lignes |
|---|---:|---|---:|
| attendance | 0 | child_school_years | 16 |
| children | 23 | exception | 34 |
| holidays | 9 | invoices | 5 |
| menus | 0 | message_destinataires | 0 |
| messages | 0 | parents | 15 |
| pattern | 4 | pickup_history | 4 |
| pickup_persons | 2 | requests | 8 |
| school_calendar | 0 | school_year | 2 |
| school_years | 4 | service_closures | 0 |
| supplier_orders | 2 | | |

Ces volumes sont des données de test et ne permettent pas d'arbitrer un index.

## Plans d'exécution sur 100 000 lignes synthétiques

Les mesures ont été réalisées sous MySQL 8 dans une base temporaire supprimée
à la fin. Aucun index n'a été ajouté à la base WordPress réelle.

| Requête | Sans index candidat | Avec index candidat | Plan obtenu |
|---|---:|---:|---|
| DB-01, résolution par second parent | ~60,7 ms | ~1,1 ms | 100 000 lignes via `active`, puis `index merge` sur les deux e-mails |
| DB-02A, `unverified` arrivé à 7 jours | ~26,0 ms | ~0,04 ms | 25 000 lignes filtrées, puis range couvrant `(status,created_at)` |
| DB-02B, traité arrivé à 90 jours | ~25,1 ms | ~0,08 ms | scan de 100 000 lignes, puis range couvrant `(status,decided_at)` |

Le jeu DB-02 stable contient 100 demandes non vérifiées et 200 demandes
traitées arrivant à expiration. Sur un premier nettoyage où une grande partie
des lignes est périmée, le gain du composite est naturellement plus faible.
Ces résultats justifient de proposer les trois index dans une étape de schéma
distincte, après confrontation avec les volumes de la cible.

## Observation legacy

L'option non autoloadée `psc_legacy_usage_counts` compte désormais, sans URL ni
identité :

- les demandes explicites de `?psc_tab=cantine` (Planning 1) ;
- les lectures de `registrations` par migration ou vérification ;
- les lectures de `calendar_days` et `trimestres` par anciennes migrations.

La période retenue est **35 jours consécutifs après déploiement du compteur**,
et doit inclure au moins une génération puis vérification de factures. Relevé :

```bash
wp option get psc_legacy_usage_counts --format=json
```

Le début de période correspond à la date de déploiement, à consigner ici. À la
fin, exporter uniquement les compteurs et horodatages, jamais les journaux HTTP
nominatifs. L'absence d'une clé vaut zéro usage observé.

## Décision de passage

`STEP-02` reste ouverte jusqu'à la fin de cette observation et au relevé de la
version minimale réellement déployée. En conséquence, `STEP-03` est bloquée :
aucun chemin legacy et aucune table ne doivent encore être supprimés.
