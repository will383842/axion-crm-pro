/** DM2-INF — « Ajouter une boîte » : test de connexion, signature, mode absence. */
import { useState } from 'react';
import { Check, X } from 'lucide-react';
import { Modal, Button } from '@/components/ui';
import { PLAFOND_PAR_BOITE, useAjouterBoite, useDomaines, useTesterConnexion, type ResultatTest } from './api';
import { BOUTON_TACTILE, ChampChoix, ChampTexte, CLASSE_CHAMP, MessageErreur } from './Formulaire';
import { messageErreur } from './format';

export function DialogueAjouterBoite({ ouvert, fermer }: { ouvert: boolean; fermer: () => void }) {
  const domaines = useDomaines();
  const ajouter = useAjouterBoite();
  const tester = useTesterConnexion();
  const [domaineChoisi, setDomaine] = useState('');
  const [adresse, setAdresse] = useState('');
  const [nom, setNom] = useState('');
  const [signature, setSignature] = useState('');
  const [absence, setAbsence] = useState(false);
  const [test, setTest] = useState<ResultatTest | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);

  const domaineId = domaineChoisi || domaines.data?.data[0]?.id || '';
  const nomDomaine = domaines.data?.data.find((d) => d.id === domaineId)?.nom ?? '';

  const lancerTest = async () => {
    setErreur(null);
    setTest(null);
    try {
      setTest(await tester.mutateAsync({ adresse: adresse.trim(), domaine_id: domaineId }));
    } catch (e) {
      setErreur(messageErreur(e));
    }
  };

  const enregistrer = async () => {
    setErreur(null);
    try {
      await ajouter.mutateAsync({
        adresse: adresse.trim(),
        domaine_id: domaineId,
        nom_affiche: nom.trim(),
        signature,
        mode_absence: { actif: absence, jusqu_au: null, message: null },
      });
      setAdresse('');
      setNom('');
      setSignature('');
      setAbsence(false);
      setTest(null);
      fermer();
    } catch (e) {
      setErreur(messageErreur(e));
    }
  };

  return (
    <Modal
      open={ouvert}
      onClose={fermer}
      title="Ajouter une boîte d’envoi"
      description={`La boîte démarre sa chauffe de 30 jours. Plafond : ${PLAFOND_PAR_BOITE} envois par jour et par boîte.`}
      footer={
        <>
          <Button variant="secondary" className={BOUTON_TACTILE} onClick={fermer}>Annuler</Button>
          <Button variant="secondary" className={BOUTON_TACTILE} loading={tester.isPending} onClick={() => void lancerTest()}>Tester la connexion</Button>
          <Button variant="primary" className={BOUTON_TACTILE} loading={ajouter.isPending} onClick={() => void enregistrer()}>Ajouter la boîte</Button>
        </>
      }
    >
      <div className="space-y-3">
        <ChampChoix label="Domaine" value={domaineId} onChange={(e) => setDomaine(e.target.value)}>
          {(domaines.data?.data ?? []).map((d) => <option key={d.id} value={d.id}>{d.nom}</option>)}
        </ChampChoix>
        <ChampTexte label="Adresse de la boîte" type="email" value={adresse} placeholder={nomDomaine ? `prenom@${nomDomaine}` : ''} onChange={(e) => setAdresse(e.target.value)} autoComplete="off" />
        <ChampTexte label="Nom affiché aux destinataires" value={nom} onChange={(e) => setNom(e.target.value)} autoComplete="off" />
        <div className="flex flex-col gap-1">
          <label htmlFor="boite-signature" className="text-xs font-medium text-slate-700">Signature</label>
          <textarea id="boite-signature" rows={3} className={`${CLASSE_CHAMP} h-auto py-2 md:h-auto`} value={signature} onChange={(e) => setSignature(e.target.value)} />
        </div>
        <label className="flex min-h-11 items-center gap-2 text-sm text-slate-800 md:min-h-0">
          <input type="checkbox" className="h-4 w-4" checked={absence} onChange={(e) => setAbsence(e.target.checked)} />
          Démarrer en mode absence (la boîte n’envoie pas)
        </label>
        {test ? (
          <ul className="space-y-1 rounded-lg bg-slate-50 p-3 text-sm" aria-label="Résultat du test de connexion">
            {test.etapes.map((e) => (
              <li key={e.libelle} className="flex items-center gap-2">
                {e.ok ? <Check className="h-4 w-4 text-emerald-600" aria-hidden /> : <X className="h-4 w-4 text-rose-600" aria-hidden />}
                <span>{e.libelle}</span>
                <span className="sr-only">{e.ok ? ' : réussi' : ' : à corriger'}</span>
              </li>
            ))}
          </ul>
        ) : null}
        {erreur ? <MessageErreur>{erreur}</MessageErreur> : null}
      </div>
    </Modal>
  );
}
