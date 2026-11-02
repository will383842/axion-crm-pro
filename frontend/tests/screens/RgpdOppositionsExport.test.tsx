/**
 * Export du registre des oppositions (DM1-COM) — page « Demandes RGPD ».
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { RgpdRequestsPage } from '@/features/rgpd/RgpdRequestsPage';
import { handlers } from '@/demo/handlers/socle/ecrans-communs.handlers';
import { renderScreen } from '../helpers/renderScreen';

afterEach(() => vi.restoreAllMocks());

describe('Demandes RGPD : registre des oppositions', () => {
  it('« Exporter le registre des oppositions » produit un fichier CSV', async () => {
    const user = userEvent.setup();
    const blobs: Blob[] = [];
    Object.defineProperty(URL, 'createObjectURL', { configurable: true, writable: true, value: (b: Blob) => { blobs.push(b); return 'blob:demo'; } });
    Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, writable: true, value: () => undefined });
    const clic = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
    await renderScreen(<RgpdRequestsPage />, { path: '/rgpd/requests', handlers });
    await user.click(await screen.findByRole('button', { name: 'Exporter le registre des oppositions' }));
    await waitFor(() => expect(blobs).toHaveLength(1));
    expect(clic).toHaveBeenCalled();
    expect(await blobs[0]!.text()).toMatch(/^Valeur;Portée;Motif;Inscrite le/);
  });
});
