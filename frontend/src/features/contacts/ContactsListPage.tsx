import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { PageShell } from '@/components/ui/PageShell';
import { EmptyState } from '@/components/ui/EmptyState';
import { CompaniesTableSkeleton } from '@/components/ui/Skeleton';
import { api } from '@/lib/api';

interface Contact {
  id: number; first_name?: string|null; last_name: string; role?: string|null;
  email?: string|null; email_status?: string|null; email_score?: number|null;
  phone?: string|null; linkedin_url?: string|null; discovery_source?: string|null;
}

export function ContactsListPage() {
  const [emailStatus, setEmailStatus] = useState('');
  const [search, setSearch] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['contacts', emailStatus, search],
    queryFn: async () => {
      const params = new URLSearchParams({ per_page: '50' });
      if (emailStatus) params.set('filter[email_status]', emailStatus);
      if (search) params.set('filter[last_name]', search);
      return (await api.get<{ data: Contact[]; meta: { total: number } }>(`/contacts?${params}`)).data;
    },
  });

  return (
    <PageShell title="Contacts" subtitle={`${data?.meta.total ?? '…'} décideurs identifiés (waterfall + Direction Finder)`}>
      <div className="mb-4 flex gap-2">
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Nom de famille…"
          aria-label="Recherche nom"
          className="flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm"
        />
        <select
          value={emailStatus}
          onChange={(e) => setEmailStatus(e.target.value)}
          aria-label="Filtre statut email"
          className="rounded-md border border-slate-300 px-3 py-1.5 text-sm"
        >
          <option value="">Tous statuts email</option>
          <option value="valid">Valide</option>
          <option value="catchall">Catch-all</option>
          <option value="unknown">Inconnu</option>
          <option value="invalid">Invalide</option>
          <option value="role">Role</option>
          <option value="disposable">Jetable</option>
        </select>
      </div>

      {isLoading ? <CompaniesTableSkeleton rows={6} />
        : (data?.data ?? []).length === 0 ? (
          <EmptyState icon="👤" title="Aucun contact" description="Lance l'enrichissement d'entreprises depuis la liste Entreprises." />
        ) : (
          <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase text-slate-600">
                <tr>
                  <th className="px-4 py-3">Nom</th>
                  <th className="px-4 py-3">Rôle</th>
                  <th className="px-4 py-3">Email</th>
                  <th className="px-4 py-3">Score</th>
                  <th className="px-4 py-3">Téléphone</th>
                  <th className="px-4 py-3">Source</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data!.data.map((c) => (
                  <tr key={c.id} className="hover:bg-slate-50">
                    <td className="px-4 py-2 font-medium">{[c.first_name, c.last_name].filter(Boolean).join(' ')}</td>
                    <td className="px-4 py-2 text-slate-600">{c.role ?? '—'}</td>
                    <td className="px-4 py-2">{c.email ?? '—'}</td>
                    <td className="px-4 py-2">
                      {c.email_score !== null && c.email_score !== undefined
                        ? <span className={`rounded px-1.5 py-0.5 text-xs ${c.email_score >= 70 ? 'bg-emerald-100 text-emerald-800' : c.email_score >= 40 ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800'}`}>{c.email_score}</span>
                        : '—'}
                    </td>
                    <td className="px-4 py-2 text-slate-600">{c.phone ?? '—'}</td>
                    <td className="px-4 py-2 text-xs text-slate-500">{c.discovery_source ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
    </PageShell>
  );
}
