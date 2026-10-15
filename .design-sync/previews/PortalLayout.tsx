import { PortalLayout, Sidebar, PageHeader, StatCard, StatGrid, ImpersonationBanner } from 'sidiscm-ds';

const items = [
  { label: 'Tableau de bord', icon: 'home' as const, active: true },
  { label: 'Planning', icon: 'calendar' as const },
  { label: 'Messages', icon: 'mail' as const, badge: 1 },
  { label: 'Mes factures', icon: 'receipt' as const },
];

export const PortailFamille = () => (
  <PortalLayout sidebar={<Sidebar items={items} accountEmail="claire.martin@exemple.fr" />}>
    <PageHeader dashboard eyebrow="Jeudi 15 octobre" title="Famille Martin" subtitle="Bonjour, voici l’essentiel de la semaine." />
    <StatGrid>
      <StatCard label="Réservations du mois" value="34" sub="pour 2 enfants" />
      <StatCard label="Dernière facture" value="86,40 €" sub="Septembre · payée" />
      <StatCard label="Messages" value="1" sub="réponse reçue" />
    </StatGrid>
  </PortalLayout>
);

export const Consultation = () => (
  <PortalLayout banner={<ImpersonationBanner family="Martin" until="15:40" />} sidebar={<Sidebar items={items} accountEmail="claire.martin@exemple.fr" />}>
    <PageHeader title="Mes factures" subtitle="Une facture par mois, détaillée par enfant." />
  </PortalLayout>
);
