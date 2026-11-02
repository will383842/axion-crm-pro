/**
 * DM2-CAM — onglet « Destinataires » du suivi : l'état de CHAQUE destinataire
 * (en file, parti, refusé avec la raison en français, a répondu, arrêté pour
 * l'entreprise, exclu). La colonne « Ouverture (estimation) » n'existe que si la
 * campagne suit les ouvertures ; les ouvertures automatiques sont signalées et
 * ne comptent pas. Aucune colonne de clic, jamais.
 */
import { useDeferredValue, useState } from 'react';
import { Button, EmptyState, QueryErrorState, SearchInput, Skeleton, StatusPill } from '@/components/ui';
import { cn } from '@/components/ui/cn';
import { useDestinataires, type Campagne, type Destinataire, type FicheCampagne, type FiltreOuverture, type GroupeDestinataires } from '../api';
import { dateRelative, ETAT_LIBELLE, ETAT_TON, GROUPE_LIBELLE, heureDe, jourEtMois, libelleMotifAutomatique } from './libelles';

type Compteurs = FicheCampagne['compteurs'];

const ORDRE: ReadonlyArray<GroupeDestinataires> = ['en_file', 'partis', 'refuses', 'ont_repondu', 'arretes', 'exclus'];

function compteDuGroupe(c: Compteurs, g: GroupeDestinataires): number {
  switch (g) {
    case 'en_file': return c.en_file;
    case 'partis': return c.partis;
    case 'refuses': return c.refuses;
    case 'ont_repondu': return c.ont_repondu;
    case 'arretes': return c.arretes_entreprise;
    case 'exclus': return c.exclus;
  }
}

function nomComplet(d: Destinataire): string {
  const nom = [d.prenom, d.nom].filter((x): x is string => x !== null && x !== '').join(' ');
  return nom !== '' ? nom : d.adresse;
}

function initiales(d: Destinataire): string {
  return ([d.prenom, d.nom].map((x) => x?.charAt(0) ?? '').join('') || d.adresse.charAt(0)).toUpperCase();
}

function libelleMessage(d: Destinataire): string {
  return d.etape <= 1 ? 'Message 1' : `Relance ${d.etape - 1}`;
}

function detail(d: Destinataire, campagne: Campagne, maintenant: string): string {
  switch (d.etat) {
    case 'rebond_dur':
    case 'rebond_mou': {
      const code = d.code_serveur?.split(' ')[0];
      return `${d.motif ?? 'Refusé par le serveur'}${code ? ` (${code})` : ''}`;
    }
    case 'a_repondu':
      return d.motif ?? 'Relances annulées pour cette personne';
    case 'relances_annulees':
      return d.motif ?? 'Relances arrêtées';
    case 'a_envoyer':
      return campagne.prochain_envoi_le !== null && campagne.statut === 'en_cours' ? `Départ prévu ${dateRelative(campagne.prochain_envoi_le, maintenant)}` : 'En attente du départ';
    case 'desinscrit':
      return 'Désinscrit : plus aucun message';
    case 'plainte':
      return 'A signalé le message : plus aucun message';
    case 'exclu':
      return d.motif ?? 'Exclu de la campagne';
    default:
      return 'Accepté par le serveur';
  }
}

function Ouverture({ d }: { d: Destinataire }) {
  const o = d.ouverture;
  if (o === null) return <span className="text-slate-400">—</span>;
  if (o.automatique_probable) {
    return (
      <span className="text-slate-600">
        Automatique probable ({libelleMotifAutomatique(o.raison_automatique)}) : non comptée
      </span>
    );
  }
  return (
    <span className="text-slate-700">
      Ouvert {o.nombre > 1 ? `${o.nombre} fois` : '1 fois'}, le {jourEtMois(o.premiere_le)} à {heureDe(o.premiere_le)}
    </span>
  );
}

export function TableauDestinataires({ fiche }: { fiche: FicheCampagne }) {
  const { campagne, compteurs, maintenant } = fiche;
  const id = String(campagne.id);
  const [groupe, setGroupe] = useState<GroupeDestinataires | null>(null);
  const [ouvert, setOuvert] = useState<FiltreOuverture | null>(null);
  const [recherche, setRecherche] = useState('');
  const [page, setPage] = useState(1);
  const q = useDeferredValue(recherche);
  const requete = useDestinataires(id, { groupe, ouvert, q, page }, true);
  const avecOuvertures = campagne.suivre_ouvertures;
  const colonnes = avecOuvertures
    ? 'md:grid-cols-[minmax(0,2fr)_9rem_6rem_minmax(0,2fr)_minmax(0,2fr)]'
    : 'md:grid-cols-[minmax(0,2fr)_9rem_6rem_minmax(0,3fr)]';

  const choisir = (g: GroupeDestinataires | null) => {
    setGroupe(g);
    setPage(1);
  };
  const filtres: Array<{ g: GroupeDestinataires | null; libelle: string; n: number }> = [
    { g: null, libelle: 'Tous', n: campagne.total },
    ...ORDRE.map((g) => ({ g, libelle: GROUPE_LIBELLE[g], n: compteDuGroupe(compteurs, g) })).filter((f) => f.g !== 'arretes' || f.n > 0),
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div role="group" aria-label="Filtrer par état" className="flex flex-wrap gap-2">
          {filtres.map((f) => (
            <button
              key={f.g ?? 'tous'}
              type="button"
              aria-pressed={groupe === f.g}
              onClick={() => choisir(f.g)}
              className={cn(
                'inline-flex h-9 items-center rounded-full px-3 text-sm font-medium ring-1 transition max-md:h-11',
                groupe === f.g ? 'bg-produit-pale text-produit ring-produit' : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50',
              )}
            >
              {f.libelle} · {f.n.toLocaleString('fr-FR')}
            </button>
          ))}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {avecOuvertures ? (
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <span>Ouverture (estimation)</span>
              <select
                value={ouvert ?? ''}
                onChange={(e) => {
                  setOuvert(e.target.value === '' ? null : (e.target.value as FiltreOuverture));
                  setPage(1);
                }}
                className="h-9 rounded-lg border border-slate-300 bg-white px-2 text-sm max-md:h-11"
              >
                <option value="">Toutes</option>
                <option value="oui">Ouvertes (hors automatiques)</option>
                <option value="automatique">Automatiques probables</option>
                <option value="non">Sans ouverture</option>
              </select>
            </label>
          ) : null}
          <SearchInput
            value={recherche}
            onChange={(v) => {
              setRecherche(v);
              setPage(1);
            }}
            label="Chercher un destinataire"
            placeholder="Chercher un destinataire"
          />
        </div>
      </div>

      {requete.isPending ? (
        <div role="status" aria-busy="true" aria-label="Chargement des destinataires" className="space-y-2">
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
        </div>
      ) : requete.isError ? (
        <QueryErrorState error={requete.error} contexte="les destinataires de cette campagne" onRetry={() => void requete.refetch()} />
      ) : requete.data.data.length === 0 ? (
        <EmptyState title="Rien à afficher pour le moment" description="Les éléments apparaîtront ici dès qu’il y en aura." />
      ) : (
        <>
          <div role="table" aria-label="Destinataires" className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div role="row" className={`hidden gap-4 border-b border-slate-100 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 md:grid ${colonnes}`}>
              <div role="columnheader">Personne</div>
              <div role="columnheader">État</div>
              <div role="columnheader">Message</div>
              <div role="columnheader">Détail</div>
              {avecOuvertures ? <div role="columnheader">Ouverture (estimation)</div> : null}
            </div>
            <div role="rowgroup" className="divide-y divide-slate-100">
              {requete.data.data.map((d) => (
                <div key={String(d.id)} role="row" className={`grid gap-x-4 gap-y-1 px-4 py-3 text-sm md:items-center ${colonnes}`}>
                  <div role="cell" className="flex min-w-0 items-center gap-3">
                    <span aria-hidden className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">
                      {initiales(d)}
                    </span>
                    <span className="min-w-0">
                      <span className="block truncate font-medium text-slate-900">{nomComplet(d)}</span>
                      <span className="block truncate text-xs text-slate-500">{d.organisation ?? d.adresse}</span>
                    </span>
                  </div>
                  <div role="cell">
                    <StatusPill tone={ETAT_TON[d.etat]}>{ETAT_LIBELLE[d.etat]}</StatusPill>
                  </div>
                  <div role="cell" className="text-slate-700">{libelleMessage(d)}</div>
                  <div role="cell" className="min-w-0 text-slate-700">{detail(d, campagne, maintenant)}</div>
                  {avecOuvertures ? (
                    <div role="cell" className="min-w-0 text-xs">
                      <Ouverture d={d} />
                    </div>
                  ) : null}
                </div>
              ))}
            </div>
          </div>
          {requete.data.meta.pages > 1 ? (
            <nav aria-label="Pages des destinataires" className="flex items-center justify-between gap-3 text-sm text-slate-600">
              <span>
                Page {requete.data.meta.page} sur {requete.data.meta.pages} · {requete.data.meta.total.toLocaleString('fr-FR')} destinataires
              </span>
              <span className="flex gap-2">
                <Button variant="secondary" className="max-md:h-11" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                  Précédent
                </Button>
                <Button variant="secondary" className="max-md:h-11" disabled={page >= requete.data.meta.pages} onClick={() => setPage((p) => p + 1)}>
                  Suivant
                </Button>
              </span>
            </nav>
          ) : null}
        </>
      )}
    </div>
  );
}
