/**
 * Lecture des e-mails de test via l'API Mailpit — jamais via l'UI/iframe
 * de prévisualisation. Partagé entre tests/parent-connu.spec.ts et
 * tests/supplier-order.spec.ts.
 */

const MAILPIT_API = 'http://localhost:8025/api/v1';

export interface MailpitSummary {
  ID: string;
  Created: string;
  Subject: string;
}

export interface MailpitFull {
  Text: string;
  HTML: string;
  Subject: string;
}

export async function findLatestMessage(
  toEmail: string,
  subjectContains: string,
  timeoutMs = 10_000
): Promise<MailpitFull> {
  const query = `to:${toEmail} subject:"${subjectContains}"`;
  const deadline = Date.now() + timeoutMs;

  while (Date.now() < deadline) {
    const res = await fetch(`${MAILPIT_API}/search?${new URLSearchParams({ query, limit: '5' })}`);
    const data = (await res.json()) as { messages: MailpitSummary[] };
    if (data.messages.length > 0) {
      const latest = data.messages.reduce((a, b) => (a.Created > b.Created ? a : b));
      const full = await fetch(`${MAILPIT_API}/message/${latest.ID}`);
      return (await full.json()) as MailpitFull;
    }
    await new Promise((r) => setTimeout(r, 400));
  }
  throw new Error(`Mailpit : aucun message pour ${toEmail} contenant "${subjectContains}" sous ${timeoutMs}ms.`);
}
