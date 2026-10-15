import { PaymentChoice } from 'sidiscm-ds';

const options = [
  { value: 'sepa', title: 'Prélèvement automatique', sub: 'Chaque mois, sans démarche (mandat SEPA).' },
  { value: 'autre', title: 'Autre moyen', sub: 'Chèque, espèces ou paiement en ligne au Trésor public.' },
];

export const Prelevement = () => <div style={{ padding: 16, maxWidth: 640 }}><PaymentChoice options={options} selected="sepa" /></div>;
export const AutreMoyen = () => <div style={{ padding: 16, maxWidth: 640 }}><PaymentChoice options={options} selected="autre" /></div>;
