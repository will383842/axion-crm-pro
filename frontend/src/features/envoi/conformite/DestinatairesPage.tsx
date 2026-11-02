/**
 * Destinataires (DM2-CNF) : le carnet d'Envoi sans CRM (offre « Envoi seul »).
 * Le registre « ne plus écrire » s'applique avant chaque envoi, carnet ou non.
 */
import { useId, useState } from 'react';
import { Link } from '@tanstack/react-router';
import { Plus } from 'lucide-react';
import { Button, Modal, PageHeader, SearchInput } from '@/components/ui';
import { OngletsCampagnes } from '@/features/envoi/campagnes/OngletsCampagnes';
import { useAjouterAuCarnet, useCarnet } from './composants/api';
import { Annonce, Bloc, CLASSE_BOUTON_MOBILE, CLASSE_CHAMP, Etat, Etiquette, messageDErreur } from './composants/commun';
import { dateCourte } from './composants/format';

function Ajout({ ouvert, onClose }: { ouvert: boolean; onClose: () => void }) {
  const idAdresse = useId();
  const idPrenom = useId();
  const idNom = useId();
  const idOrg = useId();
  const ajouter = useAjouterAuCarnet();
  const [champs, setChamps] = useState({ adresse: '', prenom: '', nom: '', organisation: '' });
  const [erreur, setErreur] = useState<string | null>(null);
  const maj = (cle: keyof typeof champs) => (e: { target: { value: string } }) => setChamps((c) => ({ ...c, [cle]: e.target.value }));

  async function valider() {
    setErreur(null);
    try {
      await ajouter.mutateAsync(champs);
      setChamps({ adresse: '', prenom: '', nom: '', organisation: '' });
      onClose();
    } catch (e) {
      setErreur(messageDErreur(e, 'L’ajout a échoué. Réessayez.'));
    }
  }

  return (
    <Modal
      open={ouvert}
      onClose={onClose}
      title="Ajouter un destinataire"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} className={CLASSE_BOUTON_MOBILE}>Annuler</Button>
          <Button onClick={() => void valider()} loading={ajouter.isPending} className={CLASSE_BOUTON_MOBILE}>Ajouter</Button>
        </>
      }
    >
      <div className="space-y-3">
        <div><Etiquette htmlFor={idAdresse}>Adresse e-mail</Etiquette><input id={idAdresse} type="email" value={champs.adresse} onChange={maj('adresse')} className={CLASSE_CHAMP} /></div>
        <div className="grid grid-cols-2 gap-3">
          <div><Etiquette htmlFor={idPrenom}>Prénom</Etiquette><input id={idPrenom} value={champs.prenom} onChange={maj('prenom')} className={CLASSE_CHAMP} /></div>
          <div><Etiquette htmlFor={idNom}>Nom</Etiquette><input id={idNom} value={champs.nom} onChange={maj('nom')} className={CLASSE_CHAMP} /></div>
        </div>
        <div><Etiquette htmlFor={idOrg}>Organisation</Etiquette><input id={idOrg} value={champs.organisation} onChange={maj('organisation')} className={CLASSE_CHAMP} /></div>
        <Annonce message={erreur} ton="erreur" />
      </div>
    </Modal>
  );
}

export function DestinatairesPage() {
  const [recherche, setRecherche] = useState('');
  const [ouvert, setOuvert] = useState(false);
  const requete = useCarnet(recherche);
  return (
    <div>
      <PageHeader
        title="Destinataires"
        surTitre="Envoi"
        subtitle="Votre carnet d’adresses d’envoi, sans CRM."
        actions={
          <Button iconLeft={<Plus className="h-4 w-4" aria-hidden />} className={CLASSE_BOUTON_MOBILE} onClick={() => setOuvert(true)}>
            Ajouter un destinataire
          </Button>
        }
      />
      <OngletsCampagnes />
      <Bloc titre="Carnet" droite={<SearchInput value={recherche} onChange={setRecherche} label="Rechercher un destinataire" />}>
        <Etat requete={requete} contexte="le carnet de destinataires" vide={(d) => d.data.length === 0}>
          {(d) => (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <caption className="sr-only">Carnet de destinataires</caption>
                <thead className="text-xs text-slate-500">
                  <tr>
                    <th scope="col" className="py-2 pr-3 font-medium">Adresse</th>
                    <th scope="col" className="py-2 pr-3 font-medium">Nom</th>
                    <th scope="col" className="py-2 pr-3 font-medium">Organisation</th>
                    <th scope="col" className="py-2 font-medium">Ajouté le</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {d.data.map((e) => (
                    <tr key={e.id}>
                      <td className="max-w-[16rem] break-all py-2 pr-3 font-medium text-slate-900">{e.adresse}</td>
                      <td className="py-2 pr-3 text-slate-600">{[e.prenom, e.nom].filter(Boolean).join(' ') || '—'}</td>
                      <td className="py-2 pr-3 text-slate-600">{e.organisation ?? '—'}</td>
                      <td className="whitespace-nowrap py-2 text-slate-600">{dateCourte(e.ajoute_le)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Etat>
      </Bloc>
      <p className="mt-3 text-xs text-slate-500">
        Le registre « ne plus écrire » s’applique avant chaque envoi.{' '}
        <Link to="/envoi/desinscriptions" className="font-medium text-produit underline-offset-2 hover:underline">Voir les désinscriptions</Link>
      </p>
      <Ajout ouvert={ouvert} onClose={() => setOuvert(false)} />
    </div>
  );
}
