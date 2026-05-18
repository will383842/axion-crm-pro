/**
 * Sprint 19.7 — CampaignWizardPage.
 *
 * Wizard 4 étapes :
 *   1. Identité (nom + description + schedule maintenant/plus tard)
 *   2. Zones cibles (départements / régions / villes)
 *   3. Sources (cards toggle)
 *   4. Budget & sécurité anti-blacklist
 *
 * À la fin : "Créer en brouillon" ou "Créer et lancer maintenant".
 */
import { useMemo, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  ChevronLeft, ChevronRight, Check, X, Search,
  Building2, Clock, Gauge, Calendar, Sparkles, ShieldAlert,
  Database, Briefcase, MapPin, BookOpen, Lock, Info,
} from 'lucide-react';
import type { ComponentType, SVGProps } from 'react';
import { api } from '@/lib/api';
import {
  Button,
  Card,
  cn,
  Input,
  PageHeader,
  SegmentedControl,
  StatusPill,
} from '@/components/ui';
import {
  type Campaign,
  type CampaignSource,
  type CampaignZone,
  type ZoneType,
} from './types';
import { getReferentialZones } from './fr-zones';

type Step = 1 | 2 | 3 | 4;
type ScheduleMode = 'now' | 'later';

interface CoverageCell { code: string; name: string; total?: number; region_code?: string; department?: string }

type DiscoverySourceStatus = 'api_key' | 'proxies_required';

interface DiscoverySource {
  id: CampaignSource;
  label: string;
  description: string;
  status: DiscoverySourceStatus;
  activable: boolean;
  icon: ComponentType<SVGProps<SVGSVGElement>>;
  unavailableHint?: string;
}

/**
 * Sources de DÉCOUVERTE (créent des companies).
 * Les sources d'ENRICHISSEMENT (annuaire-entreprises, bodacc, ban, mentions légales, LLM Mistral)
 * sont appliquées automatiquement par WaterfallOrchestrator — pas exposées dans le wizard.
 */
const DISCOVERY_SOURCES: DiscoverySource[] = [
  {
    id: 'insee',
    label: 'INSEE Sirene',
    description: 'Base officielle entreprises FR (~30M entreprises légales)',
    status: 'api_key',
    activable: true,
    icon: Database,
  },
  {
    id: 'france_travail',
    label: 'France Travail',
    description: 'Entreprises qui recrutent dans la zone — signal intent fort',
    status: 'api_key',
    activable: true,
    icon: Briefcase,
  },
  {
    id: 'google_maps',
    label: 'Google Maps (Phase B)',
    description: 'Fiches géolocalisées avec photos/horaires (Webshare requis)',
    status: 'proxies_required',
    activable: false,
    icon: MapPin,
    unavailableHint: 'Phase B — Webshare proxy résidentiel requis (~$30/mois). À configurer dans Settings.',
  },
  {
    id: 'pages_jaunes',
    label: 'Pages Jaunes (Phase B)',
    description: 'Annuaire pro FR — TPE/artisans souvent absents INSEE',
    status: 'proxies_required',
    activable: false,
    icon: BookOpen,
    unavailableHint: 'Phase B — Webshare proxy résidentiel requis (~$30/mois). À configurer dans Settings.',
  },
];

const SOURCE_STATUS_LABEL: Record<DiscoverySourceStatus, string> = {
  api_key: 'API key requise',
  proxies_required: 'Proxies requis',
};

const SOURCE_STATUS_TONE: Record<DiscoverySourceStatus, 'success' | 'warning' | 'info'> = {
  api_key: 'info',
  proxies_required: 'warning',
};

export function CampaignWizardPage() {
  const navigate = useNavigate();
  const [step, setStep] = useState<Step>(1);

  // --- Étape 1 ---
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [scheduleMode, setScheduleMode] = useState<ScheduleMode>('now');
  const [scheduledAt, setScheduledAt] = useState<string>('');

  // --- Étape 2 ---
  const [zoneType, setZoneType] = useState<ZoneType>('department');
  const [zoneSearch, setZoneSearch] = useState('');
  const [selectedZones, setSelectedZones] = useState<CampaignZone[]>([]);

  // Source référentielle (hardcodée FR, toujours dispo) — base canonique des zones.
  const referentialZones = useMemo(() => getReferentialZones(zoneType), [zoneType]);

  // Données coverage (entreprises déjà scrappées) — mergées en option pour afficher
  // le nombre d'entreprises connues par zone. Si l'endpoint plante ou est vide, fallback silencieux.
  const { data: coverageData } = useQuery({
    queryKey: ['coverage-wizard', zoneType],
    queryFn: async () => {
      const r = await api.get<{ cells: CoverageCell[] }>('/coverage', { params: { level: zoneType } });
      return r.data.cells;
    },
    staleTime: 60_000,
    retry: false,
  });
  const coverageMap = useMemo(() => {
    const m = new Map<string, number>();
    for (const c of coverageData ?? []) m.set(c.code, c.total ?? 0);
    return m;
  }, [coverageData]);

  const cells = useMemo<CoverageCell[]>(
    () => referentialZones.map((z) => ({ code: z.code, name: z.name, total: coverageMap.get(z.code) ?? 0 })),
    [referentialZones, coverageMap],
  );

  const filteredCells = useMemo(() => {
    const q = zoneSearch.trim().toLowerCase();
    if (!q) return cells; // afficher TOUS les départements (96+) par défaut
    return cells.filter((c) =>
      c.name?.toLowerCase().includes(q) || c.code?.toLowerCase().includes(q),
    );
  }, [cells, zoneSearch]);

  const estimatedCompanies = useMemo(() => {
    return selectedZones.reduce((s, z) => s + (coverageMap.get(z.code) ?? 0), 0);
  }, [selectedZones, coverageMap]);

  // --- Étape 3 ---
  const [sources, setSources] = useState<CampaignSource[]>(['insee']);

  // --- Étape 4 ---
  const [maxCompanies, setMaxCompanies] = useState<number>(1000);
  const [maxDuration, setMaxDuration] = useState<number>(180);
  const [maxRpm, setMaxRpm] = useState<number>(30);
  const [showPerSource, setShowPerSource] = useState(false);
  const [perSourceLimits, setPerSourceLimits] = useState<Record<string, { rpm?: number; daily?: number }>>({});

  // --- Mutation ---
  const createMutation = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<Campaign>('/campaigns', payload)).data,
  });
  const startMutation = useMutation({
    mutationFn: async (id: number) => (await api.post<Campaign>(`/campaigns/${id}/start`)).data,
  });

  function buildPayload(): Record<string, unknown> {
    const payload: Record<string, unknown> = {
      name: name.trim(),
      description: description.trim() || undefined,
      sources,
      zones: selectedZones.map(({ type, code }) => ({ type, code })),
      max_companies: maxCompanies,
      max_duration_minutes: maxDuration,
      max_requests_per_minute: maxRpm,
    };
    if (Object.keys(perSourceLimits).length > 0) {
      payload.per_source_limits = perSourceLimits;
    }
    if (scheduleMode === 'later' && scheduledAt) {
      payload.scheduled_at = new Date(scheduledAt).toISOString();
    }
    return payload;
  }

  async function handleCreateDraft() {
    const payload = buildPayload();
    try {
      const c = await createMutation.mutateAsync(payload);
      toast.success('Campagne créée en brouillon');
      void navigate({ to: '/campaigns/$campaignId', params: { campaignId: String(c.id) } });
    } catch (err) {
      toast.error(extractApiMessage(err) ?? 'Création impossible');
    }
  }

  async function handleCreateAndStart() {
    const payload = buildPayload();
    try {
      const c = await createMutation.mutateAsync(payload);
      if (scheduleMode === 'now') {
        await startMutation.mutateAsync(c.id);
        toast.success('Campagne lancée');
      } else {
        toast.success('Campagne créée et planifiée');
      }
      void navigate({ to: '/campaigns/$campaignId', params: { campaignId: String(c.id) } });
    } catch (err) {
      toast.error(extractApiMessage(err) ?? 'Lancement impossible');
    }
  }

  // --- Validation par étape ---
  const canContinue: Record<Step, boolean> = {
    1: name.trim().length > 0 && (scheduleMode === 'now' || (scheduleMode === 'later' && !!scheduledAt)),
    2: selectedZones.length > 0,
    3: sources.length > 0,
    4: maxCompanies > 0 && maxDuration >= 5 && maxRpm >= 1,
  };

  function toggleZone(cell: CoverageCell) {
    setSelectedZones((cur) => {
      const exists = cur.find((z) => z.code === cell.code && z.type === zoneType);
      if (exists) return cur.filter((z) => !(z.code === cell.code && z.type === zoneType));
      return [...cur, { type: zoneType, code: cell.code, label: cell.name }];
    });
  }
  function removeZone(z: CampaignZone) {
    setSelectedZones((cur) => cur.filter((x) => !(x.code === z.code && x.type === z.type)));
  }
  function toggleSource(s: CampaignSource) {
    const meta = DISCOVERY_SOURCES.find((m) => m.id === s);
    if (!meta || !meta.activable) return;
    setSources((cur) => cur.includes(s) ? cur.filter((x) => x !== s) : [...cur, s]);
  }

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Nouvelle campagne"
        subtitle="Configure ta campagne en 4 étapes : identité, zones, sources, budget."
        breadcrumbs={[
          { label: 'Campagnes', to: '/campaigns' },
          { label: 'Nouvelle' },
        ]}
      />

      <Stepper step={step} />

      <Card padding="lg" className="mt-6">
        {step === 1 ? (
          <StepIdentity
            name={name} setName={setName}
            description={description} setDescription={setDescription}
            scheduleMode={scheduleMode} setScheduleMode={setScheduleMode}
            scheduledAt={scheduledAt} setScheduledAt={setScheduledAt}
          />
        ) : null}
        {step === 2 ? (
          <StepZones
            zoneType={zoneType} setZoneType={setZoneType}
            zoneSearch={zoneSearch} setZoneSearch={setZoneSearch}
            filteredCells={filteredCells}
            selectedZones={selectedZones}
            toggleZone={toggleZone}
            removeZone={removeZone}
            estimatedCompanies={estimatedCompanies}
          />
        ) : null}
        {step === 3 ? (
          <StepSources sources={sources} toggleSource={toggleSource} />
        ) : null}
        {step === 4 ? (
          <StepBudget
            maxCompanies={maxCompanies} setMaxCompanies={setMaxCompanies}
            maxDuration={maxDuration} setMaxDuration={setMaxDuration}
            maxRpm={maxRpm} setMaxRpm={setMaxRpm}
            sources={sources}
            showPerSource={showPerSource} setShowPerSource={setShowPerSource}
            perSourceLimits={perSourceLimits} setPerSourceLimits={setPerSourceLimits}
          />
        ) : null}

        {/* Navigation */}
        <div className="mt-8 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-5 dark:border-slate-800">
          <Button
            variant="ghost"
            size="md"
            iconLeft={<ChevronLeft className="h-4 w-4" />}
            onClick={() => {
              if (step > 1) setStep((step - 1) as Step);
              else void navigate({ to: '/campaigns' });
            }}
          >
            {step === 1 ? 'Annuler' : 'Précédent'}
          </Button>

          <div className="flex items-center gap-2">
            {step < 4 ? (
              <Button
                variant="primary"
                size="md"
                iconRight={<ChevronRight className="h-4 w-4" />}
                disabled={!canContinue[step]}
                onClick={() => setStep(((step as number) + 1) as Step)}
              >
                Continuer
              </Button>
            ) : (
              <>
                <Button
                  variant="secondary"
                  size="md"
                  loading={createMutation.isPending && !startMutation.isPending}
                  disabled={!canContinue[4] || !canContinue[1] || !canContinue[2] || !canContinue[3]}
                  onClick={() => { void handleCreateDraft(); }}
                >
                  Créer en brouillon
                </Button>
                <Button
                  variant="primary"
                  size="md"
                  iconLeft={<Sparkles className="h-4 w-4" />}
                  loading={createMutation.isPending || startMutation.isPending}
                  disabled={!canContinue[4] || !canContinue[1] || !canContinue[2] || !canContinue[3]}
                  onClick={() => { void handleCreateAndStart(); }}
                >
                  {scheduleMode === 'later' ? 'Créer et planifier' : 'Créer et lancer'}
                </Button>
              </>
            )}
          </div>
        </div>
      </Card>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Stepper
// ---------------------------------------------------------------------------
function Stepper({ step }: { step: Step }) {
  const items: Array<{ id: Step; label: string }> = [
    { id: 1, label: 'Identité' },
    { id: 2, label: 'Zones' },
    { id: 3, label: 'Sources' },
    { id: 4, label: 'Budget & sécurité' },
  ];
  return (
    <ol className="flex flex-wrap items-center gap-2">
      {items.map((it, i) => {
        const done = step > it.id;
        const active = step === it.id;
        return (
          <li key={it.id} className="flex items-center gap-2">
            <span
              className={cn(
                'inline-flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-semibold ring-1',
                done
                  ? 'bg-emerald-500 text-white ring-emerald-500'
                  : active
                  ? 'bg-slate-900 text-white ring-slate-900 dark:bg-white dark:text-slate-900 dark:ring-white'
                  : 'bg-white text-slate-500 ring-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700',
              )}
            >
              {done ? <Check className="h-3 w-3" /> : it.id}
            </span>
            <span className={cn(
              'text-xs font-medium',
              active ? 'text-slate-900 dark:text-white' : done ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400',
            )}>{it.label}</span>
            {i < items.length - 1 ? (
              <span aria-hidden className="mx-1 h-px w-8 bg-slate-200 dark:bg-slate-700" />
            ) : null}
          </li>
        );
      })}
    </ol>
  );
}

// ---------------------------------------------------------------------------
// Étape 1
// ---------------------------------------------------------------------------
function StepIdentity({
  name, setName, description, setDescription,
  scheduleMode, setScheduleMode, scheduledAt, setScheduledAt,
}: {
  name: string; setName: (v: string) => void;
  description: string; setDescription: (v: string) => void;
  scheduleMode: ScheduleMode; setScheduleMode: (v: ScheduleMode) => void;
  scheduledAt: string; setScheduledAt: (v: string) => void;
}) {
  return (
    <div className="space-y-5">
      <SectionHeading title="Identité" hint="Nom et description visibles dans la liste des campagnes." />
      <Field label="Nom de la campagne" required>
        <Input
          placeholder="Ex : Prospection PME Paris IT — semaine 21"
          value={name}
          onChange={(e) => setName(e.target.value)}
          maxLength={120}
        />
      </Field>
      <Field label="Description (optionnel)">
        <textarea
          className="min-h-[80px] w-full rounded-lg bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-slate-200 transition placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700"
          placeholder="Notes internes pour l’équipe"
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          maxLength={500}
        />
      </Field>

      <Field label="Planification">
        <SegmentedControl
          value={scheduleMode}
          onChange={setScheduleMode}
          options={[
            { id: 'now',   label: 'Lancer maintenant' },
            { id: 'later', label: 'Planifier plus tard', icon: <Calendar className="h-3.5 w-3.5" /> },
          ]}
        />
        {scheduleMode === 'later' ? (
          <div className="mt-3 max-w-xs">
            <input
              type="datetime-local"
              className="h-9 w-full rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700"
              value={scheduledAt}
              min={new Date(Date.now() + 60000).toISOString().slice(0, 16)}
              onChange={(e) => setScheduledAt(e.target.value)}
            />
            <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
              Démarrage automatique à la date choisie (cron toutes les minutes).
            </p>
          </div>
        ) : null}
      </Field>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Étape 2 — Zones
// ---------------------------------------------------------------------------
function StepZones({
  zoneType, setZoneType, zoneSearch, setZoneSearch,
  filteredCells, selectedZones, toggleZone, removeZone, estimatedCompanies,
}: {
  zoneType: ZoneType; setZoneType: (v: ZoneType) => void;
  zoneSearch: string; setZoneSearch: (v: string) => void;
  filteredCells: CoverageCell[];
  selectedZones: CampaignZone[];
  toggleZone: (c: CoverageCell) => void;
  removeZone: (z: CampaignZone) => void;
  estimatedCompanies: number;
}) {
  return (
    <div className="space-y-5">
      <SectionHeading title="Zones cibles" hint="Choisis les départements, régions ou villes à scraper." />

      <div className="flex flex-wrap items-center gap-3">
        <SegmentedControl
          value={zoneType}
          onChange={setZoneType}
          options={[
            { id: 'department', label: 'Départements' },
            { id: 'region',     label: 'Régions' },
            { id: 'city',       label: 'Villes' },
          ]}
        />
        <div className="ml-auto w-full max-w-xs">
          <Input
            iconLeft={<Search className="h-4 w-4" />}
            placeholder={`Rechercher ${zoneType === 'department' ? 'un dépt' : zoneType === 'region' ? 'une région' : 'une ville'}…`}
            value={zoneSearch}
            onChange={(e) => setZoneSearch(e.target.value)}
          />
        </div>
      </div>

      {/* Panier sélection */}
      {selectedZones.length > 0 ? (
        <Card variant="flat" padding="sm">
          <div className="mb-2 flex items-center justify-between gap-2">
            <span className="text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
              {selectedZones.length} zone{selectedZones.length > 1 ? 's' : ''} sélectionnée{selectedZones.length > 1 ? 's' : ''}
            </span>
            <span className="text-xs text-slate-500 dark:text-slate-400">
              {estimatedCompanies > 0 ? `~${estimatedCompanies.toLocaleString('fr-FR')} entreprises connues` : 'estimation indisponible'}
            </span>
          </div>
          <div className="flex flex-wrap gap-1.5">
            {selectedZones.map((z) => (
              <button
                key={`${z.type}-${z.code}`}
                type="button"
                onClick={() => removeZone(z)}
                className="inline-flex items-center gap-1.5 rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-medium text-sky-700 hover:bg-sky-200 dark:bg-sky-950/40 dark:text-sky-300 dark:hover:bg-sky-900/40"
              >
                <span>{z.label ?? z.code}</span>
                <X className="h-3 w-3" />
              </button>
            ))}
          </div>
        </Card>
      ) : null}

      <div className="flex items-center justify-between text-[11px] text-slate-500 dark:text-slate-400">
        <span>
          {filteredCells.length} {zoneType === 'department' ? 'département' : zoneType === 'region' ? 'région' : 'ville'}{filteredCells.length > 1 ? 's' : ''}
          {zoneSearch ? <> pour « <span className="font-medium text-slate-700 dark:text-slate-300">{zoneSearch}</span> »</> : null}
        </span>
        <span className="text-slate-400">Clic = ajouter / retirer</span>
      </div>

      {/* Liste */}
      <div className="grid max-h-[420px] grid-cols-1 gap-1.5 overflow-y-auto rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40 md:grid-cols-2 lg:grid-cols-3">
        {filteredCells.length === 0 ? (
          <div className="col-span-full px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">
            Aucune zone ne correspond à « {zoneSearch} ». Efface la recherche pour voir toutes les zones disponibles.
          </div>
        ) : (
          filteredCells.map((cell) => {
            const selected = selectedZones.find((z) => z.code === cell.code && z.type === zoneType);
            return (
              <button
                key={cell.code}
                type="button"
                onClick={() => toggleZone(cell)}
                className={cn(
                  'flex items-center justify-between gap-2 rounded-lg px-3 py-1.5 text-left text-sm transition',
                  selected
                    ? 'bg-sky-500 text-white shadow-sm ring-1 ring-sky-600'
                    : 'bg-white text-slate-700 ring-1 ring-slate-200 hover:bg-slate-100 hover:ring-slate-300 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800',
                )}
              >
                <span className="min-w-0 truncate">
                  <span className="font-mono text-[11px] opacity-70">{cell.code}</span>{' '}
                  <span className="font-medium">{cell.name}</span>
                </span>
                <span className={cn(
                  'shrink-0 text-[10px] tabular-nums',
                  selected ? 'text-white/80' : 'text-slate-400',
                )}>
                  {cell.total ? `~${cell.total}` : ''}
                </span>
              </button>
            );
          })
        )}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Étape 3 — Sources
// ---------------------------------------------------------------------------
function StepSources({
  sources, toggleSource,
}: {
  sources: CampaignSource[];
  toggleSource: (s: CampaignSource) => void;
}) {
  return (
    <div className="space-y-5">
      <SectionHeading
        title="Sources de découverte"
        hint="Au moins une source obligatoire. Ces sources créent des entreprises ; l'enrichissement est appliqué automatiquement ensuite."
      />

      <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
        {DISCOVERY_SOURCES.map((src) => {
          const active = sources.includes(src.id);
          const disabled = !src.activable;
          const Icon = src.icon;
          return (
            <button
              key={src.id}
              type="button"
              onClick={() => toggleSource(src.id)}
              disabled={disabled}
              aria-disabled={disabled}
              title={disabled ? src.unavailableHint : undefined}
              className={cn(
                'group relative flex flex-col items-start gap-2 rounded-2xl p-4 text-left transition',
                disabled
                  ? 'cursor-not-allowed bg-white opacity-60 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800'
                  : active
                  ? 'bg-gradient-to-br from-sky-50 to-blue-50 ring-2 ring-sky-500 dark:from-sky-950/40 dark:to-blue-950/40'
                  : 'bg-white ring-1 ring-slate-200 hover:ring-slate-300 dark:bg-slate-900 dark:ring-slate-800',
              )}
            >
              {disabled ? (
                <span
                  aria-hidden
                  className="absolute right-3 top-3 inline-flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-slate-500 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700"
                >
                  <Lock className="h-3.5 w-3.5" />
                </span>
              ) : null}
              <div className="flex w-full items-start justify-between gap-2">
                <div className={cn(
                  'flex h-9 w-9 items-center justify-center rounded-lg',
                  active && !disabled
                    ? 'bg-sky-500 text-white'
                    : 'bg-slate-100 text-slate-500 group-hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400',
                )}>
                  <Icon className="h-4.5 w-4.5" aria-hidden />
                </div>
                {disabled ? null : active ? (
                  <Check className="h-5 w-5 text-sky-600 dark:text-sky-400" />
                ) : (
                  <span className="h-5 w-5 rounded-full ring-2 ring-slate-200 dark:ring-slate-700" />
                )}
              </div>
              <div>
                <div className="text-sm font-semibold text-slate-900 dark:text-white">{src.label}</div>
                <div className="mt-0.5 text-xs text-slate-600 dark:text-slate-400">{src.description}</div>
              </div>
              <StatusPill tone={SOURCE_STATUS_TONE[src.status]}>{SOURCE_STATUS_LABEL[src.status]}</StatusPill>
              {disabled && src.unavailableHint ? (
                <div className="mt-1 inline-flex items-center gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                  <Lock className="h-3 w-3" aria-hidden />
                  <span>{src.unavailableHint}</span>
                </div>
              ) : null}
            </button>
          );
        })}
      </div>

      <div className="flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50/60 p-3 text-xs text-slate-700 dark:border-sky-900/40 dark:bg-sky-950/30 dark:text-slate-200">
        <Info className="mt-0.5 h-4 w-4 shrink-0 text-sky-600 dark:text-sky-400" aria-hidden />
        <div className="space-y-1">
          <p>
            <strong className="font-semibold">Enrichissement automatique.</strong>{' '}
            Chaque entreprise découverte ci-dessus est ensuite enrichie automatiquement par :
          </p>
          <ul className="ml-4 list-disc space-y-0.5">
            <li><strong>Annuaire Entreprises</strong> — CA, bilans, dirigeants (gratuit)</li>
            <li><strong>BODACC</strong> — signaux légaux : création, redressement (gratuit)</li>
            <li><strong>BAN géocodage</strong> — coordonnées GPS précises (gratuit)</li>
            <li><strong>Mentions légales scrape</strong> — email + téléphone publics (18 URLs explorées par site)</li>
            <li><strong>Google Places API</strong> — téléphone, horaires, note Google (~$0 grâce au crédit $200/mois)</li>
            <li><strong>LLM Mistral</strong> — classification, priorité, tags intent</li>
          </ul>
          <p className="mt-1 text-slate-500 dark:text-slate-400">Pas besoin d'activer ces sources ici — elles tournent en background.</p>
        </div>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Étape 4 — Budget
// ---------------------------------------------------------------------------
function StepBudget({
  maxCompanies, setMaxCompanies, maxDuration, setMaxDuration, maxRpm, setMaxRpm,
  sources, showPerSource, setShowPerSource, perSourceLimits, setPerSourceLimits,
}: {
  maxCompanies: number; setMaxCompanies: (v: number) => void;
  maxDuration: number; setMaxDuration: (v: number) => void;
  maxRpm: number; setMaxRpm: (v: number) => void;
  sources: CampaignSource[];
  showPerSource: boolean; setShowPerSource: (v: boolean) => void;
  perSourceLimits: Record<string, { rpm?: number; daily?: number }>;
  setPerSourceLimits: (v: Record<string, { rpm?: number; daily?: number }>) => void;
}) {
  return (
    <div className="space-y-5">
      <SectionHeading
        title="Budget et sécurité anti-blacklist"
        hint="Trois garde-fous : volume max, temps max, débit max. Atteint un de ces seuils ⇒ auto-pause."
      />

      <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
        <NumberField
          icon={<Building2 className="h-4 w-4" />}
          label="Entreprises max"
          hint="1 — 50 000"
          min={1}
          max={50000}
          step={50}
          value={maxCompanies}
          onChange={setMaxCompanies}
        />
        <NumberField
          icon={<Clock className="h-4 w-4" />}
          label="Durée max (minutes)"
          hint="5 — 1440 (24h)"
          min={5}
          max={1440}
          step={5}
          value={maxDuration}
          onChange={setMaxDuration}
        />
        <div className="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
          <div className="mb-1.5 flex items-center justify-between text-xs">
            <span className="inline-flex items-center gap-1.5 font-semibold text-slate-700 dark:text-slate-300">
              <Gauge className="h-4 w-4" /> Débit max
            </span>
            <span className="font-mono tabular-nums text-slate-900 dark:text-white">{maxRpm} req/min</span>
          </div>
          <input
            type="range"
            min={1}
            max={100}
            step={1}
            value={maxRpm}
            onChange={(e) => setMaxRpm(Number(e.target.value))}
            className="w-full accent-sky-600"
          />
          <p className="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">
            Plus c’est bas, moins de risque de blacklist source (Google Maps, Pages Jaunes).
          </p>
        </div>
      </div>

      {/* Recap final */}
      <Card variant="glass" padding="md" className="border border-emerald-200 bg-emerald-50/60 dark:border-emerald-900/40 dark:bg-emerald-950/30">
        <div className="flex flex-wrap items-center gap-3 text-sm">
          <Sparkles className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
          <div className="text-slate-800 dark:text-slate-100">
            <strong className="font-semibold">Budget estimé</strong> : jusqu’à{' '}
            <span className="font-mono tabular-nums">{maxCompanies.toLocaleString('fr-FR')}</span> entreprises sur{' '}
            <span className="font-mono tabular-nums">{Math.floor(maxDuration / 60)}h{(maxDuration % 60).toString().padStart(2, '0')}</span>,
            à <span className="font-mono tabular-nums">{maxRpm} req/min</span>.
          </div>
        </div>
      </Card>

      {/* Avancé */}
      <button
        type="button"
        onClick={() => setShowPerSource(!showPerSource)}
        className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
      >
        <ShieldAlert className="h-3.5 w-3.5" />
        Limites par source {showPerSource ? '(cacher)' : '(avancé)'}
      </button>

      {showPerSource ? (
        <div className="space-y-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
          {sources.map((s) => {
            const meta = ALL_SOURCES.find((m) => m.id === s);
            const lim = perSourceLimits[s] ?? {};
            return (
              <div key={s} className="grid grid-cols-1 items-center gap-2 md:grid-cols-[1fr_120px_120px]">
                <span className="text-sm font-medium text-slate-700 dark:text-slate-300">{meta?.label ?? s}</span>
                <div>
                  <label className="text-[10px] uppercase tracking-wider text-slate-500">RPM</label>
                  <Input
                    type="number"
                    min={1}
                    max={100}
                    value={lim.rpm ?? ''}
                    onChange={(e) => {
                      const raw = e.target.value;
                      const next: { rpm?: number; daily?: number } = { ...lim };
                      if (raw === '') delete next.rpm;
                      else next.rpm = Number(raw);
                      setPerSourceLimits({ ...perSourceLimits, [s]: next });
                    }}
                  />
                </div>
                <div>
                  <label className="text-[10px] uppercase tracking-wider text-slate-500">Daily quota</label>
                  <Input
                    type="number"
                    min={1}
                    max={50000}
                    value={lim.daily ?? ''}
                    onChange={(e) => {
                      const raw = e.target.value;
                      const next: { rpm?: number; daily?: number } = { ...lim };
                      if (raw === '') delete next.daily;
                      else next.daily = Number(raw);
                      setPerSourceLimits({ ...perSourceLimits, [s]: next });
                    }}
                  />
                </div>
              </div>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Sous-composants utilitaires
// ---------------------------------------------------------------------------
function SectionHeading({ title, hint }: { title: string; hint?: string }) {
  return (
    <div>
      <h2 className="text-base font-semibold text-slate-900 dark:text-white">{title}</h2>
      {hint ? <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{hint}</p> : null}
    </div>
  );
}

function Field({ label, required, children }: { label: string; required?: boolean; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1 inline-block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
        {label} {required ? <span className="text-rose-500">*</span> : null}
      </span>
      {children}
    </label>
  );
}

function NumberField({
  icon, label, hint, min, max, step, value, onChange,
}: {
  icon: React.ReactNode;
  label: string;
  hint?: string;
  min: number; max: number; step?: number;
  value: number;
  onChange: (v: number) => void;
}) {
  return (
    <div className="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
      <div className="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-slate-700 dark:text-slate-300">
        {icon}
        <span>{label}</span>
      </div>
      <Input
        type="number"
        min={min}
        max={max}
        step={step}
        value={value}
        onChange={(e) => {
          const v = Number(e.target.value);
          if (!Number.isNaN(v)) onChange(Math.max(min, Math.min(max, v)));
        }}
      />
      {hint ? <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{hint}</p> : null}
    </div>
  );
}

function extractApiMessage(err: unknown): string | null {
  if (typeof err === 'object' && err !== null) {
    const e = err as { response?: { data?: { message?: string; error?: string } } };
    return e.response?.data?.message ?? e.response?.data?.error ?? null;
  }
  return null;
}
