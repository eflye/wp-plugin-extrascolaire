import type { CSSProperties, ReactNode } from 'react';
import { cx } from '../util';

export interface PscRootProps {
  children?: ReactNode;
  /** Ajoute la marge intérieure d'une page invité (`.psc-guest-inner`, 920px centrés). */
  padded?: boolean;
  className?: string;
  style?: CSSProperties;
}

/**
 * Racine obligatoire du design system : pose les jetons `--psc-*`
 * (encre, abricot, crème…), la police Public Sans et le fond crème.
 * Tout composant rendu hors de PscRoot perd ses couleurs.
 */
export function PscRoot({ children, padded = false, className, style }: PscRootProps) {
  return (
    <div className={cx('psc-guest psc-sidscm', className)} style={style}>
      {padded ? <div className="psc-guest-inner">{children}</div> : children}
    </div>
  );
}
