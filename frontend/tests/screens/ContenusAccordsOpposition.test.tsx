/**
 * CRM-OPP — fiche du contact : « accord donné » n'est jamais affiché seul quand une
 * opposition existe. L'opposition l'emporte, qu'elle soit reçue avant ou après l'accord.
 * Rien n'est supprimé. Valeurs fictives (dépôt PUBLIC).
 */
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';

import { ContenusAccordsCard } from '@/features/crm-console/ContenusAccordsCard';
import { libelleAccord } from '@/features/crm-console/libelleAccord';
import type { ContenusAccords } from '@/features/crm-console/types';

const ACCORD = { nature: 'optin', date: '2026-11-03T12:00:00Z', source: 'confirmation', version: 'M2' };
const base: ContenusAccords = {
  origine: null,
  guides: [],
  consentements: [ACCORD],
  opposition: { actif: false, date: null },
};
const apres: ContenusAccords['opposition'] = { actif: true, date: '2026-11-10T12:00:00Z' };
const avant: ContenusAccords['opposition'] = { actif: true, date: '2026-11-01T12:00:00Z' };

function texteAccord(bloc: ContenusAccords): string {
  render(<ContenusAccordsCard bloc={bloc} />);
  return screen.getByTestId('accord-libelle').textContent ?? '';
}

describe('CRM-OPP — libellé de l’accord', () => {
  it('accord puis opposition : « accord donné le X, retiré par opposition le Y »', () => {
    const t = texteAccord({ ...base, opposition: apres });
    expect(t).toContain('accord donné le 03/11/2026, retiré par opposition le 10/11/2026');
  });

  it('opposition puis accord : même résultat, l’opposition l’emporte toujours', () => {
    const t = texteAccord({ ...base, opposition: avant });
    expect(t).toContain('accord donné le 03/11/2026, retiré par opposition le 01/11/2026');
    expect(t).not.toMatch(/^donné/);
  });

  it('accord seul : « donné le X », sans mention d’opposition', () => {
    const t = texteAccord(base);
    expect(t).toContain('donné le 03/11/2026');
    expect(t).not.toContain('opposition');
  });

  it('opposition seule : aucun accord donné n’est affiché', () => {
    const t = texteAccord({ ...base, consentements: [], opposition: apres });
    expect(t).not.toMatch(/(^|· )(accord )?donné le/);
    expect(t).toContain('opposition reçue le 10/11/2026');
  });

  it('les données restent : le consentement et sa version de texte restent lisibles', () => {
    render(<ContenusAccordsCard bloc={{ ...base, opposition: apres }} />);
    expect(screen.getByText(/version M2/)).toBeTruthy();
    expect(screen.getByTestId('bandeau-opposition').textContent).toContain('Les données sont gardées');
  });

  it('fonction pure : jamais « donné » seul quand une opposition est active', () => {
    for (const opposition of [avant, apres]) {
      expect(libelleAccord([ACCORD], opposition)?.texte).toMatch(/retiré par opposition/);
    }
    expect(libelleAccord([], { actif: false, date: null })).toBeNull();
  });
});
