import { Notice } from 'sidiscm-ds';

export const Succes = () => <div style={{ padding: 16, maxWidth: 560 }}><Notice>Enfant ajouté à votre compte.</Notice></div>;
export const Erreur = () => <div style={{ padding: 16, maxWidth: 560 }}><Notice tone="err">Le fichier dépasse la taille maximale autorisée (1 Mo).</Notice></div>;
