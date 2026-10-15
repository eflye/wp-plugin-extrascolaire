import { ChildBlock, ReservationCalendar } from 'sidiscm-ds';

export const Planning = () => (
  <div style={{ padding: 16, maxWidth: 620 }}>
    <ChildBlock name="Léa Martin" classe="CE1" total="48,60 € ce mois-ci">
      <ReservationCalendar
        childName="Léa"
        services={[{ code: 'GM', label: 'Garderie du matin', price: 2.1 }, { code: 'CANT', label: 'Cantine', price: 4.35 }, { code: 'GS', label: 'Garderie du soir', price: 3.2 }]}
        days={[{ label: 'Lun. 19/10', booked: ['CANT'] }, { label: 'Mar. 20/10', booked: ['CANT', 'GS'] }]}
      />
    </ChildBlock>
  </div>
);
