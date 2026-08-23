/**
 * ⚙️ RÉGLAGES — constat D26-003 (S1) : « quatre onglets, une vingtaine de
 * commandes, UNE SEULE produit un effet ».
 *
 * ═══ L'INVENTAIRE MESURÉ (agent 26, `07-settings-commandes-mortes.txt`) ═══
 *
 *  • WORKSPACE — le formulaire poste vers `PUT /workspace`, qui rend `501`
 *    (`WorkspaceController::update` = `notImplemented('3')`). L'utilisateur
 *    n'obtenait que `toast.error('Erreur mise à jour')` : le message du serveur
 *    n'était jamais affiché. Le seul formulaire de l'écran ne pouvait pas
 *    réussir, et l'écran ne disait pas pourquoi.
 *  • INTÉGRATIONS — « Renouveler » et « Configurer » sans `onClick` : **14
 *    boutons** (7 × 2) dont le clic ne produisait rien. `MaskedSecret
 *    value="sk-•••••"` était une CONSTANTE LITTÉRALE : « Afficher » révélait une
 *    chaîne inventée. Et `status: 'configured'` était écrit en dur dans le
 *    frontend — l'écran affirmait « Configuré » sans avoir rien lu.
 *  • OBSERVABILITÉ — le champ « DSN Sentry » n'avait ni `name`, ni `onChange`,
 *    ni `<form>`, ni bouton : la saisie était jetée. Et elle ne POUVAIT pas
 *    aboutir : `src/lib/sentry.ts:13` lit `import.meta.env.VITE_SENTRY_DSN`,
 *    figée au build de l'image.
 *  • APPARENCE — `density` était un `useState` local, sans effet ni persistance.
 *
 * ═══ LA RÈGLE APPLIQUÉE, COMMANDE PAR COMMANDE ═══
 *
 * « Un bouton qui ne fait rien est pire qu'un bouton absent. » Donc : soit on
 * branche, soit on RETIRE la commande **et** on écrit ce qui manque. Rien ne
 * reste muet.
 *
 *  → Workspace     : le formulaire reste (il est correct — c'est le serveur qui
 *                    ne suit pas), mais l'échec s'affiche À DEMEURE, avec le
 *                    message du serveur et le code HTTP. Le jour où
 *                    `PUT /workspace` sera implémenté, rien à changer ici.
 *  → Intégrations  : les 14 boutons et le faux secret sont RETIRÉS. L'état
 *                    « Configuré » aussi : aucune route n'expose l'état réel des
 *                    clés, l'affirmer était une invention. L'écran le dit.
 *  → Observabilité : le champ est RETIRÉ, remplacé par l'état RÉEL de ce build
 *                    (`VITE_SENTRY_DSN` présent ou non) et par l'endroit où le
 *                    régler pour de vrai.
 *  → Apparence     : la densité est BRANCHÉE (`src/lib/densite.ts` + `index.css`).
 *
 * Garde : `tests/screens/SettingsPage.commandes-mortes.test.tsx`.
 */
import { useEffect, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Activity,
  Briefcase,
  ExternalLink,
  Palette,
  Plug,
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
  QueryErrorState,
  ReponseVideState,
  StatusPill,
  Tabs,
  type TabItem,
} from '@/components/ui';
import { api, qualifierErreur } from '@/lib/api';
import { appliquerDensite, lireDensite, type Densite } from '@/lib/densite';
import { toast } from 'sonner';

interface Workspace {
  id: string;
  name: string;
  slug: string;
  cost_cap_eur: number;
  settings: Record<string, unknown>;
}

type TabKey = 'workspace' | 'integrations' | 'observability' | 'appearance';

/**
 * ⚠️ `role` et NON `status`. Le champ s'appelait `status: 'configured' | …` et
 * l'écran en tirait une pastille « Configuré » : une affirmation sur l'état du
 * SERVEUR, écrite dans le FRONTEND, qu'aucune lecture n'étayait. Ce qu'on peut
 * dire honnêtement d'ici, c'est la place de l'intégration dans le produit —
 * requise, optionnelle, ou repoussée à une phase ultérieure. Pas si la clé est
 * renseignée.
 */
interface Integration {
  name: string;
  env: string;
  description: string;
  role: 'requise' | 'optionnelle' | 'phase-b';
}

const INTEGRATIONS: Integration[] = [
  { name: 'INSEE Sirene', env: 'INSEE_API_KEY', description: 'Base entreprises + données légales (gratuit, 500 req/min)', role: 'requise' },
  { name: 'France Travail', env: 'FRANCE_TRAVAIL_CLIENT_ID', description: 'Offres d\'emploi + intentions de recrutement (gratuit)', role: 'requise' },
  { name: 'Mistral AI', env: 'MISTRAL_API_KEY', description: 'LLM principal classification entreprises (FR souverain, ~5€/mois)', role: 'requise' },
  { name: 'Anthropic Claude', env: 'ANTHROPIC_API_KEY', description: 'LLM premium pour use cases stratégiques (optionnel)', role: 'optionnelle' },
  // Sprint H9 + H12 — Google Places API officielle (enrichissement auto, garde-fou quota)
  { name: 'Google Places API', env: 'GOOGLE_PLACES_API_KEY', description: 'Enrichissement auto téléphone/horaires/site/note Google (gratuit ≤12K/mois via crédit $200, garde-fou quota actif)', role: 'optionnelle' },
  { name: 'Webshare proxies', env: 'WEBSHARE_USERNAME', description: 'Proxies résidentiels pour Pages Jaunes (~$30/mois, Phase B optionnelle)', role: 'phase-b' },
  { name: '2captcha', env: 'TWOCAPTCHA_API_KEY', description: 'Résolution captcha (Phase B, uniquement si scraping Google direct)', role: 'phase-b' },
];

const LIBELLE_ROLE: Record<Integration['role'], string> = {
  requise: 'Requise',
  optionnelle: 'Optionnelle',
  'phase-b': 'Phase B',
};

const TON_ROLE: Record<Integration['role'], 'success' | 'info' | 'warning'> = {
  requise: 'success',
  optionnelle: 'info',
  'phase-b': 'warning',
};

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

/** Le message que le serveur a réellement écrit, ou `null`. Aucune invention. */
function messageDuServeur(err: unknown): string | null {
  if (typeof err !== 'object' || err === null) return null;
  const e = err as { response?: { data?: { message?: string; error?: string } } };
  return e.response?.data?.message ?? e.response?.data?.error ?? null;
}

/**
 * L'échec d'enregistrement, AFFICHÉ À DEMEURE.
 *
 * Un toast s'évapore en quatre secondes ; il ne répond pas à « pourquoi mon
 * plafond de coût n'est-il jamais enregistré ? ». Et `toast.error('Erreur mise
 * à jour')` jetait le seul élément utile : la phrase du serveur.
 */
function EchecEnregistrement({ erreur }: { erreur: unknown }) {
  const { status } = qualifierErreur(erreur);
  const message = messageDuServeur(erreur);

  return (
    <div
      role="alert"
      className="rounded-xl border-2 border-dashed border-rose-300 bg-rose-50/60 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-300"
    >
      {/* ⚠️ Sous-chaîne cherchée par la garde : « Modifications non enregistr ». */}
      <p className="font-semibold">Modifications non enregistrées</p>
      <p className="mt-1">
        {message ??
          'Le serveur a refusé la demande sans expliquer pourquoi. Rien n’a été modifié.'}
      </p>
      <p className="mt-2 font-mono text-xs opacity-80">
        {status === null ? 'aucune réponse du serveur' : `code HTTP ${status}`}
      </p>
    </div>
  );
}

export function SettingsPage() {
  const qc = useQueryClient();
  const [tab, setTab] = useState<TabKey>('workspace');

  // D26-003 — la densité est lue au montage et APPLIQUÉE au document, pas
  // seulement gardée dans un état local qui ne pilotait que deux boutons.
  const [densite, setDensite] = useState<Densite>(lireDensite);
  useEffect(() => {
    appliquerDensite(densite);
  }, [densite]);

  const ws = useQuery({
    queryKey: ['workspace'],
    queryFn: async () => (await api.get<Workspace>('/workspace')).data,
  });

  const [echecEnregistrement, setEchecEnregistrement] = useState<unknown>(null);

  const updateMut = useMutation({
    // H46-008 — le generique ferme le `any` que rendait `api.put` sans
    // parametre ; la suppression `no-unsafe-return` correspondante a ete
    // retiree de `frontend/eslint-suppressions.json` dans le meme geste.
    //
    // ⚠️ `unknown`, et PAS `Workspace`. Mesure du 2026-08-22 :
    // `WorkspaceController::update()` tient en une ligne —
    // `return $this->notImplemented('3')` — c'est-a-dire un 501 portant
    // `{ error, message, sprint }`. Ecrire `api.put<Workspace>` ici
    // declarerait une forme que ce point d'entree ne rend JAMAIS ; `unknown`
    // dit ce qu'on sait. Le succes de cette mutation n'existe pas encore : ce
    // defaut-la est distinct, et il n'est pas referme ici.
    mutationFn: async (patch: Partial<Workspace>) =>
      (await api.put<unknown>('/workspace', patch)).data,
    onMutate: () => setEchecEnregistrement(null),
    onSuccess: () => {
      setEchecEnregistrement(null);
      toast.success('Workspace mis à jour');
      qc.invalidateQueries({ queryKey: ['workspace'] });
    },
    onError: (err) => {
      // On garde l'erreur COMPLÈTE : `EchecEnregistrement` en tire le message du
      // serveur ET le code HTTP. Le toast ne sert plus qu'à attirer l'œil.
      setEchecEnregistrement(err);
      toast.error(messageDuServeur(err) ?? 'Enregistrement impossible');
    },
  });

  /**
   * ⚠️ `data === undefined` fait partie de la condition (même patron que
   * `QueryErrorState` et `ArbitragePage`) : React Query v5 garde la dernière
   * réponse réussie quand un rafraîchissement échoue, et effacer le formulaire
   * dans ce cas ferait perdre à l'exploitant ce qu'il avait sous les yeux.
   *
   * Cette branche ferme au passage l'observation D25-004 sur cet écran : le
   * code écrivait `ws.data ? <form/> : <p>Chargement…</p>`, donc « Chargement… »
   * pour l'éternité dès que la lecture échouait.
   */
  const echecLecture = ws.error !== null && ws.data === undefined;

  /**
   * ═══ D25-004, LE RESTE DU DÉFAUT ═══
   *
   * Mesure du 2026-08-21 : la branche `echecLecture` ci-dessus NE SUFFISAIT
   * PAS. Sous une réponse **200 au corps vide**, React Query tient la requête
   * pour réussie — `ws.error === null` — et `ws.data` est malgré tout absente.
   * L'écran retombait donc sur son `<p>Chargement…</p>` final, et y restait
   * pour toujours : le plafond de dépenses LLM et les clés d'intégration
   * derrière un « Chargement… » perpétuel, exactement comme avant le correctif.
   *
   * Le sablier n'est désormais légitime QUE tant que la requête charge
   * vraiment. `ws.isLoading` est la seule chose qui a le droit de le montrer.
   *
   * ⚠️ `ws.data` peut être une CHAÎNE VIDE et non `undefined` : axios laisse
   * `response.data` à `''` quand le corps est vide, `JSON.parse` ayant échoué
   * en mode tolérant. Le test est donc une falsité, pas un `=== undefined`.
   */
  const reponseVide = !ws.isLoading && ws.error === null && !ws.data;

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
          {echecLecture ? (
            <QueryErrorState
              error={ws.error}
              contexte="les réglages de l’espace de travail"
              onRetry={() => void ws.refetch()}
            />
          ) : ws.data ? (
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

              {echecEnregistrement !== null ? (
                <EchecEnregistrement erreur={echecEnregistrement} />
              ) : null}

              <div className="flex justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                <Button type="submit" variant="primary" loading={updateMut.isPending}>
                  Enregistrer
                </Button>
              </div>
            </form>
          ) : reponseVide ? (
            <ReponseVideState
              contexte="les réglages de l’espace de travail"
              onRetry={() => void ws.refetch()}
            />
          ) : (
            <p className="text-sm text-slate-500">Chargement…</p>
          )}
        </Card>
      )}

      {tab === 'integrations' && (
        <div className="space-y-3">
          {/*
            🔴 CE QUI A ÉTÉ RETIRÉ ICI, ET POURQUOI IL NE FAUT PAS LE REMETTRE :
            — 14 boutons « Renouveler » / « Configurer » sans `onClick` ;
            — une pastille de secret dont la valeur (`sk-•••••`) était une
              constante du frontend, que « Afficher » révélait fièrement ;
            — un état « Configuré » écrit en dur, sans aucune lecture.
            Tant qu'aucune route n'expose l'état réel des clés, la seule position
            tenable est de le DIRE.
          */}
          <p className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
            Ces clés sont des <strong>secrets du serveur</strong>, lus dans son environnement.
            Aucune route de l’API ne les expose, même masquées&nbsp;: cet écran{' '}
            <strong>ne peut donc pas dire</strong> lesquelles sont renseignées, ni les modifier.
            Elles se règlent dans les variables d’environnement du déploiement.
          </p>

          <div className="grid gap-3 md:grid-cols-2">
            {INTEGRATIONS.map((i) => (
              <Card key={i.env}>
                <CardHeader>
                  <div className="min-w-0">
                    <CardEyebrow>{i.env}</CardEyebrow>
                    <CardTitle className="mt-1 truncate text-base">{i.name}</CardTitle>
                  </div>
                  {/* Ce que l'écran sait vraiment : la place de l'intégration dans
                      le produit. Pas si sa clé est posée sur le serveur. */}
                  <StatusPill tone={TON_ROLE[i.role]}>{LIBELLE_ROLE[i.role]}</StatusPill>
                </CardHeader>
                <p className="text-sm text-slate-600 dark:text-slate-300">{i.description}</p>
              </Card>
            ))}
          </div>
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
            {/*
              🔴 LE CHAMP « DSN Sentry » A ÉTÉ RETIRÉ. Il n'avait ni `name`, ni
              `onChange`, ni `<form>`, ni bouton — tout ce qui y était saisi
              était jeté. Et il ne pouvait pas en être autrement :
              `src/lib/sentry.ts:13` lit `import.meta.env.VITE_SENTRY_DSN`, une
              valeur figée AU MOMENT DU BUILD de l'image. Aucune saisie faite
              dans le navigateur ne peut la changer.
            */}
            <p className="text-sm text-slate-600 dark:text-slate-300">
              Le DSN est lu dans <code className="font-mono text-xs">VITE_SENTRY_DSN</code>{' '}
              <strong>au moment du build</strong> de l’image (cf.{' '}
              <code className="font-mono text-xs">Dockerfile.frontend</code>). Il ne se règle pas
              depuis cet écran, et ne le pourrait pas&nbsp;: il est compilé dans le paquet servi au
              navigateur.
            </p>
            <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">
              État de <em>ce</em> build&nbsp;:{' '}
              {import.meta.env['VITE_SENTRY_DSN'] ? (
                <StatusPill tone="success">DSN présent — le SDK est actif</StatusPill>
              ) : (
                <StatusPill tone="info">aucun DSN — le SDK reste inerte</StatusPill>
              )}
            </p>
          </Card>

          <div>
            <p className="mb-2 text-xs text-slate-500">
              Adresses de la pile d’observabilité locale (docker-compose). Hors de cette pile,
              elles ne répondent pas.
            </p>
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
              Choisis l’espacement par défaut des lignes dans les listes longues. Le réglage est
              posé sur le document et conservé d’une session à l’autre.
            </p>
            <div className="inline-flex rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800">
              {(['comfortable', 'compact'] as const).map((d) => (
                <button
                  key={d}
                  type="button"
                  aria-pressed={densite === d}
                  onClick={() => setDensite(d)}
                  className={
                    densite === d
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
