/** Étape 4 — Tout est en règle ? Les 7 contrôles avant lancement. */
import { useEffect } from 'react';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import { Button, QueryErrorState, Skeleton } from '@/components/ui';
import { Bloc, ListeControles } from './composants';
import { useControles } from './api';

export function EtapeControles({
  brouillonId,
  empreinte,
  suite,
  retour,
  onBloquants,
}: {
  brouillonId: string | null;
  /** Change quand le brouillon change : relance les contrôles. */
  empreinte: string;
  suite: () => void;
  retour: () => void;
  /** Nombre de contrôles bloquants, ou `null` tant que le résultat n'est pas là. */
  onBloquants: (n: number | null) => void;
}) {
  const controles = useControles(brouillonId, empreinte);
  const d = controles.data;
  const bloquants = d?.bloquants ?? null;
  useEffect(() => {
    onBloquants(bloquants);
  }, [bloquants, onBloquants]);

  return (
    <Bloc titre="Tout est en règle ?" mot="Étape 4 sur 5" etiquette="Étape 4">
      {controles.isError ? (
        <QueryErrorState error={controles.error} contexte="les contrôles" onRetry={() => { void controles.refetch(); }} />
      ) : d === undefined ? (
        <div role="status" aria-label="Chargement en cours" className="space-y-2">
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-48 w-full" />
        </div>
      ) : (
        <>
          <div role="status" className={`flex items-center gap-3 rounded-xl p-3 ring-1 ${d.bloquants > 0 ? 'bg-rose-50 ring-rose-200' : 'bg-emerald-50 ring-emerald-200'}`}>
            {d.bloquants > 0 ? <AlertTriangle className="h-5 w-5 text-rose-600" aria-hidden /> : <CheckCircle2 className="h-5 w-5 text-emerald-600" aria-hidden />}
            <p className="text-sm font-semibold text-slate-900">
              {d.bloquants > 0
                ? `${d.bloquants} ${d.bloquants > 1 ? 'contrôles' : 'contrôle'} à corriger`
                : 'Tous les contrôles sont au vert'}
            </p>
          </div>
          <div className="mt-3">
            <ListeControles etiquette="Contrôles avant lancement" controles={d.controles} />
          </div>
        </>
      )}
      <div className="mt-6 flex flex-wrap gap-2">
        <Button variant="primary" disabled={d === undefined || d.bloquants > 0} onClick={suite}>Continuer : lancement</Button>
        <Button variant="ghost" onClick={retour}>Retour</Button>
        <Button variant="secondary" onClick={() => { void controles.refetch(); }}>Revérifier</Button>
      </div>
    </Bloc>
  );
}
