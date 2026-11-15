/**
 * G4-UI (D14) — le badge « opposée » dans les écrans CRM : liste des contacts, liste des personnes, fiche d'une personne.
 *
 * Le serveur (G4-API) rend `opposition: { opposee, motifs }`, sans date ni contenu. Le badge porte le MOT « opposée »
 * (jamais une couleur seule) et une infobulle qui REGROUPE les motifs. Une adresse non opposée n'a pas de badge.
 *
 * Adresses en `example.invalid` uniquement (dépôt PUBLIC).
 */
import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { BadgeOpposee } from '@/features/crm-console/BadgeOpposee';
import { ContactsListPage } from '@/features/contacts/ContactsListPage';
import { PersonnesPage } from '@/features/crm-console/PersonnesPage';
import { PersonneDetailPage } from '@/features/crm-console/PersonneDetailPage';
import type { PersonneFiche, PersonneRow, PersonnesCounts } from '@/features/crm-console/types';
import { renderScreen } from '../helpers/renderScreen';
import { getJson } from '../msw/handlers';

const OPPOSEE = { opposee: true, motifs: ['stop', 'art21'] };
const LIBRE = { opposee: false, motifs: [] };

function personne(overrides: Partial<PersonneRow> = {}): PersonneRow {
  return {
    id: 12,
    person_key: 'a'.repeat(64),
    email: 'zz.opposee@example.invalid',
    first_name: null,
    last_name: null,
    email_nature: 'pro',
    locale: 'fr',
    premiere_source: 'guide-ia',
    premiere_source_at: '2026-09-24T10:00:00Z',
    derniere_interaction_at: '2026-09-24T10:00:00Z',
    legal_basis: 'legitimate_interest_b2b',
    statut_lettre: null,
    base_lettre: null,
    prospection_autorisee: true,
    placement: null,
    rattachee: false,
    contact_id: null,
    company_id: null,
    entreprise: null,
    purge_prevue_le: null,
    opposition: OPPOSEE,
    ...overrides,
  };
}

const COUNTS: PersonnesCounts = {
  total: 2,
  by_statut_lettre: { abonne: 0, desabonne: 0, aucun: 2 },
  by_nature: { pro: 2, perso: 0, inconnue: 0 },
  by_source: { 'guide-ia': 2 },
  rattachees: 0,
  non_rattachees: 2,
};
const CURSEUR = { per_page: 50, next_cursor: null, prev_cursor: null, has_more: false };

describe('BadgeOpposee — composant', () => {
  it('affiche le mot « opposée » et une infobulle qui regroupe les motifs', () => {
    render(<BadgeOpposee opposition={OPPOSEE} />);
    const badge = screen.getByText('opposée');
    expect(badge).toBeVisible();
    const infobulle = badge.closest('[title]')?.getAttribute('title') ?? '';
    expect(infobulle).toContain('STOP');
    expect(infobulle).toContain('art. 21');
  });

  it('une liste de refus seule : motif « liste de refus »', () => {
    render(<BadgeOpposee opposition={{ opposee: true, motifs: ['liste_refus'] }} />);
    expect(screen.getByText('opposée').closest('[title]')?.getAttribute('title')).toContain('liste de refus');
  });

  it('rien du tout quand l’adresse n’est pas opposée, ou quand l’ancienne API n’envoie rien', () => {
    const { container, rerender } = render(<BadgeOpposee opposition={LIBRE} />);
    expect(container.textContent).toBe('');
    rerender(<BadgeOpposee opposition={undefined} />);
    expect(container.textContent).toBe('');
  });

  it('n’affiche jamais un motif inconnu tel quel (pas de slug technique à l’écran)', () => {
    render(<BadgeOpposee opposition={{ opposee: true, motifs: ['slug_inconnu'] }} />);
    expect(screen.getByText('opposée').closest('[title]')?.getAttribute('title') ?? '').not.toContain('slug_inconnu');
  });
});

describe('BadgeOpposee — liste des contacts', () => {
  const contacts = {
    data: [
      { id: 1, last_name: 'ZZ OPPOSE', email: 'zz.oppose@example.invalid', opposition: OPPOSEE },
      { id: 2, last_name: 'ZZ LIBRE', email: 'zz.libre@example.invalid', opposition: LIBRE },
    ],
    meta: { total: 2 },
  };

  it('badge sur la ligne opposée seulement, et il reste après un filtre', async () => {
    await renderScreen(<ContactsListPage />, { path: '/contacts', handlers: [getJson('/contacts', contacts)] });
    expect(await screen.findByText('zz.oppose@example.invalid')).toBeVisible();
    expect(screen.getAllByText('opposée')).toHaveLength(1);

    await userEvent.selectOptions(screen.getByLabelText('Statut de l’e-mail'), 'valid');
    await waitFor(() => expect(screen.getAllByText('opposée')).toHaveLength(1));
    expect(screen.getByText('zz.oppose@example.invalid')).toBeVisible();
  });
});

describe('BadgeOpposee — liste des personnes', () => {
  it('badge sur la personne opposée, et il reste après un filtre', async () => {
    const liste = {
      data: [personne(), personne({ id: 13, email: 'zz.libre@example.invalid', opposition: LIBRE })],
      meta: CURSEUR,
    };
    await renderScreen(<PersonnesPage />, {
      path: '/console/lettre-et-guide',
      url: '/console/lettre-et-guide',
      consoleFeatures: 'open',
      handlers: [getJson('/crm/personnes', liste), getJson('/crm/personnes/counts', COUNTS)],
    });
    expect(await screen.findByText('zz.opposee@example.invalid')).toBeVisible();
    expect(screen.getAllByText('opposée')).toHaveLength(1);

    await userEvent.selectOptions(screen.getByLabelText('Newsletter'), 'aucun');
    await waitFor(() => expect(screen.getAllByText('opposée')).toHaveLength(1));
  });
});

describe('BadgeOpposee — fiche d’une personne', () => {
  const fiche = (p: PersonneRow): PersonneFiche => ({ personne: p, abonnement: null, entreprise: null, taches: [], timeline: [] });

  async function monter(p: PersonneRow) {
    await renderScreen(<PersonneDetailPage />, {
      path: '/console/lettre-et-guide/$personneId',
      url: '/console/lettre-et-guide/12',
      consoleFeatures: 'open',
      handlers: [getJson('/crm/personnes/12', fiche(p))],
    });
  }

  it('la fiche opposée porte le badge avec l’infobulle du motif regroupé', async () => {
    await monter(personne());
    const badge = await screen.findByText('opposée');
    expect(badge).toBeVisible();
    const infobulle = badge.closest('[title]')?.getAttribute('title') ?? '';
    expect(infobulle).toContain('STOP');
    expect(infobulle).toContain('art. 21');
  });

  it('la fiche non opposée n’a pas de badge', async () => {
    await monter(personne({ opposition: LIBRE }));
    expect((await screen.findAllByText('zz.opposee@example.invalid')).length).toBeGreaterThan(0);
    expect(screen.queryByText('opposée')).toBeNull();
  });
});
