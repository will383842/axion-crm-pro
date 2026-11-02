/**
 * DM0-COQ — PAGE « EN ATTENTE ».
 *
 * Toutes les routes des deux produits existent dès DM0-COQ (plan 30 § 4.2) ;
 * chacune pointe vers SON fichier de page, qui monte ce composant au nom du
 * lot qui la remplira. Le lot propriétaire REMPLACE le contenu de son fichier
 * de page ; il ne touche ni `routeTree.tsx` ni ce composant.
 *
 * Code de lot INTERNE : affiché seulement dans l'espace Envoi, dont toutes les
 * routes sont gardées par `exigerProduit('envoi')` (invisibles en production
 * tant qu'Envoi est absent, utiles en démo). Les pages joignables en
 * production par leur adresse (`/taches`, `/affaires`, `/compte`, la page
 * publique de désinscription) ont une formulation NEUTRE, sans code de lot.
 * Le lot reste lisible dans `data-lot`, pour les tests.
 *
 * Le titre est déjà le bon (même mot que le menu et le fil d'Ariane, garde
 * `tests/components/navigation-cible.test.tsx`).
 */
import type { ReactNode } from 'react';
import { Hourglass } from 'lucide-react';
import { PageHeader } from '@/components/ui';
import { NOM_DU_PRODUIT, useProduitCourant } from './produit/useProduitCourant';

export interface PageEnAttenteProps {
  /** Titre de la page : le mot du menu. */
  title: string;
  /** Identifiant du lot qui remplira la page (ex. `DM2-CAM`). */
  lot: string;
  /** Barre d'onglets partagée, quand la page en a une. */
  onglets?: ReactNode;
}

/** Texte neutre des pages joignables en production. */
export const TEXTE_EN_PREPARATION = 'Cette page est en préparation : elle sera disponible prochainement.';

export function PageEnAttente({ title, lot, onglets }: PageEnAttenteProps) {
  const produit = useProduitCourant();
  return (
    <div data-testid="page-en-attente" data-lot={lot}>
      <PageHeader title={title} surTitre={NOM_DU_PRODUIT[produit]} />
      {onglets}
      <div className="mt-4 flex items-start gap-3 rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
        <Hourglass className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" aria-hidden />
        {produit === 'envoi' ? (
          <p>
            Cette page est en attente : elle arrive avec le lot <strong className="font-semibold text-slate-900">{lot}</strong>.
          </p>
        ) : (
          <p>{TEXTE_EN_PREPARATION}</p>
        )}
      </div>
    </div>
  );
}

/** La page publique, hors coquille (désinscription par lien d'e-mail). */
export function PagePubliqueEnAttente({ title, lot }: Omit<PageEnAttenteProps, 'onglets'>) {
  return (
    <main id="main" data-testid="page-en-attente" data-lot={lot} className="mx-auto flex min-h-screen max-w-xl flex-col justify-center px-4 py-10">
      <h1 className="text-2xl font-semibold tracking-tight text-slate-900 md:text-3xl">{title}</h1>
      <p className="mt-3 text-sm text-slate-600">{TEXTE_EN_PREPARATION}</p>
    </main>
  );
}
