/**
 * NR-21 — l'instantané de `routeTree` : les adresses d'écran d'aujourd'hui
 * existent toujours (ou redirigent).
 *
 * Lecture STATIQUE du code (`src/app/routeTree.tsx`), sans rendu. La liste
 * ci-dessous est la photo des 55 chemins littéraux déclarés à la date du filet
 * (le relevé du plan 21 en annonçait 54 : l'écart vient du comptage, pas d'une
 * route manquante). Retirer une adresse fait rougir ; en AJOUTER est permis.
 * Une adresse qui devient une redirection reste une adresse déclarée.
 *
 * Renommer « campagnes de collecte » en « collectes » (lot DM0-COL) doit garder
 * les anciennes adresses : alias ou redirection.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const SOURCE = readFileSync(resolve(__dirname, '../../src/app/routeTree.tsx'), 'utf8').replace(/\/\/.*$/gm, '');

const ADRESSES_ATTENDUES = [
  '/', '/2fa', '/admin/observability', '/analytics', '/audiences', '/audiences/$audienceId', '/audiences/new',
  '/audit-logs', '/campaigns', '/campaigns/$campaignId', '/campaigns/new', '/cold-email', '/collectes', '/collectes/$collecteId', '/collectes/new', '/companies',
  '/companies/$companyId', '/console/arbitrage', '/console/contacts', '/console/lettre-et-guide',
  '/console/lettre-et-guide/$personneId', '/console/personnes/$personKey', '/console/propositions',
  '/console/vivier', '/contacts', '/coverage', '/crm', '/doublons', '/evenements', '/evenements/$eventId',
  '/federations', '/federations/$companyId', '/international/roumanie', '/journalists',
  '/journalists/$journalistId', '/linkedin', '/listes', '/listes/$listeId', '/llm/proxy-providers',
  '/llm/rotations', '/llm/router', '/login', '/magic-link', '/magic-link/verify', '/media', '/media/$mediaId',
  '/pas-encore-livre', '/password-reset', '/presse/envois', '/rgpd/ai-act', '/rgpd/requests', '/scraper-runs',
  '/settings', '/tags', '/users',
];

function declarees(): Set<string> {
  return new Set([...SOURCE.matchAll(/path:\s*'([^']+)'/g)].map((m) => m[1]!));
}

describe('NR-21 : les adresses d’écran existent toujours', () => {
  it('la lecture voit bien les routes (témoin de couverture)', () => {
    expect(declarees().size).toBeGreaterThanOrEqual(52);
  });

  it('chaque adresse de la photo est toujours déclarée', () => {
    const presentes = declarees();
    const disparues = ADRESSES_ATTENDUES.filter((a) => !presentes.has(a));
    expect(disparues).toEqual([]);
  });

  it('les anciennes adresses de collecte « /campaigns » restent déclarées (renommage : alias ou redirection)', () => {
    const presentes = declarees();
    for (const a of ['/campaigns', '/campaigns/new', '/campaigns/$campaignId']) {
      expect(presentes.has(a)).toBe(true);
    }
  });

  it('les pages d’attente redirigent au lieu de laisser un lien mort', () => {
    expect(SOURCE).toContain('redirect(');
  });
});
