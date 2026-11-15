/**
 * G4-ACCORD (D15) — section « Accord donné » de la fiche personne, en lecture seule.
 * Valeurs fictives (dépôt PUBLIC). Jamais `commentaire_ref`, `post_ref` ni `post_url` dans le DOM.
 */
import { describe, it, expect } from 'vitest';
import { render, screen, within } from '@testing-library/react';

import { ContenusAccordsCard } from '@/features/crm-console/ContenusAccordsCard';
import type { ContenusAccords } from '@/features/crm-console/types';

const avecAccord: ContenusAccords = {
  origine: { mot: 'guide-ia', reseau: 'reseau-a', guide: null, date: '2026-11-02T09:58:00Z' },
  guides: [],
  consentements: [{ nature: 'optin', date: '2026-11-03T10:00:00Z', source: 'confirmation', version: 'M2' }],
  opposition: { actif: false, date: null },
};

const sansAccord: ContenusAccords = { origine: null, guides: [], consentements: [], opposition: { actif: false, date: null } };

describe('Accord donné (G4-ACCORD)', () => {
  it('montre date, source et version du texte accepté, et l’origine', () => {
    render(<ContenusAccordsCard bloc={avecAccord} />);
    const section = screen.getByTestId('accord-donne');
    expect(within(section).getByText('Accord donné')).toBeTruthy();
    expect(section.textContent).toContain('03/11/2026');
    expect(section.textContent).toContain('confirmation');
    expect(section.textContent).toContain('version du texte : M2');
    expect(section.textContent).toContain('guide-ia');
    expect(section.textContent).toContain('02/11/2026');
  });

  it('optin puis retrait : la section montre le retrait, jamais un accord nu', () => {
    const bloc: ContenusAccords = {
      ...avecAccord,
      consentements: [
        { nature: 'optin', date: '2026-11-03T10:00:00Z', source: 'confirmation', version: 'M2' },
        { nature: 'retrait', date: '2026-11-10T10:00:00Z', source: 'lien', version: 'M2' },
      ],
    };
    render(<ContenusAccordsCard bloc={bloc} />);
    const texte = screen.getByTestId('accord-donne').textContent ?? '';
    expect(texte).toContain('retiré le 10/11/2026');
    expect(texte).not.toMatch(/accord reçu le 03\/11\/2026 · source : confirmation · version du texte : M2(?!.*retiré)/);
  });

  it('optin + opposition : la section montre l’opposition enregistrée', () => {
    const bloc: ContenusAccords = { ...avecAccord, opposition: { actif: true, date: '2026-11-12T10:00:00Z' } };
    render(<ContenusAccordsCard bloc={bloc} />);
    const texte = screen.getByTestId('accord-donne').textContent ?? '';
    expect(texte).toContain('opposition enregistrée');
    expect(texte).toContain('12/11/2026');
  });

  it('sans origine : la ligne « Origine — » reste', () => {
    render(<ContenusAccordsCard bloc={sansAccord} />);
    expect(screen.getByTestId('accord-donne').textContent).toContain('Origine—');
  });

  it('sans accord : « Aucun accord enregistré », jamais une valeur inventée', () => {
    render(<ContenusAccordsCard bloc={sansAccord} />);
    expect(within(screen.getByTestId('accord-donne')).getByText('Aucun accord enregistré')).toBeTruthy();
  });

  it('ancienne API sans bloc : « Aucun accord enregistré »', () => {
    render(<ContenusAccordsCard bloc={undefined} />);
    expect(screen.getByText('Aucun accord enregistré')).toBeTruthy();
  });

  it('GARDE avec témoin : commentaire_ref, post_ref et post_url ne sont jamais dans le DOM', () => {
    const interdits = {
      commentaire_ref: 'COMM-REF-0001',
      post_ref: 'POST-REF-0002',
      post_url: 'https://reseau.example.test/post/0003',
    };
    const bloc = {
      ...avecAccord,
      origine: { ...avecAccord.origine!, ...interdits },
      consentements: [{ ...avecAccord.consentements[0]!, ...interdits }],
    } as unknown as ContenusAccords;
    const { container } = render(<ContenusAccordsCard bloc={bloc} />);
    for (const valeur of Object.values(interdits)) expect(container.innerHTML).not.toContain(valeur);

    // Témoin : un rendu fautif qui afficherait le champ fait rougir la même garde.
    const fautif = render(<p>{interdits.commentaire_ref}</p>);
    expect(() => {
      for (const valeur of Object.values(interdits)) expect(fautif.container.innerHTML).not.toContain(valeur);
    }).toThrow();
  });
});
