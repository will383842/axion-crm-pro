/**
 * LI-D1 — FILE « PRÊT À CLIQUER » (voie S). Le fondateur ouvre lui-même chaque profil et agit sur LinkedIn à la main :
 * l'écran n'automatise rien, n'ouvre aucun profil, ne contacte personne. Gestes à clé d'idempotence stable. Aucune suppression.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { PageHeader } from '@/components/ui';
import { Chargement, Erreur, Vide } from '@/features/origines/Etats';
import { BoutonArret } from './BoutonArret';
import { CarteProspect } from './CarteProspect';
import { lireFile, poserArret, poserGeste, refusDe, statutHttp, type FileDuJour, type Geste } from './api';

const CLE_FILE = ['prospection-linkedin', 'file'] as const;

export function FileLinkedinPage() {
  const qc = useQueryClient();
  const [retires, setRetires] = useState<ReadonlySet<string>>(new Set());
  const [bandeau, setBandeau] = useState<string | null>(null);
  const file = useQuery({ queryKey: CLE_FILE, queryFn: lireFile, retry: false });

  const geste = useMutation({
    mutationFn: (v: { id: string; geste: Geste; cle: string }) => poserGeste(v.id, v.geste, v.cle),
    onSuccess: (_r, v) => setRetires((s) => new Set(s).add(v.id)),
    onError: (e, v) => {
      const refus = refusDe(e);
      if (statutHttp(e) === 409 && (refus === 'oppose' || refus === 'arret')) {
        setRetires((s) => new Set(s).add(v.id));
        setBandeau(refus === 'oppose'
          ? 'Une personne opposée figurait dans la file : arrêt automatique posé. Rien n’a été fait pour elle.'
          : 'L’arrêt est actif : aucun geste n’est enregistré.');
        void qc.invalidateQueries({ queryKey: CLE_FILE });
      } else {
        toast.error('Geste non enregistré. Vous pouvez réessayer : la même clé est rejouée, rien ne sera compté deux fois.');
      }
    },
  });
  const arret = useMutation({
    mutationFn: (v: { g: 'arret' | 'reprise'; motif: string }) => poserArret(v.g, v.motif),
    onSuccess: () => { setBandeau(null); void qc.invalidateQueries({ queryKey: CLE_FILE }); },
    onError: () => toast.error('Arrêt ou reprise non enregistré.'),
  });

  if (file.isPending) return <Chargement texte="Chargement de la file…" />;
  if (file.isError) {
    const s = statutHttp(file.error);
    if (s === 404 || s === 403) {
      return (
        <div role="alert" className="rounded-xl border-2 border-dashed border-amber-200 px-6 py-12 text-center dark:border-amber-900">
          <h2 className="text-lg font-semibold text-slate-900 dark:text-white">{s === 404 ? 'Module éteint' : 'Accès réservé'}</h2>
          <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
            {s === 404 ? 'Le module de prospection LinkedIn n’est pas activé pour cet espace.' : 'Seul le propriétaire de l’espace peut ouvrir cette file.'}
          </p>
        </div>
      );
    }
    return <Erreur onRetry={() => void file.refetch()} />;
  }
  const d: FileDuJour = file.data;
  const cartes = d.prospects.filter((p) => !retires.has(p.id));
  const arretActif = d.arret || bandeau !== null;

  return (
    <div className="space-y-4">
      <PageHeader title="File LinkedIn" subtitle="Prêt à cliquer : vous ouvrez le profil et vous agissez vous-même sur LinkedIn." />
      <div role="status" aria-live="polite" className="text-sm text-slate-700 dark:text-slate-200">
        Restants aujourd’hui : {d.restants.jour} · cette semaine : {d.restants.semaine}
      </div>
      {bandeau !== null && (
        <div role="alert" className="rounded-lg border border-rose-300 bg-rose-50 p-3 text-sm text-rose-900 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100">{bandeau}</div>
      )}
      <BoutonArret
        actif={arretActif}
        occupe={arret.isPending}
        onArret={(motif) => arret.mutate({ g: 'arret', motif })}
        onReprise={(motif) => arret.mutate({ g: 'reprise', motif })}
      />
      {cartes.length === 0 ? (
        <Vide
          titre={d.arret ? 'Arrêt actif' : 'Rien à faire pour le moment'}
          description={d.arret ? 'La file est vide tant que l’arrêt est actif.' : 'Aucun prospect prêt, ou les plafonds du jour sont atteints.'}
        />
      ) : (
        cartes.map((p) => (
          <CarteProspect key={p.id} prospect={p} occupe={geste.isPending} onGeste={(g, cle) => geste.mutate({ id: p.id, geste: g, cle })} />
        ))
      )}
    </div>
  );
}
