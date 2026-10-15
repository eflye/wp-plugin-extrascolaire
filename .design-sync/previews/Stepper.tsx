import { Stepper } from 'sidiscm-ds';

const steps = ['Coordonnées', 'Enfants', 'Paiement', 'Règlement'];

export const Debut = () => <div style={{ padding: 16, maxWidth: 680 }}><Stepper steps={steps} current={0} /></div>;
export const Paiement = () => <div style={{ padding: 16, maxWidth: 680 }}><Stepper steps={steps} current={2} /></div>;
export const Dernier = () => <div style={{ padding: 16, maxWidth: 680 }}><Stepper steps={steps} current={3} /></div>;
