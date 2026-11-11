/**
 * Fiche entreprise : résumé de ce qu'Envoi a envoyé à cette entreprise et des
 * réponses reçues. N'existe que si Envoi est actif pour l'espace. Les
 * ouvertures ne sont pas mesurées : on ne les affiche pas.
 */
import { Link } from '@tanstack/react-router';
import { Card, CardEyebrow, CardHeader, CardTitle } from '@/components/ui';

export interface EnvoiFiche {
  campagne: string;
  message: string;
  statut: string;
  le: string;
  reponse: string | null;
}

export function EnvoisRecusCard({ companyId, envois }: { companyId: number; envois: EnvoiFiche[] }) {
  return (
    <Card padding="md">
      <CardHeader>
        <div>
          <CardEyebrow>Produit Envoi</CardEyebrow>
          <CardTitle>Envois reçus</CardTitle>
        </div>
      </CardHeader>
      {envois.length === 0 ? (
        <p className="text-sm text-slate-500">Aucun envoi pour cette entreprise.</p>
      ) : (
        <ul className="divide-y divide-slate-100 text-sm">
          {envois.map((e) => (
            <li key={`${e.campagne}-${e.le}`} className="py-2">
              <p className="font-medium text-slate-900">
                {e.campagne} · {e.message}
              </p>
              <p className="text-xs text-slate-500">
                {e.statut} · {new Date(e.le).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })}
              </p>
              {e.reponse ? <p className="mt-1 text-xs text-slate-700">Réponse : {e.reponse}</p> : null}
            </li>
          ))}
        </ul>
      )}
      <div className="mt-3 flex flex-wrap gap-2">
        <Link
          to={'/envoi/campagnes/nouvelle'}
          search={{ entreprise: String(companyId) } as never}
          className="inline-flex h-9 items-center justify-center rounded-lg bg-white px-4 text-sm font-medium text-slate-900 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50 max-sm:h-11"
        >
          Ajouter à une campagne
        </Link>
        <Link
          to={'/envoi/sante/chercher'}
          className="inline-flex h-9 items-center justify-center rounded-lg px-4 text-sm font-medium text-slate-700 hover:bg-slate-100 max-sm:h-11"
        >
          Chercher un envoi
        </Link>
      </div>
    </Card>
  );
}
