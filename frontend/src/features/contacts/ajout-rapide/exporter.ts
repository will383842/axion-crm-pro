/**
 * Export (CSV) de la sélection : seulement les fiches cochées et visibles,
 * jamais « tout ce qui correspond au filtre ».
 */
import { toast } from 'sonner';
import { api } from '@/lib/api';
import { messageServeur } from './types';

export async function exporterSelection(companyIds: number[], contactIds: number[]): Promise<void> {
  try {
    const r = await api.post<Blob>('/crm/contacts/export', { company_ids: companyIds, contact_ids: contactIds }, { responseType: 'blob' });
    const nombre = Number(r.headers['x-nombre-de-fiches'] ?? 0);
    if (typeof URL.createObjectURL === 'function') {
      const lien = document.createElement('a');
      lien.href = URL.createObjectURL(r.data);
      lien.download = 'contacts-selection.csv';
      document.body.appendChild(lien);
      lien.click();
      lien.remove();
      URL.revokeObjectURL(lien.href);
    }
    toast.success(`${nombre} ${nombre > 1 ? 'fiches exportées' : 'fiche exportée'}`);
  } catch (e) {
    toast.error(messageServeur(e) ?? 'L’export n’a pas pu se faire.');
  }
}
