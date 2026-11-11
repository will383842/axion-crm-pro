/**
 * L8-F02 — la démo d'Envoi ne renvoie jamais de champ d'ouverture, ni le champ
 * `automatique_probable` (retiré du contrat par L8-F01), quelle que soit la route.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { server } from '../../msw/server';
import { handlers as campagnes } from '@/demo/handlers/envoi/campagnes.handlers';
import { handlers as pilotage } from '@/demo/handlers/envoi/pilotage.handlers';
import { handlers as infrastructure } from '@/demo/handlers/envoi/infrastructure.handlers';
import { handlers as conformite } from '@/demo/handlers/envoi/conformite.handlers';
import { handlers as creation } from '@/demo/handlers/envoi/creation.handlers';
import { demoDb } from '@/demo/base';

const MOTIF = /ouverture|automatique_probable|raison_automatique|pixel/i;
const lire = async (chemin: string): Promise<string> => (await fetch(`http://localhost/api/v1${chemin}`)).text();

beforeEach(() => {
  demoDb.reinitialiser();
  server.use(...campagnes, ...pilotage, ...infrastructure, ...conformite, ...creation);
});

describe('démo d’Envoi : aucune ouverture dans les réponses', () => {
  it('résultats, santé, feu vert, recherche d’un envoi', async () => {
    const adresse = encodeURIComponent(demoDb.evenementsEnvoi.tous()[0]!.adresse);
    const textes = [
      ...(await Promise.all(['boite', 'domaine', 'liste', 'message'].map((p) => lire(`/envoi/resultats?par=${p}`)))),
      await lire('/envoi/resultats/courbe'),
      await lire('/envoi/sante'),
      await lire('/envoi/feu-vert'),
      await lire(`/envoi/envois/chercher?adresse=${adresse}`),
    ];
    for (const t of textes) expect(t).not.toMatch(MOTIF);
  });

  it('fiche de chaque boîte (refus récents) et de chaque campagne (derniers événements)', async () => {
    for (const b of demoDb.boites.tous()) expect(await lire(`/envoi/boites/${b.id}`)).not.toMatch(MOTIF);
    for (const c of demoDb.campagnes.tous()) expect(await lire(`/envoi/campagnes/${c.id}`)).not.toMatch(MOTIF);
  });

  it('chaque événement stocké est sans champ automatique_probable', () => {
    for (const e of demoDb.evenementsEnvoi.tous()) expect(Object.keys(e)).not.toContain('automatique_probable');
  });
});
