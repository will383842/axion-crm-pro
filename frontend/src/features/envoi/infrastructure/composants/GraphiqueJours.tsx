/**
 * DM2-INF — graphique des 30 derniers jours (SVG maison, sans dépendance).
 * Barres : messages envoyés ; points : refus. Équivalent texte : un tableau
 * réservé aux lecteurs d'écran, une ligne par jour.
 */
import type { JourActivite } from './api';
import { dateCourte, nombre } from './format';

const L = 600;
const H = 160;
const MARGE = 8;

export function GraphiqueJours({ jours, libelle }: { jours: JourActivite[]; libelle: string }) {
  const max = Math.max(1, ...jours.map((j) => j.envoyes));
  const pas = (L - MARGE * 2) / Math.max(1, jours.length);
  const refusMax = Math.max(1, ...jours.map((j) => j.rebonds_durs + j.rebonds_mous));
  const premier = jours[0];
  const milieu = jours[Math.floor(jours.length / 2)];
  return (
    <figure className="m-0">
      <svg viewBox={`0 0 ${L} ${H}`} role="img" aria-label={`${libelle} : 30 derniers jours, messages envoyés par jour et refus`} className="h-auto w-full text-sky-600">
        <line x1={MARGE} x2={L - MARGE} y1={H - 20} y2={H - 20} stroke="#cbd5e1" strokeWidth="1" />
        {jours.map((j, i) => {
          const h = ((H - 40) * j.envoyes) / max;
          const x = MARGE + i * pas + pas * 0.15;
          return <rect key={j.jour} x={x} y={H - 20 - h} width={pas * 0.7} height={Math.max(0, h)} rx="2" fill="currentColor" opacity="0.85" />;
        })}
        {jours.map((j, i) => {
          const r = j.rebonds_durs + j.rebonds_mous;
          if (r === 0) return null;
          const cy = 12 + (1 - r / refusMax) * 24;
          return <circle key={`r-${j.jour}`} cx={MARGE + i * pas + pas / 2} cy={cy} r="4" fill="#d97706" stroke="#fff" strokeWidth="1" />;
        })}
      </svg>
      <div aria-hidden className="mt-1 flex justify-between text-xs text-slate-500">
        <span>{premier ? dateCourte(premier.jour) : ''}</span>
        <span>{milieu ? dateCourte(milieu.jour) : ''}</span>
        <span>Aujourd’hui</span>
      </div>
      <p aria-hidden className="mt-1 text-xs text-slate-500">
        <span className="mr-3 inline-flex items-center gap-1"><span className="inline-block h-2 w-3 rounded-sm bg-sky-600" />Envoyés</span>
        <span className="inline-flex items-center gap-1"><span className="inline-block h-2.5 w-2.5 rounded-full bg-amber-600" />Refus</span>
      </p>
      <table className="sr-only">
        <caption>{libelle} : 30 derniers jours</caption>
        <thead>
          <tr><th scope="col">Jour</th><th scope="col">Envoyés</th><th scope="col">Refus</th><th scope="col">Plaintes</th><th scope="col">Réponses</th></tr>
        </thead>
        <tbody>
          {jours.map((j) => (
            <tr key={j.jour}>
              <th scope="row">{dateCourte(j.jour)}</th>
              <td>{nombre(j.envoyes)}</td>
              <td>{j.rebonds_durs + j.rebonds_mous}</td>
              <td>{j.plaintes}</td>
              <td>{j.reponses}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </figure>
  );
}

export function totaux(jours: JourActivite[]) {
  const envoyes = jours.reduce((n, j) => n + j.envoyes, 0);
  const refus = jours.reduce((n, j) => n + j.rebonds_durs + j.rebonds_mous, 0);
  const plaintes = jours.reduce((n, j) => n + j.plaintes, 0);
  const reponses = jours.reduce((n, j) => n + j.reponses, 0);
  return { envoyes, refus, plaintes, reponses, tauxRefus: envoyes > 0 ? (refus / envoyes) * 100 : 0, tauxPlaintes: envoyes > 0 ? (plaintes / envoyes) * 100 : 0 };
}
