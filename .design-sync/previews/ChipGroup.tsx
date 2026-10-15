import { Chip, ChipGroup } from 'sidiscm-ds';

export const ObjetDuMessage = () => (
  <div style={{ padding: 16, maxWidth: 560 }}>
    <ChipGroup label="Objet de votre message">
      <Chip name="objet" value="cantine" defaultChecked>Cantine</Chip>
      <Chip name="objet" value="garderie">Garderie</Chip>
      <Chip name="objet" value="facture">Facturation</Chip>
      <Chip name="objet" value="autre">Autre question</Chip>
    </ChipGroup>
  </div>
);
