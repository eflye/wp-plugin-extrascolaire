import { ConversationStatus } from 'sidiscm-ds';

export const Statuts = () => (
  <div style={{ display: 'flex', gap: 10, alignItems: 'center', padding: 16 }}>
    <ConversationStatus status="recue" />
    <ConversationStatus status="attente" />
    <ConversationStatus status="lue" />
    <ConversationStatus status="close" />
  </div>
);
