import { Link, useSearch } from '@tanstack/react-router';
import { PageShell } from '@/components/ui/PageShell';

/**
 * L'ÉCRAN D'ATTERRISSAGE DES ROUTES SUPPRIMÉES — §8.2 de `10_NAVIGATION-CIBLE.md`.
 *
 * Le tableau de correspondance prescrit, pour `/cold-email` et `/linkedin` :
 * « 301 → `/pas-encore-livre?lot=L7` (écran unique, hors menu, qui nomme le lot
 * et la date) ». Cet écran est cette cible.
 *
 * 🔑 POURQUOI UN ÉCRAN PLUTÔT QU'UN 404. Le constat `I48-008` dit que
 * `/cold-email` et `/linkedin` sont « le seul endroit où le produit DÉPASSE son
 * périmètre » : le lot L7 est explicitement exclu. Les faire tomber en 404
 * effacerait la question ; les laisser afficher un écran de démonstration
 * laissait croire à une fonctionnalité. Un écran qui NOMME le lot et dit qu'il
 * n'est pas livré est la seule réponse qui ne mente dans aucun sens.
 *
 * ⚠️ Il n'est dans AUCUN menu, et c'est voulu : on n'y arrive que par un
 * signet ou un lien ancien.
 */

/** Les lots que cet écran sait nommer. Un lot inconnu reste affiché tel quel. */
const LOTS: Record<string, { titre: string; explication: string }> = {
  L7: {
    titre: 'Prospection sortante (cold email, LinkedIn)',
    explication:
      "Ce lot est hors du périmètre engagé. Les écrans qui l'esquissaient ont été retirés le 23 août 2026 : ils avaient l'apparence d'une fonctionnalité livrée.",
  },
};

export function PasEncoreLivrePage() {
  const recherche = useSearch({ strict: false });
  const lot = typeof recherche.lot === 'string' ? recherche.lot : null;
  const connu = lot !== null ? LOTS[lot] : undefined;

  return (
    <PageShell
      title="Pas encore livré"
      subtitle={lot !== null ? `Lot ${lot}` : 'Cette partie du produit n’existe pas encore'}
    >
      <div className="max-w-2xl rounded-xl border-2 border-dashed border-amber-300 bg-amber-50 p-6 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
        {connu !== undefined ? (
          <>
            <p className="text-base font-semibold">{connu.titre}</p>
            <p className="mt-2 text-sm leading-relaxed">{connu.explication}</p>
          </>
        ) : (
          <p className="text-sm leading-relaxed">
            L’adresse que vous avez suivie pointe vers une partie du produit qui n’est pas
            livrée. Ce n’est pas une panne : il n’y a rien à cette adresse, et il n’y a
            jamais rien eu de fonctionnel.
          </p>
        )}

        <p className="mt-4 text-sm">
          Aucune donnée n’est perdue et rien n’est en panne. Si vous êtes arrivé ici depuis
          un signet, il peut être supprimé.
        </p>
      </div>

      {/* D23-009 — les routes retirées tombaient sur un 404 SANS barre latérale,
          donc hors du gabarit, avec un seul lien. Cet écran est monté dans le
          gabarit : la navigation reste là, et ce lien est un secours, pas la
          seule issue. */}
      <Link
        to="/"
        className="mt-6 inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
      >
        Retour au tableau de bord
      </Link>
    </PageShell>
  );
}
