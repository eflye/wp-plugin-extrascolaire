import { ConfirmBox, Button } from 'sidiscm-ds';

export const Planning = () => (
  <div style={{ padding: 16, maxWidth: 620 }}>
    <ConfirmBox
      title="Confirmer mon planning"
      text="Vos déclarations sont déjà enregistrées. Ce bouton vous envoie par e-mail un récapitulatif complet de l’année scolaire, à conserver comme preuve de votre saisie."
    >
      <Button variant="primary">Valider et recevoir mon planning</Button>
    </ConfirmBox>
  </div>
);
