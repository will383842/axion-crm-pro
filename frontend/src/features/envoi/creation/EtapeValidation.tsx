/** Étape 5 — Valider et programmer : code reçu par e-mail, puis 5 minutes pour annuler. */
import { useEffect, useState } from 'react';
import { Link } from '@tanstack/react-router';
import { isAxiosError } from 'axios';
import { CheckCircle2, Undo2 } from 'lucide-react';
import { Button } from '@/components/ui';
import { Bloc, CHAMP } from './composants';
import { annulerValidation, demanderCode, validerCampagne } from './api';
import { dateLongue, pluriel } from './outils';
import type { Saisie } from './saisie';

type Phase =
  | { nom: 'code' }
  | { nom: 'valide'; campagneId: string; reste: number | null }
  | { nom: 'annule' };

const DUREE_ANNULATION = 300;

function dureeLisible(secondes: number): string {
  const m = Math.floor(secondes / 60);
  const s = secondes % 60;
  return `${m} min ${String(s).padStart(2, '0')} s`;
}

function messageDErreur(e: unknown, parDefaut: string): string {
  if (isAxiosError(e)) {
    const corps = e.response?.data as { message?: string } | undefined;
    if (corps?.message) return corps.message;
  }
  return parDefaut;
}

export function EtapeValidation({
  brouillonId,
  saisie,
  restant,
  campagneDejaValidee,
  retour,
  onChangement,
}: {
  brouillonId: string;
  saisie: Saisie;
  restant: number;
  /** Brouillon rouvert alors qu'il a déjà été validé. */
  campagneDejaValidee: string | null;
  retour: () => void;
  onChangement: () => void;
}) {
  const [phase, setPhase] = useState<Phase>(campagneDejaValidee ? { nom: 'valide', campagneId: campagneDejaValidee, reste: null } : { nom: 'code' });
  const [codeEnvoye, setCodeEnvoye] = useState<string | null>(null);
  const [code, setCode] = useState('');
  const [erreur, setErreur] = useState<string | null>(null);
  const [enCours, setEnCours] = useState(false);

  const reste = phase.nom === 'valide' ? phase.reste : null;
  const campagneId = phase.nom === 'valide' ? phase.campagneId : null;
  useEffect(() => {
    if (reste === null || reste <= 0) return;
    const minuterie = window.setTimeout(() => {
      setPhase((p) => (p.nom === 'valide' && p.reste !== null ? { ...p, reste: Math.max(0, p.reste - 1) } : p));
    }, 1000);
    return () => { window.clearTimeout(minuterie); };
  }, [reste]);

  const envoyerCode = async () => {
    setErreur(null);
    setEnCours(true);
    try {
      const r = await demanderCode(brouillonId);
      setCodeEnvoye(r.envoye_a);
    } catch (e) {
      setErreur(messageDErreur(e, 'Le code n’a pas pu être envoyé. Réessayez dans un instant.'));
    } finally {
      setEnCours(false);
    }
  };

  const valider = async () => {
    setErreur(null);
    setEnCours(true);
    try {
      const r = await validerCampagne(brouillonId, code.trim());
      setPhase({ nom: 'valide', campagneId: r.campagne.id, reste: DUREE_ANNULATION });
      setCode('');
      onChangement();
    } catch (e) {
      setErreur(messageDErreur(e, 'Le code n’a pas pu être vérifié. Réessayez dans un instant.'));
    } finally {
      setEnCours(false);
    }
  };

  const annuler = async () => {
    if (campagneId === null) return;
    setErreur(null);
    setEnCours(true);
    try {
      await annulerValidation(campagneId);
      setPhase({ nom: 'annule' });
      setCodeEnvoye(null);
      onChangement();
    } catch (e) {
      setErreur(messageDErreur(e, 'La validation n’a pas pu être annulée.'));
    } finally {
      setEnCours(false);
    }
  };

  const depart = saisie.depart === 'immediat' ? 'Dès la validation' : saisie.jour !== '' ? `${dateLongue(saisie.jour)} · ${saisie.heure}` : '—';

  return (
    <Bloc titre="Valider et programmer" mot="Étape 5 sur 5" etiquette="Étape 5">
      <dl className="grid grid-cols-2 gap-3 text-center sm:grid-cols-4">
        {[
          [restant.toLocaleString('fr-FR'), 'personnes'],
          [depart, 'départ'],
          [String(saisie.sequence.length), saisie.sequence.length > 1 ? 'messages' : 'message'],
          [saisie.ab ? 'A/B' : 'Un objet', saisie.ab ? '20 % de la cible' : 'sans test'],
        ].map(([valeur, nom]) => (
          <div key={nom} className="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
            <dd className="break-words text-sm font-semibold text-slate-900">{valeur}</dd>
            <dt className="text-xs text-slate-600">{nom}</dt>
          </div>
        ))}
      </dl>
      <p className="mt-3 text-xs text-slate-600">
        Ouvertures : {saisie.suivreOuvertures ? 'suivies, affichées comme une estimation' : 'non suivies'}.
      </p>

      {phase.nom === 'valide' ? (
        <div role="status" className="mt-5 rounded-xl bg-emerald-50 p-4 ring-1 ring-emerald-200">
          <p className="flex items-center gap-2 text-sm font-semibold text-slate-900">
            <CheckCircle2 className="h-5 w-5 text-emerald-600" aria-hidden /> Campagne programmée
          </p>
          <p className="mt-1 text-sm text-slate-700">
            {reste === null || reste > 0
              ? 'Rien ne part pendant les 5 minutes qui suivent : vous pouvez encore annuler.'
              : 'La fenêtre d’annulation est passée. Pour arrêter la campagne, ouvrez son suivi et mettez-la en pause.'}
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            {reste === null || reste > 0 ? (
              <Button variant="secondary" loading={enCours} iconLeft={<Undo2 className="h-4 w-4" aria-hidden />} onClick={() => { void annuler(); }}>
                {`Annuler la validation${reste !== null ? ` (${dureeLisible(reste)})` : ''}`}
              </Button>
            ) : null}
            <Link
              to="/envoi/campagnes/$campagneId"
              params={{ campagneId: phase.campagneId }}
              className="inline-flex h-11 items-center rounded-lg px-4 text-sm font-medium text-produit ring-1 ring-produit hover:bg-produit-pale md:h-9"
            >
              Voir la campagne
            </Link>
          </div>
        </div>
      ) : (
        <>
          {phase.nom === 'annule' ? (
            <p role="status" className="mt-5 rounded-xl bg-amber-50 p-3 text-sm text-slate-900 ring-1 ring-amber-200">
              Validation annulée : la campagne est remise en brouillon. Rien n’est parti.
            </p>
          ) : null}
          <fieldset className="mt-5">
            <legend className="mb-2 text-sm font-semibold text-slate-900">Code reçu par e-mail</legend>
            {codeEnvoye === null ? (
              <Button variant="secondary" loading={enCours} onClick={() => { void envoyerCode(); }}>Envoyer le code par e-mail</Button>
            ) : (
              <>
                <p role="status" className="mb-2 text-xs text-slate-600">
                  Code envoyé à {codeEnvoye}. En démonstration, il est affiché dans le bandeau en haut de la page.
                </p>
                <label htmlFor="code-validation" className="sr-only">Code reçu par e-mail</label>
                <input
                  id="code-validation"
                  type="text"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={6}
                  className={`${CHAMP} max-w-48 text-center font-mono text-lg tracking-[0.4em]`}
                  value={code}
                  onChange={(ev) => { setCode(ev.target.value.replace(/\D/g, '')); }}
                />
              </>
            )}
          </fieldset>
          {erreur ? <p role="alert" className="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200">{erreur}</p> : null}
          <p className="mt-3 text-xs text-slate-600">{pluriel(5, 'minute', 'minutes')} pour annuler ensuite.</p>
          <div className="mt-4 flex flex-wrap gap-2">
            <Button variant="primary" loading={enCours} disabled={codeEnvoye === null || code.length !== 6} onClick={() => { void valider(); }}>
              Valider et programmer
            </Button>
            <Button variant="ghost" onClick={retour}>Retour</Button>
          </div>
        </>
      )}
      {phase.nom === 'valide' && erreur ? <p role="alert" className="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200">{erreur}</p> : null}
    </Bloc>
  );
}
