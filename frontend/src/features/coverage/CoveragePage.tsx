import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { FranceCoverageMap, type CoverageMode } from './FranceCoverageMap';
import { api } from '@/lib/api';
import { toast } from 'sonner';

interface Cell { code: string; name: string; total: number; complete?: number; partial?: number; lat?: number; lon?: number }

type Level = 'region' | 'department' | 'city';

const MODES: Array<{ id: CoverageMode; label: string; hint: string }> = [
  { id: 'visu',   label: 'Visualisation', hint: 'Lecture seule' },
  { id: 'search', label: 'Recherche',     hint: 'Filtre la liste' },
  { id: 'action', label: 'Action',        hint: 'Clic = lance scrape' },
];

const LEVELS: Array<{ id: Level; label: string }> = [
  { id: 'region',     label: 'Régions' },
  { id: 'department', label: 'Départements' },
  { id: 'city',       label: 'Villes' },
];

export function CoveragePage() {
  const [mode, setMode] = useState<CoverageMode>('visu');
  const [level, setLevel] = useState<Level>('department');
  const [selected, setSelected] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['coverage', level],
    queryFn: async () => {
      const r = await api.get<{ cells: Cell[] }>('/coverage', { params: { level } });
      return r.data.cells;
    },
    refetchInterval: 60_000,
  });

  const cells = data ?? [];

  const stats = useMemo(() => {
    const totalAll = cells.reduce((s, c) => s + (c.total ?? 0), 0);
    const completeAll = cells.reduce((s, c) => s + (c.complete ?? 0), 0);
    const covered = cells.filter((c) => (c.total ?? 0) > 0).length;
    const denom = level === 'department' ? 96 : level === 'region' ? 13 : Math.max(cells.length, 1);
    const pct = denom ? Math.round((covered / denom) * 100) : 0;
    const top = [...cells].sort((a, b) => (b.total ?? 0) - (a.total ?? 0)).slice(0, 8);
    return { totalAll, completeAll, covered, denom, pct, top };
  }, [cells, level]);

  const selectedCell = selected ? cells.find((c) => c.code === selected) ?? null : null;

  async function launch(dept: string) {
    try {
      await api.post('/coverage/launch', { department: dept, limit: 100 });
      toast.success(`Scraping lancé · département ${dept}`);
    } catch {
      toast.error('Erreur lors du lancement');
    }
  }

  return (
    <div className="min-h-full bg-gradient-to-br from-slate-50 via-white to-slate-50 px-6 py-6">
      {/* Header */}
      <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="mb-1 inline-flex items-center gap-2 rounded-full bg-sky-50 px-2.5 py-0.5 text-[11px] font-medium text-sky-700 ring-1 ring-sky-200">
            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-sky-500" />
            Live · refresh 60s
          </div>
          <h1 className="bg-gradient-to-br from-slate-900 to-slate-600 bg-clip-text text-3xl font-semibold tracking-tight text-transparent">
            Couverture France
          </h1>
          <p className="mt-1 max-w-2xl text-sm text-slate-500">
            Carte interactive de votre prospection · sélectionnez une zone pour la détailler ou lancer un scrape ciblé.
          </p>
        </div>
        <SegmentedControl
          options={MODES.map((m) => ({ id: m.id, label: m.label }))}
          value={mode}
          onChange={setMode}
        />
      </header>

      {/* KPI cards */}
      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <KpiCard
          label="Couverture"
          value={`${stats.pct}%`}
          sublabel={`${stats.covered} / ${stats.denom} ${level === 'department' ? 'dépts' : level === 'region' ? 'régions' : 'villes'}`}
          tone="sky"
          progress={stats.pct}
        />
        <KpiCard
          label="Entreprises trouvées"
          value={stats.totalAll.toLocaleString('fr-FR')}
          sublabel={`${stats.completeAll.toLocaleString('fr-FR')} complètes`}
          tone="violet"
        />
        <KpiCard
          label="Mode actif"
          value={MODES.find((m) => m.id === mode)?.label ?? '—'}
          sublabel={MODES.find((m) => m.id === mode)?.hint ?? ''}
          tone="emerald"
        />
        <KpiCard
          label="Niveau"
          value={LEVELS.find((l) => l.id === level)?.label ?? '—'}
          sublabel={`${cells.length} zones`}
          tone="amber"
        />
      </div>

      {/* Toolbar */}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <SegmentedControl
          options={LEVELS.map((l) => ({ id: l.id, label: l.label }))}
          value={level}
          onChange={setLevel}
          variant="ghost"
        />
        <div className="ml-auto text-xs text-slate-500">
          {isLoading ? 'Chargement…' : `${stats.totalAll.toLocaleString('fr-FR')} entreprises au total`}
        </div>
      </div>

      {/* Map + Sidebar */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_360px]">
        <div className="rounded-2xl bg-white/80 p-1 shadow-[0_8px_32px_-12px_rgb(0_0_0/0.12)] ring-1 ring-slate-200/60 backdrop-blur-sm">
          <FranceCoverageMap
            cells={cells}
            mode={mode}
            onZoneClick={(code) => {
              setSelected(code);
              if (mode === 'action') void launch(code);
            }}
          />
        </div>

        <aside className="flex flex-col gap-4">
          {selectedCell ? (
            <SelectionCard
              cell={selectedCell}
              mode={mode}
              onLaunch={() => void launch(selectedCell.code)}
              onClose={() => setSelected(null)}
            />
          ) : (
            <HintCard mode={mode} />
          )}

          <TopList top={stats.top} selected={selected} onSelect={setSelected} />
        </aside>
      </div>
    </div>
  );
}

/* ─────────────────────────── UI helpers ─────────────────────────── */

interface SegOption<T extends string> { id: T; label: string }

function SegmentedControl<T extends string>({
  options,
  value,
  onChange,
  variant = 'solid',
}: {
  options: Array<SegOption<T>>;
  value: T;
  onChange: (v: T) => void;
  variant?: 'solid' | 'ghost';
}) {
  return (
    <div
      className={
        variant === 'solid'
          ? 'inline-flex items-center gap-1 rounded-full bg-slate-100 p-1 shadow-inner ring-1 ring-slate-200'
          : 'inline-flex items-center gap-1 rounded-lg bg-white p-0.5 ring-1 ring-slate-200'
      }
    >
      {options.map((o) => {
        const active = o.id === value;
        return (
          <button
            key={o.id}
            onClick={() => onChange(o.id)}
            className={[
              'rounded-full px-3.5 py-1.5 text-sm font-medium transition',
              variant === 'ghost' ? 'rounded-md px-2.5 py-1 text-xs' : '',
              active
                ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200'
                : 'text-slate-600 hover:text-slate-900',
            ].join(' ')}
          >
            {o.label}
          </button>
        );
      })}
    </div>
  );
}

type Tone = 'sky' | 'violet' | 'emerald' | 'amber';

const TONE_MAP: Record<Tone, { ring: string; chip: string; bar: string; glow: string }> = {
  sky:     { ring: 'ring-sky-200/60',     chip: 'bg-sky-50 text-sky-700',         bar: 'from-sky-500 to-blue-600',         glow: 'shadow-sky-500/10' },
  violet:  { ring: 'ring-violet-200/60',  chip: 'bg-violet-50 text-violet-700',   bar: 'from-violet-500 to-fuchsia-600',   glow: 'shadow-violet-500/10' },
  emerald: { ring: 'ring-emerald-200/60', chip: 'bg-emerald-50 text-emerald-700', bar: 'from-emerald-500 to-teal-600',     glow: 'shadow-emerald-500/10' },
  amber:   { ring: 'ring-amber-200/60',   chip: 'bg-amber-50 text-amber-700',     bar: 'from-amber-500 to-orange-600',     glow: 'shadow-amber-500/10' },
};

function KpiCard({
  label,
  value,
  sublabel,
  tone,
  progress,
}: {
  label: string;
  value: string;
  sublabel?: string;
  tone: Tone;
  progress?: number;
}) {
  const t = TONE_MAP[tone];
  return (
    <div className={`group relative overflow-hidden rounded-2xl bg-white/80 p-4 ring-1 ${t.ring} shadow-[0_4px_24px_-8px_rgb(0_0_0/0.06)] backdrop-blur-sm transition hover:-translate-y-0.5 hover:shadow-[0_8px_32px_-8px_rgb(0_0_0/0.10)] ${t.glow}`}>
      <div className={`mb-2 inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider ${t.chip}`}>
        {label}
      </div>
      <div className="text-2xl font-semibold tracking-tight text-slate-900">{value}</div>
      {sublabel ? <div className="mt-0.5 text-xs text-slate-500">{sublabel}</div> : null}
      {typeof progress === 'number' ? (
        <div className="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
          <div
            className={`h-full rounded-full bg-gradient-to-r ${t.bar} transition-[width] duration-500`}
            style={{ width: `${Math.max(2, Math.min(100, progress))}%` }}
          />
        </div>
      ) : null}
    </div>
  );
}

function SelectionCard({
  cell,
  mode,
  onLaunch,
  onClose,
}: {
  cell: Cell;
  mode: CoverageMode;
  onLaunch: () => void;
  onClose: () => void;
}) {
  const isCovered = (cell.total ?? 0) > 0;
  return (
    <div className="rounded-2xl bg-white/80 p-5 ring-1 ring-slate-200/60 shadow-[0_4px_24px_-8px_rgb(0_0_0/0.06)] backdrop-blur-sm">
      <div className="mb-3 flex items-start justify-between gap-2">
        <div>
          <div className="text-[11px] font-medium uppercase tracking-wider text-slate-500">Sélection</div>
          <div className="mt-0.5 flex items-center gap-2">
            <span className="rounded-md bg-slate-900 px-1.5 py-0.5 font-mono text-xs text-white">{cell.code}</span>
            <span className="text-lg font-semibold tracking-tight text-slate-900">{cell.name}</span>
          </div>
        </div>
        <button
          onClick={onClose}
          className="rounded-full p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
          aria-label="Désélectionner"
        >
          <svg viewBox="0 0 20 20" className="h-4 w-4"><path d="M6 6l8 8M14 6l-8 8" stroke="currentColor" strokeWidth="2" strokeLinecap="round" /></svg>
        </button>
      </div>

      <div className="grid grid-cols-2 gap-2">
        <Stat label="Entreprises" value={(cell.total ?? 0).toLocaleString('fr-FR')} />
        <Stat label="Complètes"   value={(cell.complete ?? 0).toLocaleString('fr-FR')} />
      </div>

      <div className="mt-4 flex flex-col gap-2">
        <button
          onClick={onLaunch}
          className="group inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-br from-slate-900 to-slate-700 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-900/10 transition hover:from-slate-800 hover:to-slate-600 active:scale-[0.98]"
        >
          {isCovered ? 'Relancer un scrape' : 'Lancer le scrape'}
          <svg viewBox="0 0 20 20" className="h-4 w-4 transition group-hover:translate-x-0.5"><path d="M5 10h10m0 0l-4-4m4 4l-4 4" stroke="currentColor" strokeWidth="2" fill="none" strokeLinecap="round" strokeLinejoin="round" /></svg>
        </button>
        {mode === 'search' ? (
          <p className="text-center text-xs text-slate-500">Le filtre est appliqué à la liste entreprises.</p>
        ) : null}
      </div>
    </div>
  );
}

function HintCard({ mode }: { mode: CoverageMode }) {
  const m = MODES.find((x) => x.id === mode);
  return (
    <div className="rounded-2xl bg-white/60 p-5 ring-1 ring-dashed ring-slate-300/80 backdrop-blur-sm">
      <div className="mb-1 text-[11px] font-medium uppercase tracking-wider text-slate-500">Aucune sélection</div>
      <div className="text-sm font-medium text-slate-700">{m?.label} actif</div>
      <p className="mt-2 text-xs leading-relaxed text-slate-500">
        Cliquez sur un département pour voir ses détails
        {mode === 'action' ? ' — un scrape sera lancé immédiatement.' : '.'}
      </p>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-100">
      <div className="text-[10px] font-medium uppercase tracking-wider text-slate-500">{label}</div>
      <div className="mt-0.5 text-lg font-semibold tracking-tight text-slate-900">{value}</div>
    </div>
  );
}

function TopList({
  top,
  selected,
  onSelect,
}: {
  top: Cell[];
  selected: string | null;
  onSelect: (code: string) => void;
}) {
  const hasData = top.some((c) => (c.total ?? 0) > 0);
  return (
    <div className="rounded-2xl bg-white/80 p-4 ring-1 ring-slate-200/60 shadow-[0_4px_24px_-8px_rgb(0_0_0/0.06)] backdrop-blur-sm">
      <div className="mb-3 flex items-center justify-between">
        <div className="text-sm font-semibold text-slate-900">Top zones</div>
        <div className="text-[10px] font-medium uppercase tracking-wider text-slate-400">Triées · entreprises</div>
      </div>
      {!hasData ? (
        <div className="rounded-xl bg-slate-50 p-4 text-center">
          <div className="mb-1 text-sm font-medium text-slate-600">Aucune donnée pour l'instant</div>
          <p className="text-xs text-slate-500">Lancez un premier scrape pour voir les zones se remplir.</p>
        </div>
      ) : (
        <ul className="space-y-1.5">
          {top.map((c) => {
            const active = c.code === selected;
            return (
              <li key={c.code}>
                <button
                  onClick={() => onSelect(c.code)}
                  className={[
                    'group flex w-full items-center justify-between gap-2 rounded-xl px-2.5 py-2 text-left transition',
                    active ? 'bg-slate-900 text-white' : 'hover:bg-slate-50',
                  ].join(' ')}
                >
                  <div className="flex items-center gap-2">
                    <span className={['rounded-md px-1.5 py-0.5 font-mono text-[10px]', active ? 'bg-white/10' : 'bg-slate-100 text-slate-600'].join(' ')}>
                      {c.code}
                    </span>
                    <span className={active ? 'text-sm font-medium' : 'text-sm text-slate-700'}>{c.name}</span>
                  </div>
                  <span className={active ? 'text-sm font-semibold' : 'text-sm font-semibold text-slate-900'}>
                    {(c.total ?? 0).toLocaleString('fr-FR')}
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
