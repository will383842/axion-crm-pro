/**
 * DM0-COQ — LE SÉLECTEUR DE PRODUIT, EN HAUT DE LA BARRE (ADR-14).
 *
 * La barre montre le menu d'UN produit à la fois ; ce sélecteur passe de l'un
 * à l'autre. Pastille carrée de 32 px avec l'initiale (« C » bleu de marque,
 * « E » turquoise pétrole) + nom du produit.
 *
 *  - Visible SEULEMENT si les deux produits sont présents et accessibles à
 *    l'utilisateur (la barre le décide) : avec un seul produit, il disparaît.
 *  - Chaque lien rend la DERNIÈRE page ouverte de son produit (`sessionStorage`,
 *    `produit/dernierePage.ts`), sinon son accueil.
 *
 * Chaque pastille porte SON `data-produit` : sa couleur vient des jetons, même
 * quand la coquille est dans l'autre produit. Aucune couleur en dur.
 */
import { Link } from '@tanstack/react-router';
import { cn } from '@/components/ui';
import { dernierePage, type EspaceProduit } from '@/features/socle/produit/dernierePage';
import { NOM_DU_PRODUIT } from '@/features/socle/produit/useProduitCourant';

const INITIALE: Record<EspaceProduit, string> = { crm: 'C', envoi: 'E' };

export interface SelecteurProduitProps {
  /** L'espace dont la barre montre le menu. */
  espace: EspaceProduit;
  /** Barre réduite : seulement les pastilles. */
  reduit?: boolean;
}

export function PastilleProduit({ produit, className }: { produit: EspaceProduit; className?: string }) {
  return (
    <span
      aria-hidden
      data-produit={produit}
      data-pastille-produit
      className={cn(
        'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-produit text-sm font-bold text-white shadow-produit',
        className,
      )}
    >
      {INITIALE[produit]}
    </span>
  );
}

export function SelecteurProduit({ espace, reduit = false }: SelecteurProduitProps) {
  return (
    <nav aria-label="Choisir le produit" data-selecteur-produit>
      <ul className={cn('flex gap-1', reduit ? 'flex-col items-center' : 'flex-row')}>
        {(['crm', 'envoi'] as const).map((produit) => {
          const actif = produit === espace;
          return (
            <li key={produit} className={reduit ? undefined : 'min-w-0 flex-1'}>
              <Link
                to={dernierePage(produit)}
                // `exact` : sans lui, le lien du CRM vers « / » serait « actif »
                // sur toutes les pages, Envoi compris (TanStack y pose alors
                // `aria-current="page"`, qui l'emporte sur le nôtre).
                activeOptions={{ exact: true }}
                aria-current={actif ? 'true' : undefined}
                {...(actif ? { 'data-actif': '' } : {})}
                title={NOM_DU_PRODUIT[produit]}
                className={cn(
                  'flex items-center gap-2 rounded-lg p-1 text-sm font-medium transition',
                  actif ? 'bg-white/15 text-white ring-1 ring-white/20' : 'text-sidebar-fg hover:bg-white/10 hover:text-white',
                )}
              >
                <PastilleProduit produit={produit} />
                <span className={reduit ? 'sr-only' : 'truncate'}>{NOM_DU_PRODUIT[produit]}</span>
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
