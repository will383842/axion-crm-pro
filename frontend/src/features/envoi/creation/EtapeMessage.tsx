/** Étape 2 — Quel message ? Séquence de 1 à 4 messages, objets A/B, champs et valeurs de repli. */
import { useState } from 'react';
import { Plus, Send, X } from 'lucide-react';
import { Button, Modal } from '@/components/ui';
import { Bloc, CHAMP, Interrupteur } from './composants';
import { useChamps, useMessagesTypes, envoyerTestCampagne, type MessageTypeEtat } from './api';
import { cibleDeContrat, sequenceDeContrat, type Saisie } from './saisie';

function versionUtilisee(m: MessageTypeEtat | undefined) {
  if (!m) return undefined;
  const numero = m.version_validee ?? m.version_courante;
  return m.versions.find((v) => v.numero === numero);
}

export function EtapeMessage({
  saisie,
  modifier,
  suite,
  retour,
  assurerBrouillon,
}: {
  saisie: Saisie;
  modifier: (patch: Partial<Saisie>) => void;
  suite: () => Promise<void>;
  retour: () => void;
  /** Enregistre le brouillon (au besoin) et rend son identifiant. */
  assurerBrouillon: () => Promise<string>;
}) {
  const messages = useMessagesTypes(false);
  const liste = messages.data?.data ?? [];
  const champs = useChamps(cibleDeContrat(saisie), sequenceDeContrat(saisie), saisie.repli);
  const [erreur, setErreur] = useState<string | null>(null);
  const [testOuvert, setTestOuvert] = useState(false);
  const [retourTest, setRetourTest] = useState<string | null>(null);
  const [envoiTest, setEnvoiTest] = useState(false);

  const premier = liste.find((m) => m.id === saisie.sequence[0]?.message_id);
  const objetA = versionUtilisee(premier)?.objet ?? '';

  const majLigne = (i: number, patch: Partial<Saisie['sequence'][number]>) => {
    modifier({ sequence: saisie.sequence.map((l, k) => (k === i ? { ...l, ...patch } : l)) });
  };

  const basculerAB = (actif: boolean) => {
    const propose = versionUtilisee(premier)?.objet_b ?? '';
    modifier({ ab: actif, ...(actif && saisie.objetB.trim() === '' ? { objetB: propose } : {}) });
  };

  const continuer = async () => {
    setErreur(null);
    const bloques = (champs.data?.champs ?? []).filter((c) => c.bloquant);
    if (bloques.length > 0) {
      const c = bloques[0]!;
      setErreur(`Champ obligatoire sans valeur : {${c.nom}} (${c.manquants.toLocaleString('fr-FR')} personnes). Indiquez une valeur de repli.`);
      return;
    }
    if (saisie.sequence.some((l) => !liste.find((m) => m.id === l.message_id && m.statut === 'valide'))) {
      setErreur('Chaque message de la séquence doit être un message validé.');
      return;
    }
    if (saisie.ab && saisie.objetB.trim() === '') {
      setErreur('Écrivez la version B de l’objet, ou désactivez le test A/B.');
      return;
    }
    try {
      await suite();
    } catch {
      setErreur('Le brouillon n’a pas pu être enregistré. Réessayez dans un instant.');
    }
  };

  const envoyerTest = async () => {
    setEnvoiTest(true);
    try {
      const id = await assurerBrouillon();
      setRetourTest(await envoyerTestCampagne(id, 1));
    } catch {
      setRetourTest('Le test n’a pas pu partir. Réessayez dans un instant.');
    } finally {
      setEnvoiTest(false);
      setTestOuvert(false);
    }
  };

  return (
    <Bloc titre="Quel message ?" mot="Étape 2 sur 5" etiquette="Étape 2">
      <h3 className="mb-2 text-sm font-semibold text-slate-900">
        {saisie.sequence.length === 1 ? '1 message' : `${saisie.sequence.length} messages, même fil`}
      </h3>
      <ol className="space-y-3">
        {saisie.sequence.map((ligne, i) => (
          <li key={i} className="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
            <div className="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
              <div>
                <label htmlFor={`message-${i + 1}`} className="mb-1 block text-xs font-medium text-slate-600">{`Message ${i + 1}`}</label>
                <select
                  id={`message-${i + 1}`}
                  className={CHAMP}
                  value={ligne.message_id}
                  onChange={(ev) => { majLigne(i, { message_id: ev.target.value }); }}
                >
                  {liste.map((m) => (
                    <option key={m.id} value={m.id} disabled={m.statut !== 'valide'}>
                      {m.statut === 'valide' ? `${m.nom} · v${m.version_validee ?? m.version_courante}` : `${m.nom} (pas encore validé)`}
                    </option>
                  ))}
                </select>
              </div>
              {i === 0 ? (
                <p className="pb-2 text-sm font-medium text-slate-700">Jour 0</p>
              ) : (
                <div>
                  <label htmlFor={`delai-${i + 1}`} className="mb-1 block text-xs font-medium text-slate-600">Délai en jours ouvrés</label>
                  <input
                    id={`delai-${i + 1}`}
                    type="number"
                    min={1}
                    max={30}
                    className={`${CHAMP} sm:w-28`}
                    value={ligne.delai_jours}
                    onChange={(ev) => { majLigne(i, { delai_jours: Math.max(1, Number(ev.target.value) || 1) }); }}
                  />
                </div>
              )}
              {i > 0 ? (
                <Button variant="ghost" aria-label={`Retirer la relance ${i}`} onClick={() => { modifier({ sequence: saisie.sequence.filter((_l, k) => k !== i) }); }}>
                  <X className="h-4 w-4" aria-hidden /> Retirer
                </Button>
              ) : null}
            </div>
            {i > 0 ? <p className="mt-2 text-xs text-slate-600">Part dans le même fil que le premier message (objet précédé de « Re : »).</p> : null}
          </li>
        ))}
      </ol>
      {saisie.sequence.length < 4 ? (
        <Button
          className="mt-3"
          variant="secondary"
          iconLeft={<Plus className="h-4 w-4" aria-hidden />}
          onClick={() => { modifier({ sequence: [...saisie.sequence, { message_id: liste.find((m) => m.statut === 'valide')?.id ?? 'msg-1', delai_jours: 4 }] }); }}
        >
          Ajouter une relance
        </Button>
      ) : null}

      <div className="mt-5 space-y-3">
        <Interrupteur
          id="ab"
          libelle="Tester deux objets (A/B)"
          aide="Sur 20 % de la cible ; la meilleure version part ensuite."
          coche={saisie.ab}
          onChange={basculerAB}
        />
        {saisie.ab ? (
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="rounded-xl bg-sky-50 p-3 ring-1 ring-sky-200">
              <p className="text-xs font-semibold text-slate-700">Objet · version A</p>
              <p className="mt-1 text-sm text-slate-900">{objetA}</p>
            </div>
            <div className="rounded-xl bg-violet-50 p-3 ring-1 ring-violet-200">
              <label htmlFor="objet-b" className="text-xs font-semibold text-slate-700">Objet · version B</label>
              <input id="objet-b" type="text" className={`${CHAMP} mt-1`} value={saisie.objetB} onChange={(ev) => { modifier({ objetB: ev.target.value }); }} />
            </div>
          </div>
        ) : null}
      </div>

      {champs.data && champs.data.champs.length > 0 ? (
        <div className="mt-5">
          <h3 className="mb-2 text-sm font-semibold text-slate-900">Champs utilisés dans vos messages</h3>
          <ul className="space-y-3">
            {champs.data.champs.map((c) => (
              <li key={c.nom} className="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
                <p className="text-sm text-slate-900">
                  <code className="font-mono text-xs">{`{${c.nom}}`}</code>
                  {c.obligatoire ? <span className="ml-2 text-xs font-medium text-slate-600">obligatoire</span> : null}
                </p>
                {c.manquants > 0 ? (
                  <>
                    <p className="mt-1 text-xs text-slate-600">
                      Vide pour {c.manquants.toLocaleString('fr-FR')} {c.manquants > 1 ? 'personnes' : 'personne'}
                      {c.obligatoire ? ' : une valeur de repli est nécessaire.' : ' : le message s’écrit sans ce mot.'}
                    </p>
                    <label htmlFor={`repli-${c.nom}`} className="mt-2 block text-xs font-medium text-slate-600">{`Valeur de repli : {${c.nom}}`}</label>
                    <input
                      id={`repli-${c.nom}`}
                      type="text"
                      className={`${CHAMP} mt-1`}
                      value={saisie.repli[c.nom] ?? ''}
                      onChange={(ev) => { modifier({ repli: { ...saisie.repli, [c.nom]: ev.target.value } }); }}
                    />
                  </>
                ) : (
                  <p className="mt-1 text-xs text-slate-600">Renseigné pour toutes les personnes.</p>
                )}
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      {erreur ? (
        <p role="alert" className="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200">{erreur}</p>
      ) : null}
      {retourTest ? <p role="status" className="mt-4 text-sm text-slate-700">{retourTest}</p> : null}

      <div className="mt-6 flex flex-wrap gap-2">
        <Button variant="primary" onClick={() => { void continuer(); }}>Continuer : quand</Button>
        <Button variant="secondary" iconLeft={<Send className="h-4 w-4" aria-hidden />} onClick={() => { setTestOuvert(true); }}>M’envoyer un test</Button>
        <Button variant="ghost" onClick={retour}>Retour</Button>
      </div>

      <Modal
        open={testOuvert}
        onClose={() => { setTestOuvert(false); }}
        title="M’envoyer un test"
        description="Le test part vers vos adresses de test seulement : aucune personne de la cible n’est contactée."
        footer={
          <>
            <Button variant="ghost" onClick={() => { setTestOuvert(false); }}>Annuler</Button>
            <Button variant="primary" loading={envoiTest} onClick={() => { void envoyerTest(); }}>Envoyer le test</Button>
          </>
        }
      />
    </Bloc>
  );
}
