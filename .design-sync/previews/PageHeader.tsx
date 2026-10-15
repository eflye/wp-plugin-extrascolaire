import { PageHeader } from 'sidiscm-ds';

export const TableauDeBord = () => (
  <div style={{ padding: 16 }}>
    <PageHeader dashboard eyebrow="Jeudi 15 octobre" title="Famille Martin" subtitle="Bonjour, voici l’essentiel de la semaine." />
  </div>
);

export const Section = () => (
  <div style={{ padding: 16 }}>
    <PageHeader eyebrow="Espace familles" title="Mes factures" subtitle="Une facture par mois, détaillée par enfant." />
  </div>
);
