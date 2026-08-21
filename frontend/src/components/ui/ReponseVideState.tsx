/**
 * `ReponseVideState` — le serveur a repondu, et il n'a RIEN envoye.
 *
 * ═══ LE TROU QUE CE COMPOSANT FERME (D25-004) ═══
 *
 * `QueryErrorState` (D25-001) couvre tout ce qui produit une ERREUR : 403, 500,
 * reseau coupe, delai depasse. Il lui faut un objet `error` pour parler — c'est
 * lui qui porte le code HTTP et la nature de l'echec.
 *
 * Il existe un cas ou cet objet N'EXISTE PAS, et ou l'ecran n'a pourtant aucune
 * donnee a rendre : le serveur repond **200 avec un corps vide** (ou `null`).
 * React Query considere alors la requete REUSSIE :
 *
 *     query.error   === null        <- rien a montrer a `QueryErrorState`
 *     query.isLoading === false     <- le chargement est bel et bien fini
 *     query.data    === undefined   <- et il n'y a rien
 *
 * Un ecran repare a coups de `error !== null && data === undefined` reste donc
 * bloque sur son sablier dans ce cas precis. Mesure du 2026-08-21 : `/settings`
 * avait recu la branche d'erreur (commit 5be7ef0) et restait malgre tout en
 * « Chargement… » perpetuel sous une reponse 200 vide.
 *
 * ═══ POURQUOI PAS `EmptyState` ═══
 *
 * « Il n'y a rien dans cette fiche » et « le serveur n'a pas envoye la fiche »
 * appellent deux gestes opposes : ne rien faire, ou reessayer puis signaler.
 * C'est exactement la confusion que D25-001 a coute. D'ou `role="alert"` et un
 * bouton « Reessayer » — le serveur a peut-etre simplement hoquete.
 *
 * ⚠️ La garde `tests/screens/sablier-eternel.test.tsx` cherche la sous-chaine
 * SANS ACCENT « vide du serveur ». Ne la casse pas en reecrivant ce titre.
 */
import { FileQuestion } from 'lucide-react';

import { Button } from './Button';

export interface ReponseVideStateProps {
  /**
   * Ce que l'ecran essayait de charger, au complement d'objet :
   * « cette campagne », « les reglages de l'espace de travail ».
   */
  contexte: string;
  /** Branche sur `query.refetch()`. Omis, le bouton n'apparait pas. */
  onRetry?: () => void;
}

export function ReponseVideState({ contexte, onRetry }: ReponseVideStateProps) {
  return (
    <div
      // `alert` et non `status` : une reponse vide est une anomalie de service,
      // pas un etat normal de la donnee.
      role="alert"
      className="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-amber-300 bg-amber-50/60 px-6 py-12 text-center dark:border-amber-900 dark:bg-amber-950/30"
    >
      <div className="mb-3 text-amber-600 dark:text-amber-400" aria-hidden>
        <FileQuestion className="h-9 w-9" />
      </div>
      <h2 className="text-lg font-semibold text-amber-900 dark:text-amber-200">
        Réponse vide du serveur
      </h2>
      <p className="mt-1 max-w-xl text-sm text-amber-800 dark:text-amber-300/90">
        {`Le serveur a répondu sans erreur pour ${contexte}, mais il n’a renvoyé aucune donnée. `}
        Ce n’est donc pas une fiche vide&nbsp;: c’est la réponse elle-même qui était vide.
        Réessayez, et signalez-le si cela recommence.
      </p>
      <p className="mt-3 font-mono text-xs text-amber-800 opacity-80 dark:text-amber-300/90">
        code HTTP 200, corps vide
      </p>
      {onRetry !== undefined ? (
        <div className="mt-5">
          <Button variant="secondary" size="sm" onClick={onRetry}>
            Réessayer
          </Button>
        </div>
      ) : null}
    </div>
  );
}
