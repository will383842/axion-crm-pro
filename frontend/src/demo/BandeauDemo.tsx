/**
 * Bandeau permanent de la démo, au-dessus de tout (connexion comprise).
 *
 * « Démonstration : données fictives », les identifiants et le code de
 * connexion de démonstration (aucun compte réel), « Voir comme » (trois
 * offres) et « Remettre la démo à zéro ». Monté par `main.tsx` seulement en
 * mode démo : absent du build normal.
 */
import { useId } from 'react';
import { FlaskConical, RotateCcw } from 'lucide-react';
import { demoDb } from './base';
import { IDENTIFIANTS_DEMO } from './donnees';
import { OFFRES, choisirOffre, estOffre, lireOffre } from './offres';
import { oublierScenarios } from './scenarios';
import { definirEtatForce } from './outils';

function recharger(): void {
  window.location.reload();
}

export function BandeauDemo() {
  const idOffre = useId();
  const offre = lireOffre();

  return (
    <section
      aria-label="Démonstration"
      className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-violet-200 bg-violet-50 px-4 py-2 text-sm text-violet-950"
    >
      <p className="flex items-center gap-2 font-semibold">
        <FlaskConical className="h-4 w-4 shrink-0" aria-hidden="true" />
        Démonstration : données fictives
      </p>
      <p className="text-xs text-violet-900">
        Connexion : <span className="font-medium">{IDENTIFIANTS_DEMO.email}</span>, mot de passe{' '}
        <span className="font-medium">{IDENTIFIANTS_DEMO.motDePasse}</span>, code{' '}
        <span className="font-mono font-semibold" data-testid="code-demo">
          {IDENTIFIANTS_DEMO.code}
        </span>
      </p>
      <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
        <label htmlFor={idOffre} className="text-xs font-medium">
          Voir comme
        </label>
        <select
          id={idOffre}
          value={offre}
          onChange={(e) => {
            if (!estOffre(e.target.value)) return;
            choisirOffre(e.target.value);
            recharger();
          }}
          className="h-11 rounded-lg border border-violet-300 bg-white px-2 text-sm text-slate-900 sm:h-9"
        >
          {OFFRES.map((o) => (
            <option key={o.valeur} value={o.valeur}>
              {o.libelle}
            </option>
          ))}
        </select>
        <button
          type="button"
          onClick={() => {
            demoDb.reinitialiser();
            oublierScenarios();
            definirEtatForce(null);
            recharger();
          }}
          className="inline-flex h-11 items-center gap-2 rounded-lg border border-violet-300 bg-white px-3 text-sm font-medium text-violet-950 hover:bg-violet-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 sm:h-9"
        >
          <RotateCcw className="h-4 w-4" aria-hidden="true" />
          Remettre la démo à zéro
        </button>
      </div>
    </section>
  );
}
