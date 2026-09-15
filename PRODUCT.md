# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Familles** (parents/tuteurs) : gèrent au quotidien la vie périscolaire de leurs enfants sans passer par la mairie — inscription, planning annuel (rythme + exceptions), dépôt de l'assurance scolaire, suivi de facturation, messages de la mairie et échanges directs avec le service. Se connectent par lien e-mail (pas de compte WordPress, pas de mot de passe).
- **La mairie / le service périscolaire** (agents du SIVOS, backoffice WordPress) : valide les demandes d'inscription, les familles, les enfants et les assurances ; configure années scolaires, tarifs, menus, messages ; exploite les mêmes données de planning pour les listes terrain, les commandes fournisseur, la facturation et les prélèvements SEPA.
- **Les intervenants** (agents de terrain — garderie, cantine) : écran dédié, protégé par code d'accès (pas de compte non plus), optimisé mobile, pour pointer les présences, consulter régimes/allergies/personnes autorisées à récupérer un enfant.
- **Le fournisseur de repas** : reçoit les commandes calculées depuis le planning (rôle en aval, pas d'accès direct au produit à ce jour).

## Product Purpose

Centraliser l'inscription, le planning, la cantine, les garderies, les présences et la facturation périscolaires dans un seul plugin WordPress auto-hébergé par la collectivité, pour remplacer un fonctionnement à base de fichiers calendrier remplis à la main et recroisés entre plusieurs personnes. Succès = une famille peut gérer seule son planning et ses documents sans solliciter la mairie, et la mairie peut produire listes, commandes, factures et prélèvements sans ressaisie.

## Positioning

Un seul planning annuel par enfant (rythme récurrent + exceptions ponctuelles) alimente cinq usages en aval sans double saisie : listes d'enfants attendus, pointage des présences, commandes de repas, exports du secrétariat, factures mensuelles. Auto-hébergé sur le WordPress de la collectivité (aucune donnée chez un tiers SaaS), distribué en open source (GPLv2+), et volontairement sans paiement en ligne : le plugin génère les factures et prépare les fichiers de prélèvement `pain.008` mais ne transmet aucun ordre à la banque — la collectivité garde la main sur le circuit financier.

## Operating Context

- Développé pour et actuellement déployé pour le **SIVOS de Montgeroult–Courcelles** (Val-d'Oise, Vexin français) ; publié en open source sur GitHub avec l'avertissement explicite qu'une adaptation est nécessaire pour un autre territoire ou une autre organisation scolaire.
- **Pas encore en production** au moment de la rédaction de ce document : développement/tests en cours, pas encore ouvert aux familles.
- Suit la semaine scolaire à 4 jours (lundi, mardi, jeudi, vendredi), importe le calendrier scolaire de la zone C, et conserve des libellés hérités du SIDSCM (écran intervenants).
- Cycle annuel : préparation du service par la mairie (année scolaire, calendrier, tarifs, réglages) → inscription des familles avec validation e-mail puis mairie → dossier enfant (assurance) → construction du planning par la famille → exploitation mairie (terrain, commandes, factures, exports, prélèvements) → passage d'année et réinscription en fin de cycle.
- Développement/tests locaux via WordPress + MySQL + Mailpit sous Podman ; suite de tests unitaires PHP, tests d'intégration WP-CLI, E2E Playwright, PHPStan.

## Capabilities and Constraints

- Pas de paiement en ligne : aucune transmission d'ordre bancaire, seulement génération de factures PDF et préparation de fichiers de prélèvement SEPA `pain.008` ; l'acceptation du règlement intérieur se fait par case à cocher horodatée (pas une signature électronique qualifiée).
- Plugin WordPress classique : PHP 7.4+/WordPress 5.8+, sans framework JS ni build en production (jQuery disponible) ; nécessite HTTPS et un transport d'e-mails fiable, `ZipArchive` (export ODS) et DOM/libxml (export `pain.008`) en production.
- Sécurité/vie privée déjà en place : jetons de connexion hachés, sessions révocables, mode « consultation mairie » journalisé et limité à 30 minutes (lecture seule stricte, écritures refusées serveur), IBAN chiffré au repos, documents (assurances, factures) hors médiathèque publique, limitation de tentatives sur les formulaires publics, journal d'audit unifié.
- Conformité RGPD : les mécanismes techniques ne suffisent pas seuls (registre de traitements, durées de conservation, information des familles restent à la charge de la collectivité et de son DPO) — cf. TODO.md.
- **Accessibilité : cible confirmée = RGAA** (obligation légale pour un service public en ligne français). Un audit RGAA complet n'a pas encore été réalisé ; des attributs ARIA et corrections ponctuelles existent déjà, mais focus/clavier, contraste, zoom et libellés dynamiques restent à vérifier systématiquement (cf. TODO.md, chantier P2-15).
- Le design système existant (Fraunces pour les titres, Work Sans pour l'interface, Cormorant Garamond italique pour les sous-titres d'écran ; palette bleu encre `#24405C`, bleu doux `#4E6C8D`, abricot `#E08A5F`, crème `#FAF6F1`, blush `#F5E7DC`, rouge alerte `#9E4A4A`) est déjà en place sur l'ensemble du portail famille et doit être respecté par tout nouvel écran, pas réinventé.

## Brand Commitments

- Nom du produit : « Périscolaire — Inscriptions » (dépôt GitHub `eflye/wp-plugin-extrascolaire`).
- Identité de la collectivité cliente réelle : Syndicat intercommunal (SIVOS) de **Montgeroult–Courcelles**, Val-d'Oise, Vexin français — nom et territoire à traiter comme donnée réelle, pas comme exemple à généraliser sans le dire.
- Licence GPLv2 ou ultérieure ; projet open source.

## Evidence on Hand

- Captures d'écran réelles dans `docs/images/` : `planning-famille.png` (planning annuel espace famille), `tableau-de-bord-mairie.png` (tableau de bord mairie), `pointage-intervenants.png` (pointage mobile intervenants).
- `README.md` documente le parcours complet (installation, sécurité, limites connues) et sert de source de vérité produit.
- `TODO.md` et `docs/refactoring-safety-net.md` recensent les chantiers ouverts (accessibilité, RGPD, migrations) — à ne pas présenter comme déjà résolus.
- Aucun témoignage, chiffre d'usage ou étude de cas réel à ce jour : ne pas en inventer pour cette collectivité tant que le service n'est pas en production.

## Product Principles

- Une seule saisie de planning fait toute l'année et alimente tous les usages en aval — jamais de ressaisie manuelle entre écrans mairie et famille.
- La collectivité garde la maîtrise de ses données (auto-hébergement) et de son circuit financier (aucun paiement en ligne géré par le plugin).
- Les familles s'autoservent (inscription, planning, documents) pour réduire la charge du secrétariat, sans jamais perdre la possibilité pour la mairie de corriger ou surveiller.
- La transparence prime sur l'opacité utile : consultation mairie d'un espace famille toujours journalisée et bornée dans le temps, jamais silencieuse.
- Le produit reste un outil de collectivité française, pas un SaaS générique : les contraintes réglementaires françaises (RGPD, RGAA, SEPA) sont des exigences produit, pas des options.

## Accessibility & Inclusion

Cible confirmée : **RGAA**. Aucun audit RGAA complet n'a encore été mené ; c'est un chantier ouvert et documenté (TODO.md, P2-15 : ordre du focus, pièges clavier, annonce des états, libellés actualisés, contrastes, zoom, erreurs rattachées aux champs). Les parcours essentiels (inscription, correction de planning) doivent rester réalisables sans souris, au clavier et avec lecteur d'écran.
