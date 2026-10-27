# AGENTS.md — wp-plugin-extrascolaire

Guide de travail pour les agents de code (Claude Code, Codex, Copilot…) intervenant sur ce dépôt.
Lis ce fichier en entier avant toute modification.

---

## 1. Contexte du projet

Plugin WordPress sur mesure qui dématérialise l'inscription au service périscolaire de la commune de **Montgeroult (95)**.
Il remplace un calendrier papier trimestriel que les parents remplissaient et que la secrétaire de mairie ressaisissait pour la facturation.

**Utilisateurs :**
- **Parents / responsables** : front-office, authentification par **magic link** (aucun mot de passe).
- **Secrétaire de mairie** : tableau de bord WP Admin (modération, facturation, exports).
- **Agents périscolaires** : consultation des présences (en cours de conception).

**Services gérés :** garderie matin, cantine, garderie soir, forfait journée.

**Fonctionnalités en place :** facturation PDF, mandats SEPA, import iCal vacances zone C avec corrections manuelles, workflows de modération, purge RGPD, régimes alimentaires, modèles d'e-mails configurables, annulation / signalement d'absence, menus de cantine, dépôt de documents (attestation d'assurance), tableau de bord secrétaire.

**Statut : en production sur une installation unique** (mairie de Montgeroult). Tout changement de schéma passe par `Psc_Installer` (`DB_VERSION`, `dbDelta()` et, si des données existantes doivent être transformées, une méthode `migrate_X_Y_Z()` couverte par `bin/verify-migrations.php`). La montée n'est garantie que depuis le schéma 4.15.0 (`MIN_UPGRADE_FROM`) ; aucune couche de compatibilité en deçà.

---

## 2. Règles métier à préserver

Ces décisions ont été prises par la mairie. Respecte-les dans chaque évolution.

1. **Une facture par foyer et par mois** (`wp_psc_invoices`, clé unique `parent_id, mois`), quel que soit le responsable qui a effectué la réservation. Le détail est ventilé par enfant dans les lignes de la facture.
2. **Confidentialité stricte entre responsables** : chaque responsable voit uniquement ses propres données et celles des enfants dont il a l'autorité parentale. Le contrôle se fait **côté serveur**, à chaque requête.
3. **Propriété des réservations** : seul le responsable auteur d'une réservation (ou la secrétaire) peut l'annuler.
   *État actuel : les réservations n'enregistrent pas leur auteur ; elles appartiennent au foyer. Ne pas supposer cette règle implémentée.*
4. **Rôles d'un responsable** vis-à-vis d'un enfant : `autorite_parentale` et `responsable_facturation`, portés par une table de liaison (le responsable est découplé du foyer).
   *État actuel : le foyer (`wp_psc_parents`) porte un titulaire et un second parent optionnel (colonnes `second_parent_*`) ; la table de liaison n'existe pas. La session distingue titulaire et second parent (`Psc_Parents::session_login_role()`).*
5. **Classes rattachées à une année scolaire** : la classe d'un enfant dépend de l'année. La promotion de classe utilise un **mapping configurable** (écoles rurales à classes multi-niveaux : la progression d'un niveau à l'autre est rarement linéaire).
6. **Personnes autorisées à récupérer l'enfant** : liste active avec suppression logique + historique **append-only** contenant un instantané complet à chaque modification.
7. **Calendrier** : les jours d'ouverture découlent du calendrier zone C importé, corrigé manuellement par la secrétaire. Les corrections manuelles restent prioritaires sur l'import.

---

## 3. Méthode de travail

### 3.1 Schéma d'abord, puis point d'arrêt
Pour toute fonctionnalité qui touche aux données :
1. Propose le schéma (tables, colonnes, types, index, clés, contraintes) et les impacts sur les écrans existants.
2. **Arrête-toi et attends la validation explicite** avant d'écrire le code d'implémentation.
3. Implémente ensuite par petites étapes.

### 3.2 Périmètre
- Reformule le besoin et liste ce qui sera livré avant de commencer.
- Signale toute ambiguïté fonctionnelle sous forme de question fermée, avec ta recommandation.
- Limite les modifications au périmètre validé ; note les améliorations annexes dans une section « Pistes » du compte rendu.
- Ce qui reste ouvert se tient dans `docs/feuille-de-route.md` (seule liste vivante) ; les audits de `docs/audits/` sont figés et ne se réécrivent pas.

### 3.3 Commits atomiques
- Un commit = une unité cohérente (schéma, logique métier, UI admin, UI parent, tests, docs).
- Chaque commit laisse le plugin fonctionnel et les tests au vert.
- Messages au format Conventional Commits, en français :
  `feat(reservations): empêcher l'annulation par un autre responsable`
  Types : `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`, `a11y`.

### 3.4 Versions
- Versionnement sémantique (`vX.Y.Z`), releases publiées par GitHub Actions à partir des tags.
- Mets à jour la version dans l'en-tête du fichier principal du plugin et dans la constante de version quand la tâche le demande.

---

## 4. Conventions techniques

### 4.1 Base de données
- Tables préfixées `{$wpdb->prefix}psc_` (ex. `wp_psc_children`, `wp_psc_pickup_persons`).
- Création / mise à jour via `dbDelta()`.
- Toutes les requêtes passent par `$wpdb->prepare()`.
- Noms de tables et de colonnes en `snake_case`, en français lorsque le terme métier est français (`autorite_parentale`, `forfait_journee`).
- Horodatages (`created_at`, `updated_at`…) en heure du site via `current_time('mysql')` ; échéances comparées à `time()` (`expires_at`…) en UTC via `gmdate()`. Affichage au fuseau `Europe/Paris`.
- Montants stockés en euros (`DECIMAL(10,2)`), calculés en **centimes entiers** (clé `centimes` de `psc_billing_tariffs()`), convertis en euros par `/ 100.0`. Les factures envoyées ne sont jamais recalculées.

### 4.2 PHP / WordPress
- Code compatible PHP 7.4 à 8.3 (`php -l` en CI) et sans erreur PHPStan niveau 3 (`phpstan.neon`) ; suis le style du fichier modifié.
- Échappe toute sortie (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`) et assainis toute entrée (`sanitize_*`, `absint`).
- Protège chaque formulaire et chaque action AJAX / REST par un **nonce** et une vérification de capability.
- Endpoints REST : `permission_callback` explicite qui applique les règles de confidentialité de la section 2.
- Chaînes affichées traduisibles via `__()` / `esc_html__()` avec le text domain du plugin.
- Hooks et fonctions globales préfixés `psc_` ; classes globales préfixées `Psc_` (pas d'espace de noms).

### 4.3 Front-end
- JavaScript sans framework lourd ; privilégie le JS natif et les composants WordPress existants.
- Assets chargés uniquement sur les pages qui en ont besoin.

---

## 5. Accessibilité RGAA — exigence de base

Chaque écran créé ou modifié (parent **et** admin) respecte le RGAA 4.1 :
- Structure sémantique : titres hiérarchisés, landmarks, listes, tableaux avec `<th scope>` et `<caption>`.
- Formulaires : chaque champ a un `<label>` associé ; erreurs annoncées (`aria-live` / `aria-describedby`) et reliées au champ.
- Navigation complète au clavier, ordre de focus logique, focus visible.
- Contrastes AA minimum (4,5:1 texte courant, 3:1 éléments d'interface).
- Information transmise par le texte en plus de la couleur (calendrier de réservation notamment).
- Composants interactifs custom : rôles et états ARIA corrects.
- Vérification automatisée via `@axe-core/playwright` dans les tests de chaque écran touché.

---

## 6. Tests

- **Playwright** accompagne chaque fonctionnalité : parcours nominal, cas d'erreur, et **contrôle des droits** (un responsable A tente d'accéder aux données du responsable B → refus attendu).
- Un test d'accessibilité axe par écran modifié.
- Données de test créées et nettoyées par les tests eux-mêmes (fixtures dédiées).
- Lance la suite complète avant de proposer un commit.

---

## 7. Données personnelles et sécurité

- Données d'enfants = données sensibles : collecte minimale, accès journalisé côté admin.
- Tout nouveau champ personnel est intégré à la **purge RGPD** existante et à l'export des données.
- Documents déposés stockés hors de l'accès public direct, servis via un contrôleur qui vérifie les droits.
- Magic links : jeton stocké haché, durée de vie bornée. **Ils ouvrent la session en un seul clic, sans page de confirmation** (décision du 15/10/2026 : fluidité sur mobile, mécanique sans mot de passe) ; ils restent donc réutilisables pendant leur durée de vie. Ne pas ajouter d'étape de confirmation ni d'usage unique sans nouvelle décision.
- Journaux et messages d'erreur exempts de données personnelles.

---

## 8. E-mails

- Envoi via `wp_mail()` ; le transport SMTP (relais OVH Exchange) est configuré hors du plugin, par constantes dans `wp-config.php` et une extension SMTP.
- Contenus issus des modèles configurables ; ajoute toute nouvelle notification sous forme de modèle éditable par la secrétaire.

---

## 9. Commandes utiles

<!-- À ADAPTER à l'environnement réel du dépôt -->
```bash
# Environnement local
# (docker-compose.yml : WordPress, MySQL, Mailpit ; podman sur ce poste)
podman compose up -d                 # stack de travail (:8080), données conservées
npm run test:local:up                # stack de test jetable (:8081), cf. bin/test-stack.sh

# Tests end-to-end — toujours sur la stack de test : le seed écrase la base visée
npm run test:local                   # suite complète
npm run test:local -- tests/<spec>   # un fichier
npm run verify:local <nom>           # bin/verify-<nom>.php (migrations compris)

# Qualité PHP
composer phpstan
# Scripts de vérification WP-CLI : bin/verify-*.php (liste dans .github/workflows/e2e.yml)
```

---

## 10. Définition de « terminé »

Une tâche est terminée quand :
- [ ] le périmètre validé est entièrement livré ;
- [ ] le schéma a été validé avant implémentation (si concerné) ;
- [ ] les règles métier de la section 2 sont respectées ;
- [ ] les écrans touchés passent les contrôles RGAA et axe ;
- [ ] les tests Playwright couvrent le nominal, les erreurs et les droits, et passent ;
- [ ] PHPStan et les scripts `bin/verify-*.php` concernés sont au vert ;
- [ ] les commits sont atomiques et bien nommés ;
- [ ] un compte rendu final liste : ce qui a été fait, les choix techniques, les points à vérifier manuellement, les pistes.

---

## 11. Glossaire

| Terme | Sens |
|---|---|
| Foyer | Regroupement familial d'enfants et de responsables |
| Responsable | Adulte lié à un ou plusieurs enfants (autorité parentale et/ou facturation) |
| Année scolaire | Période de réservation ; porte le calendrier et les classes des enfants |
| Mois | Période de facturation (une facture par foyer et par mois) |
| Campagne de réinscription | Fenêtre paramétrable pendant laquelle les familles confirment l'année suivante |
| Forfait journée | Formule combinant plusieurs services sur une journée |
| Zone C | Zone de vacances scolaires de l'Île-de-France |
| Secrétaire | Agent de mairie qui administre le service dans WP Admin |
