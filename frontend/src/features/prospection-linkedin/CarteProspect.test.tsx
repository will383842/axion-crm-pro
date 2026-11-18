/**
 * UI-LI — le lien du profil n'est rendu que pour une adresse LinkedIn sûre ; sinon, texte simple (jamais de `javascript:`).
 */
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { CarteProspect } from './CarteProspect';
import type { ProspectFile } from './api';

const prospect = (profil: string): ProspectFile => ({
  id: '11111111-1111-4111-8111-111111111111',
  profil,
  entreprise: 'Exemple SA',
  score: 80,
  texte: '[ESSAI] Bonjour.',
});

const monter = (profil: string) => render(<CarteProspect prospect={prospect(profil)} occupe={false} onGeste={() => undefined} />);

describe('CarteProspect — lien du profil', () => {
  it('un profil LinkedIn sûr devient un lien sécurisé', () => {
    monter('https://www.linkedin.com/in/exemple-test/');
    const lien = screen.getByRole('link', { name: /ouvrir le profil/i });
    expect(lien).toHaveAttribute('href', 'https://www.linkedin.com/in/exemple-test/');
    expect(lien).toHaveAttribute('target', '_blank');
    expect(lien).toHaveAttribute('rel', 'noopener noreferrer');
  });

  it.each([
    'javascript:alert(1)',
    'JAVASCRIPT:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'http://www.linkedin.com/in/exemple/',
    'https://www.linkedin.com.exemple.test/in/x/',
    'https://exemple.test/https://www.linkedin.com/in/x/',
    'https://www.linkedin.com/company/x/',
    '',
  ])('« %s » : aucun lien, aucun href, texte simple', (profil) => {
    const { container } = monter(profil);
    expect(screen.queryByRole('link')).toBeNull();
    expect(container.querySelector('[href]')).toBeNull();
    expect(screen.getByText(/profil non cliquable/i)).toBeInTheDocument();
  });
});
