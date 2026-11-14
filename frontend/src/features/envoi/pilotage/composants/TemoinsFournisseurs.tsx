/** Journées témoins par fournisseur sur 14 jours. Lecture seule : aucune saisie, aucun « vert » inventé. */
export type EtatJourTemoin = 'ok' | 'indesirable' | 'absent' | 'non_teste';
export type LigneTemoins = { fournisseur: string; jours: { jour: string; etat: EtatJourTemoin }[] };

const NOMS: Record<string, string> = { gmail: 'Gmail', microsoft: 'Microsoft', yahoo: 'Yahoo', orange: 'Orange', ovh: 'OVH', autre: 'Autre' };
export const nomFournisseur = (f: string): string => NOMS[f] ?? (f.length === 0 ? f : f.charAt(0).toUpperCase() + f.slice(1));

const SYMBOLE: Record<EtatJourTemoin, { texte: string; libelle: string; classe: string }> = {
  ok: { texte: '✓', libelle: 'arrivé en boîte de réception', classe: 'text-emerald-700' },
  indesirable: { texte: '✗', libelle: 'tombé en indésirables', classe: 'text-red-700' },
  absent: { texte: '!', libelle: 'non reçu', classe: 'text-amber-700' },
  non_teste: { texte: '·', libelle: 'non testé', classe: 'text-slate-500' },
};

export function TemoinsFournisseurs({ lignes }: { lignes: LigneTemoins[] | undefined }) {
  if (lignes === undefined || lignes.length === 0) {
    return <p className="text-sm text-slate-600">Témoins par fournisseur non mesurés : aucun relevé pour l’instant. Rien n’est considéré comme bon.</p>;
  }
  const jours = lignes[0]!.jours.map((j) => j.jour);
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[640px] text-left text-xs">
        <caption className="sr-only">Témoins par fournisseur sur 14 jours</caption>
        <thead className="text-slate-600">
          <tr>
            <th scope="col" className="py-2 pr-3 text-sm">Fournisseur</th>
            {jours.map((j) => <th key={j} scope="col" className="px-1 py-2 text-center font-normal"><span aria-hidden>{j.slice(8)}</span><span className="sr-only">{j}</span></th>)}
          </tr>
        </thead>
        <tbody>
          {lignes.map((l) => (
            <tr key={l.fournisseur} className="border-t border-slate-100">
              <th scope="row" className="py-2 pr-3 text-sm font-medium">{nomFournisseur(l.fournisseur)}</th>
              {l.jours.map((j) => (
                <td key={j.jour} className={`px-1 py-2 text-center ${SYMBOLE[j.etat].classe}`} title={`${j.jour} : ${SYMBOLE[j.etat].libelle}`}>
                  <span aria-hidden>{SYMBOLE[j.etat].texte}</span><span className="sr-only">{j.jour} : {SYMBOLE[j.etat].libelle}</span>
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
