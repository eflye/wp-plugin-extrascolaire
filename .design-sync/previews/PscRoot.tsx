import { PscRoot, PageHeader, Button } from 'sidiscm-ds';

export const PageInvite = () => (
  <PscRoot padded>
    <PageHeader eyebrow="Service périscolaire" title="Inscriptions 2026-2027" subtitle="Garderie, cantine et menus des écoles de Montgeroult et Courcelles." />
    <Button variant="primary">Faire une demande</Button>
  </PscRoot>
);

export const Jetons = () => (
  <PscRoot style={{ padding: 20 }}>
    <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
      {[['Encre', 'var(--psc-forest)', '#fff'], ['Bleu doux', 'var(--psc-moss)', '#fff'], ['Abricot', 'var(--psc-gold)', 'var(--psc-ink)'], ['Abricot encré', 'var(--psc-gold-ink)', '#fff'], ['Papier', 'var(--psc-paper)', 'var(--psc-ink)'], ['Crème', 'var(--psc-cream)', 'var(--psc-ink)'], ['Pierre', 'var(--psc-stone)', '#fff'], ['Noir', 'var(--psc-ink)', '#fff']].map(([n, bg, fg]) => (
        <div key={n} style={{ background: bg, color: fg, width: 110, height: 70, padding: 8, fontSize: 12, fontWeight: 700, border: '1px solid var(--psc-rule-light)' }}>{n}</div>
      ))}
    </div>
  </PscRoot>
);
