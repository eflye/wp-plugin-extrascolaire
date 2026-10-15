import { Pill } from 'sidiscm-ds';

export const Etats = () => (
  <div style={{ display: 'flex', gap: 10, alignItems: 'center', padding: 16 }}>
    <Pill>Payée</Pill>
    <Pill tone="pending">À régler</Pill>
    <Pill tone="muted">Non émise</Pill>
  </div>
);
