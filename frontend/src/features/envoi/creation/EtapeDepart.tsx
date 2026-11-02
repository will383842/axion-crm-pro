/** Étape 3 — Quand et par où ? Départ, fenêtre d'envoi, boîtes et plafond. */
import { useEffect } from 'react';
import { Button, Skeleton } from '@/components/ui';
import { Bloc, CHAMP, Puces } from './composants';
import { useBoites, useContexte, type BoiteChoix } from './api';
import { ajouterJours, dateCourte, dateDeFin, FENETRES, jourDeParis, joursNecessaires, pluriel } from './outils';
import type { Saisie } from './saisie';

const MOT_STATUT: Record<BoiteChoix['statut'], string> = {
  prete: 'Prête',
  ralentie: 'Ralentie',
  en_chauffe: 'En chauffe',
  suspendue: 'Suspendue',
};

export function EtapeDepart({
  saisie,
  modifier,
  restant,
  suite,
  retour,
}: {
  saisie: Saisie;
  modifier: (patch: Partial<Saisie>) => void;
  restant: number;
  suite: () => void;
  retour: () => void;
}) {
  const boites = useBoites();
  const contexte = useContexte();
  const liste = boites.data ?? [];

  // Par défaut : les boîtes prêtes (tant que la personne n'a rien coché ni décoché).
  useEffect(() => {
    if (!saisie.boitesChoisies && boites.data) {
      const pretes = boites.data.filter((b) => b.statut === 'prete').map((b) => b.id);
      const capacite = boites.data.filter((b) => pretes.includes(b.id)).reduce((n, b) => n + b.quota_jour, 0);
      modifier({ boites: pretes, plafond: Math.max(1, Math.min(80, capacite)) });
    }
    // `modifier` est stable pour un rendu donné ; on ne réagit qu'à l'arrivée des boîtes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [boites.data]);

  const demain = (): string | null => (contexte.data ? ajouterJours(jourDeParis(new Date(contexte.data.maintenant)), 1) : null);
  const aujourdhui = contexte.data ? jourDeParis(new Date(contexte.data.maintenant)) : undefined;

  const bascule = (id: string, coche: boolean) => {
    const boitesNouvelles = coche ? [...saisie.boites, id] : saisie.boites.filter((x) => x !== id);
    const capacite = liste.filter((b) => boitesNouvelles.includes(b.id)).reduce((n, b) => n + b.quota_jour, 0);
    modifier({ boites: boitesNouvelles, boitesChoisies: true, plafond: capacite > 0 ? Math.min(saisie.plafond, capacite) || 1 : saisie.plafond });
  };

  const jours = joursNecessaires(restant, saisie.plafond);
  const depart = saisie.depart === 'immediat' ? (aujourdhui ?? '') : saisie.jour;
  const fin = depart !== '' && jours > 0 ? dateDeFin(depart, jours) : null;
  const pret = saisie.depart === 'immediat' || saisie.jour !== '';

  return (
    <Bloc titre="Quand et par où ?" mot="Étape 3 sur 5" etiquette="Étape 3">
      <Puces<Saisie['depart']>
        libelle="Départ"
        options={[
          { id: 'immediat', libelle: 'Dès la validation' },
          { id: 'programme', libelle: 'Programmer' },
        ]}
        valeur={saisie.depart}
        onChange={(depart) => { modifier({ depart }); }}
      />

      {saisie.depart === 'programme' ? (
        <div className="mt-4 grid gap-3 sm:grid-cols-2">
          <div>
            <label htmlFor="depart-jour" className="mb-1 block text-xs font-medium text-slate-600">Jour</label>
            <input
              id="depart-jour"
              type="date"
              className={CHAMP}
              value={saisie.jour}
              {...(aujourdhui ? { min: aujourdhui } : {})}
              onChange={(ev) => { modifier({ jour: ev.target.value }); }}
            />
          </div>
          <div>
            <label htmlFor="depart-heure" className="mb-1 block text-xs font-medium text-slate-600">Heure</label>
            <input
              id="depart-heure"
              type="time"
              className={CHAMP}
              value={saisie.heure}
              onChange={(ev) => { modifier({ heure: ev.target.value }); }}
            />
          </div>
          <div className="sm:col-span-2">
            <Button
              variant="secondary"
              disabled={demain() === null}
              onClick={() => {
                const d = demain();
                if (d !== null) modifier({ depart: 'programme', jour: d, heure: '09:00' });
              }}
            >
              Demain 9:00
            </Button>
          </div>
        </div>
      ) : (
        <p className="mt-3 text-sm text-slate-600">Le départ a lieu après la validation par code e-mail, quelques minutes plus tard (le temps d’annuler).</p>
      )}

      <div className="mt-4 grid gap-3 sm:grid-cols-2">
        <div>
          <label htmlFor="fenetre" className="mb-1 block text-xs font-medium text-slate-600">Fenêtre d’envoi</label>
          <select id="fenetre" className={CHAMP} value={saisie.fenetre} onChange={(ev) => { modifier({ fenetre: ev.target.value as Saisie['fenetre'] }); }}>
            {FENETRES.map((f) => <option key={f.id} value={f.id}>{f.libelle}</option>)}
          </select>
        </div>
        <div>
          <label htmlFor="plafond" className="mb-1 block text-xs font-medium text-slate-600">Plafond de cette campagne</label>
          <input
            id="plafond"
            type="number"
            min={1}
            className={CHAMP}
            value={saisie.plafond}
            aria-describedby="plafond-aide"
            onChange={(ev) => { modifier({ plafond: Math.max(1, Number(ev.target.value) || 1) }); }}
          />
          <span id="plafond-aide" className="mt-1 block text-xs text-slate-600">par jour, toutes boîtes</span>
        </div>
      </div>

      <h3 className="mb-2 mt-5 text-sm font-semibold text-slate-900">Boîtes d’envoi</h3>
      {boites.isPending ? (
        <Skeleton className="h-24 w-full" />
      ) : (
        <ul className="divide-y divide-slate-100 rounded-xl ring-1 ring-slate-200">
          {liste.map((b) => (
            <li key={b.id}>
              <label htmlFor={`boite-${b.id}`} className="flex min-h-11 cursor-pointer items-center gap-3 px-3 py-2">
                <input
                  id={`boite-${b.id}`}
                  type="checkbox"
                  className="h-4 w-4 accent-[var(--color-produit)]"
                  checked={saisie.boites.includes(b.id)}
                  onChange={(ev) => { bascule(b.id, ev.target.checked); }}
                />
                <span className="min-w-0 flex-1">
                  <strong className="block break-all text-sm text-slate-900">{b.adresse}</strong>
                  <span className="text-xs text-slate-600">{b.quota_jour} par jour</span>
                </span>
                <span className="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">{MOT_STATUT[b.statut]}</span>
              </label>
            </li>
          ))}
        </ul>
      )}

      <dl className="mt-4 grid grid-cols-3 gap-3 text-center">
        <div className="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
          <dd className="text-lg font-semibold text-slate-900">{saisie.plafond}</dd>
          <dt className="text-xs text-slate-600">par jour</dt>
        </div>
        <div className="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
          <dd className="text-lg font-semibold text-slate-900">{jours > 0 ? pluriel(jours, 'jour', 'jours') : '—'}</dd>
          <dt className="text-xs text-slate-600">pour tout envoyer</dt>
        </div>
        <div className="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
          <dd className="text-lg font-semibold text-slate-900">{fin ? dateCourte(fin) : '—'}</dd>
          <dt className="text-xs text-slate-600">fin prévue</dt>
        </div>
      </dl>

      <div className="mt-6 flex flex-wrap gap-2">
        <Button variant="primary" disabled={!pret} onClick={suite}>Continuer : vérification</Button>
        <Button variant="ghost" onClick={retour}>Retour</Button>
      </div>
    </Bloc>
  );
}
