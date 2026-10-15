import { StatCard, Button } from 'sidiscm-ds';

export const Chiffre = () => (
  <div style={{ padding: 16, maxWidth: 300 }}>
    <StatCard label="Dernière facture" value="86,40 €" sub="Septembre · payée" />
  </div>
);

export const AccesRapide = () => (
  <div style={{ padding: 16, maxWidth: 300 }}>
    <StatCard gold label="Un imprévu ?">
      <Button variant="ink">Signaler une absence</Button>
      <Button variant="outline-ink">Voir le planning</Button>
    </StatCard>
  </div>
);
