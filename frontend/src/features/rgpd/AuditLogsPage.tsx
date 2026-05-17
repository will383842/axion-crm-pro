import { useQuery, useMutation } from '@tanstack/react-query';
import { PageShell } from '@/components/ui/PageShell';
import { CompaniesTableSkeleton } from '@/components/ui/Skeleton';
import { api } from '@/lib/api';
import { toast } from 'sonner';

interface AuditLog {
  id: number; event_type: string; path?: string|null; status_code?: number|null;
  ip?: string|null; user_agent?: string|null; created_at: string;
  current_hash: string;
}

export function AuditLogsPage() {
  const list = useQuery({
    queryKey: ['audit-logs'],
    queryFn: async () => (await api.get<{ data: AuditLog[] }>('/audit-logs')).data,
  });

  const verifyMut = useMutation({
    mutationFn: async () => (await api.get<{ valid: boolean }>('/audit-logs/verify-chain')).data,
    onSuccess: (r) => {
      r.valid
        ? toast.success('Chaîne d\'audit VALIDE — aucune anomalie détectée.')
        : toast.error('Chaîne d\'audit INVALIDE — possible falsification.');
    },
    onError: () => toast.error('Erreur vérification chaîne'),
  });

  return (
    <PageShell
      title="Audit logs"
      subtitle="Journal append-only avec chaîne cryptographique SHA-256 vérifiable."
      actions={
        <button
          onClick={() => verifyMut.mutate()}
          disabled={verifyMut.isPending}
          className="rounded-md bg-brand-600 px-3 py-1.5 text-sm text-white disabled:opacity-50"
        >
          {verifyMut.isPending ? 'Vérification…' : 'Vérifier la chaîne'}
        </button>
      }
    >
      {list.isLoading ? <CompaniesTableSkeleton rows={10} />
        : (
          <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase text-slate-600">
                <tr>
                  <th className="px-4 py-3">Quand</th>
                  <th className="px-4 py-3">Event</th>
                  <th className="px-4 py-3">Path</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">IP</th>
                  <th className="px-4 py-3">Hash</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {(list.data?.data ?? []).map((l) => (
                  <tr key={l.id}>
                    <td className="px-4 py-2 text-xs">{new Date(l.created_at).toLocaleString('fr-FR')}</td>
                    <td className="px-4 py-2 font-medium">{l.event_type}</td>
                    <td className="px-4 py-2 font-mono text-xs">{l.path ?? '—'}</td>
                    <td className="px-4 py-2">
                      <span className={`rounded px-1.5 py-0.5 text-xs ${
                        (l.status_code ?? 200) < 400 ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                      }`}>{l.status_code}</span>
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">{l.ip ?? '—'}</td>
                    <td className="px-4 py-2 font-mono text-xs text-slate-400">{l.current_hash.slice(0, 12)}…</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
    </PageShell>
  );
}
