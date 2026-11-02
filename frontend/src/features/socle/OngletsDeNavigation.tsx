/**
 * DM0-COQ — barre d'onglets PAR ADRESSE, socle des six barres partagées
 * (`OngletsTableauDeBord`, `OngletsCampagnes`, `OngletsSante`,
 * `OngletsServeurs`, `OngletsReponses`, `OngletsReglages`).
 *
 * Chaque onglet est un lien (et non un état local) : il survit au
 * rechargement, se partage, et le retour arrière fonctionne. L'onglet actif
 * prend la couleur du produit (ADR-13 § 5) — c'est une position, pas un état.
 */
import { Link, useRouterState } from '@tanstack/react-router';
import { cn } from '@/components/ui';

export interface OngletDeNavigation {
  to: string;
  libelle: string;
  /** Actif aussi sur les sous-pages (`/envoi/campagnes/c-1` pour « Campagnes »). Défaut : exact. */
  sousPages?: boolean;
}

export interface OngletsDeNavigationProps {
  /** Nom de la barre pour les lecteurs d'écran. */
  libelle: string;
  onglets: readonly OngletDeNavigation[];
}

export function ongletActif(onglets: readonly OngletDeNavigation[], chemin: string): string | null {
  // L'onglet le plus précis gagne : sur `/envoi/campagnes/messages`, « Messages
  // types » et non « Campagnes ».
  const candidats = onglets.filter((o) =>
    o.sousPages === true ? chemin === o.to || chemin.startsWith(`${o.to}/`) : chemin === o.to,
  );
  candidats.sort((a, b) => b.to.length - a.to.length);
  return candidats[0]?.to ?? null;
}

export function OngletsDeNavigation({ libelle, onglets }: OngletsDeNavigationProps) {
  const chemin = useRouterState({ select: (s) => s.location.pathname });
  const actif = ongletActif(onglets, chemin);
  return (
    <nav aria-label={libelle} className="mb-4 overflow-x-auto border-b border-slate-200">
      <ul className="flex min-w-max gap-1">
        {onglets.map((onglet) => {
          const estActif = onglet.to === actif;
          return (
            <li key={onglet.to}>
              <Link
                to={onglet.to}
                aria-current={estActif ? 'page' : undefined}
                className={cn(
                  'inline-flex h-9 items-center border-b-2 px-3 text-sm font-medium transition',
                  estActif
                    ? 'border-produit text-produit'
                    : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900',
                )}
              >
                {onglet.libelle}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
