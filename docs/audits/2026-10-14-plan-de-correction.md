# Plan de correction — constats d'AUDIT.md

> **Document archivé, état figé au 15/10/2026.** Ce qui reste ouvert est suivi dans [la feuille de route](../feuille-de-route.md). Les constats auxquels ce plan renvoie sont dans [2026-10-14-audit.md](2026-10-14-audit.md).

**Branche :** `audit-fixes`, créée depuis `fix/cycle-de-vie-crons`.

**Équipe :**
- **lead** : session principale. Il planifie, arbitre et consolide.
- **fix-1**, **fix-2**, **fix-3** : chacun corrige les constats de son lot.
- **verificateur** : relit les corrections. Il ne touche pas au code.

Ce fichier sert de **liste de tâches partagée**. Seul le verificateur coche les tâches, dans la colonne « Validé » ; le lead commite le fichier en fin de chantier.

---

## 1. Règles communes

### Fichiers et commits

- **Propriété exclusive.** Chaque fichier appartient à un seul lot (section 3). Pour obtenir un changement dans un fichier d'un autre lot, envoie un message direct à son propriétaire avec le besoin précis. Il l'implémente puis confirme.
- **Commit.** Une correction donne un commit. Le message suit le format `SEC-03: description courte`, avec le suffixe `/L1`, `/L2` ou `/L3` pour un constat découpé entre lots (ex. `PRA-07/L3: pluriels et marqueurs du portail`). Il se termine par les lignes :
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01JjbusyBQDpFka9m4dZ3TRz
  ```
- **Git partagé.** Les 4 agents travaillent dans le **même dossier de travail**.
  - Ne jamais utiliser `git add -A`, `git add .`, `git commit -a`, `stash`, `checkout`, `reset`, `rebase` ni `push`.
  - Commiter uniquement ses chemins : `git commit -m "…" -- fichier1 fichier2`.
  - Un fichier nouveau s'ajoute dans la même commande : `git add nouveau && git commit -m "…" -- nouveau autres`.
  - Si `.git/index.lock` existe, attendre 2 s puis réessayer.

### Contrôles après chaque correction

- `php -l` sur chaque fichier PHP modifié, en PHP 8.3 **et** en PHP 7.4 :
  ```bash
  podman exec plugin-extrascolaire-wordpress-1 php -l /var/www/html/wp-content/plugins/periscolaire-registration/<fichier>
  podman run --rm -v "$PWD:/app:ro" docker.io/library/php:7.4-cli php -l /app/<fichier>
  ```
- PHPStan est autorisé (`podman exec -w /var/www/html/wp-content/plugins/periscolaire-registration plugin-extrascolaire-wordpress-1 php vendor/bin/phpstan analyse --memory-limit=2G --no-progress`). Ne lire que les erreurs de **ses** fichiers : les autres lots sont en cours d'écriture.
- **Pas de Playwright en phase 2.** L'environnement est partagé et les tests réinitialisent la base. Le lead lance la suite complète en phase 3.
- Les scripts `bin/verify-*.php` ciblés sont autorisés ; chacun nettoie ses propres données.

### Exigences d'AGENTS.md

- **Schéma.** Pas de changement de schéma : ni `DB_VERSION`, ni colonne, ni index, ni clé étrangère (§3.1, point d'arrêt). Si une correction l'exige, prévenir le lead.
- **Sécurité.** Nonce, capacité, `$wpdb->prepare()`, `sanitize_*` en entrée et `esc_*` en sortie sur tout code touché.
- **Textes.** Chaînes en `__()` avec le domaine `periscolaire-registration`. Toute nouvelle notification devient un **modèle d'e-mail éditable** (`Psc_Email_Templates`).
- **Compatibilité.** PHP 7.4 à 8.3. Aucune donnée personnelle dans les journaux ni dans les messages d'erreur.
- **Tests.** Chaque correction SEC ou RGPD qui change un comportement s'accompagne d'une vérification automatisée, dans les fichiers de test **propres au lot** :
  - `bin/verify-audit-lotN.php`, commande WP-CLI `verify-audit-lotN` ;
  - et/ou `tests/audit-fixes-lotN.spec.ts`, avec un contrôle de droits pour les constats d'accès.

### Escalade et validation

- Une correction ambiguë, risquée, ou qui change un comportement visible des familles ou des agents de la mairie **au-delà des arbitrages de la section 2** remonte au lead (adresse `main`) avant d'être écrite.
- Une fois la tâche commitée, envoyer au verificateur `ID + hash du commit`.

---

## 2. Arbitrages du lead

### Corrections approuvées, comportements visibles assumés

| ID | Décision |
|---|---|
| SEC-01 | Une nouvelle soumission ne modifie **jamais** une demande `pending` : la réponse reste identique, rien n'est changé, et la famille qui veut corriger contacte la mairie. Une demande `unverified` n'est plus réécrite : une nouvelle ligne est créée, et les anciennes `unverified` de la même adresse ne sont supprimées (fichiers compris) **qu'à la confirmation** de la nouvelle. Aucune colonne nouvelle. |
| SEC-02 | Seule une session ouverte par l'adresse du titulaire peut changer l'e-mail du titulaire. L'ancienne adresse reçoit un avis avec un lien d'annulation (nouveau modèle éditable, fourni par fix-3). Une session sans identité de connexion (cookie antérieur) doit se reconnecter pour changer l'e-mail. L'identité de connexion est reportée dans le journal (`acteur_libelle`). |
| SEC-03 | Le registre est revérifié à chaque appel par jeton ; le jeton est supprimé si l'intervenant est révoqué ou expiré. Une longueur minimale de 8 caractères s'applique **aux codes créés ou modifiés** ; les codes existants restent valides. `intervenant_id` devient obligatoire **seulement si** l'écran de déverrouillage le transmet déjà ; sinon, escalade au lead. |
| SEC-07 | La signature des sessions utilise une clé hors base quand elle existe (`psc_encryption_key_outside_db()`), et `wp_salt` sinon. Toutes les familles sont déconnectées une fois au déploiement ; c'est accepté, et regroupé avec SEC-02. |
| RGPD-01 | Un foyer sans enfant **et** sans activité depuis la durée de conservation des enfants existante est supprimé via `delete_family()`, qui anonymise s'il reste des factures. Une demande `pending` est purgée au bout de **180 jours**. Un enfant sans ligne d'année est purgé d'après sa date de création. |
| RGPD-05 | L'export restitue le contenu des allergies et des messages (droit d'accès, art. 15). |
| RGPD-06, RGPD-07 | Correctifs approuvés. Ils changent l'écran intervenants et le formulaire d'édition d'une famille. |
| PERF-01, PERF-02 | Un seul e-mail récapitulatif par famille pour une fermeture de plage. L'écran affiche ensuite « N envois en cours » au lieu d'un bilan synchrone. |
| PRA-08 | Les sous-pages masquées sont retirées du menu, sans casser la mise en surbrillance du menu parent. |

### Mis de côté pour validation humaine

Ces points ne sont **pas traités** dans cette branche.

| ID | Raison |
|---|---|
| SEC-04, SEC-08 | Ils changent la connexion de toutes les familles (page de confirmation, jeton à usage unique, durée de 72 h ramenée à 1 h) : décision produit à prendre. |
| SEC-05 | Fichier d'infrastructure de pré-production : lier les ports à 127.0.0.1 peut couper le proxy inverse. À valider avec l'exploitant. |
| SEC-06 (HMAC) | Il faut une nouvelle constante en production et une version 3 de la chaîne. Seule la partie verrou (`GET_LOCK`) est traitée. |
| RGPD-08 | La durée de conservation des factures relève du DPO. |
| RGPD-09 (empreinte) | Changement de schéma (§3.1). Seule la partie « jeton retiré de l'URL » est traitée. |
| RGPD-10 (chiffrement, retrait) | Décision du DPO. Seul le retrait du détail des allergies dans l'e-mail est traité. |
| RGPD-11 | Suppression de documents d'assurance : décision de la mairie. |
| RGPD-12 | Choix documenté par la mairie (`rgpd.md:44`). |
| PERF-12 (index) | Changement de schéma (§3.1). La purge des `envois` et le marqueur de `cleanup_receipts` sont traités. |
| PRA-01 | Décision de la mairie : facture par foyer, ou migration de schéma. |
| PRA-13, PRA-16 | Refactorisations larges, sans couverture dédiée et de faible priorité. |
| PRA-17 (Settings API) | Choix d'architecture. Seuls le journal des clés modifiées et la validation de la progression sont traités. |

---

## 3. Lots (fichiers exclusifs)

### Lot 1 — fix-1 : accès, sessions, demandes, intervenants

Fichiers :
- `includes/class-psc-requests.php`
- `includes/class-psc-admin-requests.php`
- `includes/class-psc-parents.php`
- `includes/class-psc-login-tokens.php`
- `includes/helpers/session.php`
- `includes/helpers/crypto.php`
- `includes/helpers/core.php`
- `includes/helpers/throttle.php`
- `includes/class-psc-admin-base.php`
- `includes/class-psc-frontend-base.php`
- `includes/class-psc-frontend-profil.php`
- `includes/class-psc-impersonation.php`
- `includes/class-psc-sidscm.php`
- `assets/js/sidscm.js`
- `templates/sidscm.php`
- `includes/class-psc-frontend-inscriptions.php`
- `assets/js/guest.js`
- `templates/guest-request.php`
- `templates/partials/guest-child-row.php` (nouveau)
- `templates/portal-profil.php`
- `templates/frontend-portal.php` (ajouté par le lead pendant la phase 2, pour les notices de SEC-02)
- `includes/helpers/impersonation.php` (ajouté par le lead pendant la phase 2, registre de consultation pour SEC-02 et PRA-04)
- `tests/integration/capabilities-matrix.php`
- `bin/verify-audit-lot1.php` (nouveau)
- `tests/audit-fixes-lot1.spec.ts` (nouveau)

**Fonctions partagées à livrer en premier**, puis à annoncer à toute l'équipe :
- `psc_required_capability($action)` sous forme de table explicite (PRA-02) ;
- l'identité de connexion de la session, par exemple `Psc_Parents::session_login_role(): 'titulaire'|'second'|''` (SEC-02).

### Lot 2 — fix-2 : données, RGPD, journal d'audit, échanges, messages

Fichiers :
- `includes/class-psc-audit.php`
- `includes/helpers/audit.php`
- `includes/class-psc-privacy.php`
- `includes/class-psc-retention.php`
- `includes/helpers/retention.php`
- `includes/class-psc-admin-familles.php`
- `templates/admin-parents.php`
- `templates/admin-children.php`
- `includes/class-psc-conversations.php`
- `includes/class-psc-conversations-frontend.php`
- `includes/class-psc-conversations-admin.php`
- `templates/admin-conversation.php`
- `templates/frontend-conversations.php`
- `includes/class-psc-messages.php`
- `includes/class-psc-messages-frontend.php`
- `includes/class-psc-messages-admin.php`
- `assets/js/psc-message-notifications.js`
- `assets/js/psc-messages-admin.js`
- `templates/admin-messages-edit.php`
- `templates/admin-messages-list.php`
- `templates/admin-messages-stats.php`
- `includes/class-psc-admin-assurances.php`
- `templates/admin-assurances.php`
- `templates/portal-assurance-gate.php`
- `documentation/rgpd.md`
- `includes/class-psc-admin-audit.php` et `templates/admin-audit.php` (ajoutés par le lead pendant la phase 2, tâche PERF-05)
- `tests/unit/retention-policy.php` et `tests/integration/p1-final-summary.php` (ajoutés par le lead pendant la phase 2, tâche RGPD-14)
- `bin/verify-audit-lot2.php` (nouveau)
- `tests/audit-fixes-lot2.spec.ts` (nouveau)

**Fonction partagée à livrer en premier :** `Psc_Audit::forget_child($child_id)` et la nouvelle forme de `forget_family()` (RGPD-04), à annoncer à toute l'équipe.

### Lot 3 — fix-3 : envois, calendrier, facturation, performance, administration

Fichiers :
- `includes/class-psc-school-calendar.php`
- `includes/helpers/school-calendar.php`
- `includes/class-psc-envois.php`
- `includes/class-psc-mailer.php`
- `includes/class-psc-email-templates.php`
- `templates/admin-email-templates.php`
- `includes/class-psc-invoices.php`
- `includes/class-psc-admin-invoices.php`
- `includes/class-psc-menus.php`
- `includes/class-psc-admin-cantine.php`
- `includes/class-psc-admin-school-years.php`
- `templates/admin-annees.php`
- `includes/class-psc-installer.php`
- `includes/helpers/files.php`
- `includes/helpers/services.php`
- `includes/helpers/settings.php`
- `includes/class-psc-frontend.php`
- `includes/class-psc-frontend-enfants.php`
- `includes/class-psc-school-years.php`
- `includes/class-psc-school-year.php`
- `includes/class-psc-planning.php`
- `templates/portal-planning-2.php`
- `templates/portal-enfants.php`
- `includes/class-psc-admin.php`
- `assets/css/admin.css`
- `includes/class-psc-admin-config.php`
- `templates/admin-settings.php`
- `templates/admin-supplier-orders.php` et `templates/admin-supplier-settings.php` (ajoutés par le lead pendant la phase 2, tâche PRA-02/L3)
- `templates/admin-menus.php` et `templates/admin-factures.php` (ajoutés par le lead pendant la phase 2, tâche PERF-02)
- `bin/verify-envois.php` et `tests/envois.spec.ts` (rattachés après coup, tâche PERF-02 : passage des tests en mode asynchrone)
- `bin/verify-audit-lot3.php` (nouveau)
- `tests/audit-fixes-lot3.spec.ts` (nouveau)

**Fonctions partagées à livrer en premier**, puis à annoncer à toute l'équipe :
- l'avis à l'ancienne adresse, en modèle éditable, par exemple `Psc_Mailer::send_email_change_notice_old($old, $new, $cancel_url)` (pour fix-1, SEC-02) ;
- `Psc_Frontend::send_private_headers()` (PRA-09, utilisée par fix-1 dans `sidscm.php`) ;
- `psc_shortcode_page_id($shortcode, $option)` (PRA-12, utilisée par fix-1 dans `sidscm.php`).

### Fichiers réservés au lead

`AUDIT.md`, `FIXES-PLAN.md` (le verificateur peut y cocher les tâches), `AGENTS.md`, `.github/workflows/*`, `docker-compose*.yml`, `periscolaire-registration.php`, `uninstall.php`, `bin/verify-crons.php`.

---

## 4. Liste des tâches

L'ordre suit, dans chaque lot, la priorité Critique, puis Élevée, Moyenne, Faible. Les livraisons partagées passent en tête, car elles débloquent les autres lots.

### Lot 1 — fix-1

| Ordre | ID | Prio | Fichiers | Dépend de | Commit | Validé |
|---|---|---|---|---|---|---|
| 1 | PRA-02 | Élevée | helpers/core.php, class-psc-admin-base.php, tests/integration/capabilities-matrix.php | — | a0bfd2c | [x] |
| 2 | SEC-02 | Élevée | class-psc-parents.php, helpers/session.php, class-psc-frontend-profil.php, templates/portal-profil.php | fix-3 : avis à l'ancienne adresse | 5382aea, 044917d, b12603d, d06a7ee, c36bd57 | [x] |
| 3 | SEC-01 | Élevée | class-psc-requests.php | — | 922b759 | [x] |
| 4 | PRA-03 | Élevée | assets/js/guest.js, templates/guest-request.php, templates/partials/guest-child-row.php | — | aa806c2 | [x] |
| 5 | RGPD-04/L1 | Élevée | class-psc-requests.php, class-psc-parents.php, class-psc-frontend-profil.php (résumés sans e-mail, `famille_id` renseigné) | fix-2 : convention annoncée | ba237d3 | [x] |
| 6 | RGPD-03/L1 | Élevée | class-psc-requests.php (champs SEPA vidés au refus et à l'approbation) | — | 27517b1 | [x] |
| 7 | RGPD-01/L1 | Élevée | class-psc-requests.php (purge `pending` à 180 j) | — | ec3104f | [x] |
| 8 | SEC-03 | Moyenne | class-psc-sidscm.php, assets/js/sidscm.js | — | 62b73d0, 2ee0e10, 6f19bc1 | [x] |
| 9 | RGPD-06 | Moyenne | class-psc-sidscm.php | — | 1c2c9d7 | [x] |
| 10 | PRA-09/L1 | Moyenne | class-psc-sidscm.php | fix-3 : `send_private_headers()` | a874df5, 294f4d4 | [x] |
| 11 | PRA-07/L1 | Moyenne | class-psc-sidscm.php (marqueurs numérotés, translators) | — | b349539 | [x] |
| 12 | SEC-07 | Faible | helpers/crypto.php, helpers/session.php | après SEC-02 | 46421fe | [x] |
| 13 | SEC-10 | Faible | class-psc-parents.php | — | 1468fd0 | [x] |
| 14 | PERF-07 | Faible | class-psc-requests.php, class-psc-admin-requests.php | — | 2a9a3f1 | [x] |
| 15 | PRA-12/L1 | Faible | class-psc-sidscm.php | fix-3 : `psc_shortcode_page_id()` | 509f06f | [x] |
| 16 | PRA-14/L1 | Faible | class-psc-requests.php, class-psc-impersonation.php | — | ef1133e | [x] |
| 17 | PRA-18 | Faible | class-psc-frontend-inscriptions.php | — | 4c45e70 | [x] |

### Lot 2 — fix-2

| Ordre | ID | Prio | Fichiers | Dépend de | Commit | Validé |
|---|---|---|---|---|---|---|
| 1 | RGPD-04 | Élevée | class-psc-audit.php (`forget_family` : `ip` et `pour_le_compte_de` vidés, `forget_child`), class-psc-retention.php, class-psc-admin-familles.php (résumés sans identité) | — | 6588bc5, a9f94d3, 9938736 | [x] |
| 2 | RGPD-02 | Élevée | class-psc-conversations.php | — | eb58030 | [x] |
| 3 | RGPD-03 | Élevée | class-psc-privacy.php, class-psc-admin-familles.php (`requests` et `message_destinataires` couverts) | — | 66d966f | [x] |
| 4 | RGPD-01 | Élevée | class-psc-retention.php (foyers sans enfant, enfants sans année) | — | db8015b | [x] |
| 5 | PERF-05 | Moyenne | class-psc-audit.php (plus de réécriture de `resume`, donc pas de rechaînage) | RGPD-04 | 1987a3c | [x] |
| 6 | RGPD-05 | Moyenne | class-psc-privacy.php | RGPD-03 | d486d05 | [x] |
| 7 | RGPD-07 | Moyenne | templates/admin-parents.php, class-psc-admin-familles.php | — | a6229d3, 7995a2a | [x] |
| 8 | RGPD-09/L2 | Moyenne | class-psc-messages-frontend.php (jeton retiré de l'URL par redirection) | — | 3463198 | [x] |
| 9 | PRA-04 | Moyenne | class-psc-conversations-admin.php, templates/admin-conversation.php, class-psc-conversations.php | — | c5b1efc, e7d69f4 | [x] |
| 10 | PRA-05 | Moyenne | templates/admin-messages-edit.php, templates/admin-messages-list.php, class-psc-messages-admin.php | — | bf94678, de9abff | [x] |
| 11 | PRA-06 | Moyenne | class-psc-admin-assurances.php, templates/admin-assurances.php, templates/portal-assurance-gate.php, templates/admin-messages-*.php, assets/js/psc-messages-admin.js | fix-3 : PRA-06/L3 | a553f2d, a0b13a0, e3d3cce | [x] |
| 12 | PRA-07/L2 | Moyenne | class-psc-admin-familles.php | — | aafab39 | [x] |
| 13 | SEC-06 (verrou) | Faible | class-psc-audit.php | — | 1fd3297 | [x] |
| 14 | SEC-09 | Faible | class-psc-conversations-frontend.php | — | 85d5f7a | [x] |
| 15 | RGPD-13 | Faible | class-psc-privacy.php | RGPD-03 | 504dfbc | [x] |
| 16 | RGPD-14 | Faible | class-psc-retention.php, helpers/retention.php, documentation/rgpd.md | RGPD-01 | e8a2511, 583e56b, 7a22f31 | [x] |
| 17 | PERF-08 | Faible | assets/js/psc-message-notifications.js, class-psc-messages-frontend.php, class-psc-messages.php | — | 383d45f | [x] |
| 18 | PERF-12/L2 | Faible | class-psc-messages.php (marqueur `cleanup_receipts`) | — | ef29e86 | [x] |
| 19 | PRA-14/L2 | Faible | class-psc-conversations.php, class-psc-conversations-frontend.php, class-psc-retention.php | — | 68465a2 | [x] |

### Lot 3 — fix-3

| Ordre | ID | Prio | Fichiers | Dépend de | Commit | Validé |
|---|---|---|---|---|---|---|
| 1 | SEC-02/L3 | Élevée | class-psc-mailer.php, class-psc-email-templates.php (avis à l'ancienne adresse) | — (débloque fix-1) | 0b207b1 | [x] |
| 2 | PRA-09 | Moyenne | class-psc-frontend.php (`send_private_headers()` sur `template_redirect`) | — (débloque fix-1) | 74fafc8 | [x] |
| 3 | PRA-12 | Faible | class-psc-mailer.php, templates/admin-settings.php, class-psc-admin-config.php (`psc_shortcode_page_id`) | — (débloque fix-1) | f61b5c9 | [x] |
| 4 | PERF-02 | Moyenne | class-psc-envois.php (réservation, puis lots par cron), class-psc-admin-invoices.php, class-psc-menus.php, class-psc-admin-cantine.php | — | 876d210 | [x] |
| 5 | PERF-01 | Élevée | class-psc-school-calendar.php, class-psc-admin-school-years.php, class-psc-mailer.php, class-psc-email-templates.php (récapitulatif par famille) | PERF-02 (envoi asynchrone) | 4c065ad | [x] |
| 6 | PERF-03 | Moyenne | class-psc-installer.php | — | bdfdc66 | [x] |
| 7 | PERF-04 | Moyenne | class-psc-frontend.php | — | e33843d | [x] |
| 8 | PRA-08 | Moyenne | class-psc-admin.php | — | 0d798d8 | [x] |
| 9 | PRA-10 | Moyenne | class-psc-email-templates.php, class-psc-mailer.php, class-psc-invoices.php, templates/admin-email-templates.php | après SEC-02/L3 et PERF-01 | a4d866b | [x] |
| 10 | RGPD-10/L3 | Moyenne | class-psc-mailer.php (badge au lieu du détail des allergies) | — | a647988 | [x] |
| 11 | PRA-07/L3 | Moyenne | class-psc-frontend.php, class-psc-invoices.php, class-psc-mailer.php, class-psc-installer.php | — | b07a991 | [x] |
| 12 | PRA-06/L3 | Moyenne | templates/admin-settings.php (`:215-222`) | — | ca90e9e | [x] |
| 13 | SEC-11 | Faible | class-psc-school-calendar.php | — | 2d84ffd | [x] |
| 14 | PERF-06 | Faible | class-psc-school-years.php, class-psc-menus.php, templates/portal-planning-2.php, templates/portal-enfants.php | — | acd61e6 | [x] |
| 15 | PERF-09 | Faible | class-psc-invoices.php (rappel : une facture **envoyée** n'est jamais recalculée) | — | 4591ee1 | [x] |
| 16 | PERF-10 | Faible | class-psc-installer.php, helpers/files.php | PERF-03 | ad73dd2 | [x] |
| 17 | PERF-11 | Faible | class-psc-school-calendar.php (corrections manuelles prioritaires, règle n°7) | — | d457d99 | [x] |
| 18 | PERF-12/L3 | Faible | class-psc-envois.php (purge des envois de plus de 13 mois) | PERF-02 | 79cdefa | [x] |
| 19 | PRA-11 | Faible | class-psc-admin.php, assets/css/admin.css | — | 788da11 | [x] |
| 20 | PRA-15 | Faible | class-psc-frontend-enfants.php, class-psc-planning.php, class-psc-school-year.php, class-psc-school-calendar.php, class-psc-installer.php, class-psc-mailer.php, helpers/services.php, helpers/school-calendar.php | — | fc0afc8 | [x] |
| 21 | PRA-17 (partiel) | Faible | class-psc-admin-config.php | — | c05dbad | [x] |
| 22 | PRA-14/L3 | Faible | class-psc-invoices.php, helpers/school-calendar.php | — | 1402526 | [x] |
| 23 | PRA-02/L3 | Faible | templates/admin-supplier-orders.php, templates/admin-supplier-settings.php (formulaire fournisseur masqué sans psc_manage_config ; ajout du lead) | PRA-02 | 2d03fbf | [x] |

**Total :** 58 tâches (17 + 19 + 22).

---

## 5. Phase 3 : consolidation (lead)

Une fois toutes les tâches validées :

1. `php -l` en 7.4 et 8.3, puis PHPStan et PHPCS (standard WordPress) sur tout le plugin.
2. Les scripts `bin/verify-*.php`, dont les nouveaux scripts par lot, ajoutés à `.github/workflows/e2e.yml`.
3. La suite Playwright complète.
4. La mise à jour d'`AUDIT.md` : chaque constat reçoit son statut (corrigé avec son commit, partiel, ou mis de côté avec la raison).
