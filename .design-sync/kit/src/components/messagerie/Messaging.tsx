import type { ReactNode } from 'react';
import { cx } from '../util';
import { ConversationStatus, type ConversationStatusValue } from '../donnees/DataDisplay';

export interface ConversationSummary {
  subject: string;
  date: string;
  /** Auteur du dernier message (« Mairie », « Vous »). */
  author: string;
  excerpt: string;
  status: ConversationStatusValue;
  /** Objet de l'échange (Cantine, Facturation…). */
  topic?: string;
  unread?: boolean;
}

export interface ConversationListProps {
  conversations: ConversationSummary[];
}

/** Liste « Mes échanges » : liseré abricot et fond chaud sur les non-lus. */
export function ConversationList({ conversations }: ConversationListProps) {
  if (conversations.length === 0) {
    return (
      <div className="psc-conv-empty">
        <h3>Aucun échange pour l’instant</h3>
        <p>Une question sur la cantine, la garderie ou une facture ? Écrivez à la mairie, la réponse arrivera ici.</p>
      </div>
    );
  }
  return (
    <div className="psc-conv-list">
      {conversations.map((c) => (
        <a key={c.subject} href="#" className={cx('psc-conv-row', c.unread && 'is-unread')}>
          <span className="psc-conv-rail" />
          <span className="psc-conv-row-body">
            <span className="psc-conv-row-line1">
              <span className="psc-conv-subject">{c.subject}</span>
              <ConversationStatus status={c.status} />
              <span className="psc-conv-date">{c.date}</span>
            </span>
            <span className="psc-conv-row-line2">
              <span className="psc-conv-author">{c.author}</span>
              <span className="psc-conv-excerpt">{c.excerpt}</span>
            </span>
            {c.topic && (
              <span className="psc-conv-row-line3">
                <span className="psc-conv-objet">{c.topic}</span>
              </span>
            )}
          </span>
        </a>
      ))}
    </div>
  );
}

export interface ThreadMessage {
  from: 'famille' | 'mairie';
  author: string;
  date: string;
  body: string;
  /** Nom d'une pièce jointe. */
  attachment?: string;
}

export interface ConversationThreadProps {
  subject: string;
  status: ConversationStatusValue;
  /** Métadonnées à droite du bandeau (ouvert le…, objet). */
  meta?: ReactNode;
  messages: ThreadMessage[];
  /** Échange clos : remplace la zone de réponse par une note. */
  closed?: boolean;
}

/**
 * Fil d'un échange : bandeau encre plein (zone d'autorité), bulles famille
 * à droite (papier, liseré encre), bulles mairie à gauche (blanc, filet),
 * zone de réponse en bas.
 */
export function ConversationThread({ subject, status, meta, messages, closed = false }: ConversationThreadProps) {
  return (
    <article className="psc-conv-thread-card">
      <header className="psc-conv-thread-head">
        <div className="psc-conv-thread-head-left">
          <div className="psc-conv-thread-badges"><ConversationStatus status={status} /></div>
          <h2 className="psc-conv-thread-subject">{subject}</h2>
        </div>
        {meta && <div className="psc-conv-thread-meta">{meta}</div>}
      </header>
      <div className="psc-conv-thread-body">
        {messages.map((m, i) => (
          <div key={i} className={cx('psc-conv-bubble-wrap', m.from === 'famille' ? 'is-famille' : 'is-mairie')}>
            <div className="psc-conv-bubble-meta"><strong>{m.author}</strong> · {m.date}</div>
            <div className="psc-conv-bubble">{m.body}</div>
            {m.attachment && (
              <div className="psc-conv-attachment">
                <span>{m.attachment}</span>
                <a href="#">Télécharger</a>
              </div>
            )}
          </div>
        ))}
      </div>
      {closed ? (
        <p className="psc-conv-closed-note">Cet échange est clos.<a href="#">Écrire un nouveau message</a></p>
      ) : (
        <div className="psc-conv-reply-zone">
          <form onSubmit={(e) => e.preventDefault()}>
            <label htmlFor="psc-reply">Votre réponse</label>
            <textarea id="psc-reply" rows={4} />
            <div className="psc-conv-reply-actions">
              <span className="psc-conv-char-count">0 / 3000 caractères</span>
              <div className="psc-conv-reply-actions-right">
                <button type="submit" className="psc-btn-primary">Envoyer</button>
              </div>
            </div>
          </form>
        </div>
      )}
    </article>
  );
}

export interface InfoRailProps {
  children: ReactNode;
}

/** Bandeau informatif à liseré encre gauche sur fond blanc (délai de réponse…). */
export function InfoRail({ children }: InfoRailProps) {
  return (
    <div className="psc-conv-delay">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.6} aria-hidden="true">
        <circle cx="12" cy="12" r="8.5" /><path d="M12 7.5V12l3 2" />
      </svg>
      <span>{children}</span>
    </div>
  );
}
