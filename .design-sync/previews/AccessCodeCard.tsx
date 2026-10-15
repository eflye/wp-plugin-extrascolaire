import { AccessCodeCard } from 'sidiscm-ds';

export const Saisie = () => <div style={{ padding: 24, display: 'flex', justifyContent: 'center' }}><AccessCodeCard /></div>;
export const CodeRefuse = () => <div style={{ padding: 24, display: 'flex', justifyContent: 'center' }}><AccessCodeCard error="Code incorrect. Vérifiez auprès de la mairie." /></div>;
