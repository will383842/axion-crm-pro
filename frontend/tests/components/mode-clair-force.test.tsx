/**
 * LOT 2 UX — PAS DE MODE SOMBRE (décision permanente du propriétaire).
 *
 * Audit du 02/10/2026, P1-9 : un sélecteur ☀ ⚙ ☾ trônait dans l'en-tête de
 * TOUS les écrans, une carte « Thème » occupait les Paramètres, et une étape
 * de la visite guidée lui était consacrée. Tout est retiré, et le clair est
 * FORCÉ à l'amorçage — y compris pour un navigateur qui avait choisi
 * « sombre » auparavant (`axion-theme=dark` en stockage local).
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { forcerThemeClair, CLE_THEME_HISTORIQUE } from '@/lib/theme';
import { SettingsPage } from '@/features/settings/SettingsPage';
import { renderScreen } from '../helpers/renderScreen';
import { getJson } from '../msw/handlers';

const racine = path.dirname(fileURLToPath(import.meta.url));
const SRC = path.resolve(racine, '../../src');
const lire = (rel: string) => readFileSync(path.join(SRC, rel), 'utf8');

describe('Mode clair forcé — l’amorçage', () => {
  beforeEach(() => {
    document.documentElement.classList.add('dark');
    document.documentElement.setAttribute('data-theme', 'dark');
    window.localStorage.setItem(CLE_THEME_HISTORIQUE, 'dark');
  });

  it('efface la classe `dark`, pose `data-theme="light"` et oublie l’ancienne préférence', () => {
    forcerThemeClair();
    expect(document.documentElement.classList.contains('dark')).toBe(false);
    expect(document.documentElement.getAttribute('data-theme')).toBe('light');
    expect(window.localStorage.getItem(CLE_THEME_HISTORIQUE)).toBeNull();
  });

  it('est APPELÉ par `main.tsx`, avant le premier rendu', () => {
    const main = lire('main.tsx');
    expect(main).toContain('forcerThemeClair();');
    expect(main.indexOf('forcerThemeClair();')).toBeLessThan(main.indexOf('createRoot('));
  });
});

describe('Mode clair forcé — aucun sélecteur nulle part', () => {
  it('le composant `DarkModeToggle` n’existe plus, et rien ne l’importe', () => {
    expect(existsSync(path.join(SRC, 'components/ui/DarkModeToggle.tsx'))).toBe(false);
    expect(lire('components/ui/index.ts')).not.toContain('DarkModeToggle');
    expect(lire('components/layout/Header.tsx')).not.toContain('DarkModeToggle');
    expect(lire('components/layout/Header.tsx')).not.toContain('dark-mode');
  });

  it('la visite guidée n’a plus d’étape « mode clair/sombre »', () => {
    const visite = lire('components/OnboardingTour.tsx');
    expect(visite).not.toContain('data-tour="dark-mode"');
    expect(visite).not.toMatch(/sombre/i);
  });

  it('les Paramètres ne proposent plus de thème : onglet « Affichage », densité seule', async () => {
    await renderScreen(<SettingsPage />, {
      path: '/settings',
      handlers: [getJson('/workspace', { id: 'w1', name: 'Axion IA', slug: 'axion-ia', cost_cap_eur: 50 })],
    });
    expect(screen.queryByRole('tab', { name: /Apparence/ })).toBeNull();
    const onglet = await screen.findByRole('tab', { name: /Affichage/ });
    await userEvent.setup().click(onglet);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /Compacte/ })).toBeInTheDocument();
    });
    expect(document.body.textContent).not.toMatch(/sombre|Thème/);
    expect(screen.queryByRole('button', { name: /theme/i })).toBeNull();
  });
});
