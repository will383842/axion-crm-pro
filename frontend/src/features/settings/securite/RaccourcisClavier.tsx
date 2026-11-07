import { Card, CardEyebrow, CardHeader, CardTitle } from '@/components/ui';

const RACCOURCIS: Array<[string, string]> = [
  ['Ctrl K', 'Rechercher une page, une fiche, une adresse ou une action'],
  ['Échap', 'Fermer la fenêtre ou le panneau ouvert'],
  ['Tab', 'Passer à l’élément suivant, sans souris'],
  ['Entrée', 'Valider le formulaire ou ouvrir l’élément choisi'],
];

export function RaccourcisClavier() {
  return (
    <Card>
      <CardHeader>
        <div>
          <CardEyebrow>Confort</CardEyebrow>
          <CardTitle className="mt-1 text-lg font-bold">Raccourcis clavier</CardTitle>
        </div>
      </CardHeader>
      <dl className="space-y-2">
        {RACCOURCIS.map(([touche, effet]) => (
          <div key={touche} className="flex flex-wrap items-center gap-3 text-sm">
            <dt>
              <kbd className="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-800 ring-1 ring-slate-200">{touche}</kbd>
            </dt>
            <dd className="text-slate-600">{effet}</dd>
          </div>
        ))}
      </dl>
    </Card>
  );
}
