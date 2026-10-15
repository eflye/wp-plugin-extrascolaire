import { Chip } from 'sidiscm-ds';

export const Filtres = () => (
  <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap', padding: 16 }}>
    <Chip name="filtre" value="tous" defaultChecked>Tous</Chip>
    <Chip name="filtre" value="attente">En attente</Chip>
    <Chip name="filtre" value="recue">Réponse reçue</Chip>
    <Chip name="filtre" value="clos">Clos</Chip>
  </div>
);
