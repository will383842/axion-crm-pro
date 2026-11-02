/** Le contrôleur de contenu : mots interdits, liens, bloc d'information, texte simple, valeurs de repli. */
import { useEffect, useState } from 'react';
import { Bloc, ListeControles } from '../../creation/composants';
import { controlerMessage, type ControleContenu, type SaisieMessage } from '../../creation/api';

export function ControlesMessage({ saisie, onResultat }: { saisie: SaisieMessage; onResultat?: (bloquants: number) => void }) {
  const [controles, setControles] = useState<ControleContenu[] | null>(null);
  const [erreur, setErreur] = useState(false);
  const empreinte = JSON.stringify([saisie.objet, saisie.objet_b ?? null, saisie.corps, saisie.bloc_information ?? true]);

  useEffect(() => {
    let annule = false;
    const minuterie = window.setTimeout(() => {
      controlerMessage(saisie)
        .then((r) => {
          if (annule) return;
          setControles(r.controles);
          setErreur(false);
          onResultat?.(r.bloquants);
        })
        .catch(() => { if (!annule) setErreur(true); });
    }, 300);
    return () => { annule = true; window.clearTimeout(minuterie); };
    // L'empreinte résume la saisie : on ne relance que si le contenu change.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [empreinte]);

  const ok = controles?.filter((c) => c.etat === 'ok').length ?? 0;
  return (
    <Bloc titre="Contrôles" {...(controles ? { mot: `${ok}/${controles.length}` } : {})} etiquette="Contrôles">
      {erreur ? (
        <p role="alert" className="text-sm text-rose-800">Les contrôles n’ont pas pu être faits. Réessayez dans un instant.</p>
      ) : controles === null ? (
        <p role="status" className="text-sm text-slate-600">Contrôle du message…</p>
      ) : (
        <ListeControles etiquette="Contrôles du message" controles={controles} />
      )}
    </Bloc>
  );
}
