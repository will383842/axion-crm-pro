/**
 * UI-CAMP — « Lancer » est désactivé par la propriété `blocage` de GestesCampagne,
 * dès le premier rendu (plus par un effet qui touche le DOM après coup).
 */
import { describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse, type HttpHandler } from 'msw';
import { GestesCampagne } from '@/features/envoi/campagnes/composants/GestesCampagne';
import { PHRASE_BLOCAGE_GENERIQUE, phraseBlocage } from '@/features/envoi/campagnes/composants/libelles';
import type { Campagne } from '@/features/envoi/campagnes/api';
import { renderScreen } from '../../helpers/renderScreen';
import { apiUrl } from '../../msw/handlers';

const CAMPAGNE = { id: 7, nom: 'Campagne test', statut: 'programmee' } as unknown as Campagne;
const CHEMIN = '/envoi/campagnes/test-blocage';

async function monter(blocage?: { idRaison: string } | null, handlers: HttpHandler[] = []) {
  const Ecran = () => (
    <div>
      <p id="raison-test">Boîtes ou plafond : renvoyez à valider.</p>
      <GestesCampagne campagne={CAMPAGNE} maintenant="2027-01-12T09:00:00.000Z" variante="entete" blocage={blocage} />
    </div>
  );
  await renderScreen(<Ecran />, { path: CHEMIN, url: CHEMIN, handlers });
  return screen.findByRole('button', { name: 'Lancer' });
}

describe('GestesCampagne — propriété blocage', () => {
  it('bloquée : « Lancer » est désactivé dès le premier rendu, avec sa raison accessible', async () => {
    const lancer = await monter({ idRaison: 'raison-test' });
    expect(lancer).toBeDisabled();
    expect(lancer).toHaveAttribute('aria-describedby', 'raison-test');
    expect(lancer).toHaveAccessibleDescription('Boîtes ou plafond : renvoyez à valider.');
  });

  it('sans blocage (absent ou nul) : « Lancer » actif, sans aria-describedby', async () => {
    const lancer = await monter(null);
    expect(lancer).toBeEnabled();
    expect(lancer).not.toHaveAttribute('aria-describedby');
  });
});

describe('GestesCampagne — Lancer pendant l’envoi', () => {
  it('« Lancer » est désactivé tant que le serveur n’a pas répondu : un second clic n’envoie rien', async () => {
    let appels = 0;
    let liberer: () => void = () => {};
    const attente = new Promise<void>((r) => { liberer = r; });
    const lancer = http.post(apiUrl('/envoi/campagnes/7/lancer'), async () => {
      appels += 1;
      await attente;
      return HttpResponse.json({ ...CAMPAGNE, statut: 'en_cours' });
    });
    const bouton = await monter(null, [lancer]);
    const u = userEvent.setup();
    await u.click(bouton);
    await waitFor(() => { expect(bouton).toBeDisabled(); });
    await u.click(bouton);
    expect(appels).toBe(1);
    liberer();
  });
});

describe('phraseBlocage — codes hérités de Object.prototype', () => {
  it.each(['toString', 'constructor', '__proto__', 'hasOwnProperty'])('%s ⇒ phrase par défaut, sans [object …]', (code) => {
    const phrase = phraseBlocage(code);
    expect(phrase).toBe(PHRASE_BLOCAGE_GENERIQUE);
    expect(phrase).not.toMatch(/\[object|function/);
  });
});
