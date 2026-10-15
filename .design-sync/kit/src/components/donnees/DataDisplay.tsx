import type { ReactNode } from 'react';
import { cx } from '../util';

export interface StatCardProps {
  label: string;
  value?: ReactNode;
  sub?: ReactNode;
  /**
   * Carte d'accès rapide abricot (bordure basse 3px) : la seule carte
   * actionnable du tableau de bord ; y placer des `Button` `ink` / `outline-ink`.
   */
  gold?: boolean;
  children?: ReactNode;
}

/** Carte de chiffre clé du tableau de bord (fond blanc, filet, aucun relief). */
export function StatCard({ label, value, sub, gold = false, children }: StatCardProps) {
  return (
    <div className={cx('psc-portal-card', gold && 'psc-portal-card--gold')}>
      <div className="psc-portal-card-label">{label}</div>
      {value !== undefined && <div className="psc-portal-card-value">{value}</div>}
      {sub && <div className="psc-portal-card-sub">{sub}</div>}
      {children}
    </div>
  );
}

export interface StatGridProps {
  children: ReactNode;
}

/** Grille de 3 `StatCard` du tableau de bord. */
export function StatGrid({ children }: StatGridProps) {
  return <div className="psc-portal-cards">{children}</div>;
}

export interface ChildCardProps {
  name: string;
  /** Classe et année, en capitales (ex. « CE1 · 2026-2027 »). */
  meta?: string;
  /** Résumé des réservations à venir. */
  summary?: string;
}

/** Carte enfant du tableau de bord. */
export function ChildCard({ name, meta, summary }: ChildCardProps) {
  return (
    <div className="psc-portal-dash-child-card">
      <div className="psc-portal-dash-child-name">{name}</div>
      {meta && <div className="psc-portal-dash-child-meta">{meta}</div>}
      {summary && <div className="psc-portal-dash-child-summary">{summary}</div>}
    </div>
  );
}

export interface PillProps {
  children: ReactNode;
  /** `default` papier/encre, `pending` abricot pâle (à traiter), `muted` texte pierre seul. */
  tone?: 'default' | 'pending' | 'muted';
}

/** Étiquette d'état dans un tableau (facture payée, assurance en attente…). */
export function Pill({ children, tone = 'default' }: PillProps) {
  return <span className={cx('psc-portal-pill', tone !== 'default' && `psc-portal-pill--${tone}`)}>{children}</span>;
}

export type ConversationStatusValue = 'recue' | 'attente' | 'lue' | 'close';

const STATUS_LABEL: Record<ConversationStatusValue, string> = {
  recue: 'Réponse reçue',
  attente: 'En attente',
  lue: 'Lu par la mairie',
  close: 'Clos',
};

export interface ConversationStatusProps {
  /** `recue` est le seul statut en aplat (à repérer d'un coup d'œil) ; les autres en contour. */
  status: ConversationStatusValue;
  /** Remplace le libellé par défaut. */
  label?: string;
}

/** Badge de statut d'un échange avec la mairie. */
export function ConversationStatus({ status, label }: ConversationStatusProps) {
  return <span className={cx('psc-conv-status', `psc-conv-status-${status}`)}>{label ?? STATUS_LABEL[status]}</span>;
}

export interface CategoryTagProps {
  children: ReactNode;
  /** Couleurs de la catégorie (fond, texte) ; encre sur gris-bleu par défaut. */
  background?: string;
  color?: string;
}

/** Étiquette de catégorie d'un message (Cantine, Garderie, Facturation…). */
export function CategoryTag({ children, background, color }: CategoryTagProps) {
  const style = { ...(background ? { ['--cat-bg' as string]: background } : {}), ...(color ? { ['--cat-fg' as string]: color } : {}) };
  return <span className="psc-cat" style={style}>{children}</span>;
}

export interface DataTableColumn {
  key: string;
  label: string;
}

export interface DataTableProps {
  columns: DataTableColumn[];
  rows: Array<Record<string, ReactNode>>;
  /** Légende accessible du tableau (masquée visuellement). */
  caption: string;
}

/** Tableau du portail (Mes enfants, Mes factures) : en-têtes en capitales, filets fins. */
export function DataTable({ columns, rows, caption }: DataTableProps) {
  return (
    <div className="psc-portal-table-scroll">
      <table className="psc-portal-table">
        <caption className="psc-sr-only">{caption}</caption>
        <thead>
          <tr>
            {columns.map((c) => <th key={c.key} scope="col">{c.label}</th>)}
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i}>
              {columns.map((c) => <td key={c.key}>{r[c.key]}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export interface MenuDay {
  day: string;
  /** Plats du jour ; vide = pas de service. */
  dishes: string;
}

export interface WeekMenuProps {
  /** Libellé de la semaine (ex. « Semaine du 12 au 16 octobre »). */
  week: string;
  days: MenuDay[];
  /** Affiche le lien « Revenir à cette semaine ». */
  showToday?: boolean;
}

/** Menu de cantine de la semaine, avec navigation ←/→. */
export function WeekMenu({ week, days, showToday = false }: WeekMenuProps) {
  return (
    <div>
      <div className="psc-portal-menu-nav">
        <button type="button" className="psc-portal-menu-nav-btn" aria-label="Semaine précédente">←</button>
        <div className="psc-portal-menu-week">
          <p className="psc-portal-menu-week-label">{week}</p>
          {showToday && <button type="button" className="psc-portal-menu-today-link">Revenir à cette semaine</button>}
        </div>
        <button type="button" className="psc-portal-menu-nav-btn" aria-label="Semaine suivante">→</button>
      </div>
      {days.length === 0 ? (
        <p className="psc-portal-menu-empty">Le menu de cette semaine n’est pas encore publié.</p>
      ) : (
        <table className="psc-portal-menu-table">
          <caption className="psc-sr-only">Menu de cantine — {week}</caption>
          <tbody>
            {days.map((d) => (
              <tr key={d.day}>
                <th scope="row">{d.day}</th>
                <td>{d.dishes}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

export interface PanelProps {
  title?: string;
  children: ReactNode;
  /** Retire le plafond de 640px (panneau contenant un tableau). */
  wide?: boolean;
}

/** Encart papier froid pour un formulaire d'ajout ou d'édition. */
export function Panel({ title, children, wide = false }: PanelProps) {
  return (
    <section className={cx('psc-portal-panel', wide && 'psc-portal-panel--wide')}>
      {title && <h3 className="psc-portal-panel-title">{title}</h3>}
      {children}
    </section>
  );
}
