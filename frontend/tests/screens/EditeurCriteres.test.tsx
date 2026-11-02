/**
 * DM1-CIB — l'éditeur des critères ET / OU / SAUF.
 *
 * Le cœur : traduire des lignes « champ, opérateur, valeur » en `criteria`
 * (`all` = ET, `any` = OU, `not` = SAUF), relire le tout en une phrase, et
 * afficher la taille estimée donnée par le faux serveur de démo.
 */
import { useState } from 'react';
import { describe, it, expect } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { EditeurCriteres, etatVide, versCriteres, type EtatEditeur } from '@/features/audiences/EditeurCriteres';
import { handlers } from '@/demo/handlers/crm/ciblage.handlers';
import { renderScreen } from '../helpers/renderScreen';

function Banc({ surChangement }: { surChangement?: (e: EtatEditeur) => void }) {
  const [etat, setEtat] = useState<EtatEditeur>(etatVide());
  return (
    <EditeurCriteres
      valeur={etat}
      onChange={(e) => {
        setEtat(e);
        surChangement?.(e);
      }}
    />
  );
}

const ATTENTE = { timeout: 3000 } as const;

/** La ligne n° `n` (à partir de 1) du groupe désigné. */
function ligne(n: number): HTMLElement {
  return screen.getAllByRole('group', { name: /^Critère \d+/ })[n - 1]!;
}

describe('EditeurCriteres', () => {
  it('construit « secteur = BTP ET région = Auvergne-Rhône-Alpes SAUF étiquette = clients-2026 »', async () => {
    const user = userEvent.setup();
    let dernier: EtatEditeur = etatVide();
    await renderScreen(<Banc surChangement={(e) => (dernier = e)} />, { handlers });

    // Ligne 1 : ET Secteur est BTP.
    await user.selectOptions(within(ligne(1)).getByLabelText('Champ'), 'sector_main');
    await user.selectOptions(within(ligne(1)).getByLabelText('Valeur'), 'btp');

    // Ligne 2 : ET Région est Auvergne-Rhône-Alpes.
    await user.click(screen.getByRole('button', { name: '+ Critère ET' }));
    await user.selectOptions(within(ligne(2)).getByLabelText('Champ'), 'region_code');
    await user.selectOptions(within(ligne(2)).getByLabelText('Valeur'), '84');

    // Ligne 3 : SAUF Étiquette contient clients-2026.
    await user.click(screen.getByRole('button', { name: '+ SAUF' }));
    await user.selectOptions(within(ligne(3)).getByLabelText('Champ'), 'tags');
    await user.type(within(ligne(3)).getByLabelText('Valeur'), 'clients-2026');

    expect(versCriteres(dernier)).toEqual({
      all: [
        { field: 'sector_main', op: 'in', value: ['btp'] },
        { field: 'region_code', op: 'in', value: ['84'] },
      ],
      not: [{ field: 'tags', op: 'contains_any', value: ['clients-2026'] }],
    });

    // Relu en phrase.
    const phrase = screen.getByRole('status', { name: 'Critères en une phrase' });
    expect(phrase).toHaveTextContent(
      'Secteur est Bâtiment et travaux publics ET Région est Auvergne-Rhône-Alpes SAUF Étiquette contient clients-2026',
    );
  });

  it('la taille estimée se met à jour quand on ajoute un SAUF', async () => {
    const user = userEvent.setup();
    await renderScreen(<Banc />, { handlers });

    await user.selectOptions(within(ligne(1)).getByLabelText('Champ'), 'region_code');
    await user.selectOptions(within(ligne(1)).getByLabelText('Valeur'), '84');

    const taille = screen.getByRole('status', { name: 'Taille estimée' });
    await waitFor(() => expect(taille).toHaveTextContent(/\d+ fiches?/), ATTENTE);
    const avant = Number(/(\d+) fiche/.exec(taille.textContent ?? '')?.[1]);
    expect(avant).toBeGreaterThan(0);

    await user.click(screen.getByRole('button', { name: '+ SAUF' }));
    await user.selectOptions(within(ligne(2)).getByLabelText('Champ'), 'tags');
    await user.type(within(ligne(2)).getByLabelText('Valeur'), 'salon-artisans-2026');

    await waitFor(() => {
      const apres = Number(/(\d+) fiche/.exec(taille.textContent ?? '')?.[1]);
      expect(apres).toBeLessThan(avant);
    }, ATTENTE);
  });

  it('un groupe OU regroupe deux critères alternatifs', async () => {
    const user = userEvent.setup();
    let dernier: EtatEditeur = etatVide();
    await renderScreen(<Banc surChangement={(e) => (dernier = e)} />, { handlers });

    await user.click(screen.getByRole('button', { name: '+ Groupe OU' }));
    expect(screen.getAllByRole('group', { name: /^Critère \d+/ })).toHaveLength(3);
    await user.selectOptions(within(ligne(2)).getByLabelText('Champ'), 'tags');
    await user.type(within(ligne(2)).getByLabelText('Valeur'), 'a-rappeler');
    await user.selectOptions(within(ligne(3)).getByLabelText('Champ'), 'size_category');
    await user.selectOptions(within(ligne(3)).getByLabelText('Valeur'), 'pme');

    expect(versCriteres(dernier).any).toEqual([
      { field: 'tags', op: 'contains_any', value: ['a-rappeler'] },
      { field: 'size_category', op: 'in', value: ['pme'] },
    ]);
  });

  it('une ligne incomplète n’ajoute aucun critère, et on peut la retirer', async () => {
    const user = userEvent.setup();
    let dernier: EtatEditeur = etatVide();
    await renderScreen(<Banc surChangement={(e) => (dernier = e)} />, { handlers });

    await user.click(screen.getByRole('button', { name: '+ SAUF' }));
    expect(versCriteres(dernier)).toEqual({});
    await user.click(within(ligne(2)).getByRole('button', { name: 'Retirer ce critère' }));
    expect(screen.getAllByRole('group', { name: /^Critère \d+/ })).toHaveLength(1);
  });
});
