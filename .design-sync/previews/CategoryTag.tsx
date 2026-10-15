import { CategoryTag } from 'sidiscm-ds';

export const Categories = () => (
  <div style={{ display: 'flex', gap: 8, padding: 16 }}>
    <CategoryTag>Information</CategoryTag>
    <CategoryTag background="#E7EEE8" color="#33553C">Cantine</CategoryTag>
    <CategoryTag background="#F6E7E7" color="#9E4A4A">Urgent</CategoryTag>
    <CategoryTag background="#FBEBDD" color="#8A4B25">Fermeture</CategoryTag>
  </div>
);
