import type { MouseEventHandler, ReactNode } from 'react';
import { cx } from '../util';

export type ButtonVariant =
  | 'primary'
  | 'secondary'
  | 'ink'
  | 'outline-ink'
  | 'outline-forest'
  | 'small'
  | 'dashed';

export interface ButtonProps {
  children: ReactNode;
  /**
   * `primary` abricot à bordure basse 3px (action principale, une par écran) ;
   * `secondary` contour encre ; `ink` aplat noir ; `outline-ink` contour noir ;
   * `outline-forest` contour encre en capitales ; `small` aplat encre compact
   * (actions de ligne de tableau) ; `dashed` pointillé (« + Ajouter »).
   */
  variant?: ButtonVariant;
  disabled?: boolean;
  /** Rend un lien `<a>` au lieu d'un `<button>`. */
  href?: string;
  type?: 'button' | 'submit' | 'reset';
  onClick?: MouseEventHandler<HTMLElement>;
  className?: string;
}

const VARIANT_CLASS: Record<ButtonVariant, string> = {
  primary: 'psc-btn-primary',
  secondary: 'psc-btn-secondary',
  ink: 'psc-portal-btn-ink',
  'outline-ink': 'psc-portal-btn-outline-ink',
  'outline-forest': 'psc-portal-btn-outline-forest',
  small: 'psc-portal-btn-sm',
  dashed: 'psc-wizard-add-btn',
};

/** Bouton du portail : coins carrés, capitales espacées, jamais d'ombre. */
export function Button({ children, variant = 'primary', disabled, href, type = 'button', onClick, className }: ButtonProps) {
  const cls = cx(VARIANT_CLASS[variant], href && disabled && 'is-disabled', className);
  if (href) {
    return (
      <a className={cls} href={disabled ? undefined : href} aria-disabled={disabled || undefined} onClick={onClick}>
        {children}
      </a>
    );
  }
  return (
    <button type={type} className={cls} disabled={disabled} onClick={onClick}>
      {children}
    </button>
  );
}
