import { describe, it, expect, beforeEach } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ResultatsPage } from '@/features/envoi/pilotage/ResultatsPage';
import { handlers } from '@/demo/handlers/envoi/pilotage.handlers';
import { demoDb } from '@/demo/base';
import { renderScreen } from '../../helpers/renderScreen';
import { server } from '../../msw/server';

async function monter() {
  return renderScreen(<ResultatsPage />, { path: '/envoi/campagnes/resultats', handlers });
}

describe('ResultatsPage — Résultats', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('affiche le titre, la période et les onglets par boîte, domaine et liste', async () => {
    await monter();
    expect(await screen.findByRole('heading', { level: 1, name: 'Résultats' })).toBeVisible();
    for (const nom of ['Par boîte', 'Par domaine', 'Par liste', 'Par message type']) {
      expect(await screen.findByRole('tab', { name: nom })).toBeVisible();
    }
  });

  it('liste les boîtes dans le premier onglet, et les domaines dans le second', async () => {
    const user = userEvent.setup();
    await monter();
    const tableau = await screen.findByRole('table', { name: 'Par boîte' });
    expect(within(tableau).getByText('camille@prospection-b.example')).toBeVisible();
    await user.click(screen.getByRole('tab', { name: 'Par domaine' }));
    const domaines = await screen.findByRole('table', { name: 'Par domaine' });
    expect(within(domaines).getByText('prospection-a.example')).toBeVisible();
  });

  it('n’affiche aucune colonne ni mot de clic, et les ouvertures sont une estimation', async () => {
    const user = userEvent.setup();
    const { container } = await monter();
    await screen.findByRole('table', { name: 'Par boîte' });
    for (const nom of ['Par boîte', 'Par domaine', 'Par liste', 'Par message type']) {
      await user.click(screen.getByRole('tab', { name: nom }));
      const tableau = await screen.findByRole('table', { name: nom });
      for (const colonne of within(tableau).getAllByRole('columnheader')) {
        expect(colonne.textContent ?? '').not.toMatch(/clic/i);
        if (/ouvert/i.test(colonne.textContent ?? '')) expect(colonne.textContent).toMatch(/estimation/i);
      }
    }
    expect(container.textContent ?? '').not.toMatch(/clic/i);
    expect(container.textContent ?? '').not.toMatch(/aucun pixel/i);
  });

  it('ne montre aucune ouverture estimée, même quand une campagne a activé le pixel', async () => {
    expect(demoDb.campagnes.tous().some((c) => c.suivre_ouvertures)).toBe(true);
    const { container } = await monter();
    const tableau = await screen.findByRole('table', { name: 'Par boîte' });
    const entetes = within(tableau).getAllByRole('columnheader').map((c) => c.textContent ?? '');
    expect(entetes.filter((t) => /ouvert/i.test(t))).toEqual([]);
    expect(container.textContent ?? '').not.toMatch(/ouvertures|automatiques à part/i);
  });

  it('le faux serveur ne calcule ni ne renvoie aucune ouverture estimée (le champ est absent)', async () => {
    server.use(...handlers);
    for (const par of ['boite', 'domaine', 'liste', 'message']) {
      const reponse = await fetch(`https://api.localhost/api/v1/envoi/resultats?par=${par}`);
      const corps = (await reponse.json()) as { data: Array<Record<string, unknown>> };
      expect(corps.data.length).toBeGreaterThan(0);
      expect(corps.data.every((l) => !('ouvertures' in l))).toBe(true);
    }
  });

  it('n’affiche aucune ouverture quand aucune campagne n’a activé le pixel', async () => {
    for (const c of demoDb.campagnes.tous()) demoDb.campagnes.modifier(c.id, { suivre_ouvertures: false });
    const user = userEvent.setup();
    await monter();
    await screen.findByRole('table', { name: 'Par boîte' });
    for (const nom of ['Par boîte', 'Par domaine', 'Par liste', 'Par message type']) {
      await user.click(screen.getByRole('tab', { name: nom }));
      const tableau = await screen.findByRole('table', { name: nom });
      for (const colonne of within(tableau).getAllByRole('columnheader')) {
        expect(colonne.textContent ?? '').not.toMatch(/ouvert/i);
      }
    }
  });

  it('montre l’entonnoir et la comparaison avec la période d’avant', async () => {
    await monter();
    expect(await screen.findByRole('list', { name: 'Entonnoir' })).toBeVisible();
    expect(screen.getAllByText(/période d’avant/i).length).toBeGreaterThan(0);
  });

  it('exporte le tableau affiché en CSV', async () => {
    const user = userEvent.setup();
    await monter();
    await screen.findByRole('table', { name: 'Par boîte' });
    await user.click(screen.getByRole('button', { name: 'Exporter (CSV)' }));
    expect(await screen.findByText(/Export prêt/i)).toBeVisible();
  });

  it('montre un état vide propre', async () => {
    const { handlers: h } = await import('@/demo/handlers/envoi/pilotage.handlers');
    const { http, HttpResponse } = await import('msw');
    const vide = http.get('*/api/v1/envoi/resultats', () =>
      HttpResponse.json({ data: [], totaux: null, precedent: null, entonnoir: null, periode: { du: '2026-12-13', au: '2027-01-12' } }),
    );
    await renderScreen(<ResultatsPage />, { path: '/envoi/campagnes/resultats', handlers: [vide, ...h] });
    expect(await screen.findByText('Rien à afficher pour le moment')).toBeVisible();
  });
});
