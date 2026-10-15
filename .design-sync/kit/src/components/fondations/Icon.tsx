import type { ReactElement } from 'react';

export type IconName =
  | 'home'
  | 'calendar'
  | 'menu'
  | 'mail'
  | 'child'
  | 'shield'
  | 'receipt'
  | 'profile'
  | 'document'
  | 'renew';

const PATHS: Record<IconName, ReactElement> = {
  home: <><path d="M3 11.5 12 4l9 7.5" /><path d="M5.5 10v9h13v-9" /><path d="M10 19v-6h4v6" /></>,
  calendar: <><rect x="3.5" y="5" width="17" height="15" rx="1" /><path d="M3.5 9.5h17" /><path d="M8 3v3M16 3v3" /></>,
  menu: <><path d="M6 3v8a2 2 0 0 0 4 0V3M6 6h4" /><path d="M14 3c-1.2 1.3-1.2 6 0 8M14 3v18M17 3v6a2 2 0 0 0 2 2v10" /></>,
  mail: <><rect x="3" y="5" width="18" height="14" rx="1" /><path d="m3.5 6 8.5 6.5L20.5 6" /></>,
  child: <><circle cx="12" cy="7" r="3.2" /><path d="M5 20c0-3.5 3.2-6 7-6s7 2.5 7 6" /></>,
  shield: <><path d="M12 3l7 3v5c0 4.6-3 7.6-7 9-4-1.4-7-4.4-7-9V6l7-3Z" /><path d="M9 11.5l2 2 4-4" /></>,
  receipt: <><path d="M6 3h12v18l-3-2-3 2-3-2-3 2Z" /><path d="M9 8h6M9 12h6M9 16h4" /></>,
  profile: <><circle cx="12" cy="8" r="3.5" /><path d="M5 20c0-4 3.1-7 7-7s7 3 7 7" /></>,
  document: <><path d="M7 3h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" /><path d="M14 3v5h5" /><path d="M9 13h6M9 17h6" /></>,
  renew: <><path d="M4 12a8 8 0 1 1 2.34 5.66" /><path d="M4 21v-5h5" /></>,
};

export interface IconProps {
  /** Pictogramme de la navigation du portail. */
  name: IconName;
  /** Taille en px (18 par défaut, celle de la barre latérale). */
  size?: number;
}

/** Pictogrammes au trait 1,6px du portail, en `currentColor`. */
export function Icon({ name, size = 18 }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.6} aria-hidden="true">
      {PATHS[name]}
    </svg>
  );
}
