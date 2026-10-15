import { DataTable, Pill, Button } from 'sidiscm-ds';

export const Factures = () => (
  <div style={{ padding: 16, maxWidth: 760 }}>
    <DataTable
      caption="Mes factures"
      columns={[{ key: 'mois', label: 'Mois' }, { key: 'montant', label: 'Montant' }, { key: 'statut', label: 'Statut' }, { key: 'action', label: '' }]}
      rows={[
        { mois: 'Septembre 2026', montant: '86,40 €', statut: <Pill>Payée</Pill>, action: <Button variant="outline-forest" href="#">PDF</Button> },
        { mois: 'Octobre 2026', montant: '92,15 €', statut: <Pill tone="pending">À régler</Pill>, action: <Button variant="outline-forest" href="#">PDF</Button> },
      ]}
    />
  </div>
);

export const Enfants = () => (
  <div style={{ padding: 16, maxWidth: 760 }}>
    <DataTable
      caption="Mes enfants"
      columns={[{ key: 'nom', label: 'Enfant' }, { key: 'classe', label: 'Classe' }, { key: 'regime', label: 'Régime' }, { key: 'assurance', label: 'Assurance' }]}
      rows={[
        { nom: 'Léa Martin', classe: 'CE1', regime: 'Sans porc', assurance: <Pill>Validée</Pill> },
        { nom: 'Hugo Martin', classe: 'GS', regime: <span className="psc-portal-muted">—</span>, assurance: <Pill tone="pending">En attente</Pill> },
      ]}
    />
  </div>
);
