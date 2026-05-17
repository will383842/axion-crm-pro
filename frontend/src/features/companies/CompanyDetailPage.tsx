import { useState, useMemo } from 'react';
import { useParams } from '@tanstack/react-router';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  Avatar,
  Breadcrumbs,
  Button,
  Card,
  CardEyebrow,
  CardHeader,
  CardTitle,
  DropdownMenu,
  EmptyState,
  IconButton,
  PageShell,
  QualityBadge,
  SizeCategoryBadge,
  Spinner,
  cn,
} from '@/components/ui';
import { api } from '@/lib/api';
import { ContactsCard, type ContactItem } from './components/ContactsCard';
import { QualityScoreCard } from './components/QualityScoreCard';
import { EnrichmentTimeline, deriveTimelineFromSignals } from './components/EnrichmentTimeline';

interface CompanyDetail {
  id: number;
  siren: string;
  denomination?: string | null;
  naf?: string | null;
  naf_label?: string | null;
  legal_form?: string | null;
  effectif_range?: string | null;
  size_category?: string | null;
  address?: string | null;
  postcode?: string | null;
  city?: string | null;
  department?: string | null;
  website?: string | null;
  phone?: string | null;
  linkedin_url?: string | null;
  quality_score?: number | null;
  quality_breakdown?: {
    email?: number | null;
    phone?: number | null;
    website?: number | null;
    contact?: number | null;
  } | null;
  priority?: string | null;
  signals?: Record<string, unknown>;
  created_at?: string | null;
  enriched_at?: string | null;
  contacts?: ContactItem[];
}

export function CompanyDetailPage() {
  const qc = useQueryClient();
  const { companyId } = useParams({ strict: false }) as { companyId?: string };
  const [showRaw, setShowRaw] = useState(false);

  const { data: c, isLoading, isError } = useQuery({
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

  const timeline = useMemo(() => deriveTimelineFromSignals(c?.signals), [c?.signals]);

  if (isLoading) {
    return (
      <PageShell title="Chargement…">
        <div className="flex items-center gap-2 text-sm text-slate-500">
          <Spinner /> Chargement de la fiche entreprise…
        </div>
      </PageShell>
    );
  }
  if (isError || !c) {
    return (
      <PageShell title="Entreprise introuvable">
        <EmptyState
          title="404"
          description="Cette entreprise n'existe pas ou a été supprimée."
        />
      </PageShell>
    );
  }

  const name = c.denomination ?? c.siren;
  const dept = c.department ?? c.postcode?.slice(0, 2) ?? '—';
  const addressLine = [c.address, c.postcode, c.city].filter(Boolean).join(', ') || '—';

  return (
    <div className="px-6 py-6">
      <Breadcrumbs
        items={[
          { label: 'Entreprises', to: '/companies' },
          { label: name },
        ]}
        className="mb-3"
      />

      <header className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div className="flex min-w-0 items-start gap-4">
          <Avatar name={name} size="lg" />
          <div className="min-w-0">
            <h1 className="text-2xl font-semibold tracking-tight md:text-3xl bg-gradient-to-br from-slate-900 to-slate-600 bg-clip-text text-transparent dark:from-white dark:to-slate-300">
              {name}
            </h1>
            <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
              <span className="font-mono tabular-nums">SIREN {c.siren}</span>
              <Dot />
              <span>Dept {dept}</span>
              <Dot />
              <SizeCategoryBadge size={c.size_category} />
              <Dot />
              <QualityBadge score={c.quality_score} />
              {c.priority ? (
                <>
                  <Dot />
                  <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                    Priorité {c.priority}
                  </span>
                </>
              ) : null}
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Button
            variant="primary"
            size="md"
            loading={enrichMut.isPending}
            onClick={() => enrichMut.mutate()}
          >
            {enrichMut.isPending ? 'Enrichissement…' : 'Enrichir maintenant'}
          </Button>
          <Button variant="secondary" size="md">Exporter</Button>
          <DropdownMenu
            align="right"
            trigger={
              <IconButton label="Plus d'actions" variant="outline" size="md">
                <svg viewBox="0 0 20 20" className="h-4 w-4" fill="currentColor" aria-hidden>
                  <circle cx="4" cy="10" r="1.5" />
                  <circle cx="10" cy="10" r="1.5" />
                  <circle cx="16" cy="10" r="1.5" />
                </svg>
              </IconButton>
            }
            items={[
              { id: 'vcf', label: 'Exporter en vCard (VCF)' },
              { id: 'dup', label: 'Marquer doublon' },
              { id: 'sep', label: '', divider: true },
              { id: 'obsolete', label: 'Marquer obsolète', destructive: true },
            ]}
          />
        </div>
      </header>

      <div className="grid gap-6 lg:grid-cols-3">
        {/* LEFT COLUMN (2/3) */}
        <div className="space-y-6 lg:col-span-2">
          <Card padding="md">
            <CardHeader>
              <div>
                <CardEyebrow>Identité légale</CardEyebrow>
                <CardTitle>Informations entreprise</CardTitle>
              </div>
            </CardHeader>
            <dl className="grid grid-cols-2 gap-x-6 gap-y-4 text-sm">
              <Item label="SIREN"><span className="font-mono tabular-nums">{c.siren}</span></Item>
              <Item label="Forme juridique">{c.legal_form ?? '—'}</Item>
              <Item label="NAF">
                <span className="font-mono">{c.naf ?? '—'}</span>
                {c.naf_label ? <span className="ml-2 text-xs text-slate-500">{c.naf_label}</span> : null}
              </Item>
              <Item label="Effectif INSEE">{c.effectif_range ?? '—'}</Item>
              <Item label="Adresse" wide>{addressLine}</Item>
              <Item label="Site web">
                {c.website ? (
                  <a className="text-brand-600 hover:underline dark:text-sky-400" href={c.website} target="_blank" rel="noopener noreferrer">
                    {c.website}
                  </a>
                ) : '—'}
              </Item>
              <Item label="Téléphone">{c.phone ? <span className="tabular-nums">{c.phone}</span> : '—'}</Item>
              <Item label="LinkedIn">
                {c.linkedin_url ? (
                  <a className="text-brand-600 hover:underline dark:text-sky-400" href={c.linkedin_url} target="_blank" rel="noopener noreferrer">
                    Voir le profil →
                  </a>
                ) : '—'}
              </Item>
              <Item label="Date création">
                {c.created_at ? new Date(c.created_at).toLocaleDateString('fr-FR') : '—'}
              </Item>
              <Item label="Enrichi le">
                {c.enriched_at ? new Date(c.enriched_at).toLocaleString('fr-FR') : 'Jamais'}
              </Item>
            </dl>
          </Card>

          <EnrichmentTimeline steps={timeline} />

          {/* Raw data accordion */}
          <Card padding="md">
            <button
              type="button"
              onClick={() => setShowRaw((v) => !v)}
              className="flex w-full items-center justify-between gap-2 text-left"
              aria-expanded={showRaw}
            >
              <div>
                <CardEyebrow>Debug</CardEyebrow>
                <CardTitle>Données brutes (signals)</CardTitle>
              </div>
              <svg
                viewBox="0 0 20 20"
                className={cn('h-4 w-4 text-slate-400 transition-transform', showRaw && 'rotate-180')}
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                aria-hidden
              >
                <path d="M6 8l4 4 4-4" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </button>
            {showRaw ? (
              c.signals && Object.keys(c.signals).length > 0 ? (
                <pre className="mt-3 max-h-80 overflow-auto rounded-lg bg-slate-50 p-3 text-xs leading-relaxed text-slate-700 ring-1 ring-slate-200 dark:bg-slate-950 dark:text-slate-300 dark:ring-slate-800">
                  {JSON.stringify(c.signals, null, 2)}
                </pre>
              ) : (
                <p className="mt-3 text-sm text-slate-500 dark:text-slate-400">Aucun signal capté.</p>
              )
            ) : null}
          </Card>
        </div>

        {/* RIGHT COLUMN (1/3) */}
        <aside className="space-y-6">
          <ContactsCard contacts={c.contacts ?? []} />
          <QualityScoreCard
            score={c.quality_score}
            breakdown={c.quality_breakdown ?? undefined}
          />
          <Card padding="md">
            <CardHeader>
              <div>
                <CardEyebrow>Actions rapides</CardEyebrow>
                <CardTitle>Workflow</CardTitle>
              </div>
            </CardHeader>
            <div className="flex flex-col gap-2">
              <Button
                variant="primary"
                size="md"
                full
                loading={enrichMut.isPending}
                onClick={() => enrichMut.mutate()}
              >
                Enrichir maintenant
              </Button>
              <Button variant="secondary" size="md" full>
                Exporter (vCard / VCF)
              </Button>
              <Button variant="ghost" size="md" full>
                Marquer obsolète
              </Button>
            </div>
          </Card>
        </aside>
      </div>
    </div>
  );
}

function Item({ label, children, wide }: { label: string; children: React.ReactNode; wide?: boolean }) {
  return (
    <div className={wide ? 'col-span-2' : ''}>
      <dt className="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
        {label}
      </dt>
      <dd className="mt-1 text-slate-900 dark:text-white">{children}</dd>
    </div>
  );
}

function Dot() {
  return <span aria-hidden className="text-slate-300 dark:text-slate-600">·</span>;
}
