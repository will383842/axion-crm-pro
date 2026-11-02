/** DM2-INF — « Ajouter un domaine » : rend les enregistrements DNS à créer. */
import { useState } from 'react';
import { Modal, Button } from '@/components/ui';
import { useAjouterDomaine, type EnregistrementDns } from './api';
import { BOUTON_TACTILE, ChampTexte, MessageErreur } from './Formulaire';
import { messageErreur } from './format';
import { TableauDns } from './BlocsDomaine';

export function DialogueAjouterDomaine({ ouvert, fermer }: { ouvert: boolean; fermer: () => void }) {
  const ajouter = useAjouterDomaine();
  const [nom, setNom] = useState('');
  const [dns, setDns] = useState<EnregistrementDns[] | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);

  const fermerTout = () => {
    setNom('');
    setDns(null);
    setErreur(null);
    fermer();
  };

  const envoyer = async () => {
    setErreur(null);
    try {
      const r = await ajouter.mutateAsync(nom.trim());
      setDns(r.dns);
    } catch (e) {
      setErreur(messageErreur(e));
    }
  };

  return (
    <Modal
      open={ouvert}
      onClose={fermerTout}
      size="lg"
      title={dns ? 'Domaine ajouté : enregistrements à créer' : 'Ajouter un domaine'}
      description={dns ? 'Créez ces enregistrements chez votre hébergeur de domaine, puis ouvrez la fiche du domaine pour les vérifier.' : 'Le domaine servira à écrire en votre nom. Nous vous disons ensuite quoi créer côté DNS.'}
      footer={
        dns ? (
          <Button variant="primary" className={BOUTON_TACTILE} onClick={fermerTout}>Terminer</Button>
        ) : (
          <>
            <Button variant="secondary" className={BOUTON_TACTILE} onClick={fermerTout}>Annuler</Button>
            <Button variant="primary" className={BOUTON_TACTILE} loading={ajouter.isPending} onClick={() => void envoyer()}>Ajouter le domaine</Button>
          </>
        )
      }
    >
      {dns ? (
        <TableauDns dns={dns} />
      ) : (
        <div className="space-y-3">
          <ChampTexte label="Nom du domaine" value={nom} placeholder="prospection-e.example" onChange={(e) => setNom(e.target.value)} autoComplete="off" aide="En démonstration, les domaines finissent par « .example »." />
          {erreur ? <MessageErreur>{erreur}</MessageErreur> : null}
        </div>
      )}
    </Modal>
  );
}
