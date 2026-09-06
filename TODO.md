# TODO — Audit technique et protection des données

**Date : 6 septembre 2026 — Extension v5.4.1 — Référence : `39e657a`.**

**Statut : liste arbitrée et en cours de traitement.** Traité en v5.4.2 (P0-01 allergies + rapprochement, P0-02 présence midi) et v5.4.3 (P1-03 anti-cache, P1-05 révocation durable des sessions, P1-12 chiffrement fail-closed). Les cases cochées portent la preuve de test correspondante (E2E ou sonde) ; les tickets d’hébergement et DPO restent ouverts.

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
| Allergies / régime | `children.food_allergies`, copie dans `requests.children_json`, indicateurs de régime | Famille, backoffice, SIDSCM ; description également envoyée par e-mail à la mairie | Pas de durée dédiée, ni circuit distinct pour les données de santé. |
| Personnes autorisées | `pickup_persons`, `pickup_history`, second parent dans `parents` | Famille ; mairie ; intervenants GS, coordonnées incluses | Retrait logique ; historique jusqu’à suppression de l’enfant. |
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

### P1-01 — Remplacer le secret partagé des intervenants SIDSCM

- [ ] **Mettre en place des accès intervenants individuels, limités et révocables.**

**Constaté :** `includes/class-psc-sidscm.php:154` vérifie un code commun, stocké dans une option WordPress ; `assets/js/sidscm.js:92` le conserve dans `localStorage` et le renvoie aux requêtes. Aucun minimum de robustesse n’est imposé à l’enregistrement (`class-psc-admin-config.php:43`). Ce secret ouvre les listes nominatives, allergies et coordonnées de tiers, ainsi que le pointage. La limitation par IP existe, mais ne permet ni révocation individuelle ni attribution des actions.

**À faire :** comptes ou invitations nominatifs avec session serveur expirante ; permissions par fonction/périmètre ; verrouillage des postes partagés ; journalisation de l’auteur. Réserver les données sanitaires aux personnes qui en ont besoin. Évaluer l’authentification renforcée selon l’AIPD, notamment pour les accès privilégiés.

**Acceptation :** départ d’un intervenant → accès coupé pour lui seul ; expiration après inactivité ; aucun secret durable dans `localStorage` ; opérations attribuables. **Développement + mairie ; L.**

### P1-02 — Réduire les droits accordés par défaut aux éditeurs WordPress

- [ ] **Créer une matrice d’habilitations mairie, facturation et données sanitaires.**

**Constaté :** `includes/helpers/core.php:37` attribue par défaut la capacité globale aux administrateurs **et aux éditeurs**. `Psc_Installer::sync_roles()` l’ajoute sans la retirer. La même capacité ouvre les familles, pièces, réglages, exports et suppressions.

**À faire :** droits distincts et attribution explicite ; migration des capacités déjà accordées ; revue régulière des comptes. Sur le serveur distant, inventorier qui possède effectivement ces droits avant de les modifier.

**Acceptation :** un éditeur de contenus sans mission périscolaire n’accède pas aux dossiers ; le rôle facturation ne consulte pas automatiquement les allergies ; tests de refus sur les URL et endpoints. **Développement + administrateur WordPress ; M.**

### P1-03 — TRAITÉ (v5.4.2/v5.4.3) — Empêcher la mise en cache partagée des pages familles

- [x] **Protéger explicitement le portail et les URL portant des jetons contre le cache.**

**Constaté / risque conditionnel :** le portail authentifie un visiteur hors comptes WordPress (`class-psc-parents.php`) ; `class-psc-frontend.php` ne pose pas de politique explicite `no-store` ni d’exclusion de cache. Les téléchargements, eux, appellent `nocache_headers()`. Un cache WordPress/CDN qui considère `psc_session` comme un cookie anonyme peut mélanger des pages de familles. Aucune fuite entre familles n’a été testée ou constatée sur le serveur distant.

**À faire :** en-têtes adaptés dès le point d’entrée ; exclusions de cache par cookie, page et paramètres des liens magiques ; vérifier les redirections, retour navigateur et en-têtes de référence. Une constante PHP seule ne suffit pas si le cache intervient avant WordPress.

**Acceptation :** deux familles et un navigateur anonyme derrière le cache réellement utilisé ne partagent jamais HTML, données de démarrage ou jetons ; réponses privées non stockées. **Développement + hébergeur ; M.**

### P1-04 — Vérifier et fiabiliser le stockage privé sur le serveur distant

- [ ] **Valider l’inaccessibilité HTTP des documents, des journaux et des anciens emplacements.**

**Constaté / conditionnel :** `includes/helpers/files.php:31` choisit par défaut `wp-content/psc-private`, donc **sous la racine web**, malgré certains commentaires « hors racine ». Les protections `.htaccess`/`web.config` existent, ainsi qu’un témoin et une alerte d’administration ; nginx ne lit pas `.htaccess`. Sur le laptop Apache, le témoin est bien refusé (403). Cela ne prouve rien pour l’hébergement distant.

**À faire :** privilégier `PSC_PRIVATE_DIR` hors racine web quand l’hébergement le permet ; sinon règle serveur explicite vérifiée. Contrôler chemins historiques, sauvegardes, prévisualisations et accès direct aux noms prévisibles. Rendre les échecs de création des protections détectables ; vérifier la détermination d’URL pour les emplacements personnalisés.

**Acceptation :** un fichier témoin non sensible placé dans chaque emplacement concerné est inaccessible anonymement ; les documents restent téléchargeables après contrôle d’appartenance. **Hébergeur + développement ; M.**

### P1-05 — TRAITÉ (v5.4.2/v5.4.3) — Révoquer réellement les accès des familles et du second parent

- [x] **Associer les sessions à une identité d’accès et à un état de révocation durable.**

**Constaté :** `class-psc-parents.php` ouvre une session signée au niveau du foyer ; changer l’e-mail ou retirer le second parent (`class-psc-frontend-profil.php`) ne révoque pas les sessions déjà ouvertes ni le lien commun encore valable. Un accès retiré peut donc subsister jusqu’à 12 h par défaut. `includes/helpers/session.php` conserve la révocation individuelle dans un transient, susceptible d’être évincé avant son expiration en présence d’un cache externe. Les anciens cookies sans identifiant restent acceptés par le code.

**À faire :** identité titulaire/second parent distincte, registre de sessions ou version de révocation persistante ; invalider les liens et sessions concernés lors d’un retrait/changement sensible ; lister/révoquer les appareils ; supprimer la compatibilité des anciens cookies une fois la fenêtre de migration dépassée. Prévoir les situations d’autorité parentale restreinte avec la mairie.

**Acceptation :** ancien cookie et ancien lien refusés après retrait du second parent, changement d’identifiant ou révocation ; refus maintenu après purge du cache. **Développement + mairie ; L.**

### P1-06 — Encadrer juridiquement et techniquement les allergies

- [ ] **Faire valider le traitement des données de santé avant de considérer ce volet conforme.**

**Constaté / à documenter :** texte libre d’allergies dans `children` et `requests`, répliqué dans les e-mails (`class-psc-mailer.php:311`). L’acceptation du règlement intérieur n’établit pas, à elle seule, la condition juridique autorisant ces données de santé.

**À faire :** le DPO détermine la base de l’article 6 et la condition applicable de l’article 9 ; documenter finalité, données strictement nécessaires, destinataires et durée. Séparer signalement opérationnel et détails médicaux ; privilégier une alerte avec lien sécurisé plutôt que recopier descriptions actuelle et précédente dans la messagerie ; examiner la protection au repos selon les risques.

**Acceptation :** circuit PAI, habilitations et conservation validés ; collecte minimale ; aucun consentement « global RGPD » ajouté par défaut comme solution universelle. L’exigence HDS éventuelle dépend du contexte juridique réel et doit être examinée, pas déduite du seul champ allergies. Référence : [RGPD, articles 5, 6 et 9](https://www.cnil.fr/fr/reglement-europeen-protection-donnees/chapitre2). **DPO + métier + développement ; L.**

### P1-07 — Compléter l’information des familles et des tiers

- [ ] **Rédiger une notice de confidentialité paramétrable et accessible aux points de collecte.**

**Constaté :** `templates/guest-request.php:293` annonce uniquement le traitement de la demande, la purge à 7 jours et accès/rectification par contact mairie. Elle ne décrit pas tout le service : suivi annuel, factures, santé, tiers, journaux et prestataires. Les noms/coordonnées du second parent et des personnes autorisées sont collectés indirectement.

**À faire :** identifier responsable/DPO, finalités, bases, destinataires, durées ou critères, droits applicables, réclamation CNIL, caractère obligatoire/facultatif et conséquences ; information des tiers selon l’article 14. Relier la notice au portail et au formulaire, sans confondre information et consentement.

**Acceptation :** texte relu par le DPO, coordonnées réelles, accès sans connexion et information des tiers documentée. Référence : [RGPD, articles 12 à 14](https://www.cnil.fr/fr/reglement-europeen-protection-donnees/chapitre3). **DPO + développement ; M.**

### P1-08 — Définir puis appliquer une politique complète de conservation

- [ ] **Couvrir toutes les tables, pièces, messages, journaux et sauvegardes.**

**Constaté :** `class-psc-requests.php:54` purge les demandes non vérifiées à 7 jours et traitées à 90 jours ; les demandes `pending` peuvent rester indéfiniment. Familles, allergies, années, présences et anciens tiers n’ont pas de purge par durée. La suppression de famille ne purge pas les demandes d’origine. La désinstallation conditionnelle ne remplace pas une politique de conservation.

**À faire :** tableau validé avec le DPO et le service d’archives : finalité, point de départ, durée en base active, archivage intermédiaire, sort final, exceptions justifiées. Prévoir relances puis clôture des demandes pendantes, nettoyage des fichiers orphelins, surveillance du cron et réapplication des suppressions après restauration.

**Acceptation :** simulation des suppressions, rapport sans contenu sensible, exécution contrôlée et test horloge figée. Ne pas appliquer arbitrairement « tout effacer après un an » ou un délai comptable unique. Référence : [CNIL — conservation et articulation avec les archives publiques](https://www.cnil.fr/fr/passer-laction/les-durees-de-conservation-des-donnees). **DPO + archives + développement ; L.**

### P1-09 — Outiller l’exercice des droits sans destruction comptable aveugle

- [ ] **Prévoir export de dossier, rectification, limitation et effacement encadré.**

**Constaté :** pas d’intégration aux exporteurs/effaceurs de données personnelles WordPress trouvée ; la modification de profil et la suppression mairie couvrent seulement une partie du besoin. `class-psc-admin-familles.php:121` détruit aussi les factures/PDF d’une famille, mais pas ses demandes ; `purge_child():84` ne retire pas son nom des PDF familiaux existants.

**À faire :** procédure de vérification d’identité, réponse suivie, export couvrant tables/fichiers/tiers/historiques, décisions d’effacement ou de conservation motivées ; séparer données opérationnelles et archives légalement nécessaires. Des outils WordPress sont une option pratique, pas une obligation en soi. L’effacement n’est pas absolu et la portabilité ne s’applique pas automatiquement à une mission d’intérêt public.

**Acceptation :** exercice complet sur famille fictive et second parent, périmètre des enfants autorisés vérifié, absence de divulgation des autres familles, décision et délai tracés. Référence : [RGPD, articles 15 à 21](https://www.cnil.fr/fr/reglement-europeen-protection-donnees/chapitre3). **DPO + développement ; L.**

### P1-10 — Constituer le dossier de conformité et évaluer l’AIPD

- [ ] **Rassembler les preuves organisationnelles avec la mairie et le DPO.**

**À documenter :** responsable du traitement, DPO, registre, habilitations, contrats hébergement/messagerie/maintenance, localisation et transferts éventuels, procédures d’incident. Aucune conclusion sur leur existence hors dépôt.

**À faire :** analyse de nécessité d’une AIPD, en considérant données de santé et personnes vulnérables ; réaliser l’AIPD si requise. Vérifier obligations des sous-traitants et traitement des violations, avec procédure d’évaluation/notification et responsables identifiés. Le délai de notification de 72 h s’apprécie lorsque la notification est requise, à compter de la prise de connaissance ; documenter aussi les incidents non notifiés.

**Acceptation :** registre à jour, décision AIPD motivée, contrats/garanties disponibles, exercice d’incident et arbitrage formalisé des risques résiduels. Références : [RGPD, chapitre IV](https://www.cnil.fr/fr/reglement-europeen-protection-donnees/chapitre4), [CNIL — AIPD](https://www.cnil.fr/fr/ce-quil-faut-savoir-sur-lanalyse-dimpact-relative-la-protection-des-donnees-aipd). **Mairie + DPO + prestataires ; L.**

### P1-11 — Étendre et maîtriser la journalisation

- [ ] **Tracer les opérations sensibles avec une identité fiable et une durée définie.**

**Constaté :** `includes/helpers/files.php:196` journalise uniquement les téléchargements de documents, avec e-mail, IP et chemin ; écriture silencieuse en cas d’échec, sans rotation. `pickup_history` conserve des changements, mais les modifications de profil du second parent qui influencent les autorisations ne suivent pas ce même historique. Le code commun SIDSCM ne permet pas de distinguer les intervenants.

**À faire :** journal structuré des connexions/révocations, consultations sensibles, modifications, exports, suppressions, paramètres bancaires et actions de pointage ; identité minimale, horodatage UTC, rotation, contrôle d’accès et alerte de panne. Ne jamais journaliser les tokens, IBAN complets ou descriptions d’allergies.

**Acceptation :** reconstitution d’un incident fictif, auteurs identifiables, journal protégé et rétention appliquée, consultation elle-même limitée. Référence : [CNIL — tracer les opérations](https://www.cnil.fr/fr/securite-tracer-les-operations). **Développement + exploitation + DPO ; M/L.**

### P1-12 — TRAITÉ (v5.4.2/v5.4.3) — Interdire le repli bancaire silencieux en clair

- [x] **Faire échouer explicitement un enregistrement bancaire si le chiffrement est indisponible.**

**Constaté :** `includes/helpers/crypto.php:39` renvoie l’IBAN initial si aucune primitive n’existe ou si OpenSSL échoue. Le chiffrement fonctionne sur le laptop avec sodium ; aucune défaillance du serveur distant n’est établie. Sans clé dédiée, la clé est dérivée des sels WordPress : leur rotation peut rendre les données illisibles.

**À faire :** prérequis et état de santé explicites ; clé dédiée, sauvegardée séparément et protégée ; rotation versionnée avec migration vérifiable ; distinguer donnée absente et déchiffrement impossible. Les sauvegardes doivent inclure le nécessaire à la restauration sans exposer clé et données au même niveau d’accès.

**Acceptation :** absence/échec des primitives → refus sans écriture en clair ; rotation/restauration testées ; zéro IBAN complet dans logs ou erreurs. **Développement + hébergeur ; M.**

### P1-13 — Éviter la perte silencieuse des justificatifs

- [ ] **Rendre les écritures fichier/base et les reprises d’échec fiables.**

**Constaté :** `class-psc-assurances.php:151` supprime l’ancien fichier d’une autre extension avant d’avoir réussi le nouveau dépôt ; `upsert_row():224` renvoie vrai sans vérifier chaque résultat SQL. Après approbation, `class-psc-requests.php:825` ignore le résultat de `promote_pending()` puis supprime tous les fichiers d’attente. Une promotion ratée peut donc supprimer sa propre source. La réinscription (`class-psc-frontend-reinscription.php:43`) ignore également les retours d’inscription et de stockage, puis annonce un succès.

**À faire :** validation préalable de la fratrie, écriture temporaire, publication atomique, vérification SQL, conservation de la source jusqu’au succès et reprise idempotente. Ne pas déclarer une assurance valide sur la seule présence d’un chemin si le fichier manque.

**Acceptation :** disque plein, accès refusé, échec SQL, deuxième enfant invalide → ancien document préservé et résultat explicite ; reprise sans perte ni doublon. **Développement ; M/L.**

### P1-14 — Corriger les référentiels de temps du verrou de modification

- [ ] **Comparer des timestamps Unix réels et afficher le même instant que celui contrôlé.**

**Reproduit :** `includes/helpers/lock.php:24` utilise `current_time('timestamp')`, valeur décalée par WordPress ; `psc_lock_deadline_ts():64` renvoie un timestamp Unix réel. Avec un maintenant simulé au 5 septembre 2026 à 23 h à Paris et un service le 8 septembre, le verrou 48 h est déjà vrai alors qu’il devrait rester faux jusqu’à minuit.

**À faire :** unifier UTC pour la comparaison et timezone WordPress pour l’affichage ; revoir les dates de purge qui mélangent `current_time('mysql')` et `gmdate()`.

**Acceptation :** tests juste avant/à/après échéance, Paris hiver/été, transitions d’heure, fuseau UTC et délai zéro. **Développement ; S/M.**

### P1-15 — Supprimer les cumuls restants et aligner CSV, courriels et forfait sans repas

- [ ] **Définir puis partager une règle unique de prestation facturable.**

**Reproduit / constaté :** `includes/helpers/planning.php:228` renvoie encore `[FORF, GM, GS, MSR]` pour un forfait avec exception MSR chez un enfant non flagué. Le correctif du forfait avec repas n’a pas couvert ce scénario. `class-psc-admin-inscriptions.php:235` appelle `psc_billing_services()` sans le flag enfant : le CSV d’un forfait sans repas diverge du PDF FSR. `class-psc-mailer.php:161` rend les rythmes bruts avec les libellés génériques, sans adapter le forfait au profil sans repas.

**À faire :** arbitrer forfait/modification ponctuelle/fermeture, conserver la séparation présence vs facturation, propager le flag et le tarif partout ; vérifier les journées historiques concernées avant une éventuelle régénération.

**Acceptation :** matrice FORF/CANT/MSR/FSR avec retraits et fermetures ; une seule facturation du créneau ; PDF, CSV et totaux cohérents ; récapitulatif intelligible. **Développement + facturation ; M.**

### P1-16 — Figer les factures et historiser les paramètres influant sur le passé

- [ ] **Séparer brouillon recalculable, document émis et correction comptable.**

**Constaté :** `class-psc-invoices.php:103` relit tarifs et flags actuels ; régénérer remplace total, date, fichier et `sent_at` sous le même identifiant. Le schéma ne stocke pas de lignes tarifaires figées. `generate_month():40` ne sélectionne que les enfants/familles actuellement actifs : un enfant sorti peut être omis lors d’une première facturation tardive. Le statut sans repas, non daté, modifie aussi la résolution des jours passés.

**À faire :** snapshot des lignes, tarifs, identités utiles et version du calcul ; périodes d’effet des tarifs/flags ; sélection selon l’activité au mois facturé ; procédure de correction validée par la facturation ; montants en centimes ou calcul décimal maîtrisé.

**Acceptation :** changer un tarif, sortir un enfant ou modifier son flag en octobre n’altère pas une facture de septembre déjà émise ; correction identifiable et archive conservée selon la politique validée. La qualification comptable exacte du PDF doit être confirmée par la mairie. **Développement + facturation/archives ; L.**

### P1-17 — Sécuriser les migrations et déplacements du stockage

- [ ] **N’enregistrer une migration comme réussie qu’après vérification complète.**

**Constaté :** `class-psc-installer.php:46` lance les migrations au chargement et enregistre `psc_db_version` sans bilan de tous les retours SQL. Aucun verrou global de migration n’est visible. `move_tree():569` ignore les erreurs de déplacement et supprime la source lorsqu’un nom existe déjà à destination sans vérifier que les contenus sont identiques. `sync_private_dir():140` mémorise ensuite le nouveau chemin.

**À faire :** verrou, étapes idempotentes, journal technique sans données métier, contrôles de postconditions, reprise explicite ; en déplacement, vérifier les contenus avant suppression et conserver les sources en échec. Les DDL MySQL ne doivent pas être traités comme entièrement annulables par une simple transaction.

**Acceptation :** migrations ancienne version → actuelle avec interruption/permission refusée/conflit de fichier ; aucun document perdu, version non avancée à tort, reprise vérifiable. **Développement + exploitation ; L.**

### P1-18 — Valider les prérequis du serveur distant et sa restauration

- [ ] **Constituer une fiche de recette d’hébergement pour l’installation par ZIP.**

**À vérifier, pas constaté en défaut :** version WordPress/PHP/base, TLS et cookies `Secure`, thème/plugins, cache, droits fichiers, SMTP, cron, accès admin, journaux et sauvegardes du serveur distant. Le ZIP ne configure pas ces éléments. Le guide Compose est un guide de test ; son exemple de sauvegarde (`docs/self-hosting-docker.md:172`) couvre `uploads/` mais pas le dossier privé par défaut ni la clé de chiffrement.

**À faire :** sauvegarder base, fichiers privés et configuration/clé nécessaires ; stockage protégé hors racine web, rétention définie, restauration isolée testée avec envoi d’e-mails neutralisé ; fixer objectifs de perte de données et de reprise. Surveiller l’exécution réelle du cron, les erreurs et la capacité disque.

**Acceptation :** restauration complète d’un dossier fictif avec assurance, facture et IBAN déchiffrable ; contrôles P1-03/P1-04 réussis sur l’hébergement réel ; preuve datée disponible. Références : [CNIL — sauvegarder](https://www.cnil.fr/fr/securite-sauvegarder), [sécuriser les serveurs](https://www.cnil.fr/fr/securite-securiser-les-serveurs). **Hébergeur + administrateur + DPO ; M/L.**

## P2 — Fiabilisation et prévention des régressions

### P2-01 — Aligner les cases visibles sur les déclarations réellement prises en compte

- [ ] **Traiter les anciennes déclarations CANT et les exceptions couvertes par un forfait.**

**Constaté / sonde partielle :** `class-psc-planning.php:341` construit `month_state()` à partir des données brutes sans la conversion sans repas appliquée par `declared_map()`. Depuis v5.4.1, une ancienne case CANT peut donc être masquée alors qu’elle continue de produire du MSR au calcul. `psc_exception_write_decision():146` ne reçoit pas l’exception FORF du jour : pour retirer GM couvert seulement par cette exception, il décide `delete` sur la base du rythme vide, ce qui ne matérialise pas le retrait attendu.

**À faire :** définir une représentation unique des états effectifs, sans confondre leur origine ; préserver l’historique ; test de passage « avec repas → sans repas » sur un planning déjà rempli. Ajouter un refus explicite des ajouts aux services fermés : le moteur d’écriture ne vérifie actuellement que le jour d’école, bien que le résolveur masque les services fermés.

**Acceptation :** chaque case visible peut être modifiée conformément à son état réel et une case cachée ne maintient pas une inscription incompréhensible ; retraits sous exception FORF persistants. **Développement ; M.**

### P2-02 — Gérer concurrence et échecs des écritures métier

- [ ] **Rendre atomiques les opérations composées et vérifier les retours SQL.**

**Constaté :** `toggle_pattern()` supprime les conflits, écrit puis fige les dates en plusieurs requêtes non transactionnelles ; de nombreux retours SQL ne conditionnent pas le succès. `approve_request()` utilise une transaction, mais ne verrouille pas la demande ni ne la réclame atomiquement avant création. Deux validations simultanées d’une demande pendante ne sont pas explicitement sérialisées. L’unicité du second e-mail de parent repose sur une lecture préalable entre deux colonnes, pas sur une identité normalisée unique.

**À faire :** transactions et verrous ciblés, transition de statut conditionnelle, idempotence, gestion de conflit ; données d’identité contraintes en base. Distinguer succès, absence de changement et échec.

**Acceptation :** deux validations/clics concurrents, échec d’une requête intermédiaire → aucune famille dupliquée, aucun rythme partiellement détruit, réponse fidèle à l’état enregistré. **Développement ; L.**

### P2-03 — Valider le contenu des justificatifs, pas seulement leur extension

- [ ] **Renforcer la validation MIME/contenu et la gestion des fichiers non fiables.**

**Constaté :** `class-psc-assurances.php:121` contrôle l’erreur d’upload, la taille déclarée et `wp_check_filetype()` sur le **nom**. Un fichier renommé `.pdf` n’est pas pour autant un PDF valide. Le stockage privé, le contrôle d’accès et `nosniff` existent ; aucune exécution de code par upload n’est démontrée ici.

**À faire :** vérifier taille réelle, MIME/signature, cohérence extension/type ; traiter le PDF comme contenu non fiable ; évaluer analyse antivirus et téléchargement en pièce jointe selon le risque. Appliquer la même validation aux demandes en attente.

**Acceptation :** faux PDF/image, fichier vide, dépassement, erreur d’upload et contenu malformé refusés sans détruire l’ancien justificatif. **Développement ; M.**

### P2-04 — Rendre fiables les envois et leur statut

- [ ] **Suivre les résultats par destinataire et permettre une reprise sans doublon.**

**Constaté :** `class-psc-menus.php:150` boucle synchroniquement et renseigne `sent_at` même si certains ou tous les envois échouent. `class-psc-admin-invoices.php` affiche `sent_all` après une boucle dont les retours ne sont pas exploités. `approve_request()` contient une notification d’allergie avant COMMIT, malgré la séparation annoncée des effets externes. La commande fournisseur envoie puis écrit son historique, sans reprise durable en cas d’échec SQL.

**À faire :** file d’envoi avec états et reprise ; identifiants d’idempotence ; notifications après commit ; distinction accepté par SMTP / délivré. Limiter les pièces personnelles dans les messages selon P1-06.

**Acceptation :** coupure SMTP/timeout/échec de persistance → bilan exact, relance des seuls échecs, aucun succès fictif ni notification d’une opération annulée. **Développement + messagerie ; M/L.**

### P2-05 — Borner les imports de calendriers et prévenir les requêtes internes

- [ ] **Sécuriser l’URL ICS, les redirections et le volume téléchargé.**

**Constaté :** URL configurable par un gestionnaire (`class-psc-admin-config.php:53`) ; import via `wp_remote_get()` avec timeout, sans contrôle explicite d’adresses privées ni limite de réponse (`class-psc-school-calendar.php:160`). Risque SSRF pour un compte disposant de cette capacité ; pas une route publique sans authentification.

**À faire :** URL sûre, schéma et destinations autorisés, contrôles sur les redirections, taille/temps bornés ; import transactionnel ou préparé avant remplacement ; validation des dates et événements aberrants.

**Acceptation :** loopback, adresse privée, redirection interne, réponse trop grosse et ICS invalide refusés ; calendrier existant intact. **Développement ; M.**

### P2-06 — Maîtriser les ressources externes et migrer l’ancienne API Adresse

- [ ] **Inventorier les flux navigateur du site réellement déployé.**

**Constaté :** `assets/js/guest.js:353` appelle directement `api-adresse.data.gouv.fr` et envoie la saisie à partir de trois caractères. La documentation BAN annonce la dépréciation de cette API et le décommissionnement de l’URL fin janvier 2026 ; sa disponibilité effective depuis le serveur/navigateur cible n’a pas été testée. Le thème séparé `theme/Archive/functions.php:24` charge Google Fonts, alors que le plugin possède des polices locales. L’utilisation de ce thème en production reste à confirmer.

**À faire :** remplacer l’API obsolète par le service supporté, maintenir la saisie manuelle, borner les requêtes ; informer sur le flux d’adresse et en valider la nécessité. Auto-héberger les polices si le thème est utilisé ; inventorier aussi analytics/widgets ajoutés hors plugin.

**Acceptation :** parcours utilisable sans réponse du prestataire ; aucun chargement tiers non identifié ; test réseau du site réel. Les cookies d’authentification strictement nécessaires ne justifient pas, à eux seuls, un bandeau de consentement ; les autres traceurs sont à examiner séparément. Références : [BAN — documentation API](https://adresse.data.gouv.fr/outils/api-doc/adresse), [CNIL — cookies nécessaires](https://www.cnil.fr/fr/cookies-et-autres-traceurs/que-dit-la-loi). **Développement + DPO ; M.**

### P2-07 — Sécuriser les changements rapides d’enfant et les états de chargement

- [ ] **Tester et neutraliser les réponses AJAX devenues obsolètes.**

**Constaté / scénario à tester :** `assets/js/planning-2.js` ne verrouille pas les onglets enfants dans `setBusy()` ; `loadMonth()` ne porte pas d’identifiant de requête courante ni d’annulation. Deux réponses arrivant en ordre inverse peuvent afficher un état inattendu. La restauration générale de `disabled` doit également respecter les règles recalculées par le serveur.

**À faire :** ignorer les réponses obsolètes ou sérialiser la navigation ; synchroniser onglet actif, enfant affiché et destination de chaque écriture ; préserver les libellés accessibles après changement d’enfant.

**Acceptation :** réseau ralenti, clic A/B/A, réponse inversée et échec réseau → aucun affichage ou clic dirigé vers le mauvais enfant. **Développement ; M.**

### P2-08 — Réconcilier les deux représentations d’année scolaire

- [ ] **Documenter puis faire respecter les invariants entre `school_years` et `school_year`.**

**Constaté :** classes/assurances s’appuient sur `Psc_School_Years`, tandis que dates, vacances et verrous utilisent `Psc_School_Year`. Les années sont sélectionnées par des règles différentes. `class-psc-school-years.php:151` archive l’année active puis active la suivante sans transaction ni vérification complète de réussite. La réinscription ignore les enfants décochés, sans retirer une confirmation déjà enregistrée lors d’un envoi antérieur.

**À faire :** invariants d’activation, calendrier, dates, assurance et classe ; reprise de promotion ; règle explicite pour une réinscription modifiée. Vérifier le statut annuel au-delà du seul `children.statut` global.

**Acceptation :** activation en échec, double activation, enfant non réinscrit, modification d’une réinscription et consultation historique donnent un état cohérent dans planning/listes/factures. **Développement + métier ; M/L.**

### P2-09 — Mesurer et réduire les lectures complètes et traitements synchrones

- [ ] **Établir un budget de requêtes et de latence sur un effectif représentatif.**

**Constaté :** listes familles/enfants non paginées (`Psc_Parents::all()`, `Psc_Admin_Familles::page_children()`), chargements `SELECT *` ; SIDSCM appelle classe et personnes autorisées enfant par enfant ; génération mensuelle refait des lectures par famille après une première lecture globale. Des lectures groupées existent déjà dans `declared_map()` et sont à préserver.

**À faire :** mesurer avec données synthétiques au volume cible, charger seulement les colonnes nécessaires, grouper/paginer, traiter les grosses tâches par lots. Ne pas ajouter un cache partagé de dossiers pour résoudre la performance.

**Acceptation :** budget convenu et mesuré, absence de N+1 dominant, mémoire bornée, génération reprenable. Aucun résultat de test de charge n’est revendiqué ici. **Développement ; M.**

### P2-10 — Ajouter une suite de sécurité et de régression métier représentative

- [ ] **Compléter les tests qui passent aujourd’hui sans couvrir les défauts prioritaires.**

**Constaté :** tests unitaires/E2E/migrations existants utiles, mais pas de suite systématique identifiée pour la matrice d’accès inter-familles, la révocation/cache, les droits RGPD et la rétention. Les E2E CI utilisent `WP_ENVIRONMENT_TYPE=local`, ce qui désactive la limitation de fréquence ; ils ne valident donc pas son comportement de production.

**À faire :** famille A/B/anonyme, rôles mairie/intervenants, nonce absent/étranger/expiré, documents, révocation, uploads, conservation, échecs disque/SQL, concurrence, profils repas/forfait et changements d’heure. Créer une stack jetable dédiée ; interdire les seed destructifs sur une instance contenant des données réelles.

**Acceptation :** les défauts P0/P1 sont capturés par des tests qui échouent avant correction ; un profil de sécurité garde rate-limit/cache/TLS représentatifs ; CI obligatoire avant release. **Développement ; L, au fil des corrections.**

### P2-11 — Conditionner la release aux contrôles et traiter le nouveau TODO dans le packaging

- [ ] **Faire dépendre la publication d’un commit validé et décider du périmètre documentaire du ZIP.**

**Constaté :** `.github/workflows/release.yml` vérifie la version et l’installation/activation du ZIP, mais ne dépend pas du succès des workflows lint/E2E. Ces derniers tournent sur branches/PR ; un tag déclenche sa release séparément. La vérification de complétude échouera si `TODO.md` est ajouté à Git sans être explicitement inclus ou exclu : il ne figure pas dans la liste actuelle.

**À faire :** gating des résultats du commit exact, tests de migration du paquet, archive et somme de contrôle ; **exclure explicitement ce TODO d’audit du ZIP de production** lors de la future implémentation du packaging. Ne pas le publier accidentellement comme fichier accessible sur le site.

**Acceptation :** tests en échec → aucune release publiée ; ZIP propre et installable ; ajout du TODO suivi n’entraîne ni exposition du rapport ni échec inattendu. **Aucune modification du workflow effectuée pendant cet audit. Développement ; S/M.**

### P2-12 — Définir une politique de versions et de dépendances supportées

- [ ] **Distinguer compatibilité historique et environnement de production maintenu.**

**Constaté :** minimum PHP 7.4, WordPress 5.8 ; CI PHP 7.4–8.3 ; images locales flottantes `latest`. FPDF 1.9 est embarqué hors Composer, sans inventaire d’avis de sécurité automatisé dans la CI examinée. PHPStan niveau 3 couvre uniquement `includes/`. Aucun avis npm connu trouvé lors de cet audit ; cela ne certifie pas tout l’écosystème.

**À faire :** imposer/documenter des versions maintenues pour la production, tester les branches actuelles appropriées, inventorier FPDF et licences, surveiller les avis de sécurité, vérifier les outils téléchargés en CI et versions des actions. Étendre progressivement l’analyse aux templates et aux erreurs de typage utiles.

**Acceptation :** versions distantes recensées, calendrier de maintenance, dépendances identifiées et alertes traitées. Les anciennes versions PHP ne reçoivent plus les corrections du projet PHP : [PHP — versions supportées](https://www.php.net/supported-versions.php), [versions abandonnées](https://www.php.net/eol.php). **Développement + hébergeur ; M.**

### P2-13 — Isoler le développement sur le laptop

- [ ] **Limiter les ports locaux et éviter le service HTTP des fichiers de travail.**

**Reproduit, laptop uniquement :** ports Podman annoncés `0.0.0.0:8080` et `0.0.0.0:8025` ; le dépôt complet est monté sous le répertoire public du plugin (`docker-compose.yml`). Les HEAD de `.env`, `.git/HEAD` et `claude-export.zip` répondent 200 ; Mailpit répond sans authentification. L’accessibilité effective depuis un autre appareil dépend du réseau, de Podman/macOS et du pare-feu ; aucune exposition Internet ni consultation par un tiers n’est établie.

**À faire :** lier à loopback quand aucun partage n’est nécessaire ; ne servir que les fichiers runtime ou bloquer les fichiers de travail ; données synthétiques dans Mailpit/tests, chiffrement/verrouillage du laptop, règles pour exports et sauvegardes locales. Si des secrets réels ont pu être consultés, évaluer leur rotation après confinement.

**Acceptation :** navigation locale et tests fonctionnels préservés ; fichiers de travail refusés par HTTP ; pas de partage involontaire des mails. **Le ZIP v5.4.1 inspecté n’embarque aucun de ces fichiers : ne pas présenter ce point comme une faille démontrée du serveur distant. Développement ; S.**

### P2-14 — Versionner les règlements et les preuves d’acceptation

- [ ] **Conserver la version du document effectivement accepté, avec le contexte utile.**

**Constaté :** champs `reglement_accepted_at` et `sepa_reglement_accepted_at` dans familles/demandes/années ; réglages de documents remplaçables par identifiants de médias ; pas de version ou empreinte du texte accepté dans ce modèle. Le PDF de mandat généré ne démontre pas à lui seul l’existence d’un mandat valablement signé et archivé.

**À faire :** conserver référence/version datée, auteur identifiable et mode d’acceptation ; faire valider le circuit réel de signature et d’archivage SEPA. Séparer approbation du règlement, mandat de paiement et éventuel consentement à un traitement particulier.

**Acceptation :** remplacement d’un règlement ne change pas la preuve antérieure ; la mairie retrouve le document applicable à une inscription sans conserver des données supplémentaires inutiles. **Métier/facturation + DPO + développement ; M.**

### P2-15 — Auditer l’accessibilité des parcours essentiels

- [ ] **Tester clavier, lecteur d’écran, mobile et gestion des erreurs.**

**Périmètre à vérifier :** wizard multiétape, onglets enfants, grilles, popins, formulaires SEPA et notifications AJAX. Les attributs ARIA et tests de débordement présents sont utiles, mais aucun audit complet d’accessibilité n’est fourni. Ce chantier est distinct de la conformité RGPD.

**À faire :** ordre du focus, pièges clavier, annonce des états, libellés actualisés, contrastes, zoom, erreurs rattachées aux champs ; confirmer les obligations d’accessibilité du site communal avec son responsable.

**Acceptation :** inscription et correction du planning réalisables sans souris, avec lecteur d’écran et zoom important ; rapport de contrôle et corrections priorisées. **Développement + référent accessibilité ; M/L.**

## P3 — Maintenance

### P3-01 — Réduire les divergences entre vues et modèle métier

- [ ] **Formaliser les contrats présence, repas fourni, prestation déclarée et prestation facturée.**

**Constaté :** résolution centrale existante, mais adaptations répétées dans planning, CSV, mail, factures et SIDSCM ; commentaires parfois contradictoires avec le comportement actuel. Deux modèles d’année coexistent. Des changements récents ont corrigé une vue sans couvrir tous les consommateurs.

**À faire :** contrats d’entrée/sortie documentés, table de décision, fonctions de présentation communes ; réduire les doublons par étapes après couverture des comportements. Ne pas engager une réécriture globale pendant les corrections urgentes.

**Acceptation :** chaque règle possède une définition et des tests partagés ; ajout d’un tarif ou profil répercuté sur tous les canaux. **Développement ; L.**

### P3-02 — Mettre la documentation en accord avec le mode de déploiement réel

- [ ] **Distinguer laptop Podman, tests jetables et installation distante par ZIP.**

**Constaté :** guide d’auto-hébergement orienté Compose et anciens trimestres ; commentaires historiques sur stockage hors racine, forfaits, calendrier et cron parfois obsolètes ; README déjà en cours de modification avant cet audit.

**À faire :** procédure ZIP incluant sauvegarde préalable, contrôle de version, migrations, reprise et recette ; guide local distinct ; documentation des données et droits ; ne pas affirmer « conforme RGPD » sur la seule présence de chiffrement ou de suppression à la désinstallation.

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

## Ordre de travail proposé après lecture

1. **P0-01 et P0-02** : exactitude des allergies et de la présence midi ; contrôle des données déjà enregistrées avec la mairie.
2. **Accès et confidentialité** : P1-01 à P1-05 et P1-11/P1-12 ; recette du serveur distant P1-18.
3. **Intégrité métier** : P1-13 à P1-17, puis P2-01/P2-02/P2-08 ; tests associés avant chaque modification.
4. **Conformité avec le DPO** : P1-06 à P1-10, en parallèle de la conception des correctifs ; aucune purge réelle avant validation des règles d’archives et de conservation.
5. **Fiabilisation** : autres P2, puis P3. Traiter P2-11 avant le prochain tag si ce TODO est ajouté au dépôt suivi.

## Conditions pour conclure à un niveau de conformité acceptable

La fermeture d’un ticket technique exige une preuve de test ; un ticket d’hébergement exige un contrôle sur le **serveur distant** ; un ticket organisationnel exige un document ou une décision de la mairie/DPO. Les trois ne sont pas interchangeables.

Avant d’annoncer la conformité : clôture des points bloquants, politique de conservation appliquée, droits exerçables, habilitations vérifiées, sauvegarde restaurée, information publiée et dossier de conformité validé. Toute impossibilité restante doit faire l’objet d’un arbitrage documenté, et non d’une case cochée par défaut.

**Aucune tâche de cette liste n’est commencée. La prochaine étape est ta lecture et ton arbitrage ; aucune implémentation n’est autorisée par la seule création de ce document.**
