import { ChildCard } from 'sidiscm-ds';

export const Enfants = () => (
  <div className="psc-portal-dash-children" style={{ padding: 16 }}>
    <ChildCard name="Léa Martin" meta="CE1 · 2026-2027" summary="Cantine 4 j/sem · garderie du soir mardi et jeudi" />
    <ChildCard name="Hugo Martin" meta="GS · 2026-2027" summary="Cantine sans repas · garderie du matin" />
  </div>
);
