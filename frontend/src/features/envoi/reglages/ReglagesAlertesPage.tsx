/**
 * Alertes et avis (DM2-CNF) : type × canal, CONSOLE et E-MAIL seulement,
 * alerte de test, seconde adresse, regroupement 15 minutes.
 * Maquette : `maquettes/reglages-alertes.html`.
 */
import { useEffect, useId, useState } from 'react';
import { Send } from 'lucide-react';
import { Button, PageHeader } from '@/components/ui';
import { useAlerteDeTest, useEnregistrerReglesAlertes, useReglesAlertes, type ReglagesAlertes } from '@/features/envoi/conformite/composants/api';
import { Annonce, Bloc, CLASSE_BOUTON_MOBILE, CLASSE_CHAMP, Etat, Etiquette, messageDErreur } from '@/features/envoi/conformite/composants/commun';
import { OngletsReglages } from './OngletsReglages';

const CANAUX = [
  { cle: 'console', libelle: 'Console' },
  { cle: 'email', libelle: 'E-mail' },
] as const;

function Formulaire({ initial }: { initial: ReglagesAlertes }) {
  const idAdresse = useId();
  const [brouillon, setBrouillon] = useState(initial);
  const [message, setMessage] = useState<{ texte: string; ton: 'ok' | 'erreur' } | null>(null);
  const enregistrer = useEnregistrerReglesAlertes();
  const test = useAlerteDeTest();
  useEffect(() => setBrouillon(initial), [initial]);

  function basculer(id: string, canal: 'console' | 'email') {
    setBrouillon((b) => ({
      ...b,
      data: b.data.map((r) => (r.id !== id ? r : { ...r, canaux: r.canaux.includes(canal) ? r.canaux.filter((c) => c !== canal) : [...r.canaux, canal] })),
    }));
  }

  async function valider() {
    setMessage(null);
    try {
      await enregistrer.mutateAsync(brouillon);
      setMessage({ texte: 'Réglages enregistrés.', ton: 'ok' });
    } catch (e) {
      setMessage({ texte: messageDErreur(e, 'L’enregistrement a échoué. Réessayez.'), ton: 'erreur' });
    }
  }

  async function essayer() {
    setMessage(null);
    try {
      await test.mutateAsync();
      setMessage({ texte: 'Alerte de test envoyée : regardez la cloche de la console, puis votre boîte e-mail.', ton: 'ok' });
    } catch (e) {
      setMessage({ texte: messageDErreur(e, 'L’alerte de test n’a pas pu partir.'), ton: 'erreur' });
    }
  }

  return (
    <div className="space-y-4">
      <Bloc titre="Type × canal">
        <div className="overflow-x-auto">
          <table aria-label="Alertes : type et canal" className="w-full text-left text-sm">
            <thead className="text-xs text-slate-500">
              <tr>
                <th scope="col" className="py-2 pr-3 font-medium">Alerte</th>
                {CANAUX.map((c) => (
                  <th key={c.cle} scope="col" className="w-24 py-2 text-center font-medium">{c.libelle}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {brouillon.data.map((r) => (
                <tr key={r.id}>
                  <th scope="row" className="py-2 pr-3 font-medium text-slate-900">{r.nom}</th>
                  {CANAUX.map((c) => (
                    <td key={c.cle} className="py-1 text-center">
                      <label className="inline-flex h-9 w-11 cursor-pointer items-center justify-center max-md:h-11">
                        <input
                          type="checkbox"
                          aria-label={`${r.nom} : ${c.libelle}`}
                          checked={r.canaux.includes(c.cle)}
                          onChange={() => basculer(r.id, c.cle)}
                          className="h-4 w-4 accent-[var(--color-produit,#0b7285)]"
                        />
                      </label>
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="mt-2 text-xs text-slate-500">Les alertes arrivent dans la cloche de la console et par e-mail. Il n’y a pas d’autre canal.</p>
      </Bloc>

      <Bloc titre="Seconde adresse et regroupement">
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Etiquette htmlFor={idAdresse}>Seconde adresse</Etiquette>
            <input
              id={idAdresse}
              type="email"
              value={brouillon.seconde_adresse ?? ''}
              onChange={(e) => setBrouillon((b) => ({ ...b, seconde_adresse: e.target.value === '' ? null : e.target.value }))}
              placeholder="relais@example.test"
              className={CLASSE_CHAMP}
            />
          </div>
          <p className="self-end text-sm text-slate-600">Les alertes de même type sont regroupées sur {brouillon.regroupement_minutes} minutes.</p>
        </div>
      </Bloc>

      <Bloc titre="Alerte de test">
        <p className="mb-3 text-sm text-slate-600">Vérifiez que vous recevez bien les alertes : un message d’essai part dans la cloche et par e-mail.</p>
        <Button variant="secondary" iconLeft={<Send className="h-4 w-4" aria-hidden />} loading={test.isPending} className={CLASSE_BOUTON_MOBILE} onClick={() => void essayer()}>
          Envoyer une alerte de test
        </Button>
      </Bloc>

      <div>
        <Button loading={enregistrer.isPending} className={CLASSE_BOUTON_MOBILE} onClick={() => void valider()}>
          Enregistrer
        </Button>
        <Annonce message={message?.texte ?? null} ton={message?.ton ?? 'ok'} />
      </div>
    </div>
  );
}

export function ReglagesAlertesPage() {
  const requete = useReglesAlertes();
  return (
    <div>
      <PageHeader title="Alertes et avis" surTitre="Envoi" subtitle="Console et e-mail seulement." />
      <OngletsReglages />
      <Etat requete={requete} contexte="les règles d’alerte" vide={(d) => d.data.length === 0}>
        {(d) => <Formulaire initial={d} />}
      </Etat>
    </div>
  );
}
