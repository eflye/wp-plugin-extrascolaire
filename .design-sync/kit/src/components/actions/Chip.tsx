import type { ChangeEventHandler, ReactNode } from 'react';

export interface ChipProps {
  children: ReactNode;
  /** Nom du groupe radio : les puces d'un même groupe s'excluent. */
  name: string;
  value: string;
  checked?: boolean;
  defaultChecked?: boolean;
  onChange?: ChangeEventHandler<HTMLInputElement>;
}

/**
 * Puce de choix (filtre, catégorie de message) : radio natif masqué,
 * état actif en aplat encre. À regrouper dans `ChipGroup`.
 */
export function Chip({ children, name, value, checked, defaultChecked, onChange }: ChipProps) {
  return (
    <label className="psc-chip">
      <input type="radio" name={name} value={value} checked={checked} defaultChecked={defaultChecked} onChange={onChange} />
      <span>{children}</span>
    </label>
  );
}

export interface ChipGroupProps {
  children: ReactNode;
  /** Libellé visible au-dessus du groupe (capitales bleu doux). */
  label?: string;
}

/** Rangée de puces avec son libellé de champ. */
export function ChipGroup({ children, label }: ChipGroupProps) {
  return (
    <div role="radiogroup" aria-label={label}>
      {label && <span className="psc-conv-field-label">{label}</span>}
      <div className="psc-conv-chip-group">{children}</div>
    </div>
  );
}
