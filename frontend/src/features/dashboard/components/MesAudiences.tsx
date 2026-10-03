import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { Card, QueryErrorState, Skeleton } from '@/components/ui';
import { AUDIENCES_KEY, chargerAudiences } from '@/features/audiences/listeAudiences';

/** Combien d'audiences l'accueil montre (maquette validée par Will, 03/10/2026). */
export const AUDIENCES_SUR_L_ACCUEIL = 3;

/**
 * « Mes audiences » — les trois premières audiences de la liste (même ordre
 * que l'écran Audiences), avec leur nombre de membres, et « Tout voir ».
 *
 * Même requête et même clé de cache que l'écran Audiences
 * (`listeAudiences.ts`) : pas de second appel ni de seconde forme. Pas de
 * scrutation ici : les nombres de membres sont recalculés chaque nuit.
 *
 * Quatre états qui ne se confondent pas :
 *  - chargement : trois lignes en attente ;
 *  - échec (aucune réponse) : `QueryErrorState`, avec « Réessayer » ;
 *  - réponse DÉGRADÉE (`degraded: true`, le serveur n'a pas pu lire) :
 *    « Chiffre indisponible pour le moment », jamais « aucune audience » ;
 *  - liste vide : « Aucune audience pour l'instant ».
 *
 * Une audience jamais calculée (`refreshed_at` vide) montre « — » : son
 * `member_count` vaut 0 par défaut, ce qui se lirait « personne ».
 */
export function MesAudiences() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: AUDIENCES_KEY,
    queryFn: chargerAudiences,
    staleTime: 60_000,
  });

  const echec = error !== null && data === undefined;
  const audiences = (data?.data ?? []).slice(0, AUDIENCES_SUR_L_ACCUEIL);

  return (
    <Card padding="lg" className="flex min-w-0 flex-col gap-3.5">
      <div className="flex items-center justify-between gap-3">
        <h2 id="titre-mes-audiences" className="text-base font-bold text-slate-900">
          Mes audiences
        </h2>
        <Link to="/audiences" className="text-sm font-semibold text-brand-600 hover:text-brand-700">
          Tout voir
        </Link>
      </div>

      {isLoading ? (
        <div className="flex flex-col gap-2.5">
          {Array.from({ length: AUDIENCES_SUR_L_ACCUEIL }).map((_, i) => (
            <Skeleton key={i} className="h-12 w-full rounded-xl" />
          ))}
        </div>
      ) : echec ? (
        <QueryErrorState error={error} contexte="vos audiences" onRetry={() => void refetch()} />
      ) : data?.degraded === true ? (
        <p className="text-sm text-slate-600" data-testid="audiences-indisponibles">
          Chiffre indisponible pour le moment.
        </p>
      ) : audiences.length === 0 ? (
        <p className="text-sm text-slate-600">Aucune audience pour l’instant.</p>
      ) : (
        <ul className="flex flex-col gap-2.5" aria-labelledby="titre-mes-audiences">
          {audiences.map((a) => {
            const calculee = a.refreshed_at !== null;
            const membres = a.member_count.toLocaleString('fr-FR');
            return (
              <li key={a.id}>
                <Link
                  to="/audiences/$audienceId"
                  params={{ audienceId: String(a.id) }}
                  className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-3 text-slate-900 ring-1 ring-slate-100 transition hover:bg-slate-100"
                  aria-label={
                    calculee
                      ? `${a.name} : ${membres} membre${a.member_count > 1 ? 's' : ''}`
                      : `${a.name} : pas encore calculée`
                  }
                >
                  <span className="min-w-0 truncate font-semibold">{a.name}</span>
                  <span className="shrink-0 font-bold tabular-nums">{calculee ? membres : '—'}</span>
                </Link>
              </li>
            );
          })}
        </ul>
      )}

      <p className="text-xs text-slate-500">Mises à jour chaque nuit</p>
    </Card>
  );
}
