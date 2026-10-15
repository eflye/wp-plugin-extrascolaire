import { ServiceTabs } from 'sidiscm-ds';

export const Services = () => (
  <div style={{ paddingBottom: 16 }}>
    <ServiceTabs active="Cantine" services={[{ label: 'Garderie du matin', count: 14 }, { label: 'Cantine', count: 52 }, { label: 'Garderie du soir', count: 21 }]} />
  </div>
);
