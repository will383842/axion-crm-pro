/**
 * DM1-CIB — l'éditeur des critères ET / OU / SAUF.
 *
 * Des lignes « champ, opérateur, valeur », rangées en trois blocs :
 *  - ET   : toutes les lignes doivent être vraies (`criteria.all`) ;
 *  - OU   : au moins une ligne du groupe doit l'être (`criteria.any`) ;
 *  - SAUF : on écarte les fiches qui répondent à l'une d'elles (`criteria.not`).
 *
 * L'éditeur est contrôlé par la page (`valeur` / `onChange`) : elle fusionne
 * `versCriteres(valeur)` avec ses propres puces. Il relit les critères en une
 * phrase et affiche la taille estimée (« ≈ N fiches »), jamais une mesure
 * d'ouvertures ni de clics.
 */
import { useEffect, useId, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Plus, X } from 'lucide-react';
import { api } from '@/lib/api';
import { JOIGNABILITES, NATURES, REGIONS, SECTEURS, TAILLES, type EntreeReferentiel } from '@/lib/referentiels.generated';
import { RELATION_TYPE_LABELS } from '@/features/crm-console/types';
import { Button, cn } from '@/components/ui';
import type { AudienceCondition, AudienceCriteria } from './AudiencesListPage';

export type BlocCritere = 'et' | 'ou' | 'sauf';

export interface LigneEditeur {
  id: string;
  bloc: BlocCritere;
  champ: string;
  valeur: string;
}

export interface EtatEditeur {
  lignes: LigneEditeur[];
  /** Compteur des identifiants de ligne (stable, sans hasard). */
  suivant: number;
}

interface ChampEditable {
  code: string;
  libelle: string;
  operateur: string;
  /** Opérateur de l'API (`criteria`). */
  op: 'in' | 'contains_any';
  /** Valeurs proposées ; absent : saisie libre (séparée par des virgules). */
  options?: ReadonlyArray<{ code: string; libelle: string }>;
}

const enOptions = (liste: readonly EntreeReferentiel[]) => liste.map((e) => ({ code: e.code, libelle: e.libelle }));

export const CHAMPS_EDITABLES: readonly ChampEditable[] = [
  { code: 'department_code', libelle: 'Département', operateur: 'est l’un de', op: 'in' },
  { code: 'region_code', libelle: 'Région', operateur: 'est', op: 'in', options: enOptions(REGIONS) },
  { code: 'sector_main', libelle: 'Secteur', operateur: 'est', op: 'in', options: enOptions(SECTEURS) },
  { code: 'size_category', libelle: 'Taille', operateur: 'est', op: 'in', options: enOptions(TAILLES) },
  { code: 'entity_nature', libelle: 'Nature d’organisation', operateur: 'est', op: 'in', options: enOptions(NATURES) },
  {
    code: 'relation_type', libelle: 'Type de relation', operateur: 'est', op: 'in',
    options: Object.entries(RELATION_TYPE_LABELS).map(([code, libelle]) => ({ code, libelle })),
  },
  { code: 'joignabilite', libelle: 'Joignabilité', operateur: 'est', op: 'in', options: enOptions(JOIGNABILITES) },
  { code: 'tags', libelle: 'Étiquette', operateur: 'contient', op: 'contains_any' },
];

const champDe = (code: string) => CHAMPS_EDITABLES.find((c) => c.code === code);

export function etatVide(): EtatEditeur {
  return { lignes: [{ id: 'l1', bloc: 'et', champ: '', valeur: '' }], suivant: 2 };
}

/** Lignes dans l'ordre d'affichage : ET, puis le groupe OU, puis SAUF. */
export function lignesOrdonnees(etat: EtatEditeur): LigneEditeur[] {
  return (['et', 'ou', 'sauf'] as const).flatMap((b) => etat.lignes.filter((l) => l.bloc === b));
}

function conditionDe(l: LigneEditeur): AudienceCondition | null {
  const champ = champDe(l.champ);
  if (champ === undefined) return null;
  const valeurs = champ.options !== undefined ? [l.valeur] : l.valeur.split(/[,\s]+/);
  const propres = valeurs.map((v) => v.trim()).filter((v) => v !== '');
  return propres.length === 0 ? null : { field: champ.code, op: champ.op, value: propres };
}

/** Les lignes complètes, rangées en `all` / `any` / `not` ; un bloc vide est omis. */
export function versCriteres(etat: EtatEditeur): AudienceCriteria {
  const criteres: AudienceCriteria = {};
  const bloc = (b: BlocCritere) => etat.lignes.filter((l) => l.bloc === b).map(conditionDe).filter((c): c is AudienceCondition => c !== null);
  const et = bloc('et');
  const ou = bloc('ou');
  const sauf = bloc('sauf');
  if (et.length > 0) criteres.all = et;
  if (ou.length > 0) criteres.any = ou;
  if (sauf.length > 0) criteres.not = sauf;
  return criteres;
}

export function estVide(etat: EtatEditeur): boolean {
  return Object.keys(versCriteres(etat)).length === 0;
}

function libelleValeur(l: LigneEditeur): string {
  const champ = champDe(l.champ);
  if (champ?.options !== undefined) return champ.options.find((o) => o.code === l.valeur)?.libelle ?? l.valeur;
  return l.valeur.split(/[,\s]+/).filter((v) => v !== '').join(', ');
}

/** « Secteur est Bâtiment ET Région est Auvergne-Rhône-Alpes SAUF Étiquette contient client ». */
export function phraseDesCriteres(etat: EtatEditeur): string {
  const complete = (l: LigneEditeur) => conditionDe(l) !== null;
  const texte = (l: LigneEditeur) => `${champDe(l.champ)?.libelle ?? ''} ${champDe(l.champ)?.operateur ?? ''} ${libelleValeur(l)}`;
  const et = etat.lignes.filter((l) => l.bloc === 'et' && complete(l)).map(texte);
  const ou = etat.lignes.filter((l) => l.bloc === 'ou' && complete(l)).map(texte);
  const sauf = etat.lignes.filter((l) => l.bloc === 'sauf' && complete(l)).map(texte);
  const positifs = [...et, ...(ou.length > 1 ? [`(${ou.join(' OU ')})`] : ou)];
  const debut = positifs.join(' ET ');
  const fin = sauf.map((s) => `SAUF ${s}`).join(' ');
  return [debut, fin].filter((p) => p !== '').join(' ');
}

const BADGE: Record<BlocCritere, string> = {
  et: 'bg-slate-100 text-slate-700',
  ou: 'bg-violet-100 text-violet-700',
  sauf: 'bg-rose-100 text-rose-700',
};
const LIBELLE_BLOC: Record<BlocCritere, string> = { et: 'ET', ou: 'OU', sauf: 'SAUF' };

const CHAMP_SAISIE =
  'h-11 min-w-0 rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-300 sm:h-9';

interface Props {
  valeur: EtatEditeur;
  onChange: (etat: EtatEditeur) => void;
}

export function EditeurCriteres({ valeur, onChange }: Props) {
  const idTitre = useId();
  const criteres = useMemo(() => versCriteres(valeur), [valeur]);
  const cle = JSON.stringify(criteres);

  // Estimation : anti-rebond court, puis l'aperçu du faux serveur.
  const [cleStable, setCleStable] = useState(cle);
  useEffect(() => {
    const t = setTimeout(() => setCleStable(cle), 300);
    return () => clearTimeout(t);
  }, [cle]);
  const estimation = useQuery({
    queryKey: ['editeur-criteres', 'estimation', cleStable],
    queryFn: async () => (await api.post<{ companies: number; contacts: number }>('/audiences/preview', { criteria: JSON.parse(cleStable) as AudienceCriteria })).data,
    enabled: cleStable !== '{}',
  });

  const ajouter = (blocs: BlocCritere[]) => {
    let n = valeur.suivant;
    const neuves = blocs.map((bloc) => ({ id: `l${n++}`, bloc, champ: '', valeur: '' }));
    onChange({ lignes: [...valeur.lignes, ...neuves], suivant: n });
  };
  const modifier = (id: string, patch: Partial<LigneEditeur>) =>
    onChange({ ...valeur, lignes: valeur.lignes.map((l) => (l.id === id ? { ...l, ...patch } : l)) });
  const retirer = (id: string) => {
    const reste = valeur.lignes.filter((l) => l.id !== id);
    onChange({ ...valeur, lignes: reste.length > 0 ? reste : etatVide().lignes });
  };

  const lignes = lignesOrdonnees(valeur);
  const nbOu = lignes.filter((l) => l.bloc === 'ou').length;
  const phrase = phraseDesCriteres(valeur);

  let texteTaille = '—';
  if (cleStable !== '{}') {
    if (estimation.isError) texteTaille = 'Estimation indisponible';
    else if (estimation.data === undefined) texteTaille = 'Calcul en cours…';
    else {
      const n = estimation.data.companies;
      texteTaille = `≈ ${n} fiche${n > 1 ? 's' : ''}`;
    }
  }

  return (
    <section aria-labelledby={idTitre} className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 id={idTitre} className="text-sm font-semibold text-slate-900">Éditeur des critères (ET / OU / SAUF)</h3>
        <p role="status" aria-label="Taille estimée" className="text-sm font-medium text-slate-700">{texteTaille}</p>
      </div>

      <div className="space-y-2">
        {lignes.map((l, i) => {
          const champ = champDe(l.champ);
          return (
            <div
              key={l.id}
              role="group"
              aria-label={`Critère ${i + 1} (${LIBELLE_BLOC[l.bloc]})`}
              className={cn(
                'flex flex-wrap items-center gap-2 rounded-lg p-2',
                l.bloc === 'ou' ? 'border border-violet-200 bg-violet-50/40' : 'border border-transparent',
              )}
            >
              <span className={cn('inline-flex h-7 min-w-10 items-center justify-center rounded-md px-2 text-xs font-semibold', BADGE[l.bloc])}>
                {LIBELLE_BLOC[l.bloc]}
              </span>
              <select
                aria-label="Champ"
                value={l.champ}
                onChange={(e) => modifier(l.id, { champ: e.target.value, valeur: '' })}
                className={cn(CHAMP_SAISIE, 'w-full sm:w-52')}
              >
                <option value="">Choisir un champ</option>
                {CHAMPS_EDITABLES.map((c) => <option key={c.code} value={c.code}>{c.libelle}</option>)}
              </select>
              <select aria-label="Opérateur" disabled={champ === undefined} className={cn(CHAMP_SAISIE, 'w-full sm:w-36')} value={champ?.operateur ?? ''} onChange={() => undefined}>
                {champ === undefined ? <option value="">—</option> : <option value={champ.operateur}>{champ.operateur}</option>}
              </select>
              {champ?.options !== undefined ? (
                <select aria-label="Valeur" value={l.valeur} onChange={(e) => modifier(l.id, { valeur: e.target.value })} className={cn(CHAMP_SAISIE, 'w-full sm:min-w-56 sm:flex-1')}>
                  <option value="">Choisir une valeur</option>
                  {champ.options.map((o) => <option key={o.code} value={o.code}>{o.libelle}</option>)}
                </select>
              ) : (
                <input
                  type="text"
                  aria-label="Valeur"
                  disabled={champ === undefined}
                  value={l.valeur}
                  placeholder={champ?.code === 'tags' ? 'Ex : clients-2026' : champ !== undefined ? 'Ex : 69, 38' : ''}
                  onChange={(e) => modifier(l.id, { valeur: e.target.value })}
                  className={cn(CHAMP_SAISIE, 'w-full sm:min-w-56 sm:flex-1')}
                />
              )}
              <button
                type="button"
                aria-label="Retirer ce critère"
                onClick={() => retirer(l.id)}
                className="inline-flex h-11 w-11 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800 sm:h-9 sm:w-9"
              >
                <X className="h-4 w-4" aria-hidden="true" />
              </button>
            </div>
          );
        })}
      </div>

      <div className="flex flex-wrap gap-2">
        <Button type="button" variant="secondary" size="md" iconLeft={<Plus className="h-4 w-4" />} onClick={() => ajouter(['et'])}>+ Critère ET</Button>
        <Button type="button" variant="secondary" size="md" iconLeft={<Plus className="h-4 w-4" />} onClick={() => ajouter(nbOu === 0 ? ['ou', 'ou'] : ['ou'])}>+ Groupe OU</Button>
        <Button type="button" variant="secondary" size="md" iconLeft={<Plus className="h-4 w-4" />} onClick={() => ajouter(['sauf'])}>+ SAUF</Button>
      </div>

      <p role="status" aria-label="Critères en une phrase" className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
        {phrase === '' ? 'Aucun critère pour l’instant.' : phrase}
      </p>
    </section>
  );
}
