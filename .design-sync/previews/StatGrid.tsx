import { StatGrid, StatCard, Button } from 'sidiscm-ds';

export const TableauDeBord = () => (
  <div style={{ padding: 16, maxWidth: 960 }}>
    <StatGrid>
      <StatCard label="Réservations du mois" value="34" sub="pour 2 enfants" />
      <StatCard label="Dernière facture" value="86,40 €" sub="Septembre · payée" />
      <StatCard gold label="Un imprévu ?">
        <Button variant="ink">Signaler une absence</Button>
        <Button variant="outline-ink">Voir le planning</Button>
      </StatCard>
    </StatGrid>
  </div>
);
