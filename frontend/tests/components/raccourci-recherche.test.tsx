/**
 * FINITIONS P2 — le raccourci de la recherche globale s'affiche selon
 * l'ordinateur : « Ctrl+K » sur Windows et Linux, « ⌘K » seulement sur Mac.
 * Audit UX du 2026-10-02 : le pied de la palette affichait « ⌘K » à tout le
 * monde, alors que l'utilisateur travaille sur PC.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { GlobalSearch } from '@/components/ui/GlobalSearch';
import { estMac, libelleRaccourciRecherche } from '@/lib/raccourci';
import { renderScreen } from '../helpers/renderScreen';

describe('libelleRaccourciRecherche', () => {
  it.each([
    [{ platform: 'Win32' }, 'Ctrl+K'],
    [{ platform: 'Linux x86_64' }, 'Ctrl+K'],
    [{ platform: '', userAgentData: { platform: 'Windows' } }, 'Ctrl+K'],
    [{ platform: 'MacIntel' }, '⌘K'],
    [{ platform: '', userAgentData: { platform: 'macOS' } }, '⌘K'],
    [{ platform: 'iPad' }, '⌘K'],
  ])('%o → %s', (nav, attendu) => {
    expect(libelleRaccourciRecherche(nav)).toBe(attendu);
  });

  it('sans navigateur, ce n’est pas un Mac', () => {
    expect(estMac(undefined)).toBe(false);
  });
});

describe('GlobalSearch — bouton d’en-tête et pied de la palette', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('sur Windows : « Ctrl+K » partout, jamais « ⌘K »', async () => {
    vi.spyOn(window.navigator, 'platform', 'get').mockReturnValue('Win32');
    const user = userEvent.setup();
    await renderScreen(<GlobalSearch />);

    const bouton = screen.getByRole('button', { name: 'Recherche globale' });
    expect(bouton).toHaveTextContent('Ctrl+K');
    expect(bouton).not.toHaveTextContent('⌘');

    await user.click(bouton);
    const dialogue = screen.getByRole('dialog', { name: 'Recherche globale' });
    expect(dialogue).toHaveTextContent('Ctrl+K pour ouvrir / fermer');
    expect(dialogue).not.toHaveTextContent('⌘');
  });

  it('sur Mac : « ⌘K »', async () => {
    vi.spyOn(window.navigator, 'platform', 'get').mockReturnValue('MacIntel');
    const user = userEvent.setup();
    await renderScreen(<GlobalSearch />);

    const bouton = screen.getByRole('button', { name: 'Recherche globale' });
    expect(bouton).toHaveTextContent('⌘K');

    await user.click(bouton);
    expect(screen.getByRole('dialog', { name: 'Recherche globale' })).toHaveTextContent('⌘K pour ouvrir / fermer');
  });
});
