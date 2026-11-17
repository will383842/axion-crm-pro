/** DM2-REP — les réponses déjà traitées : classement, date, lien. Rien n'est supprimé. */
import { useId, useState } from 'react';
import { Archive } from 'lucide-react';
import { Button, EmptyState, Modal, PageHeader, QueryErrorState, Skeleton } from '@/components/ui';
import { messageErreur } from '@/features/envoi/infrastructure/composants/format';
import { NOM_DU_PRODUIT, useProduitCourant } from '@/features/socle/produit/useProduitCourant';
import { OngletsReponses } from './OngletsReponses';
import { Initiales, PastilleClassement } from './composants/PastilleClassement';
import { ModaleStop } from './composants/ModaleStop';
import { CLASSEMENTS, CLASSEMENTS_AVEC_TACHE, formaterDate, initiales, nomComplet, type Classement, type Reponse } from './composants/libelles';
import { useAnnuler, useClasser, useListeReponses } from './composants/requetes';

const FILTRES = Object.keys(CLASSEMENTS).filter((c) => c !== 'a_rattacher') as Classement[];
const PHRASE_STOP = 'Opposition définitive : elle ne s’annule pas depuis cet écran.';

type Geste = { type: 'corriger' | 'annuler' | 'stop'; reponse: Reponse };

export function ReponsesTraiteesPage() {
  const produit = useProduitCourant();
  const idRecherche = useId();
  const idFiltre = useId();
  const [q, setQ] = useState('');
  const [classement, setClassement] = useState('');
  const liste = useListeReponses('traitees', q, classement);
  const reponses = liste.data?.data ?? [];
  const classer = useClasser();
  const annuler = useAnnuler();
  const [geste, setGeste] = useState<Geste | null>(null);
  const [refus, setRefus] = useState<string | null>(null);

  function fermer() {
    setGeste(null);
  }
  function corriger(r: Reponse, classement: Classement, toutesOrganisation = false) {
    setRefus(null);
    classer.mutate(
      { id: String(r.id), classement, ...(CLASSEMENTS_AVEC_TACHE.includes(classement) ? { creer_tache: true } : {}), ...(toutesOrganisation ? { toute_organisation: true } : {}) },
      { onSuccess: fermer, onError: (e) => { setRefus(messageErreur(e)); fermer(); } },
    );
  }
  function annulerClassement(r: Reponse) {
    setRefus(null);
    annuler.mutate({ id: String(r.id) }, { onSuccess: fermer, onError: (e) => { setRefus(messageErreur(e)); fermer(); } });
  }
  const aTraiter = liste.data?.par_file.a_traiter;

  return (
    <div>
      <PageHeader title="Réponses traitées" surTitre={NOM_DU_PRODUIT[produit]} subtitle="Les réponses déjà classées. Les données sont toujours conservées." />
      <OngletsReponses />
      {aTraiter !== undefined ? (
        <p role="status" aria-label="Réponses à traiter" className="mt-3 text-sm text-slate-600">
          À traiter : <span className="font-semibold text-slate-900">{aTraiter}</span>
        </p>
      ) : null}
      {refus ? (
        <p role="alert" className="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{refus} Rien n’a été modifié.</p>
      ) : null}
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
              <li key={String(r.id)} aria-label={`Réponse de ${nomComplet(r)}`} className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-3">
                <Initiales texte={initiales(r)} />
                <div className="min-w-0 flex-1">
                  <p className="flex flex-wrap items-center justify-between gap-x-2">
                    <span className="truncate text-sm font-semibold text-slate-900">{nomComplet(r)}</span>
                    <PastilleClassement classement={r.classement} />
                  </p>
                  <p className="truncate text-xs text-slate-500">{[r.organisation, r.de].filter(Boolean).join(' · ')}</p>
                  <p className="truncate text-xs text-slate-500">« {r.extrait} » · {formaterDate(r.recue_le)}</p>
                </div>
                {r.classement === 'stop' ? (
                  <p className="basis-full text-xs font-medium text-slate-600 sm:basis-auto sm:max-w-xs">{PHRASE_STOP}</p>
                ) : (
                  <div className="flex gap-2">
                    <Button variant="secondary" className="max-md:h-11" aria-label={`Corriger le classement de ${nomComplet(r)}`} onClick={() => setGeste({ type: 'corriger', reponse: r })}>Corriger</Button>
                    <Button variant="secondary" className="max-md:h-11" aria-label={`Annuler le classement de ${nomComplet(r)}`} onClick={() => setGeste({ type: 'annuler', reponse: r })}>Annuler</Button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
      {geste?.type === 'corriger' ? (
        <Modal
          open
          onClose={fermer}
          title={`Corriger le classement de ${nomComplet(geste.reponse)}`}
          description="Choisissez le bon classement. Les arrêts et les tâches déjà créés sont conservés."
          footer={<Button variant="secondary" className="max-md:h-11" onClick={fermer}>Fermer</Button>}
        >
          <ul aria-label="Classements possibles" className="flex flex-wrap gap-2">
            {FILTRES.filter((c) => c !== geste.reponse.classement).map((c) => (
              <li key={c}>
                <Button
                  variant="secondary"
                  className="max-md:h-11"
                  loading={classer.isPending}
                  onClick={() => (c === 'stop' ? setGeste({ type: 'stop', reponse: geste.reponse }) : corriger(geste.reponse, c))}
                >
                  {CLASSEMENTS[c].libelle}
                </Button>
              </li>
            ))}
          </ul>
        </Modal>
      ) : null}
      {geste?.type === 'stop' ? (
        <ModaleStop reponse={geste.reponse} ouverte enCours={classer.isPending} onAnnuler={fermer} onConfirmer={(toute) => corriger(geste.reponse, 'stop', toute)} />
      ) : null}
      {geste?.type === 'annuler' ? (
        <Modal
          open
          onClose={fermer}
          title={`Annuler le classement de ${nomComplet(geste.reponse)} ?`}
          description="Le classement est annulé ; les arrêts et les tâches déjà créés sont conservés."
          footer={
            <>
              <Button variant="secondary" className="max-md:h-11" onClick={fermer}>Garder le classement</Button>
              <Button className="max-md:h-11" loading={annuler.isPending} onClick={() => annulerClassement(geste.reponse)}>Annuler le classement</Button>
            </>
          }
        />
      ) : null}
    </div>
  );
}
