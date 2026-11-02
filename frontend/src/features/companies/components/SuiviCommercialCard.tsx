/**
 * Fiche entreprise : responsable et affaire en cours (lecture). L'étape
 * commerciale se règle dans « Relation » ; elle ne recule jamais d'elle-même :
 * une étape posée à la main n'est plus touchée par l'import.
 *
 * Chaque ligne n'apparaît que si le serveur fournit son champ (`undefined` =
 * non servi) : « Non attribué » ne doit jamais s'afficher faute de donnée.
 */
import { Card, CardEyebrow, CardHeader, CardTitle } from '@/components/ui';

export interface AffaireFiche {
  titre: string;
  montant: string;
  etape: string;
}

export function SuiviCommercialCard({ responsable, affaire }: { responsable?: string | null | undefined; affaire?: AffaireFiche | null | undefined }) {
  return (
    <Card padding="md">
      <CardHeader>
        <div>
          <CardEyebrow>Commercial</CardEyebrow>
          <CardTitle>Suivi commercial</CardTitle>
        </div>
      </CardHeader>
      <dl className="space-y-3 text-sm">
        {responsable !== undefined ? (
          <div>
            <dt className="text-xs font-semibold uppercase tracking-wider text-slate-500">Responsable</dt>
            <dd className="mt-1 text-slate-900">{responsable ?? 'Non attribué'}</dd>
          </div>
        ) : null}
        {affaire !== undefined ? (
          <div>
            <dt className="text-xs font-semibold uppercase tracking-wider text-slate-500">Affaire en cours</dt>
            <dd className="mt-1 text-slate-900">
              {affaire ? (
                <>
                  {affaire.titre} <span className="text-slate-500">· {affaire.montant} · {affaire.etape}</span>
                </>
              ) : (
                'Aucune affaire en cours'
              )}
            </dd>
          </div>
        ) : null}
      </dl>
    </Card>
  );
}
