import { useParams } from '@tanstack/react-router';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { PageShell } from '@/components/ui/PageShell';
import { QualityBadge } from '@/components/ui/QualityBadge';
import { SizeCategoryBadge } from '@/components/ui/SizeCategoryBadge';
import { EmptyState } from '@/components/ui/EmptyState';
import { api } from '@/lib/api';
import { toast } from 'sonner';

interface Contact {
  id: number;
  first_name?: string | null;
  last_name: string;
  role?: string | null;
  email?: string | null;
  email_status?: string | null;
  email_score?: number | null;
  phone?: string | null;
  linkedin_url?: string | null;
  discovery_source?: string | null;
}

interface CompanyDetail {
  id: number;
  siren: string;
  denomination?: string | null;
  naf?: string | null;
  legal_form?: string | null;
  effectif_range?: string | null;
  size_category?: string | null;
  address?: string | null;
  postcode?: string | null;
  city?: string | null;
  website?: string | null;
  phone?: string | null;
  linkedin_url?: string | null;
  quality_score?: number | null;
  priority?: string | null;
  signals?: Record<string, unknown>;
  enriched_at?: string | null;
  contacts?: Contact[];
}

export function CompanyDetailPage() {
  const qc = useQueryClient();
  const { companyId } = useParams({ strict: false }) as { companyId?: string };

  const { data: c, isLoading } = useQuery({
    queryKey: ['company', companyId],
    queryFn: async () => (await api.get<CompanyDetail>(`/companies/${companyId}`)).data,
    enabled: !!companyId,
  });

  const enrichMut = useMutation({
    mutationFn: async () => (await api.post(`/companies/${companyId}/enrich`)).data,
    onSuccess: () => {
      toast.success('Enrichissement lancé');
      qc.invalidateQueries({ queryKey: ['company', companyId] });
    },
    onError: () => toast.error('Échec lancement enrichissement'),
  });

  if (isLoading) return <PageShell title="Chargement…" />;
  if (!c) return <PageShell title="Entreprise introuvable"><EmptyState title="404" description="Cette entreprise n'existe pas ou a été supprimée." /></PageShell>;

  return (
    <PageShell
      title={c.denomination ?? c.siren}
      subtitle={`SIREN ${c.siren} · ${c.naf ?? '—'} · ${c.legal_form ?? '—'}`}
      actions={
        <button
          onClick={() => enrichMut.mutate()}
          disabled={enrichMut.isPending}
          className="rounded-md bg-brand-600 px-3 py-1.5 text-sm text-white hover:bg-brand-700 disabled:opacity-50"
        >
          {enrichMut.isPending ? 'Enrichissement…' : 'Relancer enrichissement'}
        </button>
      }
    >
      <div className="grid gap-6 lg:grid-cols-3">
        <section className="lg:col-span-2 rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-600">Identité</h2>
          <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <Item label="Taille"><SizeCategoryBadge size={c.size_category} /></Item>
            <Item label="Effectif INSEE">{c.effectif_range ?? '—'}</Item>
            <Item label="Qualité"><QualityBadge score={c.quality_score} /></Item>
            <Item label="Priorité">{c.priority ?? '—'}</Item>
            <Item label="Adresse">{[c.address, c.postcode, c.city].filter(Boolean).join(', ') || '—'}</Item>
            <Item label="Site web">{c.website ? <a className="text-brand-600 hover:underline" href={c.website} target="_blank" rel="noopener noreferrer">{c.website}</a> : '—'}</Item>
            <Item label="Téléphone">{c.phone ?? '—'}</Item>
            <Item label="LinkedIn">{c.linkedin_url ? <a className="text-brand-600 hover:underline" href={c.linkedin_url} target="_blank" rel="noopener noreferrer">Voir →</a> : '—'}</Item>
            <Item label="Enrichi le">{c.enriched_at ? new Date(c.enriched_at).toLocaleString('fr-FR') : 'Jamais'}</Item>
          </dl>
        </section>

        <aside className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-600">Signaux</h2>
          {c.signals && Object.keys(c.signals).length > 0 ? (
            <pre className="overflow-auto rounded bg-slate-50 p-3 text-xs">{JSON.stringify(c.signals, null, 2)}</pre>
          ) : (
            <p className="text-sm text-slate-500">Aucun signal détecté.</p>
          )}
        </aside>
      </div>

      <section className="mt-6 rounded-xl border border-slate-200 bg-white p-5">
        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-600">Contacts ({c.contacts?.length ?? 0})</h2>
        {(c.contacts ?? []).length === 0 ? (
          <p className="text-sm text-slate-500">Aucun contact identifié.</p>
        ) : (
          <table className="min-w-full text-sm">
            <thead className="text-left text-xs uppercase text-slate-500">
              <tr><th className="py-2">Nom</th><th>Rôle</th><th>Email</th><th>Score</th><th>Source</th></tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {c.contacts!.map((ct) => (
                <tr key={ct.id}>
                  <td className="py-2 font-medium">{[ct.first_name, ct.last_name].filter(Boolean).join(' ')}</td>
                  <td className="text-slate-600">{ct.role ?? '—'}</td>
                  <td className="text-slate-700">{ct.email ?? '—'}</td>
                  <td>
                    {ct.email_score !== null && ct.email_score !== undefined ? (
                      <span className={`rounded px-1.5 py-0.5 text-xs ${ct.email_score >= 70 ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}`}>
                        {ct.email_score}
                      </span>
                    ) : '—'}
                  </td>
                  <td className="text-xs text-slate-500">{ct.discovery_source ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </PageShell>
  );
}

function Item({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs uppercase text-slate-500">{label}</dt>
      <dd className="mt-0.5 text-slate-900">{children}</dd>
    </div>
  );
}
