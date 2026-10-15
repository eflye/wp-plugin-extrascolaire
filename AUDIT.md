# Audit du plugin « Périscolaire – Inscriptions » (v5.34.1)

- **Date :** 14/10/2026.
- **Branche auditée :** `fix/cycle-de-vie-crons`. Elle contient déjà 4 correctifs issus d'une revue préalable : reprogrammation des crons, retrait de toutes les tâches à la désactivation, pointage SIDSCM atomique et capacités retirées à la désinstallation. Ils ne figurent pas ci-dessous.
- **Méthode :** audit en lecture seule mené par 4 auditeurs (pratiques, performance, sécurité, données). Chacun a lu l'intégralité du code avec les skills `wordpress-router` et `wp-plugin-development`.
  - Les constats qui touchaient un autre périmètre ont été transmis à son titulaire.
  - « sécurité » et « données » ont confronté leurs conclusions sur le contrôle d'accès.
  - Un problème vu sous deux angles n'apparaît qu'une fois, dans son périmètre principal, avec mention de l'impact secondaire.
- **Vérification par le lead :** les constats retenus dans les 5 actions prioritaires ont été revérifiés dans le code.
- **Priorités :** elles sont celles des auditeurs, sauf mention « (priorité ajustée) ».

**Bilan : 55 constats.** 10 Élevée, 20 Moyenne, 25 Faible, aucun Critique.

Le plugin est globalement solide :

- un garde centralisé (nonce et capacité) sur chaque action, ainsi qu'un double jeton pour le portail famille ;
- aucune IDOR trouvée ;
- des requêtes préparées et des sorties échappées ;
- des IBAN chiffrés par AEAD ;
- des documents hors racine web ;
- des dépôts de fichiers vérifiés par signature binaire ;
- un journal d'audit en liste blanche.

Les défauts sérieux se concentrent sur trois sujets :
- **Mutations anonymes ou entre co-responsables d'un même foyer :** une demande d'inscription peut être écrasée, un second parent peut évincer le titulaire.
- **Données conservées hors de toute purge :** fiches foyer, pièces jointes, demandes en attente, journal d'audit.
- **Envois synchrones dans les requêtes HTTP.**

---

## Suivi des corrections (14-15/10/2026)

Les corrections ont été appliquées sur la branche `audit-fixes` par trois correcteurs, avec un vérificateur qui a relu et validé chaque commit. Le plan, les lots et les arbitrages sont dans `FIXES-PLAN.md`.

| Statut | Nombre | Constats |
|---|---|---|
| Corrigé | 48 | SEC-01, 02, 03, 07, 09, 10, 11 · RGPD-01 à 14 · PERF-01 à 12 · PRA-01 à 12, 14, 15, 18 |
| Partiellement corrigé | 2 | SEC-06, PRA-17 |
| Risque accepté (décision) | 2 | SEC-04, SEC-08 |
| Mis de côté (validation humaine) | 3 | SEC-05, PRA-13, PRA-16 |

Contrôles de consolidation, le 15/10/2026 :
- `php -l` en PHP 7.4 et 8.3 sur les 212 fichiers PHP : aucune erreur.
- PHPStan niveau 3 : aucune erreur.
- PHPCS (WordPress) : aucune régression de sécurité. Les nouveaux signalements de sécurité concernent des noms de table interpolés, alors que les valeurs passent bien par `prepare()`, et des lectures GET d'affichage passées par `absint()`.
- Les 28 scripts `bin/verify-*` passent, dont les trois scripts par lot `verify-audit-lot1/2/3`, ajoutés à la CI. `verify-migrations.php` n'est pas lancé en local, car il détruit les données.
- 1 396 tests unitaires : tous passent.
- Playwright :
  - 6 échecs, identiques à ceux de `main` avant les corrections : `audit.spec.ts` lignes 272 et 331, `family-isolation.spec.ts:148` et `parent-connu.spec.ts:54`. Ils viennent des données de test (assurance manquante, e-mail Mailpit).
  - `messages.spec.ts:133`, qui échouait sur `main`, passe désormais.

Chaque constat porte ci-dessous une ligne **Statut** avec ses commits, ou la raison de sa mise de côté. Les parties restantes des corrections partielles exigent un changement de schéma (AGENTS.md §3.1), une décision du DPO ou de la mairie, ou une refactorisation plus large.

## Sommaire

1. [Sécurité applicative](#1-sécurité-applicative)
2. [Données personnelles et RGPD](#2-données-personnelles-et-rgpd)
3. [Performance](#3-performance)
4. [Bonnes pratiques et qualité](#4-bonnes-pratiques-et-qualité)
5. [Tableau récapitulatif](#5-tableau-récapitulatif)
6. [Les 5 actions à mener en premier](#6-les-5-actions-à-mener-en-premier)

---

## 1. Sécurité applicative

Il n'y a aucun endpoint REST : `register_rest_route` est absent du code. Tous les fichiers PHP de `includes/` et `templates/` ont leur garde ABSPATH.

### SEC-01 — Une requête anonyme écrase la demande d'inscription d'un tiers

- **Fichier :** `includes/class-psc-requests.php:262-266`, `:472-478`. Exploitation ensuite par `maybe_verify()` (`:493-560`) et `approve_request()`.
- **Priorité :** Élevée. Vérifié par le lead.
- **Statut (branche `audit-fixes`) :** Corrigé — 922b759.
- **Explication :**
  - `handle_submit()` est en `nopriv`, protégé par un simple nonce WordPress identique pour tous les visiteurs.
  - Si une demande existe pour l'e-mail saisi, en statut `unverified` **ou `pending`**, elle est **mise à jour** avec les données de l'attaquant. Elle repasse alors à `verified = 0` et `status = 'unverified'`, reçoit un nouveau `verify_hash` (le lien de la victime ne fonctionne plus) et perd ses justificatifs (`delete_pending_files`).
  - Scénario : un ex-conjoint connaissant l'e-mail de l'autre parent soumet une demande où il se déclare second parent. La victime confirme le nouvel e-mail qu'elle reçoit, la demande est validée, et l'attaquant obtient un lien magique vers tout le foyer.
- **Correction :** ne jamais modifier une demande existante depuis une requête anonyme. Le remplacement ne se fait qu'après confirmation de l'adresse.
  ```php
  if ($existing && $existing->status === 'pending') {
      wp_safe_redirect(add_query_arg('psc_msg', 'request_sent', $back)); exit; // réponse identique, rien n'est modifié
  }
  $data['replaces_request_id'] = $existing ? (int) $existing->id : null;
  $wpdb->insert($t, $data);                // jamais d'update ici
  // maybe_verify() : supprimer la demande remplacée (et ses fichiers) seulement une fois la nouvelle confirmée.
  ```
- **Impact secondaire (données) :** perte silencieuse des données et des justificatifs de la victime.

### SEC-02 — Le second parent peut évincer le titulaire en changeant l'e-mail de connexion

- **Fichier :** `includes/class-psc-frontend-profil.php:63-67`, `includes/class-psc-parents.php:335-370` et `:428-431`, `includes/class-psc-mailer.php:149-166`.
- **Priorité :** Élevée. Vérifié par le lead.
- **Statut (branche `audit-fixes`) :** Corrigé — 5382aea, 044917d, b12603d, d06a7ee, c36bd57, 0b207b1.
- **Explication :**
  - Le titulaire et le second parent ouvrent la **même** session de foyer ; `Psc_Parents::current()` ne sait pas qui agit.
  - Depuis « Mon profil », le second parent remplace l'adresse de connexion du titulaire. La confirmation part uniquement vers la nouvelle adresse, et l'ancienne n'est jamais prévenue.
  - Au clic, `revoke_access()` coupe les sessions du titulaire, qui perd l'accès sans alerte.
  - Le journal ne peut pas non plus dire lequel des deux parents a agi (traçabilité, AGENTS §7).
- **Correction :** inscrire dans la session l'adresse qui l'a ouverte, réserver le changement d'e-mail du titulaire au titulaire, prévenir l'ancienne adresse avec un lien d'annulation, et reporter l'acteur dans `acteur_libelle`.
  ```php
  // open_session($parent_id, $login_email) : payload signé + empreinte de l'adresse de connexion
  $payload .= '|' . substr(hash_hmac('sha256', strtolower($login_email), wp_salt('psc_session')), 0, 16);
  // handle_parent_update_profile()
  if ($new_email && $new_email !== $parent->email) {
      if (!Psc_Parents::session_is_titulaire($parent)) self::parent_form_redirect('email_change_forbidden');
      $r = Psc_Parents::request_email_change($parent->id, $new_email);
      Psc_Mailer::send_email_change_notice_old($parent->email, $new_email); // avec lien d'annulation
  }
  ```
- **Impact secondaire (données) :** journal d'audit incapable d'attribuer l'action à l'un des deux parents.

### SEC-03 — Écran intervenants (SIDSCM) : sessions non révoquées, codes sans robustesse minimale

- **Fichier :** `includes/class-psc-sidscm.php:184-205` (`sanitize_intervenants`), `:214-241` (`authenticate`), `:255-264`. `includes/helpers/throttle.php:66-73`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Corrigé — 62b73d0, 2ee0e10.
- **Explication :**
  - (a) Le jeton de session dure 8 h et se renouvelle à chaque appel, mais il n'est jamais revérifié contre le registre. Un intervenant **révoqué** garde donc l'accès aux listes, allergies et téléphones, et peut falsifier des pointages.
  - (b) Aucune longueur minimale n'est imposée au code.
  - (c) Avec un `intervenant_id` vide, le code est testé contre tous les intervenants. La seule limite est de 20 essais par heure et par IP, contournable avec plusieurs IP ou en IPv6.
- **Correction :**
  ```php
  $actor = get_transient(self::session_key($token));
  if (is_array($actor) && !self::find_active_intervenant($actor['id'])) {
      delete_transient(self::session_key($token)); return false;   // révoqué ou expiré
  }
  // sanitize_intervenants() : code ≥ 8 caractères ; intervenant_id obligatoire ;
  // psc_rate_limit('sidscm_bad_id_' . $id, 10, HOUR_IN_SECONDS) en plus de la limite par IP.
  ```
- **Impact secondaire (données) :** voir RGPD-06, minimisation des données envoyées.

### SEC-04 — Liens magiques réutilisables, jetons de notification valables 72 h

- **Fichier :** `includes/class-psc-parents.php:200-282`, `includes/class-psc-login-tokens.php:28-30` et `:60-74`, `includes/class-psc-mailer.php:874`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Risque accepté — décision du 15/10/2026 : connexion en un seul clic, sans page de confirmation, pour une expérience fluide sur mobile (mécanique sans mot de passe). AGENTS.md §7 est aligné.
- **Explication :**
  - Le lien de connexion est rejouable pendant toute sa durée de vie (30 min, réglable jusqu'à 24 h).
  - Le jeton porté par les e-mails de notification ouvre une session pendant **72 h**.
  - AGENTS §7 exige « usage unique, durée de vie courte ».
  - Un e-mail transféré ou lu sur un poste partagé donne donc 3 jours d'accès complet au foyer.
  - Ce mécanisme permet aussi le *login CSRF* (SEC-08).
- **Correction :** un GET (souvent émis par les scanners de messagerie) n'affiche qu'une page « Continuer ». Un POST avec nonce consomme le jeton et le marque utilisé. Le TTL des notifications passe à environ 1 h.
  ```php
  // sur POST valide uniquement :
  $wpdb->update(psc_table('parents'), array('token_hash' => null, 'token_expires' => null), array('id' => $parent->id));
  ```

### SEC-05 — Déploiement `docker-compose.prod.yml` : Mailpit exposé et mu-plugins de développement montés

- **Fichier :** `docker-compose.prod.yml:26-27` (`8087:8025`), `:40`, `:48-53`, `:58-60`.
- **Priorité :** Moyenne, si ce fichier sert à une pré-production avec de vraies familles.
- **Statut (branche `audit-fixes`) :** Mis de côté — fichier d'infrastructure de pré-production : à valider avec l'exploitant.
- **Explication :**
  - Mailpit, sans authentification et publié sur toutes les interfaces, reçoit tous les liens magiques.
  - `psc-offline-updates.php`, monté avec le reste de `mu-plugins/`, coupe les mises à jour de sécurité de WordPress.
  - `X-Forwarded-Proto` est accepté de n'importe quel client.
  - Rien de cela n'entre dans le zip de release, ce qui a été vérifié.
- **Correction :**
  ```yaml
  mailpit:   { ports: ["127.0.0.1:8087:8025"], environment: { MP_UI_AUTH_FILE: /data/auth } }
  wordpress:
    ports: ["127.0.0.1:8085:80"]
    volumes: [ "./mu-plugins/mailpit-smtp.php:/var/www/html/wp-content/mu-plugins/mailpit-smtp.php:ro" ]
  ```

### SEC-06 — Journal d'audit : empreintes sans clé, champs non couverts, chaîne qui peut bifurquer

- **Fichier :** `includes/helpers/audit.php:854-877`, `includes/class-psc-audit.php:182-219`.
- **Priorité :** Faible (défense en profondeur ; l'attaque suppose un accès en écriture à la base).
- **Statut (branche `audit-fixes`) :** Partiellement corrigé — 1fd3297 — verrou GET_LOCK ; signature HMAC mise de côté (nouvelle constante en production, chaîne v3).
- **Explication :**
  - Les empreintes sont des SHA-256 sans secret. Elles ne couvrent ni `details`, ni `famille_id`, `enfant_id`, `ip` ou `resultat`.
  - La séquence lecture du dernier hash → insertion → mise à jour n'est pas verrouillée : sous concurrence, la chaîne bifurque et `verify_chain()` signale de fausses ruptures.
- **Correction :** `hash_hmac('sha256', $canonique_toutes_colonnes, PSC_AUDIT_KEY)` avec une clé hors base, et `GET_LOCK('psc_audit_chain', 2)` autour de la séquence (repli sans chaînage si le verrou échoue).
- **Impact secondaire (performance) :** coût négligeable avec un délai d'attente court.

### SEC-07 — Clés de signature des sessions dérivées d'un secret qui peut être en base

- **Fichier :** `includes/helpers/crypto.php:187-197`, `includes/helpers/session.php:121-128`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — 46421fe.
- **Explication :** `wp_salt('psc_session')` et les autres « schemes » personnalisés retombent sur l'option `secret_key` stockée en base si `SECRET_KEY` n'est pas défini. Une fuite de sauvegarde suffit alors à forger un cookie de session de n'importe quel foyer.
- **Correction :** signer avec une clé hors base (`PSC_SESSION_KEY`, ou dérivée de `psc_encryption_key()`), et vérifier sa présence dans l'écran Maintenance.
  ```php
  function psc_sign($payload) { return hash_hmac('sha256', $payload, psc_encryption_key() . '|session'); }
  ```

### SEC-08 — Login CSRF par lien magique

- **Fichier :** `includes/class-psc-parents.php:211-282`, branché sur `init` pour toute URL.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Risque accepté — conséquence de la décision SEC-04 (connexion en un seul clic).
- **Explication :** une victime qui clique sur un lien valide de l'attaquant se retrouve connectée **à son foyer**, où elle peut saisir un IBAN ou des enfants.
- **Correction :** même mécanisme que SEC-04, avec une page de confirmation POST qui affiche « Vous allez vous connecter à l'espace de … ».

### SEC-09 — Consultation par la mairie : la lecture d'un échange le marque lu pour la famille

- **Fichier :** `includes/class-psc-conversations-frontend.php:53`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — 85d5f7a.
- **Explication :** `mark_read($id, 'famille')` est appelé sans tester la consultation. La consultation, censée être en lecture seule, modifie donc l'état des messages, alors que `class-psc-messages-frontend.php:80` fait bien ce test.
- **Correction :**
  ```php
  if (!Psc_Parents::is_impersonated()) Psc_Conversations::mark_read($requested, 'famille');
  ```

### SEC-10 — Changement d'e-mail sans limite de débit

- **Fichier :** `includes/class-psc-parents.php:335-370`, appelé depuis `includes/class-psc-frontend-profil.php:65`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — 1468fd0.
- **Explication :** une session famille peut faire envoyer sans limite, au nom de la mairie, des e-mails de confirmation vers des adresses arbitraires. Cela expose la réputation du domaine d'envoi.
- **Correction :**
  ```php
  if (!psc_rate_limit('email_change_' . $parent_id, 3, DAY_IN_SECONDS)) return new WP_Error('psc_rate', __('Trop de demandes.', 'periscolaire-registration'));
  ```

### SEC-11 — Import iCal : fenêtre de DNS rebinding

- **Fichier :** `includes/class-psc-school-calendar.php:173-229`.
- **Priorité :** Faible. L'action est réservée à `psc_manage_config`, et les redirections sont déjà revalidées.
- **Statut (branche `audit-fixes`) :** Corrigé — 2d84ffd — épinglage effectif avec le transport cURL.
- **Explication :** `validate_ics_url()` résout le nom, puis `wp_remote_get()` le résout une seconde fois. Un DNS à TTL nul peut répondre une IP publique, puis une IP interne.
- **Correction :** épingler l'IP validée (`CURLOPT_RESOLVE` via `http_api_curl`) ou limiter les hôtes à une liste blanche (`data.education.gouv.fr`).

> Le mappage des capacités par sous-chaîne a un impact sécurité. Il est traité dans **PRA-02**. L'IBAN visible avec l'habilitation « Familles » est traité dans **RGPD-07**. La notice alimentée par l'URL est traitée dans **PRA-05**.

---

## 2. Données personnelles et RGPD

### Inventaire synthétique

| Donnée | Stockage | Protection | Conservation effective | Export RGPD | Effacement |
|---|---|---|---|---|---|
| Identité et coordonnées du titulaire | `psc_parents` | clair | **illimitée** | oui | anonymisation |
| Second parent | `psc_parents.second_parent_*` | clair | illimitée | oui | oui |
| IBAN | `psc_parents.sepa_iban`, `psc_requests.sepa_iban` | chiffré (`psc1:`) | illimitée (foyer) ; demandes : vidé à l'approbation, 90 j si refusée | masqué | oui (foyer) / **non** (demandes) |
| BIC, titulaire, adresse SEPA, RUM | `psc_parents.sepa_*`, `psc_requests.sepa_*` | clair | illimitée ; 90 j sur la demande | oui | oui / **non** |
| Demande d'inscription (enfants, second parent, message) | `psc_requests` (`children_json`…) | clair sauf IBAN | non vérifiée : 7 j ; traitée : 90 j ; **en attente : illimitée** | **non** | **non** |
| Identité de l'enfant, date de naissance, régimes | `psc_children` | clair | 400 j après la sortie ; **jamais sans ligne d'année** | oui | oui |
| Allergies (texte libre, héritées) | `psc_children.food_allergies`, `children_json` | clair ; admin réservé à `psc_view_health` | comme l'enfant | présence seulement | oui |
| Classe, inscription, assurance | `psc_child_school_years` + fichiers privés | hors racine web | comme l'enfant, **toutes années** | métadonnées | oui |
| Planning, présences, heures | `psc_pattern`, `psc_exception`, `psc_attendance` | clair | comme l'enfant | planning oui, **présences non** | oui |
| Personnes autorisées et historique | `psc_pickup_persons`, `psc_pickup_history` | clair | comme l'enfant | liste oui, **historique non** | oui |
| Échanges famille ↔ mairie | `psc_conversations`, `psc_conversation_messages` | clair | 395 j | sans le contenu | oui |
| Pièces jointes des échanges | fichiers `conversations/Y/m/*` | privés | **illimitée** | non | **non** |
| Diffusions et accusés de lecture | `psc_message_destinataires` (jeton en clair) | clair | illimitée | **non** | **non** |
| Consultations par la mairie | `psc_impersonations` | clair | 365 j | non | oui |
| Factures | `psc_invoices`, `psc_invoice_versions`, PDF | privés | **illimitée** (10 ans annoncés) | métadonnées | non, par décision |
| Journal d'audit | `psc_audit_log` (`resume`, `details`, `ip`) | chaînage SHA-256 | 180, 365 ou 1095 j | non | partiel |

### RGPD-01 — Pas de durée de conservation pour les foyers, les demandes en attente et les enfants sans année

- **Fichier :** `includes/class-psc-retention.php:58-66` (« La fiche famille n'est pas touchée »), `:115-125` (`departed_sql`), `includes/class-psc-requests.php:57-81` (`cleanup`).
- **Priorité :** Élevée. Vérifié par le lead.
- **Statut (branche `audit-fixes`) :** Corrigé — db8015b, ec3104f.
- **Explication :**
  - Une fois le dernier enfant purgé, la ligne `psc_parents` reste en base sans limite. Elle contient les coordonnées des deux parents, l'IBAN chiffré, le BIC et la RUM.
  - `cleanup()` ignore les demandes `pending` : une demande jamais traitée est conservée indéfiniment.
  - `departed_sql()` exige une ligne `child_school_years`. Un enfant sans année (ajouté hors année active, ou dont l'année a été supprimée) n'est jamais purgé.
  - Ces trois points contredisent `class-psc-privacy.php:107` (« le temps de la relation »).
- **Correction :**
  ```php
  // Psc_Retention : passe « foyers sans enfant »
  $ids = $wpdb->get_col($wpdb->prepare("SELECT p.id FROM $t_par p
      WHERE NOT EXISTS (SELECT 1 FROM $t_child c WHERE c.parent_id = p.id)
        AND p.email NOT LIKE 'famille-supprimee-%%'
        AND COALESCE(p.last_login, p.created_at) < %s", $cutoff));
  foreach ($ids as $id) Psc_Admin_Familles::delete_family((int) $id); // anonymise s'il reste des factures
  // Psc_Requests::cleanup() : ajouter "status = 'pending' AND created_at < %s" (ex. 180 j)
  // departed_sql() : horloge de repli COALESCE(..., c.created_at) au lieu d'exclure l'enfant sans année
  ```

### RGPD-02 — Pièces jointes des échanges jamais supprimées du disque

- **Fichier :** `includes/class-psc-conversations.php:400-409` (`delete_for_family`), `:415-431` (`purge_old`), `:612-641` (stockage).
- **Priorité :** Élevée. Vérifié par le lead : aucun `unlink` ne vise `piece_jointe_path`.
- **Statut (branche `audit-fixes`) :** Corrigé — eb58030.
- **Explication :**
  - La purge à 395 j, la suppression d'une famille et l'effaceur RGPD suppriment les lignes, mais pas les fichiers (PDF et images, parfois des certificats médicaux).
  - Ces fichiers deviennent orphelins : impossibles à exporter ou à effacer ensuite.
  - « sécurité » a confirmé qu'aucun chemin applicatif ne permet de les relire.
- **Correction :**
  ```php
  private static function unlink_attachments(array $conv_ids) {
      global $wpdb; if (!$conv_ids) return;
      $ph = implode(',', array_fill(0, count($conv_ids), '%d'));
      foreach ($wpdb->get_col($wpdb->prepare('SELECT piece_jointe_path FROM ' . psc_table('conversation_messages')
          . " WHERE conversation_id IN ($ph) AND piece_jointe_path IS NOT NULL", $conv_ids)) as $rel) {
          $abs = psc_private_path($rel); if ($abs && is_file($abs)) @unlink($abs);
      }
  }
  // appeler avant les DELETE de delete_for_family() et purge_old()
  ```
- **Impact secondaire (sécurité) :** stock de documents sensibles en cas de fuite du répertoire privé.

### RGPD-03 — L'effaceur et l'export WordPress ignorent `psc_requests` et `psc_message_destinataires`

- **Fichier :** `includes/class-psc-privacy.php:338-387` et `:401-410`, `includes/class-psc-admin-familles.php:192-198` (oubli reconnu en commentaire), `includes/class-psc-requests.php:1046` (`handle_reject`), `:967-978`.
- **Priorité :** Élevée.
- **Statut (branche `audit-fixes`) :** Corrigé — 66d966f, 27517b1.
- **Explication :**
  - Une famille jamais validée obtient un export vide et un effacement « rien trouvé », alors que sa demande (enfants, adresse, IBAN chiffré, message) reste en base.
  - Pour une famille validée puis effacée, la demande approuvée reste 90 j avec `children_json`.
  - `message_destinataires` (family_id, jeton, dates de lecture) n'est jamais supprimée, et aucune clé étrangère ne la couvre. L'effaceur répond pourtant « données supprimées ».
  - Une demande refusée garde l'IBAN chiffré 90 j. Une demande approuvée garde le titulaire et l'adresse SEPA.
- **Correction :**
  ```php
  foreach (array_filter(array($parent->email, $parent->second_parent_email)) as $e) {
      foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . psc_table('requests') . ' WHERE email = %s OR second_parent_email = %s', $e, $e)) as $rid) {
          Psc_Assurances::delete_pending_files($rid); $wpdb->delete(psc_table('requests'), array('id' => $rid));
      }
  }
  $wpdb->delete(psc_table('message_destinataires'), array('family_id' => (int) $parent->id), array('%d'));
  // handle_reject() et approve_request() : sepa_iban, sepa_bic, sepa_titulaire, sepa_adresse, sepa_code_postal, sepa_ville => null
  // foreign_key_map : array('message_destinataires', 'family_id', 'parents', 'CASCADE')
  ```

### RGPD-04 — Journal d'audit : données personnelles qui échappent à l'anonymisation et à la purge

- **Fichier :**
  - `includes/class-psc-retention.php:87-90` (nom de l'enfant purgé) ;
  - `includes/class-psc-requests.php:653`, `:786`, `:1060` (e-mail en clair, `famille_id` NULL) ;
  - `includes/class-psc-parents.php:154`, `:184`, `:241`, `:252` ;
  - `includes/class-psc-frontend-profil.php:136` ;
  - `includes/class-psc-admin-familles.php:67-71` et `:83-87` ;
  - `includes/class-psc-audit.php:632-640` (`forget_family` garde `ip`).
- **Priorité :** Élevée (priorité ajustée : « données » l'avait classé Critique ; la lecture du journal est réservée à `psc_view_audit`). Vérifié par le lead.
- **Statut (branche `audit-fixes`) :** Corrigé — 6588bc5, a9f94d3, ba237d3.
- **Explication :**
  - La purge automatique écrit le **prénom et le nom** de l'enfant au moment où elle efface sa fiche, et ce journal est conservé 365 j.
  - La suppression manuelle consigne nom, prénom et date de naissance au niveau critique, conservé 1095 j.
  - Les lignes `demande.*` portent l'e-mail sans `famille_id` : `forget_family()` ne les retrouve jamais.
  - L'IP de la famille survit à l'anonymisation, tout comme l'e-mail du second parent après son effacement.
  - AGENTS §7 exige des journaux sans données personnelles.
- **Correction :**
  ```php
  'resume' => sprintf(__('Fiche enfant #%1$d purgée automatiquement (conservation de %2$d jours expirée).', 'periscolaire-registration'), (int) $row->id, $retention_days),
  'resume' => sprintf(__('Demande d’inscription #%d validée.', 'periscolaire-registration'), (int) $req->id),
  // forget_family() : 'ip' => null, 'pour_le_compte_de' => null dans l'UPDATE
  // erase_second_parent() : Psc_Audit::forget_family($parent->id, array($second_email, $second_prenom, $second_nom))
  // Psc_Audit::forget_child($child_id) appelée par purge_child()
  ```
- **Impact secondaire (performance) :** voir PERF-05 pour le coût de réécriture de la chaîne.

### RGPD-05 — Export RGPD incomplet

- **Fichier :** `includes/class-psc-privacy.php:132-213`, `:552`, `:586-591`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Corrigé — d486d05.
- **Explication :**
  - Absents de l'export : présences et heures, historique des personnes autorisées, demandes, consultations par la mairie, lignes d'audit, fichiers.
  - Le **contenu** des allergies et des messages, fournis par la famille elle-même, est remplacé par « omis ». Le droit d'accès (art. 15) porte pourtant sur ces contenus.
  - Une recherche sur l'e-mail du second parent renvoie tout le foyer, y compris l'adresse et le téléphone du titulaire.
- **Correction :** ajouter les groupes `psc-presences`, `psc-demandes`, `psc-historique-autorises` et `psc-consultations`, restituer `food_allergies` et `corps`, et limiter le profil au second parent quand c'est lui qui est visé.
  ```php
  $rows = $wpdb->get_results($wpdb->prepare('SELECT jour_date, service, present, arrival_time, departure_time FROM ' . psc_table('attendance') . ' WHERE child_id = %d', $child->id));
  ```

### RGPD-06 — L'écran intervenants transmet plus de données de santé et de tiers que nécessaire

- **Fichier :** `includes/class-psc-sidscm.php:419-426`, `:549-559`, `:578-590` ; `assets/js/sidscm.js:277`, `:407`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Corrigé — 1c2c9d7.
- **Explication :**
  - `ajax_data` renvoie pour chaque enfant attendu, sur 9 semaines consultables, le **texte libre des allergies**, quel que soit l'onglet. Le JS ne l'affiche pourtant qu'en cantine.
  - Il renvoie aussi les téléphones des personnes autorisées pour toute la semaine.
  - Un intervenant de garderie reçoit donc des données de santé inutiles à sa mission.
- **Correction :**
  ```php
  'allergies' => (!empty($per_service['CANT']) || !empty($per_service['MSR'])) && $allergies !== '' ? $allergies : null,
  if (!empty($per_service['GS']) && $monday === $current_week) $needs_authorized[] = (int) $c->id;
  ```
- **Impact secondaire (sécurité) :** portée d'un code intervenant divulgué (SEC-03).

### RGPD-07 — IBAN complet lisible et modifiable avec la seule habilitation « Familles »

- **Fichier :** `templates/admin-parents.php:89-90`, `includes/class-psc-admin-familles.php:480-510` et `:522`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Corrigé — a6229d3, 7995a2a.
- **Explication :** le formulaire d'édition pré-remplit l'IBAN déchiffré, et `psc_manage_families` suffit pour l'ouvrir. Partout ailleurs, l'IBAN est masqué (`admin-parents.php:178`, `admin-requests.php:96`, `portal-profil.php:112`).
- **Correction :**
  ```php
  <?php if (current_user_can('psc_manage_billing')): ?> <!-- champ vide = inchangé, placeholder masqué -->
    <input name="sepa_iban" value="" placeholder="<?php echo esc_attr(psc_mask_iban(psc_read_iban($edit_parent))); ?>">
  <?php else: ?><td><?php echo esc_html(psc_mask_iban(psc_read_iban($edit_parent))); ?></td><?php endif; ?>
  // handle_edit_parent() : ignorer sepa_* si !current_user_can('psc_manage_billing')
  ```
- **Impact secondaire (sécurité) :** cloisonnement des habilitations (PRA-02).

### RGPD-08 — Conservation décennale des factures annoncée mais non bornée

- **Fichier :** `includes/class-psc-privacy.php:107` et `:383`, `includes/helpers/retention.php:15`, `includes/class-psc-invoices.php:496-554`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Corrigé — décision du 15/10/2026 : 10 ans révolus après la fin de l'année de facturation (10 ans minimum), purge quotidienne désactivée par défaut et activable dans Réglages › Confidentialité ; bin/verify-invoice-retention.php.
- **Explication :** la décision de ne jamais réécrire une facture est respectée. Mais rien ne supprime les factures, leurs versions (`lines_json` avec les noms des enfants) ni les PDF au terme annoncé (10 ans).
- **Correction :** purge annuelle désactivée par défaut, avec une durée validée par le DPO.
  ```php
  $limit = gmdate('Y-m', strtotime('-' . (int) get_option('psc_invoice_retention_years', 10) . ' years'));
  // SELECT pdf_path … WHERE mois < %s → unlink ; DELETE invoice_versions puis invoices ; puis parents anonymisés sans facture
  ```

### RGPD-09 — Diffusions : jeton en clair, sans expiration, présent dans l'URL

- **Fichier :** `includes/class-psc-installer.php:1256`, `includes/class-psc-mailer.php:11`, `includes/class-psc-messages-frontend.php:98-124`.
- **Priorité :** Moyenne. L'exploitabilité a été contestée et ramenée à Faible par « sécurité » : environ 190 bits, contenu collectif. La conformité reste en cause.
- **Statut (branche `audit-fixes`) :** Corrigé — 3463198 (jeton retiré de l'URL) ; lien valable 90 jours après l'envoi du message (décision du 15/10/2026). Stockage du jeton en clair : risque accepté, le jeton n'ouvre aucune session et n'affiche que ce message ; le hacher obligerait à invalider le lien de l'e-mail précédent à chaque relance.
- **Explication :** `message_destinataires.token` (CHAR 32) est stocké en clair et n'expire jamais. La page `?psc_msg=&t=` est rendue sans redirection, donc le jeton reste dans l'historique du navigateur. Cela contredit AGENTS §7 (jeton haché, durée courte).
- **Correction :** stocker `hash_hmac('sha256', $token, wp_salt('psc_token'))`, rechercher par empreinte, et rediriger après lecture pour retirer le jeton de l'URL.

### RGPD-10 — Allergies détaillées héritées : en clair, invisibles pour la famille, envoyées par e-mail

- **Fichier :** `includes/class-psc-installer.php:1003`, `includes/class-psc-requests.php:766-779`, `includes/class-psc-mailer.php:776-779`, `documentation/rgpd.md:59`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Corrigé — a647988 (détail retiré de l'e-mail mairie) ; texte libre supprimé partout (décision du 15/10/2026) : migration 4.20.0 qui convertit tout texte en signalement puis supprime la colonne, rapprochement des allergies retiré, écrans fondés sur le signalement ; la commande fournisseur ne dépend que du statut « Cantine sans repas » posé par la mairie (ni repas ni goûter), le doublon par allergie étant supprimé.
- **Explication :**
  - Le parcours ne collecte plus le détail médical. Pourtant `food_allergies` (TEXT en clair) reste peuplé pour les fiches anciennes, et peut être recopié par le rapprochement mairie.
  - La famille ne peut ni le voir, ni le corriger, ni l'effacer.
  - L'e-mail « nouvelle demande » envoie ce texte en clair dans la boîte de la mairie, contrairement à `notify_food_allergy`.
- **Correction :** faire trancher le DPO. D'ici là, remplacer le détail par un badge dans l'e-mail, et chiffrer la colonne (`psc_encrypt()`) ou offrir à la famille un bouton « retirer la description ».
  ```php
  if (!empty($c['food_allergies']) || !empty($c['food_allergy_signal'])) $badges[] = __('échange alimentation à prévoir', 'periscolaire-registration');
  ```

### RGPD-11 — Attestations d'assurance des années passées conservées toute la vie de la fiche

- **Fichier :** `includes/class-psc-admin-familles.php:100-113`, `includes/class-psc-school-years.php:195-210`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — décision du 15/10/2026 : attestation conservée jusqu'au 30 septembre qui suit la fin de son année scolaire puis supprimée (fichier et colonnes `assurance_*`), l'année active étant toujours conservée ; purge quotidienne, bin/verify-insurance-retention.php.
- **Explication :** une attestation ne sert que l'année concernée. Un enfant scolarisé 6 ans en accumule 6.
- **Correction :** à l'archivage d'une année plus vieille d'un an, supprimer les fichiers `assurance_file_path` (`unlink`) et vider les colonnes `assurance_*`.

### RGPD-12 — Contenu des échanges recopié par défaut dans les e-mails

- **Fichier :** `includes/helpers/settings.php:90`, `includes/class-psc-mailer.php:819-837` et `:855-857`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — décision du 15/10/2026 : texte des messages non recopié par défaut ; la mairie peut l'activer (Réglages › Notifications).
- **Explication :** l'option `psc_conversations_contenu_email` vaut 1 par défaut. Les copies dans les boîtes de réception échappent à la purge à 395 j et à l'effacement. Ce choix est documenté, mais il va à l'inverse de la protection par défaut.
- **Correction :** `get_option('psc_conversations_contenu_email', 0)`.

### RGPD-13 — L'effaceur et la suppression manuelle ne laissent pas le même reliquat

- **Fichier :** `includes/class-psc-privacy.php:296-302` et `:373-377`, `includes/class-psc-admin-familles.php:200-204`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — 504dfbc.
- **Explication :** l'effaceur anonymise toujours la ligne parent, même quand il n'existe aucune facture, alors que la suppression manuelle la supprime dans ce cas. Le commentaire promet « le même reliquat ».
- **Correction :** faire appeler `Psc_Admin_Familles::delete_family()` par `erase_household()`.

### RGPD-14 — Le registre des durées de conservation n'est relié à aucune purge

- **Fichier :** `includes/class-psc-retention.php:22-45` (`run()` sans appelant), `includes/helpers/retention.php`, `documentation/rgpd.md:36`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — e8a2511, 583e56b, 7a22f31 — registre aligné sur les purges réelles, simulation seule.
- **Explication :** `psc_retention_policies()` décrit des durées que rien n'applique. La documentation annonce `Psc_Retention::run()`, qui n'est jamais appelée. Le registre est décoratif : un réglage modifié n'a aucun effet.
- **Correction :** brancher `run()` sur le cron `psc_purge_departed_children`, ou supprimer le registre et documenter les purges réelles.

> **Hors défauts, décision de la mairie :** les factures émises ne sont jamais réécrites ni supprimées, même lors d'un effacement RGPD. Seule leur borne de conservation est à fixer (RGPD-08).

---

## 3. Performance

Contexte de mesure : MySQL 8.0, 35 enfants. Volume réel : une école rurale, quelques dizaines de familles.

### PERF-01 — `close_range()` ferme jour par jour, avec un e-mail par famille et par jour dans la requête HTTP

- **Fichier :** `includes/class-psc-school-calendar.php:637-654` (et `close_day()` `:603-628`), appelé par `includes/class-psc-admin-school-years.php:347-355`.
- **Priorité :** Élevée.
- **Statut (branche `audit-fixes`) :** Corrigé — 4c065ad.
- **Explication :**
  - Pour chaque jour, `close_day()` recalcule les familles touchées (environ 8 requêtes), écrit la fermeture, puis envoie un `wp_mail()` synchrone par famille.
  - 5 jours × 50 familles = environ 250 envois SMTP via le relais OVH, soit 2 à 8 minutes. C'est au-delà de `max_execution_time`.
  - Si le processus est tué, les jours restants **ne sont pas fermés** et restent facturables, sans reprise possible.
  - Chaque famille reçoit un e-mail par jour au lieu d'un récapitulatif, alors que `affected_families_range()` existe déjà.
- **Correction :**
  ```php
  public static function close_range($debut, $fin, $label) {
      $affected = self::affected_families_range($debut, $fin);            // un seul calcul
      $wpdb->query('START TRANSACTION');
      foreach ($period as $d) self::mark_closed($d->format('Y-m-d'), $label); // upsert, sans e-mail
      $wpdb->query('COMMIT');
      self::flush_closed_cache();
      Psc_Envois::lancer('fermeture', $closure_id, Psc_Envois::lot(), array_keys($affected['families'])); // 1 récapitulatif, hors requête
  }
  ```
- **Impact secondaire :** pratiques (e-mails répétés) ; données (risque de facturer des jours fermés).

### PERF-02 — Envois groupés synchrones : factures, menus, notifications de fermeture

- **Fichier :** `includes/class-psc-admin-invoices.php:226-260`, `includes/class-psc-menus.php:229-234`, `includes/class-psc-envois.php:101-108` et `:197-227`, `includes/class-psc-school-calendar.php:620-624` et `:768-778`.
- **Priorité :** Moyenne (Élevée à partir d'environ 100 familles).
- **Statut (branche `audit-fixes`) :** Corrigé — 876d210.
- **Explication :**
  - Avec 60 familles et un envoi SMTP de 0,5 à 2 s (PDF joint), l'écran dure 30 à 120 s et finit souvent en erreur 504.
  - `Psc_Envois` reprend les envois laissés en plan, mais le bilan affiché est faux et la secrétaire relance.
  - Les notifications de fermeture n'ont aucune reprise.
  - Le bon modèle existe déjà dans le plugin : `Psc_Messages::send()` envoie par lots de 50 via `wp_schedule_single_event`.
- **Correction :**
  ```php
  public static function lancer($type, $objet_id, $lot, $famille_ids = null, $inline_max = 5) {
      foreach ($targets as $fid) self::reserver($type, $objet_id, $lot, $fid);
      self::traiter($type, $objet_id, $lot, array(self::A_ENVOYER), $inline_max);
      if (self::bilan($type, $objet_id, $lot)[self::A_ENVOYER] > 0)
          wp_schedule_single_event(time() + 5, 'psc_envois_lot', array($type, (int) $objet_id, $lot));
      return self::bilan($type, $objet_id, $lot); // l'écran affiche « N en cours »
  }
  ```

### PERF-03 — `maybe_upgrade()` rejoue le chemin admin à chaque appel admin-ajax ou admin-post, y compris côté famille

- **Fichier :** `includes/class-psc-installer.php:118-124` (`has_pending_work()` repose sur `is_admin()`), `:108-115`, `:149-151`, `:573-583`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Corrigé — bdfdc66.
- **Explication :**
  - `is_admin()` est vrai sur `admin-ajax.php` et `admin-post.php`.
  - Chaque clic du planning, chaque pointage et chaque interrogation des notifications (toutes les 30 s par onglet) provoque donc 2 écritures dans `wp_options` (verrou), la vérification du répertoire privé et `store_constraints_state()` (mesuré : **10 requêtes, environ 3,4 ms**, dont `information_schema`).
  - C'est l'inverse de ce qu'annonce le commentaire de la ligne 147.
- **Correction :**
  ```php
  protected static function is_admin_screen() {
      return is_admin() && !wp_doing_ajax() && !(defined('DOING_CRON') && DOING_CRON)
          && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'admin-post.php' && current_user_can(psc_manage_cap());
  }
  ```

### PERF-04 — Le portail famille connecté est rendu deux fois par page

- **Fichier :** `includes/class-psc-frontend.php:27`, `:58-61`, `:595`.
- **Priorité :** Moyenne. Vérifié par le lead.
- **Statut (branche `audit-fixes`) :** Corrigé — e33843d.
- **Explication :** `do_shortcode` (priorité 11) rend le portail, puis le filtre du plugin (priorité 20) jette ce rendu et rappelle `shortcode()`. Mesure : 56 requêtes et 28 ms, puis 41 requêtes et 17 ms. Environ 40 % du coût d'une page est perdu.
- **Correction :**
  ```php
  public static function shortcode($atts) {
      static $html = null;
      if ($html !== null) return $html;
      // … rendu actuel …
      return $html = ob_get_clean();
  }
  ```

### PERF-05 — `forget_family()` peut réécrire tout le journal d'audit ligne à ligne, sans limite de temps

- **Fichier :** `includes/class-psc-audit.php:596-668`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Corrigé — 1987a3c.
- **Explication :**
  - Réécrire un `resume` change l'empreinte de cette ligne et de **toutes les suivantes**, soit environ 40 000 lignes par an : autant d'`UPDATE` dans la requête HTTP.
  - Si le processus est interrompu, la chaîne est cassée et `verify_chain()` signale une rupture fictive.
- **Correction :** ne pas réécrire `resume`, mais seulement vider `details`, `famille_id`, `enfant_id` et `ip`, qui ne sont pas hachés : le rechaînage disparaît. À défaut, rechaîner par lots (`UPDATE … CASE id`), avec `ignore_user_abort(true)` et un curseur repris par cron.

### PERF-06 — Lectures répétées sans mémoïsation dans le portail

- **Fichier :** `includes/class-psc-school-years.php:32-42` et `:278-316`, `includes/class-psc-frontend-inscriptions.php:186-193`, `templates/portal-planning-2.php:58-59`, `:98-102`, `:158`, `:354`, `templates/portal-enfants.php:24`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — acd61e6.
- **Explication :** sur un rendu de 41 requêtes, environ 25 sont redondantes : année active ×11, `child_school_years` ×8, `children_of` ×4, menus de la semaine ×4.
- **Correction :**
  ```php
  private static $active = false;
  public static function active() {
      if (self::$active === false) { global $wpdb; self::$active = $wpdb->get_row("SELECT * …"); }
      return self::$active; // vider dans activate(), archive(), create(), delete()
  }
  ```

### PERF-07 — `allergies_to_reconcile()` : N+1 à chaque affichage de l'écran Demandes

- **Fichier :** `includes/class-psc-requests.php:674-728`, appelé par `includes/class-psc-admin-requests.php:34` et `:760`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — 2a9a3f1.
- **Explication :** la fonction fait 1 requête, puis 2 par demande approuvée, jusqu'à 201 requêtes, alors que la jointure initiale fournit déjà `p.id`.
- **Correction :** `SELECT r.*, p.id AS parent_id …`, puis charger les enfants en un seul `WHERE parent_id IN (…)` et les regrouper par parent.

### PERF-08 — Interrogation des notifications toutes les 30 s, même onglet masqué

- **Fichier :** `assets/js/psc-message-notifications.js:66-71`, `includes/class-psc-messages-frontend.php:49-71`, `includes/class-psc-messages.php:255-258`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — 383d45f.
- **Explication :** l'interrogation représente 120 requêtes admin-ajax par heure et par onglet. Chacune charge le `corps` LONGTEXT de **tous** les messages pour n'en garder que 10.
- **Correction :**
  ```js
  document.addEventListener('visibilitychange', function () { document.hidden ? stopPolling() : startPolling(); });
  ```
  Côté serveur : `SELECT m.id, m.titre, LEFT(m.corps, 400) … AND d.vu_le IS NULL ORDER BY m.date_envoi DESC LIMIT 10`.

### PERF-09 — Facturation du mois : double résolution et PDF reconstruits pour des brouillons inchangés

- **Fichier :** `includes/class-psc-invoices.php:40-100` (`generate_month`), `:106-286` (`generate_one`).
- **Priorité :** Faible (Moyenne au-delà d'environ 150 familles).
- **Statut (branche `audit-fixes`) :** Corrigé — 4591ee1.
- **Explication :** `declared_map()` est calculé pour tous les enfants, puis recalculé par famille. Le PDF FPDF est reconstruit même quand l'instantané n'a pas changé. Pour 60 familles, cela représente environ 600 requêtes et 60 PDF.
- **Correction :** passer la tranche `$declared` à `generate_one()`, puis :
  ```php
  if ($existing && !self::snapshot_differs($existing, $snapshot) && file_exists($pdf)) return $invoice_id;
  ```
  Ajouter aussi une garde `isset` dans `preload_closed()`.

### PERF-10 — Coût d'initialisation de `maybe_upgrade()` sur chaque page publique du site

- **Fichier :** `includes/class-psc-installer.php:100-102`, `:122-123`, `:364` ; `includes/helpers/files.php:145-183`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — ad73dd2.
- **Explication :** à chaque page du site communal, même hors portail, `maybe_upgrade()` lit 2 options non autoloadées et fait environ 8 appels système (`stat`) pour `psc_ensure_private_dir()`.
- **Correction :** passer `psc_private_dir_path` en autoload, regrouper l'état dans une option `psc_install_state` autoloadée, et appeler `psc_ensure_private_dir()` seulement au moment d'écrire un document.

### PERF-11 — Import ICS : deux requêtes par jour importé

- **Fichier :** `includes/class-psc-school-calendar.php:325-343`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — d457d99.
- **Explication :** l'import fait un `SELECT source` puis un upsert par jour, soit 1 000 à 3 000 requêtes.
- **Correction :** une seule requête qui préserve la priorité des corrections manuelles (règle métier n°7), idéalement en `INSERT` multi-lignes par paquets de 200.
  ```sql
  INSERT INTO $t (jour_date,label,is_closed,source,created_at,updated_at) VALUES (%s,%s,1,'import',%s,%s)
  ON DUPLICATE KEY UPDATE label = IF(source='manual', label, VALUES(label)),
    is_closed = IF(source='manual', is_closed, 1), updated_at = IF(source='manual', updated_at, VALUES(updated_at))
  ```

### PERF-12 — Tables qui grossissent sans fin, index manquant sur le journal

- **Fichier :** `includes/class-psc-envois.php` (aucune purge), `includes/class-psc-messages.php:216-222` (`cleanup_receipts`), `includes/class-psc-installer.php:1318-1347` (`audit_log`).
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — ef29e86, 79cdefa (purge des envois, marqueur des accusés) ; index `enfant_horodatage` ajouté au schéma 4.20.0 (validé le 15/10/2026).
- **Explication :** `envois` n'est jamais purgée. `cleanup_receipts()` refait chaque jour toutes les années closes. Le filtre « enfant » de l'écran Journal parcourt toute la table.
- **Correction :** ajouter `KEY enfant_horodatage (enfant_id, horodatage)` (DB_VERSION et dbDelta) et `update_option('psc_receipts_cleaned_until', $year->date_fin, false)`, et purger les `envois` de plus de 13 mois.

---

## 4. Bonnes pratiques et qualité

### PRA-01 — AGENTS.md (§2) ne décrit pas le modèle réellement en production

- **Fichier :** `includes/class-psc-installer.php:1084` (`UNIQUE KEY parent_mois (parent_id, mois)`), `includes/class-psc-invoices.php:58-140`, `includes/class-psc-installer.php:942-952`, `AGENTS.md` (non suivi par git).
- **Priorité :** Élevée.
- **Statut (branche `audit-fixes`) :** Corrigé — décision du 15/10/2026 : une facture par foyer et par mois est conservée ; AGENTS.md est aligné sur le modèle réel et versionné.
- **Explication :**
  - Le code produit **une facture par foyer et par mois**, alors qu'AGENTS §2.1 demande une facture par enfant.
  - Il n'existe pas de table de liaison `autorite_parentale` / `responsable_facturation` (§2.4) : un second parent est porté en colonnes.
  - Les réservations n'ont pas d'auteur, donc la règle §2.3 est inapplicable.
  - Le glossaire parle de « trimestres », que le code a supprimés.
  - Un agent qui « respecterait » §2.1 casserait la facturation.
- **Correction :** trancher avec la mairie. Soit on aligne AGENTS.md sur le modèle réel :
  ```md
  1. **Une facture par foyer et par mois** (invoices UNIQUE(parent_id, mois)) — détail par enfant dans les lignes.
  4. Foyer = responsable principal + second parent optionnel (colonnes second_parent_* de wp_psc_parents).
  ```
  Soit on ouvre un chantier de schéma, avec un point d'arrêt (§3.1). Dans les deux cas, il faut versionner AGENTS.md.
- **Impact secondaire :** sécurité (règle §2.3) et données (périmètre de la facture).

### PRA-02 — Capacité requise déduite par sous-chaîne du nom d'action, avec repli sur la capacité globale

- **Fichier :** `includes/helpers/core.php:69-78` (`psc_required_capability`), `includes/class-psc-admin-base.php:19-25`.
- **Priorité :** Élevée. Vérifié par le lead.
- **Statut (branche `audit-fixes`) :** Corrigé — a0bfd2c, 2d03fbf.
- **Explication :**
  - 16 actions ne contiennent aucun mot-clé reconnu et retombent sur `psc_manage_periscolaire`. C'est le cas de `psc_download_pain008` (export **des IBAN en clair**), `psc_payment_received`, `psc_download_general`, `psc_admin_update_registrations`, des jours d'école, des vacances et de la promotion d'année.
  - Un agent habilité au seul domaine voit l'écran, mais reçoit un 403 sur l'action.
  - À l'inverse, tout rôle recevant la capacité de base obtient ces exports.
  - Un mot-clé ajouté plus tard peut élargir un droit sans que personne s'en aperçoive.
  - La matrice de tests ne vérifie que les refus.
- **Correction :** table explicite et refus par défaut, avec des tests d'**accès légitime** par domaine.
  ```php
  function psc_required_capability($action) {
      $map = array(
          'psc_download_pain008' => 'psc_manage_billing', 'psc_payment_received' => 'psc_manage_billing',
          'psc_download_general' => 'psc_manage_billing', 'psc_admin_update_registrations' => 'psc_manage_presence',
          'psc_close_school_day' => 'psc_manage_config', /* … toutes les actions guard() … */
      );
      return $map[sanitize_key($action)] ?? 'do_not_allow';
  }
  ```
- **Impact secondaire (sécurité) :** élargissement silencieux de droits ; IBAN en clair accessibles hors habilitation « Facturation ».

### PRA-03 — Signalement alimentaire perdu pour les enfants 2 à 5 du formulaire d'inscription

- **Fichier :** `assets/js/guest.js:275-283`, `templates/guest-request.php:158`, `includes/class-psc-requests.php:356`.
- **Priorité :** Élevée. Vérifié par le lead.
- **Statut (branche `audit-fixes`) :** Corrigé — aa806c2.
- **Explication :**
  - L'enfant 0 (rendu par PHP) porte la case `child_food_signal_0`, seule lue par le serveur.
  - Les lignes ajoutées en JS portent à la place une zone de texte `child_food_allergies_N` et une case de consentement, que le serveur ignore.
  - Pour ces enfants, le signalement n'atteint jamais la mairie et l'alerte PAI ne part pas. La famille saisit en plus des données de santé qui sont perdues.
- **Correction :** une seule source de balisage.
  ```php
  <template id="psc-child-row-tpl"><?php include PSC_PATH . 'templates/partials/guest-child-row.php'; ?></template>
  ```
  ```js
  var html = document.getElementById('psc-child-row-tpl').innerHTML.replace(/__IDX__/g, idx);
  ```
- **Impact secondaire (données) :** collecte de santé non conforme à la minimisation, et perte d'une information de sécurité pour l'enfant.

### PRA-04 — Les pièces jointes envoyées par les familles sont invisibles côté mairie

- **Fichier :** `templates/admin-conversation.php:32-41`, `includes/class-psc-conversations-frontend.php:18-19` et `:415`, `includes/class-psc-conversations.php:611-661`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Corrigé — c5b1efc, e7d69f4.
- **Explication :** une famille peut joindre un fichier, mais aucune route admin ne permet à la secrétaire de le télécharger. La fonctionnalité est livrée à moitié.
- **Correction :**
  ```php
  add_action('admin_post_psc_download_conversation_attachment', function () {
      if (!current_user_can('psc_manage_messages')) wp_die('', '', 403);
      $id = psc_get_int('message_id'); check_admin_referer('psc_download_conversation_attachment_' . $id);
      Psc_Conversations::stream_attachment($id);
  });
  ```

### PRA-05 — Écrans « Messages » : codes techniques et texte libre de l'URL affichés tels quels

- **Fichier :** `templates/admin-messages-edit.php:8`, `includes/class-psc-messages-admin.php:129-131`, `templates/admin-messages-list.php:23` et `:25`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Corrigé — bf94678.
- **Explication :**
  - La secrétaire lit « test_sent » dans la notice, et « Envoye » ou « Ecole » dans la liste (`ucfirst` sans traduction ni accents).
  - `$_GET['psc_msg']` étant affiché tel quel, même échappé, un lien forgé peut faire afficher un texte arbitraire dans une notice officielle.
- **Correction :**
  ```php
  $psc_notices = array('saved' => array('success', __('Brouillon enregistré.', 'periscolaire-registration')), /* … */);
  psc_admin_notice_map($psc_notices, sanitize_key(wp_unslash($_GET['psc_msg'] ?? '')));
  ```
- **Impact secondaire (sécurité, Faible) :** usurpation de message dans le back-office.

### PRA-06 — Écrans entiers non traduisibles

- **Fichier :** `includes/class-psc-admin-assurances.php:10`, `:16`, `:30` ; `templates/admin-assurances.php` (17 chaînes) ; `templates/portal-assurance-gate.php` ; `templates/admin-settings.php:215-222` ; `templates/admin-messages-edit.php:18-32` ; `templates/admin-messages-stats.php:3-6` ; `assets/js/psc-messages-admin.js:14-41`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Corrigé — a553f2d, a0b13a0, e3d3cce, ca90e9e.
- **Explication :** les modules assurances et messages, JS compris, sont écrits en dur. Par ailleurs, la table `admin-assurances.php:28` n'a ni `<caption>` ni `scope` (RGAA, AGENTS §5).
- **Correction :**
  ```php
  <h1><?php esc_html_e('Assurances scolaires', 'periscolaire-registration'); ?></h1>
  ```
  ```js
  $('#psc-preview-title').text($title.val() || PSC_MESSAGES.i18n.title_placeholder); // via wp_localize_script
  ```

### PRA-07 — Qualité i18n : commentaires translators, marqueurs, pluriels, concaténations

- **Fichier :** `includes/class-psc-frontend.php:461`, `:479-480` ; `includes/class-psc-invoices.php:867` et `:871` ; `includes/class-psc-mailer.php:295`, `:309`, `:560`, `:564` ; `includes/class-psc-admin-familles.php:70`, `:86`, `:213`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Corrigé — b349539, aafab39, b07a991, 7bb2b06.
- **Explication :**
  - 170 des 191 chaînes à marqueur n'ont pas de commentaire `translators`.
  - 42 chaînes ont plusieurs `%s` non numérotés.
  - Les pluriels sont faits à la main (`'%d jour%s déclaré%s'`, 46 « (s) »).
  - On trouve des concaténations (`__('Famille ') . $nom`) et du HTML dans les msgid.
  - `load_plugin_textdomain` pointe vers un dossier `/languages` absent.
- **Correction :**
  ```php
  /* translators: 1: nombre de jours, 2: montant en euros */
  sprintf(_n('%1$d jour déclaré · %2$s €', '%1$d jours déclarés · %2$s €', $n, 'periscolaire-registration'), $n, number_format_i18n($amount, 2));
  ```
  Ajouter en CI `wp i18n make-pot` ou la règle `WordPress.WP.I18n`.

### PRA-08 — 7 liens vides dans le menu d'administration

- **Fichier :** `includes/class-psc-admin.php:172`, `:175`, `:178`, `:205` ; `includes/class-psc-conversations-admin.php:26` ; `includes/class-psc-messages-admin.php:31-32`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Corrigé — 0d798d8.
- **Explication :** des sous-pages sont enregistrées avec `menu_title = null`. WordPress génère quand même un `<a>` sans intitulé, atteignable au clavier (RGAA 6.2).
- **Correction :**
  ```php
  add_action('admin_head', function () {
      foreach (array('psc_pickup_persons','psc_impersonate','psc_reglement_version','psc_passage_annee','psc_conversation','psc_message_edit','psc_message_stats') as $s)
          remove_submenu_page('psc_dashboard', $s);
  });
  ```

### PRA-09 — En-têtes `no-store` émis depuis le callback du shortcode, donc trop tard

- **Fichier :** `includes/class-psc-frontend.php:608-613`, `includes/class-psc-sidscm.php:116-120`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Corrigé — 74fafc8, a874df5, 294f4d4.
- **Explication :** le shortcode s'exécute après l'envoi du `<head>`. Les en-têtes ne partent aujourd'hui que grâce au tampon de gabarit de WP ≥ 6.9. Sur une version antérieure (le plugin déclare 5.8), les pages famille partent sans `no-store`, sans aucune erreur.
- **Correction :**
  ```php
  add_action('template_redirect', function () {
      $p = is_singular() ? get_post() : null;
      if ($p && (has_shortcode($p->post_content, 'periscolaire_form') || has_shortcode($p->post_content, 'periscolaire_sidscm'))) {
          header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0', true); nocache_headers();
      }
  });
  ```
- **Impact secondaire (sécurité) :** un cache partagé pourrait conserver une page famille.

### PRA-10 — Notifications hors modèles éditables ; l'envoi de facture contourne le Mailer

- **Fichier :** `includes/class-psc-mailer.php:149-166`, `:293-319`, `:556-686`, `:695-717`, `:749-757` ; `includes/class-psc-invoices.php:865-890`.
- **Priorité :** Moyenne.
- **Statut (branche `audit-fixes`) :** Corrigé — a4d866b.
- **Explication :**
  - AGENTS §8 demande que chaque notification soit un modèle éditable par la secrétaire. Ce n'est pas le cas pour une dizaine d'entre elles : fermeture, annulation de cantine, changement d'e-mail, activation SEPA, etc.
  - `Psc_Invoices::deliver()` reconstruit sa propre mise en page et l'en-tête `From:`, que l'on retrouve ainsi à 3 endroits.
- **Correction :** ajouter les clés `day_closed`, `service_closed`, `cantine_cancelled`, `email_change`, `sepa_enabled`… à `Psc_Email_Templates::defaults()`. Exposer `Psc_Mailer::send_html()` en public et l'utiliser dans `deliver()`.

### PRA-11 — Intitulés de section du menu non stylés hors des écrans du plugin

- **Fichier :** `includes/class-psc-admin.php:155-196` et `:304-307`, `assets/css/admin.css:118` et `:162-177`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — 788da11.
- **Explication :** `admin.css` n'est chargé que sur les écrans `psc_`. Partout ailleurs, les 6 intitulés apparaissent comme des liens cliquables. `admin.css:118` vise aussi un slug supprimé.
- **Correction :** `wp_add_inline_style('admin-menu', '#adminmenu .psc-menu-section{pointer-events:none;…}')` chargé sur tous les écrans d'administration.

### PRA-12 — URL du portail : option jamais écrite, recherche dupliquée, absence mise en cache 24 h

- **Fichier :** `includes/class-psc-mailer.php:24-49`, `includes/class-psc-sidscm.php:59-84`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — f61b5c9, 509f06f.
- **Explication :**
  - `psc_form_page_id` n'est jamais écrite : on retombe toujours sur une recherche mise en cache 24 h, même quand elle n'a rien trouvé.
  - Une page publiée après coup laisse les liens magiques pointer vers l'accueil pendant un jour.
  - Cette logique est dupliquée dans `Psc_Sidscm`.
- **Correction :** un helper commun `psc_shortcode_page_id($shortcode, $option)`, l'option exposée dans Réglages, et l'invalidation du cache sur `save_post_page`.

### PRA-13 — Scripts et styles écrits directement dans les gabarits

- **Fichier :** `templates/admin-inscriptions.php:120`, `admin-supplier-orders.php:118`, `portal-enfants.php:229`, `frontend-conversations.php:34`, `:125`, `:236`, `admin-email-templates.php:123` et `:186`, `admin-audit.php:288`, `admin-annees.php:375`, `admin-settings.php:544`, `admin-maintenance.php:57` ; `includes/class-psc-admin.php:389-404`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Mis de côté — refactorisation large sans couverture dédiée.
- **Explication :** environ 230 lignes de JS ne sont ni versionnées, ni lintées, et sont incompatibles avec une CSP. Trois de ces blocs sont côté famille.
- **Correction :** déplacer ce code dans `assets/js/*.js`, chargé conditionnellement, avec les données passées par `wp_localize_script()`.

### PRA-14 — Horloges mélangées (AGENTS §4.1)

- **Fichier :** `includes/class-psc-requests.php:57-80` ; `current_time('timestamp')` à 10 endroits, dont `includes/class-psc-impersonation.php:54`, `:90`, `:381`, `includes/class-psc-conversations.php:418`, `:475`, `:556`, `includes/class-psc-retention.php:70`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — ef1133e, 68465a2, 1402526 (horloges unifiées) ; `impersonations.expires_at` stocké en UTC et comparé à `gmdate()` (validé le 15/10/2026), vérifié en UTC et en Europe/Paris.
- **Explication :**
  - Les dates `created_at` sont en heure du site, mais comparées à `gmdate()` : la purge est décalée de 1 à 2 h.
  - `current_time('timestamp')` est déprécié.
  - `impersonations.expires_at` est stocké en heure du site, alors que la convention veut l'UTC.
- **Correction :**
  ```php
  $cutoff = wp_date('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);         // même horloge que current_time('mysql')
  'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl);                  // et comparer à gmdate()
  ```

### PRA-15 — Code mort et docblocks orphelins

- **Fichier :** `includes/class-psc-frontend-enfants.php:19` (`food_allergies_post`), `includes/class-psc-planning.php:458`, `includes/class-psc-school-year.php:167`, `includes/class-psc-school-calendar.php:684`, `includes/class-psc-installer.php:508`, `includes/class-psc-mailer.php:509`, `includes/helpers/services.php:115`, `includes/helpers/school-calendar.php:21` ; docblocks mal placés : `includes/class-psc-admin.php:408-418`, `includes/helpers/settings.php:83`, `includes/class-psc-mailer.php:500`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — fc0afc8.
- **Explication :** 9 fonctions n'ont aucun appelant, et 7 autres ne servent qu'à l'outillage `bin/` ou `tests/`. `Psc_Retention::run()` relève de RGPD-14.
- **Correction :** supprimer ce code, déplacer les helpers de test dans `tests/`, et activer `checkUncalledPrivateCode` dans PHPStan.

### PRA-16 — Fonctions trop longues sur les chemins critiques

- **Fichier :**
  - `includes/class-psc-requests.php:224` (`handle_submit`, 286 lignes) et `:812` (`approve_request`, 215) ;
  - `includes/class-psc-invoices.php:1046` (`build_pdf`, 219) et `:106` (`generate_one`, 181) ;
  - `includes/class-psc-frontend.php:595` (`shortcode`, 188) ;
  - `includes/class-psc-planning.php:911` (`toggle_pattern`, 163).
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Mis de côté — refactorisation large sans couverture dédiée.
- **Explication :** ces fonctions sont difficiles à tester sans HTTP. `handle_submit` contient à lui seul 25 couples « redirection + exit ».
- **Correction :**
  ```php
  $error = self::validate_submission($_POST, $data); // '' ou 'bad_email' / 'child_incomplete'…
  if ($error !== '') { wp_safe_redirect(add_query_arg('psc_msg', $error, $back)); exit; }
  ```

### PRA-17 — Réglages hors Settings API, journal « champs modifiés » trompeur

- **Fichier :** `includes/class-psc-admin-config.php:63-204`, en particulier `:185` et `:200`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Partiellement corrigé — c05dbad — progression validée, journal des seules options modifiées ; migration vers la Settings API mise de côté.
- **Explication :**
  - 42 options sont écrites par `update_option()` sans `register_setting()`.
  - `psc_classe_progression` accepte n'importe quel texte.
  - L'audit journalise `array_keys($_POST)` comme « clés modifiées », alors que ce sont tous les champs du formulaire.
- **Correction :**
  ```php
  register_setting('psc', 'psc_lock_hours', array('type' => 'integer', 'default' => 48, 'sanitize_callback' => 'absint'));
  $changed = array_keys(array_filter($new, function ($v, $k) { return get_option($k) !== $v; }, ARRAY_FILTER_USE_BOTH));
  ```

### PRA-18 — En consultation (lecture seule), le planning AJAX peut créer une année scolaire

- **Fichier :** `includes/class-psc-frontend-inscriptions.php:91-99`, `includes/class-psc-frontend.php:627-632`, `includes/class-psc-school-year.php:482-498`.
- **Priorité :** Faible.
- **Statut (branche `audit-fixes`) :** Corrigé — 4c45e70.
- **Explication :** le shortcode évite volontairement `ensure_default()` pendant une consultation par la mairie. Le point AJAX en lecture l'appelle pourtant, ce qui écrit en base pendant un simple affichage.
- **Correction :**
  ```php
  if (!Psc_Parents::is_impersonated()) Psc_School_Year::ensure_default();
  ```

---

## 5. Tableau récapitulatif

| # | Priorité | Périmètre | Constat | Fichier | Statut |
|---|---|---|---|---|---|
| SEC-01 | Élevée | Sécurité | Une requête anonyme écrase la demande d'un tiers, jusqu'à la prise de contrôle du foyer | `class-psc-requests.php:262-266, 472-478` | Corrigé |
| SEC-02 | Élevée | Sécurité | Le second parent évince le titulaire en changeant l'e-mail de connexion | `class-psc-frontend-profil.php:63-67` | Corrigé |
| RGPD-01 | Élevée | Données | Aucune conservation bornée pour les foyers, les demandes en attente et les enfants sans année | `class-psc-retention.php:58-66` | Corrigé |
| RGPD-02 | Élevée | Données | Pièces jointes des échanges jamais supprimées | `class-psc-conversations.php:400-431` | Corrigé |
| RGPD-03 | Élevée | Données | Effaceur et export ignorent `requests` et `message_destinataires` | `class-psc-privacy.php:338-410` | Corrigé |
| RGPD-04 | Élevée | Données | Le journal d'audit garde noms, e-mails et IP malgré la purge ou l'effacement | `class-psc-retention.php:87-90` | Corrigé |
| PERF-01 | Élevée | Performance | `close_range()` : e-mails synchrones jour par jour, fermeture partielle possible | `class-psc-school-calendar.php:637-654` | Corrigé |
| PRA-01 | Élevée | Pratiques | AGENTS.md contredit le modèle en production (facture par foyer) | `class-psc-installer.php:1084` | Corrigé |
| PRA-02 | Élevée | Pratiques | Capacité déduite par sous-chaîne ; pain008 (IBAN) hors habilitation | `helpers/core.php:69-78` | Corrigé |
| PRA-03 | Élevée | Pratiques | Signalement alimentaire perdu pour les enfants 2 à 5 | `assets/js/guest.js:275-283` | Corrigé |
| SEC-03 | Moyenne | Sécurité | SIDSCM : sessions non révoquées, codes faibles | `class-psc-sidscm.php:184-264` | Corrigé |
| SEC-04 | Moyenne | Sécurité | Liens magiques réutilisables, jetons de 72 h | `class-psc-parents.php:200-282` | Risque accepté |
| SEC-05 | Moyenne | Sécurité | `docker-compose.prod.yml` : Mailpit exposé, mu-plugins de dev | `docker-compose.prod.yml:26-60` | Mis de côté |
| RGPD-05 | Moyenne | Données | Export RGPD incomplet | `class-psc-privacy.php:132-213` | Corrigé |
| RGPD-06 | Moyenne | Données | SIDSCM : allergies et téléphones envoyés au-delà du besoin | `class-psc-sidscm.php:419-590` | Corrigé |
| RGPD-07 | Moyenne | Données | IBAN en clair avec l'habilitation « Familles » | `templates/admin-parents.php:89-90` | Corrigé |
| RGPD-08 | Moyenne | Données | Conservation des factures non bornée | `class-psc-privacy.php:107` | Corrigé |
| RGPD-09 | Moyenne | Données | Jeton des diffusions en clair, sans expiration | `class-psc-messages-frontend.php:98-124` | Corrigé |
| RGPD-10 | Moyenne | Données | Allergies héritées en clair, envoyées par e-mail | `class-psc-mailer.php:776-779` | Corrigé |
| PERF-02 | Moyenne | Performance | Envois groupés synchrones (factures, menus, fermetures) | `class-psc-envois.php:101-227` | Corrigé |
| PERF-03 | Moyenne | Performance | `maybe_upgrade()` sur chaque appel AJAX famille | `class-psc-installer.php:118-151` | Corrigé |
| PERF-04 | Moyenne | Performance | Portail rendu deux fois | `class-psc-frontend.php:58-61` | Corrigé |
| PERF-05 | Moyenne | Performance | `forget_family()` réécrit tout le journal | `class-psc-audit.php:596-668` | Corrigé |
| PRA-04 | Moyenne | Pratiques | Pièces jointes invisibles côté mairie | `templates/admin-conversation.php:32-41` | Corrigé |
| PRA-05 | Moyenne | Pratiques | Notices et statuts « Messages » bruts ; texte libre de l'URL affiché | `templates/admin-messages-edit.php:8` | Corrigé |
| PRA-06 | Moyenne | Pratiques | Écrans assurances et messages non traduisibles | `templates/admin-assurances.php` | Corrigé |
| PRA-07 | Moyenne | Pratiques | i18n : translators, pluriels, concaténations | `class-psc-frontend.php:461-480` | Corrigé |
| PRA-08 | Moyenne | Pratiques | 7 liens vides dans le menu admin (RGAA) | `class-psc-admin.php:172-205` | Corrigé |
| PRA-09 | Moyenne | Pratiques | `no-store` émis trop tard | `class-psc-frontend.php:608-613` | Corrigé |
| PRA-10 | Moyenne | Pratiques | Notifications hors modèles éditables | `class-psc-mailer.php:149-757` | Corrigé |
| SEC-06 | Faible | Sécurité | Chaîne d'audit sans clé et sans verrou | `helpers/audit.php:854-877` | Partiel |
| SEC-07 | Faible | Sécurité | Signature des sessions dérivable d'un secret en base | `helpers/crypto.php:187-197` | Corrigé |
| SEC-08 | Faible | Sécurité | Login CSRF par lien magique | `class-psc-parents.php:211-282` | Risque accepté |
| SEC-09 | Faible | Sécurité | La consultation par la mairie marque les échanges lus | `class-psc-conversations-frontend.php:53` | Corrigé |
| SEC-10 | Faible | Sécurité | Changement d'e-mail sans limite de débit | `class-psc-parents.php:335-370` | Corrigé |
| SEC-11 | Faible | Sécurité | Import iCal : DNS rebinding | `class-psc-school-calendar.php:173-229` | Corrigé |
| RGPD-11 | Faible | Données | Attestations des années passées conservées | `class-psc-admin-familles.php:100-113` | Corrigé |
| RGPD-12 | Faible | Données | Contenu des échanges dans les e-mails par défaut | `helpers/settings.php:90` | Corrigé |
| RGPD-13 | Faible | Données | Effaceur et suppression : reliquats différents | `class-psc-privacy.php:373-377` | Corrigé |
| RGPD-14 | Faible | Données | Registre de conservation sans effet | `class-psc-retention.php:22-45` | Corrigé |
| PERF-06 | Faible | Performance | Lectures répétées sans mémoïsation | `class-psc-school-years.php:32-42` | Corrigé |
| PERF-07 | Faible | Performance | N+1 dans `allergies_to_reconcile()` | `class-psc-requests.php:674-728` | Corrigé |
| PERF-08 | Faible | Performance | Interrogation toutes les 30 s même onglet masqué | `psc-message-notifications.js:66-71` | Corrigé |
| PERF-09 | Faible | Performance | Facturation : double résolution, PDF reconstruits | `class-psc-invoices.php:40-286` | Corrigé |
| PERF-10 | Faible | Performance | Coût de `maybe_upgrade()` sur toutes les pages | `class-psc-installer.php:100-123` | Corrigé |
| PERF-11 | Faible | Performance | Import ICS : 2 requêtes par jour | `class-psc-school-calendar.php:325-343` | Corrigé |
| PERF-12 | Faible | Performance | Tables sans purge, index `enfant_id` manquant | `class-psc-installer.php:1318-1347` | Corrigé |
| PRA-11 | Faible | Pratiques | Intitulés du menu non stylés hors du plugin | `class-psc-admin.php:304-307` | Corrigé |
| PRA-12 | Faible | Pratiques | URL du portail : cache d'absence de 24 h | `class-psc-mailer.php:24-49` | Corrigé |
| PRA-13 | Faible | Pratiques | JS et CSS écrits dans les gabarits | `templates/*` | Mis de côté |
| PRA-14 | Faible | Pratiques | Horloges mélangées | `class-psc-requests.php:57-80` | Corrigé |
| PRA-15 | Faible | Pratiques | Code mort et docblocks orphelins | `class-psc-frontend-enfants.php:19` | Corrigé |
| PRA-16 | Faible | Pratiques | Fonctions trop longues | `class-psc-requests.php:224` | Mis de côté |
| PRA-17 | Faible | Pratiques | Réglages hors Settings API, journal trompeur | `class-psc-admin-config.php:63-204` | Partiel |
| PRA-18 | Faible | Pratiques | Consultation : le planning AJAX peut créer une année | `class-psc-frontend-inscriptions.php:91-99` | Corrigé |

---

## 6. Les 5 actions à mener en premier

1. **Fermer les deux prises de contrôle de foyer (SEC-01, SEC-02).** Ce sont les seules failles exploitables par un tiers, ou par un co-responsable en conflit, sur des données d'enfants.
   - Une soumission anonyme ne modifie plus jamais une demande existante.
   - Le changement d'e-mail du titulaire est réservé au titulaire, avec un avis et un lien d'annulation envoyés à l'ancienne adresse.
   - L'adresse de connexion est inscrite dans la session, ce qui corrige aussi la traçabilité.
   - Tests Playwright de droits à ajouter : « un visiteur ne peut pas écraser une demande `pending` » ; « une session second parent ne peut pas changer l'e-mail du titulaire ».

2. **Compléter les purges et l'effacement RGPD (RGPD-01, RGPD-02, RGPD-03).**
   - Supprimer les pièces jointes du disque avec leurs messages.
   - Purger les foyers sans enfant, les demandes en attente anciennes et les enfants sans année.
   - Faire couvrir `psc_requests` et `psc_message_destinataires` par l'effaceur, l'export et `delete_family()`.
   - Vider tous les champs bancaires des demandes refusées ou approuvées.
   - Étendre les scripts `bin/verify-*` pour vérifier qu'il ne reste rien après effacement.

3. **Retirer les données personnelles du journal d'audit (RGPD-04, qui supprime au passage PERF-05).**
   - Écrire des résumés par identifiant (`#id`) plutôt que par nom ou e-mail.
   - Renseigner `famille_id` sur les lignes `demande.*`.
   - Vider `ip` à l'anonymisation.
   - Ne plus réécrire `resume`, ce qui supprime le rechaînage complet du journal à chaque effacement.

4. **Remplacer le mappage des capacités par une table explicite à refus par défaut (PRA-02), et réserver l'IBAN à l'habilitation Facturation (RGPD-07).**
   - Les exports pain008 et le formulaire d'IBAN cessent d'être accessibles hors du domaine « Facturation et prélèvements ».
   - Compléter la matrice de tests par des cas d'**accès légitime** par domaine.

5. **Sortir les envois de masse des requêtes HTTP et fiabiliser la fermeture de plage (PERF-01, PERF-02), et réparer le formulaire d'inscription (PRA-03).**
   - Écrire la fermeture en une transaction, puis confier les notifications à `Psc_Envois` avec un récapitulatif par famille.
   - Remplacer le balisage dupliqué de `guest.js` par un `<template>` rendu côté serveur, pour que le signalement alimentaire de chaque enfant atteigne la mairie.

**Décision préalable à prendre avec la mairie (PRA-01) :** aligner AGENTS.md sur le modèle en production (facture par foyer, second parent en colonnes), ou planifier la migration de schéma prévue par §2. Tant que ce point n'est pas tranché, toute évolution guidée par AGENTS.md risque de casser la facturation.
