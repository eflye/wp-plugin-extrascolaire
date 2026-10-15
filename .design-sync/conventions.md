# SIDISCM DS — conventions (portail périscolaire de Montgeroult–Courcelles)

Interfaces en français, pour des familles (portail), des agents de terrain (écran intervenants) et la mairie. Direction « L'Habit de Marianne » : rigueur d'un service public numérique, aplats, une seule police.

## Racine obligatoire

Enveloppe tout écran dans `PscRoot`. C'est lui qui pose les jetons `--psc-*`, la police Public Sans et le fond crème ; sans lui, les composants perdent leurs couleurs.

```jsx
const { PscRoot, PortalLayout, Sidebar, PageHeader, StatGrid, StatCard, Button } = window.SidiscmDS;

<PscRoot>
  <PortalLayout sidebar={<Sidebar accountEmail="claire.martin@exemple.fr" items={[
    { label: 'Tableau de bord', icon: 'home', active: true },
    { label: 'Planning', icon: 'calendar' },
    { label: 'Messages', icon: 'mail', badge: 2 },
  ]} />}>
    <PageHeader dashboard eyebrow="Jeudi 15 octobre" title="Famille Martin" subtitle="Voici l’essentiel de la semaine." />
    <StatGrid>
      <StatCard label="Dernière facture" value="86,40 €" sub="Septembre · payée" />
      <StatCard gold label="Un imprévu ?"><Button variant="ink">Signaler une absence</Button></StatCard>
    </StatGrid>
  </PortalLayout>
</PscRoot>
```

Page invité (connexion, demande d'inscription) : `<PscRoot padded>` puis `GuestCard`. Écran intervenants : `AppHeader`, `DayPicker`, `ServiceTabs`, `AttendanceList`.

## Style : jetons CSS, pas de classes inventées

Pour la mise en page qui n'est pas un composant, utilise des styles en ligne avec ces jetons (définis par `PscRoot`) :

| Jeton | Rôle |
|---|---|
| `var(--psc-forest)` #24405C | encre : titres, fonds d'autorité (barre latérale, bandeaux) |
| `var(--psc-moss)` #4E6C8D | texte secondaire, libellés |
| `var(--psc-gold)` #E08A5F | abricot : **uniquement** ce qui est actionnable |
| `var(--psc-gold-ink)` #9A5B2E | abricot en texte, bordure basse des aplats abricot |
| `var(--psc-ink)` #1A1A1A | texte courant |
| `var(--psc-cream)` #F2F3F5 / `var(--psc-paper)` #EAEFF4 | fond de page / encarts |
| `var(--psc-stone)` #665F58 | métadonnées |
| `var(--psc-rule)`, `--psc-rule-light`, `--psc-rule-lighter` | filets de 22 %, 15 % et 10 % |
| `var(--psc-error)` #9E4A4A | alerte |

Classes de mise en page réelles, réutilisables telles quelles : `psc-portal-cards` (grille de 3), `psc-portal-dash-children` (rangée de cartes enfant), `psc-portal-field-grid` (2 colonnes de champs), `psc-portal-intro`, `psc-portal-muted`, `psc-sr-only`.

Espacements : 6, 12, 20, 26 et 40 px.

## Règles de la marque

- **Coins carrés partout** (`border-radius: 0`). Seuls les points de statut et les cercles d'étape sont ronds.
- **Aucune ombre décorative**. Le seul relief est une bordure basse de 3 px sur les aplats abricot ; seuls les éléments réellement flottants (toast, liste d'autocomplétion, bandeau collé en haut) ont une ombre.
- **Une seule police**, Public Sans, sans italique. La hiérarchie passe par la graisse (400 à 800) et par les capitales espacées.
- **Abricot rare** : un `Button variant="primary"` par écran. Les badges et les fonds informatifs ne sont jamais abricot.
- **Statuts** : `ConversationStatus status="recue"` est le seul en aplat ; les autres sont en contour.
- Un encart d'information porte un liseré gauche (`InfoRail`, `ConfirmBox`, `Notice`), jamais un cadre plein.
- L'état ne repose jamais sur la seule couleur : la case cochée affiche ✓, la ligne verrouillée un cadenas.

## Réactivité

Sous 900 px de **fenêtre**, la barre latérale passe en bandeau horizontal, `StatGrid` et `FieldGrid` passent sur une colonne. Une maquette de bureau demande une fenêtre de plus de 900 px.

## Où trouver la référence

`styles.css` → `_ds_bundle.css` (feuilles réelles du plugin : frontend, portal, psc-messages, sidscm) ; `components/<groupe>/<Nom>/<Nom>.prompt.md` pour l'API de chaque composant ; `guidelines/DESIGN.md` pour la direction complète.
