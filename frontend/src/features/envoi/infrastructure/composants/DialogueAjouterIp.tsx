/**
 * DM2-INF — « Ajouter une adresse IP » : trois clics depuis Ctrl K (la
 * palette, la ligne « Ajouter une adresse IP », puis « Ajouter et appliquer »).
 * L'adresse proposée est la prochaine adresse libre des plages de documentation.
 */
import { useState } from 'react';
import { Modal, Button } from '@/components/ui';
import { useAjouterIp, useAppliquerConfiguration, useDomaines, useServeurs, type FluxEnvoi } from './api';
import { BOUTON_TACTILE, ChampChoix, ChampTexte, MessageErreur } from './Formulaire';
import { messageErreur } from './format';

export function prochaineAdresseLibre(utilisees: readonly string[]): string {
  for (let n = 10; n < 250; n += 1) {
    const candidate = `192.0.2.${n}`;
    if (!utilisees.includes(candidate)) return candidate;
  }
  return '192.0.2.250';
}

export function DialogueAjouterIp({ ouvert, fermer }: { ouvert: boolean; fermer: () => void }) {
  const serveurs = useServeurs();
  const domaines = useDomaines();
  const ajouter = useAjouterIp();
  const appliquer = useAppliquerConfiguration();
  const [serveurChoisi, setServeur] = useState('');
  const [adresseSaisie, setAdresse] = useState<string | null>(null);
  const [flux, setFlux] = useState<FluxEnvoi>('prospection');
  const [domaineChoisi, setDomaine] = useState('');
  const [erreur, setErreur] = useState<string | null>(null);

  const liste = serveurs.data ?? [];
  const serveurId = serveurChoisi || liste[0]?.id || '';
  const domaineId = domaineChoisi || domaines.data?.data[0]?.id || '';
  const adresse = adresseSaisie ?? prochaineAdresseLibre(liste.flatMap((s) => s.adresses_ip.map((i) => i.adresse)));
  const occupe = ajouter.isPending || appliquer.isPending;

  const envoyer = async (avecApplication: boolean) => {
    setErreur(null);
    try {
      await ajouter.mutateAsync({ serveur_id: serveurId, adresse: adresse.trim(), flux, domaine_id: domaineId });
      if (avecApplication) await appliquer.mutateAsync();
      setAdresse(null);
      fermer();
    } catch (e) {
      setErreur(messageErreur(e));
    }
  };

  return (
    <Modal
      open={ouvert}
      onClose={fermer}
      title="Ajouter une adresse IP"
      description="L’adresse rejoint une nouvelle version de la configuration. Vous la voyez avant de l’appliquer."
      footer={
        <>
          <Button variant="secondary" className={BOUTON_TACTILE} onClick={fermer}>Annuler</Button>
          <Button variant="secondary" className={BOUTON_TACTILE} disabled={occupe} onClick={() => void envoyer(false)}>Ajouter</Button>
          <Button variant="primary" className={BOUTON_TACTILE} loading={occupe} onClick={() => void envoyer(true)}>Ajouter et appliquer</Button>
        </>
      }
    >
      <div className="space-y-3">
        <ChampChoix label="Serveur" value={serveurId} onChange={(e) => setServeur(e.target.value)}>
          {liste.map((s) => <option key={s.id} value={s.id}>{s.nom}</option>)}
        </ChampChoix>
        <ChampTexte label="Adresse IP" value={adresse} onChange={(e) => setAdresse(e.target.value)} inputMode="decimal" autoComplete="off" aide="En démonstration : plages de documentation seulement (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24)." />
        <ChampChoix label="Flux" value={flux} onChange={(e) => setFlux(e.target.value as FluxEnvoi)}>
          <option value="prospection">Prospection</option>
          <option value="lettre">Lettre d’information</option>
        </ChampChoix>
        <ChampChoix label="Domaine" value={domaineId} onChange={(e) => setDomaine(e.target.value)}>
          {(domaines.data?.data ?? []).map((d) => <option key={d.id} value={d.id}>{d.nom}</option>)}
        </ChampChoix>
        {erreur ? <MessageErreur>{erreur}</MessageErreur> : null}
      </div>
    </Modal>
  );
}
