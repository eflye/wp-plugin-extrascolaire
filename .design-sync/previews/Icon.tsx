import { Icon } from 'sidiscm-ds';

const names = ['home', 'calendar', 'menu', 'mail', 'child', 'shield', 'receipt', 'profile', 'document', 'renew'] as const;

export const Jeu = () => (
  <div style={{ display: 'flex', gap: 18, flexWrap: 'wrap', padding: 16, color: 'var(--psc-forest)' }}>
    {names.map((n) => (
      <div key={n} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6, fontSize: 11, color: 'var(--psc-moss)' }}>
        <span style={{ color: 'var(--psc-forest)' }}><Icon name={n} size={24} /></span>{n}
      </div>
    ))}
  </div>
);

export const SurEncre = () => (
  <div style={{ display: 'flex', gap: 14, padding: 16, background: 'var(--psc-forest)', color: 'var(--psc-cream)' }}>
    {names.map((n) => <Icon key={n} name={n} />)}
  </div>
);
