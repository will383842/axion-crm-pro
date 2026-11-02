/**
 * DM2-REP — réponses reçues d'une adresse inconnue : rattacher à une fiche
 * existante, ou créer une nouvelle personne (avec sa provenance). Rien n'est supprimé.
 */
import { useState } from 'react';
import { CheckCircle2, Link2 } from 'lucide-react';
import { EmptyState, PageHeader, QueryErrorState, Skeleton } from '@/components/ui';
import { NOM_DU_PRODUIT, useProduitCourant } from '@/features/socle/produit/useProduitCourant';
import { OngletsReponses } from './OngletsReponses';
import { CarteARattacher } from './composants/CarteARattacher';
import { useListeReponses } from './composants/requetes';

export function ReponsesARattacherPage() {
  const produit = useProduitCourant();
  const liste = useListeReponses('a_rattacher');
  const [message, setMessage] = useState<string | null>(null);
  const reponses = liste.data?.data ?? [];

  return (
    <div>
      <PageHeader
        title="Réponses à rattacher"
        surTitre={NOM_DU_PRODUIT[produit]}
        subtitle={liste.isSuccess && reponses.length > 0 ? `${reponses.length} ${reponses.length > 1 ? 'adresses inconnues' : 'adresse inconnue'}. Choisissez la bonne fiche.` : 'Les réponses venues d’une adresse inconnue.'}
      />
      <OngletsReponses />
      <div className="mt-4 space-y-4">
        {message ? (
          <div role="status" aria-label="Rattachement terminé" className="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            <p>{message}</p>
          </div>
        ) : null}
        {liste.isPending ? (
          <div role="status" aria-label="Chargement en cours"><Skeleton className="h-64 w-full" /></div>
        ) : liste.isError ? (
          <QueryErrorState error={liste.error} contexte="les réponses à rattacher" onRetry={() => void liste.refetch()} />
        ) : reponses.length === 0 ? (
          <EmptyState icon={<Link2 />} title="Rien à rattacher" description="Toutes les réponses ont une fiche." />
        ) : (
          reponses.map((r) => <CarteARattacher key={String(r.id)} reponse={r} onTerminee={(m) => setMessage(m)} />)
        )}
      </div>
    </div>
  );
}
