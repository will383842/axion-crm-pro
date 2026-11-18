/**
 * B8-F — l'assistant « Nouvelle campagne » ne propose ni n'envoie les réglages que l'envoi ne sait pas
 * encore traiter (test A/B, boîtes, plafond par jour) : capacités lues du serveur, refus par défaut.
 * Faux serveur local ; les charges utiles des étapes sont relevées.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { NouvelleCampagnePage } from '@/features/envoi/campagnes/NouvelleCampagnePage';
import { handlers } from '@/demo/handlers/envoi/creation.handlers';
import { demoDb } from '@/demo/base';
import { SAISIE_INITIALE, contenuEtape, saisieDepuis, type Saisie } from '@/features/envoi/creation/saisie';
import type { Brouillon } from '@/features/envoi/creation/api';
import { renderScreen } from '../../helpers/renderScreen';

const CHEMIN = '/envoi/campagnes/nouvelle';
const REFUSEES = { ab: false, boites: false, plafond: false };
const ACCEPTEES = { ab: true, boites: true, plafond: true };

function brouillonAncien(etape: number): Brouillon {
  return {
    id: 'bro-ancien',
    nom: 'Ancien brouillon',
    etape_courante: etape,
    etapes_faites: [1, 2],
    contenu: {
      nom: 'Ancien brouillon',
      cible: { type: 'etiquette', reference: 'x', libelle: 'x' },
      sequence: [{ etape: 1, message_id: 'msg-1', delai_jours: 0, objet_b: 'Objet B ancien' }],
      depart_immediat: true,
      boites: ['boi-camille'],
      plafond_jour: 40,
    },
    mis_a_jour_le: '2026-11-18T08:00:00Z',
    campagne_id: null,
    annulable_jusqua: null,
  } as unknown as Brouillon;
}

/** Faux serveur : capacités voulues, brouillon voulu (ou neuf), relevé des PUT d'étapes. */
function serveur(capacites: typeof REFUSEES, brouillon: Brouillon | null) {
  const puts: Array<{ etape: number; corps: Record<string, unknown> }> = [];
  const surcharges = [
    http.get('*/envoi/creation/contexte', () => HttpResponse.json({ maintenant: '2026-11-18T09:00:00Z', fuseau: 'Europe/Paris', capacites })),
    ...(brouillon
      ? [http.get('*/envoi/brouillons/bro-ancien', () => HttpResponse.json(brouillon))]
      : []),
    http.put('*/envoi/brouillons/*/etapes/*', async ({ request }) => {
      puts.push({ etape: Number(new URL(request.url).pathname.split('/').pop()), corps: (await request.json()) as Record<string, unknown> });
      return HttpResponse.json(brouillon ?? brouillonAncien(3));
    }),
  ];
  return { puts, surcharges };
}

async function ouvrir(surcharges: ReturnType<typeof serveur>['surcharges'], url = CHEMIN) {
  await renderScreen(<NouvelleCampagnePage />, { path: CHEMIN, url, handlers: [...surcharges, ...handlers], landingRoutes: ['/envoi/campagnes/$campagneId'] });
  await screen.findByRole('heading', { level: 1, name: 'Nouvelle campagne' });
}

const decrit = (el: HTMLElement): string => {
  const id = el.getAttribute('aria-describedby');
  return (id ?? '').split(' ').map((i) => document.getElementById(i)?.textContent ?? '').join(' ');
};

describe('B8-F — contenu des étapes sans les réglages refusés (pur)', () => {
  const plein: Saisie = { ...SAISIE_INITIALE, ab: true, objetB: 'B', boites: ['b1'], boitesChoisies: true, plafond: 30 };

  it('capacités fausses (valeur par défaut) : ni boites, ni plafond_jour, ni objet_b', () => {
    expect(contenuEtape(3, plein)).not.toHaveProperty('boites');
    expect(contenuEtape(3, plein)).not.toHaveProperty('plafond_jour');
    expect(JSON.stringify(contenuEtape(2, plein))).not.toContain('objet_b');
  });

  it('capacités vraies : comportement d’avant', () => {
    const s = { ...plein, capacites: ACCEPTEES };
    expect(contenuEtape(3, s)).toMatchObject({ boites: ['b1'], plafond_jour: 30 });
    expect(JSON.stringify(contenuEtape(2, s))).toContain('"objet_b":"B"');
  });

  it('un ancien brouillon est rechargé, puis réécrit sans ces champs', () => {
    const s = saisieDepuis(brouillonAncien(3));
    expect(s.boites).toEqual(['boi-camille']);
    const sortie = contenuEtape(3, s);
    expect(sortie).not.toHaveProperty('boites');
    expect(sortie).not.toHaveProperty('plafond_jour');
    expect(JSON.stringify(contenuEtape(2, s))).not.toContain('objet_b');
  });
});

describe('B8-F — assistant : contrôles grisés tant que refusés', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
  });

  it('étape 3 : boîtes et plafond désactivés avec raison accessible, rien envoyé, rien pré-sélectionné', async () => {
    const { puts, surcharges } = serveur(REFUSEES, brouillonAncien(3));
    await ouvrir(surcharges, `${CHEMIN}?brouillon=bro-ancien`);
    const plafond = await screen.findByLabelText('Plafond de cette campagne');
    expect(plafond).toBeDisabled();
    expect(decrit(plafond)).toMatch(/Bientôt/);
    const cases = await screen.findAllByRole('checkbox');
    expect(cases.length).toBeGreaterThan(0);
    for (const c of cases) {
      expect(c).toBeDisabled();
      expect(c).not.toBeChecked();
      expect(decrit(c)).toMatch(/Bientôt/);
    }
    expect(screen.getByText(/réglages retirés : non pris en charge/i)).toBeVisible();
    await userEvent.click(screen.getByRole('button', { name: /Continuer : vérification/ }));
    await waitFor(() => { expect(puts.some((p) => p.etape === 3)).toBe(true); }, { timeout: 4000 });
    for (const p of puts.filter((x) => x.etape === 3)) {
      expect(p.corps).not.toHaveProperty('boites');
      expect(p.corps).not.toHaveProperty('plafond_jour');
    }
  });

  it('brouillon neuf à l’étape 3 : aucune boîte n’est pré-sélectionnée', async () => {
    const neuf = { ...brouillonAncien(3), contenu: { nom: 'N', cible: { type: 'etiquette', reference: 'x', libelle: 'x' }, depart_immediat: true } } as unknown as Brouillon;
    const { puts, surcharges } = serveur(REFUSEES, neuf);
    await ouvrir(surcharges, `${CHEMIN}?brouillon=bro-ancien`);
    await screen.findByLabelText('Plafond de cette campagne');
    await new Promise((r) => setTimeout(r, 400));
    for (const c of screen.queryAllByRole('checkbox')) expect(c).not.toBeChecked();
    for (const p of puts) expect(p.corps).not.toHaveProperty('boites');
  });

  it('étape 2 : case A/B désactivée avec raison, objet_b jamais envoyé', async () => {
    const { puts, surcharges } = serveur(REFUSEES, brouillonAncien(2));
    await ouvrir(surcharges, `${CHEMIN}?brouillon=bro-ancien`);
    const ab = await screen.findByRole('checkbox', { name: /Tester deux objets/ });
    expect(ab).toBeDisabled();
    expect(ab).not.toBeChecked();
    expect(decrit(ab)).toMatch(/Bientôt : le test A\/B n’est pas encore pris en charge par l’envoi/);
    expect(screen.queryByLabelText(/version B/)).toBeNull();
    await userEvent.click(await screen.findByRole('button', { name: /Continuer/ }));
    await waitFor(() => { expect(puts.some((p) => p.etape === 2)).toBe(true); }, { timeout: 4000 });
    for (const p of puts.filter((x) => x.etape === 2)) expect(JSON.stringify(p.corps)).not.toContain('objet_b');
  });

  it('capacités vraies : comportement d’aujourd’hui (boîtes prêtes pré-sélectionnées et envoyées)', async () => {
    const neuf = { ...brouillonAncien(3), contenu: { nom: 'N', cible: { type: 'etiquette', reference: 'x', libelle: 'x' }, depart_immediat: true } } as unknown as Brouillon;
    const { puts, surcharges } = serveur(ACCEPTEES, neuf);
    await ouvrir(surcharges, `${CHEMIN}?brouillon=bro-ancien`);
    const plafond = await screen.findByLabelText('Plafond de cette campagne');
    await waitFor(() => { expect(plafond).toBeEnabled(); });
    await waitFor(() => { expect(screen.getAllByRole('checkbox').some((c) => (c as HTMLInputElement).checked)).toBe(true); });
    await userEvent.click(screen.getByRole('button', { name: /Continuer : vérification/ }));
    await waitFor(() => { expect(puts.some((p) => p.etape === 3 && Array.isArray(p.corps['boites']) && (p.corps['boites'] as string[]).length > 0 && 'plafond_jour' in p.corps)).toBe(true); }, { timeout: 4000 });
  });
});
