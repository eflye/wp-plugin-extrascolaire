# Notes design-sync — SIDISCM DS

- **Le dépôt n'est pas un design system React.** C'est un plugin WordPress (PHP, CSS, JS natif). Le paquet `.design-sync/kit/` (`sidiscm-ds`) contient de fins composants React qui produisent exactement le balisage `.psc-*` des templates PHP (`templates/`, `assets/js/sidscm.js`). Ils ne réimplémentent aucun style : la CSS vient telle quelle de `assets/css/`.
- **Construction** : `cd .design-sync/kit && npm ci && npm run build` (`buildCmd`). `build.mjs` concatène `frontend.css`, `portal.css`, `psc-messages.css` et `sidscm.css` dans l'ordre d'enqueue WordPress vers `dist/sidiscm.css`, rebase les URL de police sur `dist/fonts/` et copie les deux woff2 Public Sans. Une feuille ajoutée ou retirée côté plugin doit être reportée dans `build.mjs`.
- **Racine** : `PscRoot` rend `div.psc-guest.psc-sidscm`, les deux portées qui déclarent les jetons `--psc-*` (`--psc-error` n'existe que sous `.psc-sidscm`). Ne pas y ajouter `.psc-family-messages` : cette portée redéfinit `--psc-ink` (#24405C au lieu de #1A1A1A) et stylise tout `h2`.
- **Composants regroupés par fichier** (`src/components/<groupe>/<Fichier>.tsx`) : la découverte du convertisseur ne retrouve pas plusieurs composants dans un même fichier, d'où `componentSrcMap`, qui épingle chaque composant. Le groupe vient du nom de dossier.
- **Point de rupture à 900 px de fenêtre** (`@media (max-width: 900px)` dans portal.css). Les captures à 900 px affichent donc la mise en page mobile. `PortalLayout`, `Sidebar` et `StatGrid` ont une `viewport` de 1280 px dans `overrides` pour montrer la version bureau.
- **Textes d'aperçu** : ils reprennent les libellés réels des templates (`ConfirmBox`, `ImpersonationBanner`, badge « Midi sans repas — à ne pas compter dans les couverts »). Ne pas inventer de fonctionnalité dans un aperçu.
- `.design-sync/kit/dist/` et `node_modules/` sont ignorés par git ; `.design-sync` figure dans la liste d'exclusion de `release.yml` (ce n'est pas du code du plugin).

## Known render warns

Aucun : la vérification du rendu est propre sur les 42 composants.

## Re-sync risks

- **CSS du plugin modifiée** : relancer `buildCmd`, puis le pilote. Les aperçus se mettent à jour, mais une classe renommée dans `assets/css` casse silencieusement le composant React qui l'emploie. Après toute refonte CSS, comparer les classes de `src/components/**` avec les feuilles (grep).
- **Balisage des templates modifié** : les composants ne suivent pas les templates PHP automatiquement. Une nouvelle structure (ex. ligne SIDSCM, en-tête de fil) se reporte à la main dans le composant correspondant.
- **Libellés recopiés** depuis les templates et `class-psc-sidscm.php` (i18n) : ils peuvent diverger après une modification de texte côté plugin.
- **Icônes** : chemins SVG recopiés de `Psc_Frontend` (onglets du portail). Une nouvelle icône côté plugin doit être ajoutée à `Icon.tsx`.
- **Outils** : esbuild 0.24, TypeScript 5.6, React 18.3 (versions du kit) ; playwright 1.62.1 pour la vérification (chromium-1234 en cache local).
