/* eslint-disable @typescript-eslint/no-explicit-any, @typescript-eslint/no-unsafe-member-access, @typescript-eslint/no-unsafe-argument, @typescript-eslint/no-unsafe-assignment -- les réponses JSON de la démo sont lues telles quelles */
/** S2-CONTRATS : les gestionnaires de démo suivent les nouveaux chemins du contrat (feu vert, import de refus, adaptateur). */
import { beforeEach, describe, expect, it } from 'vitest';
import { getResponse } from 'msw';
import { demoDb } from '@/demo/base';
import { handlers } from '@/demo/handlers';

async function appeler(methode: string, chemin: string, corps?: unknown, entetes: Record<string, string> = {}) {
  const init: RequestInit = { method: methode, headers: { 'content-type': 'application/json', ...entetes } };
  if (corps !== undefined) init.body = JSON.stringify(corps);
  const r = await getResponse(handlers, new Request(`http://localhost/api/v1${chemin}`, init));
  expect(r, `${methode} ${chemin}`).toBeDefined();
  const t = await r!.text();
  return { statut: r!.status, json: t === '' ? null : (JSON.parse(t) as Record<string, any>) };
}

beforeEach(() => demoDb.reinitialiser());

describe('démo — décision du feu vert', () => {
  it('GET rend empreinte_liste et decision nulle au départ', async () => {
    const { json } = await appeler('GET', '/envoi/feu-vert');
    expect(typeof json!['empreinte_liste']).toBe('string');
    expect(json!['decision']).toBeNull();
  });

  it('« retire » est toujours accepté, sans en-tête, et l’historique est gardé', async () => {
    const a = await appeler('POST', '/envoi/feu-vert/decision', { decision: 'retire', motif: 'Retrait de précaution.' });
    expect(a.statut).toBe(200);
    expect(a.json!['decision']).toMatchObject({ decision: 'retire' });
    const b = await appeler('POST', '/envoi/feu-vert/decision', { decision: 'retire', motif: 'Second retrait notable.' });
    expect(b.statut).toBe(200);
    expect(demoDb.zone('pilotage', 'decisions').tous()).toHaveLength(2);
  });

  it('« accorde » : 428 sans en-tête, 412 si périmé, 409 tant qu’un bloquant est rouge', async () => {
    const corps = { decision: 'accorde', motif: 'Tout est vérifié.' };
    expect((await appeler('POST', '/envoi/feu-vert/decision', corps)).statut).toBe(428);
    expect((await appeler('POST', '/envoi/feu-vert/decision', corps, { 'If-Match': '"perime"' })).statut).toBe(412);
    const { json } = await appeler('GET', '/envoi/feu-vert');
    expect((await appeler('POST', '/envoi/feu-vert/decision', corps, { 'If-Match': `"${json!['empreinte_liste']}"` })).statut).toBe(409);
  });

  it('« accorde » sans bloquant : accepté ; l’empreinte change avec la décision', async () => {
    for (const p of demoDb.zone<any>('pilotage', 'points').tous()) demoDb.zone<any>('pilotage', 'points').modifier(p.id, { bloquant: false });
    const avant = (await appeler('GET', '/envoi/feu-vert')).json!['empreinte_liste'];
    const r = await appeler('POST', '/envoi/feu-vert/decision', { decision: 'accorde', motif: 'Tout est vérifié.' }, { 'If-Match': avant });
    expect(r.statut).toBe(200);
    expect(r.json!['decision']).toMatchObject({ decision: 'accorde' });
    expect(r.json!['empreinte_liste']).not.toBe(avant);
  });
});

describe('démo — import des listes de refus', () => {
  const contenu = 'un@societe-a.example.test\n=piege@societe-b.example.test\nautre@gmail.com\n';

  it('simulation : compte et refus par numéro de ligne, jamais d’adresse, rien d’inscrit', async () => {
    const avant = demoDb.opposition.compter();
    const { statut, json } = await appeler('POST', '/envoi/opposition/import', { etape: 'simulation', contenu });
    expect(statut).toBe(200);
    expect(json).toMatchObject({ etape: 'simulation', valides: 1, deja_presentes: 0 });
    expect((json!['refusees'] as Array<{ ligne: number }>).map((x) => x.ligne)).toEqual([2, 3]);
    expect(JSON.stringify(json)).not.toContain('@');
    expect(demoDb.opposition.compter()).toBe(avant);
  });

  it('confirmation : jeton exigé et consommé une seule fois (409 ensuite)', async () => {
    const sim = await appeler('POST', '/envoi/opposition/import', { etape: 'simulation', contenu });
    const jeton = sim.json!['jeton'];
    expect((await appeler('POST', '/envoi/opposition/import', { etape: 'confirmation', contenu })).statut).toBe(409);
    const ok = await appeler('POST', '/envoi/opposition/import', { etape: 'confirmation', contenu, jeton });
    expect(ok.statut).toBe(200);
    expect(ok.json).toMatchObject({ etape: 'confirmation', valides: 1 });
    expect((await appeler('POST', '/envoi/opposition/import', { etape: 'confirmation', contenu, jeton })).statut).toBe(409);
  });
});

describe('démo — domaines', () => {
  it('chaque domaine porte adaptateur « faux »', async () => {
    const { json } = await appeler('GET', '/envoi/domaines');
    expect(json!['data'].length).toBeGreaterThan(0);
    for (const d of json!['data']) expect(d.adaptateur).toBe('faux');
  });
});
