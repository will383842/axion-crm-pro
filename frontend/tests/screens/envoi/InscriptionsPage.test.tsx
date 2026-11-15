import { describe, it, expect, beforeEach } from 'vitest';
import { screen, within } from '@testing-library/react';
import { http, HttpResponse, type HttpHandler } from 'msw';
import { InscriptionsPage } from '@/features/envoi/pilotage/InscriptionsPage';
import { handlers as pilotage } from '@/demo/handlers/envoi/pilotage.handlers';
import { handlers as reputation } from '@/demo/handlers/envoi/reputation.handlers';
import { demoDb } from '@/demo/base';
import { renderScreen } from '../../helpers/renderScreen';

const SERVICES = ['Google Postmaster Tools', 'Microsoft SNDS et JMRP', 'Yahoo, boucle de plaintes (CFL)', 'Orange : contact délivrabilité', 'Signal Spam'];
const monter = (extra: HttpHandler[] = []) =>
  renderScreen(<InscriptionsPage />, { path: '/envoi/sante/inscriptions', handlers: [...extra, ...pilotage, ...reputation] });
const ligne = async (nom: string) => (await screen.findByRole('heading', { level: 3, name: nom })).closest('li') as HTMLElement;
const point = (code: string, etat: string, preuve: unknown = null) => ({ code, libelle: code, groupe: 'conformite', bloquant: true, source: '30 A11', detail: null, nature: 'manuel', etat, fait: etat === 'fait', preuve });
const feuVert = (points: unknown[]) => http.get('*/api/v1/envoi/feu-vert', () => HttpResponse.json({ points, faits: 0, total: points.length, bloquants_restants: 0, verrous: {} }));
const constats = (data: unknown[]) => http.get('*/api/v1/envoi/reputation', () => HttpResponse.json({ data }));

describe('InscriptionsPage — inscriptions de réputation (lecture seule)', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('une ligne par service, dans l’ordre', async () => {
    await monter();
    for (const nom of SERVICES) expect(await ligne(nom)).toBeVisible();
    expect(screen.getAllByRole('heading', { level: 3 })).toHaveLength(5);
  });

  it('l’état est tiré du feu vert : fait, à faire, non évalué', async () => {
    await monter([
      feuVert([point('manuel.postmaster_google', 'fait'), point('manuel.snds_jmrp', 'a_faire'), point('manuel.yahoo_cfl', 'attend_serveur_reel')]),
    ]);
    expect((await ligne('Google Postmaster Tools')).textContent).toContain('Fait');
    expect((await ligne('Microsoft SNDS et JMRP')).textContent).toContain('À faire');
    expect((await ligne('Yahoo, boucle de plaintes (CFL)')).textContent).toContain('Non évalué');
    expect((await ligne('Orange : contact délivrabilité')).textContent).toContain('Non évalué');
    expect((await ligne('Signal Spam')).textContent).toContain('Non évalué');
  });

  it('montre le dernier constat de réputation du service, sinon « aucun constat »', async () => {
    await monter([constats([
      { id: 2, source: 'google_postmaster', ressource_type: 'domaine', ressource: 'envoi.example.test', jour: '2026-11-10', reputation: 'bonne', saisie: 'manuelle' },
      { id: 1, source: 'google_postmaster', ressource_type: 'domaine', ressource: 'envoi.example.test', jour: '2026-11-01', reputation: 'mauvaise', saisie: 'manuelle' },
      { id: 3, source: 'microsoft_jmrp', ressource_type: 'ip', ressource: '192.0.2.10', jour: '2026-11-05', reputation: 'moyenne', saisie: 'manuelle' },
    ])]);
    const g = await ligne('Google Postmaster Tools');
    expect(g.textContent).toContain('Bonne');
    expect(g.textContent).not.toContain('Mauvaise');
    expect((await ligne('Microsoft SNDS et JMRP')).textContent).toContain('Moyenne');
    expect((await ligne('Yahoo, boucle de plaintes (CFL)')).textContent).toMatch(/aucun constat/i);
  });

  it('aucun bouton d’écriture, aucun lien externe, aucune case', async () => {
    await monter();
    await ligne('Signal Spam');
    expect(screen.queryByRole('button')).toBeNull();
    expect(screen.queryByRole('checkbox')).toBeNull();
    expect(screen.queryByRole('textbox')).toBeNull();
    for (const a of document.querySelectorAll('a[href]')) expect(a.getAttribute('href')).not.toMatch(/^https?:/);
  });

  it('renvoie vers la fiche K14 par son titre, sans lien', async () => {
    await monter();
    await ligne('Signal Spam');
    expect(screen.getByText(/Fiche K14 — inscriptions de réputation \(pas à pas\)/)).toBeVisible();
    expect(screen.queryByRole('link', { name: /Fiche K14/ })).toBeNull();
  });

  it('chargement, vide, panne', async () => {
    const attente = http.get('*/api/v1/envoi/feu-vert', () => new Promise(() => {}));
    const { unmount } = await monter([attente]);
    expect(await screen.findByRole('status', { name: /Chargement/ })).toBeVisible();
    unmount();

    await monter([feuVert([]), constats([])]);
    expect(await screen.findByText('Aucune inscription à afficher')).toBeVisible();
    expect(screen.queryByRole('heading', { level: 3 })).toBeNull();
  });

  it('panne : message et « Réessayer »', async () => {
    await monter([http.get('*/api/v1/envoi/feu-vert', () => HttpResponse.json({ message: 'x' }, { status: 500 }))]);
    const alerte = await screen.findByRole('alert');
    expect(within(alerte).getByRole('button', { name: 'Réessayer' })).toBeVisible();
  });

  it('la démo montre les cinq services', async () => {
    await monter();
    expect((await ligne('Orange : contact délivrabilité')).textContent).toContain('Non évalué');
    expect((await ligne('Signal Spam')).textContent).toContain('Fait');
  });
});
