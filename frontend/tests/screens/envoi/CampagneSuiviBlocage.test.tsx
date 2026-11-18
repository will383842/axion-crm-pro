/**
 * UI-CAMP — « Lancer » est désactivé par la propriété `blocage` de GestesCampagne,
 * dès le premier rendu (plus par un effet qui touche le DOM après coup).
 */
import { describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import { GestesCampagne } from '@/features/envoi/campagnes/composants/GestesCampagne';
import { PHRASE_BLOCAGE_GENERIQUE, phraseBlocage } from '@/features/envoi/campagnes/composants/libelles';
import type { Campagne } from '@/features/envoi/campagnes/api';
import { renderScreen } from '../../helpers/renderScreen';

const CAMPAGNE = { id: 7, nom: 'Campagne test', statut: 'programmee' } as unknown as Campagne;
const CHEMIN = '/envoi/campagnes/test-blocage';

async function monter(blocage?: { idRaison: string } | null) {
  const Ecran = () => (
    <div>
      <p id="raison-test">Boîtes ou plafond : renvoyez à valider.</p>
      <GestesCampagne campagne={CAMPAGNE} maintenant="2027-01-12T09:00:00.000Z" variante="entete" blocage={blocage} />
    </div>
  );
  await renderScreen(<Ecran />, { path: CHEMIN, url: CHEMIN });
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

describe('phraseBlocage — codes hérités de Object.prototype', () => {
  it.each(['toString', 'constructor', '__proto__', 'hasOwnProperty'])('%s ⇒ phrase par défaut, sans [object …]', (code) => {
    const phrase = phraseBlocage(code);
    expect(phrase).toBe(PHRASE_BLOCAGE_GENERIQUE);
    expect(phrase).not.toMatch(/\[object|function/);
  });
});
