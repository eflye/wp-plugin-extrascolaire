import { cx } from '../util';

export interface CalendarService {
  /** Code court affiché en en-tête (GM, CANT, GS). */
  code: string;
  /** Libellé complet, en infobulle. */
  label: string;
  /** Prix unitaire en euros. */
  price: number;
}

export interface CalendarDay {
  /** Libellé du jour (ex. « Lun. 12/10 »). */
  label: string;
  /** Codes des services réservés ce jour-là. */
  booked: string[];
  /** Délai de modification dépassé : cases désactivées, cadenas. */
  locked?: boolean;
  /** Jour fermé (férié, vacances) : aucune case. */
  closed?: boolean;
}

export interface ReservationCalendarProps {
  /** Prénom de l'enfant, pour la légende accessible. */
  childName: string;
  services: CalendarService[];
  days: CalendarDay[];
  /** Affiche la ligne « Tout / Retirer » sous les en-têtes. */
  showToutRow?: boolean;
}

const euros = (n: number) => n.toLocaleString('fr-FR', { minimumFractionDigits: 2 }) + ' €';

/**
 * Calendrier de réservation d'un enfant : une ligne par jour, une case
 * « tampon » 34×30 par service. Cochée = aplat encre et ✓ (l'état ne
 * dépend jamais de la seule couleur). Signature visuelle du portail.
 */
export function ReservationCalendar({ childName, services, days, showToutRow = true }: ReservationCalendarProps) {
  return (
    <div className="psc-portal-table-scroll">
      <table className="psc-portal-calendar">
        <caption className="psc-sr-only">Calendrier périscolaire de {childName}</caption>
        <thead>
          <tr>
            <th scope="col">Jour</th>
            {services.map((s) => (
              <th key={s.code} scope="col">
                <abbr className="psc-portal-th-abbr" title={s.label}>{s.code}</abbr>
                <small>{euros(s.price)}</small>
              </th>
            ))}
          </tr>
          {showToutRow && (
            <tr className="psc-portal-tout-row">
              <th scope="col" />
              {services.map((s) => {
                const open = days.filter((d) => !d.locked && !d.closed);
                const all = open.length > 0 && open.every((d) => d.booked.includes(s.code));
                return (
                  <th key={s.code} scope="col">
                    <button type="button" className={cx('psc-tout-btn', all && 'psc-tout-btn-all')} disabled={open.length === 0}>
                      {all ? 'Retirer' : 'Tout'}
                    </button>
                  </th>
                );
              })}
            </tr>
          )}
        </thead>
        <tbody>
          {days.map((d) => (
            <tr key={d.label} className={d.locked ? 'psc-row-locked' : undefined}>
              <th scope="row" className="psc-daylabel">
                {d.label}
                {d.locked && <span className="psc-lock" title="Délai de modification dépassé" aria-label="Délai de modification dépassé">🔒</span>}
              </th>
              {services.map((s) => (
                <td key={s.code}>
                  {d.closed ? (
                    <span className="psc-portal-muted" aria-label="Fermé">—</span>
                  ) : (
                    <input
                      type="checkbox"
                      className="psc-check"
                      defaultChecked={d.booked.includes(s.code)}
                      disabled={d.locked}
                      aria-label={`${s.label} — ${d.label}`}
                    />
                  )}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export interface ChildBlockProps {
  name: string;
  classe?: string;
  /** Total du mois (ex. « 48,60 € ce mois-ci »). */
  total?: string;
  children: import('react').ReactNode;
}

/** Bloc d'un enfant dans le planning : nom, classe, total du mois, puis son calendrier. */
export function ChildBlock({ name, classe, total, children }: ChildBlockProps) {
  return (
    <section className="psc-portal-child-block">
      <div className="psc-portal-child-header">
        <h2>
          {name} {classe && <span className="psc-portal-child-classe">{classe}</span>}
        </h2>
        {total && <span className="psc-portal-child-total">{total}</span>}
      </div>
      {children}
    </section>
  );
}
