/**
 * Nouvelle campagne : l'assistant en 5 étapes (cible, message, quand,
 * vérification, lancement). Pré-remplie par `?etiquette=` ou `?entreprise=`,
 * reprise d'un brouillon par `?brouillon=`. Lot DM2-ASS.
 */
import { useState } from 'react';
import { Link, useSearch } from '@tanstack/react-router';
import { Tags } from 'lucide-react';
import { EmptyState, PageHeader, QueryErrorState, Skeleton } from '@/components/ui';
import { NOM_DU_PRODUIT, useProduitCourant } from '@/features/socle/produit/useProduitCourant';
import { Assistant } from '../creation/Assistant';
import { useBrouillon, useCibles } from '../creation/api';

function texte(valeur: unknown): string | null {
  return typeof valeur === 'string' || typeof valeur === 'number' ? String(valeur) : null;
}

export function NouvelleCampagnePage() {
  const produit = useProduitCourant();
  const recherche: Record<string, unknown> = useSearch({ strict: false });
  // Lus une fois : l'adresse change ensuite (le brouillon s'y ajoute) sans rien recharger.
  const [etiquette] = useState(() => texte(recherche['etiquette']));
  const [entreprise] = useState(() => texte(recherche['entreprise']));
  const [idBrouillon] = useState(() => texte(recherche['brouillon']));

  const cibles = useCibles(entreprise);
  const brouillon = useBrouillon(idBrouillon);

  const entete = (
    <PageHeader
      title="Nouvelle campagne"
      surTitre={NOM_DU_PRODUIT[produit]}
      subtitle={
        <>
          Étiquette, audience ou liste · 5 étapes · <strong className="font-semibold text-slate-700">rien ne part seul</strong>.
        </>
      }
    />
  );

  if (cibles.isError || brouillon.isError) {
    return (
      <div>
        {entete}
        <QueryErrorState
          error={cibles.error ?? brouillon.error}
          contexte="la création de campagne"
          onRetry={() => {
            void cibles.refetch();
            void brouillon.refetch();
          }}
        />
      </div>
    );
  }
  if (cibles.isPending || (idBrouillon !== null && brouillon.isPending)) {
    return (
      <div>
        {entete}
        <div role="status" aria-label="Chargement en cours" className="space-y-3">
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-72 w-full" />
        </div>
      </div>
    );
  }
  if (cibles.data.length === 0) {
    return (
      <div>
        {entete}
        <EmptyState
          icon={<Tags />}
          title="Rien à cibler"
          description="Créez une audience, une liste ou une étiquette dans le CRM pour pouvoir écrire à des personnes."
          action={<Link to="/audiences" className="inline-flex h-9 items-center rounded-lg bg-produit px-4 text-sm font-medium text-white hover:bg-produit-fonce">Ouvrir les audiences</Link>}
        />
      </div>
    );
  }

  const cibleInitiale =
    (etiquette !== null ? cibles.data.find((c) => c.type === 'etiquette' && c.reference === etiquette) : undefined) ??
    (entreprise !== null ? cibles.data.find((c) => c.type === 'carnet') : undefined) ??
    null;

  return (
    <div>
      {entete}
      <Assistant cibles={cibles.data} brouillonInitial={brouillon.data ?? null} cibleInitiale={cibleInitiale} />
    </div>
  );
}
