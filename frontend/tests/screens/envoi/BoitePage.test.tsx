/**
 * DM2-INF — fiche d'une boîte : envois, refus, réponses et plafond du jour.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { BoitePage } from '@/features/envoi/infrastructure/BoitePage';
import { demoDb } from '@/demo/base';
import { handlers } from '@/demo/handlers/envoi/infrastructure.handlers';
import { renderScreen } from '../../helpers/renderScreen';

async function monter(id: string, adresse: string) {
  await renderScreen(<BoitePage />, { path: '/envoi/boites/$boiteId', url: `/envoi/boites/${id}`, handlers });
  await screen.findByRole('heading', { level: 1, name: adresse });
}

describe('BoitePage', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('montre les envois sur 30 jours, le quota du jour et les derniers refus en clair', async () => {
    await monter('boi-camille', 'camille@prospection-b.example');
    expect(screen.getByText('Envoyés (30 j)')).toBeInTheDocument();
    expect(screen.getByText('Quota du jour')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: /30 derniers jours/i })).toBeInTheDocument();
    const refus = screen.getByRole('region', { name: /Derniers refus/ });
    expect(within(refus).getAllByRole('row').length).toBeGreaterThan(1);
  });

  it('liste les réponses reçues par la boîte', async () => {
    await monter('boi-claire', 'claire@prospection-a.example');
    const bloc = await screen.findByRole('region', { name: /Réponses reçues/ });
    expect(within(bloc).getAllByRole('listitem').length).toBeGreaterThan(0);
  });

  it('montre le plafond du jour d’une boîte, sans chauffe propre', async () => {
    await monter('boi-paul', 'paul@prospection-c.example');
    const bloc = await screen.findByRole('region', { name: 'Chauffe' });
    expect(within(bloc).getByText(/envoyés aujourd’hui/i)).toBeInTheDocument();
    expect(within(bloc).queryByRole('button', { name: /Reculer/ })).toBeNull();
    expect(demoDb.boites.trouver('boi-paul')).not.toHaveProperty('chauffe');
  });

  it('enregistre la signature et le mode absence', async () => {
    const u = userEvent.setup();
    await monter('boi-claire', 'claire@prospection-a.example');
    const signature = await screen.findByLabelText('Signature');
    await u.clear(signature);
    await u.type(signature, 'Claire, équipe démo');
    await u.click(screen.getByRole('checkbox', { name: 'Mode absence' }));
    await u.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByText('Réglages enregistrés.')).toBeInTheDocument();
  });
});
