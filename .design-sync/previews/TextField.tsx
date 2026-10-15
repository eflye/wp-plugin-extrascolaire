import { TextField } from 'sidiscm-ds';

const box = { padding: 16, maxWidth: 320, display: 'grid', gap: 18 };

export const Saisie = () => (
  <div style={box}>
    <TextField label="Adresse e-mail" type="email" placeholder="prenom.nom@exemple.fr" />
    <TextField label="Téléphone" type="tel" defaultValue="06 12 34 56 78" />
  </div>
);

export const Liste = () => (
  <div style={box}>
    <TextField label="Classe" options={['PS', 'MS', 'GS', 'CP', 'CE1', 'CE2', 'CM1', 'CM2']} defaultValue="CE1" />
  </div>
);

export const Erreur = () => (
  <div style={box}>
    <TextField label="Date de naissance" type="date" defaultValue="2027-01-01" error="La date de naissance ne peut pas être dans le futur." />
  </div>
);
