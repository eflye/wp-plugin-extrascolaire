import { GuestCard, TextField, Button } from 'sidiscm-ds';

export const Connexion = () => (
  <div style={{ padding: 16, maxWidth: 720 }}>
    <GuestCard eyebrow="Familles déjà inscrites" title="Accéder à mon espace" intro="Recevez un lien de connexion par e-mail, sans mot de passe." hint="Pensez à vérifier vos courriers indésirables.">
      <div className="psc-guest-login-row">
        <div style={{ flex: 1, minWidth: 220 }}><TextField label="Adresse e-mail" type="email" placeholder="prenom.nom@exemple.fr" /></div>
        <Button variant="primary">Recevoir le lien</Button>
      </div>
    </GuestCard>
  </div>
);
