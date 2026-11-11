import { Card, CardEyebrow, CardHeader, CardTitle } from '@/components/ui';

export interface LigneHistorique {
  le: string;
  quoi: string;
}

/** Fiche entreprise : ce qui s'est passé, du plus récent au plus ancien. */
export function HistoriqueCard({ lignes }: { lignes: LigneHistorique[] }) {
  return (
    <Card padding="md">
      <CardHeader>
        <div>
          <CardEyebrow>Activité</CardEyebrow>
          <CardTitle>Historique</CardTitle>
        </div>
      </CardHeader>
      {lignes.length === 0 ? (
        <p className="text-sm text-slate-500">Rien à afficher pour le moment.</p>
      ) : (
        <ul className="divide-y divide-slate-100 text-sm">
          {lignes.map((l) => (
            <li key={`${l.le}-${l.quoi}`} className="flex flex-wrap items-baseline justify-between gap-2 py-2">
              <span className="text-slate-900">{l.quoi}</span>
              <time dateTime={l.le} className="text-xs text-slate-500">
                {new Date(l.le).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })}
              </time>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
