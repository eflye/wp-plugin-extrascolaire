# P1-01 à P1-18 — Sécurité, protection des données et intégrité automatisables Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finaliser les protections techniques P1 restantes, sans fermer les validations manuelles DPO, mairie ou hébergeur.

**Architecture:** Conserver `Psc_Privacy`, `Psc_Retention` et `Psc_Audit` comme frontières publiques. Ajouter des fonctions ciblées, idempotentes et testables, en partageant les helpers de rédaction, de politique de conservation et de résolution d’acteur plutôt qu’en réécrivant les parcours existants.

**Tech Stack:** PHP 7.4+, WordPress 5.8+, MySQL via `$wpdb`, WP-Cron, tests unitaires PHP, sondes d’intégration WP-CLI et Playwright existant.

**Spec:** `docs/superpowers/specs/2026-09-17-p1-06-a-p1-11-protection-donnees-design.md`

## Global Constraints

- Ne jamais choisir ou affirmer la base légale RGPD, la durée comptable ou la conformité de l’hébergement dans le code.
- Utiliser exclusivement des données fictives dans les tests et neutraliser les e-mails sortants.
- Ne jamais écrire dans les logs, rapports ou exports un token, un IBAN complet, une description d’allergie ou le contenu d’une conversation.
- Le mode de rétention par défaut est `simulation`; aucune nouvelle purge automatique n’est activée avant validation DPO/mairie.
- Respecter PHP 7.4+/WordPress 5.8+ et les conventions existantes du plugin.
- Chaque mutation vérifie nonce, capacité, appartenance et retours `$wpdb`; les opérations répétées sont idempotentes.

---

### Task 1: Contrats de données sensibles et notice paramétrable

**Files:**
- Modify: `includes/class-psc-privacy.php`
- Modify: `includes/class-psc-frontend-enfants.php`
- Modify: `includes/class-psc-requests.php`
- Modify: `templates/guest-request.php`
- Modify: `templates/portal-enfants.php`
- Modify: `includes/helpers/settings.php`
- Test: `tests/unit/privacy-contract.php`

**Interfaces:**
- Produces `Psc_Privacy::privacy_settings()` returning normalized strings for municipality, DPO contact, rights contact and published policy URL.
- Produces `psc_privacy_notice_html($context)` with escaped, filterable output and no global consent checkbox.
- Preserves the existing allergy consent timestamp contract and rejects a new/changed non-empty allergy without explicit consent.

- [ ] **Step 1: Write failing contract tests** for defaults, filters, escaping, empty/changed allergy consent and absence of sensitive text in notification arguments.
- [ ] **Step 2: Run the focused test** with `podman exec -w /var/www/html/wp-content/plugins/periscolaire-registration plugin-extrascolaire-wordpress-1 php tests/unit/privacy-contract.php`; verify each new assertion fails before implementation.
- [ ] **Step 3: Implement normalized privacy settings** in `Psc_Privacy`, using existing WordPress options and `apply_filters`, with explicit “à adapter” defaults.
- [ ] **Step 4: Render the same notice/link** at public request and family child-edit collection points; keep the allergy-specific consent separate and conditional.
- [ ] **Step 5: Adjust allergy notifications** to carry child/family identifiers and a protected admin link, not the free-text allergy, unless an existing operational contract explicitly requires it.
- [ ] **Step 6: Re-run the focused tests and existing allergy/request tests**; require zero failures.
- [ ] **Step 7: Commit** with `git add includes/class-psc-privacy.php includes/class-psc-frontend-enfants.php includes/class-psc-requests.php templates/guest-request.php templates/portal-enfants.php includes/helpers/settings.php tests/unit/privacy-contract.php && git commit -m "feat: encadre les données sensibles et la notice de confidentialité"`.

### Task 2: Rétention déclarative et simulation sûre

**Files:**
- Modify: `includes/class-psc-retention.php`
- Modify: `includes/class-psc-installer.php`
- Create: `includes/helpers/retention.php`
- Test: `tests/integration/retention-policy.php`

**Interfaces:**
- Produces `psc_retention_policies()` returning category, start event, duration, validation flag and handler name.
- Produces `Psc_Retention::run($mode = 'simulation', $now = null)` returning `categories`, `examined`, `removed`, `retained`, `errors` and `next_run`.
- Keeps `Psc_Retention::purge_departed_children()` as a compatibility wrapper that delegates to the declared child handler.

- [ ] **Step 1: Add failing integration assertions** that simulation performs no writes, unvalidated categories are retained with a reason, and a second execution is idempotent.
- [ ] **Step 2: Run the integration probe** through WP-CLI and record the expected failures.
- [ ] **Step 3: Define the policy registry** for requests, departed children, planning/attendance, pickup persons, allergies, conversations, audit logs, private files and invoices; mark only already-approved child/conversation behavior executable.
- [ ] **Step 4: Implement deterministic cutoff calculation** using an injected `$now`, with explicit UTC storage and WordPress timezone only for display.
- [ ] **Step 5: Implement simulation and execution modes** with per-category counts and errors; refuse unknown/negative/unvalidated durations server-side.
- [ ] **Step 6: Add file handlers** that verify private-directory containment, preserve the source until the destination/deletion postcondition succeeds, and report orphaned files without deleting them.
- [ ] **Step 7: Schedule the report-producing cron** without enabling new destructive categories; expose a manual admin/CLI execution path in simulation.
- [ ] **Step 8: Re-run retention probes, existing privacy/impersonation retention tests and PHP lint**.
- [ ] **Step 9: Commit** with `git add includes/class-psc-retention.php includes/class-psc-installer.php includes/helpers/retention.php tests/integration/retention-policy.php && git commit -m "feat: rend la rétention déclarative et simulable"`.

### Task 3: Export et effacement complets des droits

**Files:**
- Modify: `includes/class-psc-privacy.php`
- Modify: `includes/class-psc-admin-familles.php`
- Modify: `includes/helpers/files.php`
- Test: `tests/integration/privacy-rights.php`

**Interfaces:**
- `Psc_Privacy::export_family_data()` returns all configured data categories, with secret fields omitted or redacted and private files represented by metadata.
- `Psc_Privacy::erase_family_data()` returns removed, retained and error messages plus a trace identifier.
- A shared purge helper returns a structured postcondition result and never deletes an invoice PDF solely because a parent row is anonymized.

- [ ] **Step 1: Write a failing fictional-household probe** covering second parent, child, planning, pickup person, insurance metadata, invoice, conversation and audit traces.
- [ ] **Step 2: Run the probe** and capture the missing categories and current destructive-manual-delete mismatch.
- [ ] **Step 3: Extend export coverage** to remaining stored fields and file metadata; assert no token, full IBAN, allergy text or conversation body appears in diagnostic errors.
- [ ] **Step 4: Refactor erasure to a shared, idempotent household handler** that anonymizes retained accounting references, removes operational records, and reports file failures without claiming success.
- [ ] **Step 5: Align the manual mairie deletion path** with the same retention guard so invoices are retained when the configured accounting rule says so.
- [ ] **Step 6: Add audit events** for export, erasure, retained accounting data and partial failures, using redacted identifiers.
- [ ] **Step 7: Re-run the integration probe twice** and verify identical second-run results apart from the new audit event.
- [ ] **Step 8: Commit** with `git add includes/class-psc-privacy.php includes/class-psc-admin-familles.php includes/helpers/files.php tests/integration/privacy-rights.php && git commit -m "feat: sécurise lexercice des droits sur les données famille"`.

### Task 4: Audit des opérations sensibles et intégrité

**Files:**
- Modify: `includes/class-psc-audit.php`
- Modify: `includes/helpers/audit.php`
- Modify: `includes/class-psc-admin-audit.php`
- Modify: `templates/admin-audit.php`
- Modify: `includes/class-psc-installer.php`
- Test: `tests/integration/audit-coverage.php`
- Test: `tests/unit/audit-redaction.php`

**Interfaces:**
- `psc_audit_action_registry()` declares every sensitive action with category, sensitivity level and safe summary.
- `Psc_Audit::verify_chain($from, $to)` returns `valid`, `first_error` and inspected count.
- `Psc_Audit::purge_expired($mode = 'simulation', $now = null)` returns a redacted report and never rewrites retained history.

- [ ] **Step 1: Extend the registry test** to enumerate admin, family, AJAX, cron, export, erasure, billing, impersonation and SIDSCM actions; fail on an unknown sensitive action.
- [ ] **Step 2: Add redaction cases** for tokens, IBAN/BIC, allergy descriptions, conversation bodies, file paths and e-mail addresses; run `tests/unit/audit-redaction.php` to verify failures.
- [ ] **Step 3: Implement the coverage hooks** at the existing action boundaries, preserving the generic shutdown fallback and semantic deduplication.
- [ ] **Step 4: Implement chain verification and retention simulation** with explicit first-bad-row reporting and no mutation in simulation mode.
- [ ] **Step 5: Harden admin filters/exports** with capability and nonce checks; audit each view, export, verification and purge action.
- [ ] **Step 6: Add failure monitoring** that increments the existing counter and records only a technical code in the fallback log.
- [ ] **Step 7: Run registry, redaction, audit E2E and PHP lint tests**; verify a fictional incident can be reconstructed without sensitive payloads.
- [ ] **Step 8: Commit** with `git add includes/class-psc-audit.php includes/helpers/audit.php includes/class-psc-admin-audit.php templates/admin-audit.php includes/class-psc-installer.php tests/integration/audit-coverage.php tests/unit/audit-redaction.php && git commit -m "feat: étend la traçabilité des opérations sensibles"`.

### Task 5: Validation croisée, documentation et garde-fous manuels

**Files:**
- Modify: `TODO.md`
- Modify: `readme.txt`
- Modify: `documentation/rgpd.md`
- Create: `tests/integration/p1-data-protection-summary.php`

- [ ] **Step 1: Write a summary probe** that executes the complete fictional lifecycle: allergy declaration/change, privacy export, erasure with retained invoice, retention simulation/execution, audit verification.
- [ ] **Step 2: Run the full verification set**: PHP lint, unit tests, integration probes, `npm run docs:a11y`, and `mkdocs build --strict`.
- [ ] **Step 3: Document manual gates** in `documentation/rgpd.md`: DPO legal basis and durations, PAI circuit, AIPD, private storage test, backup/key restoration and cron monitoring.
- [ ] **Step 4: Update `TODO.md`** only for criteria demonstrated by tests; leave DPO/server acceptance explicitly open and link each completed item to its test/commit.
- [ ] **Step 5: Update the changelog** with technical safeguards and the remaining manual validations; do not claim RGPD compliance.
- [ ] **Step 6: Commit** with `git add TODO.md readme.txt documentation/rgpd.md tests/integration/p1-data-protection-summary.php && git commit -m "docs: consigne les preuves et validations restantes P1"`.

### Task 6: Accès intervenants individuels et habilitations WordPress (P1-01/P1-02)

**Files:**
- Modify: `includes/class-psc-sidscm.php`
- Modify: `assets/js/sidscm.js`
- Modify: `includes/helpers/core.php`
- Modify: `includes/class-psc-installer.php`
- Modify: `includes/class-psc-admin-config.php`
- Test: `tests/integration/intervenants-capacites.php`

- [ ] **Step 1: Écrire les tests d’accès** : deux intervenants distincts, révocation d’un seul, expiration d’inactivité, absence de secret dans `localStorage`, éditeur sans capacité sensible.
- [ ] **Step 2: Implémenter le registre serveur des intervenants** avec identifiant, empreinte de code individuel, rôle/périmètre, expiration et révocation persistante.
- [ ] **Step 3: Remplacer le code côté JavaScript** par un cookie/session opaque non persistant et supprimer la conservation dans `localStorage`.
- [ ] **Step 4: Séparer les capacités WordPress** pour familles, facturation, données sanitaires, audit et configuration ; refuser chaque écran et endpoint côté serveur.
- [ ] **Step 4b: Ajouter sur la fiche utilisateur WordPress** des cases indépendantes pour ces capacités ; enregistrer avec nonce/capacité et permettre plusieurs cases simultanément, sans remplacer les rôles existants.
- [ ] **Step 5: Ajouter audit et tests de refus**, exécuter la sonde et le lint.
- [ ] **Step 6: Commit** `feat: individualise les accès intervenants et les habilitations`.

### Task 7: Stockage privé et preuves de recette (P1-04/P1-18)

**Files:**
- Modify: `includes/helpers/files.php`
- Modify: `includes/class-psc-admin-config.php`
- Create: `tests/integration/private-storage-receipt.php`
- Modify: `documentation/installation/installation-activation.md`
- Modify: `documentation/installation/sauvegarde-mise-a-jour.md`

- [ ] **Step 1: Écrire la sonde** de containment, refus des chemins traversants, fichier témoin inaccessible et diagnostic sans données métier.
- [ ] **Step 2: Refuser ou signaler explicitement** un `PSC_PRIVATE_DIR` sous racine lorsque la protection serveur équivalente n’est pas vérifiable.
- [ ] **Step 3: Ajouter une fiche de recette** listant PHP/WordPress, TLS/cookies, droits, SMTP, cron, cache, stockage, sauvegardes et restauration, avec statut `à vérifier` par défaut.
- [ ] **Step 4: Documenter la sauvegarde** de la base, du répertoire privé et de la clé, puis la restauration isolée avec e-mails neutralisés.
- [ ] **Step 5: Exécuter la sonde locale et le lint**, sans conclure sur le serveur distant.
- [ ] **Step 6: Commit** `feat: renforce les garde-fous du stockage privé`.

### Task 8: Justificatifs, temps, facturation et migrations (P1-13 à P1-17)

**Files:**
- Modify: `includes/class-psc-assurances.php`
- Modify: `includes/class-psc-frontend-reinscription.php`
- Modify: `includes/helpers/lock.php`
- Modify: `includes/helpers/planning.php`
- Modify: `includes/class-psc-admin-inscriptions.php`
- Modify: `includes/class-psc-mailer.php`
- Modify: `includes/class-psc-invoices.php`
- Modify: `includes/class-psc-installer.php`
- Test: `tests/integration/p1-integrity.php`

- [ ] **Step 1: Écrire les cas d’échec** disque plein, SQL refusé, enfant invalide, échéance, forfait sans repas, tarif modifié après émission et migration interrompue.
- [ ] **Step 2: Rendre les dépôts atomiques** : fichier temporaire, vérification MIME/taille, SQL contrôlé, source conservée et reprise idempotente.
- [ ] **Step 3: Unifier les timestamps** sur des instants Unix pour les comparaisons et timezone WordPress pour l’affichage.
- [ ] **Step 4: Extraire une décision unique de prestation facturable** et l’utiliser par planning, CSV, e-mail, PDF et commandes.
- [ ] **Step 5: Ajouter un snapshot immuable** des lignes/tarifs/flags à l’émission d’une facture ; une correction crée une nouvelle version traçable.
- [ ] **Step 6: Protéger les migrations** par verrou, postconditions, journal technique, conservation des sources et reprise sans double déplacement.
- [ ] **Step 7: Exécuter tests d’intégrité et lint**, puis commit `feat: fiabilise justificatifs facturation et migrations`.

### Task 9: Dossier de preuves P1 et synchronisation du suivi (P1-10/P1-18)

**Files:**
- Modify: `TODO.md`
- Modify: `documentation/rgpd.md`
- Modify: `documentation/installation/installation-activation.md`
- Create: `documentation/installation/fiche-recette-p1.md`
- Test: `tests/integration/p1-final-summary.php`

- [ ] **Step 1: Écrire la sonde finale** non destructive des contrats automatisés.
- [ ] **Step 2: Documenter les décisions manuelles** : registre, AIPD, contrats, base légale, durées, PAI, stockage, sauvegardes, restauration et responsables.
- [ ] **Step 3: Mettre à jour `TODO.md`** uniquement pour les critères prouvés ; conserver les validations externes ouvertes.
- [ ] **Step 4: Exécuter lint PHP, tests unitaires, sondes, build documentation et audit axe**.
- [ ] **Step 5: Commit** `docs: rassemble les preuves et recettes P1`.

## Final verification

Run from the repository root (with the WordPress container running):

```bash
for file in $(find includes templates -name '*.php' -print); do
  podman exec -w /var/www/html/wp-content/plugins/periscolaire-registration plugin-extrascolaire-wordpress-1 php -l "$file" || exit 1
done
podman exec -w /var/www/html/wp-content/plugins/periscolaire-registration plugin-extrascolaire-wordpress-1 php tests/unit/run.php
podman exec -w /var/www/html plugin-extrascolaire-wordpress-1 php /usr/local/bin/wp-cli.phar --path=/var/www/html eval-file wp-content/plugins/periscolaire-registration/tests/integration/p1-data-protection-summary.php --allow-root
PATH="$PWD/.venv-docs/bin:$PATH" npm run docs:a11y
git diff --check
```

The final report must separate automated evidence from the still-manual DPO and hosting decisions. Do not mark P1-06/P1-07/P1-08/P1-09/P1-11 complete merely because their technical components exist; their acceptance criteria require the external validations listed in the specification.
