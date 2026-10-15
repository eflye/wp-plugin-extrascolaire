import type { ReactNode } from 'react';

export interface NoticeProps {
  children: ReactNode;
  /** `ok` liseré encre sur papier ; `err` liseré rouge sur rose pâle. */
  tone?: 'ok' | 'err';
}

/** Message de retour après une action (enregistré, refusé). */
export function Notice({ children, tone = 'ok' }: NoticeProps) {
  return (
    <div className={`psc-notice psc-notice-${tone}`} role={tone === 'err' ? 'alert' : 'status'}>
      {children}
    </div>
  );
}

export interface ConfirmBoxProps {
  title: string;
  text?: string;
  /** Bouton(s) d'action. */
  children?: ReactNode;
}

/** Encart de confirmation à liseré abricot, sous le calendrier. */
export function ConfirmBox({ title, text, children }: ConfirmBoxProps) {
  return (
    <div className="psc-portal-confirm">
      <p className="psc-portal-confirm-title">{title}</p>
      {text && <p className="psc-portal-confirm-text">{text}</p>}
      {children}
    </div>
  );
}

export interface ModalProps {
  title: string;
  children: ReactNode;
  /** Boutons alignés à droite en pied de fenêtre. */
  actions?: ReactNode;
  /**
   * `overlay` (défaut) : voile plein écran fixe. `inline` : la fenêtre seule,
   * dans le flux (aperçus, maquettes).
   */
  mode?: 'overlay' | 'inline';
}

/** Fenêtre modale du portail (annulation, signalement d'absence). */
export function Modal({ title, children, actions, mode = 'overlay' }: ModalProps) {
  const dialog = (
    <div className="psc-portal-modal" role="dialog" aria-modal={mode === 'overlay'} aria-label={title}>
      <p className="psc-portal-modal-title">{title}</p>
      {children}
      {actions && <div className="psc-portal-modal-actions">{actions}</div>}
    </div>
  );
  return mode === 'inline' ? dialog : <div className="psc-portal-modal-overlay">{dialog}</div>;
}

export interface ImpersonationBannerProps {
  /** Nom de la famille consultée. */
  family: string;
  /** Heure de fin automatique de la consultation (ex. « 15:40 »). */
  until: string;
}

/** Bandeau de consultation d'un compte famille par la mairie (lecture seule), collé en haut. */
export function ImpersonationBanner({ family, until }: ImpersonationBannerProps) {
  return (
    <div className="psc-impersonation-banner" role="region" aria-label="Mode consultation mairie">
      <div className="psc-impersonation-banner-text">
        <span className="psc-impersonation-banner-icon" aria-hidden="true">👁</span>
        <strong>Lecture seule</strong>
        <span>Vous consultez l’espace de la famille {family} en lecture seule. Fin automatique à {until}.</span>
      </div>
      <button type="button" className="psc-impersonation-stop">Quitter la consultation</button>
    </div>
  );
}
