import { Panel, FieldGrid, TextField, Button } from 'sidiscm-ds';

export const AjouterEnfant = () => (
  <div style={{ padding: 16 }}>
    <Panel title="Ajouter un enfant">
      <FieldGrid>
        <TextField label="Prénom" defaultValue="Hugo" />
        <TextField label="Nom" defaultValue="Martin" />
        <TextField label="Date de naissance" type="date" defaultValue="2021-03-14" />
        <TextField label="Classe" options={['PS', 'MS', 'GS', 'CP', 'CE1']} defaultValue="GS" />
      </FieldGrid>
      <Button variant="primary">Ajouter</Button>
    </Panel>
  </div>
);
