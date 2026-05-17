import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { PageShell } from '@/components/ui/PageShell';
import { FormField } from '@/components/ui/FormField';
import { DarkModeToggle } from '@/components/ui/DarkModeToggle';
import { api } from '@/lib/api';
import { toast } from 'sonner';

interface Workspace {
  id: string; name: string; slug: string; cost_cap_eur: number;
  settings: Record<string, unknown>;
}

export function SettingsPage() {
  const qc = useQueryClient();
  const [tab, setTab] = useState<'workspace' | 'integrations' | 'observability' | 'appearance'>('workspace');

  const ws = useQuery({
    queryKey: ['workspace'],
    queryFn: async () => (await api.get<Workspace>('/workspace')).data,
  });

  const updateMut = useMutation({
    mutationFn: async (patch: Partial<Workspace>) => (await api.put('/workspace', patch)).data,
    onSuccess: () => {
      toast.success('Workspace mis à jour');
      qc.invalidateQueries({ queryKey: ['workspace'] });
    },
    onError: () => toast.error('Erreur mise à jour'),
  });

  return (
    <PageShell title="Paramètres" subtitle="Workspace, intégrations, observabilité, apparence.">
      <div className="mb-6 flex gap-2 border-b border-slate-200">
        {([
          ['workspace', 'Workspace'],
          ['integrations', 'Intégrations'],
          ['observability', 'Observabilité'],
          ['appearance', 'Apparence'],
        ] as const).map(([k, l]) => (
          <button
            key={k}
            onClick={() => setTab(k)}
            className={`-mb-px border-b-2 px-3 py-2 text-sm ${tab === k ? 'border-brand-600 font-medium text-brand-700' : 'border-transparent text-slate-700'}`}
          >{l}</button>
        ))}
      </div>

      {tab === 'workspace' && (
        <div className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-4 text-sm font-semibold uppercase text-slate-600">Workspace</h2>
          {ws.data ? (
            <form onSubmit={(e) => { e.preventDefault();
              const fd = new FormData(e.currentTarget);
              updateMut.mutate({
                name: String(fd.get('name') ?? ''),
                cost_cap_eur: Number(fd.get('cost_cap_eur') ?? 0),
              });
            }}>
              <FormField name="name" label="Nom" defaultValue={ws.data.name} required />
              <FormField name="slug" label="Slug (URL)" defaultValue={ws.data.slug} disabled helpText="Non modifiable" />
              <FormField
                name="cost_cap_eur"
                label="Plafond LLM mensuel (€)"
                type="number"
                step="0.01"
                defaultValue={String(ws.data.cost_cap_eur)}
                helpText="Kill-switch automatique LLM quand atteint"
              />
              <button type="submit" disabled={updateMut.isPending}
                className="rounded-md bg-brand-600 px-3 py-1.5 text-sm text-white disabled:opacity-50">
                {updateMut.isPending ? 'Enregistrement…' : 'Enregistrer'}
              </button>
            </form>
          ) : <p className="text-sm text-slate-500">Chargement…</p>}
        </div>
      )}

      {tab === 'integrations' && (
        <div className="space-y-4">
          {[
            { name: 'INSEE Sirene', env: 'INSEE_API_KEY', status: '⚙️ configurable via .env' },
            { name: 'France Travail', env: 'FRANCE_TRAVAIL_CLIENT_ID', status: '⚙️ configurable via .env' },
            { name: 'Anthropic Claude', env: 'ANTHROPIC_API_KEY', status: '⚙️ optionnel (fallback Mistral)' },
            { name: 'Mistral AI', env: 'MISTRAL_API_KEY', status: '⚙️ configurable via .env' },
            { name: 'Webshare proxies', env: 'WEBSHARE_API_KEY', status: '⚙️ Sprint scrape Google+' },
            { name: 'IPRoyal proxies', env: 'IPROYAL_USERNAME', status: '⚙️ Sprint scrape Google+' },
            { name: '2captcha', env: 'TWOCAPTCHA_API_KEY', status: '⚙️ Sprint scrape Google+' },
          ].map((i) => (
            <div key={i.env} className="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4">
              <div>
                <p className="font-medium">{i.name}</p>
                <p className="text-xs font-mono text-slate-500">{i.env}</p>
              </div>
              <span className="text-xs text-slate-600">{i.status}</span>
            </div>
          ))}
        </div>
      )}

      {tab === 'observability' && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {[
            ['Prometheus', 'http://localhost:9090'],
            ['Grafana', 'http://localhost:3000'],
            ['Loki logs', 'http://localhost:3100'],
            ['Tempo traces', 'http://localhost:3200'],
            ['GlitchTip errors', 'http://localhost:8080'],
            ['Uptime Kuma', 'http://localhost:3001'],
            ['Horizon', '/horizon'],
            ['Telescope', '/telescope (local only)'],
          ].map(([name, url]) => (
            <a key={name} href={url} target="_blank" rel="noopener noreferrer"
              className="rounded-xl border border-slate-200 bg-white p-4 hover:border-brand-300">
              <p className="text-sm font-medium">{name}</p>
              <p className="mt-1 text-xs font-mono text-slate-500">{url}</p>
            </a>
          ))}
        </div>
      )}

      {tab === 'appearance' && (
        <div className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-4 text-sm font-semibold uppercase text-slate-600">Thème</h2>
          <div className="flex items-center gap-4">
            <span className="text-sm">Mode :</span>
            <DarkModeToggle />
          </div>
        </div>
      )}
    </PageShell>
  );
}
