import { CheckLine } from 'sidiscm-ds';

export const Reglement = () => (
  <div style={{ padding: 16, maxWidth: 560, display: 'grid', gap: 12 }}>
    <CheckLine defaultChecked>J’ai lu et j’accepte le règlement intérieur du service périscolaire.</CheckLine>
    <CheckLine>J’autorise la mairie à prélever les factures mensuelles sur le compte indiqué (mandat SEPA).</CheckLine>
  </div>
);
