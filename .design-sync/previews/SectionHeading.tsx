import { SectionHeading } from 'sidiscm-ds';

export const Numerotee = () => (
  <div style={{ padding: 16, maxWidth: 640 }}>
    <SectionHeading numeral="I.">Cette semaine</SectionHeading>
    <SectionHeading numeral="II." aside={<span className="psc-portal-child-total">3 enfants</span>}>Mes enfants</SectionHeading>
  </div>
);

export const Simple = () => (
  <div style={{ padding: 16, maxWidth: 640 }}>
    <SectionHeading>Menu de la cantine</SectionHeading>
  </div>
);
