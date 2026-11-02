/** Étape 1 — Qui reçoit ? Liste, audience ou étiquette, exclusions et revérification. */
import { useMemo, useState } from 'react';
import { Button, Skeleton } from '@/components/ui';
import { Bloc, CHAMP, Jauge, Puces } from './composants';
import { useEstimation, type CibleProposee } from './api';
import { cibleDeContrat, type Saisie } from './saisie';
import { cn } from '@/components/ui';

type TypeAffiche = CibleProposee['type'];

const LIBELLE_TYPE: Record<TypeAffiche, string> = { etiquette: 'Étiquette', audience: 'Audience', liste: 'Liste', carnet: 'Entreprise' };

export function EtapeCible({
  saisie,
  modifier,
  cibles,
  brouillonId,
  suite,
}: {
  saisie: Saisie;
  modifier: (patch: Partial<Saisie>) => void;
  cibles: readonly CibleProposee[];
  brouillonId: string | null;
  suite: () => void;
}) {
  const types = useMemo(() => (['etiquette', 'audience', 'liste', 'carnet'] as const).filter((t) => cibles.some((c) => c.type === t)), [cibles]);
  const [typeChoisi, setTypeChoisi] = useState<TypeAffiche>(saisie.cible?.type ?? types[0] ?? 'etiquette');
  const visibles = cibles.filter((c) => c.type === typeChoisi);
  const estimation = useEstimation(brouillonId, cibleDeContrat(saisie));
  const e = estimation.data;
  const dOffice = e ? (e.exclusions['opposition'] ?? 0) + (e.exclusions['clients'] ?? 0) + (e.exclusions['adresses_partagees'] ?? 0) : 0;
  const saisies = e?.exclusions['saisies'] ?? 0;

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
              <span className="mt-1 block text-2xl font-semibold tracking-tight text-slate-900">{c.joignables.toLocaleString('fr-FR')}</span>
              <Jauge valeur={c.joignables} max={c.total} libelle={`Joignables : ${c.joignables} sur ${c.total}`} />
            </label>
          );
        })}
      </div>

      <div className="mt-4 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200" role="status" aria-live="polite">
        {saisie.cible === null ? (
          <p className="text-sm text-slate-600">Choisissez une cible pour voir combien d’adresses sont joignables.</p>
        ) : e === undefined ? (
          <Skeleton className="h-10 w-full" />
        ) : (
          <>
            <p className="text-sm text-slate-900"><strong>{dOffice.toLocaleString('fr-FR')} adresses retirées d’office</strong></p>
            <p className="mt-0.5 text-xs text-slate-600">
              Oppositions ({e.exclusions['opposition'] ?? 0}), clients et affaires en cours ({e.exclusions['clients'] ?? 0}), adresses partagées ({e.exclusions['adresses_partagees'] ?? 0}).
            </p>
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
          {e ? <span className="block text-xs text-slate-600">Environ {Math.round(e.restant * 0.12).toLocaleString('fr-FR')} adresses concernées.</span> : null}
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
