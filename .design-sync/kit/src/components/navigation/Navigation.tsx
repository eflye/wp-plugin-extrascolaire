import type { ReactNode } from 'react';
import { cx } from '../util';
import { Icon, type IconName } from '../fondations/Icon';

export interface SidebarItem {
  label: string;
  /** Pictogramme 18px au trait (voir `Icon`). */
  icon?: IconName;
  href?: string;
  active?: boolean;
  /** Pastille abricot de non-lus (messages). */
  badge?: number;
}

export interface SidebarProps {
  items: SidebarItem[];
  /** Intitulé au-dessus de la navigation. */
  sectionLabel?: string;
  /** Adresse e-mail affichée dans le bloc « Connecté avec ». */
  accountEmail?: string;
  /** Texte du lien d'aide sous la navigation. */
  helpLabel?: string;
}

/**
 * Barre latérale du portail famille : fond encre plein (zone d'autorité),
 * élément actif teinté abricot, compte connecté poussé en bas.
 */
export function Sidebar({ items, sectionLabel = 'Espace familles', accountEmail, helpLabel = 'Aide — guide des familles' }: SidebarProps) {
  return (
    <aside className="psc-portal-sidebar">
      <div className="psc-portal-section-label">{sectionLabel}</div>
      <nav className="psc-portal-nav">
        {items.map((it) => (
          <a key={it.label} href={it.href ?? '#'} className={cx('psc-portal-nav-btn', it.active && 'is-active')} aria-current={it.active ? 'page' : undefined}>
            {it.icon && <Icon name={it.icon} />}
            {it.label}
            {it.badge ? <span className="psc-message-nav-badge">{it.badge}</span> : null}
          </a>
        ))}
      </nav>
      {helpLabel && (
        <div className="psc-portal-help">
          <a className="psc-portal-help-link" href="#">{helpLabel}</a>
        </div>
      )}
      {accountEmail && (
        <div className="psc-portal-account">
          <div className="psc-portal-account-label">Connecté avec</div>
          <div className="psc-portal-account-email">{accountEmail}</div>
          <button type="button" className="psc-portal-account-logout">Se déconnecter</button>
        </div>
      )}
    </aside>
  );
}

export interface PortalLayoutProps {
  /** La barre latérale (`Sidebar`). */
  sidebar: ReactNode;
  children: ReactNode;
  /** Bandeau pleine largeur au-dessus (ex. `ImpersonationBanner`). */
  banner?: ReactNode;
}

/** Gabarit du portail famille : barre latérale 264px + colonne principale (max 1120px). */
export function PortalLayout({ sidebar, children, banner }: PortalLayoutProps) {
  return (
    <div className="psc-portal" data-psc-readonly={banner ? '1' : undefined}>
      {banner}
      {sidebar}
      <main className="psc-portal-main">{children}</main>
    </div>
  );
}

export interface SpaceSwitchProps {
  /** Espace courant (non cliquable). */
  current?: string;
  /** Autre espace, en lien. */
  otherLabel?: string;
  otherHref?: string;
}

/** Bascule entre espaces, alignée à droite au-dessus du contenu. */
export function SpaceSwitch({ current = 'Espace familles', otherLabel = 'Espace intervenants', otherHref = '#' }: SpaceSwitchProps) {
  return (
    <div className="psc-portal-switch">
      <span className="psc-portal-switch-current">{current}</span>
      <a className="psc-portal-switch-link" href={otherHref}>{otherLabel}</a>
    </div>
  );
}

export interface TabItem {
  label: string;
  href?: string;
  active?: boolean;
  /** Compteur abricot (non-lus). */
  badge?: number;
}

export interface TabsProps {
  items: TabItem[];
}

/** Onglets soulignés (messagerie) : l'actif porte un filet encre de 2px. */
export function Tabs({ items }: TabsProps) {
  return (
    <nav className="psc-message-tabs">
      {items.map((t) => (
        <a key={t.label} href={t.href ?? '#'} className={cx('psc-message-tab', t.active && 'is-active')} aria-current={t.active ? 'page' : undefined}>
          {t.label}
          {t.badge ? <span className="psc-message-tab-badge">{t.badge}</span> : null}
        </a>
      ))}
    </nav>
  );
}

export interface StepperProps {
  /** Libellés des étapes, dans l'ordre. */
  steps: string[];
  /** Index (0) de l'étape en cours ; les précédentes sont « faites ». */
  current: number;
}

/** Progression d'un formulaire en plusieurs étapes (demande d'inscription). */
export function Stepper({ steps, current }: StepperProps) {
  return (
    <ol className="psc-wizard-stepper" style={{ listStyle: 'none', padding: 0 }}>
      {steps.map((label, i) => {
        const state = i < current ? 'is-done' : i === current ? 'is-current' : '';
        return (
          <li key={label} className="psc-wizard-step-wrap">
            <div className="psc-wizard-step-track">
              <button type="button" className={cx('psc-wizard-circle', state)} aria-current={i === current ? 'step' : undefined}>
                {i < current ? '✓' : i + 1}
              </button>
              {i < steps.length - 1 && <span className={cx('psc-wizard-line', i < current && 'is-done')} />}
            </div>
            <span className={cx('psc-wizard-step-label', i === current && 'is-current')}>{label}</span>
          </li>
        );
      })}
    </ol>
  );
}
