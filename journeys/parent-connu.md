# Parcours — Parent déjà connu de la mairie

Du formulaire de connexion à l'envoi du récapitulatif de planning. Une entrée
par action **utilisateur** (clic, saisie, navigation, consultation d'e-mail) ;
tout traitement serveur (nonce, AJAX, redirection, envoi de mail) est repris
comme condition d'attente (`wait_for`) de l'étape qui le déclenche.

Source du comportement : `includes/class-psc-parents.php`,
`includes/class-psc-frontend.php`, `includes/class-psc-mailer.php`,
`includes/helpers.php`, `templates/frontend-login.php`,
`templates/frontend-form.php`, `assets/js/frontend.js`.

## Vidéo

Vidéo muette, lue au projecteur : le sous-titre est la seule piste
d'information (pas de voix off). Chaque étape porte trois champs
supplémentaires :
- **narration** : texte du sous-titre affiché pendant cette étape.
- **duration** : durée d'affichage, en secondes.
- **focus** : testid à mettre en avant (zoom/surbrillance) pendant cette
  étape, ou `overlay`/`carton` pour les étapes sans élément d'app à cadrer
  (respectivement : encart hors navigateur type consultation Mailpit ;
  plan de titre/fin plein cadre).

**Contrainte de durée** : `duration >= ceil(longueur(narration) / 12)`,
minimum 3 s dans tous les cas.

Deux étapes encadrent le parcours sans action navigateur :
`00-carton-titre` (avant `01`) et `12-carton-fin` (après `11`).

Une même étape technique peut être revisitée par une seconde scène — une
pause supplémentaire sur le même écran, sans action entre les deux. Dans
ce cas, **narration**/**duration**/**focus** de l'étape deviennent des
listes ordonnées de prises (une seule étape en pratique dans ce
parcours : `09-jour-verrouille-non-cliquable`). À l'inverse, quand une
scène couvre la transition entre deux étapes consécutives, la même
narration/duration/focus est dupliquée sur les deux, annotée « scène
partagée avec … ».

## fixtures

```yaml
parent:
  email: famille.dupont@example.com
  nom: Dupont
  active: 1
  # aucun mot de passe : authentification par lien à usage unique

enfants:
  - prenom: Léo
    nom: Dupont
    classe: CE1
    active: 1
    # index 0 dans la boucle $children → suffixe "-0" dans tous les testid

trimestre:
  label: Trimestre de test
  date_debut: today-3d
  date_fin: today+45d
  active: 1

jours_calendrier:
  # Générés par le seed pour tout le trimestre (is_open=1, hors week-ends/fériés).
  # Deux jours nommés sont utilisés par ce parcours :
  locked_day:
    # premier jour ouvert dont l'échéance de verrouillage est déjà dépassée
    # au moment du test, càd le premier jour ouvré tel que
    # now >= (jour - psc_lock_hours()). Avec le réglage par défaut
    # (psc_lock_hours() = 48h), c'est concrètement le premier jour ouvré
    # dans les 48h qui suivent "today".
    regle: premier jour ouvert avec (jour - 48h) <= today
  open_day:
    # premier jour ouvert confortablement hors du délai de verrouillage,
    # pour ne pas dépendre d'un jour pile à la limite
    regle: premier jour ouvert avec (jour - 48h) > today + 3j

services:
  # psc_services() par défaut (includes/helpers.php) — utilisés tels quels,
  # non redéfinis par ce parcours. Les étapes 07/08/11 dérivent leurs
  # montants attendus de ces prix plutôt que de les recopier en dur.
  GM:   { label: Garderie Matin, price: 1.85 }
  CANT: { label: Cantine,        price: 5.80 }
  GS:   { label: Garderie Soir,  price: 4.70 }
  FORF: { label: Forfait journée, price: 11.70 }
```

## env

```yaml
mailpit:
  smtp: le conteneur wordpress doit tourner avec MAILPIT_ENABLED=true
        (docker-compose.yml, profil "mailpit")
  ui: http://localhost:8025
  api: http://localhost:8025/api/v1  # utilisé pour récupérer les liens des
                                      # étapes 04 et 11 sans dépendre du rendu
                                      # de l'iframe de prévisualisation

rate_limiting:
  # includes/helpers.php:psc_rate_limit() court-circuite désormais la
  # limitation (retourne true sans rien stocker) si :
  #   - wp_get_environment_type() vaut 'local' ou 'development', ou
  #   - le filtre psc_rate_limit_enabled renvoie false
  # → aucune purge de transient n'est plus nécessaire pour ce mécanisme.
  bypass: >
    définir WP_ENVIRONMENT_TYPE=local|development dans wp-config.php de
    l'environnement de test (déjà la valeur par défaut de nombreux setups
    locaux type wp-env), ou ajouter dans un mu-plugin de test :
    add_filter('psc_rate_limit_enabled', '__return_false');
  limites_concernees_par_ce_parcours: # neutralisées par le bypass ci-dessus
    - "mail_<email>"      # 3 / 15 min — includes/class-psc-parents.php:100
    - "ip_<ip>"            # 10 / heure — includes/class-psc-parents.php:101
    - "recap_<parent_id>"  # 5 / 10 min — includes/class-psc-frontend.php:186

recap_snapshot:
  # Distinct du rate-limiting : ajax_confirm() (class-psc-frontend.php:206)
  # stocke dans un transient l'état des inscriptions au moment du dernier
  # récapitulatif envoyé, pour calculer un diff « Modifications depuis
  # votre dernier récapitulatif » dans l'e-mail. Ce transient N'EST PAS
  # couvert par le bypass rate-limit (ce n'est pas une limitation de
  # fréquence) et survit à une réinitialisation des tables wp_psc_*.
  # À purger avant CHAQUE run, sinon un run précédent laisse une trace qui
  # rend l'expect de l'étape 11 non déterministe (bloc diff présent ou
  # absent selon l'historique) :
  action: >
    DELETE FROM wp_options WHERE option_name LIKE
    '_transient_psc_recap_snap_%' OR option_name LIKE
    '_transient_timeout_psc_recap_snap_%';

verrouillage:
  psc_lock_hours: 48  # option WP par défaut, lue par psc_is_locked()

session:
  cookie: psc_session (httponly, signé, 12h — psc_session_ttl())
  login_link_ttl: 30 min (psc_login_link_ttl())
```

## Étapes

### 00-carton-titre
- **action** : aucune (carton plein cadre, pas de navigateur)
- **testid** : n/a
- **wait_for** : n/a
- **expect** : n/a
- **narration** : "Inscription périscolaire — du papier à l'écran"
- **duration** : 3 s — ⚠️ 46 caractères → minimum calculé 4 s (46/12 =
  3,83 → 4). Valeur du découpage fourni conservée telle quelle
  ("reprends exactement le découpage"), signalée ici plutôt que corrigée
  en silence.
- **focus** : carton

### 01-arrivee-page-connexion
- **action** : navigate — URL de la page contenant `[periscolaire_form]`, sans cookie `psc_session`
- **testid** : `login-card`
- **wait_for** : `[data-testid="login-card"]` visible
- **expect** : titre "Déclarer les jours de présence" affiché ; `[data-testid="login-email-input"]` vide
- **narration** : "Aucun compte à créer : le parent saisit son e-mail" *(scène partagée avec 02)*
- **duration** : 5 s
- **focus** : `login-form`

### 02-saisie-email
- **action** : fill
- **testid** : `login-email-input`
- **wait_for** : valeur du champ === `fixtures.parent.email` (pas d'attente serveur)
- **expect** : `[data-testid="login-email-input"]` contient `fixtures.parent.email`
- **narration** : "Aucun compte à créer : le parent saisit son e-mail" *(scène partagée avec 01)*
- **duration** : 5 s
- **focus** : `login-form`

### 03-demande-lien-connexion
- **action** : click
- **testid** : `login-submit-button`
- **wait_for** : navigation terminée sur `?psc_msg=link_sent`
  *(couvre côté serveur : `check_admin_referer`, validation e-mail, double
  rate-limit — neutralisé en environnement de test, cf. bloc `env` —,
  `send_login_link()` qui génère et hash le token, envoie l'e-mail)*
- **expect** : `[data-testid="notice-link_sent"]` visible, texte "Si cette
  adresse est enregistrée auprès de la mairie, un lien de connexion vient
  d'être envoyé…"
- **narration** : "Il reçoit un lien d'accès valable 30 minutes" *(scène partagée avec 04)*
- **duration** : 5 s
- **focus** : overlay

### 04-consultation-email-connexion
- **action** : consultation de la boîte mail (hors UI de l'app — requête à
  l'API Mailpit : `GET /api/v1/messages` puis `GET /api/v1/message/{ID}` en
  filtrant `To = fixtures.parent.email`)
- **testid** : n/a (Mailpit, hors périmètre testid de l'app)
- **wait_for** : un message avec `Subject` contenant "Votre lien d'accès aux
  inscriptions périscolaires" apparaît pour `fixtures.parent.email`
  (poll jusqu'à réception, timeout raisonnable ex. 10s)
- **expect** : le corps texte contient une URL `.../?page_id=...&psc_pid=<id
  du parent>&psc_token=<64 car. hex>`
- **demo_render** : overlay — "📬 Récupération du lien de connexion dans Mailpit (hors écran famille)"
- **narration** : "Il reçoit un lien d'accès valable 30 minutes" *(scène partagée avec 03)*
- **duration** : 5 s
- **focus** : overlay

### 05-ouverture-lien-de-connexion
- **action** : navigate vers l'URL extraite à l'étape 04
- **testid** : `account-bar`
- **wait_for** : `[data-testid="account-bar"]` visible après redirection sur
  `?psc_msg=welcome`
  *(couvre côté serveur : `maybe_consume_token()` — vérification hash +
  expiration, ouverture de session, pose du cookie `psc_session`)*
- **expect** : `[data-testid="notice-welcome"]` visible ("Vous êtes
  connecté.") ; `[data-testid="account-email"]` === `fixtures.parent.email` ;
  cookie `psc_session` présent
- **narration** : "Il arrive directement sur le planning de ses enfants"
- **duration** : 5 s
- **focus** : `account-bar`

### 06-ouverture-du-mois
- **action** : click
- **testid** : `month-toggle-0-{YYYY-MM du mois contenant open_day}`
- **wait_for** : `[data-testid="calendar-table-0-{YYYY-MM}"]` visible
  *(natif : `<details>`/`<summary>`, aucun appel serveur)*
- **expect** : `[data-testid="day-row-0-{open_day}"]` visible dans le
  tableau déplié ; `[data-testid="month-summary-0-{YYYY-MM}"]` affiche
  "Aucun jour déclaré"
- **narration** : "Un calendrier par enfant, mois par mois"
- **duration** : 4 s
- **focus** : `calendar-table-0-{YYYY-MM}`

### 07-cocher-cantine-jour-ouvert
- **action** : click
- **testid** : `check-0-{open_day}-CANT`
- **wait_for** : réponse réseau à `admin-ajax.php` dont le `postData`
  contient `action=psc_toggle` (attendre la requête, pas l'animation —
  attendre la classe `psc-ok` est une race, elle peut apparaître et
  disparaître entre deux polls)
  *(couvre côté serveur : AJAX `psc_toggle` — vérification nonce, session,
  propriété de l'enfant, trimestre actif, jour ouvert, non verrouillé, puis
  `INSERT ... ON DUPLICATE KEY UPDATE` dans `wp_psc_registrations`)*
- **expect** : `[data-testid="check-0-{open_day}-CANT"]` coché ;
  `[data-testid="cell-0-{open_day}-CANT"]` a brièvement porté la classe
  `psc-ok` (flash ~700ms, assertion visuelle uniquement, pas une condition
  d'attente) ; `[data-testid="month-summary-0-{YYYY-MM}"]` passe à
  "1 jour · `fixtures.services.CANT.price`" avec la classe
  `psc-month-summary-active`
- **narration** : "Chaque case cochée est enregistrée aussitôt"
- **duration** : 5 s
- **focus** : `cell-0-{open_day}-CANT`

### 08-cocher-garderie-matin-meme-jour
- **action** : click
- **testid** : `check-0-{open_day}-GM`
- **wait_for** : réponse réseau à `admin-ajax.php` dont le `postData`
  contient `action=psc_toggle` (même principe que l'étape 07)
- **expect** : `[data-testid="check-0-{open_day}-GM"]` coché ;
  `[data-testid="cell-0-{open_day}-GM"]` a brièvement porté la classe
  `psc-ok` (assertion visuelle, pas une attente) ;
  `[data-testid="month-summary-0-{YYYY-MM}"]` passe à
  "1 jour · `fixtures.services.CANT.price + fixtures.services.GM.price`"
  (même jour, deux prestations cumulées)
- **narration** : "Le total du mois se met à jour en direct"
- **duration** : 4 s
- **focus** : `month-summary-0-{YYYY-MM}`

### 09-jour-verrouille-non-cliquable
- **action** : click forcé (`force: true` — contourne l'attente
  d'« actionabilité » du framework, qui sinon patiente que l'élément
  devienne cliquable jusqu'au timeout par défaut, ~30s, au lieu d'échouer
  proprement sur une case volontairement désactivée)
- **testid** : `check-0-{locked_day}-CANT`
- **wait_for** : absence de requête réseau `admin-ajax.php` avec
  `action=psc_toggle` dans les 500ms suivant le clic (attente négative :
  on confirme qu'aucune requête ne part, pas qu'une réponse arrive)
- **expect** : AUCUNE requête `admin-ajax.php` avec `action=psc_toggle`
  n'a été émise ; `[data-testid="check-0-{locked_day}-CANT"]` a l'attribut
  `disabled` ; son `aria-label` contient "(non modifiable)" ; la ligne
  `[data-testid="day-row-0-{locked_day}"]` porte la classe
  `psc-row-locked`
- **narration** *(2 prises, même écran, aucune action entre les deux)* :
  1. "Les jours à moins de 48 h sont verrouillés"
  2. "Le prestataire reçoit des effectifs fiables"
- **duration** : 5 s puis 5 s
- **focus** : `day-row-0-{locked_day}` puis `day-row-0-{locked_day}` *(idem, on reste)*

### 10-valider-planning
- **action** : click
- **testid** : `confirm-button`
- **wait_for** : `[data-testid="confirm-feedback"]` non vide
  *(couvre côté serveur : AJAX `psc_confirm` — rate-limit dédié — neutralisé
  en environnement de test —, calcul du diff vs. dernier récapitulatif via
  le transient `psc_recap_snap_<parent>_<trimestre>` — purgé avant le run,
  cf. bloc `env` —, `Psc_Mailer::send_recap()`)*
- **expect** : `[data-testid="confirm-feedback"]` contient "Récapitulatif
  envoyé à `fixtures.parent.email`." avec la classe `psc-ok-text`
- **narration** : "Le parent valide et reçoit son récapitulatif"
- **duration** : 5 s
- **focus** : `confirm-feedback`

### 11-verification-email-recap
- **action** : consultation de la boîte mail (API Mailpit, comme l'étape 04)
- **testid** : n/a (Mailpit)
- **wait_for** : un nouveau message pour `fixtures.parent.email` avec
  `Subject` contenant "Confirmation de votre planning périscolaire"
  apparaît (poll, timeout ex. 10s)
- **expect** : le corps HTML contient une ligne pour `open_day` avec les
  prestations Cantine et Garderie Matin cochées, et un total de mois égal à
  `fixtures.services.CANT.price + fixtures.services.GM.price` (même somme
  qu'à l'étape 08, un seul jour déclaré) ; le transient de snapshot ayant
  été purgé avant le run (bloc `env`), le bloc « Modifications depuis votre
  dernier récapitulatif » liste ces deux ajouts plutôt que d'être absent ou
  incohérent
- **demo_render** : overlay — "📧 Vérification du récapitulatif reçu dans Mailpit (hors écran famille)"
- **narration** : "Une trace écrite, côté parent comme côté mairie"
- **duration** : 5 s
- **focus** : overlay

### 12-carton-fin
- **action** : aucune (carton plein cadre, pas de navigateur)
- **testid** : n/a
- **wait_for** : n/a
- **expect** : n/a
- **narration** : "Plus de fichier papier. Zéro ressaisie au secrétariat."
- **duration** : 5 s
- **focus** : carton
