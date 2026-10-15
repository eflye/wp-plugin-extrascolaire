import { AppHeader } from 'sidiscm-ds';

export const Jour = () => <AppHeader title="Écoles de Montgeroult et Courcelles" today="jeudi 15 octobre 2026" modes={['Jour', 'Semaine']} activeMode="Jour" />;
export const SansVerrou = () => <AppHeader title="Listes de présence" today="lundi 12 octobre 2026" modes={['Jour', 'Semaine']} activeMode="Semaine" lockable={false} />;
