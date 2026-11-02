/**
 * DM2-REP — la boîte des réponses d'Envoi.
 *
 * Les réponses vivent ICI ; le CRM n'en reçoit qu'un résumé (affiché comme
 * confirmation). Un STOP met la personne à l'écart des envois : les données
 * sont gardées, rien n'est supprimé.
 *
 * Raccourcis : J (suivante), K (précédente), 1 (pas intéressé), 2 (plus tard),
 * 3 (absent), S (STOP). Ils ne jouent pas pendant la saisie d'un texte.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link } from '@tanstack/react-router';
import { Inbox } from 'lucide-react';
import { EmptyState, KpiCard, PageHeader, QueryErrorState, Skeleton } from '@/components/ui';
import { NOM_DU_PRODUIT, useProduitCourant } from '@/features/socle/produit/useProduitCourant';
import { OngletsReponses } from './OngletsReponses';
import { ConfirmationCrm, type ConfirmationGeste } from './composants/ConfirmationCrm';
import { DetailReponse } from './composants/DetailReponse';
import { ListeReponses } from './composants/ListeReponses';
import { useClasser, useListeReponses } from './composants/requetes';
import type { Classement } from './composants/libelles';
import { nomComplet } from './composants/libelles';

const TOUCHES_CLASSEMENT: Record<string, Classement> = { '1': 'refus', '2': 'pas_maintenant', '3': 'absence' };

function estSaisie(cible: EventTarget | null): boolean {
  if (!(cible instanceof HTMLElement)) return false;
  return cible.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(cible.tagName);
}

export function ReponsesPage() {
  const produit = useProduitCourant();
  const liste = useListeReponses('a_traiter');
  const classer = useClasser();
  const [choix, setChoix] = useState<string | null>(null);
  const [stopOuvert, setStopOuvert] = useState(false);
  const [confirmation, setConfirmation] = useState<ConfirmationGeste | null>(null);
  const dernierRang = useRef(0);
  const detailRef = useRef<HTMLDivElement>(null);

  const reponses = useMemo(() => liste.data?.data ?? [], [liste.data]);
  const rangChoisi = reponses.findIndex((r) => String(r.id) === choix);
  // Si la réponse choisie a disparu (traitée), on reste au même rang : la suivante prend sa place.
  const rang = rangChoisi >= 0 ? rangChoisi : Math.min(dernierRang.current, Math.max(0, reponses.length - 1));
  const courante = reponses[rang];
  const selection = courante ? String(courante.id) : null;

  useEffect(() => {
    if (rangChoisi >= 0) dernierRang.current = rangChoisi;
  }, [rangChoisi]);

  const choisir = useCallback((id: string) => {
    setChoix(id);
    setStopOuvert(false);
    if (window.matchMedia('(max-width: 1023px)').matches) detailRef.current?.scrollIntoView?.({ block: 'start' });
  }, []);

  useEffect(() => {
    function auClavier(e: KeyboardEvent) {
      if (e.ctrlKey || e.metaKey || e.altKey || estSaisie(e.target) || stopOuvert) return;
      const touche = e.key.toLowerCase();
      if (touche === 'j' || touche === 'k') {
        const suivant = reponses[rang + (touche === 'j' ? 1 : -1)];
        if (suivant) setChoix(String(suivant.id));
      } else if (selection && courante && touche in TOUCHES_CLASSEMENT) {
        classer.mutate(
          { id: selection, classement: TOUCHES_CLASSEMENT[touche]! },
          { onSuccess: (r) => r.resume_crm && setConfirmation({ nom: nomComplet(courante), resume: r.resume_crm }) },
        );
      } else if (touche === 's' && selection) {
        setStopOuvert(true);
      } else {
        return;
      }
      e.preventDefault();
    }
    window.addEventListener('keydown', auClavier);
    return () => window.removeEventListener('keydown', auClavier);
  }, [reponses, rang, selection, courante, stopOuvert, classer]);

  const parFile = liste.data?.par_file;
  const interessees = reponses.filter((r) => r.suggestion.classement === 'interesse').length;
  const total = parFile?.a_traiter ?? reponses.length;

  return (
    <div>
      <PageHeader
        title="Réponses"
        surTitre={NOM_DU_PRODUIT[produit]}
        subtitle={liste.isSuccess ? (total === 0 ? 'Rien à traiter pour le moment.' : `${total} ${total > 1 ? 'réponses' : 'réponse'} à traiter. Répondez sans quitter l’outil.`) : 'Répondez sans quitter l’outil.'}
      />
      <OngletsReponses />

      {liste.isPending ? (
        <div role="status" aria-label="Chargement en cours" className="mt-4 space-y-3">
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-72 w-full" />
        </div>
      ) : liste.isError ? (
        <div className="mt-4">
          <QueryErrorState error={liste.error} contexte="les réponses" onRetry={() => void liste.refetch()} />
        </div>
      ) : (
        <>
          <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <KpiCard label="Intéressées" value={interessees} sublabel="à rappeler" tone="emerald" />
            <KpiCard label="À traiter" value={total} sublabel="réponses en attente" tone="sky" />
            <Link to="/envoi/reponses/a-rattacher" className="block rounded-2xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-produit">
              <KpiCard label="À rattacher" value={parFile?.a_rattacher ?? 0} sublabel="adresse inconnue" tone="violet" />
            </Link>
          </div>

          <div className="mt-4">
            {confirmation ? <ConfirmationCrm confirmation={confirmation} /> : null}
            {reponses.length === 0 ? (
              <EmptyState icon={<Inbox />} title="Aucune réponse à traiter" description="Tout est traité. Les nouvelles réponses apparaîtront ici." />
            ) : (
              <div className="grid grid-cols-1 gap-4 lg:grid-cols-[22rem_minmax(0,1fr)]">
                <ListeReponses reponses={reponses} selection={selection} onChoisir={choisir} />
                <div ref={detailRef} className="min-w-0 scroll-mt-4">
                  {selection ? (
                    <DetailReponse
                      key={selection}
                      id={selection}
                      stopOuvert={stopOuvert}
                      onStopOuvert={setStopOuvert}
                      onGeste={(nom, resume) => setConfirmation({ nom, resume })}
                    />
                  ) : null}
                </div>
              </div>
            )}
          </div>
        </>
      )}
    </div>
  );
}
