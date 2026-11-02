/** DM2-INF — « Qui envoie en votre nom ? » : pour chaque domaine, les boîtes et les adresses IP autorisées. */
import { Modal, Button } from '@/components/ui';
import { useBoites, useDomaines, useServeurs } from './api';
import { BOUTON_TACTILE } from './Formulaire';

export function DialogueQuiEnvoie({ ouvert, fermer }: { ouvert: boolean; fermer: () => void }) {
  const domaines = useDomaines();
  const boites = useBoites();
  const serveurs = useServeurs();
  const ips = (serveurs.data ?? []).flatMap((s) => s.adresses_ip.map((i) => ({ ...i, serveur: s.nom })));
  return (
    <Modal
      open={ouvert}
      onClose={fermer}
      size="lg"
      title="Qui envoie en votre nom ?"
      description="Pour chaque domaine : les boîtes qui écrivent et les adresses IP autorisées à envoyer. Toute autre source est inconnue, et le DMARC la bloque."
      footer={<Button variant="primary" className={BOUTON_TACTILE} onClick={fermer}>Fermer</Button>}
    >
      <ul className="space-y-3">
        {(domaines.data?.data ?? []).map((d) => (
          <li key={d.id} className="rounded-xl p-3 ring-1 ring-slate-200">
            <h3 className="text-sm font-semibold text-slate-900">{d.nom}</h3>
            <p className="mt-1 text-xs text-slate-600">
              Boîtes : {(boites.data ?? []).filter((b) => b.domaine_id === d.id).map((b) => b.adresse).join(', ') || 'aucune'}
            </p>
            <p className="text-xs text-slate-600">
              Adresses IP : {ips.filter((i) => i.domaine_id === d.id).map((i) => i.adresse).join(', ') || 'aucune'}
            </p>
          </li>
        ))}
      </ul>
    </Modal>
  );
}
