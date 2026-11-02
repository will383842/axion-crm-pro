import { useId, useState, type FormEvent } from 'react';
import { Button, Modal } from '@/components/ui';
import { useCreerFiche, type ReponseApresGeste, type ReponseResume } from './requetes';

interface Props {
  reponse: ReponseResume;
  ouverte: boolean;
  onFermer: () => void;
  onCreee: (r: ReponseApresGeste) => void;
}

const CHAMP = 'h-9 w-full rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-produit max-md:h-11';

/** Créer une nouvelle personne dans le CRM à partir d'une réponse sans fiche, avec sa provenance. */
export function ModaleNouvelleFiche({ reponse, ouverte, onFermer, onCreee }: Props) {
  const base = useId();
  const [prenom, setPrenom] = useState(reponse.prenom ?? '');
  const [nom, setNom] = useState(reponse.nom ?? '');
  const [organisation, setOrganisation] = useState(reponse.organisation ?? '');
  const [provenance, setProvenance] = useState('Réponse reçue d’une adresse inconnue');
  const creer = useCreerFiche();

  function soumettre(e: FormEvent) {
    e.preventDefault();
    creer.mutate(
      { id: String(reponse.id), prenom, nom, organisation: organisation === '' ? null : organisation, provenance },
      { onSuccess: onCreee },
    );
  }

  const champs: ReadonlyArray<[string, string, string, (v: string) => void]> = [
    ['prenom', 'Prénom', prenom, setPrenom],
    ['nom', 'Nom', nom, setNom],
    ['organisation', 'Organisation', organisation, setOrganisation],
    ['provenance', 'Provenance', provenance, setProvenance],
  ];

  return (
    <Modal open={ouverte} onClose={onFermer} title="Nouvelle fiche dans le CRM" description={`Adresse : ${reponse.de}`}>
      <form onSubmit={soumettre} className="space-y-3">
        {champs.map(([cle, libelle, valeur, changer]) => (
          <div key={cle} className="flex flex-col gap-1">
            <label htmlFor={`${base}-${cle}`} className="text-xs font-medium text-slate-600">{libelle}</label>
            <input id={`${base}-${cle}`} type="text" value={valeur} onChange={(e) => changer(e.target.value)} className={CHAMP} />
          </div>
        ))}
        {creer.isError ? <p role="alert" className="text-sm text-rose-700">La fiche n’a pas pu être créée. Vérifiez les champs, puis réessayez.</p> : null}
        <div className="flex flex-wrap justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" className="max-md:h-11" onClick={onFermer}>Annuler</Button>
          <Button type="submit" className="max-md:h-11" loading={creer.isPending}>Créer la fiche</Button>
        </div>
      </form>
    </Modal>
  );
}
