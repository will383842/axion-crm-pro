import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Activity,
  Briefcase,
  Eye,
  EyeOff,
  ExternalLink,
  KeyRound,
  Palette,
  Plug,
  RefreshCw,
  Settings as SettingsIcon,
} from 'lucide-react';
import {
  Button,
  Card,
  CardEyebrow,
  CardHeader,
  CardTitle,
  DarkModeToggle,
  Input,
  PageHeader,
  StatusPill,
  Tabs,
  type TabItem,
} from '@/components/ui';
import { api } from '@/lib/api';
import { toast } from 'sonner';

interface Workspace {
  id: string;
  name: string;
  slug: string;
  cost_cap_eur: number;
  settings: Record<string, unknown>;
}

type TabKey = 'workspace' | 'integrations' | 'observability' | 'appearance';

interface Integration {
  name: string;
  env: string;
  description: string;
  status: 'configured' | 'optional' | 'pending';
}

const INTEGRATIONS: Integration[] = [
  { name: 'INSEE Sirene', env: 'INSEE_API_KEY', description: 'Base entreprises + données légales (gratuit, 500 req/min)', status: 'configured' },
  { name: 'France Travail', env: 'FRANCE_TRAVAIL_CLIENT_ID', description: 'Offres d\'emploi + intentions de recrutement (gratuit)', status: 'configured' },
  { name: 'Mistral AI', env: 'MISTRAL_API_KEY', description: 'LLM principal classification entreprises (FR souverain, ~5€/mois)', status: 'configured' },
  { name: 'Anthropic Claude', env: 'ANTHROPIC_API_KEY', description: 'LLM premium pour use cases stratégiques (optionnel)', status: 'optional' },
  // Sprint H9 + H12 — Google Places API officielle (enrichissement auto, garde-fou quota)
  { name: 'Google Places API', env: 'GOOGLE_PLACES_API_KEY', description: 'Enrichissement auto téléphone/horaires/site/note Google (gratuit ≤12K/mois via crédit $200, garde-fou quota actif)', status: 'optional' },
  { name: 'Webshare proxies', env: 'WEBSHARE_USERNAME', description: 'Proxies résidentiels pour Pages Jaunes (~$30/mois, Phase B optionnelle)', status: 'pending' },
  { name: '2captcha', env: 'TWOCAPTCHA_API_KEY', description: 'Résolution captcha (Phase B, uniquement si scraping Google direct)', status: 'pending' },
];

const OBSERVABILITY_LINKS: Array<{ name: string; url: string; description: string }> = [
  { name: 'Prometheus', url: 'http://localhost:9090', description: 'Métriques + alertes' },
  { name: 'Grafana', url: 'http://localhost:3000', description: 'Dashboards visuels' },
  { name: 'Loki logs', url: 'http://localhost:3100', description: 'Agrégateur de logs' },
  { name: 'Tempo traces', url: 'http://localhost:3200', description: 'Traces distribuées' },
  { name: 'GlitchTip errors', url: 'http://localhost:8080', description: 'Errors Sentry-compatible' },
  { name: 'Uptime Kuma', url: 'http://localhost:3001', description: 'Probes uptime' },
  { name: 'Horizon', url: '/horizon', description: 'Workers queue Laravel' },
  { name: 'Telescope', url: '/telescope', description: 'Debug local uniquement' },
];

const TABS: Array<TabItem<TabKey>> = [
  { id: 'workspace', label: 'Workspace', icon: <Briefcase className="h-3.5 w-3.5" /> },
  { id: 'integrations', label: 'Intégrations', icon: <Plug className="h-3.5 w-3.5" /> },
  { id: 'observability', label: 'Observabilité', icon: <Activity className="h-3.5 w-3.5" /> },
  { id: 'appearance', label: 'Apparence', icon: <Palette className="h-3.5 w-3.5" /> },
];

function MaskedSecret({ value, label }: { value: string; label: string }) {
  const [revealed, setRevealed] = useState(false);
  return (
    <div className="flex items-center gap-2">
      <code className="rounded-md bg-slate-100 px-2 py-1 font-mono text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">
        {revealed ? value : '••••••••••••'}
      </code>
      <button
        type="button"
        onClick={() => setRevealed((v) => !v)}
        aria-label={revealed ? `Masquer ${label}` : `Afficher ${label}`}
        className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-white"
      >
        {revealed ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
      </button>
    </div>
  );
}

export function SettingsPage() {
  const qc = useQueryClient();
  const [tab, setTab] = useState<TabKey>('workspace');
  const [density, setDensity] = useState<'comfortable' | 'compact'>('comfortable');

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
    <div className="px-6 py-6">
      <PageHeader
        title="Paramètres"
        subtitle="Workspace, intégrations, observabilité, apparence."
        actions={
          <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
            <SettingsIcon className="h-3.5 w-3.5" /> Workspace : {ws.data?.name ?? '…'}
          </span>
        }
      />

      <div className="mb-6">
        <Tabs items={TABS} value={tab} onChange={setTab} />
      </div>

      {tab === 'workspace' && (
        <Card>
          <CardHeader>
            <div>
              <CardEyebrow>Workspace</CardEyebrow>
              <CardTitle className="mt-1 text-base">Identité et limites</CardTitle>
            </div>
          </CardHeader>
          {ws.data ? (
            <form
              className="space-y-4"
              onSubmit={(e) => {
                e.preventDefault();
                const fd = new FormData(e.currentTarget);
                updateMut.mutate({
                  name: String(fd.get('name') ?? ''),
                  cost_cap_eur: Number(fd.get('cost_cap_eur') ?? 0),
                });
              }}
            >
              <div className="grid gap-4 sm:grid-cols-2">
                <label className="block text-sm">
                  <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">Nom</span>
                  <Input name="name" defaultValue={ws.data.name} required />
                </label>
                <label className="block text-sm">
                  <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">
                    Slug (URL)
                  </span>
                  <Input name="slug" defaultValue={ws.data.slug} disabled />
                  <span className="mt-1 block text-xs text-slate-500">Non modifiable</span>
                </label>
                <label className="block text-sm">
                  <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">
                    Plafond LLM mensuel (€)
                  </span>
                  <Input
                    name="cost_cap_eur"
                    type="number"
                    step="0.01"
                    defaultValue={String(ws.data.cost_cap_eur)}
                  />
                  <span className="mt-1 block text-xs text-slate-500">
                    Kill-switch automatique LLM quand atteint
                  </span>
                </label>
              </div>
              <div className="flex justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                <Button type="submit" variant="primary" loading={updateMut.isPending}>
                  Enregistrer
                </Button>
              </div>
            </form>
          ) : (
            <p className="text-sm text-slate-500">Chargement…</p>
          )}
        </Card>
      )}

      {tab === 'integrations' && (
        <div className="grid gap-3 md:grid-cols-2">
          {INTEGRATIONS.map((i) => (
            <Card key={i.env}>
              <CardHeader>
                <div className="min-w-0">
                  <CardEyebrow>{i.env}</CardEyebrow>
                  <CardTitle className="mt-1 truncate text-base">{i.name}</CardTitle>
                </div>
                <StatusPill
                  tone={i.status === 'configured' ? 'success' : i.status === 'optional' ? 'info' : 'warning'}
                >
                  {i.status === 'configured' ? 'Configuré' : i.status === 'optional' ? 'Optionnel' : 'À configurer'}
                </StatusPill>
              </CardHeader>
              <p className="mb-3 text-sm text-slate-600 dark:text-slate-300">{i.description}</p>
              <div className="flex items-center justify-between gap-2">
                <MaskedSecret value="sk-•••••" label={i.env} />
                <div className="flex items-center gap-1">
                  <Button variant="ghost" size="sm" iconLeft={<RefreshCw className="h-3.5 w-3.5" />}>
                    Renouveler
                  </Button>
                  <Button variant="secondary" size="sm" iconLeft={<KeyRound className="h-3.5 w-3.5" />}>
                    Configurer
                  </Button>
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}

      {tab === 'observability' && (
        <div className="space-y-4">
          <Card>
            <CardHeader>
              <div>
                <CardEyebrow>Sentry / GlitchTip</CardEyebrow>
                <CardTitle className="mt-1 text-base">Suivi des erreurs</CardTitle>
              </div>
            </CardHeader>
            <label className="block text-sm">
              <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">DSN Sentry</span>
              <Input placeholder="https://xxx@sentry.io/yyy" defaultValue="" />
              <span className="mt-1 block text-xs text-slate-500">
                Reporting des erreurs runtime, lié à GlitchTip self-hosted en dev.
              </span>
            </label>
          </Card>

          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {OBSERVABILITY_LINKS.map((link) => (
              <a
                key={link.name}
                href={link.url}
                target="_blank"
                rel="noopener noreferrer"
                className="group rounded-2xl bg-white p-4 ring-1 ring-slate-200/70 transition hover:-translate-y-0.5 hover:shadow-md dark:bg-slate-900 dark:ring-slate-800"
              >
                <div className="mb-1 flex items-center justify-between">
                  <p className="text-sm font-semibold text-slate-900 dark:text-white">{link.name}</p>
                  <ExternalLink className="h-3.5 w-3.5 text-slate-400 transition group-hover:text-slate-700 dark:group-hover:text-slate-200" />
                </div>
                <p className="text-xs text-slate-500">{link.description}</p>
                <p className="mt-2 truncate font-mono text-[11px] text-slate-400">{link.url}</p>
              </a>
            ))}
          </div>
        </div>
      )}

      {tab === 'appearance' && (
        <div className="grid gap-3 md:grid-cols-2">
          <Card>
            <CardHeader>
              <div>
                <CardEyebrow>Thème</CardEyebrow>
                <CardTitle className="mt-1 text-base">Mode clair / sombre</CardTitle>
              </div>
            </CardHeader>
            <p className="mb-4 text-sm text-slate-600 dark:text-slate-300">
              Le thème est synchronisé avec ta préférence système et persisté localement.
            </p>
            <DarkModeToggle />
          </Card>

          <Card>
            <CardHeader>
              <div>
                <CardEyebrow>Densité</CardEyebrow>
                <CardTitle className="mt-1 text-base">Affichage tables</CardTitle>
              </div>
            </CardHeader>
            <p className="mb-4 text-sm text-slate-600 dark:text-slate-300">
              Choisis l'espacement par défaut des lignes dans les listes longues.
            </p>
            <div className="inline-flex rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800">
              {(['comfortable', 'compact'] as const).map((d) => (
                <button
                  key={d}
                  type="button"
                  onClick={() => setDensity(d)}
                  className={
                    density === d
                      ? 'rounded-md bg-white px-3 py-1.5 text-xs font-semibold text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white'
                      : 'rounded-md px-3 py-1.5 text-xs font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'
                  }
                >
                  {d === 'comfortable' ? 'Confortable' : 'Compacte'}
                </button>
              ))}
            </div>
          </Card>
        </div>
      )}
    </div>
  );
}
