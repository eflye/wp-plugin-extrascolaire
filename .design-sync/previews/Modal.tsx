import { Modal, Button, TextField } from 'sidiscm-ds';

export const SignalerAbsence = () => (
  <div style={{ padding: 24, display: 'flex', justifyContent: 'center' }}>
    <Modal
      mode="inline"
      title="Signaler une absence"
      actions={<><Button variant="outline-ink">Fermer</Button><Button variant="primary">Confirmer l’absence</Button></>}
    >
      <TextField label="Enfant" options={['Léa Martin', 'Hugo Martin']} />
      <div className="psc-absence-items">
        <label className="psc-absence-item"><input type="checkbox" defaultChecked /> Lun. 19/10 — Cantine</label>
        <label className="psc-absence-item"><input type="checkbox" defaultChecked /> Lun. 19/10 — Garderie du soir</label>
        <label className="psc-absence-item"><input type="checkbox" /> Mar. 20/10 — Cantine</label>
      </div>
    </Modal>
  </div>
);
