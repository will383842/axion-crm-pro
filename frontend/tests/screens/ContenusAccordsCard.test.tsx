/**
 * Bloc « Contenus et accords » (PV-6) : rendu en lecture seule.
 * Adresses et valeurs fictives (dépôt PUBLIC) ; mots interdits seulement sous forme de jetons.
 */
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';

import { ContenusAccordsCard } from '@/features/crm-console/ContenusAccordsCard';
import type { ContenusAccords } from '@/features/crm-console/types';

const complet: ContenusAccords = {
  origine: { mot: 'guide-ia', reseau: 'reseau-a', guide: 'guide-ia', date: '2026-11-02T09:58:00Z' },
  guides: [{ guide: 'guide-ia', date: '2026-11-02T10:00:00Z' }],
  consentements: [
    { nature: 'information', date: '2026-11-02T10:00:00Z', source: 'mot-guide-ia:reseau-a', version: 'M1' },
    { nature: 'optin', date: '2026-11-03T10:00:00Z', source: 'confirmation', version: 'M2' },
  ],
  opposition: { actif: false, date: null },
};

describe('ContenusAccordsCard', () => {
  it('montre l’origine, les guides, les accords et les versions de texte', () => {
    render(<ContenusAccordsCard bloc={complet} />);
    expect(screen.getByText('Contenus et accords')).toBeTruthy();
    expect(screen.getByText(/Réseau : reseau-a/)).toBeTruthy();
    expect(screen.getByText(/Accord pour de futures campagnes/)).toBeTruthy();
    expect(screen.getByText(/version M1/)).toBeTruthy();
    expect(screen.queryByTestId('bandeau-opposition')).toBeNull();
  });

  it('affiche l’opposition en bandeau d’alerte visible', () => {
    render(<ContenusAccordsCard bloc={{ ...complet, opposition: { actif: true, date: '2026-11-04T08:00:00Z' } }} />);
    const bandeau = screen.getByTestId('bandeau-opposition');
    expect(bandeau.getAttribute('role')).toBe('alert');
    expect(bandeau.textContent).toContain('mise à l’écart de tout envoi');
  });

  it('une ancienne API sans le bloc ne casse rien', () => {
    render(<ContenusAccordsCard bloc={undefined} />);
    expect(screen.getByText(/Aucune information de contenu/)).toBeTruthy();
  });

  it('échappe le texte : aucune balise brute n’est interprétée', () => {
    const piege: ContenusAccords = {
      ...complet,
      origine: { mot: '<img src=x onerror=alert(1)>', reseau: null, guide: null, date: null },
    };
    const { container } = render(<ContenusAccordsCard bloc={piege} />);
    expect(container.querySelector('img')).toBeNull();
    expect(container.textContent).toContain('<img src=x');
  });
});
