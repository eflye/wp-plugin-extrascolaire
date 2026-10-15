import { Button } from 'sidiscm-ds';

const row = { display: 'flex', gap: 12, flexWrap: 'wrap' as const, alignItems: 'center', padding: 16 };

export const Principal = () => (
  <div style={row}>
    <Button variant="primary">Envoyer ma demande</Button>
    <Button variant="primary" disabled>Envoyer ma demande</Button>
  </div>
);

export const Secondaires = () => (
  <div style={row}>
    <Button variant="secondary">Écrire à la mairie</Button>
    <Button variant="outline-forest">Voir la facture</Button>
    <Button variant="outline-ink">Annuler</Button>
  </div>
);

export const CarteAbricot = () => (
  <div style={row}>
    <Button variant="ink">Signaler une absence</Button>
    <Button variant="small">Enregistrer</Button>
    <Button variant="dashed">+ Ajouter un enfant</Button>
  </div>
);
