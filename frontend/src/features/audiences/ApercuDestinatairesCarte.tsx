/**
 * L'APERÇU CHIFFRÉ des destinataires (2026-09-30) : organisations, adresses
 * distinctes, exclues par motif, écartées par le réglage, et un échantillon.
 * Les adresses arrivent déjà masquées pour un compte en lecture seule.
 */
import { MOTIFS_EXCLUSION, RAISONS_REGLAGE, type ApercuDestinataires } from './destinataires';

export function ApercuDestinatairesCarte({ apercu }: { apercu: ApercuDestinataires }) {
  const exclues = Object.entries(apercu.exclues).filter(([, n]) => n > 0);
  const ecartees = Object.entries(apercu.ecartees_par_le_reglage).filter(([, n]) => n > 0);

  return (
    <div className="space-y-3 text-sm" data-testid="apercu-destinataires">
      <div className="grid grid-cols-2 gap-2">
        <Chiffre libelle="Adresses distinctes" valeur={apercu.destinataires} />
        <Chiffre libelle="Organisations" valeur={apercu.organisations} />
        <Chiffre libelle="Génériques" valeur={apercu.par_type.generique} />
        <Chiffre libelle="Nominatives" valeur={apercu.par_type.nominative} />
      </div>
      <ul className="space-y-0.5 text-xs text-slate-600 dark:text-slate-300">
        <li>{apercu.organisations_sans_destinataire.toLocaleString('fr-FR')} organisation(s) sans aucune adresse retenue</li>
        <li>
          {apercu.adresses_partagees_entre_organisations.toLocaleString('fr-FR')} adresse(s) partagée(s) entre organisations —{' '}
          {apercu.doublons_evites.toLocaleString('fr-FR')} envoi(s) en double évité(s)
        </li>
      </ul>
      {exclues.length > 0 ? (
        <div>
          <div className="text-xs font-semibold uppercase tracking-wider text-slate-500">
            Exclues ({apercu.exclues_total.toLocaleString('fr-FR')})
          </div>
          <ul className="text-xs text-slate-600 dark:text-slate-300">
            {exclues.map(([motif, n]) => (
              <li key={motif}>
                {MOTIFS_EXCLUSION[motif] ?? motif} : {n.toLocaleString('fr-FR')}
              </li>
            ))}
          </ul>
        </div>
      ) : null}
      {ecartees.length > 0 ? (
        <div>
          <div className="text-xs font-semibold uppercase tracking-wider text-slate-500">
            Écartées par le réglage ({apercu.ecartees_total.toLocaleString('fr-FR')})
          </div>
          <ul className="text-xs text-slate-600 dark:text-slate-300">
            {ecartees.map(([raison, n]) => (
              <li key={raison}>
                {RAISONS_REGLAGE[raison] ?? raison} : {n.toLocaleString('fr-FR')}
              </li>
            ))}
          </ul>
        </div>
      ) : null}
      {apercu.lignes.length > 0 ? (
        <div>
          <div className="text-xs font-semibold uppercase tracking-wider text-slate-500">Échantillon</div>
          <ul className="divide-y divide-slate-100 text-xs dark:divide-slate-800">
            {apercu.lignes.map((l) => (
              <li key={l.crm_ref + (l.email ?? '')} className="py-1">
                <span className="font-mono">{l.email ?? '—'}</span>{' '}
                <span className="text-slate-500">
                  · {l.type === 'generique' ? 'générique' : 'nominative'}
                  {l.fonction !== null && l.fonction !== '' ? ` · ${l.fonction}` : ''}
                  {' · '}
                  {l.organisations.map((o) => o.nom).join(', ')}
                </span>
              </li>
            ))}
          </ul>
        </div>
      ) : null}
    </div>
  );
}

function Chiffre({ libelle, valeur }: { libelle: string; valeur: number }) {
  return (
    <div className="rounded-lg bg-white p-2 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
      <div className="text-xs font-semibold uppercase tracking-wider text-slate-500">{libelle}</div>
      <div className="text-lg font-semibold tabular-nums text-slate-900 dark:text-white">{valeur.toLocaleString('fr-FR')}</div>
    </div>
  );
}
