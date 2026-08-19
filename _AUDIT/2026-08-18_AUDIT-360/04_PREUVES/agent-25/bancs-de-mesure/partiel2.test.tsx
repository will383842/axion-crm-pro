/**
 * AGENT 25 — REPRISE DE MA PROPRE MESURE.
 * Référence : main 8db8229.
 *
 * Au premier essai, `/console/contacts` et `/console/vivier` ont rendu
 * « Something went wrong! — Cannot read properties of undefined (reading 'length') ».
 * J'ai d'abord cru à un défaut de l'état partiel. **C'était MA faute** : ma ligne
 * d'essai omettait `tags` et `contacts`, que l'écran lit sans garde
 * (`ContactsHubPage.tsx:205,226`).
 *
 * Ce fichier fait donc DEUX choses, et il faut les deux :
 *   A. la vraie mesure de l'état partiel, avec une ligne COMPLÈTE ;
 *   B. le témoin qui isole la cause : ligne incomplète + compteurs OK
 *      (si ça casse encore, la cause est bien le champ manquant, pas le 500).
 */
import { describe, it, expect } from 'vitest';
import { waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { writeFileSync, mkdirSync } from 'node:fs';

import { renderScreen } from '../../tests/helpers/renderScreen';
import { apiUrl } from '../../tests/msw/handlers';
import { ContactsHubPage } from '@/features/crm-console/ContactsHubPage';

const LIGNE_COMPLETE = {
  id: 1, siren: '123456789', denomination: 'ACME',
  relation_type: 'client', lifecycle_stage: 'client',
  legal_basis: 'contrat', city_name: 'Lyon', department_code: '69',
  size_category: 'pme', email_generic: null, updated_at: null,
  tags: ['geo-69'], contacts: [],
};
const LIGNE_INCOMPLETE = {
  id: 1, siren: '123456789', denomination: 'ACME',
  relation_type: 'client', lifecycle_stage: 'client',
};
const META = { per_page: 50, next_cursor: null, prev_cursor: null, has_more: false };
const COUNTS_OK = { total: 7, by_relation_type: { client: 7 }, by_lifecycle_stage: { client: 7 } };

const lignes: string[] = [
  'AGENT 25 — REPRISE : etat partiel de /console/contacts, et isolement de la cause',
  'Reference : main 8db8229',
  '',
];

async function monter(nom: string, ligne: unknown, countsKo: boolean) {
  const handlers = [
    http.get(apiUrl('/crm/contacts-hub'), () =>
      HttpResponse.json({ data: [ligne], meta: META } as never)),
    countsKo
      ? http.get(apiUrl('/crm/contacts-hub/counts'), () =>
          HttpResponse.json({ message: 'ko' } as never, { status: 500 }))
      : http.get(apiUrl('/crm/contacts-hub/counts'), () => HttpResponse.json(COUNTS_OK as never)),
  ];
  let texte = '';
  try {
    await renderScreen(<ContactsHubPage />, {
      path: '/console/contacts', url: '/console/contacts',
      handlers, consoleFeatures: 'open',
    });
    await waitFor(() => expect(document.body.textContent).toBeDefined(), { timeout: 5000 });
    await new Promise((r) => setTimeout(r, 350));
    texte = (document.body.textContent ?? '').replace(/\s+/g, ' ').trim();
  } catch (err) {
    texte = `EXCEPTION AU MONTAGE: ${(err as Error).message?.slice(0, 200)}`;
  }
  const casse = /Something went wrong|Cannot read properties/i.test(texte);
  lignes.push(`### ${nom}`);
  lignes.push(`  l ecran CASSE-T-IL ? ${casse ? 'OUI' : 'NON'}`);
  lignes.push(`  rendu : « ${texte.slice(0, 420)} »`);
  lignes.push('');
  return { texte, casse };
}

describe('AGENT 25 — reprise état partiel console', () => {
  it('A — ligne COMPLETE + compteurs KO (la vraie mesure de l état partiel)', async () => {
    const r = await monter('A. ligne complete, /crm/contacts-hub/counts -> 500', LIGNE_COMPLETE, true);
    lignes.push(`  => ${r.casse ? 'CASSE' : "l ecran tient ; reste a savoir ce qu il affiche a la place des compteurs"}`);
    lignes.push('');
    expect(true).toBe(true);
  }, 40_000);

  it('B — TEMOIN : ligne COMPLETE + compteurs OK (tout va bien ?)', async () => {
    await monter('B. TEMOIN POSITIF — ligne complete, compteurs 200', LIGNE_COMPLETE, false);
    expect(true).toBe(true);
  }, 40_000);

  it('C — TEMOIN D ISOLEMENT : ligne INCOMPLETE + compteurs OK', async () => {
    const r = await monter('C. TEMOIN D ISOLEMENT — ligne SANS tags ni contacts, compteurs 200', LIGNE_INCOMPLETE, false);
    lignes.push(
      r.casse
        ? '  => CASSE alors que RIEN n echoue cote reseau : la cause est bien le CHAMP MANQUANT'
          + ' (ContactsHubPage.tsx:205 `company.contacts.length`, :226 `company.tags.length`),'
          + ' PAS l etat partiel. Ma premiere mesure accusait le mauvais objet.'
        : '  => ne casse pas : la cause du plantage initial etait ailleurs.',
    );
    lignes.push('');
    expect(true).toBe(true);
  }, 40_000);

  it('ZZZ — écrit le relevé', () => {
    mkdirSync('tmp/agent25/out', { recursive: true });
    writeFileSync('tmp/agent25/out/releve-partiel-2.txt', lignes.join('\n'), 'utf8');
    // eslint-disable-next-line no-console
    console.log(lignes.join('\n'));
    expect(lignes.length).toBeGreaterThan(3);
  });
});
