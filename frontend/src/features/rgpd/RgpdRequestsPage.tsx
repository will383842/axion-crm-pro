import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { PageShell } from '@/components/ui/PageShell';
import { EmptyState } from '@/components/ui/EmptyState';
import { CompaniesTableSkeleton } from '@/components/ui/Skeleton';
import { api } from '@/lib/api';
import { toast } from 'sonner';

interface RgpdRequest {
  id: number;
  type: 'access'|'portability'|'erasure'|'rectification'|'opposition';
  status: 'pending'|'processing'|'done'|'rejected'|'expired';
  subject_email: string;
  requested_at: string;
  processed_at?: string|null;
}

export function RgpdRequestsPage() {
  const qc = useQueryClient();
  const [form, setForm] = useState({ type: 'erasure', subject_email: '' });

  const list = useQuery({
    queryKey: ['rgpd-requests'],
    queryFn: async () => (await api.get<{ data: RgpdRequest[] }>('/rgpd/requests')).data,
  });

  const createMut = useMutation({
    mutationFn: async () => (await api.post('/rgpd/requests', form)).data,
    onSuccess: () => {
      toast.success('Requête RGPD créée');
      setForm({ type: 'erasure', subject_email: '' });
      qc.invalidateQueries({ queryKey: ['rgpd-requests'] });
    },
    onError: () => toast.error('Erreur création requête'),
  });

  const processMut = useMutation({
    mutationFn: async (id: number) => (await api.post(`/rgpd/requests/${id}/process`)).data,
    onSuccess: () => {
      toast.success('Requête traitée');
      qc.invalidateQueries({ queryKey: ['rgpd-requests'] });
    },
  });

  return (
    <PageShell title="Requêtes RGPD" subtitle="Articles 15-22 : accès / portabilité / suppression / rectification / opposition.">
      <section className="mb-6 rounded-xl border border-slate-200 bg-white p-5">
        <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">Nouvelle requête</h2>
        <form
          onSubmit={(e) => { e.preventDefault(); createMut.mutate(); }}
          className="flex flex-wrap gap-2"
        >
          <select
            value={form.type}
            onChange={(e) => setForm({ ...form, type: e.target.value })}
            aria-label="Type de requête"
            className="rounded-md border border-slate-300 px-3 py-1.5 text-sm"
          >
            <option value="access">Accès (art. 15)</option>
            <option value="portability">Portabilité (art. 20)</option>
            <option value="erasure">Suppression (art. 17)</option>
            <option value="rectification">Rectification (art. 16)</option>
            <option value="opposition">Opposition (art. 21)</option>
          </select>
          <input
            type="email"
            value={form.subject_email}
            onChange={(e) => setForm({ ...form, subject_email: e.target.value })}
            placeholder="email du sujet"
            required
            aria-label="Email du sujet"
            className="flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm"
          />
          <button
            type="submit"
            disabled={createMut.isPending || !form.subject_email}
            className="rounded-md bg-brand-600 px-3 py-1.5 text-sm text-white disabled:opacity-50"
          >
            Créer
          </button>
        </form>
      </section>

      {list.isLoading ? <CompaniesTableSkeleton rows={5} />
        : (list.data?.data ?? []).length === 0 ? (
          <EmptyState icon="📋" title="Aucune requête RGPD" description="Les requêtes apparaitront ici après création par les sujets concernés." />
        ) : (
          <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase text-slate-600">
                <tr><th className="px-4 py-3">Type</th><th>Sujet</th><th>Statut</th><th>Demande</th><th>Traitement</th><th></th></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {list.data!.data.map((r) => (
                  <tr key={r.id}>
                    <td className="px-4 py-2 font-medium">{r.type}</td>
                    <td className="px-4 py-2">{r.subject_email}</td>
                    <td className="px-4 py-2">
                      <span className={`rounded px-2 py-0.5 text-xs ${
                        r.status === 'done' ? 'bg-emerald-100 text-emerald-800'
                        : r.status === 'pending' ? 'bg-amber-100 text-amber-800'
                        : r.status === 'rejected' ? 'bg-rose-100 text-rose-800'
                        : 'bg-slate-100 text-slate-700'
                      }`}>{r.status}</span>
                    </td>
                    <td className="px-4 py-2 text-xs">{new Date(r.requested_at).toLocaleString('fr-FR')}</td>
                    <td className="px-4 py-2 text-xs">{r.processed_at ? new Date(r.processed_at).toLocaleString('fr-FR') : '—'}</td>
                    <td className="px-4 py-2">
                      {r.status === 'pending' && (
                        <button
                          onClick={() => processMut.mutate(r.id)}
                          className="rounded bg-brand-600 px-2 py-1 text-xs text-white"
                        >
                          Traiter
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
    </PageShell>
  );
}
