import { AttendanceList } from 'sidiscm-ds';

export const Cantine = () => (
  <div style={{ padding: 16, maxWidth: 760 }}>
    <AttendanceList
      title="Cantine — CE1 / CE2"
      rows={[
        { name: 'Léa Martin', classe: 'CE1', present: true, diet: 'Sans porc' },
        { name: 'Hugo Bernard', classe: 'CE2', present: true, bringsMeal: true },
        { name: 'Inès Petit', classe: 'CE1', present: false },
        { name: 'Noah Robert', classe: 'CE2', present: true, allergyNote: 'Signalement alimentaire : voir la mairie (PAI)' },
      ]}
    />
  </div>
);

export const GarderieSoir = () => (
  <div style={{ padding: 16, maxWidth: 760 }}>
    <AttendanceList title="Garderie du soir" rows={[{ name: 'Jade Durand', classe: 'CP', present: true }, { name: 'Tom Moreau', classe: 'GS', present: true }]} />
  </div>
);
