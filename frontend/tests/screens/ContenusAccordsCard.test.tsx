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
    expect(screen.getByText(/Reseau a/)).toBeTruthy();
    expect(screen.getByText(/Accord pour de futures campagnes/)).toBeTruthy();
    expect(screen.getByText(/version M2/)).toBeTruthy();
    // Consentement de nature « information » et sa version de texte (M1) : toujours lisibles.
    expect(screen.getByText('Information reçue')).toBeTruthy();
    expect(screen.getByText(/version M1/)).toBeTruthy();
    expect(screen.getByText(/mot-guide-ia:reseau-a/)).toBeTruthy();
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

// ── Lien du guide (maquette « lien du guide », 4 cas) ───────────────────────
const URL_GUIDE = 'https://exemple.invalid/guides/guide-ia.pdf';
const LIEN = { url: URL_GUIDE, libelle: 'Guide exemple A (PDF)' };

function avecLien(statut: 'disponible' | 'eteint' | 'refuse', lien: typeof LIEN | null, nom: string | null = 'Guide exemple A'): ContenusAccords {
  return {
    origine: { mot: 'exemple', reseau: 'instagram', guide: 'guide-ia', date: '2026-11-02T09:58:00Z', nom, lien },
    guides: [{ guide: 'guide-ia', date: '2026-11-02T10:00:00Z', nom, lien }],
    consentements: [{ nature: 'optin', date: '2026-11-02T10:00:00Z', source: 'confirmation', version: 'M2' }],
    opposition: { actif: false, date: null },
    lien_statut: statut,
  };
}

describe('ContenusAccordsCard — lien du guide', () => {
  it('cas 1 · interrupteur activé : « Voir le guide » en nouvel onglet, rel exact, sur le guide d’origine et chaque guide reçu', () => {
    render(<ContenusAccordsCard bloc={avecLien('disponible', LIEN)} />);
    const liens = screen.getAllByRole('link', { name: /Voir le guide/ });
    expect(liens).toHaveLength(2);
    for (const a of liens) {
      expect(a.getAttribute('href')).toBe(URL_GUIDE);
      expect(a.getAttribute('target')).toBe('_blank');
      expect(a.getAttribute('rel')).toBe('noopener noreferrer nofollow');
      expect(a.textContent).toContain('Voir le guide');
    }
    expect(liens[0]?.getAttribute('aria-label')).toBe('Voir le guide « Guide exemple A (PDF) » (nouvel onglet)');
    expect(screen.getByText('Guide d’origine')).toBeTruthy();
    expect(screen.getByText('Guides reçus')).toBeTruthy();
    expect(screen.getByText('Accord pour de futures campagnes')).toBeTruthy();
    expect(screen.getByText(/donné le 02\/11\/2026/)).toBeTruthy();
    expect(screen.getByText(/la même pour tout le monde/)).toBeTruthy();
  });

  it('cas 2 · interrupteur éteint : le nom du guide, aucun lien, mention neutre', () => {
    const { container } = render(<ContenusAccordsCard bloc={avecLien('eteint', null)} />);
    expect(container.querySelector('a')).toBeNull();
    expect(screen.getAllByText(/Guide exemple A/).length).toBeGreaterThan(0);
    expect(screen.getByText('Le lien du guide n’est pas affiché pour le moment.')).toBeTruthy();
  });

  it('cas 3 · identifiant inconnu : l’identifiant en texte, aucun lien', () => {
    const { container } = render(<ContenusAccordsCard bloc={avecLien('disponible', null, null)} />);
    expect(container.querySelector('a')).toBeNull();
    expect(screen.getAllByText('guide-ia').length).toBeGreaterThan(0);
    // Message propre à chaque ligne concernée (origine + guide reçu).
    expect(screen.getAllByText('Aucun lien disponible pour ce guide.')).toHaveLength(2);
  });

  it('cas 4 · profil lecture seule : aucun lien, mention « votre profil »', () => {
    const { container } = render(<ContenusAccordsCard bloc={avecLien('refuse', null)} />);
    expect(container.querySelector('a')).toBeNull();
    expect(screen.getByText('Le lien du guide n’est pas disponible avec votre profil.')).toBeTruthy();
  });

  it('seconde garde : une URL qui n’est pas en https, ou avec ?, # ou identifiants, n’est jamais rendue en lien', () => {
    for (const url of ['http://exemple.invalid/g.pdf', 'javascript:alert(1)', 'https://exemple.invalid/g.pdf?t=abc', 'https://exemple.invalid/g.pdf#x', 'https://u@exemple.invalid/g.pdf', '//exemple.invalid/g.pdf']) {
      const { container, unmount } = render(<ContenusAccordsCard bloc={avecLien('disponible', { url, libelle: 'x' })} />);
      expect(container.querySelector('a')).toBeNull();
      unmount();
    }
  });

  it('une ancienne API sans lien ni statut : rendu sans lien et sans mention', () => {
    const { container } = render(<ContenusAccordsCard bloc={complet} />);
    expect(container.querySelector('a')).toBeNull();
    expect(container.textContent).not.toContain('Le lien du guide');
    expect(container.textContent).not.toContain('Aucun lien disponible');
  });
});

describe('ContenusAccordsCard — date d’origine et lignes sans lien', () => {
  it('la date d’origine reste affichée quand l’origine n’a pas de guide', () => {
    const bloc: ContenusAccords = {
      origine: { mot: 'exemple', reseau: 'instagram', guide: null, date: '2026-11-02T09:58:00Z' },
      guides: [],
      consentements: [],
      opposition: { actif: false, date: null },
    };
    render(<ContenusAccordsCard bloc={bloc} />);
    expect(screen.getByTestId('origine-date').textContent).toContain('02/11/2026');
  });

  it('une ancienne ligne de guide sans identifiant ne masque pas la mention « version publique » des autres', () => {
    const base = avecLien('disponible', LIEN);
    const bloc: ContenusAccords = {
      ...base,
      guides: [...base.guides, { guide: null, date: '2026-10-01T10:00:00Z', nom: null, lien: null }],
    };
    render(<ContenusAccordsCard bloc={bloc} />);
    expect(screen.getByText(/la même pour tout le monde/)).toBeTruthy();
    expect(screen.queryByText('Aucun lien disponible pour ce guide.')).toBeNull();
  });

  it('« Aucun lien disponible » ne s’affiche que sur la ligne sans lien', () => {
    const base = avecLien('disponible', LIEN);
    const bloc: ContenusAccords = {
      ...base,
      guides: [...base.guides, { guide: 'guide-inconnu', date: '2026-10-01T10:00:00Z', nom: null, lien: null }],
    };
    render(<ContenusAccordsCard bloc={bloc} />);
    expect(screen.getAllByText('Aucun lien disponible pour ce guide.')).toHaveLength(1);
    expect(screen.getAllByRole('link', { name: /Voir le guide/ })).toHaveLength(2);
    expect(screen.getByText(/la même pour tout le monde/)).toBeTruthy();
  });
});
