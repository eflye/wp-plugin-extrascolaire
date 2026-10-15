import { ReservationCalendar } from 'sidiscm-ds';

const services = [
  { code: 'GM', label: 'Garderie du matin', price: 2.1 },
  { code: 'CANT', label: 'Cantine', price: 4.35 },
  { code: 'GS', label: 'Garderie du soir', price: 3.2 },
];

export const Semaine = () => (
  <div style={{ padding: 16, maxWidth: 560 }}>
    <ReservationCalendar
      childName="Léa"
      services={services}
      days={[
        { label: 'Lun. 12/10', booked: ['GM', 'CANT'], locked: true },
        { label: 'Mar. 13/10', booked: ['CANT', 'GS'] },
        { label: 'Jeu. 15/10', booked: ['GM', 'CANT', 'GS'] },
        { label: 'Ven. 16/10', booked: ['CANT'] },
      ]}
    />
  </div>
);

export const JourFerie = () => (
  <div style={{ padding: 16, maxWidth: 560 }}>
    <ReservationCalendar
      childName="Hugo"
      services={services}
      showToutRow={false}
      days={[
        { label: 'Lun. 9/11', booked: ['CANT'] },
        { label: 'Mar. 10/11', booked: ['CANT'] },
        { label: 'Mer. 11/11', booked: [], closed: true },
        { label: 'Jeu. 12/11', booked: ['GS'] },
      ]}
    />
  </div>
);
