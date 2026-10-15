import { Sidebar } from 'sidiscm-ds';

export const Famille = () => (
  <div style={{ display: 'flex', minHeight: 560 }}>
    <Sidebar
      accountEmail="claire.martin@exemple.fr"
      items={[
        { label: 'Tableau de bord', icon: 'home', active: true },
        { label: 'Planning', icon: 'calendar' },
        { label: 'Menu de la semaine', icon: 'menu' },
        { label: 'Messages', icon: 'mail', badge: 2 },
        { label: 'Mes enfants', icon: 'child' },
        { label: 'Habilitations', icon: 'shield' },
        { label: 'Mes factures', icon: 'receipt' },
        { label: 'Mon profil', icon: 'profile' },
        { label: 'Documents', icon: 'document' },
      ]}
    />
  </div>
);
