/**
 * FICHE D'UNE PERSONNE — « Parcours » (frise) et « Historique des origines ».
 *
 * Historique COMPLET (fiche ≤ 50 + archive en lecture seule), du plus récent au
 * plus ancien, paginé par curseur. L'identifiant du post est OPAQUE (`post_ref`) :
 * copiable, jamais un lien. Le serveur peut envoyer `post_url` : il n'est PAS
 * affiché (le plan ne prévoit que `post_ref`). Aucun pseudo, aucun texte de message.
 *
 * Fiche d'une autre espace ou inexistante : même 404, même écran « Fiche
 * introuvable » — rien ne révèle que la fonction existe.
 */
import { useInfiniteQuery } from '@tanstack/react-query';
import { Copy } from 'lucide-react';
import { toast } from 'sonner';
import { Button, Card, CardTitle, EmptyState } from '@/components/ui';
import type { ContenusAccords } from '@/features/crm-console/types';
import { estRefuse, lire, statutHttp, type Historique, type OrigineEntree } from './api';
import { AccesRefuse, Chargement, Erreur, Vide } from './Etats';
import { dateFr, libelleCanal, libelleOrigine, libelleReseau } from './libelles';

const PAR_PAGE = 50;

export function HistoriqueOrigines({ personneId, bloc, integre = false }: { personneId: string; bloc?: ContenusAccords | undefined; integre?: boolean }) {
  const q = useInfiniteQuery({
    queryKey: ['crm', 'personne', personneId, 'origines'],
    queryFn: ({ pageParam }) => lire<Historique>(`/crm/personnes/${personneId}/origines`, { par_page: PAR_PAGE, ...(pageParam !== undefined ? { curseur: pageParam } : {}) }),
    initialPageParam: undefined as string | number | undefined,
    getNextPageParam: (derniere) => derniere.meta.suivant ?? undefined,
    retry: false,
  });

  if (q.isLoading) return <Chargement texte="Chargement de l’historique des origines…" />;
  if (q.error !== null && q.data === undefined) {
    // Intégré à la fiche (déjà chargée) : un 403/404 veut dire « écran fermé pour cet espace ».
    // On ne montre rien — ni message, ni trace de la fonction.
    if (integre && estRefuse(q.error)) return null;
    if (statutHttp(q.error) === 404) {
      return <EmptyState title="Fiche introuvable" description="Cette personne n’existe pas dans votre espace." />;
    }
    if (estRefuse(q.error)) return <AccesRefuse>Vous n’avez pas le droit de voir l’historique des origines de cette personne.</AccesRefuse>;
    return <Erreur onRetry={() => void q.refetch()} />;
  }
  const pages = q.data?.pages ?? [];
  const entrees = pages.flatMap((p) => p.data);
  if (pages[0]?.actif === false || entrees.length === 0) {
    return <Vide titre="Aucune origine enregistrée pour cette personne" description="Son origine apparaîtra ici dès qu’elle arrivera par un mot, le site ou un import." />;
  }
  const total = pages[0]?.meta.total ?? entrees.length;

  return (
    <div className="flex flex-col gap-4">
      <Frise actuelle={entrees[0]} bloc={bloc} />
      <Card>
        <CardTitle>Historique des origines</CardTitle>
        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
          Toutes les origines, pas seulement la dernière ({total}). La plus récente sert aux écrans actuels.
        </p>
        <ol className="mt-3 flex flex-col gap-3">
          {entrees.map((o, i) => <Entree key={`${o.date}-${o.post_ref}-${i}`} o={o} actuelle={i === 0} />)}
        </ol>
        {q.hasNextPage && (
          <div className="mt-3">
            <Button variant="secondary" size="sm" loading={q.isFetchingNextPage} onClick={() => void q.fetchNextPage()}>
              Voir les origines plus anciennes
            </Button>
          </div>
        )}
        <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
          Rien n’est retiré : jusqu’à 50 origines sur la fiche ; au-delà, les plus anciennes passent en archive (lecture seule). Aucun pseudo ni texte de commentaire n’est gardé.
        </p>
      </Card>
    </div>
  );
}

function Entree({ o, actuelle }: { o: OrigineEntree; actuelle: boolean }) {
  const copier = async () => {
    try {
      await navigator.clipboard.writeText(o.post_ref ?? '');
      toast.success('Identifiant copié');
    } catch {
      toast.error('Copie impossible : sélectionnez l’identifiant à la main.');
    }
  };
  return (
    <li className="rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-700">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-xs font-medium text-slate-500">{actuelle ? 'Origine actuelle (la plus récente)' : 'Origine passée'}</span>
        <span className="text-xs text-slate-400">{dateFr(o.date, true)}</span>
        <span className="font-medium text-slate-900 dark:text-white">{libelleOrigine(o.famille, o.detail)}</span>
      </div>
      <dl className="mt-2 flex flex-col gap-1 text-xs text-slate-600 dark:text-slate-300">
        <div className="flex gap-3"><dt className="w-44 shrink-0 text-slate-500">Réseau</dt><dd>{libelleReseau(o.reseau) ?? '—'}</dd></div>
        <div className="flex gap-3"><dt className="w-44 shrink-0 text-slate-500">Mot</dt><dd className="font-mono">{o.mot?.toUpperCase() ?? '—'}</dd></div>
        <div className="flex gap-3"><dt className="w-44 shrink-0 text-slate-500">Canal</dt><dd>{libelleCanal(o.canal) ?? '—'}</dd></div>
        <div className="flex gap-3"><dt className="w-44 shrink-0 text-slate-500">Contenu</dt><dd>{o.guide ?? '—'}</dd></div>
        <div className="flex gap-3"><dt className="w-44 shrink-0 text-slate-500">Compte</dt><dd>{o.compte ?? '—'}</dd></div>
        <div className="flex gap-3"><dt className="w-44 shrink-0 text-slate-500">Identifiant du post (opaque)</dt>
        <dd>
          {o.post_ref !== null ? (
            <span className="inline-flex items-center gap-2">
              <code className="rounded bg-slate-100 px-1.5 py-0.5 dark:bg-slate-800">{o.post_ref}</code>
              <Button variant="secondary" size="sm" iconLeft={<Copy className="h-3 w-3" />} onClick={() => void copier()}>Copier</Button>
            </span>
          ) : (
            <span>non renseigné (ancienne version du contrat, ou arrivée hors réseau)</span>
          )}
        </dd></div>
      </dl>
    </li>
  );
}

/** Frise « Parcours » : seulement ce que les données disent, sans étape inventée. */
function Frise({ actuelle, bloc }: { actuelle: OrigineEntree | undefined; bloc: ContenusAccords | undefined }) {
  if (actuelle === undefined) return null;
  const parReseau = actuelle.reseau !== null;
  const accord = bloc?.consentements?.[0];
  const guide = bloc?.guides?.[0];
  const etapes: Array<{ titre: string; date: string | null; etat: string }> = [
    {
      titre: parReseau ? (libelleCanal(actuelle.canal) ?? 'Arrivée par un réseau') : actuelle.famille === 'autre' ? 'Arrivée par import ou collecte' : 'Arrivée par le site',
      date: actuelle.date,
      etat: actuelle.mot !== null ? `Mot ${actuelle.mot.toUpperCase()} reçu` : 'Origine enregistrée',
    },
    { titre: 'Accord enregistré', date: accord?.date ?? null, etat: accord ? 'Accord donné' : 'En attente' },
    { titre: 'Guide envoyé', date: guide?.date ?? null, etat: guide ? 'Envoyé par e-mail' : 'En attente' },
  ];
  return (
    <Card>
      <CardTitle>Parcours</CardTitle>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
        De la première réaction au guide reçu{actuelle.reseau === 'linkedin' || actuelle.reseau === 'tiktok' ? ' (sans message privé : LinkedIn et TikTok)' : ''}.
      </p>
      <ol className="mt-3 flex flex-col gap-3">
        {etapes.map((e) => (
          <li key={e.titre} className="border-l-2 border-slate-200 pl-3 dark:border-slate-700">
            <div className="text-sm font-medium text-slate-900 dark:text-white">{e.titre}</div>
            <div className="text-xs text-slate-500">{e.date !== null ? dateFr(e.date, true) : 'pas encore'}</div>
            <div className="text-xs text-slate-600 dark:text-slate-300">{e.etat}</div>
          </li>
        ))}
      </ol>
    </Card>
  );
}
