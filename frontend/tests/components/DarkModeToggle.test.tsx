import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DarkModeToggle } from '@/components/ui/DarkModeToggle';

describe('DarkModeToggle', () => {
  beforeEach(() => {
    localStorage.clear();
    document.documentElement.classList.remove('dark');
    // Polyfill matchMedia for jsdom
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      value: vi.fn().mockImplementation((query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
      })),
    });
  });

  it('renders 3 buttons : light / system / dark', () => {
    render(<DarkModeToggle />);
    expect(screen.getByRole('button', { name: /Theme light/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Theme system/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Theme dark/i })).toBeInTheDocument();
  });

  it('default theme is system', () => {
    render(<DarkModeToggle />);
    expect(screen.getByRole('button', { name: /Theme system/i })).toHaveAttribute('aria-pressed', 'true');
  });

  it('switches to dark when dark button clicked', async () => {
    const user = userEvent.setup();
    render(<DarkModeToggle />);
    await user.click(screen.getByRole('button', { name: /Theme dark/i }));
    expect(document.documentElement.classList.contains('dark')).toBe(true);
    expect(localStorage.getItem('axion-theme')).toBe('dark');
  });

  it('switches to light when light button clicked', async () => {
    const user = userEvent.setup();
    render(<DarkModeToggle />);
    await user.click(screen.getByRole('button', { name: /Theme light/i }));
    expect(document.documentElement.classList.contains('dark')).toBe(false);
    expect(localStorage.getItem('axion-theme')).toBe('light');
  });

  it('persists theme to localStorage', async () => {
    const user = userEvent.setup();
    render(<DarkModeToggle />);
    await user.click(screen.getByRole('button', { name: /Theme dark/i }));
    expect(localStorage.getItem('axion-theme')).toBe('dark');
  });

  /*
   * D28-015 — cible tactile de 24 × 24 px minimum (WCAG 2.2, critère 2.5.8).
   *
   * Mesure du 2026-08-22 : sans plancher de taille, le bouton valait le glyphe
   * plus `px-2 py-1`, soit 22,9 × 24 px. Le composant vit dans l'en-tête de la
   * coquille, donc le défaut se répétait sur tous les écrans (33 nœuds fautifs
   * en clair, 32 en sombre).
   *
   * ⚠️ Ce que cette garde inspecte, et RIEN DE PLUS : la présence des deux
   * utilitaires de plancher sur la boîte du bouton. jsdom ne met rien en page —
   * toute largeur lue ici vaudrait 0 px, une assertion « ≥ 24 px » y serait un
   * mensonge. La mesure au pixel appartient à la règle `target-size` d'axe-core
   * dans `tests/e2e/a11y.spec.ts`, qui tourne sur le produit réellement rendu.
   */
  it('D28-015 — chaque bouton de thème porte le plancher de cible tactile (24 px)', () => {
    render(<DarkModeToggle />);

    for (const nom of ['light', 'system', 'dark']) {
      const bouton = screen.getByRole('button', { name: new RegExp(`Theme ${nom}`, 'i') });
      const classes = bouton.className;

      expect(
        classes.includes('min-h-6') && classes.includes('min-w-6'),
        `D28-015 : le bouton « Theme ${nom} » n'a plus « min-h-6 min-w-6 ». Sans ce ` +
          `plancher il retombe à 22,9 × 24 px, sous le minimum WCAG 2.2 de 24 × 24 px, ` +
          `et sur TOUS les écrans puisque le sélecteur vit dans l'en-tête. ` +
          `Geste : remets « inline-flex min-h-6 min-w-6 items-center justify-center » sur ` +
          `le <button> de DarkModeToggle.tsx — classes lues : « ${classes} ».`,
      ).toBe(true);
    }
  });
});
