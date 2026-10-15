import type { ReactNode } from 'react';
import { cx } from '../util';

export interface EyebrowProps {
  children: ReactNode;
  /** `moss` (bleu doux, portail) ou `gold` (abricot encré, pages invité). */
  tone?: 'moss' | 'gold';
}

/** Surtitre en capitales très espacées, au-dessus d'un titre de page. */
export function Eyebrow({ children, tone = 'moss' }: EyebrowProps) {
  return <p className={tone === 'gold' ? 'psc-guest-eyebrow' : 'psc-portal-eyebrow'}>{children}</p>;
}

export interface PageHeaderProps {
  title: ReactNode;
  eyebrow?: ReactNode;
  subtitle?: ReactNode;
  /** Titre plus grand (38px) et resserré, réservé au tableau de bord. */
  dashboard?: boolean;
}

/** En-tête de page du portail : surtitre, titre encre 800, sous-titre. */
export function PageHeader({ title, eyebrow, subtitle, dashboard = false }: PageHeaderProps) {
  return (
    <header>
      {eyebrow && <p className="psc-portal-eyebrow">{eyebrow}</p>}
      <h1 className={cx('psc-portal-h1', dashboard && 'psc-portal-h1--dashboard')}>{title}</h1>
      {subtitle && <p className="psc-portal-subtitle">{subtitle}</p>}
    </header>
  );
}

export interface SectionHeadingProps {
  children: ReactNode;
  /** Numéro de section en chiffres romains (« I. », « II. ») dans un cadre carré. */
  numeral?: string;
  /** Contenu aligné à droite (total, lien). */
  aside?: ReactNode;
}

/** Intitulé de section numéroté, souligné d'un filet. */
export function SectionHeading({ children, numeral, aside }: SectionHeadingProps) {
  return (
    <div className="psc-portal-section-heading">
      {numeral && <span className="psc-portal-numeral">{numeral}</span>}
      <h2 className="psc-portal-heading-label" style={{ margin: 0 }}>{children}</h2>
      {aside && <span style={{ marginLeft: 'auto' }}>{aside}</span>}
    </div>
  );
}
