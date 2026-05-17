/**
 * Helper utilitaire pour merger des classNames conditionnels Tailwind.
 * Plus léger que clsx + tailwind-merge (qu'on ne veut pas comme deps).
 */
export type ClassValue = string | number | null | false | undefined | ClassValue[];

export function cn(...inputs: ClassValue[]): string {
  const out: string[] = [];
  for (const i of inputs) {
    if (!i && i !== 0) continue;
    if (Array.isArray(i)) {
      const sub = cn(...i);
      if (sub) out.push(sub);
    } else {
      out.push(String(i));
    }
  }
  return out.join(' ').replace(/\s+/g, ' ').trim();
}
