---
name: Périscolaire — Inscriptions
description: Portail périscolaire du SIVOS de Montgeroult–Courcelles — direction "L'Habit de Marianne".
colors:
  ink:
    forest: "#24405C"
    moss: "#4E6C8D"
    black: "#1A1A1A"
  neutral:
    cream: "#F2F3F5"
    paper: "#EAEFF4"
    paper-line: "#D7DEE8"
    stone: "#8B8279"
    line: "rgba(36,64,92,0.15)"
    line-soft: "rgba(36,64,92,0.1)"
    surface: "#FFFFFF"
  accent:
    gold: "#E08A5F"
    gold-ink: "#9A5B2E"
  status:
    recue-bg: "#24405C"
    recue-fg: "#FFFFFF"
    attente-fg: "#8A4B25"
    lue-fg: "#24405C"
    close-bg: "#E4E6E9"
    close-fg: "#3A3F45"
    danger: "#9E4A4A"
typography:
  display:
    fontFamily: "'Public Sans', Helvetica, Arial, sans-serif"
    fontWeight: 800
    letterSpacing: "-0.01em"
  title:
    fontFamily: "'Public Sans', Helvetica, Arial, sans-serif"
    fontWeight: 700
  body:
    fontFamily: "'Public Sans', Helvetica, Arial, sans-serif"
    fontWeight: 400
    lineHeight: 1.6
  label:
    fontFamily: "'Public Sans', Helvetica, Arial, sans-serif"
    fontWeight: 700
    letterSpacing: "0.08em"
rounded:
  none: "0px"
  pill-dot: "50%"
spacing:
  xs: "6px"
  sm: "12px"
  md: "20px"
  lg: "26px"
  xl: "40px"
components:
  button-primary:
    backgroundColor: "{colors.accent.gold}"
    textColor: "{colors.ink.black}"
    typography: "{typography.label}"
    rounded: "{rounded.none}"
    padding: "12px 20px 9px"
  button-primary-disabled:
    backgroundColor: "#E6DED4"
    textColor: "#9A9086"
  button-secondary:
    backgroundColor: "transparent"
    textColor: "{colors.ink.forest}"
    rounded: "{rounded.none}"
    padding: "9px 13px"
  status-recue:
    backgroundColor: "{colors.status.recue-bg}"
    textColor: "{colors.status.recue-fg}"
    rounded: "{rounded.none}"
  status-attente:
    backgroundColor: "{colors.neutral.surface}"
    textColor: "{colors.status.attente-fg}"
    rounded: "{rounded.none}"
---

# Design System: Périscolaire — Inscriptions

## Overview

**Creative North Star: "L'Habit de Marianne"**

Ce n'est pas la charte du Système de Design de l'État — c'est ce qu'un service intercommunal a de plus proche sans emprunter la police Marianne ni le bleu France : la discipline d'un service public numérique français (aplat total, une seule famille de caractères, hiérarchie par la graisse et non par l'ornement, réserve du RGAA) appliquée à la palette bleu encre / abricot déjà propre à Montgeroult–Courcelles. Le système précédent (Fraunces, italique Cormorant Garamond, ombre « tampon ») a été traité comme preuve et contre-référence, pas comme point de départ : ce qui restait décoratif a été retiré, ce qui portait une vraie information (le liseré de non-lu, les numéros de section, le statut d'un échange) a été conservé et reconstruit dans le nouveau vocabulaire.

Une seule famille — Public Sans (USWDS, licence SIL OFL), auto-hébergée en variable woff2 comme les précédentes — fait tout le travail : titres, corps, libellés. Aucun corps italique nulle part. Le bleu encre, autrefois une touche parmi d'autres, devient le fond dominant des zones qui font autorité (bandeau du fil de conversation, en-tête). L'abricot ne reste que là où une action est réellement possible. L'ombre « tampon » à décalage plat disparaît : ce qui la remplace est une bordure basse nette de 3px, plus proche du bouton d'un service public que d'un cachet de cire.

**Key Characteristics:**
- Aplats stricts : aucune ombre décorative, uniquement des ombres fonctionnelles sur les éléments qui flottent réellement (menus déroulants, toasts, bannière de consultation).
- Une seule famille de caractères pour tout l'écran, hiérarchie par la graisse (400 à 800), jamais par le changement de police.
- Encre dominante sur les zones d'autorité (bandeaux, en-têtes de fil), abricot réservé aux actions.
- Statuts en contour par défaut ; seul le plus important (réponse reçue) reste en aplat pour rester lisible d'un coup d'œil.
- Coins carrés partout, sans exception décorative.

## Colors

Palette héritée du système précédent (les valeurs n'ont pas changé), mais la répartition change : l'encre passe de touche de texte à fond de zone.

### Primary
- **Bleu encre** (`#24405C`) : fond des bandeaux d'autorité (en-tête de fil de conversation, sidebar, en-tête de site), couleur de tous les titres, statut « réponse reçue ».

### Secondary
- **Bleu doux** (`#4E6C8D`) : texte secondaire, libellés d'auteur, sous-titres.

### Accent
- **Abricot** (`#E08A5F`) : réservé aux actions déclenchables (bouton primaire, carte d'accès rapide). N'apparaît jamais comme simple décoration.
- **Abricot encré** (`#9A5B2E`) : bordure basse des surfaces abricot — le seul relief du système, jamais une ombre.

### Neutral
- **Gris-bleu très clair** (`#F2F3F5`) : fond de page — volontairement froid, jamais le crème chaud de l'ancien système.
- **Papier froid** (`#EAEFF4`, filet `#D7DEE8`) : encarts d'information et bulle famille dans un fil — toujours associé à un liseré gauche encre plutôt qu'à une bordure pleine (cf. règle ci-dessous).
- **Pierre** (`#8B8279`) : texte tertiaire, métadonnées.
- **Filet** (`rgba(36,64,92,0.15)` / `0.1`) : bordures de carte, séparateurs de ligne.

### Named Rules
**La règle du fond d'autorité.** Une zone qui fait autorité sur l'écran (un en-tête de fil, un bandeau de site) prend l'encre en fond plein, texte en blanc ou en bleu doux clair — jamais l'encre en simple filet sur fond clair quand la zone porte une décision ou un statut.
**La règle de l'abricot rare.** L'abricot ne colore qu'un élément sur lequel on peut agir. Un badge, un fond de carte informative ou un filet ne l'utilisent jamais.
**La règle du liseré, pas de l'encadré.** Un encart informatif ou un message de la famille dans un fil ne porte jamais de bordure pleine autour d'un fond papier : un seul liseré encre à gauche (3–4px) suffit à le signaler, sur un fond blanc (bandeau de délai) ou papier froid (bulle famille) selon le poids de l'information.

## Typography

**Display/Body/Label Font:** Public Sans (USWDS, SIL OFL), avec `Helvetica, Arial, sans-serif` en repli. Auto-hébergée en fichier variable woff2 (`assets/fonts/publicsans-variable.woff2`), jamais via Google Fonts sur les écrans authentifiés — même position RGPD que l'ancien système.

**Character :** une seule famille grotesque humaniste, pensée pour un service public numérique — la hiérarchie se lit dans le poids (400 à 800) et l'espacement des capitales, jamais dans un changement de famille ou de style.

### Hierarchy
- **Display** (800, 32–38px, -0.01em) : titres de page (« Famille Dupont », « Messages »).
- **Title** (700, 22–24px) : titres de carte, sujets de conversation, titres de fil.
- **Label** (700, 10.5–12px, 0.04–0.14em, majuscules) : éyebrows, libellés de champ, boutons.
- **Body** (400–500, 14–16px, line-height 1.6) : texte courant.

### Named Rules
**La règle sans italique.** Aucun texte n'est mis en italique nulle part dans le produit — la conversion depuis Cormorant Garamond italique a supprimé la dernière occurrence.

## Layout

Grilles fluides existantes conservées (`repeat(auto-fit,minmax(...))` pour les tableaux de bord et grilles de messagerie) — aucun changement structurel, seul le vocabulaire visuel des blocs qu'elles contiennent change. Rythme d'espacement inchangé (échelle 6/12/20/26/40px déjà en usage).

## Elevation & Depth

Système plat par défaut, sans exception esthétique : aucune carte, aucun bouton ne porte d'ombre décorative. La seule marque de relief est une **bordure basse nette de 3px** dans une teinte plus sombre que le fond, posée sur les surfaces abricot (boutons primaires, carte d'accès rapide) — elle remplace l'ancienne ombre « tampon » à décalage plat identique.

Les éléments qui flottent réellement au-dessus du contenu (menu déroulant d'autocomplétion, toast de confirmation, bannière de consultation mairie fixée en haut d'écran) gardent une ombre douce fonctionnelle : ce n'est pas une exception à la règle du plat, c'est la même règle appliquée à un objet qui a une vraie position en z.

### Named Rules
**La règle de la ledge, pas du tampon.** Un élément actionnable ne projette jamais d'ombre : il porte une bordure basse plus sombre, comme le bord d'une marche, jamais comme le décalage d'un cachet encreur.

## Shapes

Coins carrés partout (`border-radius: 0`), sans exception décorative. Les seules formes rondes du système sont fonctionnelles et minuscules : puces de statut non-lu (6–7px, cercle plein) et cases à cocher rondes du calendrier de présence. Les numéros de section (« I. », « II. ») portent désormais un cadre carré fin plutôt qu'un italique — un repère de référence, pas une fioriture typographique.

## Components

### Buttons
- **Shape :** carré, aucun radius.
- **Primary :** fond abricot (`#E08A5F`), texte encre, bordure basse 3px `#9A5B2E`, capitales, `letter-spacing:0.04em`, padding `12px 20px 9px`.
- **Disabled :** fond `#E6DED4`, texte `#9A9086`, bordure basse transparente (pas de relief sur un état inactif).
- **Secondary :** fond transparent, bordure 1px `rgba(36,64,92,.3)`, texte encre.

### Chips (filtres, choix de formulaire)
- **Style :** bordure 1px, fond transparent, texte bleu doux ; état actif = fond encre plein, texte crème.
- **Implémentation :** `input[type=radio]` masqué + `label:has(input:checked)`, sans JavaScript pour la sélection elle-même.

### Cards / Containers
- **Corner Style :** carré.
- **Background :** blanc sur fond crème ; jamais de dégradé.
- **Shadow Strategy :** aucune (cf. Élévation) — la séparation vient du filet de bordure.
- **Border :** 1px `rgba(36,64,92,.15)`.

### Badges de statut
- **Réponse reçue :** seul statut en aplat plein (fond encre, texte blanc) — c'est celui qu'une famille doit repérer en un coup d'œil.
- **En attente / Lu par la mairie :** fond blanc, texte coloré, bordure `currentColor` — contour, pas aplat.
- **Clos :** fond gris neutre `#E4E6E9`, texte `#3A3F45`.

### En-tête de fil de conversation (signature)
Bandeau plein encre (`#24405C`) portant le sujet en blanc et les métadonnées en bleu très clair (`#C9D3DC`) — la seule zone du produit où l'encre couvre une surface entière plutôt qu'un simple texte ou filet. Le badge « Réponse reçue » s'y inverse (fond blanc, texte encre) pour rester lisible sur son propre fond.

## Do's and Don'ts

### Do:
- **Do** garder Public Sans comme unique famille, du titre de page au libellé de champ — la hiérarchie se fait par le poids, pas par un changement de police.
- **Do** réserver l'aplat de statut (fond plein) au seul état qu'une famille doit repérer sans lire le texte ; tout le reste en contour.
- **Do** utiliser la bordure basse 3px comme seul signal de relief sur un élément actionnable.
- **Do** garder l'encre en fond plein réservée aux zones qui font réellement autorité (bandeau de fil, en-tête de site) — pas comme habillage systématique de chaque carte.

### Don't:
- **Don't** réintroduire une police serif ou un corps italique nulle part, y compris pour un sous-titre ou une citation.
- **Don't** ajouter une ombre douce décorative à un bouton ou une carte statique — seuls les éléments réellement flottants (dropdown, toast, bannière fixe) en portent une.
- **Don't** arrondir un coin par habitude : le carré est la forme par défaut de tout le produit, sans exception esthétique.
- **Don't** charger une police via Google Fonts sur un écran authentifié (portail famille, écran intervenants) : l'auto-hébergement RGPD est une contrainte produit, pas une préférence de style.
