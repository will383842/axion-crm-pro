/** DM2-REP — les réponses déjà traitées : classement, date, lien. Rien n'est supprimé. */
import { useId, useState } from 'react';
import { Archive } from 'lucide-react';
import { EmptyState, PageHeader, QueryErrorState, Skeleton } from '@/components/ui';
import { NOM_DU_PRODUIT, useProduitCourant } from '@/features/socle/produit/useProduitCourant';
import { OngletsReponses } from './OngletsReponses';
import { Initiales, PastilleClassement } from './composants/PastilleClassement';
import { CLASSEMENTS, formaterDate, initiales, nomComplet, type Classement } from './composants/libelles';
import { useListeReponses } from './composants/requetes';

const FILTRES = Object.keys(CLASSEMENTS).filter((c) => c !== 'a_rattacher') as Classement[];

export function ReponsesTraiteesPage() {
  const produit = useProduitCourant();
  const idRecherche = useId();
  const idFiltre = useId();
  const [q, setQ] = useState('');
  const [classement, setClassement] = useState('');
  const liste = useListeReponses('traitees', q, classement);
  const reponses = liste.data?.data ?? [];

  return (
    <div>
      <PageHeader title="Réponses traitées" surTitre={NOM_DU_PRODUIT[produit]} subtitle="Les réponses déjà classées. Les données sont toujours conservées." />
      <OngletsReponses />
      <div className="mt-4 flex flex-wrap items-end gap-3">
        <div className="flex min-w-0 flex-1 flex-col gap-1 sm:max-w-xs">
          <label htmlFor={idRecherche} className="text-xs font-medium text-slate-600">Rechercher</label>
          <input id={idRecherche} type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nom, organisation, adresse" className="h-9 w-full rounded-lg bg-white px-3 text-sm ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-produit max-md:h-11" />
        </div>
        <div className="flex flex-col gap-1">
          <label htmlFor={idFiltre} className="text-xs font-medium text-slate-600">Classement</label>
          <select id={idFiltre} value={classement} onChange={(e) => setClassement(e.target.value)} className="h-9 rounded-lg bg-white px-3 text-sm ring-1 ring-slate-200 max-md:h-11">
            <option value="">Tous</option>
            {FILTRES.map((c) => <option key={c} value={c}>{CLASSEMENTS[c].libelle}</option>)}
          </select>
        </div>
      </div>
      <div className="mt-4">
        {liste.isPending ? (
          <div role="status" aria-label="Chargement en cours" className="space-y-2"><Skeleton className="h-16 w-full" /><Skeleton className="h-16 w-full" /><Skeleton className="h-16 w-full" /></div>
        ) : liste.isError ? (
          <QueryErrorState error={liste.error} contexte="les réponses traitées" onRetry={() => void liste.refetch()} />
        ) : reponses.length === 0 ? (
          <EmptyState icon={<Archive />} title="Aucune réponse traitée" description="Les réponses classées apparaîtront ici." />
        ) : (
          <ul aria-label="Réponses traitées" className="space-y-2">
            {reponses.map((r) => (
              <li key={String(r.id)} className="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-3">
                <Initiales texte={initiales(r)} />
                <div className="min-w-0 flex-1">
                  <p className="flex flex-wrap items-center justify-between gap-x-2">
                    <span className="truncate text-sm font-semibold text-slate-900">{nomComplet(r)}</span>
                    <PastilleClassement classement={r.classement} />
                  </p>
                  <p className="truncate text-xs text-slate-500">{[r.organisation, r.de].filter(Boolean).join(' · ')}</p>
                  <p className="truncate text-xs text-slate-500">« {r.extrait} » · {formaterDate(r.recue_le)}</p>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
