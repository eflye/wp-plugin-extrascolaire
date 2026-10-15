import { ConversationList } from 'sidiscm-ds';

export const Echanges = () => (
  <div style={{ padding: 16, maxWidth: 760 }}>
    <ConversationList
      conversations={[
        { subject: 'Repas de Léa le jeudi 22 octobre', date: '14/10', author: 'Mairie', excerpt: 'C’est noté : le repas du 22 octobre est retiré et ne sera pas facturé.', status: 'recue', topic: 'Cantine', unread: true },
        { subject: 'Facture de septembre', date: '08/10', author: 'Vous', excerpt: 'Bonjour, je crois qu’une garderie du soir a été comptée deux fois.', status: 'attente', topic: 'Facturation' },
        { subject: 'Attestation d’assurance 2026-2027', date: '03/09', author: 'Mairie', excerpt: 'Merci, l’attestation est validée.', status: 'close', topic: 'Documents' },
      ]}
    />
  </div>
);

export const Vide = () => (
  <div style={{ padding: 16, maxWidth: 760 }}>
    <ConversationList conversations={[]} />
  </div>
);
