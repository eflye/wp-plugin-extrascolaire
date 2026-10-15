import { Tabs } from 'sidiscm-ds';

export const Messagerie = () => (
  <div style={{ padding: 16, maxWidth: 640 }}>
    <Tabs items={[{ label: 'Mes échanges', active: true, badge: 2 }, { label: 'Informations de la mairie' }, { label: 'Écrire à la mairie' }]} />
  </div>
);
