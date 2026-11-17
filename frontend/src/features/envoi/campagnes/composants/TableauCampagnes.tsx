/**
 * DM2-CAM — tableau des campagnes. Sur grand écran, une table ; au téléphone,
 * une carte par campagne (aucun défilement horizontal). Les rôles ARIA sont
 * posés à la main : l'affichage change, la structure lue ne change pas.
 */
import { useState } from 'react';
import { Link } from '@tanstack/react-router';
import { StatusPill } from '@/components/ui';
import type { Campagne } from '../api';
import { BarreProgression } from './BarreProgression';
import { GestesCampagne } from './GestesCampagne';
import { dateRelative, phraseBlocage, STATUT_LIBELLE, STATUT_TON } from './libelles';

const GRILLE = 'md:grid-cols-[minmax(0,2.2fr)_8rem_minmax(0,1.6fr)_9rem_9rem_minmax(0,2.4fr)]';
const BOUTON_LIEN =
  'inline-flex h-9 items-center justify-center rounded-lg bg-white px-4 text-sm font-medium text-slate-900 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50 max-md:h-11';

function Reponses({ c }: { c: Campagne }) {
  if (c.envoyes === 0) return <span className="text-slate-400">—</span>;
  const interessees = c.reponses_interessees;
  return (
    <span>
      <strong className="font-semibold text-slate-900">{c.reponses}</strong>
      {interessees > 0 ? <span className="text-slate-600"> ({interessees} {interessees > 1 ? 'intéressées' : 'intéressée'})</span> : null}
    </span>
  );
}

/** B8-B : pastille « Bloquée » + raison en texte (lisible sans couleur) + lien vers le suivi. Rien si `blocage` est nul. */
function Blocage({ c }: { c: Campagne }) {
  if (c.blocage === null || c.blocage === undefined) return null;
  return (
    <p data-testid="blocage-ligne" className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-700">
      <StatusPill tone="danger">Bloquée</StatusPill>
      <span>{phraseBlocage(c.blocage.code)}</span>
      <Link to="/envoi/campagnes/$campagneId" params={{ campagneId: String(c.id) }} className="font-medium text-produit underline" aria-label={`Voir pourquoi : ${c.nom}`}>
        Voir pourquoi
      </Link>
    </p>
  );
}

export function TableauCampagnes({ campagnes, maintenant }: { campagnes: readonly Campagne[]; maintenant: string }) {
  // Filtre « Bloquées » : côté écran, sur la page chargée seulement.
  const [seulementBloquees, setSeulementBloquees] = useState(false);
  const nbBloquees = campagnes.filter((c) => c.blocage !== null && c.blocage !== undefined).length;
  const visibles = seulementBloquees ? campagnes.filter((c) => c.blocage !== null && c.blocage !== undefined) : campagnes;
  return (
    <>
      <div className="mb-2 flex items-center gap-3">
        <button
          type="button"
          aria-pressed={seulementBloquees}
          onClick={() => setSeulementBloquees((v) => !v)}
          className="inline-flex h-9 items-center rounded-full px-3 text-sm font-medium text-slate-800 ring-1 ring-slate-300 hover:bg-slate-50 aria-pressed:bg-slate-900 aria-pressed:text-white max-md:h-11"
        >
          Bloquées ({nbBloquees})
        </button>
      </div>
      {seulementBloquees && visibles.length === 0 ? (
        <p role="status" className="rounded-xl bg-white px-4 py-6 text-sm text-slate-700 ring-1 ring-slate-200">
          Aucune campagne bloquée sur cette page.
        </p>
      ) : (
        <Table campagnes={visibles} maintenant={maintenant} />
      )}
    </>
  );
}

function Table({ campagnes, maintenant }: { campagnes: readonly Campagne[]; maintenant: string }) {
  return (
    <div role="table" aria-label="Campagnes" className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <div role="row" className={`hidden gap-4 border-b border-slate-100 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 md:grid ${GRILLE}`}>
        <div role="columnheader">Campagne</div>
        <div role="columnheader">Statut</div>
        <div role="columnheader">Progression</div>
        <div role="columnheader">Réponses</div>
        <div role="columnheader">Prochain envoi</div>
        <div role="columnheader">Actions</div>
      </div>
      <div role="rowgroup" className="divide-y divide-slate-100">
        {campagnes.map((c) => {
          const id = String(c.id);
          const prochain = (c.statut === 'en_cours' || c.statut === 'programmee') && c.prochain_envoi_le !== null ? dateRelative(c.prochain_envoi_le, maintenant) : '—';
          // Seul un brouillon mène à l'assistant ; « à valider » retourne au suivi.
          const lien =
            c.statut === 'brouillon' ? (
              <Link to="/envoi/campagnes/nouvelle" search={{ brouillon: id }} className={BOUTON_LIEN} aria-label={`Ouvrir : ${c.nom}`}>
                Ouvrir
              </Link>
            ) : c.statut === 'a_valider' ? (
              <Link to="/envoi/campagnes/$campagneId" params={{ campagneId: id }} className={BOUTON_LIEN} aria-label={`Relire et valider : ${c.nom}`}>
                Relire et valider
              </Link>
            ) : null;
          return (
            <div key={id} role="row" className={`grid gap-x-4 gap-y-2 px-4 py-3 text-sm ${GRILLE} md:items-center`}>
              <div role="cell" className="min-w-0">
                <Link to="/envoi/campagnes/$campagneId" params={{ campagneId: id }} className="font-semibold text-produit hover:underline">
                  {c.nom}
                </Link>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                  <span>
                    {c.cible.libelle} · {c.cible.total.toLocaleString('fr-FR')}
                  </span>
                </div>
                <Blocage c={c} />
              </div>
              <div role="cell">
                <StatusPill tone={STATUT_TON[c.statut]} pulse={c.statut === 'en_cours'}>
                  {STATUT_LIBELLE[c.statut]}
                </StatusPill>
              </div>
              <div role="cell" className="min-w-0">
                <span className="mb-1 block text-xs text-slate-500 md:hidden">Envoyés</span>
                <BarreProgression envoyes={c.envoyes} total={c.total} nom={c.nom} />
              </div>
              <div role="cell">
                <span className="mr-1 text-xs text-slate-500 md:hidden">Réponses :</span>
                <Reponses c={c} />
              </div>
              <div role="cell" className="text-slate-700">
                <span className="mr-1 text-xs text-slate-500 md:hidden">Prochain envoi :</span>
                {prochain}
              </div>
              <div role="cell">
                <GestesCampagne campagne={c} maintenant={maintenant} variante="ligne" avant={lien} />
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
