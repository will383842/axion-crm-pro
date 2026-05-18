/**
 * Sprint Pipeline 360° — AudienceBuilderPage.
 *
 * Builder visuel 2 colonnes : critères à gauche, preview live (debounced 500ms) à droite.
 * À chaque changement, on POST /audiences/preview et on affiche {companies, contacts}.
 * Création : POST /audiences → redirect /audiences/:id.
 */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { useForm } from 'react-hook-form';
import { useMutation } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  ChevronLeft, Sparkles, X, MapPin, Building, Tag, Mail, Users2, Layers,
} from 'lucide-react';
import { api } from '@/lib/api';
import {
  Button,
  Card,
  cn,
  Input,
  PageHeader,
  Spinner,
  StatusPill,
} from '@/components/ui';
import type {
  AudienceCondition,
  AudienceCriteria,
  EmailAudience,
} from './AudiencesListPage';

// ---------------------------------------------------------------------------
// Presets
// ---------------------------------------------------------------------------
const DEPT_PRESETS: Array<{ code: string; label: string }> = [
  { code: '75', label: 'Paris' },
  { code: '92', label: 'Hauts-de-Seine' },
  { code: '93', label: 'Seine-Saint-Denis' },
  { code: '69', label: 'Rhône' },
  { code: '13', label: 'Bouches-du-Rhône' },
  { code: '33', label: 'Gironde' },
  { code: '31', label: 'Haute-Garonne' },
  { code: '59', label: 'Nord' },
  { code: '67', label: 'Bas-Rhin' },
  { code: '44', label: 'Loire-Atlantique' },
];

const REGION_PRESETS: Array<{ code: string; label: string }> = [
  { code: '11', label: 'Île-de-France' },
  { code: '24', label: 'Centre-Val de Loire' },
  { code: '27', label: 'Bourgogne-Franche-Comté' },
  { code: '28', label: 'Normandie' },
  { code: '32', label: 'Hauts-de-France' },
  { code: '44', label: 'Grand Est' },
  { code: '52', label: 'Pays de la Loire' },
  { code: '53', label: 'Bretagne' },
  { code: '75', label: 'Nouvelle-Aquitaine' },
  { code: '76', label: 'Occitanie' },
  { code: '84', label: 'Auvergne-Rhône-Alpes' },
  { code: '93', label: 'Provence-Alpes-Côte d\'Azur' },
  { code: '94', label: 'Corse' },
];

const SIZE_PRESETS: Array<{ code: string; label: string }> = [
  { code: 'micro',   label: 'Micro (1-9)' },
  { code: 'tpe',     label: 'TPE (10-49)' },
  { code: 'pme',     label: 'PME (50-249)' },
  { code: 'eti',     label: 'ETI (250-4999)' },
  { code: 'grande',  label: 'Grande (5000+)' },
];

const SECTOR_PRESETS: Array<{ code: string; label: string }> = [
  { code: 'it_saas',                 label: 'IT / SaaS' },
  { code: 'btp',                     label: 'BTP' },
  { code: 'sante',                   label: 'Santé' },
  { code: 'commerce',                label: 'Commerce' },
  { code: 'services_pro',            label: 'Services pro' },
  { code: 'finance_assurance',       label: 'Finance / Assurance' },
  { code: 'industrie',               label: 'Industrie' },
  { code: 'hotellerie_restauration', label: 'Hôtellerie / Resto' },
  { code: 'transport',               label: 'Transport' },
  { code: 'agro_alimentaire',        label: 'Agro-alimentaire' },
];

const STATUS_PRESETS: Array<{ code: string; label: string }> = [
  { code: 'pending',              label: 'Pending' },
  { code: 'ready_for_outreach',   label: 'Prêt outreach' },
  { code: 'partial_email',        label: 'Email partiel' },
  { code: 'archived_no_email',    label: 'Archivé sans email' },
];

// ---------------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------------
interface BuilderForm {
  name: string;
  description: string;
}

interface PreviewResponse {
  companies: number;
  contacts: number;
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
export function AudienceBuilderPage() {
  const navigate = useNavigate();
  const { register, handleSubmit, watch, formState: { errors } } = useForm<BuilderForm>({
    defaultValues: { name: '', description: '' },
  });

  // États critères (multi-select chips)
  const [departments, setDepartments] = useState<string[]>([]);
  const [regions, setRegions] = useState<string[]>([]);
  const [sizes, setSizes] = useState<string[]>([]);
  const [sectors, setSectors] = useState<string[]>([]);
  const [statuses, setStatuses] = useState<string[]>(['ready_for_outreach']);
  const [qualityMin, setQualityMin] = useState<number>(0);
  const [hasEmail, setHasEmail] = useState<boolean>(false);
  const [tagsInput, setTagsInput] = useState<string>('');

  // Build criteria
  const criteria = useMemo<AudienceCriteria>(() => {
    const all: AudienceCondition[] = [];
    if (departments.length > 0) all.push({ field: 'department_code', op: 'in', value: departments });
    if (regions.length > 0)     all.push({ field: 'region_code',     op: 'in', value: regions });
    if (sizes.length > 0)       all.push({ field: 'size_category',   op: 'in', value: sizes });
    if (sectors.length > 0)     all.push({ field: 'sector_main',     op: 'in', value: sectors });
    if (statuses.length > 0)    all.push({ field: 'prospection_status', op: 'in', value: statuses });
    if (qualityMin > 0)         all.push({ field: 'quality_score',   op: 'gte', value: qualityMin });
    if (hasEmail)               all.push({ field: 'has_email',       op: 'eq',  value: true });

    const tagList = tagsInput
      .split(/[,\s]+/)
      .map((t) => t.trim())
      .filter((t) => t.length > 0);
    if (tagList.length > 0) all.push({ field: 'tags', op: 'contains_any', value: tagList });

    return { all };
  }, [departments, regions, sizes, sectors, statuses, qualityMin, hasEmail, tagsInput]);

  // Preview live (debounced 500ms)
  const [preview, setPreview] = useState<PreviewResponse | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [previewError, setPreviewError] = useState<string | null>(null);

  const fetchPreview = useCallback(async (c: AudienceCriteria) => {
    setPreviewLoading(true);
    setPreviewError(null);
    try {
      const r = await api.post<PreviewResponse>('/audiences/preview', { criteria: c });
      setPreview(r.data);
    } catch (err) {
      setPreviewError(extractApiMessage(err) ?? 'Preview indisponible');
      setPreview(null);
    } finally {
      setPreviewLoading(false);
    }
  }, []);

  useEffect(() => {
    const hasAny = (criteria.all?.length ?? 0) > 0;
    if (!hasAny) {
      setPreview(null);
      setPreviewError(null);
      return;
    }
    const timer = setTimeout(() => { void fetchPreview(criteria); }, 500);
    return () => { clearTimeout(timer); };
  }, [criteria, fetchPreview]);

  // Create mutation
  const createMutation = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<{ data: EmailAudience }>('/audiences', payload)).data,
  });

  const onSubmit = handleSubmit(async (form) => {
    if ((criteria.all?.length ?? 0) === 0) {
      toast.error('Ajoute au moins un critère');
      return;
    }
    try {
      const res = await createMutation.mutateAsync({
        name: form.name.trim(),
        description: form.description.trim() || undefined,
        criteria,
        is_active: true,
        auto_refresh: false,
      });
      toast.success('Audience créée');
      void navigate({ to: '/audiences/$audienceId', params: { audienceId: String(res.data.id) } });
    } catch (err) {
      toast.error(extractApiMessage(err) ?? 'Création impossible');
    }
  });

  const watchedName = watch('name');
  const canCreate = watchedName.trim().length > 0 && (criteria.all?.length ?? 0) > 0;

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Nouvelle audience"
        subtitle="Compose un segment dynamique. La preview se met à jour à chaque modification."
        breadcrumbs={[
          { label: 'Audiences', to: '/audiences' },
          { label: 'Nouvelle' },
        ]}
      />

      <form onSubmit={onSubmit} className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
        {/* Colonne gauche : builder */}
        <div className="space-y-5">
          {/* Identité */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<Sparkles className="h-4 w-4" />} title="Informations" />
            <Field label="Nom" required error={errors.name?.message}>
              <Input
                placeholder="Ex : PME Île-de-France IT — prêtes outreach"
                {...register('name', { required: 'Nom requis', maxLength: { value: 120, message: 'Max 120 caractères' } })}
                invalid={!!errors.name}
                maxLength={120}
              />
            </Field>
            <Field label="Description (optionnel)">
              <textarea
                className="min-h-[70px] w-full rounded-lg bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-slate-200 transition placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700"
                placeholder="Pour quelle campagne ? Quel objectif ?"
                maxLength={500}
                {...register('description', { maxLength: 500 })}
              />
            </Field>
          </Card>

          {/* Géographie */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<MapPin className="h-4 w-4" />} title="Géographie" />
            <Field label="Départements">
              <ChipsMultiSelect
                options={DEPT_PRESETS}
                selected={departments}
                onChange={setDepartments}
                placeholder="Aucun département"
              />
            </Field>
            <Field label="Régions">
              <ChipsMultiSelect
                options={REGION_PRESETS}
                selected={regions}
                onChange={setRegions}
                placeholder="Aucune région"
              />
            </Field>
          </Card>

          {/* Taille / Secteur */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<Building className="h-4 w-4" />} title="Taille et secteur" />
            <Field label="Tailles d'entreprise">
              <ChipsMultiSelect
                options={SIZE_PRESETS}
                selected={sizes}
                onChange={setSizes}
                placeholder="Toutes tailles"
              />
            </Field>
            <Field label="Secteurs">
              <ChipsMultiSelect
                options={SECTOR_PRESETS}
                selected={sectors}
                onChange={setSectors}
                placeholder="Tous secteurs"
              />
            </Field>
          </Card>

          {/* Qualité */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<Layers className="h-4 w-4" />} title="Qualité et statut" />
            <Field label="Statuts prospection">
              <ChipsMultiSelect
                options={STATUS_PRESETS}
                selected={statuses}
                onChange={setStatuses}
                placeholder="Tous statuts"
              />
            </Field>
            <Field label={`Score qualité minimum : ${qualityMin}`}>
              <input
                type="range"
                min={0}
                max={100}
                step={5}
                value={qualityMin}
                onChange={(e) => setQualityMin(Number(e.target.value))}
                className="w-full accent-sky-600"
              />
              <div className="flex justify-between text-[10px] text-slate-400">
                <span>0</span><span>25</span><span>50</span><span>75</span><span>100</span>
              </div>
            </Field>
            <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
              <input
                type="checkbox"
                checked={hasEmail}
                onChange={(e) => setHasEmail(e.target.checked)}
                className="h-4 w-4 rounded accent-sky-600"
              />
              <Mail className="h-4 w-4 text-slate-400" />
              A au moins un contact avec email
            </label>
          </Card>

          {/* Tags */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<Tag className="h-4 w-4" />} title="Tags personnalisés" />
            <Field label="Slugs séparés par virgule ou espace (contains_any)">
              <Input
                placeholder="ex : decisionnaire, growth, fintech"
                value={tagsInput}
                onChange={(e) => setTagsInput(e.target.value)}
              />
            </Field>
          </Card>
        </div>

        {/* Colonne droite : sticky preview */}
        <aside className="lg:sticky lg:top-6 lg:self-start">
          <Card padding="md" variant="glass" className="space-y-4 border border-sky-200/60 dark:border-sky-900/40">
            <div className="flex items-center gap-2">
              <Users2 className="h-4 w-4 text-sky-600 dark:text-sky-400" />
              <h2 className="text-sm font-semibold text-slate-900 dark:text-white">Preview live</h2>
              {previewLoading ? <Spinner size="sm" /> : null}
            </div>

            {previewError ? (
              <div className="rounded-lg bg-rose-50 p-3 text-xs text-rose-700 ring-1 ring-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:ring-rose-900/40">
                {previewError}
              </div>
            ) : preview ? (
              <div className="grid grid-cols-2 gap-3">
                <PreviewStat label="Entreprises" value={preview.companies} tone="sky" />
                <PreviewStat label="Contacts"    value={preview.contacts}  tone="violet" />
              </div>
            ) : (
              <div className="rounded-lg bg-slate-50 p-4 text-center text-xs text-slate-500 dark:bg-slate-800/40 dark:text-slate-400">
                Ajoute au moins un critère pour voir la preview.
              </div>
            )}

            {/* Recap critères */}
            {(criteria.all?.length ?? 0) > 0 ? (
              <div className="space-y-1.5">
                <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                  Critères ({criteria.all?.length})
                </div>
                <ul className="space-y-1">
                  {criteria.all?.map((c, i) => (
                    <li key={i} className="rounded-md bg-slate-50 px-2 py-1 text-[11px] font-mono text-slate-600 dark:bg-slate-800/60 dark:text-slate-400">
                      <span className="text-slate-900 dark:text-white">{c.field}</span>{' '}
                      <span className="text-slate-400">{c.op}</span>{' '}
                      <span className="text-sky-700 dark:text-sky-300">
                        {Array.isArray(c.value) ? `[${c.value.length}]` : String(c.value)}
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}

            <div className="space-y-2 border-t border-slate-100 pt-3 dark:border-slate-800">
              <Button
                type="submit"
                variant="primary"
                size="md"
                full
                iconLeft={<Sparkles className="h-4 w-4" />}
                loading={createMutation.isPending}
                disabled={!canCreate}
              >
                Créer l'audience
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                full
                iconLeft={<ChevronLeft className="h-3.5 w-3.5" />}
                onClick={() => { void navigate({ to: '/audiences' }); }}
              >
                Annuler
              </Button>
              {!canCreate ? (
                <p className="text-[11px] text-slate-500 dark:text-slate-400">
                  Renseigne un nom et au moins un critère pour activer la création.
                </p>
              ) : null}
            </div>
          </Card>
        </aside>
      </form>
    </div>
  );
}

// ---------------------------------------------------------------------------
// ChipsMultiSelect — sélecteur chips toggle
// ---------------------------------------------------------------------------
function ChipsMultiSelect({
  options,
  selected,
  onChange,
  placeholder,
}: {
  options: Array<{ code: string; label: string }>;
  selected: string[];
  onChange: (next: string[]) => void;
  placeholder?: string;
}) {
  function toggle(code: string) {
    if (selected.includes(code)) {
      onChange(selected.filter((c) => c !== code));
    } else {
      onChange([...selected, code]);
    }
  }
  return (
    <div className="space-y-2">
      {selected.length === 0 && placeholder ? (
        <div className="text-[11px] italic text-slate-400">{placeholder}</div>
      ) : null}
      <div className="flex flex-wrap gap-1.5">
        {options.map((opt) => {
          const active = selected.includes(opt.code);
          return (
            <button
              key={opt.code}
              type="button"
              onClick={() => toggle(opt.code)}
              className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium transition',
                active
                  ? 'bg-sky-500 text-white ring-1 ring-sky-600 shadow-sm'
                  : 'bg-slate-100 text-slate-700 ring-1 ring-slate-200 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-700',
              )}
            >
              <span className="font-mono text-[10px] opacity-70">{opt.code}</span>
              <span>{opt.label}</span>
              {active ? <X className="h-3 w-3" /> : null}
            </button>
          );
        })}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// PreviewStat
// ---------------------------------------------------------------------------
function PreviewStat({ label, value, tone }: { label: string; value: number; tone: 'sky' | 'violet' }) {
  const chip = tone === 'sky'
    ? 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300'
    : 'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300';
  return (
    <div className="rounded-xl bg-white p-3 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
      <span className={cn('inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider', chip)}>
        {label}
      </span>
      <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-900 dark:text-white">
        {value.toLocaleString('fr-FR')}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Form utils
// ---------------------------------------------------------------------------
function SectionHeading({ icon, title }: { icon?: React.ReactNode; title: string }) {
  return (
    <div className="flex items-center gap-2">
      {icon ? <span className="text-slate-400">{icon}</span> : null}
      <h2 className="text-sm font-semibold tracking-tight text-slate-900 dark:text-white">{title}</h2>
    </div>
  );
}

function Field({
  label, required, error, children,
}: {
  label: string;
  required?: boolean;
  error?: string | undefined;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="mb-1 inline-block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
        {label} {required ? <span className="text-rose-500">*</span> : null}
      </span>
      {children}
      {error ? (
        <span className="mt-1 inline-flex items-center gap-1 text-[11px] text-rose-600 dark:text-rose-400">
          <StatusPill tone="danger">{error}</StatusPill>
        </span>
      ) : null}
    </label>
  );
}

function extractApiMessage(err: unknown): string | null {
  if (typeof err === 'object' && err !== null) {
    const e = err as { response?: { data?: { message?: string; error?: string } } };
    return e.response?.data?.message ?? e.response?.data?.error ?? null;
  }
  return null;
}
