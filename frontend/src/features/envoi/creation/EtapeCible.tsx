/** Étape 1 — Qui reçoit ? Liste, audience ou étiquette, exclusions et revérification. */
import { useMemo, useState } from 'react';
import { Button, Skeleton } from '@/components/ui';
import { Bloc, CHAMP, Puces } from './composants';
import { useEstimation, type CibleProposee } from './api';
import { cibleDeContrat, type Saisie } from './saisie';
import { FiltreReseau, MENTION_ESTIMATION } from './FiltreReseau';
import { cn } from '@/components/ui';

type TypeAffiche = CibleProposee['type'];

const LIBELLE_TYPE: Record<TypeAffiche, string> = { etiquette: 'Étiquette', audience: 'Audience', liste: 'Liste', carnet: 'Entreprise' };

export function EtapeCible({
  saisie,
  modifier,
  cibles,
  brouillonId,
  suite,
  rechoisir = false,
}: {
  saisie: Saisie;
  modifier: (patch: Partial<Saisie>) => void;
  cibles: readonly CibleProposee[];
  brouillonId: string | null;
  suite: () => void;
  /** Brouillon rouvert sans cible (copie sans ses destinataires) : on invite à la choisir de nouveau. */
  rechoisir?: boolean;
}) {
  const types = useMemo(() => (['etiquette', 'audience', 'liste', 'carnet'] as const).filter((t) => cibles.some((c) => c.type === t)), [cibles]);
  const [typeChoisi, setTypeChoisi] = useState<TypeAffiche>(saisie.cible?.type ?? types[0] ?? 'etiquette');
  const visibles = cibles.filter((c) => c.type === typeChoisi);
  const estimation = useEstimation(brouillonId, cibleDeContrat(saisie));
  const e = estimation.data;
  const dOffice = e ? (e.exclusions['opposition'] ?? 0) + (e.exclusions['clients'] ?? 0) + (e.exclusions['adresses_partagees'] ?? 0) : 0;
  const saisies = e?.exclusions['saisies'] ?? 0;
  const evaluees = e?.exclusions_evaluees !== false;

  return (
    <Bloc titre="Qui reçoit ?" mot="Étape 1 sur 5" etiquette="Étape 1">
      <div className="mb-4">
        <label htmlFor="nom-campagne" className="mb-1 block text-xs font-medium text-slate-600">Nom de la campagne</label>
        <input
          id="nom-campagne"
          type="text"
          className={CHAMP}
          value={saisie.nom}
          maxLength={120}
          onChange={(ev) => { modifier({ nom: ev.target.value, nomModifie: true }); }}
        />
      </div>

      <Puces<TypeAffiche>
        libelle="Type de cible"
        options={types.map((t) => ({ id: t, libelle: LIBELLE_TYPE[t] }))}
        valeur={typeChoisi}
        onChange={setTypeChoisi}
      />

      <div role="radiogroup" aria-label="Cible" className="mt-4 grid gap-3 sm:grid-cols-2">
        {visibles.map((c) => {
          const choisie = saisie.cible?.type === c.type && saisie.cible.reference === c.reference;
          return (
            <label
              key={`${c.type}:${c.reference}`}
              className={cn(
                'block min-h-11 cursor-pointer rounded-xl p-3 ring-1 transition',
                choisie ? 'bg-produit-pale ring-2 ring-produit' : 'bg-white ring-slate-200 hover:bg-slate-50',
              )}
            >
              <span className="flex items-center gap-2">
                <input
                  type="radio"
                  name="cible"
                  className="h-4 w-4 accent-[var(--color-produit)]"
                  checked={choisie}
                  onChange={() => {
                    modifier({
                      cible: { type: c.type, reference: c.reference, libelle: c.libelle },
                      ...(saisie.nomModifie ? {} : { nom: `Campagne ${c.libelle}` }),
                    });
                  }}
                />
                <strong className="min-w-0 break-words text-sm text-slate-900">{c.libelle}</strong>
              </span>
              <span className="mt-1 block text-2xl font-semibold tracking-tight text-slate-900">{c.total.toLocaleString('fr-FR')} <span className="text-sm font-normal text-slate-600">{c.total > 1 ? 'membres' : 'membre'}</span></span>
            </label>
          );
        })}
      </div>

      <FiltreReseau
        reseaux={saisie.reseaux}
        modifier={(reseaux) => { modifier({ reseaux, reseauxModifies: true }); }}
        mots={saisie.mots}
        guides={saisie.guides}
        modifierContenu={(patch) => { modifier({ ...patch, reseauxModifies: true }); }}
      />

      <div className="mt-4 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200" role="status" aria-live="polite">
        {saisie.cible === null ? (
          <p className="text-sm text-slate-600">
            {rechoisir ? <strong className="block text-slate-900">Choisissez de nouveau les destinataires.</strong> : null}
            Choisissez une cible pour voir combien d’adresses sont joignables.
          </p>
        ) : e === undefined ? (
          <Skeleton className="h-10 w-full" />
        ) : (
          <>
            <p className="text-sm text-slate-900" data-testid="joignables-estimes">
              {!evaluees ? (
                <>Joignables non évalués : les exclusions ne sont pas encore calculées.</>
              ) : e.complet === false ? (
                <>Joignables : <strong>au moins {e.restant.toLocaleString('fr-FR')} sur {e.total.toLocaleString('fr-FR')} examinés</strong></>
              ) : (
                <>Joignables : <strong>{e.restant.toLocaleString('fr-FR')}</strong></>
              )}
            </p>
            {!evaluees ? null : <p className="mt-1 text-sm text-slate-900"><strong>{dOffice.toLocaleString('fr-FR')} adresses retirées d’office</strong></p>}
            {!evaluees ? null : (
              <p className="mt-0.5 text-xs text-slate-600">
                Oppositions ({e.exclusions['opposition'] ?? 0}), clients et affaires en cours ({e.exclusions['clients'] ?? 0}), adresses partagées ({e.exclusions['adresses_partagees'] ?? 0}).
              </p>
            )}
            {saisie.reseaux.length > 0 ? <p className="mt-1 text-xs text-slate-600">{MENTION_ESTIMATION}</p> : null}
            {saisies > 0 ? <p className="mt-1 text-xs text-slate-600">{saisies.toLocaleString('fr-FR')} de plus selon vos exclusions.</p> : null}
          </>
        )}
      </div>

      <div className="mt-4">
        <label htmlFor="exclusions-libres" className="mb-1 block text-xs font-medium text-slate-600">Exclure aussi (facultatif)</label>
        <input
          id="exclusions-libres"
          type="text"
          className={CHAMP}
          value={saisie.exclusionsLibres}
          aria-describedby="exclusions-libres-aide"
          onChange={(ev) => { modifier({ exclusionsLibres: ev.target.value }); }}
        />
        <span id="exclusions-libres-aide" className="mt-1 block text-xs text-slate-600">Adresse, organisation ou domaine, séparés par une virgule.</span>
      </div>

      <label className="mt-4 flex min-h-11 items-start gap-2 text-sm text-slate-900">
        <input
          type="checkbox"
          className="mt-0.5 h-4 w-4 accent-[var(--color-produit)]"
          checked={saisie.reverifier}
          onChange={(ev) => { modifier({ reverifier: ev.target.checked }); }}
        />
        <span>
          Revérifier les adresses vues il y a plus de 30 jours avant l’envoi
        </span>
      </label>

      <div className="mt-6 flex flex-wrap gap-2">
        <Button variant="primary" disabled={saisie.cible === null} onClick={suite}>
          Continuer : message
        </Button>
      </div>
    </Bloc>
  );
}
