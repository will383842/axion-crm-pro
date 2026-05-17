import { useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ScrollText, ShieldCheck } from 'lucide-react';
import {
  Button,
  Card,
  CompaniesTableSkeleton,
  Drawer,
  EmptyState,
  PageHeader,
  SearchInput,
  StatusPill,
  type StatusTone,
  Toolbar,
  cn,
} from '@/components/ui';
import { api } from '@/lib/api';
import { toast } from 'sonner';

type Severity = 'info' | 'warning' | 'error' | 'critical';

interface AuditLog {
  id: number;
  event_type: string;
  path?: string | null;
  status_code?: number | null;
  ip?: string | null;
  user_agent?: string | null;
  created_at: string;
  current_hash: string;
  actor?: string | null;
  target?: string | null;
  severity?: Severity | null;
  payload?: unknown;
  previous_hash?: string | null;
}

const SEVERITY_TONE: Record<Severity, StatusTone> = {
  info: 'info',
  warning: 'warning',
  error: 'danger',
  critical: 'danger',
};

const GRID = '160px 180px minmax(160px,1.2fr) minmax(160px,1fr) 100px 130px 110px';

function severityFromStatus(l: AuditLog): Severity {
  if (l.severity) return l.severity;
  const s = l.status_code ?? 200;
  if (s >= 500) return 'critical';
  if (s >= 400) return 'error';
  return 'info';
}

export function AuditLogsPage() {
  const [severityFilter, setSeverityFilter] = useState<Severity | ''>('');
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<AuditLog | null>(null);

  const list = useQuery({
    queryKey: ['audit-logs'],
    queryFn: async () => (await api.get<{ data: AuditLog[] }>('/audit-logs')).data,
  });

  const verifyMut = useMutation({
    mutationFn: async () => (await api.get<{ valid: boolean }>('/audit-logs/verify-chain')).data,
    onSuccess: (r) => {
      if (r.valid) {
        toast.success("Chaîne d'audit VALIDE — aucune anomalie détectée.");
      } else {
        toast.error("Chaîne d'audit INVALIDE — possible falsification.");
      }
    },
    onError: () => toast.error('Erreur vérification chaîne'),
  });

  const rows = useMemo(() => {
    const all = list.data?.data ?? [];
    return all.filter((l) => {
      if (severityFilter && severityFromStatus(l) !== severityFilter) return false;
      if (search) {
        const hay = `${l.event_type} ${l.path ?? ''} ${l.actor ?? ''} ${l.ip ?? ''}`.toLowerCase();
        if (!hay.includes(search.toLowerCase())) return false;
      }
      return true;
    });
  }, [list.data, severityFilter, search]);

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Journaux d’audit"
        subtitle="Journal append-only avec chaîne cryptographique SHA-256 vérifiable."
        actions={
          <Button
            variant="primary"
            iconLeft={<ShieldCheck className="h-3.5 w-3.5" />}
            loading={verifyMut.isPending}
            onClick={() => verifyMut.mutate()}
          >
            Vérifier la chaîne
          </Button>
        }
      />

      <Toolbar
        left={
          <>
            <SearchInput
              value={search}
              onChange={setSearch}
              placeholder="Événement, chemin, IP, acteur…"
              className="w-72"
            />
            <select
              value={severityFilter}
              onChange={(e) => setSeverityFilter(e.target.value as Severity | '')}
              aria-label="Filtre sévérité"
              className="h-9 rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 transition focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700"
            >
              <option value="">Toutes sévérités</option>
              <option value="info">Info</option>
              <option value="warning">Attention</option>
              <option value="error">Erreur</option>
              <option value="critical">Critique</option>
            </select>
          </>
        }
      />

      {list.isLoading ? (
        <CompaniesTableSkeleton rows={10} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={<ScrollText className="h-10 w-10" />}
          title="Aucun journal d'audit"
          description={
            search || severityFilter
              ? 'Aucun log ne correspond aux filtres actuels.'
              : 'Les événements sensibles (auth, RGPD, admin) seront tracés ici de manière append-only.'
          }
        />
      ) : (
        <Card padding="none" className="overflow-hidden">
          <div
            role="row"
            className={cn(
              'sticky top-0 z-10 grid items-center gap-3 border-b border-slate-200 bg-slate-50/80 px-4 py-3 text-[11px] font-semibold uppercase tracking-wider text-slate-600 backdrop-blur',
              'dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-400',
            )}
            style={{ gridTemplateColumns: GRID }}
          >
            <div>Quand</div>
            <div>Événement</div>
            <div>Chemin</div>
            <div>Acteur</div>
            <div>Sévérité</div>
            <div>IP</div>
            <div>Empreinte</div>
          </div>
          <div className="divide-y divide-slate-100 dark:divide-slate-800">
            {rows.map((l) => {
              const sev = severityFromStatus(l);
              return (
                <button
                  key={l.id}
                  role="row"
                  onClick={() => setSelected(l)}
                  className="grid w-full items-center gap-3 px-4 py-3 text-left text-sm transition hover:bg-slate-50/70 dark:hover:bg-slate-800/30"
                  style={{ gridTemplateColumns: GRID }}
                >
                  <div className="text-xs text-slate-500">
                    {new Date(l.created_at).toLocaleString('fr-FR')}
                  </div>
                  <div className="truncate font-medium text-slate-900 dark:text-white">
                    {l.event_type}
                  </div>
                  <div className="truncate font-mono text-[11px] text-slate-500">
                    {l.path ?? '—'}
                  </div>
                  <div className="truncate text-slate-600 dark:text-slate-300">
                    {l.actor ?? '—'}
                  </div>
                  <div>
                    <StatusPill tone={SEVERITY_TONE[sev]}>{sev}</StatusPill>
                  </div>
                  <div className="truncate text-xs font-mono text-slate-500">
                    {l.ip ?? '—'}
                  </div>
                  <div className="truncate font-mono text-[11px] text-slate-400">
                    {l.current_hash.slice(0, 12)}…
                  </div>
                </button>
              );
            })}
          </div>
        </Card>
      )}

      <Drawer
        open={!!selected}
        onClose={() => setSelected(null)}
        title="Détails du log"
        width="lg"
      >
        {selected ? (
          <div className="space-y-3 text-sm">
            <div className="grid gap-2 sm:grid-cols-2">
              <div>
                <div className="text-[11px] font-semibold uppercase text-slate-500">Quand</div>
                <div>{new Date(selected.created_at).toLocaleString('fr-FR')}</div>
              </div>
              <div>
                <div className="text-[11px] font-semibold uppercase text-slate-500">Événement</div>
                <div className="font-medium">{selected.event_type}</div>
              </div>
              <div>
                <div className="text-[11px] font-semibold uppercase text-slate-500">Chemin</div>
                <div className="font-mono text-xs">{selected.path ?? '—'}</div>
              </div>
              <div>
                <div className="text-[11px] font-semibold uppercase text-slate-500">Statut</div>
                <div className="font-mono text-xs">{selected.status_code ?? '—'}</div>
              </div>
              <div>
                <div className="text-[11px] font-semibold uppercase text-slate-500">Acteur</div>
                <div>{selected.actor ?? '—'}</div>
              </div>
              <div>
                <div className="text-[11px] font-semibold uppercase text-slate-500">Cible</div>
                <div>{selected.target ?? '—'}</div>
              </div>
              <div>
                <div className="text-[11px] font-semibold uppercase text-slate-500">IP</div>
                <div className="font-mono text-xs">{selected.ip ?? '—'}</div>
              </div>
              <div>
                <div className="text-[11px] font-semibold uppercase text-slate-500">User agent</div>
                <div className="truncate text-xs">{selected.user_agent ?? '—'}</div>
              </div>
            </div>

            <div>
              <div className="mb-1 text-[11px] font-semibold uppercase text-slate-500">
                Chaîne d'empreintes
              </div>
              <div className="rounded-lg bg-slate-50 p-3 text-xs font-mono dark:bg-slate-800/60">
                <div>
                  <span className="text-slate-500">précédente :</span>{' '}
                  {selected.previous_hash ?? '—'}
                </div>
                <div>
                  <span className="text-slate-500">actuelle :</span> {selected.current_hash}
                </div>
              </div>
            </div>

            <div>
              <div className="mb-1 text-[11px] font-semibold uppercase text-slate-500">
                Payload (brut)
              </div>
              <pre className="overflow-auto rounded-lg bg-slate-50 p-3 text-xs dark:bg-slate-800/60">
                {JSON.stringify(selected.payload ?? selected, null, 2)}
              </pre>
            </div>
          </div>
        ) : null}
      </Drawer>
    </div>
  );
}
