# TODO — Audit technique et protection des données

**Date : 6 septembre 2026 — Extension v5.4.1 — Référence : `39e657a`.**

**Statut : liste arbitrée et en cours de traitement.** Mise à jour le **25 septembre 2026** (extension **v5.28.0**) : voir [Avancement](#avancement-au-25-septembre-2026) ci-dessous. Les cases cochées portent la preuve de test correspondante (E2E ou sonde) ; les éléments marqués « partiellement avancé » ne sont pas considérés comme terminés tant que leur critère d’acceptation n’est pas rempli. Les tickets d’hébergement et DPO restent ouverts.

**État au 25/09/2026 :**
- **P0 :** traités.
- **P1 traités :** P1-03, P1-05, P1-13, P1-14, P1-15 et P1-17.
- **P1 traités techniquement, avec une validation opérationnelle encore à faire :**
  - P1-02 : revue des comptes ;
  - P1-12 : clé sortie de la base sur le serveur distant ; reste une restauration testée avec cette clé.
- **P1 partiellement avancés :** P1-06, P1-07, P1-08, P1-09, P1-11 et P1-16.
- **P1 mis de côté à la demande :** P1-01.
- **P1 ouverts, côté hébergement ou DPO :** P1-04, P1-10 et P1-18.
- **P2 :** tous traités, sauf P2-14 ; P2-06 est traité côté développement, reste le test réseau du site réel.
- **P3 :** P3-02 est traité ; P3-01 est traité (deux lots).

## Avancement au 26 septembre 2026

### Versions publiées depuis l'arbitrage

| Version | Contenu |
| --- | --- |
| 5.19.0 à 5.22.0 | P2-11 (release conditionnée aux contrôles), P1-02, P1-14, P2-01 à P2-05, P2-07, P2-09, P2-10, P2-12, P2-13, P2-15 |
| 5.23.0 / 5.23.1 | Documentation mise à jour et scindée (mairie / familles), lien vers le guide des familles dans le portail |
| 5.24.0 | Rubrique **Confidentialité** paramétrable (P1-07, volet technique) |
| 5.25.0 | Suppression protégée des factures envoyées, mode debug par WP-CLI (P1-16) ; P1-13 ; P1-17 ; P3-02 |
| 5.26.0 | P2-08 : une seule table d'année (schéma 4.15.0), statut de l'enfant par année, retrait de réinscription, rétention |
| 5.27.0 | Clé des IBAN : constat qu'elle vivait en base, commande `wp psc chiffrement` (P1-12), notes de mise à jour |
| 5.28.0 | Page **Périscolaire › Maintenance** (mise à jour conduite depuis le backoffice, sans WP-CLI), clé en variable d'environnement, registre d'audit complété et contrôlé en CI |
| 5.28.1 | P2-06 : polices du thème et de l'extension servies localement, script d'émojis WordPress désactivé |
| 5.29.0 | P1-15 : règle unique de facturation d'une journée (plus de cumul forfait + garderies), factures envoyées inchangées |
| 5.30.0 | P3-01 (premier lot) : contrats d'une journée, annulation de classe au forfait, totaux du portail fournis par le serveur, avis de fermeture |

### Reste à faire

| Bloc | Ce qui reste | Qui débloque |
| --- | --- | --- |
| **Serveur distant** | **En 5.30.0 (26/09/2026)**, à jour de toutes les versions publiées. Le 25/09, passage 5.23.1 → 5.28.0 avec les cinq étapes de **Périscolaire › Maintenance** (sauvegarde, base au schéma 4.15.0, clé des IBAN hors de la base et rechiffrement, réglages, recette). Reste : tester une restauration avec la clé, puis dérouler la fiche de recette de l'hébergement (P1-04, P1-18) | Exploitation |
| P2-06 | Polices servies localement (publié en 5.28.1, déployé) ; reste l'inventaire des ressources tierces du site réel (onglet Réseau du navigateur) | Exploitation, puis DPO |
| P1-11 | Fait, à publier : fichier de repli expurgé et archivé, alerte de retard des tâches planifiées, purge par niveau sans trou dans la chaîne (schéma 4.16.0). Reste : faire valider les durées par le DPO | DPO |
| P1-16 | Tarifs et statut datés publiés en 5.32.0 ; calcul en centimes fait, à publier ; procédure de correction validée par la facturation (26/09/2026). Reste : qualification comptable du PDF, à confirmer par la mairie | Mairie |
| P2-14 | Fait, à publier : version du règlement approuvé (texte affiché + PDF, schéma 4.18.0). Reste : valider le circuit de signature et d'archivage du mandat SEPA | Facturation, DPO |
| P1-15 | Règle unique publiée en 5.29.0, déployée ; reste à revoir le tarif FSR, plus cher que ses prestations avec les valeurs par défaut | Facturation |
| P3-01 | Premier lot publié en 5.30.0, déployé ; second lot fait (listes du calendrier factorisées, e-mail « forfait modifié » par enfant), à publier | Développement |
| P1-06 à P1-10 | Notice relue, durées de conservation, procédure des droits, dossier de conformité et AIPD | DPO et mairie |
| P1-01 | Permissions par fonction des intervenants | Mis de côté |
| Documentation | Captures **Année scolaire** et **Calendrier** régénérées (formulaire sans libellé, jours hors du mois), à publier | — |

## Périmètre et limites

Le développement s’effectue sur le **laptop**, avec WordPress/MySQL/Mailpit sous **Podman**. La production est un **serveur distant recevant le ZIP de l’extension**, pas le dépôt Git ni les fichiers Compose. Les constats locaux ne sont donc pas des preuves de vulnérabilité du serveur distant.

Ont été examinés : authentification des familles, accès mairie et SIDSCM, formulaires publics, inscriptions/réinscriptions, personnes autorisées, assurances, planning, calendrier, tarifs, factures/PDF/CSV, menus, commandes fournisseur, schéma/migrations, stockage, journaux, ressources externes, tests et publication. Le thème fourni est examiné séparément : sa présence sur le serveur distant reste à confirmer.

La configuration réelle du serveur distant, ses extensions WordPress, son thème actif, son cache/CDN, ses sauvegardes, ses journaux et les documents organisationnels de la mairie ne sont pas accessibles dans ce périmètre. **La conformité RGPD n’est pas démontrable par le code seul** : cette liste rassemble les corrections techniques et les preuves à constituer avec le responsable de traitement et le DPO. L’absence d’un document dans le dépôt ne prouve pas son absence à la mairie.

Niveaux de preuve utilisés :

- **Reproduit** : sonde locale ou calcul exécuté, avec données fictives pour les règles métier.
- **Constaté dans le code** : chemin identifié ; pas de test destructif ni d’exploitation sur des données réelles.
- **Conditionnel** : risque dépendant de la configuration distante ou d’un scénario à vérifier.
- **À documenter** : décision, procédure ou preuve de conformité non disponible dans le dépôt.

Urgences proposées, à compter de l’arbitrage de cette liste :

| Niveau | Échéance proposée | Signification |
| --- | --- | --- |
| P0 | Prochaine correction prioritaire | Fiabilité des informations de sécurité des enfants ; ne pas se fier aux fonctions concernées sans contrôle métier tant que le défaut subsiste. |
| P1 | Avant de considérer le service prêt pour des données réelles ; traitement prioritaire si déjà utilisé | Sécurité, droits, conservation, intégrité de la facturation et disponibilité. Les contrôles d’hébergement sont à réaliser, pas présumés en échec. |
| P2 | Après P0/P1, prochain cycle de fiabilisation | Robustesse, couverture des tests, exploitation et risques secondaires. |
| P3 | Après stabilisation | Maintenance et documentation. |

Les tailles **S/M/L** donnent seulement un ordre de grandeur relatif. Une correction S ne couvre pas une éventuelle reprise de données historiques. Les chantiers DPO/hébergement dépendent d’intervenants extérieurs au développement.

## Vérifications réalisées

| Vérification | Résultat et portée |
| --- | --- |
| Syntaxe PHP du paquet | **91 fichiers, 0 erreur**, PHP 8.3.33 dans Podman. |
| Tests unitaires existants | **140 vérifications, 0 échec**, primitive sodium. Aucun WordPress amorcé, aucune donnée métier écrite. |
| Analyse statique | **PHPStan niveau 3 : aucune erreur**. Périmètre `includes/`, bibliothèque FPDF exclue de l’analyse. |
| JavaScript | **ESLint : aucune erreur** sur `assets/js`. |
| Dépendances npm verrouillées | `npm audit --package-lock-only` : **0 avis de vulnérabilité remonté**. Ne couvre ni WordPress, ni les extensions distantes, ni FPDF embarqué. |
| ZIP GitHub v5.4.1 | Archive officielle inspectée en mémoire : **137 entrées**, uniquement les fichiers du plugin et sa documentation. Pas de `.env`, `.git`, `bin`, `tests`, `mu-plugins`, `theme`, `vendor`, `node_modules` ni export de travail dans le paquet. |
| Sondes métier en mémoire | Perte des allergies, forfait sans présence du midi, cumul résiduel de facturation et décalage du verrou reproduits ; détails ci-dessous. |
| HTTP sur le laptop | Requêtes **HEAD uniquement**, sans affichage du contenu sensible : `.env`, `.git/HEAD` et une archive de travail répondent 200 ; témoin `wp-content/psc-private/psc-probe.txt` répond 403 ; API Mailpit répond 200 sans authentification. |

Les E2E complets, les migrations et les suppressions n’ont **pas** été exécutés pendant cet audit : les scripts de peuplement modifient la base locale. Les scénarios antérieurement validés ne remplacent pas les nouveaux tests de non-régression proposés ici. Aucun test n’a été exécuté contre le serveur distant et aucun e-mail n’a été envoyé.

La réponse 200 d’un répertoire historique sous `uploads/periscolaire/` ne démontre pas qu’il contient encore des documents ; son contenu n’a pas été consulté.

## Cartographie initiale des données

| Traitement | Données et stockage | Accès / flux observés | Conservation actuelle dans le code |
| --- | --- | --- | --- |
| Dossier famille | `parents` : identité, coordonnées, adresses, second parent | Famille via cookie dédié ; mairie | Désactivation et suppression manuelles ; pas de durée générale automatique. |
| Connexion | Empreintes de liens dans `parents`/`requests`, cookie signé, révocations en transients | E-mail du titulaire ou second parent ; session au niveau du foyer | Lien 30 min par défaut, session 12 h ; liens réglables ; effacement des empreintes expirées non systématique. |
| Enfants / accueil | `children`, `child_school_years`, `pattern`, `exception`, `attendance` | Famille, mairie, intervenants pour les listes utiles | Historique annuel ; pas de politique globale de purge. |
| Alimentation / signalement | `children.food_allergy_signal`, régimes et décision mairie `cantine_sans_repas` | Famille, mairie, intervenants pour les listes utiles | Le nouveau parcours ne collecte aucun détail médical ; les anciennes valeurs `food_allergies` restent historiques et restreintes. |
| Personnes autorisées | `pickup_persons`, `pickup_history`, second parent dans `parents` | Famille ; mairie ; intervenants GS, coordonnées incluses | Retrait logique ; historique jusqu’à suppression de l’enfant. |
| Échanges familles / mairie | `conversations`, `conversation_messages` : sujet, contenu en texte brut, pointeurs de lecture par côté | Famille via son espace ; mairie via le backoffice ; e-mails de notification sans sujet ni contenu | Purge quotidienne 395 jours après le dernier message (filtre `psc_conversations_retention_days`) ; supprimées avec la famille. |
| Assurance | Fichier nominatif et chemin dans `child_school_years`, fichiers de demandes en attente | Téléchargements contrôlés parent/mairie | Remplacement annuel et suppressions manuelles ; nettoyage des demandes limité à certains statuts. |
| Paiement / factures | IBAN chiffré, BIC, titulaire/adresse, acceptations, `invoices`, PDF | Famille, mairie, messagerie pour les factures | Pas d’archivage comptable distinct ; suppression de la famille supprime aussi ses factures. |
| Demande d’inscription | Identité, téléphone, adresse, enfants, tiers, pièces, informations SEPA et notes | Adresse vérifiée puis mairie ; approbation éventuellement automatique | Non confirmée : 7 jours ; approuvée/rejetée : 90 jours ; `pending` sans échéance. |
| Journaux | `journal-acces.log` : identité, e-mail famille, IP, chemin, horodatage ; historique des tiers | Fichier privé et utilisateurs ayant accès au serveur | Ni rotation ni durée de journal définies dans le code. |
| Fournisseur / menus | Quantités agrégées, adresse fournisseur, copie du message ; menus | Fournisseur : quantités sans liste nominative dans le flux examiné ; menus par famille | Historique des envois ; durée non définie. |
| Prestataires web | Saisie d’adresse envoyée à l’API BAN ; Google Fonts dans le thème fourni | Requêtes navigateur vers des tiers ; IP transmise de fait | Dépend des prestataires et du site distant. |

Les allergies sont des données de santé. Un choix « sans porc » ne prouve pas une religion : ne pas en déduire de conviction religieuse ni créer une catégorie religieuse. Les coordonnées bancaires sont sensibles au sens de la sécurité, sans être à elles seules une catégorie particulière de l’article 9.

## P0 — Fiabilité de l’accueil des enfants

### P0-01 — TRAITÉ (v5.4.2/v5.4.3) — Préserver les allergies lors de l’approbation des inscriptions

- [x] **Corriger le transfert des allergies, puis examiner les demandes déjà approuvées concernées.**

**Preuve : reproduit.** `includes/class-psc-requests.php:365` stocke `food_allergies`, mais `children_of():116` ne restitue pas cette clé. `handle_approve():593` ne la recopie pas non plus dans les enfants édités. `approve_request():673` attend pourtant cette valeur pour créer la fiche et déclencher l’alerte PAI. Une demande fictive contenant « arachide » ressort du décodeur sans allergie. Les parcours manuel et automatique sont concernés.

**Impact :** information sanitaire perdue, absence possible d’alerte mairie, listes intervenants incomplètes et comptages fournisseur erronés. Ce n’est pas un simple défaut de libellé.

**À faire :** conserver et revalider le champ à chaque étape ; notifier après validation effective ; prévoir un rapprochement contrôlé des demandes encore conservées avec les fiches enfants. Ne pas écraser automatiquement une information plus récente renseignée par la famille.

**Acceptation :** inscription publique avec allergie → validation manuelle ET automatique → fiche, alerte, liste SIDSCM et exclusions fournisseur cohérentes ; test d’une correction faite par la mairie et d’une demande sans allergie. **Responsable : développement + métier ; taille M.**

### P0-02 — TRAITÉ (v5.4.2/v5.4.3) — Garantir la présence et le pointage du midi pour les enfants sans repas

- [x] **Aligner forfait sans repas, liste du midi et contrôle serveur du pointage.**

**Preuve : reproduit pour la résolution, constaté dans le code pour le pointage.** `includes/helpers/planning.php:182` convertit CANT en MSR, mais ne donne pas de repli MSR à un forfait seul. Avec `FORF=true` et le flag sans repas, le résultat est `GM=true, CANT=false, GS=true, FORF=true, MSR=false`, alors que la facturation renvoie `FSR`. Dans `includes/class-psc-sidscm.php`, la liste CANT fusionne CANT et MSR, mais `is_expected():212` et `ajax_toggle():394` contrôlent seulement la prestation CANT envoyée pour le midi : une présence MSR peut donc être visible mais impossible à pointer.

**Impact :** un enfant présent à midi peut être absent de la liste ou impossible à pointer ; le forfait est pourtant facturé.

**À faire :** définir explicitement l’occupation du créneau midi indépendamment de la fourniture du repas ; faire utiliser cette règle par la résolution, la liste et le pointage. Vérifier aussi les fermetures CANT et les retraits de MSR.

**Acceptation :** forfait sans repas seul → présence midi, zéro repas fournisseur et pointage possible ; inscription MSR seule → même cohérence ; absence réelle enregistrable ; retrait du midi respecté. **Responsable : développement + métier ; taille M.**

## P1 — Sécurité, conformité et intégrité

### P1-01 — PARTIELLEMENT AVANCÉ — MIS DE CÔTÉ (24/09/2026) — Remplacer le secret partagé des intervenants SIDSCM

- [x] **Mettre en place des accès intervenants individuels, limités et révocables.**

**Réalisé côté développement :** registre serveur `psc_sidscm_intervenants` (code haché, actif/révoqué, date d’expiration), jeton de session opaque à durée limitée et conservé uniquement en mémoire JavaScript, choix d’identité dans l’écran public, et auteur individuel dans l’audit. Le code partagé reste uniquement un mode de migration tant qu’aucune identité n’est enregistrée. La validation métier du périmètre d’accès et le choix éventuel d’une authentification renforcée restent manuels (mairie/DPO).

**Reste à faire :** valider les permissions par fonction/périmètre, le verrouillage des postes partagés et le besoin d’une authentification renforcée selon l’AIPD. Les accès individuels, la session expirante et l’auteur d’audit sont implémentés.

**Acceptation :** départ d’un intervenant → accès coupé pour lui seul ; expiration après inactivité ; aucun secret durable dans `localStorage` ; opérations attribuables. **Développement + mairie ; L.**

### P1-02 — TRAITÉ TECHNIQUEMENT (après v5.19.0) — Revue des comptes encore ouverte — Réduire les droits accordés par défaut aux éditeurs WordPress

- [x] **Créer une matrice d’habilitations mairie, facturation et données sanitaires.**

**Constaté le 24/09/2026 :** la case était cochée mais l’acceptation n’était pas tenue. Les éditeurs gardaient `psc_impersonate_family`, donc la consultation de n’importe quel espace famille. Tous les écrans exigeaient la capacité globale plutôt que celle de leur domaine : une personne habilitée à la seule facturation ne voyait aucun écran. `psc_view_health` n’était vérifiée nulle part, et les allergies s’affichaient à quiconque ouvrait Enfants, Présences déclarées ou Demandes.

**Traité le 24/09/2026 :** le rôle éditeur perd la capacité globale, la consultation d’espace famille et toute capacité métier (ROLES_VERSION 1.5.0, `psc_manage_default_roles()` réduit à l’administrateur, filtrable). Chaque entrée de menu et chaque écran exige la capacité de son domaine. Les allergies (Enfants, Présences déclarées, Demandes, report des allergies) exigent `psc_view_health` ; sans elle, la même mention « Accès restreint » s’affiche pour tous les enfants, pour ne pas révéler lesquels sont concernés. La désinstallation retire toutes les capacités du plugin. **Preuves :** `tests/integration/capabilities-matrix.php` appelle les 79 endpoints admin avec un nonce valide sous un éditeur, et les endpoints hors domaine sous des profils partiels ; tout doit refuser. Vérifiée par mutation : sans le contrôle santé du report d’allergies, la sonde échoue. `tests/capabilities.spec.ts` couvre le menu, les refus 403 par URL et le masquage des allergies.

**Reste à faire (administration) :** avant la mise à jour, inventorier les comptes éditeurs qui utilisent le backoffice : ils perdent l’accès et doivent recevoir leurs habilitations sur leur profil. Organiser ensuite la revue périodique des habilitations.

**Acceptation :** un éditeur de contenus sans mission périscolaire n’accède pas aux dossiers ; le rôle facturation ne consulte pas automatiquement les allergies ; tests de refus sur les URL et endpoints. **Développement + administrateur WordPress ; M.**

### P1-03 — TRAITÉ (v5.4.2/v5.4.3) — Empêcher la mise en cache partagée des pages familles

- [x] **Protéger explicitement le portail et les URL portant des jetons contre le cache.**

**Constaté / risque conditionnel :** le portail authentifie un visiteur hors comptes WordPress (`class-psc-parents.php`) ; `class-psc-frontend.php` ne pose pas de politique explicite `no-store` ni d’exclusion de cache. Les téléchargements, eux, appellent `nocache_headers()`. Un cache WordPress/CDN qui considère `psc_session` comme un cookie anonyme peut mélanger des pages de familles. Aucune fuite entre familles n’a été testée ou constatée sur le serveur distant.

**À faire :** en-têtes adaptés dès le point d’entrée ; exclusions de cache par cookie, page et paramètres des liens magiques ; vérifier les redirections, retour navigateur et en-têtes de référence. Une constante PHP seule ne suffit pas si le cache intervient avant WordPress.

**Acceptation :** deux familles et un navigateur anonyme derrière le cache réellement utilisé ne partagent jamais HTML, données de démarrage ou jetons ; réponses privées non stockées. **Développement + hébergeur ; M.**

### P1-04 — Vérifier et fiabiliser le stockage privé sur le serveur distant

- [ ] **Valider l’inaccessibilité HTTP des documents, des journaux et des anciens emplacements.**

**Avancement technique :** le défaut vise désormais le parent de `ABSPATH`, donc un répertoire hors racine HTTP ; un repli vers `uploads/psc-private` reste possible uniquement si ce parent n’est pas inscriptible, avec protections `.htaccess`/`web.config`, témoin et alerte d’administration. Le confinement est prouvé localement par `tests/integration/private-storage-receipt.php` (25 vérifications : refus des chemins traversants et des liens symboliques sortants, garde-fous posés, jeton témoin stable, résolution d’URL nulle hors racine web). La validation HTTP effective sur l’hébergement distant reste manuelle : elle fait l’objet de la fiche de recette `documentation/installation/fiche-recette-p1.md`, dont toutes les lignes partent à « à vérifier ».

**À faire :** privilégier `PSC_PRIVATE_DIR` hors racine web quand l’hébergement le permet ; sinon règle serveur explicite vérifiée. Contrôler chemins historiques, sauvegardes, prévisualisations et accès direct aux noms prévisibles. Rendre les échecs de création des protections détectables ; vérifier la détermination d’URL pour les emplacements personnalisés.

**Acceptation :** un fichier témoin non sensible placé dans chaque emplacement concerné est inaccessible anonymement ; les documents restent téléchargeables après contrôle d’appartenance. **Hébergeur + développement ; M.**

### P1-05 — TRAITÉ (v5.4.2/v5.4.3) — Révoquer réellement les accès des familles et du second parent

- [x] **Associer les sessions à une identité d’accès et à un état de révocation durable.**

**Constaté :** `class-psc-parents.php` ouvre une session signée au niveau du foyer ; changer l’e-mail ou retirer le second parent (`class-psc-frontend-profil.php`) ne révoque pas les sessions déjà ouvertes ni le lien commun encore valable. Un accès retiré peut donc subsister jusqu’à 12 h par défaut. `includes/helpers/session.php` conserve la révocation individuelle dans un transient, susceptible d’être évincé avant son expiration en présence d’un cache externe. Les anciens cookies sans identifiant restent acceptés par le code.

**À faire :** identité titulaire/second parent distincte, registre de sessions ou version de révocation persistante ; invalider les liens et sessions concernés lors d’un retrait/changement sensible ; lister/révoquer les appareils ; supprimer la compatibilité des anciens cookies une fois la fenêtre de migration dépassée. Prévoir les situations d’autorité parentale restreinte avec la mairie.

**Acceptation :** ancien cookie et ancien lien refusés après retrait du second parent, changement d’identifiant ou révocation ; refus maintenu après purge du cache. **Développement + mairie ; L.**

### P1-06 — PARTIELLEMENT AVANCÉ — Remplacer la collecte d’allergies par un signalement alimentaire minimal

- [x] **Ne plus demander de détail d’allergie dans le parcours famille ; déclencher un échange avec la mairie.**

**Réalisé côté développement :** le parcours famille recueille seulement un booléen `food_allergy_signal`, sans description ni consentement « donnée de santé ». Une notification sans détail invite la mairie à contacter oralement la famille ; la mairie décide ensuite d’activer `cantine_sans_repas`. Les anciennes données détaillées restent conservées uniquement pour l’historique et les usages restreints existants.

**Reste à faire :** valider avec le DPO la notice, les anciennes données historiques, les destinataires et la durée de conservation ; confirmer la procédure orale et les responsabilités de la mairie.

**Avancement technique :** le signalement est minimal et la notification ne contient aucun détail médical. Le statut « cantine sans repas » n’est jamais activé automatiquement par la famille.

**Acceptation :** signalement sans détail, échange oral réalisé, décision mairie tracée par l’activation éventuelle de `cantine_sans_repas`, et durée des anciennes données historiques arbitrée. Aucun consentement « global RGPD » n’est ajouté par défaut. La qualification juridique des anciennes données éventuellement conservées reste à valider par le DPO ; elle ne doit pas être déduite du nouveau signalement minimal. Référence : [RGPD, articles 5, 6 et 9](https://www.cnil.fr/fr/reglement-europeen-protection-donnees/chapitre2). **DPO + métier + développement ; L.**

### P1-07 — PARTIELLEMENT AVANCÉ — Compléter l’information des familles et des tiers

- [ ] **Rédiger une notice de confidentialité paramétrable et accessible aux points de collecte.**

**Constaté :** `templates/guest-request.php:293` annonce uniquement le traitement de la demande, la purge à 7 jours et accès/rectification par contact mairie. Elle ne décrit pas tout le service : suivi annuel, factures, santé, tiers, journaux et prestataires. Les noms/coordonnées du second parent et des personnes autorisées sont collectés indirectement. Un texte suggéré plus complet (finalités, catégories de données, durées de conservation, droits) est désormais injecté via `wp_add_privacy_policy_content` dans Réglages > Confidentialité (`class-psc-privacy.php`) — mais il reste un texte générique à relire, adapter et publier par un administrateur, pas une notice relue par le DPO, et il ne remplace pas la mention affichée au point de collecte lui-même (toujours celle décrite ci-dessus).

**À faire :** identifier responsable/DPO, finalités, bases, destinataires, durées ou critères, droits applicables, réclamation CNIL, caractère obligatoire/facultatif et conséquences ; information des tiers selon l’article 14. Relier la notice au portail et au formulaire, sans confondre information et consentement.

**Avancement technique (v5.24.0) :** la mention affichée aux points de collecte est paramétrable dans **Périscolaire › Réglages › Confidentialité** (responsable du traitement, adresse du DPO, adresse d'exercice des droits, lien vers la notice complète), avec un aperçu identique à ce que voient les familles et une alerte tant que le responsable n'est pas renseigné. Preuve : `tests/confidentialite.spec.ts`. Restent la relecture du texte par le DPO et l'information des tiers (second parent, personnes autorisées).

**Acceptation :** texte relu par le DPO, coordonnées réelles, accès sans connexion et information des tiers documentée. Référence : [RGPD, articles 12 à 14](https://www.cnil.fr/fr/reglement-europeen-protection-donnees/chapitre3). **DPO + développement ; M.**

### P1-08 — PARTIELLEMENT AVANCÉ — Définir puis appliquer une politique complète de conservation

- [ ] **Couvrir toutes les tables, pièces, messages, journaux et sauvegardes.**

**Constaté :** `class-psc-requests.php:54` purge les demandes non vérifiées à 7 jours et traitées à 90 jours ; les demandes `pending` peuvent rester indéfiniment. Familles, allergies, années, présences et anciens tiers n’ont pas de purge par durée. La suppression de famille ne purge pas les demandes d’origine. La désinstallation conditionnelle ne remplace pas une politique de conservation. Depuis lors, `Psc_Retention` (cron quotidien) purge automatiquement présences, planning, personnes autorisées et allergies d’un enfant 400 jours après son passage à « sorti » (`children.sorti_le`, cf. readme.txt § RGPD) — mais seulement pour ce périmètre : la fiche parent n’est jamais purgée par ce mécanisme même si tous ses enfants sont partis, et les années scolaires, les sauvegardes et les demandes `pending` restent hors de toute politique de durée.

**À faire :** tableau validé avec le DPO et le service d’archives : finalité, point de départ, durée en base active, archivage intermédiaire, sort final, exceptions justifiées. Prévoir relances puis clôture des demandes pendantes, nettoyage des fichiers orphelins, surveillance du cron et réapplication des suppressions après restauration.

**Avancement technique :** un registre de catégories et un rapport `simulation`/`execution` existent ; les catégories non validées restent bloquées. Seule la purge des enfants sortis, déjà validée, est active par défaut. Les durées restantes et l’activation opérationnelle sont manuelles.

**Acceptation :** simulation des suppressions, rapport sans contenu sensible, exécution contrôlée et test horloge figée. Ne pas appliquer arbitrairement « tout effacer après un an » ou un délai comptable unique. Référence : [CNIL — conservation et articulation avec les archives publiques](https://www.cnil.fr/fr/passer-laction/les-durees-de-conservation-des-donnees). **DPO + archives + développement ; L.**

### P1-09 — PARTIELLEMENT AVANCÉ — Outiller l’exercice des droits sans destruction comptable aveugle

- [ ] **Prévoir export de dossier, rectification, limitation et effacement encadré.**

**Constaté :** pas d’intégration aux exporteurs/effaceurs de données personnelles WordPress trouvée ; la modification de profil et la suppression mairie couvrent seulement une partie du besoin. `class-psc-admin-familles.php:121` détruit aussi les factures/PDF d’une famille, mais pas ses demandes. Depuis lors, `Psc_Privacy` intègre `wp_privacy_personal_data_exporters`/`erasers` (Outils > Exporter/Effacer) : l’export couvre foyer, enfants, planning, factures et échanges ; l’effaceur purge le foyer (enfants, planning, personnes autorisées, échanges, traces d’impersonation) et anonymise la ligne parent sur place plutôt que la supprimer, précisément pour garder les factures rattachées à une référence valide sans les détruire — l’obligation comptable prévalant sur l’effacement pour ce périmètre (cf. readme.txt § RGPD). La suppression manuelle mairie suit désormais la même règle : `Psc_Admin_Familles::delete_family()` ne détruit plus aucune facture ni aucun PDF ; tant qu'une facture existe, la ligne parent est anonymisée sur place avec exactement le reliquat de l'effaceur (`Psc_Privacy::anonymized_parent_fields()`, champ unique partagé par les deux chemins), et la suppression n'est complète que pour un foyer sans facture. Conséquence assumée, et non un écart à corriger : une facture conservée garde dans son PDF l’identité sous laquelle elle a été émise, que la ligne parent en base n’affiche plus. Une pièce comptable émise ne se réécrit pas — la modifier pour en retirer un nom la falsifierait et lui ôterait sa valeur probante, alors même que c’est cette valeur qui justifie sa conservation. L’identité qu’elle porte relève donc du même régime que la facture elle-même : conservée au titre de l’obligation légale, pour la même durée, puis éliminée avec elle. Cette conservation doit être écrite dans la réponse faite à la famille et dans le registre, pas rattrapée par une réécriture des PDF.

**À faire :** procédure de vérification d’identité, réponse suivie, export couvrant tables/fichiers/tiers/historiques, décisions d’effacement ou de conservation motivées ; séparer données opérationnelles et archives légalement nécessaires. Des outils WordPress sont une option pratique, pas une obligation en soi. L’effacement n’est pas absolu et la portabilité ne s’applique pas automatiquement à une mission d’intérêt public.

**Avancement technique :** les exports masquent l’IBAN, signalent la présence d’une allergie sans son détail et ne recopient pas le contenu des conversations. Les opérations d’export/effacement sont auditées ; effacement WordPress **et** suppression manuelle mairie conservent désormais les factures selon la règle comptable documentée, preuve à l’appui (`tests/integration/privacy-rights.php`, 49 vérifications, dont la survie du PDF sur disque et l’absence d’effet sur une autre famille). La procédure d’identité et la validation du périmètre par la mairie restent à formaliser.

**Acceptation :** exercice complet sur famille fictive et second parent, périmètre des enfants autorisés vérifié, absence de divulgation des autres familles, décision et délai tracés. Référence : [RGPD, articles 15 à 21](https://www.cnil.fr/fr/reglement-europeen-protection-donnees/chapitre3). **DPO + développement ; L.**

### P1-10 — Constituer le dossier de conformité et évaluer l’AIPD

- [ ] **Rassembler les preuves organisationnelles avec la mairie et le DPO.**

**À documenter :** responsable du traitement, DPO, registre, habilitations, contrats hébergement/messagerie/maintenance, localisation et transferts éventuels, procédures d’incident. Aucune conclusion sur leur existence hors dépôt.

**À faire :** analyse de nécessité d’une AIPD, en considérant données de santé et personnes vulnérables ; réaliser l’AIPD si requise. Vérifier obligations des sous-traitants et traitement des violations, avec procédure d’évaluation/notification et responsables identifiés. Le délai de notification de 72 h s’apprécie lorsque la notification est requise, à compter de la prise de connaissance ; documenter aussi les incidents non notifiés.

**Acceptation :** registre à jour, décision AIPD motivée, contrats/garanties disponibles, exercice d’incident et arbitrage formalisé des risques résiduels. Références : [RGPD, chapitre IV](https://www.cnil.fr/fr/reglement-europeen-protection-donnees/chapitre4), [CNIL — AIPD](https://www.cnil.fr/fr/ce-quil-faut-savoir-sur-lanalyse-dimpact-relative-la-protection-des-donnees-aipd). **Mairie + DPO + prestataires ; L.**

### P1-11 — PARTIELLEMENT AVANCÉ — Étendre et maîtriser la journalisation

- [ ] **Tracer les opérations sensibles avec une identité fiable et une durée définie.**

**Avancement depuis l’audit :** le journal d’audit unifié est maintenant en place (actions génériques et registre d’actions, acteur, résultat, empreinte chaînée, rédaction des données sensibles, écran d’administration, export et vérification d’intégrité). La couverture métier fine, la rotation/rétention complète et la surveillance des échecs restent à valider.

**Constaté :** `includes/helpers/files.php:196` journalise uniquement les téléchargements de documents, avec e-mail, IP et chemin ; écriture silencieuse en cas d’échec, sans rotation. `pickup_history` conserve des changements, mais les modifications de profil du second parent qui influencent les autorisations ne suivent pas ce même historique. Le code commun SIDSCM ne permet pas de distinguer les intervenants.

**À faire :** journal structuré des connexions/révocations, consultations sensibles, modifications, exports, suppressions, paramètres bancaires et actions de pointage ; identité minimale, horodatage UTC, rotation, contrôle d’accès et alerte de panne. Ne jamais journaliser les tokens, IBAN complets ou descriptions d’allergies.

**Acceptation :** reconstitution d’un incident fictif, auteurs identifiables, journal protégé et rétention appliquée, consultation elle-même limitée. Référence : [CNIL — tracer les opérations](https://www.cnil.fr/fr/securite-tracer-les-operations). **Développement + exploitation + DPO ; M/L.**

**Traité le 26/09/2026 (volet technique) :**
- **Fichier de repli `journal-acces.log` :** il ne reçoit plus que l'horodatage UTC, le code d'action et un message technique expurgé (`psc_audit_technical_message` : valeurs entre guillemets, e-mails et suites de chiffres masqués). Une erreur SQL citait jusqu'ici la valeur en cause. Au-delà de 256 Ko, il est archivé en `.1`, une seule génération. La purge quotidienne supprime chaque fichier resté sans écriture pendant la durée « normal ». Vérifié par `bin/verify-audit-fallback.php` en CI, qui provoque une vraie panne d'écriture.
- **Alerte de panne :** les tâches planifiées du plugin en retard de plus de six heures sont signalées sur les tableaux de bord (WordPress et Périscolaire). Elles n'étaient visibles nulle part. Localement, toutes avaient 25 à 49 h de retard : WP-Cron ne se déclenche pas dans le conteneur. Procédure : [Tâches planifiées](documentation/installation/taches-planifiees.md#alerte-de-retard).
- **Purge par niveau (schéma 4.16.0, validé le 26/09/2026) :** la purge n'avançait que par préfixe contigu pour préserver le chaînage. Une ligne « critique » (3 ans) bloquait donc la suppression des lignes « volumineux » (180 jours) qui la suivaient. Désormais, `psc_audit_log.empreinte_contenu` garde l'empreinte du contenu à part (chaînage v2 à partir de l'id `psc_audit_chain_v2_from` ; les lignes antérieures gardent leur chaînage v1, jamais réécrit). Une ligne expirée est vidée de son contenu et marquée `purgee_le`, sans trou dans la chaîne ; elle n'est ni listée ni exportée, et elle est supprimée dès qu'elle forme le début de la table. Preuves : `bin/verify-audit-retention.php` (20 vérifications, en CI), tests unitaires du chaînage mixte, `tests/audit.spec.ts`.

### P1-12 — TRAITÉ TECHNIQUEMENT (v5.4.2/v5.4.3, rotation en v5.27.0/v5.28.0) — Validation opérationnelle encore ouverte — Interdire le repli bancaire silencieux en clair

- [x] **Faire échouer explicitement un enregistrement bancaire si le chiffrement est indisponible.**

**Constaté :** `includes/helpers/crypto.php:39` renvoie l’IBAN initial si aucune primitive n’existe ou si OpenSSL échoue. Le chiffrement fonctionne sur le laptop avec sodium ; aucune défaillance du serveur distant n’est établie. Sans clé dédiée, la clé est dérivée des sels WordPress : leur rotation peut rendre les données illisibles.

**À faire :** prérequis et état de santé explicites ; clé dédiée, sauvegardée séparément et protégée ; rotation versionnée avec migration vérifiable ; distinguer donnée absente et déchiffrement impossible. Les sauvegardes doivent inclure le nécessaire à la restauration sans exposer clé et données au même niveau d’accès.

**Constaté le 25/09/2026 :** sans `PSC_ENCRYPTION_KEY`, la clé n'est **pas** dérivée des sels de `wp-config.php`. `wp_salt('psc_sepa')` est un nom de sel propre au plugin : WordPress le tire de l'option `secret_key`, enregistrée **en base** (vérifié sur l'instance locale). Un dump de la base suffisait donc à déchiffrer les IBAN, contrairement à ce qu'affirmaient la documentation et le commentaire de `crypto.php`.

**Traité le 25/09/2026 :**
- **Chaîne de clés :** chiffrement avec la clé courante uniquement (`PSC_ENCRYPTION_KEY`, sinon la base). Lecture avec la courante puis les précédentes (`PSC_ENCRYPTION_KEY_PREVIOUS`, puis le secret de la base) : rien ne devient illisible pendant la transition.
- **Commande `wp psc chiffrement`** (`Psc_Key_Rotation`) : `statut` indique l'origine de la clé et le nombre de valeurs par clé ; `generer-cle` affiche la ligne à ajouter dans `wp-config.php` ; `rechiffrer [--dry-run]` couvre `parents.sepa_iban`, `requests.sepa_iban` et l'option de l'IBAN du créancier. L'écriture est conditionnelle (un IBAN modifié entre-temps n'est pas écrasé), la commande peut être relancée, les valeurs illisibles restent intactes, et l'opération est journalisée (`systeme.rechiffrement`). Elle refuse de s'exécuter sans constante.
- **Documentation :** nouvelle page « Clé de chiffrement des IBAN » (sortir la clé de la base, rotation ultérieure) ; « Sauvegarde et mise à jour » et la fiche de recette corrigées.

**Preuve :** `bin/verify-key-rotation.php`, lancé en CI (20 vérifications). Vérifié par mutation : 5 défauts réintroduits, tous détectés. Essai de bout en bout avec une vraie constante (`wp --exec`) : après rechiffrement, la base seule ne déchiffre plus l'IBAN.

**Depuis le backoffice (25/09/2026) :** pour un WordPress en conteneur, sans WP-CLI, la page **Périscolaire › Maintenance** (`Psc_Admin_Maintenance`) déroule la mise à jour en cinq étapes : sauvegarde confirmée, base de données (doublons d'années, relance), clé (génération affichée une seule fois et jamais enregistrée, rechiffrement), nouveaux réglages, recette signée. La clé peut aussi être déclarée en variable d'environnement `PSC_ENCRYPTION_KEY`. Preuve : `tests/maintenance.spec.ts` (parcours, refus serveur, droits, axe ; 3 défauts réintroduits détectés), `bin/verify-key-rotation.php` (23 vérifications, dont la variable d'environnement).

**Côté exploitation (25/09/2026) :** sur le serveur distant, passé en 5.28.0, la clé a été sortie de la base et les IBAN rechiffrés, depuis **Périscolaire › Maintenance**. Reste à tester une restauration avec la clé conservée au coffre.

**Acceptation :** absence/échec des primitives → refus sans écriture en clair ; rotation/restauration testées ; zéro IBAN complet dans logs ou erreurs. **Développement + hébergeur ; M.**

### P1-13 — TRAITÉ (après v5.24.0) — Éviter la perte silencieuse des justificatifs

- [x] **Rendre les écritures fichier/base et les reprises d’échec fiables.**

**État initial (corrigé côté développement) :** les uploads, promotions et réinscriptions ignoraient certains retours d’erreur et pouvaient perdre la source. Ces chemins sont désormais protégés par écriture temporaire/rollback et vérification des retours.

**Avancement technique :** dépôt enfant et promotion d’une demande écrivent avec sauvegarde/restauration de l’ancien fichier, vérifient l’écriture SQL et conservent la zone d’attente si un rattachement échoue ; réinscription vérifie les retours d’inscription et de stockage. La reprise complète multi-enfants et la recette disque/SQL restent à tester.

**Traité le 25/09/2026 :** trois pertes silencieuses restaient.
- **Ajout d'un enfant depuis le portail :** le résultat du dépôt était ignoré. L'enfant était créé sans justificatif, et la famille lisait « Enfant ajouté ». Fiche, inscription et justificatif sont désormais écrits dans une même transaction (`Psc_Frontend_Enfants::create_child_with_document()`). Un échec affiche « L'enfant n'a pas pu être ajouté ».
- **Réinscription :** un fichier invalide sur le deuxième enfant laissait le premier réinscrit. Tous les fichiers sont désormais contrôlés avant la première écriture (`Psc_Frontend_Reinscription::apply_reinscription()`). Une panne pendant l'enregistrement donne un message dédié, et renvoyer le formulaire ne crée pas de doublon.
- **Rattachement après validation :** la zone d'attente « conservée pour reprise » était supprimée par la purge à 90 jours, et aucune reprise n'existait. L'échec est maintenant consigné dans un manifeste (`promotions.json`), repris chaque jour avant la purge, et la zone d'attente survit à sa demande tant qu'il reste des rattachements. La reprise n'écrase jamais un justificatif déposé depuis par la famille.
- Au passage : plus de fichier temporaire laissé après un échec de dépôt, et l'ancien format (JPG remplacé par un PDF) est retiré aussi lors d'un rattachement.

**Preuve :** `bin/verify-document-writes.php`, lancé en CI (34 vérifications : disque plein, échec SQL, deuxième enfant invalide, panne au deuxième enfant puis renvoi, purge, reprise, document plus récent). Vérifié par mutation : chacun des six correctifs retiré fait échouer au moins une vérification. `tests/justificatifs-ecritures.spec.ts` couvre les messages et axe ; `tests/pickup-persons.spec.ts` vérifie qu'un enfant ajouté a bien son justificatif.

**Acceptation :** disque plein, accès refusé, échec SQL, deuxième enfant invalide → ancien document préservé et résultat explicite ; reprise sans perte ni doublon. **Développement ; M/L.**

### P1-14 — TRAITÉ (après v5.20.0) — Corriger les référentiels de temps du verrou de modification

- [x] **Comparer des timestamps Unix réels et afficher le même instant que celui contrôlé.**

**État initial (corrigé côté développement) :** `psc_now_ts()` utilisait un timestamp WordPress décalé, alors que la date limite était Unix réelle. La comparaison utilise désormais un timestamp Unix cohérent.

**Constaté le 24/09/2026 :** l’affichage restait faux. `psc_lock_message()` passait le timestamp Unix réel à `date_i18n()`, qui attend un timestamp décalé du fuseau du site : à Paris, « Modifiable jusqu’au 12 janvier à 23:00 » pour une échéance réellement contrôlée le 13 à 00:00, soit 1 h plus tôt en hiver et 2 h en été.

**Traité le 24/09/2026 :** affichage par `wp_date()`, qui montre l’instant contrôlé en heure du site. Le délai reste compté en heures réellement écoulées : aux changements d’heure, l’échéance n’est donc pas à minuit (27 mars 23:00 avant le lundi qui suit le passage à l’heure d’été, 24 octobre 01:00 avant celui qui suit le passage à l’heure d’hiver), mais l’affichage correspond exactement à ce qui est contrôlé. Choix documenté dans `psc_lock_deadline_ts()` : l’arithmétique en heure légale varie selon les versions de PHP (7.4 à 8.3). `psc_lock_hours()` devient filtrable pour les tests. **Preuve :** `bin/verify-lock-clock.php`, lancé en CI (37 vérifications) — une seconde avant, à l’échéance et une seconde après ; Paris en hiver et en été ; les deux changements d’heure ; un site en UTC ; un délai nul ; `psc_now_ts()` aligné sur `time()`. Vérifiée par mutation : avec `date_i18n()`, 4 cas échouent. Elle remplace `tests/integration/planning-lock-clock.php`, qui ne couvrait que l’instant juste avant l’échéance et l’échéance elle-même.

**Hors périmètre, à reprendre avec P1-08 :** plusieurs purges (conversations, consultations d’espace famille, rétention) calculent encore leur seuil avec `current_time('timestamp')`. Cela reste cohérent tant que les colonnes comparées sont écrites en heure locale, mais aucun test ne couvre les changements d’heure pour elles.

**Acceptation :** tests juste avant/à/après échéance, Paris hiver/été, transitions d’heure, fuseau UTC et délai zéro. **Développement ; S/M.**

### P1-15 — TRAITÉ (après v5.28.1) — Supprimer les cumuls restants et aligner CSV, courriels et forfait sans repas

- [x] **Définir puis partager une règle unique de prestation facturable.**

**État initial (partiellement corrigé côté développement) :** l’export CSV appelait auparavant `psc_billing_services()` sans le flag enfant, ce qui pouvait diverger du calcul FSR. Il transmet désormais ce flag ; les cas métier forfait/retraits/fermetures et les libellés de récapitulatif restent à valider.

**Avancement technique :** l’export CSV des inscriptions transmet désormais le flag enfant sans repas à la même fonction `psc_billing_services()` que les factures et le planning. La matrice métier forfait/retraits/fermetures et la vérification des historiques restent à finaliser avec la facturation.

**Constaté le 26/09/2026 (sonde sur un enfant de test) :**
- un forfait dont la famille retirait la cantine était facturé forfait + garderies, soit 18,25 € au lieu de 11,70 € : c'est le cumul restant ;
- un retrait de garderie gardait le prix du forfait (11,70 €), alors qu'une fermeture de la même garderie le faisait retomber sur les prestations restantes ;
- trois prestations cochées séparément coûtaient 12,35 €, plus que le forfait ;
- les effectifs du calendrier ignoraient le drapeau « sans repas », d'où un double comptage.

**Décisions (26/09/2026) :**
- règle unique : une journée complète (matin, midi, soir) est facturée au forfait, ou au forfait sans repas, sans jamais dépasser la somme de ses créneaux ; sinon, chaque créneau est facturé au tarif unitaire ;
- la règle s'applique aux factures non envoyées ; une facture envoyée n'est jamais rectifiée par le seul changement de règle.

**Traité :**
- `psc_billing_services()` applique la règle 2, et `psc_billing_rule_version()` est inscrite dans l'instantané de chaque facture (`calcul`). Une facture envoyée est recalculée avec sa règle d'origine (`psc_billing_services_regle1()` conservée), de sorte que seul un vrai changement de déclarations la rectifie.
- Factures, estimations, récapitulatifs, export CSV et effectifs du calendrier (drapeau compris) passent par la même fonction.
- Avec les tarifs par défaut, le forfait sans repas (9,00 €) dépasse ses créneaux (7,55 €) et ne s'applique donc plus ; la documentation invite à le fixer sous ce seuil.

**Preuve :** `bin/verify-billing-rule.php`, lancé en CI (29 vérifications : matrice, aucun dépassement des créneaux, facture envoyée sous la règle 1 non rectifiée par le changement de règle mais rectifiée par un changement de déclarations, facture non envoyée égale à l'estimation du portail). Vérifié par mutation : 4 défauts réintroduits, tous détectés. `tests/planning-2.spec.ts` couvre le chemin FSR jusqu'au PDF.

**Acceptation :** matrice FORF/CANT/MSR/FSR avec retraits et fermetures ; une seule facturation du créneau ; PDF, CSV et totaux cohérents ; récapitulatif intelligible. **Développement + facturation ; M.**

### P1-16 — PARTIELLEMENT AVANCÉ — Figer les factures et historiser les paramètres influant sur le passé

- [ ] **Séparer brouillon recalculable, document émis et correction comptable.**

**Constaté :** `class-psc-invoices.php:103` relit tarifs et flags actuels ; régénérer remplace total, date, fichier et `sent_at` sous le même identifiant. Le schéma ne stocke pas de lignes tarifaires figées. `generate_month():40` ne sélectionne que les enfants/familles actuellement actifs : un enfant sorti peut être omis lors d’une première facturation tardive. Le statut sans repas, non daté, modifie aussi la résolution des jours passés.

**À faire :** snapshot des lignes, tarifs, identités utiles et version du calcul ; périodes d’effet des tarifs/flags ; sélection selon l’activité au mois facturé ; procédure de correction validée par la facturation ; montants en centimes ou calcul décimal maîtrisé.

**Avancement technique :** une facture porte désormais un instantané (`invoices.lines_json`) de ses lignes, du tarif unitaire appliqué et du statut « sans repas » de chaque enfant, plus un numéro de version. Une facture **émise** (`sent_at` renseigné) ne se réécrit plus : si le calcul est inchangé, la régénération ne touche ni la base ni le PDF ; s’il a changé, la version remise est archivée telle quelle dans `psc_invoice_versions` — PDF déplacé sous un nom versionné, jamais écrasé —, la nouvelle version repart non envoyée, porte un numéro distinct (`AA-MM-NNN-R2`) et l’opération est journalisée (`facture.rectification`, niveau critique). Preuve : `tests/integration/invoice-snapshot.php` (31 vérifications, dont l’instantané archivé qui conserve l’ancien tarif après changement de grille, et le PDF remis identique octet pour octet), vérifiée par mutation.

**Suppression protégée (après v5.24.0) :** « Supprimer les factures du mois » ne supprime plus que les factures jamais envoyées et sans version archivée. La suppression totale n'est possible qu'en mode debug, activé par WP-CLI (`psc_invoice_debug_delete`), signalé par une alerte et tracé dans le journal d'audit. Preuve : `bin/verify-invoice-deletion.php`, `tests/invoices.spec.ts`.

**Périodes d’effet (schéma 4.17.0, validé le 26/09/2026) :** tables `psc_tarifs` (code, prix en centimes, début, fin) et `psc_sans_repas` (enfant, début, fin) ; la colonne `children.cantine_sans_repas` et l’option `psc_service_prices` sont reprises puis supprimées par la migration. Chaque jour est résolu avec le statut de ce jour et facturé au tarif de ce jour ; un changement en cours de mois coupe la ligne de facture. Réglages › Tarifs : « Nouveau tarif à partir du » et historique ; fiche enfant : « Cantine sans repas / Rétablir les repas à partir du ». Preuves : `bin/verify-dated-tariffs.php` (29 vérifications, dont la migration), `tests/integration/invoice-snapshot.php` (35, en CI : un tarif ou un statut daté d’après le mois ne rectifie pas une facture émise, daté dans le mois il la rectifie), `tests/tarifs-dates.spec.ts`, tests unitaires.

**Calcul en centimes (26/09/2026, option « calcul décimal maîtrisé », sans changement de schéma) :** factures, estimations du portail et plafond du forfait se calculent en centimes entiers (`psc_billing_tariffs()[code]['centimes']`), convertis en euros une seule fois ; plus d'addition de flottants ni de marge de tolérance. `invoices.total` reste en `DECIMAL(10,2)`. Les instantanés des factures émises restent identiques octet pour octet : ancienne et nouvelle formule comparées sur 5 001 prix × 25 quantités (tests unitaires), et une facture émise avec la 5.32.0 puis régénérée ne change ni de version ni d'instantané.

**Procédure de correction validée par la facturation le 26/09/2026** : rectification d'une facture émise par une nouvelle version (numéro `-R2`…), version remise archivée avec son PDF, déclenchée seulement par un changement de déclarations ou par un tarif / statut daté dans le mois facturé.

**Restant :** la qualification comptable exacte du PDF reste à confirmer par la mairie.

**Acceptation :** changer un tarif, sortir un enfant ou modifier son flag en octobre n’altère pas une facture de septembre déjà émise ; correction identifiable et archive conservée selon la politique validée. La qualification comptable exacte du PDF doit être confirmée par la mairie. **Développement + facturation/archives ; L.**

### P1-17 — TRAITÉ (après v5.24.0) — Sécuriser les migrations et déplacements du stockage

- [x] **N’enregistrer une migration comme réussie qu’après vérification complète.**

**Constaté :** `class-psc-installer.php:46` lance les migrations au chargement et enregistre `psc_db_version` sans bilan de tous les retours SQL. Aucun verrou global de migration n’est visible. `move_tree():569` ignore les erreurs de déplacement et supprime la source lorsqu’un nom existe déjà à destination sans vérifier que les contenus sont identiques. `sync_private_dir():140` mémorise ensuite le nouveau chemin.

**Avancement technique :** un verrou d’exécution avec reprise après expiration empêche désormais les migrations concurrentes ; un conflit de contenu conserve la source et signale l’échec. Les migrations restent idempotentes et la version n’avance pas si le nettoyage préalable échoue ; la recette interruption/reprise reste à compléter.

**Traité le 25/09/2026 :**
- **Montée par étapes vérifiées :** chaque migration (et chaque passe de `dbDelta()`) n'est franchie qu'en l'absence d'erreur SQL, mesurée sur `$EZSQL_ERROR`, hors sondes `DESCRIBE` de `dbDelta()`. `psc_db_version` avance étape par étape : une montée interrompue reprend à l'étape en échec sans rejouer les précédentes. L'échec est consigné (`psc_migration_failed` : étape, nature de la requête et table, jamais son texte), journalisé une fois, et signalé par une alerte. La reprise a lieu à chaque écran d'administration, et au plus toutes les 5 minutes côté public.
- **Verrou atomique :** `INSERT IGNORE` sur la clé unique, reprise d'un verrou abandonné par `UPDATE` conditionnel, restitution par le seul détenteur. Le verrou précédent (lecture puis écriture) laissait passer deux processus. Plus aucune écriture d'option à chaque visite quand rien n'est à faire.
- **Déménagements :** `sync_private_dir()` retenait le nouveau chemin même après un échec : les documents restés à l'ancien emplacement, possiblement exposé, n'étaient plus jamais déplacés. Le chemin n'est désormais retenu qu'une fois tout déplacé. La migration 3.7.0 (sortie de `uploads/periscolaire`) ignorait aussi l'échec : elle est reprise à chaque chargement, sous garde-fou, sans bloquer le schéma. Le témoin `psc-probe.txt`, aléatoire et propre à chaque dossier, faisait échouer tout déménagement : les garde-fous ne sont plus déplacés, et ne quittent la source qu'une fois celle-ci vidée. Une alerte d'administration nomme les dossiers et le nombre de fichiers restés.

**Preuve :** `bin/verify-migration-resume.php`, lancé en CI sous `www-data` (42 vérifications : verrou, erreur SQL en cours d'étape, progression conservée, reprise espacée, échec de `dbDelta`, conflit de contenu, droit refusé, `uploads/periscolaire`). Vérifié par mutation : chacun des 9 défauts réintroduits fait échouer au moins une vérification. `bin/verify-migrations.php` (montée 2.4.9 → courante) reste conforme. `tests/migrations-alertes.spec.ts` couvre les alertes (contenu, réservées à la configuration, axe).

**Reste côté exploitation :** rejouer une montée sur une copie du serveur distant (P1-18).

**Acceptation :** migrations ancienne version → actuelle avec interruption/permission refusée/conflit de fichier ; aucun document perdu, version non avancée à tort, reprise vérifiable. **Développement + exploitation ; L.**

### P1-18 — Valider les prérequis du serveur distant et sa restauration

- [ ] **Constituer une fiche de recette d’hébergement pour l’installation par ZIP.**

**À vérifier, pas constaté en défaut :** version WordPress/PHP/base, TLS et cookies `Secure`, thème/plugins, cache, droits fichiers, SMTP, cron, accès admin, journaux et sauvegardes du serveur distant. Le ZIP ne configure pas ces éléments. Le guide Compose est un guide de test ; son exemple de sauvegarde (`docs/self-hosting-docker.md:172`) couvre `uploads/` mais pas le dossier privé par défaut ni la clé de chiffrement.

**À faire :** sauvegarder base, fichiers privés et configuration/clé nécessaires ; stockage protégé hors racine web, rétention définie, restauration isolée testée avec envoi d’e-mails neutralisé ; fixer objectifs de perte de données et de reprise. Surveiller l’exécution réelle du cron, les erreurs et la capacité disque.

**Avancement technique :** la fiche de recette existe (`documentation/installation/fiche-recette-p1.md`) et couvre socle technique, TLS et cookies, cache, stockage privé, e-mails, cron, sauvegardes et restauration, chaque ligne à « à vérifier », avec la personne qui constate et la manière de le constater. La sauvegarde du répertoire privé et de la clé, puis la restauration isolée à e-mails neutralisés, sont documentées (`installation/sauvegarde-mise-a-jour.md`, `docs/self-hosting-docker.md`). Rien n’est constaté sur le serveur distant : la fiche reste entièrement ouverte tant qu’elle n’est pas remplie et datée.

**Acceptation :** restauration complète d’un dossier fictif avec assurance, facture et IBAN déchiffrable ; contrôles P1-03/P1-04 réussis sur l’hébergement réel ; preuve datée disponible. Références : [CNIL — sauvegarder](https://www.cnil.fr/fr/securite-sauvegarder), [sécuriser les serveurs](https://www.cnil.fr/fr/securite-securiser-les-serveurs). **Hébergeur + administrateur + DPO ; M/L.**

## P2 — Fiabilisation et prévention des régressions

### P2-01 — TRAITÉ (après v5.20.0) — Aligner les cases visibles sur les déclarations réellement prises en compte

- [x] **Traiter les anciennes déclarations CANT et les exceptions couvertes par un forfait.**

**Constaté / sonde partielle :** `class-psc-planning.php:341` construit `month_state()` à partir des données brutes sans la conversion sans repas appliquée par `declared_map()`. Depuis v5.4.1, une ancienne case CANT peut donc être masquée alors qu’elle continue de produire du MSR au calcul. `psc_exception_write_decision():146` ne reçoit pas l’exception FORF du jour : pour retirer GM couvert seulement par cette exception, il décide `delete` sur la base du rythme vide, ce qui ne matérialise pas le retrait attendu.

**Traité le 24/09/2026 :**
- **Retrait sous exception FORF :** déjà corrigé (`toggle_exception()` transmet l’exception FORF du jour) ; désormais prouvé par test.
- **Enfant « cantine sans repas » :** `month_state()` et `month_explicit_map()` appliquent la même conversion que la facturation. La cantine du rythme ou d’une ancienne exception apparaît en midi sans repas, modifiable dans les deux sens.
- **Écriture du midi sans repas :** pour ces enfants, la décision se calcule par cette conversion. Une ancienne exception d’ajout de cantine, qui l’emportait sur tout retrait, est retirée quand la famille retire son midi : c’est la seule donnée supprimée.
- **Changement de rythme :** `toggle_pattern()` ne purge plus comme « bruit » le retrait du midi d’un tel enfant.
- **Services fermés :** un ajout sur une prestation fermée ce jour-là, ou un forfait dont une composante est fermée, est refusé (`service_closed`, message existant côté famille) ; le retrait reste permis.

**Preuve :** `bin/verify-planning-states.php`, lancé en CI (≈1 100 vérifications). Pour chaque (date, prestation) du mois, Planning 1 et 2 affichent ce que compte `declared_map()`, avant et après le passage « avec repas → sans repas » d’un planning rempli, et avant et après chaque retrait, ajout ou changement de rythme. Le script vérifie aussi le retrait sous FORF et le refus sur une prestation fermée. Il a d’abord échoué sur le code d’origine (28 échecs), puis a détecté la purge du retrait par `toggle_pattern()` introduite en cours de correctif.

**Acceptation :** chaque case visible peut être modifiée conformément à son état réel et une case cachée ne maintient pas une inscription incompréhensible ; retraits sous exception FORF persistants. **Développement ; M.**

### P2-02 — TRAITÉ (après v5.20.0) — Gérer concurrence et échecs des écritures métier

- [x] **Rendre atomiques les opérations composées et vérifier les retours SQL.**

**Constaté :** `toggle_pattern()` supprime les conflits, écrit puis fige les dates en plusieurs requêtes non transactionnelles ; de nombreux retours SQL ne conditionnent pas le succès. `approve_request()` utilise une transaction, mais ne verrouille pas la demande ni ne la réclame atomiquement avant création. Deux validations simultanées d’une demande pendante ne sont pas explicitement sérialisées. L’unicité du second e-mail de parent repose sur une lecture préalable entre deux colonnes, pas sur une identité normalisée unique.

**À faire :** transactions et verrous ciblés, transition de statut conditionnelle, idempotence, gestion de conflit ; données d’identité contraintes en base. Distinguer succès, absence de changement et échec.

**Avancement (24/09/2026) :**
- **Validation d’une demande :** `approve_request()` réclame la demande sous verrou (`SELECT … FOR UPDATE` en tête de transaction) et refuse si elle n’est plus en attente. Deux validations concurrentes sont sérialisées ; la seconde ne crée rien. Les alertes alimentation partent après le commit, jamais pour un enfant annulé.
- **Refus d’une demande :** conditionnel (`WHERE status = 'pending'`) ; il n’écrase plus une validation intervenue entre-temps et ne prévient pas la famille à tort.
- **Écritures du planning :** `toggle_pattern()` et `toggle_exception()` se font en transaction, sous verrou de la ligne enfant. Chaque retour SQL est vérifié ; tout échec annule l’ensemble et répond `error`, que l’AJAX transmet (500) au lieu d’un succès.
- **Preuve :** `bin/verify-write-integrity.php`, lancé en CI. La concurrence est jouée par une seconde connexion MySQL qui tient le verrou ; l’échec intermédiaire, par une requête sabotée. Il couvre la double validation, la validation concurrente, l’échec au second enfant (ni famille, ni enfant, ni e-mail), le refus d’une demande déjà validée, l’échec au milieu d’un changement de rythme, et un clic concurrent sur le même enfant. Vérifié par mutation : sans la réclamation ni le refus conditionnel, 6 cas échouent.

- **Identités de connexion (24/09/2026) :** un index UNIQUE sur `second_parent_email` est posé comme les autres contraintes (hors dbDelta, retenté à chaque écran admin, signalé s’il est refusé ou si des doublons existent). Le croisement avec l’adresse du titulaire d’un autre foyer, qu’aucun index ne peut exprimer, passe par un verrou nommé MySQL (`Psc_Parents::identity_lock()`) et une lecture verrouillante (`email_in_use()`) : vérification et écriture sont sérialisées dans `create()`, `update()` et la confirmation de changement d’adresse, et voient le dernier état validé même depuis une transaction ouverte plus tôt. Vérifié par `verify-write-integrity` (30 vérifications au total), y compris par mutation : sans verrou ni lecture verrouillante, 3 cas échouent.

**Hors périmètre de l’acceptation, à reprendre au fil de l’eau :** appliquer la même discipline (transaction, retours SQL vérifiés, transition conditionnelle) aux autres écritures composées — passage d’année et génération des factures ; les messages et conversations sont déjà transactionnels.

**Acceptation :** deux validations/clics concurrents, échec d’une requête intermédiaire → aucune famille dupliquée, aucun rythme partiellement détruit, réponse fidèle à l’état enregistré. **Développement ; L.**

### P2-03 — TRAITÉ (après v5.21.0) — Valider le contenu des justificatifs, pas seulement leur extension

- [x] **Renforcer la validation MIME/contenu et la gestion des fichiers non fiables.**

**Constaté :** `class-psc-assurances.php:121` contrôle l’erreur d’upload, la taille déclarée et `wp_check_filetype()` sur le **nom**. Un fichier renommé `.pdf` n’est pas pour autant un PDF valide. Le stockage privé, le contrôle d’accès et `nosniff` existent ; aucune exécution de code par upload n’est démontrée ici.

**À faire :** vérifier taille réelle, MIME/signature, cohérence extension/type ; traiter le PDF comme contenu non fiable ; évaluer analyse antivirus et téléchargement en pièce jointe selon le risque. Appliquer la même validation aux demandes en attente.

**Traité le 24/09/2026 :** `psc_validate_document_file()` juge le fichier reçu, avant tout déplacement : taille réelle sur disque, signature binaire (en-tête et marqueur de fin : un fichier tronqué est refusé), type détecté par fileinfo, cohérence avec l’extension annoncée, images décodables par `getimagesize()`. Les PDF sont traités comme contenu non fiable : refus s’ils déclarent du JavaScript, une action de lancement ou des fichiers embarqués ; les formulaires XFA restent admis, car des attestations d’assureurs en portent. Cette recherche ne voit pas un marqueur caché dans un flux compressé : le stockage privé, le contrôle d’accès et `nosniff` restent les défenses en aval. Appliqué aux justificatifs d’assurance (portail, ajout d’enfant, réinscription), aux pièces jointes des conversations et à la promotion des justificatifs en attente, qui reste en zone d’attente en cas de refus. Même code d’erreur `invalid_type` et même message qu’avant : aucun écran modifié.

**Antivirus et téléchargement :** pas d’analyse antivirus embarquée, faute d’analyseur disponible sur un hébergement mutualisé. Le filtre `psc_document_scan` permet d’en brancher un (renvoyer false refuse le fichier). Les documents restent affichés dans le navigateur plutôt que téléchargés en pièce jointe : la mairie les consulte dans l’écran de revue, et forcer le téléchargement n’ajouterait rien une fois le contenu actif refusé.

**Preuve :** `bin/verify-document-validation.php`, lancé en CI (28 vérifications sur de vrais fichiers). Documents valides acceptés ; faux PDF, image renommée, PDF renommé en image, fichier tronqué, image indécodable, PDF avec JavaScript ou fichier embarqué, extension non acceptée, fichier vide, dépassement réel ou taille déclarée mensongère, erreurs d’upload refusés. Il vérifie aussi que l’ancien justificatif est conservé, que la pièce en attente invalide n’est pas rattachée, que les pièces jointes suivent la même règle et que le filtre antivirus est respecté. Vérifié par mutation : sans le contrôle du contenu, 12 cas échouent. De bout en bout, `tests/assurance-review.spec.ts` dépose un faux PDF depuis le portail : message d’erreur affiché, document et décision précédents intacts. Il remplace `tests/integration/assurance-upload-validation.php`.

**Acceptation :** faux PDF/image, fichier vide, dépassement, erreur d’upload et contenu malformé refusés sans détruire l’ancien justificatif. **Développement ; M.**

### P2-04 — TRAITÉ (après v5.21.0) — Rendre fiables les envois et leur statut

- [x] **Suivre les résultats par destinataire et permettre une reprise sans doublon.**

**Constaté :** `class-psc-menus.php:150` boucle synchroniquement et renseigne `sent_at` même si certains ou tous les envois échouent. `class-psc-admin-invoices.php` affiche `sent_all` après une boucle dont les retours ne sont pas exploités. `approve_request()` contient une notification d’allergie avant COMMIT, malgré la séparation annoncée des effets externes. La commande fournisseur envoie puis écrit son historique, sans reprise durable en cas d’échec SQL.

**À faire :** file d’envoi avec états et reprise ; identifiants d’idempotence ; notifications après commit ; distinction accepté par SMTP / délivré. Limiter les pièces personnelles dans les messages selon P1-06.

**Traité le 24/09/2026 (schéma validé avant implémentation) :**
- **Table `psc_envois` :** une ligne par envoi unitaire (objet, destinataire, lot), réservée avant le moindre mail sous une clé d’idempotence unique. États `a_envoyer`, `accepte` ou `echec`, avec le nombre de tentatives et une cause sans donnée personnelle. Aucune adresse n’est stockée : elle est relue à l’envoi. Clé étrangère vers le foyer (cascade), CHECK sur l’état. Chaque clic d’envoi forme un lot : un double clic ou une relance ne renvoient rien de ce qui est accepté, un « Renvoyer » volontaire ouvre un nouveau lot.
- **`Psc_Envois` :** réclamation par verrou optimiste, résultat enregistré ligne par ligne, bilan exact, relance manuelle des seuls échecs. Une tâche planifiée (15 min) reprend les envois restés en attente, après une coupure ou une requête interrompue.
- **Menus :** marqués « envoyé » seulement quand tout le lot est accepté (reprise planifiée comprise). L’écran affiche le bilan et un bouton « Relancer les échecs ».
- **Factures :** l’envoi groupé rend un bilan exact au lieu de « toutes envoyées », et l’écran signale l’échec du dernier envoi. Si la datation échoue après un mail accepté, la facture n’est pas renvoyée en double.
- **Commande fournisseur :** enregistrée avant l’envoi (`sent_at` NULL). Un enregistrement impossible ne laisse rien partir ; un mail refusé laisse la commande dans l’historique, en échec, relançable sans nouveau calcul.
- **Hors du plugin :** « accepté » signifie confié au serveur d’envoi, pas délivré. Savoir si un message est arrivé demanderait de traiter les retours de la messagerie.
- **Alerte alimentation avant COMMIT :** déjà corrigé en P2-02.

**Preuve :** `bin/verify-envois.php`, lancé en CI (28 vérifications, serveur d’envoi simulé). Il couvre l’échec partiel, la double soumission, la relance des seuls échecs, la coupure totale, la reprise d’un envoi interrompu, la facture en double soumission, le renvoi volontaire, la datation en échec et le PDF manquant. Côté fournisseur : enregistrement impossible, mail refusé, double soumission et relance ; enfin, aucune adresse dans les causes. Vérifié par mutation : 5 cas échouent quand on retire l’idempotence ou la condition « tout accepté ». `tests/envois.spec.ts` montre l’échec, relance depuis le navigateur et passe axe sur les trois écrans. Le contraste d’un en-tête de l’écran fournisseur a été corrigé à cette occasion.

**Reste (même mécanisme) :** faire passer les autres envois groupés (fermeture d’un jour, annulation de la cantine d’une classe) par `psc_envois`.

**Acceptation :** coupure SMTP/timeout/échec de persistance → bilan exact, relance des seuls échecs, aucun succès fictif ni notification d’une opération annulée. **Développement + messagerie ; M/L.**

### P2-05 — TRAITÉ (après v5.22.0) — Borner les imports de calendriers et prévenir les requêtes internes

- [x] **Sécuriser l’URL ICS, les redirections et le volume téléchargé.**

**Constaté :** URL configurable par un gestionnaire (`class-psc-admin-config.php:53`) ; import via `wp_remote_get()` avec timeout, sans contrôle explicite d’adresses privées ni limite de réponse (`class-psc-school-calendar.php:160`). Risque SSRF pour un compte disposant de cette capacité ; pas une route publique sans authentification.

**À faire :** URL sûre, schéma et destinations autorisés, contrôles sur les redirections, taille/temps bornés ; import transactionnel ou préparé avant remplacement ; validation des dates et événements aberrants.

**Traité le 24/09/2026 :**
- **Validation de l’adresse (`Psc_School_Calendar::validate_ics_url()`) :** http(s) seulement, port 80 ou 443, pas d’identifiants dans l’adresse. Toutes les adresses résolues de l’hôte (IPv4 et IPv6) doivent être publiques : loopback, réseaux privés, lien local (métadonnées cloud) et plages réservées sont refusés.
- **Enregistrement du réglage :** une adresse refusée n’est pas enregistrée, l’ancienne est conservée, et l’écran le signale.
- **Téléchargement :** redirections suivies à la main (3 au plus), chacune revalidée ; réponse limitée à 2 Mo, comme le téléversement manuel.
- **Contrôle avant écriture :** refus d’un calendrier sans jour de zone C, aux dates à plus de 5 ans ou avec une fermeture continue de plus de 100 jours.
- **Écriture en transaction :** un import refusé ou raté laisse le calendrier intact.

**Preuve :** `bin/verify-ics-import.php`, lancé en CI (31 vérifications, réseau et DNS simulés). Il refuse loopback, localhost, réseau privé, métadonnées cloud, IPv6 interne, hôte résolu en interne (y compris sur une seule de ses adresses), schémas `file` et `ftp`, port exotique, identifiants et hôte introuvable. Côté import, il refuse la redirection vers le réseau interne, la boucle de redirections, la réponse trop volumineuse, l’ICS invalide, les dates aberrantes, la fermeture de 150 jours et la réponse d’erreur, et vérifie à chaque fois que le calendrier est intact. Un import valide passe, y compris après une redirection publique. Vérifié par mutation : 12 cas échouent quand toute adresse est acceptée. `tests/ics-import.spec.ts` couvre l’écran Réglages, contrôle axe compris.

**Acceptation :** loopback, adresse privée, redirection interne, réponse trop grosse et ICS invalide refusés ; calendrier existant intact. **Développement ; M.**

### P2-06 — TRAITÉ CÔTÉ DÉVELOPPEMENT (après v5.28.0) — Test réseau du site réel encore ouvert — Maîtriser les ressources externes et migrer l’ancienne API Adresse

- [ ] **Inventorier les flux navigateur du site réellement déployé.**

**Constaté :** `assets/js/guest.js:353` appelle directement `api-adresse.data.gouv.fr` et envoie la saisie à partir de trois caractères. La documentation BAN annonce la dépréciation de cette API et le décommissionnement de l’URL fin janvier 2026 ; sa disponibilité effective depuis le serveur/navigateur cible n’a pas été testée. Le thème séparé `theme/Archive/functions.php:24` charge Google Fonts, alors que le plugin possède des polices locales. L’utilisation de ce thème en production reste à confirmer.

**À faire :** auto-héberger les polices si le thème est utilisé ; inventorier aussi analytics/widgets ajoutés hors plugin ; valider avec le DPO la nécessité du flux d’adresse et son destinataire.

**Traité le 24/09/2026 — migration vers la Géoplateforme :** l’ancienne URL répondait encore, mais en annonçant elle-même sa fin — en-têtes `deprecation`, `sunset` au 31/01/2026 (donc dépassés depuis huit mois) et `x-api-deprecated: true`. Elle pouvait disparaître sans préavis et emporter la recherche d’adresse du parcours d’inscription. `assets/js/guest.js` appelle désormais `https://data.geopf.fr/geocodage/search`. La bascule était sans risque : sur une même requête, les deux services renvoient des propriétés identiques champ pour champ (`banId` et `score` compris — c’est le même moteur, l’ancienne URL n’étant plus qu’un proxy), mêmes paramètres `q`/`limit`, mêmes en-têtes CORS. Le mock Playwright (`tests/pickup-persons.spec.ts`) et la mention affichée aux familles (`class-psc-frontend.php`) suivent. Vérifié dans un navigateur réel contre le service réel : requête en 200, suggestions affichées, trio adresse/code postal/ville rempli à la sélection.

**Conséquence pour le DPO :** le destinataire du flux de saisie d’adresse passe de la DINUM (`data.gouv.fr`) à l’IGN (Géoplateforme). Deux opérateurs publics français, aucun transfert hors UE, mais le nom du destinataire est à corriger partout où la notice le mentionne. Le quota annoncé du nouveau service est de 50 requêtes par seconde et par IP ; la saisie reste déclenchée à partir de trois caractères, avec anti-rebond de 250 ms et annulation de la requête précédente.

**Restant :** la saisie manuelle existe déjà en repli et n’a pas changé. Le volet polices (`theme/Archive/functions.php:24` charge Google Fonts alors que le plugin embarque ses polices) et l’inventaire des flux tiers du site réellement déployé restent entiers.

**Traité le 25/09/2026 (volet polices) :** le thème chargeait Public Sans depuis `fonts.googleapis.com`, transmettant l'adresse IP de chaque visiteur, familles comprises, à Google. Il la sert désormais lui-même (`theme/Archive/assets/fonts`, `assets/css/fonts.css`, thème 1.1.0), avec la licence de la police (OFL 1.1 et CC0), ajoutée aussi aux fichiers de l'extension, qui ne l'embarquaient pas. **Preuve :** `tests/ressources-externes.spec.ts` charge l'accueil, le formulaire d'inscription et quatre écrans du portail, et échoue sur toute requête sortant du domaine du site ; il vérifie aussi que Public Sans est bien chargée. Vérifié par mutation : l'appel à Google Fonts rétabli fait échouer le test. Le seul flux tiers restant est l'API Adresse de la Géoplateforme, déclenchée à la saisie d'une adresse.

**Reste :** le test réseau du site réellement déployé (extensions, widgets ou mesure d'audience ajoutés hors de l'extension), à faire dans le navigateur, onglet Réseau, sur l'accueil et le portail ; puis faire valider par le DPO le flux vers la Géoplateforme.

**Acceptation :** parcours utilisable sans réponse du prestataire ; aucun chargement tiers non identifié ; test réseau du site réel. Les cookies d’authentification strictement nécessaires ne justifient pas, à eux seuls, un bandeau de consentement ; les autres traceurs sont à examiner séparément. Références : [BAN — documentation API](https://adresse.data.gouv.fr/outils/api-doc/adresse), [CNIL — cookies nécessaires](https://www.cnil.fr/fr/cookies-et-autres-traceurs/que-dit-la-loi). **Développement + DPO ; M.**

### P2-07 — TRAITÉ (après v5.22.0) — Sécuriser les changements rapides d’enfant et les états de chargement

- [x] **Tester et neutraliser les réponses AJAX devenues obsolètes.**

**Constaté / scénario à tester :** `assets/js/planning-2.js` ne verrouille pas les onglets enfants dans `setBusy()` ; `loadMonth()` ne porte pas d’identifiant de requête courante ni d’annulation. Deux réponses arrivant en ordre inverse peuvent afficher un état inattendu. La restauration générale de `disabled` doit également respecter les règles recalculées par le serveur.

**À faire :** ignorer les réponses obsolètes ou sérialiser la navigation ; synchroniser onglet actif, enfant affiché et destination de chaque écriture ; préserver les libellés accessibles après changement d’enfant.

**Traité le 24/09/2026 (`assets/js/planning-2.js`) :**
- **Réponses de chargement :** chaque chargement (enfant ou mois) porte un numéro ; seule la réponse du dernier est appliquée, une réponse périmée est ignorée.
- **Navigation sérialisée :** les onglets enfants sont verrouillés pendant un chargement, comme les cases.
- **Enfant affiché :** l’enfant réellement affiché est distinct de l’onglet cliqué. Toute écriture (case, rythme, Tout/Aucun, revenir au rythme, copie fratrie) vise l’enfant affiché. Une réponse d’écriture arrivée après un changement d’enfant ou de mois reste enregistrée, mais ne réécrit pas l’affichage courant.
- **Échec de chargement :** onglets et affichage reviennent à l’enfant réellement affiché.
- **Fin de chargement :** `setBusy(false)` ne rend la main qu’aux éléments qu’il avait lui-même désactivés. Une case reconstruite par le serveur (jour verrouillé, prestation fermée) garde son état.

**Preuve :** `tests/planning-2-navigation.spec.ts` ralentit ou coupe `admin-ajax.php` : verrouillage des onglets pendant un chargement lent, écriture lente suivie d’un changement d’enfant (affichage du nouvel enfant préservé, écriture enregistrée pour l’enfant cliqué), échec de chargement (retour à l’enfant affiché, clic dirigé vers lui). Les 3 scénarios échouent avec l’ancien script et passent avec le nouveau. Pas de régression sur `planning-2` et `planning-overflow` (15/15).

**Acceptation :** réseau ralenti, clic A/B/A, réponse inversée et échec réseau → aucun affichage ou clic dirigé vers le mauvais enfant. **Développement ; M.**

### P2-08 — TRAITÉ (après v5.25.0) — Réconcilier les deux représentations d’année scolaire

- [x] **Documenter puis faire respecter les invariants entre `school_years` et `school_year`.**

**Constaté :** classes/assurances s’appuient sur `Psc_School_Years`, tandis que dates, vacances et verrous utilisent `Psc_School_Year`. Les années sont sélectionnées par des règles différentes. `class-psc-school-years.php:151` archive l’année active puis active la suivante sans transaction ni vérification complète de réussite. La réinscription ignore les enfants décochés, sans retirer une confirmation déjà enregistrée lors d’un envoi antérieur.

**À faire :** invariants d’activation, calendrier, dates, assurance et classe ; reprise de promotion ; règle explicite pour une réinscription modifiée. Vérifier le statut annuel au-delà du seul `children.statut` global.

**Avancement technique (24/09/2026) :**
- **Activation :** `Psc_School_Years::activate()` se fait en transaction, sous verrou des années. Le résultat est vérifié (exactement une année active), et réactiver l’année active ne change rien. Avant, un échec entre l’archivage et l’activation laissait le site sans année active (constaté par mutation). L’année activée reçoit sa configuration de calendrier (`school_year`) sous la même clé que son libellé.
- **Passage d’année :** `apply_promotion()` est tout ou rien. Un échec sur un enfant n’en promeut aucun et laisse le plan en attente, prêt à être rejoué, avec un message dédié. Le rejouer ne duplique rien. `mark_sorti()` ne traite plus un enfant déjà sorti comme un échec.
- **Preuve :** `bin/verify-school-year-integrity.php`, lancé en CI (12 vérifications). Vérifié par mutation : 5 échecs avec l’ancien code.

**Reste (décisions métier + schéma, à valider avant implémentation) :**
- fusionner les deux représentations (`school_years` pour classes et assurances, `school_year` pour dates, vacances et délai) en une seule table, avec une seule règle de sélection de l’année courante ;
- règle d’une réinscription modifiée : un enfant décoché après une confirmation déjà envoyée doit-il perdre son inscription à l’année suivante ?
- statut annuel de l’enfant au-delà de `children.statut` global.

**Décisions de la mairie (25/09/2026) :** un enfant décoché après une réinscription déjà envoyée perd son inscription à l'année suivante ; le statut de l'enfant est porté par année. Le schéma a été validé, avec trois choix : étape de migration incluse, mairie informée par un modèle d'e-mail éditable, libellé libre supprimé.

**Traité le 25/09/2026 (schéma 4.15.0) :**
- **Une seule table d'année :** `psc_school_years` porte le dossier et le calendrier (`year_key` '2026-2027' unique, déduite de la date de début ; `vacation_ranges`, `lock_hours`). `psc_school_year` et le libellé libre sont supprimés. Deux règles de sélection, et deux seulement : l'année administrative est celle au statut `active` ; l'année d'une date est celle qui la couvre, sinon celle de sa rentrée. `Psc_School_Year` garde son interface, en lisant la même table.
- **Clés étrangères** : `holidays.year_key` et `pattern.school_year` → `school_years.year_key`, `ON DELETE CASCADE ON UPDATE CASCADE`. Contraintes CHECK sur les statuts d'année (`preparation|active|archivee`) et d'inscription (`inscrit|sorti`).
- **Statut par année :** `child_school_years.statut` (`inscrit|sorti`) et `sorti_le`. `children.statut` et `children.sorti_le` sont supprimées. Un enfant est actif s'il est inscrit à l'année considérée. Les listes datées (intervenants, commande fournisseur, fermetures, export, calendrier) prennent l'année de la date ; le portail et les menus prennent l'année active ou celle en préparation. L'écran **Enfants** filtre par état pour l'année choisie : inscrit, sorti, non inscrit.
- **Réinscription modifiée :** l'enfant décoché qui était déjà réinscrit perd sa ligne et son justificatif de l'année cible, une fois tous les fichiers contrôlés. C'est journalisé (`enfant.reinscription_retiree`), et la mairie reçoit le modèle éditable « Réinscription retirée par la famille ».
- **Rétention :** la purge vise un enfant inscrit ni à l'année active ni à celle en préparation, 400 jours après sa dernière sortie ou la fin de sa dernière année d'inscription.
- **Migration 4.15.0 :** clés déduites, calendrier repris (ses dates l'emportent, elles ont servi à la facturation), années citées par le planning créées, statut global reporté sur les lignes d'année, puis colonnes et table supprimées. Elle refuse de choisir entre deux années d'une même rentrée qui portent chacune des inscriptions : alerte explicite. Au passage, un défaut de P1-17 est corrigé : une passe finale en échec après la dernière étape n'était plus retentée.

**Preuve :** `bin/verify-school-year-model.php`, lancé en CI (24 vérifications : une année par rentrée, calendrier et dossier sur une ligne, enfant actif, retrait après réinscription, rétention). Vérifié par mutation : chacun des 7 défauts réintroduits fait échouer au moins une vérification. `bin/verify-migrations.php` (montée 2.4.9 → 4.15.0 : 33 vérifications, dont 7 nouvelles), migration réelle d'une base 4.14.0 rejouée deux fois (idempotente), `bin/verify-promotion-logic.php` (sortie posée sur l'année quittée, avec sa date), `tests/school-year-promotion.spec.ts` (retrait après réinscription : ligne supprimée, journal, e-mail à la mairie). Les autres scripts et spécifications sont adaptés au modèle.

**Acceptation :** activation en échec, double activation, enfant non réinscrit, modification d’une réinscription et consultation historique donnent un état cohérent dans planning/listes/factures. **Développement + métier ; M/L.**

### P2-09 — TRAITÉ (après v5.22.0) — Mesurer et réduire les lectures complètes et traitements synchrones

- [x] **Établir un budget de requêtes et de latence sur un effectif représentatif.**

**Constaté :** listes familles/enfants non paginées (`Psc_Parents::all()`, `Psc_Admin_Familles::page_children()`), chargements `SELECT *` ; SIDSCM appelle classe et personnes autorisées enfant par enfant ; génération mensuelle refait des lectures par famille après une première lecture globale. Des lectures groupées existent déjà dans `declared_map()` et sont à préserver.

**À faire :** mesurer avec données synthétiques au volume cible, charger seulement les colonnes nécessaires, grouper/paginer, traiter les grosses tâches par lots. Ne pas ajouter un cache partagé de dossiers pour résoudre la performance.

**Traité le 24/09/2026 :**
- **Budget mesuré :** `bin/verify-query-budget.php`, lancé en CI, mesure chaque lecture en masse sur un effectif synthétique de N puis 2N enfants (rythmes, personnes autorisées, assurances). Le nombre de requêtes ne doit pas grandir avec l’effectif et doit rester sous le budget : SIDSCM semaine ≤ 25, planning d’un mois en lot ≤ 12, personnes autorisées ≤ 5, écran Enfants ≤ 40. Mesuré : 14-16, 7, 3 et 6 requêtes, constants de 60 à 120 enfants.
- **N+1 corrigés :** SIDSCM (classe lue par enfant, et 3 requêtes par enfant attendu en garderie du soir) → `classes_for()` et `authorized_for_children()` en lot : 201 → 379 requêtes avant, constant après. Écran Enfants (statut d’assurance et année active relus par enfant) → colonnes jointes dans la requête principale : 133 → 253 avant, 6 après. `authorized_for_child()` délègue à la version en lot (une seule source).
- **Génération des factures, laissée en l’état et documentée :** elle relit chaque famille, mais chaque famille produit de toute façon son PDF et sa ligne de facture. Le coût est linéaire par nature, dominé par le PDF, et la génération est reprenable, car régénérer une facture est idempotent (cf. P1-16). La refondre toucherait les règles de gel des factures.
- **Listes non paginées :** l’écran Enfants lit tous les enfants de l’année en 6 requêtes ; la pagination n’est pas justifiée à l’effectif d’une commune. Le temps et la mémoire sont affichés par la mesure à titre indicatif, sans seuil.

**Acceptation :** budget convenu et mesuré, absence de N+1 dominant, mémoire bornée, génération reprenable. Aucun résultat de test de charge n’est revendiqué ici. **Développement ; M.**

### P2-10 — TRAITÉ POUR L’ESSENTIEL (après v5.22.0) — Ajouter une suite de sécurité et de régression métier représentative

- [x] **Compléter les tests qui passent aujourd’hui sans couvrir les défauts prioritaires.**

**Constaté :** tests unitaires/E2E/migrations existants utiles, mais pas de suite systématique identifiée pour la matrice d’accès inter-familles, la révocation/cache, les droits RGPD et la rétention. Les E2E CI utilisent `WP_ENVIRONMENT_TYPE=local`, ce qui désactive la limitation de fréquence ; ils ne valident donc pas son comportement de production.

**À faire :** famille A/B/anonyme, rôles mairie/intervenants, nonce absent/étranger/expiré, documents, révocation, uploads, conservation, échecs disque/SQL, concurrence, profils repas/forfait et changements d’heure. Créer une stack jetable dédiée ; interdire les seed destructifs sur une instance contenant des données réelles.

**Avancement technique (24/09/2026) :** le constat ci-dessus n’est plus exact sur deux points. Les droits RGPD et la rétention sont désormais couverts : `tests/integration/privacy-rights.php` (conservation des pièces comptables lors des deux chemins de suppression, PDF sur disque compris), `tests/unit/retention-policy.php`, `tests/integration/impersonation-retention.php` et `tests/integration/p1-data-protection-summary.php`. S’y ajoutent le confinement du stockage privé (`private-storage-receipt.php`), le gel des factures émises (`invoice-snapshot.php`), la couverture du registre d’audit (`audit-registry.php`) et le relevé de contrats `p1-final-summary.php`. Trois de ces sondes ont été vérifiées par mutation — leur assertion centrale échoue quand on neutralise le correctif —, ce qui satisfait déjà le critère « capturés par des tests qui échouent avant correction » pour les défauts concernés.

**Complété le 24/09/2026 :**
- **Matrice d’accès inter-familles :** `tests/family-isolation.spec.ts`, en vraies requêtes HTTP. Connectée, la famille A vise les données de la famille B par les 6 appels AJAX du planning, 3 formulaires (identité d’un enfant, modification et retrait d’une personne autorisée) et 2 téléchargements (assurance, facture) : tout est refusé et la base de B est vérifiée intacte. Un visiteur anonyme n’atteint ni le planning ni les documents. Sur ses propres données, A est refusée avec un jeton famille absent, étranger (celui de B) ou périmé, ou sans nonce WordPress ; un contrôle positif vérifie que les bons jetons passent. Vérifié par mutation : sans contrôle d’appartenance, les tests échouent.
- **Profil de sécurité (limitation de fréquence) :** le filtre `psc_rate_limit_enabled` peut désormais aussi réactiver la limitation en local. `bin/verify-rate-limit.php`, lancé en CI, vérifie son comportement de production : quota, fenêtre fixe non prolongeable, remise à zéro, compteur par IP, IP indéterminable, en-tête `X-Forwarded-For` forgé ignoré, maillon de confiance lu. Vérifié par mutation.
- **Déjà couverts par les lots précédents :** concurrence (`verify-write-integrity`, P2-02), profils repas et forfait (`verify-planning-states`, P2-01), changements d’heure (`verify-lock-clock`, P1-14).

**Restant :** une stack de CI en `WP_ENVIRONMENT_TYPE=production` avec cache et TLS représentatifs ; la révocation vue depuis un cache objet externe (Redis ou Memcached absents de la stack de test).

**Acceptation :** les défauts P0/P1 sont capturés par des tests qui échouent avant correction ; un profil de sécurité garde rate-limit/cache/TLS représentatifs ; CI obligatoire avant release. **Développement ; L, au fil des corrections.**

### P2-11 — TRAITÉ (après v5.19.0) — Conditionner la release aux contrôles et traiter le nouveau TODO dans le packaging

- [x] **Faire dépendre la publication d’un commit validé et décider du périmètre documentaire du ZIP.**

**Constaté :** `.github/workflows/release.yml` vérifie la version et l’installation/activation du ZIP, mais ne dépend pas du succès des workflows lint/E2E. Ces derniers tournent sur branches/PR ; un tag déclenche sa release séparément. La vérification de complétude échouera si `TODO.md` est ajouté à Git sans être explicitement inclus ou exclu : il ne figure pas dans la liste actuelle.

**À faire :** gating des résultats du commit exact, tests de migration du paquet, archive et somme de contrôle.

**Traité depuis :** le volet packaging est réglé — `TODO.md` figure explicitement dans la liste d’exclusion de `release.yml`, et l’étape « Vérifie la complétude du paquet » échoue bruyamment sur toute entrée racine suivie par git qui ne serait ni copiée ni exclue. Le rapport d’audit ne peut donc plus partir en production par omission.

**Constaté en conditions réelles le 24/09/2026 :** un tag déclenchait `release.yml` sans aucune dépendance au succès de `lint.yml` et `e2e.yml` — or c’est `e2e.yml` qui exécute `verify-migrations`, seul contrôle de la montée de version par bonds. Pour la 5.19.0, qui portait un changement de schéma (DB_VERSION 4.13.0), la seule parade a été de pousser `main`, d’attendre la CI à la main, puis de taguer.

**Traité le 24/09/2026 :** `lint.yml` et `e2e.yml` acceptent `workflow_call` ; `release.yml` les rejoue sur le commit exact du tag (jobs `lint` et `e2e`) et le job de publication en dépend (`needs`) — un échec n’aboutit à aucune release. Les permissions d’écriture sont restreintes au job de publication. Le fumage couvre désormais aussi la **mise à jour** : dernière release publiée installée puis nouveau zip par-dessus, `psc_db_version` devant atteindre la `DB_VERSION` du paquet (vérifié localement sous Podman : 5.18.0 → 5.19.0, 4.12.0 → 4.13.0). La release publie un fichier `SHA256SUMS` à côté des zips. `actionlint` ne relève aucune erreur. **Constaté avec v5.20.0 (24/09/2026) :** lint, PHPStan et E2E rejoués dans la release, fumage de mise à jour v5.19.0 → 5.20.0 réussi, `SHA256SUMS` publié avec les deux zips.

**Acceptation :** tests en échec → aucune release publiée ; ZIP propre et installable ; ajout du TODO suivi n’entraîne ni exposition du rapport ni échec inattendu. **Aucune modification du workflow effectuée pendant cet audit. Développement ; S/M.**

### P2-12 — TRAITÉ CÔTÉ PROJET (après v5.22.0) — Définir une politique de versions et de dépendances supportées

- [x] **Distinguer compatibilité historique et environnement de production maintenu.**

**Constaté :** minimum PHP 7.4, WordPress 5.8 ; CI PHP 7.4–8.3 ; images locales flottantes `latest`. FPDF 1.9 est embarqué hors Composer, sans inventaire d’avis de sécurité automatisé dans la CI examinée. PHPStan niveau 3 couvre uniquement `includes/`. Aucun avis npm connu trouvé lors de cet audit ; cela ne certifie pas tout l’écosystème.

**À faire :** imposer/documenter des versions maintenues pour la production, tester les branches actuelles appropriées, inventorier FPDF et licences, surveiller les avis de sécurité, vérifier les outils téléchargés en CI et versions des actions. Étendre progressivement l’analyse aux templates et aux erreurs de typage utiles.

**Traité le 24/09/2026 :**
- **Politique documentée :** `documentation/installation/versions-dependances.md` distingue la compatibilité minimale (PHP 7.4, WP 5.8, contrôlée en CI de 7.4 à 8.3) de la production maintenue (PHP 8.2/8.3, dernière version de WordPress, MySQL 8.0+). La page fixe un calendrier : revue semestrielle des versions du serveur, ajout de chaque version majeure de PHP à la matrice. Elle est reliée aux prérequis.
- **Dépendances identifiées :** FPDF 1.9 (licence FPDF) et Public Sans (OFL) embarqués ; aucune dépendance Composer ni npm livrée ; outillage recensé.
- **Alertes :** `composer audit` et `npm audit --audit-level=high` bloquent le lint en CI (aucun avis au 24/09/2026). L’empreinte SHA-512 de WP-CLI, téléchargé à chaque run, est vérifiée en CI et en release.
- **`readme.txt` :** `Tested up to` passe à 7.1, la version testée en continu.

**Reste (hébergeur) :** relever les versions réelles du serveur distant (PHP, WordPress, MySQL) et les comparer au tableau ; FPDF n’a pas de flux d’avis automatisé (revue semestrielle). Épingler les actions GitHub par empreinte plutôt que par version majeure reste une option.

**Acceptation :** versions distantes recensées, calendrier de maintenance, dépendances identifiées et alertes traitées. Les anciennes versions PHP ne reçoivent plus les corrections du projet PHP : [PHP — versions supportées](https://www.php.net/supported-versions.php), [versions abandonnées](https://www.php.net/eol.php). **Développement + hébergeur ; M.**

### P2-13 — TRAITÉ CÔTÉ DÉPÔT (après v5.22.0) — Isoler le développement sur le laptop

- [x] **Limiter les ports locaux et éviter le service HTTP des fichiers de travail.**

**Reproduit, laptop uniquement :** ports Podman annoncés `0.0.0.0:8080` et `0.0.0.0:8025` ; le dépôt complet est monté sous le répertoire public du plugin (`docker-compose.yml`). Les HEAD de `.env`, `.git/HEAD` et `claude-export.zip` répondent 200 ; Mailpit répond sans authentification. L’accessibilité effective depuis un autre appareil dépend du réseau, de Podman/macOS et du pare-feu ; aucune exposition Internet ni consultation par un tiers n’est établie.

**À faire :** lier à loopback quand aucun partage n’est nécessaire ; ne servir que les fichiers runtime ou bloquer les fichiers de travail ; données synthétiques dans Mailpit/tests, chiffrement/verrouillage du laptop, règles pour exports et sauvegardes locales. Si des secrets réels ont pu être consultés, évaluer leur rotation après confinement.

**Traité le 24/09/2026 :**
- **Ports :** WordPress (8080) et Mailpit (8025) sont liés à `127.0.0.1` dans `docker-compose.yml`, donc joignables seulement depuis le poste, plus depuis le réseau local. Effet à la recréation des conteneurs (`podman compose up -d`).
- **Fichiers de travail :** un `.htaccess` de développement à la racine du dépôt refuse tout sauf les fichiers statiques chargés par le navigateur (css, js, images, polices). Constaté localement : `.env`, `.git/HEAD`, `TODO.md`, l’export zip et les sources PHP passent de 200 à 403, les assets restent à 200. Exclu du zip publié (liste de `release.yml`).
- **Preuve :** `tests/dev-isolation.spec.ts` couvre ces deux points en CI.

**Reste (hors code, poste de travail) :** chiffrement et verrouillage du laptop, données synthétiques seulement dans Mailpit, règles pour les exports et sauvegardes locales (`claude-export.zip` et les anciennes archives à la racine du dépôt sont à ranger hors du dépôt). Si des secrets réels ont pu être consultés via le réseau local avant ce correctif, envisager leur rotation.

**Acceptation :** navigation locale et tests fonctionnels préservés ; fichiers de travail refusés par HTTP ; pas de partage involontaire des mails. **Le ZIP v5.4.1 inspecté n’embarque aucun de ces fichiers : ne pas présenter ce point comme une faille démontrée du serveur distant. Développement ; S.**

### P2-14 — Versionner les règlements et les preuves d’acceptation

- [ ] **Conserver la version du document effectivement accepté, avec le contexte utile.**

**Constaté :** champs `reglement_accepted_at` et `sepa_reglement_accepted_at` dans familles/demandes/années ; réglages de documents remplaçables par identifiants de médias ; pas de version ou empreinte du texte accepté dans ce modèle. Le PDF de mandat généré ne démontre pas à lui seul l’existence d’un mandat valablement signé et archivé.

**À faire :** conserver référence/version datée, auteur identifiable et mode d’acceptation ; faire valider le circuit réel de signature et d’archivage SEPA. Séparer approbation du règlement, mandat de paiement et éventuel consentement à un traitement particulier.

**Acceptation :** remplacement d’un règlement ne change pas la preuve antérieure ; la mairie retrouve le document applicable à une inscription sans conserver des données supplémentaires inutiles. **Métier/facturation + DPO + développement ; M.**

**Traité le 26/09/2026 (volet technique, schéma 4.18.0) :** table `psc_document_versions` (type, texte exact affiché, empreinte du texte et du PDF, copie privée du PDF) ; colonnes `reglement_version_id` / `sepa_reglement_version_id` sur les familles, les demandes et les années de l'enfant, clés étrangères RESTRICT. Une version se crée d'elle-même quand le texte ou le PDF change. Enregistrée à l'inscription, à la réinscription (qui affiche désormais le règlement) et à l'activation du prélèvement depuis le profil (texte désormais identique à celui de l'inscription). Consultable depuis une demande (écran « Version de règlement », journalisé) et présente dans l'export RGPD. Preuves : `bin/verify-document-versions.php` (12 vérifications, en CI), `tests/reglement-versions.spec.ts`, `request-approval` et `school-year-promotion` étendus. Reste : circuit réel de signature et d'archivage SEPA, à valider par la mairie.

### P2-15 — TRAITÉ CÔTÉ DÉVELOPPEMENT (après v5.22.0) — Auditer l’accessibilité des parcours essentiels

- [x] **Tester clavier, lecteur d’écran, mobile et gestion des erreurs.**

**Périmètre à vérifier :** wizard multiétape, onglets enfants, grilles, popins, formulaires SEPA et notifications AJAX. Les attributs ARIA et tests de débordement présents sont utiles, mais aucun audit complet d’accessibilité n’est fourni. Ce chantier est distinct de la conformité RGPD.

**À faire :** ordre du focus, pièges clavier, annonce des états, libellés actualisés, contrastes, zoom, erreurs rattachées aux champs ; confirmer les obligations d’accessibilité du site communal avec son responsable.

**Traité le 24/09/2026 :**
- **Contrôle axe (WCAG 2.1 A/AA)** sur l’accueil visiteur et les 11 écrans du portail. Défauts relevés puis corrigés :
  - contrastes du texte secondaire (`--psc-stone` #8B8279 → #665F58 et occurrences en dur), de l’orange employé comme texte (→ `--psc-gold-ink`), des libellés de la barre latérale, du slogan du thème, d’un champ en lecture seule et d’une carte de paiement ;
  - 17 champs sans libellé associé (ajout d’un enfant, profil) ;
  - lien vide « revenir au rythme ».
  Résultat : aucune violation sur les 12 écrans.
- **Réaffichage à 320 px (zoom 400 %)** : aucun défilement horizontal, sur aucun écran.
- **Clavier seul :** changement d’enfant et correction d’une case du planning, par Tab puis Entrée ou Espace, vérifiés en base ; demande de lien de connexion.
- **Preuve :** `tests/a11y-parcours.spec.ts` en CI. Vérifié par mutation : l’ancien gris fait échouer le contrôle axe.
- **Rapport :** `documentation/accessibilite.md` (vérifications, corrections, contrôles humains restants).

**Reste (référent accessibilité) :** lecteur d’écran réel (NVDA, VoiceOver), inscription complète au clavier, mobile réel, obligations et déclaration d’accessibilité de la commune.

**Acceptation :** inscription et correction du planning réalisables sans souris, avec lecteur d’écran et zoom important ; rapport de contrôle et corrections priorisées. **Développement + référent accessibilité ; M/L.**

## P3 — Maintenance

### P3-01 — Réduire les divergences entre vues et modèle métier

- [ ] **Formaliser les contrats présence, repas fourni, prestation déclarée et prestation facturée.**

**Constaté :** résolution centrale existante, mais adaptations répétées dans planning, CSV, mail, factures et SIDSCM ; commentaires parfois contradictoires avec le comportement actuel. Deux modèles d’année coexistent. Des changements récents ont corrigé une vue sans couvrir tous les consommateurs.

**À faire :** contrats d’entrée/sortie documentés, table de décision, fonctions de présentation communes ; réduire les doublons par étapes après couverture des comportements. Ne pas engager une réécriture globale pendant les corrections urgentes.

**Acceptation :** chaque règle possède une définition et des tests partagés ; ajout d’un tarif ou profil répercuté sur tous les canaux. **Développement ; L.**

**Premier lot traité le 26/09/2026 :**
- **Contrats :** quatre lectures d'une journée, chacune par une fonction pure de `includes/helpers/planning.php` : prestation déclarée (`declared_map`), présence (`psc_day_slots`), repas fourni (`psc_day_meal`), prestation facturée (`psc_billing_services`). Table de décision et canaux : [docs/contrats-planning.md](docs/contrats-planning.md).
- **Tests partagés :** la table vit dans `tests/unit/contrats-journee.php`, rejouée par les tests unitaires (deux jeux de tarifs) et par `bin/verify-channel-contracts.php` (commande fournisseur, avis de fermeture, annulation de classe, facture), en CI.
- **Écarts corrigés :** annulation de la cantine d'une classe qui ignorait les enfants au forfait (repas commandé et facturé, famille non prévenue) ; total du portail (Planning - 1) recalculé en JavaScript avec l'ancienne règle, désormais fourni par le serveur ; avis de fermeture d'un jour qui annonçaient le forfait en plus de ses créneaux ; fermeture d'une prestation qui envoyait deux e-mails aux familles au forfait.
- **Second lot (26/09/2026) :** les trois listes de `Psc_School_Calendar` (jour, période, prestation) partagent un socle `families_for()` ; l'e-mail « forfait modifié » liste, enfant par enfant, les prestations maintenues (retraits de la famille et autres fermetures compris) ; contrastes des jours grisés du calendrier corrigés (RGAA 3.2).

### P3-02 — TRAITÉ (après v5.24.0) — Mettre la documentation en accord avec le mode de déploiement réel

- [x] **Distinguer laptop Podman, tests jetables et installation distante par ZIP.**

**Constaté :** guide d’auto-hébergement orienté Compose et anciens trimestres ; commentaires historiques sur stockage hors racine, forfaits, calendrier et cron parfois obsolètes ; README déjà en cours de modification avant cet audit.

**À faire :** procédure ZIP incluant sauvegarde préalable, contrôle de version, migrations, reprise et recette ; guide local distinct ; documentation des données et droits ; ne pas affirmer « conforme RGPD » sur la seule présence de chiffrement ou de suppression à la désinstallation.

**Traité le 25/09/2026 :**
- **Production :** nouvelle page [Déployer une nouvelle version](documentation/installation/deploiement-zip.md). Elle couvre l'archive à prendre (et le thème, facultatif), la vérification `SHA256SUMS`, ce qui ne doit jamais aller sur le serveur (`mu-plugins/`, `.env`, `docker-compose*.yml`, `bin/`, `wp-config.php` de développement, mode debug des factures), la sauvegarde préalable, le relevé de `psc_db_version`, l'installation (WordPress ou WP-CLI), la mise à jour du schéma et ses alertes, une recette de dix minutes, et le retour arrière : l'archive seule si le schéma est inchangé, sinon restauration base et documents.
- « Sauvegarde et mise à jour » est recentrée sur la sauvegarde et la restauration. Elle ne cite plus `bin/verify-database-backup.sh`, outil de développement absent du serveur. « Installation et activation » ne propose plus de copier le dossier du plugin.
- **Développement :** `docs/developpement-local.md` distingue poste Podman, instance de test et production. Il couvre la pile, les mu-plugins de développement, les pièges connus, la réinitialisation, les tests, les scripts `verify-*` sous `www-data`, la base jetable pour `verify-migrations` (destructif) et la publication.
- `docs/self-hosting-docker.md` est signalé comme instance de test, pas comme procédure de production.
- **README :** badge de version dynamique (il affichait 5.10.1), installation par archive vérifiée, menu **Année scolaire** renommé, liens vers le déploiement, la recette d'hébergement et le guide développeur. Aucune affirmation de conformité au RGPD.
- Commentaire obsolète corrigé : chemin des justificatifs relatif au répertoire privé, et non plus à `uploads`.

**Reste :** aucune page ne documente l'écran des intervenants (SIDSCM), lié à P1-01 mis de côté.

**Acceptation :** un exploitant suit la procédure adaptée au serveur distant sans copier les secrets, archives ou réglages de développement ; README relu après arbitrage des fonctionnalités. **Développement + exploitation ; S/M.**

## Points positifs à conserver

- Contrôles d’appartenance explicites sur enfants, personnes autorisées et téléchargements de factures/assurances ; le nonce n’est pas utilisé comme seul contrôle d’accès.
- Jetons de connexion aléatoires stockés hachés, comparaisons à temps constant, cookie signé `HttpOnly`/`SameSite=Lax`, protection anti-CSRF liée à la famille pour les écritures principales.
- Limitation des demandes publiques et réponse générique pour les demandes de lien ; protection imparfaite mais existante des endpoints SIDSCM.
- Chiffrement authentifié des IBAN quand une primitive est disponible ; aucune clé bancaire exposée par les sondes de cet audit.
- Protection des documents par contrôles d’accès, en-têtes et journal de téléchargement ; refus HTTP confirmé pour le témoin du répertoire privé local.
- Requêtes préparées sur les entrées examinées, échappement des vues et neutralisation des formules dans le CSV ; aucune injection SQL/XSS exploitable démontrée par cet audit.
- Nettoyage de certaines demandes, suppression des fichiers associés à plusieurs parcours et désinstallation destructive soumise à une constante explicite.
- Comptage fournisseur agrégé, sans noms d’enfants dans le flux de commande examiné.
- Helpers métier testables, lectures groupées, contraintes de base, tests E2E/migrations et contrôle du ZIP à la publication.

## Ordre de travail proposé

L'ordre initial (P0, accès et confidentialité, intégrité métier, conformité, fiabilisation) est suivi. Les P0, l'essentiel des P1 techniques et les P2 sont faits. Pour la suite :

1. **Serveur distant** : la mise à jour vers 5.28.0 et la sortie de la clé sont faites (25/09/2026). Restent la restauration testée avec la clé et la fiche de recette de l'hébergement (P1-04, P1-18).
2. **Développement autonome** : volet technique de P1-11 (P2-06 fait côté développement).
3. **Schéma à valider avant implémentation** : P1-16 (centimes, périodes d'effet), P2-14.
4. **Facturation** : P1-15, puis P3-01.
5. **Conformité avec le DPO et la mairie** : P1-06 à P1-10. Aucune purge réelle au-delà de celle des enfants partis avant validation des règles d'archives et de conservation.

## Conditions pour conclure à un niveau de conformité acceptable

La fermeture d’un ticket technique exige une preuve de test ; un ticket d’hébergement exige un contrôle sur le **serveur distant** ; un ticket organisationnel exige un document ou une décision de la mairie/DPO. Les trois ne sont pas interchangeables.

Avant d’annoncer la conformité : clôture des points bloquants, politique de conservation appliquée, droits exerçables, habilitations vérifiées, sauvegarde restaurée, information publiée et dossier de conformité validé. Toute impossibilité restante doit faire l’objet d’un arbitrage documenté, et non d’une case cochée par défaut.

Chaque bloc marqué TRAITÉ porte sa preuve (script de vérification ou spécification lancés en CI, vérifiés par mutation). Un bloc touchant au schéma n'est implémenté qu'après validation explicite du schéma proposé.
