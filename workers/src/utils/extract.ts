/**
 * Email + phone extraction agnostic du HTML brut.
 * Cf. spec/06_email_finder_validation.md § extraction exhaustive.
 */
const EMAIL_RE = /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/gi;
const PHONE_RE = /(?:\+?33|0)[\s.-]?[1-9](?:[\s.-]?\d{2}){4}/g;

export function extractEmails(text: string): string[] {
  const set = new Set<string>();
  for (const m of text.match(EMAIL_RE) ?? []) {
    set.add(m.toLowerCase());
  }
  return Array.from(set);
}

export function extractPhones(text: string): string[] {
  const set = new Set<string>();
  for (const m of text.match(PHONE_RE) ?? []) {
    set.add(m.replace(/[\s.-]/g, ''));
  }
  return Array.from(set);
}
