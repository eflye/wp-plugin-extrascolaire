import type { ChangeEventHandler, ReactNode } from 'react';
import { useId } from 'react';
import { cx } from '../util';

export interface TextFieldProps {
  label: string;
  type?: 'text' | 'email' | 'tel' | 'date' | 'password';
  value?: string;
  defaultValue?: string;
  placeholder?: string;
  /** Message d'erreur relié au champ (`aria-describedby`). */
  error?: string;
  /** Liste d'options : rend un `<select>` souligné. */
  options?: string[];
  onChange?: ChangeEventHandler<HTMLInputElement | HTMLSelectElement>;
}

/** Champ souligné du portail : libellé en capitales 9px, filet encre qui passe à l'abricot au focus. */
export function TextField({ label, type = 'text', value, defaultValue, placeholder, error, options, onChange }: TextFieldProps) {
  const id = useId();
  const errId = `${id}-err`;
  const common = {
    id,
    className: 'psc-portal-field-underline',
    'aria-invalid': error ? true : undefined,
    'aria-describedby': error ? errId : undefined,
    onChange,
  };
  return (
    <div>
      <label className="psc-portal-field-label" htmlFor={id}>{label}</label>
      {options ? (
        <select {...common} value={value} defaultValue={defaultValue}>
          {options.map((o) => <option key={o}>{o}</option>)}
        </select>
      ) : (
        <input {...common} type={type} value={value} defaultValue={defaultValue} placeholder={placeholder} />
      )}
      {error && <span id={errId} className="psc-error">{error}</span>}
    </div>
  );
}

export interface FieldGridProps {
  children: ReactNode;
}

/** Grille de champs sur deux colonnes (une seule sous 900px). */
export function FieldGrid({ children }: FieldGridProps) {
  return <div className="psc-portal-field-grid">{children}</div>;
}

export interface FileFieldProps {
  label: string;
  /** Nom du fichier choisi, affiché à côté du bouton. */
  fileName?: string;
  accept?: string;
}

/** Champ fichier : bouton contour assorti, input natif masqué mais accessible. */
export function FileField({ label, fileName, accept = '.pdf,.jpg,.jpeg,.png' }: FileFieldProps) {
  const id = useId();
  return (
    <div>
      <span className="psc-portal-field-label">{label}</span>
      <div className="psc-portal-file-field">
        <label htmlFor={id} className="psc-portal-file-field-btn">Choisir un fichier</label>
        <input id={id} type="file" accept={accept} className="psc-portal-file-field-input" />
        {fileName && <span className="psc-portal-file-field-name">{fileName}</span>}
      </div>
    </div>
  );
}

export interface CheckLineProps {
  children: ReactNode;
  checked?: boolean;
  defaultChecked?: boolean;
  onChange?: ChangeEventHandler<HTMLInputElement>;
}

/** Case à cocher suivie d'un texte sur plusieurs lignes (acceptation de règlement, mandat). */
export function CheckLine({ children, checked, defaultChecked, onChange }: CheckLineProps) {
  return (
    <label className="psc-wizard-check-line">
      <input type="checkbox" checked={checked} defaultChecked={defaultChecked} onChange={onChange} />
      <span>{children}</span>
    </label>
  );
}

export interface PaymentOption {
  value: string;
  title: string;
  sub: string;
}

export interface PaymentChoiceProps {
  options: PaymentOption[];
  selected?: string;
}

/** Cartes de choix exclusif (mode de paiement) : la sélection est teintée abricot. */
export function PaymentChoice({ options, selected }: PaymentChoiceProps) {
  return (
    <div className="psc-wizard-payment-cards" role="radiogroup">
      {options.map((o) => (
        <button key={o.value} type="button" role="radio" aria-checked={o.value === selected} className={cx('psc-wizard-payment-card', o.value === selected && 'is-active')}>
          <div className="psc-wizard-payment-card-title">{o.title}</div>
          <div className="psc-wizard-payment-card-sub">{o.sub}</div>
        </button>
      ))}
    </div>
  );
}

export interface GuestCardProps {
  eyebrow?: string;
  title: string;
  intro?: string;
  children?: ReactNode;
  hint?: string;
}

/** Carte blanche des pages invité (connexion, demande d'inscription). */
export function GuestCard({ eyebrow, title, intro, children, hint }: GuestCardProps) {
  return (
    <div className="psc-guest-card">
      {eyebrow && <p className="psc-guest-eyebrow">{eyebrow}</p>}
      <h1 className="psc-guest-h1">{title}</h1>
      {intro && <p className="psc-guest-intro">{intro}</p>}
      {children}
      {hint && <p className="psc-guest-hint">{hint}</p>}
    </div>
  );
}
