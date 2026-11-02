/** « Puis-je écrire à… ? » : une adresse ou un domaine, la réponse, TOUTES les raisons. */
import { useId, useState, type FormEvent } from 'react';
import { CheckCircle2, HelpCircle, XCircle } from 'lucide-react';
import { Button } from '@/components/ui';
import { cn } from '@/components/ui/cn';
import { demanderPuisJeEcrire, type PuisJeEcrire as Reponse } from './api';
import { Annonce, Bloc, CLASSE_BOUTON_MOBILE, CLASSE_CHAMP, Etiquette, messageDErreur } from './commun';

const ASPECT = {
  oui: { libelle: 'Oui', icone: CheckCircle2, classe: 'bg-emerald-50 text-emerald-900' },
  non: { libelle: 'Non', icone: XCircle, classe: 'bg-rose-50 text-rose-900' },
  prudence: { libelle: 'Avec prudence', icone: HelpCircle, classe: 'bg-amber-50 text-amber-900' },
} as const;

export function PuisJeEcrire() {
  const id = useId();
  const [saisie, setSaisie] = useState('');
  const [reponse, setReponse] = useState<Reponse | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);
  const [enCours, setEnCours] = useState(false);

  async function verifier(e: FormEvent) {
    e.preventDefault();
    if (saisie.trim() === '') return;
    setEnCours(true);
    setErreur(null);
    try {
      setReponse(await demanderPuisJeEcrire(saisie));
    } catch (err) {
      setReponse(null);
      setErreur(messageDErreur(err, 'La vérification n’a pas pu se faire. Réessayez dans un instant.'));
    } finally {
      setEnCours(false);
    }
  }

  const aspect = reponse ? ASPECT[reponse.reponse] : null;
  return (
    <Bloc titre="Puis-je écrire à… ?">
      <form onSubmit={(e) => void verifier(e)} className="flex flex-col gap-2 sm:flex-row sm:items-end">
        <div className="min-w-0 flex-1">
          <Etiquette htmlFor={id}>Adresse ou domaine</Etiquette>
          <input id={id} type="text" value={saisie} onChange={(e) => setSaisie(e.target.value)} placeholder="prenom@entreprise.example" className={CLASSE_CHAMP} autoComplete="off" />
        </div>
        <Button type="submit" loading={enCours} className={CLASSE_BOUTON_MOBILE}>
          Vérifier
        </Button>
      </form>
      <Annonce message={erreur} ton="erreur" />
      {reponse && aspect ? (
        <div role="status" className={cn('mt-3 rounded-xl p-4 text-sm', aspect.classe)}>
          <p className="flex items-center gap-2 text-lg font-bold">
            <aspect.icone className="h-5 w-5" aria-hidden />
            <span>{aspect.libelle}</span>
          </p>
          <p className="mt-1 break-all text-xs">{reponse.adresse}</p>
          <ul className="mt-2 list-disc space-y-1 pl-5">
            {reponse.raisons.map((raison) => (
              <li key={raison}>{raison}</li>
            ))}
          </ul>
        </div>
      ) : null}
    </Bloc>
  );
}
