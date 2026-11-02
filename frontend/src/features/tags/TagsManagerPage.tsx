/**
 * C13 — TagsManagerPage.
 *
 * Manager des tags multi-axes (geo / sector / size / intent / custom).
 * Lecture seule pour kind=auto|llm, création/suppression possible pour kind=manual.
 * Card-based, KPIs en haut, sections groupées par catégorie, modal de création.
 */
import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link } from '@tanstack/react-router';
import { Download, Hash, Megaphone, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { misAJour } from '@/lib/fraicheur';
import { api, messageApiLisible } from '@/lib/api';
import { telechargerBlob } from '@/features/listes/listes';
import { useProduitsVisibles } from '@/features/socle/produits/useProduits';
import {
  Button,
  Card,
  EmptyState,
  FormField,
  KpiCard,
  Modal,
  PageHeader,
  QueryErrorState,
  Skeleton,
} from '@/components/ui';

// ---------------------------------------------------------------------------
// Types & constantes
// ---------------------------------------------------------------------------
type TagCategory = 'geo' | 'sector' | 'size' | 'intent' | 'custom' | 'ia';
type TagKind = 'auto' | 'manual' | 'llm';

type Tag = {
  id: number;
  slug: string;
  name: string;
  color: string;
  category: TagCategory;
  kind: TagKind;
  description: string | null;
  companies_count: number;
  /** Fiches de l'étiquette qu'on peut joindre par e-mail (absent : inconnu). */
  joignables_count?: number;
  created_at?: string;
  updated_at?: string;
};

// Catégories qu'on peut CHOISIR en créant un tag à la main.
const CATEGORIES: ReadonlyArray<{ key: TagCategory; label: string; description: string }> = [
  { key: 'geo', label: 'Géographie', description: 'Région et département (auto)' },
  { key: 'sector', label: 'Secteur et métier', description: 'Secteur et métier, depuis le code NAF (auto)' },
  { key: 'size', label: 'Taille', description: 'Effectif (auto)' },
  { key: 'intent', label: 'Provenance et intérêt', description: 'Origine de la fiche et service demandé' },
  { key: 'custom', label: 'Personnalisées', description: 'Étiquettes créées à la main' },
];

// Catégories AFFICHÉES : les précédentes, plus celle des étiquettes proposées
// par l'IA (chantier 2, 2026-09-29) — elles étaient mêlées à « intent ». On ne
// crée pas une étiquette IA à la main : elle n'est pas proposée au formulaire.
const CATEGORIES_AFFICHEES: ReadonlyArray<{ key: TagCategory; label: string; description: string }> = [
  ...CATEGORIES,
  { key: 'ia', label: 'Proposées par l’IA', description: 'Étiquettes suggérées par la classification IA' },
];

type ColorName = 'slate' | 'sky' | 'violet' | 'emerald' | 'amber' | 'rose' | 'indigo';
const COLORS: ReadonlyArray<ColorName> = ['slate', 'sky', 'violet', 'emerald', 'amber', 'rose', 'indigo'];

// Tailwind ne peut pas générer des classes dynamiques `bg-${color}-100`, on mappe explicitement.
const COLOR_PILL: Record<ColorName, string> = {
  slate:   'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
  sky:     'bg-sky-100 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300',
  violet:  'bg-violet-100 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300',
  emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
  amber:   'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
  rose:    'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
  indigo:  'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300',
};

function pillClass(color: string): string {
  return (COLORS as readonly string[]).includes(color)
    ? COLOR_PILL[color as ColorName]
    : COLOR_PILL.slate;
}

// ---------------------------------------------------------------------------
// Form schema
// ---------------------------------------------------------------------------
const tagSchema = z.object({
  name: z.string().min(1, 'Nom requis').max(120, '120 caractères max'),
  slug: z
    .string()
    .regex(/^[a-z0-9-]*$/, 'a-z, 0-9, - uniquement')
    .max(64, '64 caractères max')
    .optional()
    .or(z.literal('')),
  category: z.enum(['geo', 'sector', 'size', 'intent', 'custom']),
  color: z.enum(['slate', 'sky', 'violet', 'emerald', 'amber', 'rose', 'indigo']),
  description: z.string().max(500, '500 caractères max').optional().or(z.literal('')),
});

type TagFormValues = z.infer<typeof tagSchema>;

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
export function TagsManagerPage() {
  const [modalOpen, setModalOpen] = useState(false);
  const [choisieSlug, setChoisieSlug] = useState<string | null>(null);
  // DM1-CIB — le bouton « Campagne vers cette étiquette » n'existe qu'avec Envoi.
  const envoiVisible = useProduitsVisibles().envoi;
  const qc = useQueryClient();

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['tags'],
    // Lot 3 — la réponse entière : `meta.comptes_calcules_le` date les
    // comptes par étiquette (servis depuis un cache serveur).
    queryFn: async () =>
      (await api.get<{ data: Tag[]; meta?: { comptes_calcules_le?: string | null; gestes_ciblage?: boolean } }>('/tags')).data,
  });

  const tags = useMemo<Tag[]>(() => data?.data ?? [], [data]);
  const comptesMisAJour = misAJour(data?.meta?.comptes_calcules_le ?? null);
  // DM1-CIB — l'étiquette choisie, son export et ses liens de campagne ne
  // paraissent que si le serveur les annonce (`meta.gestes_ciblage`). Le vrai
  // serveur ne l'envoie pas : en production, la page reste celle d'avant.
  const gestes = data?.meta?.gestes_ciblage === true;
  // La campagne exige en plus Envoi dans l'offre.
  const campagne = gestes && envoiVisible;

  // L'étiquette choisie : celle qu'on a cliquée, sinon la première étiquette
  // créée à la main (le salon de la démo en tête).
  const choisie = useMemo<Tag | null>(() => {
    if (!gestes) return null;
    const manuelles = tags.filter((t) => t.kind === 'manual');
    return manuelles.find((t) => t.slug === choisieSlug) ?? manuelles.find((t) => t.slug === 'salon-artisans-2026') ?? manuelles[0] ?? null;
  }, [tags, choisieSlug, gestes]);

  const exporter = useMutation({
    // La route réelle de l'export des entreprises, filtrée par étiquette.
    mutationFn: async (tag: Tag) => {
      const r = await api.get<Blob>('/companies/export', { params: { tag: tag.slug }, responseType: 'blob' });
      telechargerBlob(`etiquette-${tag.slug}.csv`, r.data);
      return tag.companies_count;
    },
    onSuccess: (n) => toast.success(`${n} fiche${n > 1 ? 's' : ''} exportée${n > 1 ? 's' : ''}.`),
    onError: (err: unknown) => toast.error(extractApiMessage(err) ?? 'Export impossible'),
  });

  const counts = useMemo(() => {
    return {
      total: tags.length,
      auto: tags.filter((t) => t.kind === 'auto').length,
      manual: tags.filter((t) => t.kind === 'manual').length,
      llm: tags.filter((t) => t.kind === 'llm').length,
    };
  }, [tags]);

  const createMutation = useMutation({
    mutationFn: async (payload: TagFormValues) => {
      const body: Record<string, unknown> = {
        name: payload.name,
        category: payload.category,
        color: payload.color,
      };
      if (payload.slug && payload.slug.length > 0) body['slug'] = payload.slug;
      if (payload.description && payload.description.length > 0) body['description'] = payload.description;
      return (await api.post<{ data: Tag }>('/tags', body)).data.data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['tags'] });
      toast.success('Étiquette créée');
      setModalOpen(false);
    },
    onError: (err: unknown) => {
      const status = extractStatus(err);
      if (status === 409) {
        toast.error('Une étiquette porte déjà ce nom');
        return;
      }
      toast.error(extractApiMessage(err) ?? 'Création de l’étiquette impossible');
    },
  });

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => api.delete(`/tags/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['tags'] });
      toast.success('Étiquette supprimée');
    },
    onError: (err: unknown) => {
      const status = extractStatus(err);
      if (status === 403) {
        toast.error('Impossible : les étiquettes automatiques ou proposées par l’IA sont protégées');
        return;
      }
      toast.error(extractApiMessage(err) ?? 'Suppression impossible');
    },
  });

  const form = useForm<TagFormValues>({
    resolver: zodResolver(tagSchema),
    defaultValues: { name: '', slug: '', category: 'custom', color: 'slate', description: '' },
  });

  const onSubmit = (values: TagFormValues) => {
    createMutation.mutate(values);
  };

  const openCreateModal = () => {
    form.reset({ name: '', slug: '', category: 'custom', color: 'slate', description: '' });
    setModalOpen(true);
  };

  return (
    <div>
      <PageHeader
        title="Étiquettes"
        subtitle="Pour classer les entreprises : zone, secteur, taille, intérêt, et les vôtres."
        actions={
          <Button variant={campagne ? 'secondary' : 'primary'} size="md" iconLeft={<Plus className="h-4 w-4" />} onClick={openCreateModal}>
            Nouvelle étiquette
          </Button>
        }
        {...(comptesMisAJour ? { badge: <span className="text-xs text-slate-500">Nombre de fiches {comptesMisAJour}</span> } : {})}
      />

      {/* KPIs */}
      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <KpiCard tone="sky"     label="Total" value={counts.total}  sublabel="tous axes confondus" />
        <KpiCard tone="emerald" label="Automatiques" value={counts.auto} sublabel="géographie, secteur, taille" />
        <KpiCard tone="violet"  label="Personnalisées" value={counts.manual} sublabel="créées à la main" />
        <KpiCard tone="amber"   label="Proposées par l’IA" value={counts.llm} sublabel="suggérées automatiquement" />
      </div>

      {/* DM1-CIB — l'étiquette choisie : ses fiches, ses joignables, la campagne. */}
      {choisie !== null ? (
        <Card padding="md" className="mb-6 border-violet-200 bg-violet-50/40" aria-label="Étiquette choisie">
          <div className="mb-2 flex flex-wrap items-center gap-2">
            <Hash className="h-4 w-4 text-violet-700" aria-hidden="true" />
            <h2 className="text-lg font-bold text-slate-900">{choisie.slug}</h2>
            <span className="rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-700">Choisie</span>
          </div>
          <p className="mb-3 text-sm text-slate-700">
            <strong>{choisie.companies_count} fiche{choisie.companies_count > 1 ? 's' : ''}</strong>
            {choisie.joignables_count !== undefined ? <> · {choisie.joignables_count} joignable{choisie.joignables_count > 1 ? 's' : ''}</> : null}
          </p>
          {choisie.joignables_count !== undefined && choisie.companies_count > 0 ? (
            <div
              role="meter"
              aria-valuemin={0}
              aria-valuemax={choisie.companies_count}
              aria-valuenow={choisie.joignables_count}
              aria-label={`Joignables : ${choisie.joignables_count} sur ${choisie.companies_count}`}
              className="mb-4"
            >
              <div className="mb-1 flex justify-between text-xs text-slate-600">
                <span>Joignables</span>
                <span>{choisie.joignables_count} sur {choisie.companies_count}</span>
              </div>
              <div className="h-2 overflow-hidden rounded-full bg-slate-200">
                <div className="h-full rounded-full bg-emerald-600" style={{ width: `${Math.round((choisie.joignables_count / choisie.companies_count) * 100)}%` }} />
              </div>
            </div>
          ) : null}
          <div className="flex flex-wrap gap-2">
            {envoiVisible ? (
              <Link
                to="/envoi/campagnes/nouvelle"
                search={{ etiquette: choisie.slug } as never}
                className="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-produit px-4 text-sm font-medium text-white shadow-produit transition hover:bg-produit-fonce focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-produit focus-visible:ring-offset-1 sm:h-9"
              >
                <Megaphone className="h-4 w-4" aria-hidden="true" />
                Campagne vers cette étiquette
              </Link>
            ) : null}
            <Button variant="secondary" size="md" className="h-11 sm:h-9" iconLeft={<Download className="h-4 w-4" />} loading={exporter.isPending} onClick={() => exporter.mutate(choisie)}>
              Exporter (CSV)
            </Button>
          </div>
        </Card>
      ) : null}

      {/* Body — P0-3 : une panne n'est jamais « Aucun tag ». */}
      {error !== null && data === undefined ? (
        <QueryErrorState error={error} contexte="les étiquettes" onRetry={() => void refetch()} />
      ) : isLoading ? (
        <div className="space-y-4">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-32 w-full" />
          ))}
        </div>
      ) : tags.length === 0 ? (
        <EmptyState
          icon={<Hash className="h-8 w-8" />}
          title="Aucune étiquette"
          description="Aucune étiquette pour l’instant."
          action={
            <Button variant="primary" size="md" iconLeft={<Plus className="h-4 w-4" />} onClick={openCreateModal}>
              Créer une étiquette
            </Button>
          }
        />
      ) : (
        <div className="space-y-6">
          {CATEGORIES_AFFICHEES.map((cat) => {
            const items = tags.filter((t) => t.category === cat.key);
            if (items.length === 0) return null;
            return (
              <Card key={cat.key} padding="md">
                <div className="mb-3 flex items-center justify-between">
                  <div>
                    <h3 className="font-medium text-slate-900 dark:text-slate-100">{cat.label}</h3>
                    <p className="text-xs text-slate-500 dark:text-slate-400">{cat.description}</p>
                  </div>
                  <span className="text-xs text-slate-500 dark:text-slate-400">{items.length} étiquette{items.length > 1 ? 's' : ''}</span>
                </div>
                <div className="flex flex-wrap gap-2">
                  {items.map((t) => (
                    <TagPill
                      key={t.id}
                      tag={t}
                      onDelete={() => deleteMutation.mutate(t.id)}
                      deleting={deleteMutation.isPending}
                      choisie={choisie?.slug === t.slug}
                      onChoose={() => setChoisieSlug(t.slug)}
                      avecChoix={gestes}
                      avecCampagne={campagne}
                    />
                  ))}
                </div>
              </Card>
            );
          })}
        </div>
      )}

      {/* Modal création */}
      <Modal open={modalOpen} onClose={() => setModalOpen(false)} title="Nouvelle étiquette" size="md">
        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-1">
          <FormField
            label="Nom"
            placeholder="Ex : VIP Client"
            autoFocus
            required
            error={form.formState.errors.name?.message}
            {...form.register('name')}
          />

          {/* Lot 2 UX — le champ « Slug » n'est plus proposé : l'adresse courte
              se calcule depuis le nom côté serveur quand elle est vide. */}

          <div className="mb-3">
            <label htmlFor="tag-category" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">
              Catégorie
            </label>
            <select
              id="tag-category"
              {...form.register('category')}
              className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
            >
              {CATEGORIES.map((c) => (
                <option key={c.key} value={c.key}>{c.label}</option>
              ))}
            </select>
          </div>

          <div className="mb-3">
            <label className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Couleur</label>
            <div className="flex flex-wrap gap-2">
              {COLORS.map((c) => {
                const checked = form.watch('color') === c;
                return (
                  <label key={c} className="cursor-pointer">
                    <input
                      type="radio"
                      value={c}
                      {...form.register('color')}
                      className="sr-only"
                    />
                    <span
                      className={`inline-flex items-center rounded-full px-3 py-1 text-xs ring-1 ring-offset-1 dark:ring-offset-slate-900 ${
                        checked ? 'ring-slate-900 dark:ring-white' : 'ring-transparent'
                      } ${COLOR_PILL[c]}`}
                    >
                      {c}
                    </span>
                  </label>
                );
              })}
            </div>
          </div>

          <div className="mb-3">
            <label htmlFor="tag-description" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">
              Description (optionnel)
            </label>
            <textarea
              id="tag-description"
              {...form.register('description')}
              className="min-h-[60px] w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
              placeholder="Notes internes…"
            />
            {form.formState.errors.description?.message ? (
              <p role="alert" className="mt-1 text-xs text-rose-600">
                {form.formState.errors.description.message}
              </p>
            ) : null}
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="ghost" onClick={() => setModalOpen(false)}>
              Annuler
            </Button>
            <Button type="submit" variant="primary" disabled={createMutation.isPending} loading={createMutation.isPending}>
              {createMutation.isPending ? 'Création…' : 'Créer'}
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  );
}

// ---------------------------------------------------------------------------
// TagPill
// ---------------------------------------------------------------------------
function TagPill({
  tag,
  onDelete,
  deleting,
  choisie,
  onChoose,
  avecChoix,
  avecCampagne,
}: {
  tag: Tag;
  onDelete: () => void;
  deleting: boolean;
  choisie: boolean;
  onChoose: () => void;
  /** Le nom devient un bouton « choisir » seulement si le serveur annonce les gestes du ciblage. */
  avecChoix: boolean;
  avecCampagne: boolean;
}) {
  const canEdit = tag.kind === 'manual';
  return (
    <span className={`inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs ${pillClass(tag.color)}`}>
      {canEdit && avecChoix ? (
        <button type="button" onClick={onChoose} aria-pressed={choisie} className="font-medium underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 max-sm:min-h-11">
          {tag.name}
        </button>
      ) : (
        <span className="font-medium">{tag.name}</span>
      )}
      <span className="opacity-60 tabular-nums">{tag.companies_count}</span>
      {canEdit && avecCampagne ? (
        <Link
          to="/envoi/campagnes/nouvelle"
          search={{ etiquette: tag.slug } as never}
          aria-label={`Campagne vers ${tag.slug}`}
          title="Campagne vers cette étiquette"
          className="-my-1 inline-flex items-center justify-center rounded-full opacity-60 transition hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 max-sm:h-11 max-sm:w-11"
        >
          <Megaphone className="h-4 w-4" aria-hidden="true" />
        </Link>
      ) : null}
      {canEdit ? (
        <button
          type="button"
          onClick={onDelete}
          disabled={deleting}
          className="opacity-50 transition hover:opacity-100 disabled:cursor-not-allowed disabled:opacity-30"
          aria-label={`Supprimer l’étiquette ${tag.name}`}
        >
          <Trash2 className="h-3 w-3" />
        </button>
      ) : null}
    </span>
  );
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
/** Lot 2 UX — jamais un code brut à l'écran (voir `messageApiLisible`). */
function extractApiMessage(err: unknown): string | null {
  return messageApiLisible(err);
}

function extractStatus(err: unknown): number | null {
  if (typeof err === 'object' && err !== null) {
    const e = err as { response?: { status?: number } };
    return e.response?.status ?? null;
  }
  return null;
}
