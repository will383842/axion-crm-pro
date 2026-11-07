/** Registre des oppositions : liste, recherche, inscription. JAMAIS de retrait. */
import { useId, useState } from 'react';
import { Plus } from 'lucide-react';
import { Button, Modal, SearchInput } from '@/components/ui';
import { useInscrireOpposition, useRegistre, type Opposition } from './api';
import { Annonce, Bloc, CLASSE_BOUTON_MOBILE, CLASSE_CHAMP, Etat, Etiquette, messageDErreur } from './commun';
import { LIBELLE_PORTEE, LIBELLE_SOURCE, dateCourte } from './format';

export function BoutonInscrire() {
  const [ouvert, setOuvert] = useState(false);
  return (
    <>
      <Button variant="secondary" iconLeft={<Plus className="h-4 w-4" aria-hidden />} className={CLASSE_BOUTON_MOBILE} onClick={() => setOuvert(true)}>
        Ajouter au registre
      </Button>
      <FormulaireInscription ouvert={ouvert} onClose={() => setOuvert(false)} />
    </>
  );
}

function FormulaireInscription({ ouvert, onClose }: { ouvert: boolean; onClose: () => void }) {
  const idValeur = useId();
  const idPortee = useId();
  const idMotif = useId();
  const inscrire = useInscrireOpposition();
  const [valeur, setValeur] = useState('');
  const [portee, setPortee] = useState<Opposition['portee']>('adresse');
  const [motif, setMotif] = useState('');
  const [erreur, setErreur] = useState<string | null>(null);

  async function valider() {
    setErreur(null);
    try {
      await inscrire.mutateAsync({ portee, valeur, motif });
      setValeur('');
      setMotif('');
      onClose();
    } catch (e) {
      setErreur(messageDErreur(e, 'L’inscription a échoué. Réessayez.'));
    }
  }

  return (
    <Modal
      open={ouvert}
      onClose={onClose}
      title="Ajouter au registre « ne plus écrire »"
      description="La ligne ne pourra jamais être retirée : elle sert de preuve."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} className={CLASSE_BOUTON_MOBILE}>
            Annuler
          </Button>
          <Button onClick={() => void valider()} loading={inscrire.isPending} className={CLASSE_BOUTON_MOBILE}>
            Inscrire
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <div>
          <Etiquette htmlFor={idValeur}>Adresse ou domaine à inscrire</Etiquette>
          <input id={idValeur} type="text" value={valeur} onChange={(e) => setValeur(e.target.value)} className={CLASSE_CHAMP} autoComplete="off" />
        </div>
        <div>
          <Etiquette htmlFor={idPortee}>Portée</Etiquette>
          <select id={idPortee} value={portee} onChange={(e) => setPortee(e.target.value as Opposition['portee'])} className={CLASSE_CHAMP}>
            <option value="adresse">Adresse</option>
            <option value="domaine">Domaine entier</option>
            <option value="organisation">Toute l’organisation</option>
          </select>
        </div>
        <div>
          <Etiquette htmlFor={idMotif}>Motif</Etiquette>
          <input id={idMotif} type="text" value={motif} onChange={(e) => setMotif(e.target.value)} placeholder="Demande RGPD" className={CLASSE_CHAMP} />
        </div>
        <Annonce message={erreur} ton="erreur" />
      </div>
    </Modal>
  );
}

export function RegistreOpposition({ recherche, onRecherche }: { recherche: string; onRecherche: (q: string) => void }) {
  const requete = useRegistre(recherche);
  return (
    <Bloc titre="Registre des oppositions" droite={<SearchInput value={recherche} onChange={onRecherche} label="Rechercher dans le registre" />}>
      <Etat requete={requete} contexte="le registre des oppositions" vide={(d) => d.data.length === 0}>
        {(d) => (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Registre des oppositions, de la plus récente à la plus ancienne</caption>
              <thead className="text-xs text-slate-500">
                <tr>
                  <th scope="col" className="py-2 pr-3 font-medium">Adresse ou domaine</th>
                  <th scope="col" className="py-2 pr-3 font-medium">Quand</th>
                  <th scope="col" className="py-2 pr-3 font-medium">Comment</th>
                  <th scope="col" className="py-2 font-medium">Portée</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {d.data.map((o) => (
                  <tr key={o.id}>
                    <td className="max-w-[16rem] break-all py-2 pr-3 font-medium text-slate-900">{o.valeur}</td>
                    <td className="whitespace-nowrap py-2 pr-3 text-slate-600">{dateCourte(o.inscrite_le)}</td>
                    <td className="py-2 pr-3 text-slate-600">{LIBELLE_SOURCE[o.source]}</td>
                    <td className="py-2 text-slate-600">{LIBELLE_PORTEE[o.portee]}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Etat>
    </Bloc>
  );
}
