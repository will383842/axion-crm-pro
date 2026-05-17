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
import { Hash, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { api } from '@/lib/api';
import {
  Button,
  Card,
  EmptyState,
  FormField,
  KpiCard,
  Modal,
  PageHeader,
  Skeleton,
} from '@/components/ui';

// ---------------------------------------------------------------------------
// Types & constantes
// ---------------------------------------------------------------------------
type TagCategory = 'geo' | 'sector' | 'size' | 'intent' | 'custom';
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
  created_at?: string;
  updated_at?: string;
};

const CATEGORIES: ReadonlyArray<{ key: TagCategory; label: string; description: string }> = [
  { key: 'geo', label: 'Géographie', description: 'Région et département (auto)' },
  { key: 'sector', label: 'Secteur', description: 'NAF mapping (auto)' },
  { key: 'size', label: 'Taille', description: 'Effectif (auto)' },
  { key: 'intent', label: 'Intent (LLM)', description: 'Classification IA' },
  { key: 'custom', label: 'Custom', description: 'Tags manuels' },
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
  const qc = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ['tags'],
    queryFn: async () => (await api.get<{ data: Tag[] }>('/tags')).data.data,
  });

  const tags = useMemo<Tag[]>(() => data ?? [], [data]);

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
      toast.success('Tag créé');
      setModalOpen(false);
    },
    onError: (err: unknown) => {
      const status = extractStatus(err);
      if (status === 409) {
        toast.error('Slug déjà utilisé');
        return;
      }
      toast.error(extractApiMessage(err) ?? 'Erreur création tag');
    },
  });

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => api.delete(`/tags/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['tags'] });
      toast.success('Tag supprimé');
    },
    onError: (err: unknown) => {
      const status = extractStatus(err);
      if (status === 403) {
        toast.error('Impossible : tags auto/LLM protégés');
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
    <div className="px-6 py-6">
      <PageHeader
        title="Tags"
        subtitle="Classification multi-axes des entreprises (géographie, secteur, taille, intent, custom)."
        actions={
          <Button variant="primary" size="md" iconLeft={<Plus className="h-4 w-4" />} onClick={openCreateModal}>
            Nouveau tag
          </Button>
        }
      />

      {/* KPIs */}
      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <KpiCard tone="sky"     label="Total tags" value={counts.total}  sublabel="tous axes confondus" />
        <KpiCard tone="emerald" label="Auto"       value={counts.auto}   sublabel="géo, secteur, taille" />
        <KpiCard tone="violet"  label="Manuel"     value={counts.manual} sublabel="créés par l'équipe" />
        <KpiCard tone="amber"   label="LLM"        value={counts.llm}    sublabel="intent classification IA" />
      </div>

      {/* Body */}
      {isLoading ? (
        <div className="space-y-4">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-32 w-full" />
          ))}
        </div>
      ) : tags.length === 0 ? (
        <EmptyState
          icon={<Hash className="h-8 w-8" />}
          title="Aucun tag"
          description="Crée ton premier tag manuel ou attends que le pipeline d'enrichissement génère les tags auto."
          action={
            <Button variant="primary" size="md" iconLeft={<Plus className="h-4 w-4" />} onClick={openCreateModal}>
              Créer un tag
            </Button>
          }
        />
      ) : (
        <div className="space-y-6">
          {CATEGORIES.map((cat) => {
            const items = tags.filter((t) => t.category === cat.key);
            if (items.length === 0) return null;
            return (
              <Card key={cat.key} padding="md">
                <div className="mb-3 flex items-center justify-between">
                  <div>
                    <h3 className="font-medium text-slate-900 dark:text-slate-100">{cat.label}</h3>
                    <p className="text-xs text-slate-500 dark:text-slate-400">{cat.description}</p>
                  </div>
                  <span className="text-xs text-slate-500 dark:text-slate-400">{items.length} tag{items.length > 1 ? 's' : ''}</span>
                </div>
                <div className="flex flex-wrap gap-2">
                  {items.map((t) => (
                    <TagPill
                      key={t.id}
                      tag={t}
                      onDelete={() => deleteMutation.mutate(t.id)}
                      deleting={deleteMutation.isPending}
                    />
                  ))}
                </div>
              </Card>
            );
          })}
        </div>
      )}

      {/* Modal création */}
      <Modal open={modalOpen} onClose={() => setModalOpen(false)} title="Nouveau tag" size="md">
        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-1">
          <FormField
            label="Nom"
            placeholder="Ex : VIP Client"
            autoFocus
            required
            error={form.formState.errors.name?.message}
            {...form.register('name')}
          />

          <FormField
            label="Slug (optionnel)"
            placeholder="vip-client"
            helpText="Auto-généré depuis le nom si vide. a-z, 0-9, - uniquement."
            error={form.formState.errors.slug?.message}
            {...form.register('slug')}
          />

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
}: {
  tag: Tag;
  onDelete: () => void;
  deleting: boolean;
}) {
  const canEdit = tag.kind === 'manual';
  return (
    <span className={`inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs ${pillClass(tag.color)}`}>
      <span className="font-medium">{tag.name}</span>
      <span className="opacity-60 tabular-nums">{tag.companies_count}</span>
      {canEdit ? (
        <button
          type="button"
          onClick={onDelete}
          disabled={deleting}
          className="opacity-50 transition hover:opacity-100 disabled:cursor-not-allowed disabled:opacity-30"
          aria-label={`Supprimer le tag ${tag.name}`}
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
function extractApiMessage(err: unknown): string | null {
  if (typeof err === 'object' && err !== null) {
    const e = err as { response?: { data?: { message?: string; error?: string } } };
    return e.response?.data?.message ?? e.response?.data?.error ?? null;
  }
  return null;
}

function extractStatus(err: unknown): number | null {
  if (typeof err === 'object' && err !== null) {
    const e = err as { response?: { status?: number } };
    return e.response?.status ?? null;
  }
  return null;
}
