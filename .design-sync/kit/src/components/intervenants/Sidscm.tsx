import type { ReactNode } from 'react';
import { cx } from '../util';

export interface AppHeaderProps {
  title: string;
  /** Date du jour, sous le titre. */
  today?: string;
  /** Modes de vue (ex. « Jour », « Semaine ») ; l'actif est en abricot. */
  modes?: string[];
  activeMode?: string;
  /** Affiche le bouton « Verrouiller ». */
  lockable?: boolean;
}

/** Bandeau encre de l'écran intervenants (SIDSCM) : titre, date, modes de vue. */
export function AppHeader({ title, today, modes = [], activeMode, lockable = true }: AppHeaderProps) {
  return (
    <header className="psc-sidscm-header">
      <div>
        <div className="psc-sidscm-eyebrow psc-sidscm-eyebrow--dark">SIDSCM · Listes</div>
        <div className="psc-sidscm-title">{title}</div>
        {today && <div className="psc-sidscm-today">{today}</div>}
      </div>
      <div className="psc-sidscm-header-actions">
        {modes.map((m) => (
          <button key={m} type="button" className={cx('psc-sidscm-mode-btn', m === activeMode && 'is-active')} aria-pressed={m === activeMode}>
            {m}
          </button>
        ))}
        {lockable && <button type="button" className="psc-sidscm-lock-btn">Verrouiller</button>}
      </div>
    </header>
  );
}

export interface DayPickerProps {
  days: string[];
  active?: string;
}

/** Rangée de jours sur fond papier ; le jour actif en aplat encre. */
export function DayPicker({ days, active }: DayPickerProps) {
  return (
    <div className="psc-sidscm-days">
      {days.map((d) => (
        <button key={d} type="button" className={cx('psc-sidscm-day-btn', d === active && 'is-active')} aria-pressed={d === active}>
          {d}
        </button>
      ))}
    </div>
  );
}

export interface ServiceTab {
  label: string;
  count: number;
}

export interface ServiceTabsProps {
  services: ServiceTab[];
  active?: string;
}

/** Sélecteur de service (garderie matin, cantine, soir) avec effectif du jour. */
export function ServiceTabs({ services, active }: ServiceTabsProps) {
  return (
    <div className="psc-sidscm-services">
      {services.map((s) => (
        <button key={s.label} type="button" className={cx('psc-sidscm-svc-btn', s.label === active && 'is-active')} aria-pressed={s.label === active}>
          {s.label}
          <span className="psc-sidscm-svc-count">{s.count}</span>
        </button>
      ))}
    </div>
  );
}

export interface AttendanceChild {
  name: string;
  classe: string;
  present?: boolean;
  /** Régime alimentaire (badge abricot), ex. « Sans porc ». */
  diet?: string;
  /** Statut « cantine sans repas » : l'enfant apporte son repas. */
  bringsMeal?: boolean;
  /** Signalement alimentaire à voir avec la mairie (texte rouge). */
  allergyNote?: string;
}

export interface AttendanceListProps {
  title: string;
  /** Enfants inscrits sur le créneau. */
  rows: AttendanceChild[];
  /** Contenu ajouté à l'en-tête (ex. bouton). */
  action?: ReactNode;
}

/**
 * Liste d'appel d'un service : une ligne par enfant, grande case à cocher
 * de présence (outil de terrain, tablette), badges régime et sans repas.
 */
export function AttendanceList({ title, rows, action }: AttendanceListProps) {
  const present = rows.filter((c) => c.present).length;
  return (
    <section className="psc-sidscm-panel">
      <div className="psc-sidscm-panel-head">
        <span className="psc-sidscm-panel-title">{title}</span>
        <span className="psc-sidscm-panel-count">{present} / {rows.length} {present > 1 ? 'présents' : 'présent'}</span>
        {action}
      </div>
      {rows.map((c) => (
        <div key={c.name} className="psc-sidscm-row">
          <label className="psc-sidscm-row-label">
            <input type="checkbox" className="psc-sidscm-row-check" defaultChecked={c.present} />
            <span>
              <span className="psc-sidscm-row-name">{c.name}</span>{' '}
              <span className="psc-sidscm-row-classe">{c.classe}</span>
            </span>
          </label>
          {c.allergyNote && <span className="psc-sidscm-row-allergy">{c.allergyNote}</span>}
          {c.bringsMeal && <span className="psc-sidscm-row-brings-meal">Midi sans repas — à ne pas compter dans les couverts</span>}
          {c.diet && <span className="psc-sidscm-row-diet">{c.diet}</span>}
        </div>
      ))}
    </section>
  );
}

export interface AccessCodeCardProps {
  /** Message d'erreur sous le champ (code refusé). */
  error?: string;
}

/** Carte de saisie du code d'accès de l'écran intervenants. */
export function AccessCodeCard({ error }: AccessCodeCardProps) {
  return (
    <div className="psc-sidscm-lock-card">
      <div className="psc-sidscm-eyebrow">Accès intervenants</div>
      <h2 className="psc-sidscm-lock-title">Listes du jour</h2>
      <p className="psc-sidscm-lock-intro">Saisissez le code d’accès communiqué par la mairie pour afficher les listes de présence.</p>
      <label className="psc-sr-only" htmlFor="psc-code">Code d’accès</label>
      <input id="psc-code" className="psc-sidscm-code-input" type="password" placeholder="Code d’accès" aria-invalid={error ? true : undefined} />
      {error && <p className="psc-sidscm-code-error" role="alert">{error}</p>}
      <button type="button" className="psc-sidscm-code-submit">Accéder aux listes</button>
    </div>
  );
}
