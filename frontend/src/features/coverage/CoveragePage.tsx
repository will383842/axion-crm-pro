import { Suspense, lazy, useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { api } from '@/lib/api';
import { toast } from 'sonner';

// D27-001 — `Stat` vient du systeme, il n'est plus recopie ici.
//
// Mesure du 2026-08-22 : `src/components/ui/Stat.tsx` etait exporte par
// `src/components/ui/index.ts:35` et n'avait AUCUN importateur ; cet ecran en
// portait une copie manuscrite, plus courte de toutes ses classes `dark:`. Le
// mode sombre y rendait donc deux cartouches blancs au milieu d'un panneau
// sombre — une divergence qu'aucune revue du systeme ne pouvait voir, puisque
// la copie ne s'appelait pas depuis le systeme.
//
// Deux consequences a garder en tete si l'apparence surprend : la version du
// systeme ajoute les classes `dark:` (c'est le correctif) et `tabular-nums`
// sur la valeur (les chiffres ne dansent plus quand le compteur change).
import { Button, LiveBadge, Modal, PageHeader, QueryErrorState, Stat } from '@/components/ui';

// G42-003 — la carte est chargee A LA DEMANDE, sur ce seul ecran.
//
// Mesure du 2026-08-20 (`pnpm build`, vite 6.4.2) : `maplibre-gl` et sa
// feuille de style pesent 802 715 o de morceau produit, et l'import STATIQUE
// qui se trouvait ici les faisait remonter jusqu'a `src/main.tsx` via
// `routeTree.tsx` — donc jusqu'a `dist/index.html`, qui portait :
//     <link rel="modulepreload" href="/assets/maplibre-CaQzARel.js">
// 802 715 o telecharges pour afficher `/login`, sur les 37 routes, alors
// qu'UN seul ecran sur 37 emploie la carte.
//
// ⚠️ Le morceau nomme de `vite.config.ts` (`manualChunks: { maplibre: [...] }`)
// ne suffisait PAS et ne pouvait pas suffire : il choisit dans QUEL fichier le
// code atterrit, pas QUAND il est telecharge. Tant qu'une arete statique y
// mene, Rollup en fait une dependance statique de l'entree et Vite la
// prefetche. Seul `import()` coupe l'arete.

import { scoreAffichable, statsCouverture, type Cell, type Level } from './statsCouverture';

const FranceCoverageMap = lazy(async () => ({
  default: (await import('./FranceCoverageMap')).FranceCoverageMap,
}));



// Lot 4 (audit P1-5, 2026-10-02) — les trois « modes » ont disparu. En mode
// « Action », un clic sur un département lançait une collecte SUR-LE-CHAMP :
// une erreur de clic coûtait du quota et des appels externes. Désormais un
// clic ne fait que SÉLECTIONNER ; la collecte passe par un bouton du panneau,
// puis par une confirmation qui nomme le département et le volume.

/** Volume d'une collecte lancée depuis la carte. */
const VOLUME_COLLECTE = 100;

/**
 * Plafond d'un enrichissement lancé depuis la carte. C'est le plafond par
 * défaut du serveur (`CoverageController::enrich`, `limit` ≤ 50 000) : on
 * l'envoie EXPLICITEMENT pour que le chiffre annoncé dans la confirmation
 * soit celui qui s'applique.
 */
const PLAFOND_ENRICHISSEMENT = 50_000;

/** Ce que la confirmation doit faire valider : l'action ET la zone visée. */
interface DemandeConfirmation {
  action: 'recuperer' | 'enrichir';
  code: string;
  name: string;
  /** Entreprises déjà en base pour ce département (pour annoncer le volume). */
  total: number;
}

function volumeEnrichissement(total: number): number {
  return Math.min(Math.max(total, 0), PLAFOND_ENRICHISSEMENT);
}

const LEVELS: Array<{ id: Level; label: string }> = [
  { id: 'region',     label: 'Régions' },
  { id: 'department', label: 'Départements' },
  { id: 'city',       label: 'Villes' },
];

export function CoveragePage() {
  const [level, setLevel] = useState<Level>('department');
  const [selected, setSelected] = useState<string | null>(null);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['coverage', level],
    queryFn: async () => {
      const r = await api.get<{ cells: Cell[]; quality_a_recalculer_pct?: number | null }>('/coverage', { params: { level } });
      return r.data;
    },
    refetchInterval: 60_000,
  });

  const cells = useMemo(() => data?.cells ?? [], [data]);
  const scoreVisible = scoreAffichable(data?.quality_a_recalculer_pct);
  // P0-3 — une panne n'est pas une France vide : la carte cède la place à l'erreur.
  const echec = error !== null && data === undefined;

  const stats = useMemo(() => statsCouverture(cells, level), [cells, level]);

  const selectedCell = selected ? cells.find((c) => c.code === selected) ?? null : null;

  // Étape 1 — Récupérer (découverte seule) et étape 2 — Enrichir (fiches déjà
  // en base). L'une comme l'autre interroge des services extérieurs : AUCUNE
  // n'est appelée directement par un clic, seulement par « Confirmer ».
  const [aConfirmer, setAConfirmer] = useState<DemandeConfirmation | null>(null);
  const boutonAnnuler = useRef<HTMLButtonElement | null>(null);
  // Verrou synchrone : `isPending` n'est vrai qu'au rendu SUIVANT, deux clics
  // dans le même instant passeraient tous les deux sans lui.
  const envoiEnCours = useRef(false);
  const lancement = useMutation({
    mutationFn: async (demande: DemandeConfirmation) => {
      if (demande.action === 'recuperer') {
        await api.post('/coverage/launch', { department: demande.code, limit: VOLUME_COLLECTE, enrich: false });
        return { demande, enFile: null };
      }
      const r = await api.post<{ queued?: number }>('/coverage/enrich', {
        department: demande.code,
        limit: PLAFOND_ENRICHISSEMENT,
      });
      return { demande, enFile: r.data?.queued ?? 0 };
    },
    onSuccess: ({ demande, enFile }) => {
      const zone = `${demande.name} (${demande.code})`;
      if (demande.action === 'recuperer') {
        toast.success(`Récupération lancée : ${zone}`);
      } else if (enFile !== null && enFile > 0) {
        toast.success(`Enrichissement lancé : ${enFile.toLocaleString('fr-FR')} entreprise(s), ${zone}`);
      } else {
        toast.success(`Aucune entreprise à enrichir pour ${zone} : récupérez-les d’abord.`);
      }
      setAConfirmer(null);
    },
    onError: (_erreur, demande) => {
      toast.error(
        demande.action === 'recuperer'
          ? 'La récupération n’a pas pu être lancée. Réessayez dans un instant.'
          : 'L’enrichissement n’a pas pu être lancé. Réessayez dans un instant.',
      );
    },
    onSettled: () => {
      envoiEnCours.current = false;
    },
  });

  // « Annuler » est le choix par défaut : c'est lui qui reçoit le focus à
  // l'ouverture, une touche Entrée distraite ne lance rien.
  useEffect(() => {
    if (aConfirmer !== null) boutonAnnuler.current?.focus();
  }, [aConfirmer]);

  function confirmer() {
    if (aConfirmer === null || envoiEnCours.current || lancement.isPending) return;
    envoiEnCours.current = true;
    lancement.mutate(aConfirmer);
  }

  function demander(action: DemandeConfirmation['action'], cell: Cell) {
    setAConfirmer({ action, code: cell.code, name: cell.name, total: cell.total ?? 0 });
  }

  return (
    <div className="min-h-full bg-gradient-to-br from-slate-50 via-white to-slate-50 px-6 py-6">
      <PageHeader
        title="Carte de France"
        subtitle="Cliquez sur un département pour voir ses entreprises"
        badge={<LiveBadge label="Mis à jour chaque minute" />}
        gradient={false}
      />

      {/* KPI cards */}
      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3">
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
          sublabel={
            scoreVisible
              ? `dont ${stats.withScore.toLocaleString('fr-FR')} au score de qualité ≥ 50`
              : 'score de qualité : calcul en attente'
          }
          tone="violet"
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
          // Changer de niveau efface la sélection : le code 84 désigne une
          // région au niveau « Régions » et le Vaucluse au niveau
          // « Départements ». Garder l'un pour l'autre ferait viser la mauvaise zone.
          onChange={(l) => {
            setLevel(l);
            setSelected(null);
          }}
          variant="ghost"
        />
        <div className="ml-auto text-xs text-slate-500">
          {isLoading ? 'Chargement…' : echec ? '' : `${stats.totalAll.toLocaleString('fr-FR')} entreprises au total`}
        </div>
      </div>

      {/* Map + Sidebar */}
      {/* D27-007 — les ombres de cet ecran passent par les JETONS
          (`--shadow-card`, `--shadow-card-hover`, `--shadow-popover` de
          `src/styles/index.css`) et non par des valeurs litterales. Pourquoi :
          les copies a la main avaient DEJA diverge. Mesure du 2026-08-22, avant
          correctif : les quatre panneaux de /coverage portaient
          `0_4px_24px_-8px_rgb(0_0_0/0.06)`, soit la PREMIERE couche du jeton
          seulement — la seconde (`0 1px 2px 0 rgb(0 0 0 / 0.04)`), celle qui
          pose le contact au sol, avait disparu ; et deux valeurs de plus
          (`-12px/0.12` ici, `-8px/0.12` dans la legende de la carte) ne
          correspondaient a AUCUN jeton. La garde
          `frontend/tests/styles/jetons-d-ombre.test.ts` refuse desormais toute
          ombre ecrite en valeur brute sous `src/` : seul `var(--shadow-*)` y
          est admis. */}
      {echec ? (
        <QueryErrorState error={error} contexte="la couverture de la France" onRetry={() => void refetch()} />
      ) : (
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_360px]">
        <div className="rounded-2xl bg-white/80 p-1 shadow-[var(--shadow-card)] ring-1 ring-slate-200/60 backdrop-blur-sm">
          {/* La reserve d'espace fait EXACTEMENT la hauteur de la carte
              (h-[640px], cf. FranceCoverageMap) : le chargement differe ne
              doit pas produire de decalage de mise en page a l'arrivee. */}
          <Suspense
            fallback={
              <div
                className="flex h-[640px] w-full items-center justify-center rounded-2xl bg-slate-50 text-sm text-slate-500"
                role="status"
              >
                Chargement de la carte…
              </div>
            }
          >
            {/* Un clic SÉLECTIONNE, il ne lance jamais rien (lot 4). */}
            <FranceCoverageMap cells={cells} onZoneClick={setSelected} />
          </Suspense>
        </div>

        <aside className="flex flex-col gap-4">
          {selectedCell ? (
            <SelectionCard
              cell={selectedCell}
              scoreVisible={scoreVisible}
              estDepartement={level === 'department'}
              onRecuperer={() => demander('recuperer', selectedCell)}
              onEnrichir={() => demander('enrichir', selectedCell)}
              onClose={() => setSelected(null)}
            />
          ) : (
            <HintCard />
          )}

          <TopList top={stats.top} selected={selected} onSelect={setSelected} />
        </aside>
      </div>
      )}

      <Modal
        open={aConfirmer !== null}
        onClose={() => {
          if (!lancement.isPending) setAConfirmer(null);
        }}
        title={
          aConfirmer?.action === 'enrichir'
            ? 'Enrichir les fiches de ce département ?'
            : `Récupérer ${VOLUME_COLLECTE} entreprises ?`
        }
        description={aConfirmer ? `Département : ${aConfirmer.name} (${aConfirmer.code})` : undefined}
        size="sm"
        footer={
          <>
            <Button
              ref={boutonAnnuler}
              variant="secondary"
              onClick={() => setAConfirmer(null)}
              disabled={lancement.isPending}
            >
              Annuler
            </Button>
            <Button onClick={confirmer} disabled={lancement.isPending} loading={lancement.isPending}>
              {lancement.isPending ? 'Envoi…' : 'Confirmer'}
            </Button>
          </>
        }
      >
        {aConfirmer?.action === 'enrichir' ? (
          <p className="text-sm text-slate-600">
            L’enrichissement va compléter au plus{' '}
            {volumeEnrichissement(aConfirmer.total).toLocaleString('fr-FR')} entreprises de ce
            département déjà récupérées et pas encore complétées (plafond :{' '}
            {PLAFOND_ENRICHISSEMENT.toLocaleString('fr-FR')}). Il interroge des services extérieurs
            pour trouver les e-mails, téléphones et dirigeants. Il se poursuit en arrière-plan.
          </p>
        ) : (
          <p className="text-sm text-slate-600">
            La collecte va chercher {VOLUME_COLLECTE} entreprises de ce département auprès des
            services publics d’information sur les entreprises. Elle se poursuit en arrière-plan ;
            les fiches apparaîtront au fil de l’eau.
          </p>
        )}
      </Modal>
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
    <div className={`group relative overflow-hidden rounded-2xl bg-white/80 p-4 ring-1 ${t.ring} shadow-[var(--shadow-card)] backdrop-blur-sm transition hover:-translate-y-0.5 hover:shadow-[var(--shadow-card-hover)] ${t.glow}`}>
      <div className={`mb-2 inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold uppercase tracking-wider ${t.chip}`}>
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
  scoreVisible,
  estDepartement,
  onRecuperer,
  onEnrichir,
  onClose,
}: {
  cell: Cell;
  scoreVisible: boolean;
  /** La collecte se lance par département : une région ou une ville n'y a pas droit. */
  estDepartement: boolean;
  onRecuperer: () => void;
  onEnrichir: () => void;
  onClose: () => void;
}) {
  const isCovered = (cell.total ?? 0) > 0;
  return (
    <div className="rounded-2xl bg-white/80 p-5 ring-1 ring-slate-200/60 shadow-[var(--shadow-card)] backdrop-blur-sm">
      <div className="mb-3 flex items-start justify-between gap-2">
        <div>
          <div className="text-xs font-medium uppercase tracking-wider text-slate-500">Sélection</div>
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
        <Stat
          label="Score ≥ 50"
          value={scoreVisible ? (Number(cell.complete ?? 0) + Number(cell.partial ?? 0)).toLocaleString('fr-FR') : 'calcul en attente'}
        />
      </div>

      {estDepartement ? (
        <div className="mt-4 flex flex-col gap-2">
          {/* Étape 1 — Récupérer (découverte seule), APRÈS confirmation. */}
          <Button onClick={onRecuperer}>
            Récupérer {VOLUME_COLLECTE} entreprises de ce département
          </Button>
          {/* Étape 2 — Enrichir (emails, téléphones, dirigeants) */}
          <button
            onClick={onEnrichir}
            disabled={!isCovered}
            className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50"
          >
            Enrichir (emails · téléphones · dirigeants)
          </button>
          {/* Lot 4 — voir la liste déjà filtrée sur ce département. */}
          <Link
            to="/companies"
            search={{ department_code: cell.code }}
            className="text-center text-sm font-medium text-brand-700 underline underline-offset-2 hover:text-brand-800"
          >
            Voir les entreprises de ce département
          </Link>
          <p className="text-center text-xs leading-relaxed text-slate-500">
            {isCovered
              ? 'Étape 1 : récupérer les entreprises. Étape 2 : les enrichir (peut être fait plus tard).'
              : "Récupérez d'abord les entreprises, puis vous pourrez les enrichir."}
          </p>
        </div>
      ) : (
        <p className="mt-4 text-xs leading-relaxed text-slate-500">
          Pour récupérer des entreprises, choisissez le niveau « Départements » puis un département.
        </p>
      )}
    </div>
  );
}

function HintCard() {
  return (
    <div className="rounded-2xl bg-white/60 p-5 ring-1 ring-dashed ring-slate-300/80 backdrop-blur-sm">
      <div className="mb-1 text-xs font-medium uppercase tracking-wider text-slate-500">Aucune sélection</div>
      <p className="mt-2 text-sm leading-relaxed text-slate-600">
        Cliquez sur un département pour voir ses entreprises.
      </p>
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
    <div className="rounded-2xl bg-white/80 p-4 ring-1 ring-slate-200/60 shadow-[var(--shadow-card)] backdrop-blur-sm">
      <div className="mb-3 flex items-center justify-between">
        <div className="text-sm font-semibold text-slate-900">Top zones</div>
        <div className="text-xs font-medium uppercase tracking-wider text-slate-400">Triées · entreprises</div>
      </div>
      {!hasData ? (
        <div className="rounded-xl bg-slate-50 p-4 text-center">
          <div className="mb-1 text-sm font-medium text-slate-600">Aucune donnée pour l'instant</div>
          <p className="text-xs text-slate-500">Les zones se remplissent après une première collecte.</p>
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
                    <span className={['rounded-md px-1.5 py-0.5 font-mono text-xs', active ? 'bg-white/10' : 'bg-slate-100 text-slate-600'].join(' ')}>
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
