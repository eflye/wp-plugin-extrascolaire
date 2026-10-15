import { ConversationThread } from 'sidiscm-ds';

export const EnCours = () => (
  <div style={{ padding: 16, maxWidth: 720 }}>
    <ConversationThread
      subject="Repas de Léa le jeudi 22 octobre"
      status="recue"
      meta={<>Ouvert le 14/10<br />Objet : Cantine</>}
      messages={[
        { from: 'famille', author: 'Vous', date: '14/10 à 08:12', body: 'Bonjour, Léa aura un rendez-vous médical jeudi midi. Peut-on retirer son repas sans frais ?' },
        { from: 'mairie', author: 'Mairie de Montgeroult', date: '14/10 à 10:47', body: 'Bonjour, c’est noté : le repas du 22 octobre est retiré et ne sera pas facturé. Bonne journée.', attachment: 'Planning-octobre-Lea.pdf' },
      ]}
    />
  </div>
);

export const Clos = () => (
  <div style={{ padding: 16, maxWidth: 720 }}>
    <ConversationThread
      subject="Attestation d’assurance 2026-2027"
      status="close"
      closed
      meta={<>Ouvert le 02/09</>}
      messages={[
        { from: 'famille', author: 'Vous', date: '02/09 à 19:30', body: 'Voici l’attestation d’assurance scolaire de Hugo pour cette année.', attachment: 'attestation-hugo.pdf' },
        { from: 'mairie', author: 'Mairie de Montgeroult', date: '03/09 à 09:05', body: 'Merci, l’attestation est validée.' },
      ]}
    />
  </div>
);
