/**
 * Règles et horaires (DM2-CNF) : des règles en phrases, DURCIR SEULEMENT.
 * Pression commerciale, frein « réponses en attente » (15 par jour), fenêtre
 * d'envoi, jours ouvrés. Maquette : `maquettes/regles-prudence.html`.
 */
import { useEffect, useId, useState } from 'react';
import { PageHeader, StatusPill } from '@/components/ui';
import { Button } from '@/components/ui';
import { useEnregistrerRegles, useRegles } from '@/features/envoi/conformite/composants/api';
import { Annonce, Bloc, CLASSE_BOUTON_MOBILE, CLASSE_CHAMP, Etat, Etiquette, messageDErreur } from '@/features/envoi/conformite/composants/commun';
import { verifierDurcissement, type ReglesPrudence } from '@/features/envoi/conformite/composants/regles';
import { OngletsReglages } from './OngletsReglages';

type Nombre = 'pause_si_refus_pct' | 'pause_si_plaintes_pour_mille' | 'frein_reponses_par_jour' | 'pression_max_par_organisation_30j';

function Formulaire({ actuel }: { actuel: ReglesPrudence }) {
  const id = useId();
  const [brouillon, setBrouillon] = useState<ReglesPrudence>(actuel);
  const [message, setMessage] = useState<{ texte: string; ton: 'ok' | 'erreur' } | null>(null);
  const enregistrer = useEnregistrerRegles();
  useEffect(() => setBrouillon(actuel), [actuel]);

  const refus = verifierDurcissement(actuel, brouillon);
  const modifie = JSON.stringify(actuel) !== JSON.stringify(brouillon);

  const nombre = (cle: Nombre, etiquette: string, unite: string, pas = 1) => (
    <div>
      <Etiquette htmlFor={`${id}-${cle}`}>{etiquette}</Etiquette>
      <div className="flex items-center gap-2">
        <input
          id={`${id}-${cle}`}
          type="number"
          inputMode="decimal"
          min={0}
          step={pas}
          value={brouillon[cle]}
          onChange={(e) => setBrouillon((b) => ({ ...b, [cle]: e.target.value === '' ? 0 : Number(e.target.value) }))}
          className={`${CLASSE_CHAMP} max-w-28`}
        />
        <span className="text-sm text-slate-600">{unite}</span>
      </div>
      <p className="mt-1 text-xs text-slate-500">Valeur actuelle : {actuel[cle]} (durcissable, jamais assouplie).</p>
    </div>
  );

  async function valider() {
    setMessage(null);
    try {
      await enregistrer.mutateAsync(brouillon);
      setMessage({ texte: 'Règles enregistrées.', ton: 'ok' });
    } catch (e) {
      setMessage({ texte: messageDErreur(e, 'L’enregistrement a échoué. Réessayez.'), ton: 'erreur' });
    }
  }

  return (
    <div className="space-y-4">
      <Bloc titre="Règles de prudence" droite={<StatusPill tone="success">3 actives</StatusPill>}>
        <div className="grid gap-4 sm:grid-cols-2">
          {nombre('pause_si_refus_pct', 'Pause de la boîte si plus de … % d’adresses refusées', '%', 0.5)}
          {nombre('pause_si_plaintes_pour_mille', 'Pause de la campagne si plus de … plainte(s) pour 1 000 messages', 'pour 1 000', 0.5)}
          {nombre('frein_reponses_par_jour', 'Frein « réponses en attente » : au plus … réponses à traiter par jour avant de ralentir', 'par jour')}
          {nombre('pression_max_par_organisation_30j', 'Pression commerciale : au plus … messages par organisation sur 30 jours', 'messages')}
        </div>
        <p className="mt-3 text-sm text-slate-700">Si le test du matin échoue : rien ne part. Cette règle est toujours active.</p>
      </Bloc>

      <Bloc titre="Fenêtre d’envoi">
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <Etiquette htmlFor={`${id}-de`}>De</Etiquette>
            <input id={`${id}-de`} type="time" value={brouillon.heure_de} onChange={(e) => setBrouillon((b) => ({ ...b, heure_de: e.target.value }))} className={`${CLASSE_CHAMP} max-w-40`} />
          </div>
          <div>
            <Etiquette htmlFor={`${id}-a`}>À</Etiquette>
            <input id={`${id}-a`} type="time" value={brouillon.heure_a} onChange={(e) => setBrouillon((b) => ({ ...b, heure_a: e.target.value }))} className={`${CLASSE_CHAMP} max-w-40`} />
          </div>
        </div>
        <label className="mt-3 flex min-h-9 items-start gap-2 text-sm text-slate-800 max-md:min-h-11">
          <input
            type="checkbox"
            checked={brouillon.jours_ouvres_seulement}
            disabled={actuel.jours_ouvres_seulement}
            onChange={(e) => setBrouillon((b) => ({ ...b, jours_ouvres_seulement: e.target.checked }))}
            className="mt-0.5 h-4 w-4"
          />
          <span>
            Jours ouvrés seulement
            <span className="block text-xs text-slate-500">Pas de week-end ni de jour férié français. Une fois activé, cela ne peut plus être rouvert.</span>
          </span>
        </label>
      </Bloc>

      {refus.length > 0 ? (
        <div role="alert" className="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
          <p className="font-semibold">Vous ne pouvez que durcir les règles.</p>
          <ul className="mt-1 list-disc pl-5">
            {refus.map((r) => (
              <li key={r}>{r}</li>
            ))}
          </ul>
        </div>
      ) : null}

      <div>
        <Button disabled={refus.length > 0 || !modifie} loading={enregistrer.isPending} className={CLASSE_BOUTON_MOBILE} onClick={() => void valider()}>
          Enregistrer
        </Button>
        <Annonce message={message?.texte ?? null} ton={message?.ton ?? 'ok'} />
      </div>
    </div>
  );
}

export function ReglesPrudencePage() {
  const requete = useRegles();
  return (
    <div>
      <PageHeader title="Règles et horaires" surTitre="Envoi" subtitle="Des règles en phrases. Durcir seulement." />
      <OngletsReglages />
      <Etat requete={requete} contexte="les règles de prudence">
        {(d) => <Formulaire actuel={d} />}
      </Etat>
    </div>
  );
}
