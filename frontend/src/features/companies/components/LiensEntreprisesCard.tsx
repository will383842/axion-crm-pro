import { Link } from '@tanstack/react-router';
import { Card, CardEyebrow, CardHeader, CardTitle } from '@/components/ui';

export interface LienFiche {
  company_id: number;
  denomination: string;
  lien: string;
}

/** Fiche entreprise : groupe, société mère, filiale (donnée déjà en base). */
export function LiensEntreprisesCard({ liens }: { liens: LienFiche[] }) {
  return (
    <Card padding="md">
      <CardHeader>
        <div>
          <CardEyebrow>Groupe</CardEyebrow>
          <CardTitle>Liens entre entreprises</CardTitle>
        </div>
      </CardHeader>
      {liens.length === 0 ? (
        <p className="text-sm text-slate-500">Aucun lien connu avec une autre entreprise.</p>
      ) : (
        <ul className="divide-y divide-slate-100 text-sm">
          {liens.map((l) => (
            <li key={`${l.company_id}-${l.denomination}`} className="flex flex-wrap items-center justify-between gap-2 py-2">
              {l.company_id > 0 ? (
                <Link to="/companies/$companyId" params={{ companyId: String(l.company_id) }} className="font-medium text-brand-600 hover:underline">
                  {l.denomination}
                </Link>
              ) : (
                <span className="font-medium text-slate-900">{l.denomination}</span>
              )}
              <span className="text-xs text-slate-500">{l.lien}</span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
