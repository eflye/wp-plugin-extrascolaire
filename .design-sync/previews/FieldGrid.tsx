import { FieldGrid, TextField } from 'sidiscm-ds';

export const Coordonnees = () => (
  <div style={{ padding: 16, maxWidth: 640 }}>
    <FieldGrid>
      <TextField label="Prénom" defaultValue="Claire" />
      <TextField label="Nom" defaultValue="Martin" />
      <TextField label="Adresse e-mail" type="email" defaultValue="claire.martin@exemple.fr" />
      <TextField label="Téléphone" type="tel" defaultValue="06 12 34 56 78" />
    </FieldGrid>
  </div>
);
