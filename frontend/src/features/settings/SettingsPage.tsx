/**
 * ⚙️ RÉGLAGES — constat D26-003 (S1) : « quatre onglets, une vingtaine de
 * commandes, UNE SEULE produit un effet ».
 *
 * ═══ L'INVENTAIRE MESURÉ (agent 26, `07-settings-commandes-mortes.txt`) ═══
 *
 *  • WORKSPACE — le formulaire postait vers `PUT /workspace`, qui rendait `501`
 *    (`WorkspaceController::update` = `notImplemented('3')`). L'utilisateur
 *    n'obtenait que `toast.error('Erreur mise à jour')` : le message du serveur
 *    n'était jamais affiché. Le seul formulaire de l'écran ne pouvait pas
 *    réussir, et l'écran ne disait pas pourquoi.
 *
 *    ✅ RÉSOLU — et il faut le dater, parce que ce fichier a affirmé le 501
 *    plus longtemps qu'il n'était vrai. `PUT /workspace` ÉCRIT depuis le
 *    2026-08-23 (constat `X39-034`) : validation, verrou optimiste, refus
 *    explicite de `slug` et `is_active`, filtrage `deleted_at`. Le second
 *    défaut du constat — « le message montré est celui du développeur »,
 *    *Endpoint à implémenter en Sprint 3* — tombe avec lui : cette phrase
 *    venait du corps du 501, elle n'a plus d'émetteur. `EchecEnregistrement`
 *    reste en place pour les VRAIS refus (422, 409, 403), et c'est sa raison
 *    d'être : afficher la phrase du serveur au lieu de la jeter.
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
 * Audit UX du 2026-10-02 (P1-12) — les Paramètres sont un écran pour un
 * humain : Mon entreprise, Mon compte, Utilisateurs, Affichage. Les
 * intégrations (noms de variables du serveur), le suivi des erreurs et les
 * outils Horizon / Telescope sont DÉPLACÉS, sans perte, dans
 * `ReglagesTechniques.tsx`, affiché sous « Technique › Santé du système ».
 *
 * Garde : `tests/screens/SettingsPage.commandes-mortes.test.tsx`.
 */
import { useEffect, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { Briefcase, Palette, Settings as SettingsIcon, KeyRound, Users } from 'lucide-react';
import {
  Button,
  Card,
  CardEyebrow,
  CardHeader,
  CardTitle,
  Input,
  PageHeader,
  QueryErrorState,
  ReponseVideState,
  Tabs,
  type TabItem,
} from '@/components/ui';
import { api, qualifierErreur } from '@/lib/api';
import { appliquerDensite, lireDensite, type Densite } from '@/lib/densite';
import { toast } from 'sonner';
import { ChangerMotDePasse } from './ChangerMotDePasse';

interface Workspace {
  id: string;
  name: string;
  slug: string;
  cost_cap_eur: number;
  settings: Record<string, unknown>;
}

type TabKey = 'workspace' | 'compte' | 'utilisateurs' | 'appearance';

const TABS: Array<TabItem<TabKey>> = [
  { id: 'workspace', label: 'Mon entreprise', icon: <Briefcase className="h-3.5 w-3.5" /> },
  { id: 'compte', label: 'Mon compte', icon: <KeyRound className="h-3.5 w-3.5" /> },
  { id: 'utilisateurs', label: 'Utilisateurs', icon: <Users className="h-3.5 w-3.5" /> },
  // Lot 2 UX — « Apparence » devient « Affichage » : il n'y reste que la
  // densité des listes (plus de mode sombre, décision permanente).
  { id: 'appearance', label: 'Affichage', icon: <Palette className="h-3.5 w-3.5" /> },
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
    // 🔴 X39-034, 2026-08-24 — CE COMMENTAIRE AFFIRMAIT UN 501 QUI N'EXISTE
    // PLUS. Il disait, mesure du 2026-08-22 a l'appui, que
    // `WorkspaceController::update()` « tient en une ligne —
    // `return $this->notImplemented('3')` », et que « le succes de cette
    // mutation n'existe pas encore ».
    //
    // Il existe depuis le 2026-08-23. La route ecrit reellement : validation de
    // `name` / `settings` / `cost_cap_eur`, verrou optimiste, refus explicite de
    // `slug` et `is_active`, filtrage `deleted_at`. Elle rend
    // `200 { data: <espace> }` — forme LUE dans le controleur, pas supposee.
    // Garde cote serveur : `EcrituresQuiRepondaient501Test`, lot 3.
    //
    // ⚠️ ASYMETRIE ASSUMEE, et il vaut mieux l'ecrire que la laisser decouvrir :
    // `show()` rend l'espace NU (`$this->ok($user->currentWorkspace)`), d'ou le
    // `api.get<Workspace>` quelques lignes plus haut, tandis que `update()`
    // l'ENVELOPPE dans `data`. Les deux generiques different donc a dessein.
    mutationFn: async (patch: Partial<Workspace>) =>
      (await api.put<{ data: Workspace }>('/workspace', patch)).data,
    onMutate: () => setEchecEnregistrement(null),
    onSuccess: () => {
      setEchecEnregistrement(null);
      toast.success('Réglages enregistrés');
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
    <div>
      <PageHeader
        title="Paramètres"
        subtitle="Votre entreprise, votre compte, les utilisateurs et l’affichage."
        actions={
          <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
            <SettingsIcon className="h-3.5 w-3.5" /> {ws.data?.name ?? '…'}
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
              <CardEyebrow>Mon entreprise</CardEyebrow>
              <CardTitle className="mt-1 text-base">Nom et budget</CardTitle>
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
                {/* Lot 2 UX — l'« adresse courte » interne (slug) n'est plus
                    affichée : non modifiable, elle n'apprenait rien. */}
                <label className="block text-sm">
                  <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">
                    Budget IA mensuel (€)
                  </span>
                  <Input
                    name="cost_cap_eur"
                    type="number"
                    step="0.01"
                    defaultValue={String(ws.data.cost_cap_eur)}
                  />
                  <span className="mt-1 block text-xs text-slate-500">
                    L’IA s’arrête d’elle-même quand le budget est atteint.
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

      {tab === 'compte' && <ChangerMotDePasse />}

      {tab === 'utilisateurs' && (
        <Card>
          <CardHeader>
            <div>
              <CardEyebrow>Utilisateurs</CardEyebrow>
              <CardTitle className="mt-1 text-base">Qui a accès à la console</CardTitle>
            </div>
          </CardHeader>
          <p className="mb-4 text-sm text-slate-600 dark:text-slate-300">
            Invitez une personne, changez son rôle ou fermez son accès.
          </p>
          <Link
            to="/users"
            className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
          >
            <Users className="h-4 w-4" aria-hidden /> Gérer les utilisateurs
          </Link>
        </Card>
      )}

      {tab === 'appearance' && (
        <div className="grid gap-3 md:grid-cols-2">
          <Card>
            <CardHeader>
              <div>
                <CardEyebrow>Densité</CardEyebrow>
                <CardTitle className="mt-1 text-base">Affichage tables</CardTitle>
              </div>
            </CardHeader>
            <p className="mb-4 text-sm text-slate-600 dark:text-slate-300">
              Choisissez l’espacement des lignes dans les listes longues. Le réglage est conservé
              d’une session à l’autre.
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
