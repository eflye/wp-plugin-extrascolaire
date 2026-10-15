import { WeekMenu } from 'sidiscm-ds';

export const Publie = () => (
  <div style={{ padding: 16, maxWidth: 720 }}>
    <WeekMenu
      week="Semaine du 12 au 16 octobre"
      showToday
      days={[
        { day: 'Lundi', dishes: 'Carottes râpées · Poulet basquaise, riz · Yaourt nature · Pomme' },
        { day: 'Mardi', dishes: 'Potage de légumes · Lasagnes végétariennes · Comté · Poire' },
        { day: 'Jeudi', dishes: 'Salade de lentilles · Poisson pané, haricots verts · Fromage blanc' },
        { day: 'Vendredi', dishes: 'Betteraves · Rôti de dinde, purée · Compote' },
      ]}
    />
  </div>
);

export const NonPublie = () => (
  <div style={{ padding: 16, maxWidth: 720 }}>
    <WeekMenu week="Semaine du 19 au 23 octobre" days={[]} />
  </div>
);
