/** Aperçu réel d'un message pour un destinataire choisi, en ordinateur ou en téléphone. */
import { useEffect, useState } from 'react';
import { Puces, CHAMP, Bloc } from '../../creation/composants';
import { apercuMessage, useExemples } from '../../creation/api';
import { cn } from '@/components/ui';

type Format = 'ordinateur' | 'telephone';

export function ApercuMessage({ objet, corps, blocInformation }: { objet: string; corps: string; blocInformation: boolean }) {
  const exemples = useExemples();
  const [format, setFormat] = useState<Format>('ordinateur');
  const [exempleId, setExempleId] = useState<string | undefined>(undefined);
  const [rendu, setRendu] = useState<{ objet: string; corps: string; de: string } | null>(null);
  const [erreur, setErreur] = useState(false);

  useEffect(() => {
    let annule = false;
    const minuterie = window.setTimeout(() => {
      apercuMessage({ objet, corps, bloc_information: blocInformation, ...(exempleId ? { destinataire_exemple_id: exempleId } : {}) })
        .then((r) => { if (!annule) { setRendu(r); setErreur(false); } })
        .catch(() => { if (!annule) setErreur(true); });
    }, 250);
    return () => { annule = true; window.clearTimeout(minuterie); };
  }, [objet, corps, blocInformation, exempleId]);

  return (
    <Bloc titre="Aperçu réel" etiquette="Aperçu réel">
      <Puces<Format>
        libelle="Format de l’aperçu"
        options={[{ id: 'ordinateur', libelle: 'Ordinateur' }, { id: 'telephone', libelle: 'Téléphone' }]}
        valeur={format}
        onChange={setFormat}
      />
      <div
        data-testid="apercu-mail"
        data-format={format}
        className={cn('mt-3 rounded-xl bg-slate-50 p-4 text-sm text-slate-900 ring-1 ring-slate-200', format === 'telephone' && 'mx-auto max-w-[320px]')}
      >
        {erreur ? (
          <p role="alert" className="text-rose-800">L’aperçu n’a pas pu être préparé. Réessayez dans un instant.</p>
        ) : rendu === null ? (
          <p className="text-slate-600">Préparation de l’aperçu…</p>
        ) : (
          <>
            <p className="text-xs text-slate-600">De : {rendu.de}</p>
            <p className="mt-1 break-words"><strong>Objet :</strong> {rendu.objet}</p>
            <hr className="my-3 border-slate-200" />
            <p className="whitespace-pre-wrap break-words">{rendu.corps}</p>
          </>
        )}
      </div>
      <div className="mt-3">
        <label htmlFor="voir-pour" className="mb-1 block text-xs font-medium text-slate-600">Voir pour</label>
        <select id="voir-pour" className={CHAMP} value={exempleId ?? exemples.data?.[0]?.id ?? ''} onChange={(e) => { setExempleId(e.target.value); }}>
          {(exemples.data ?? []).map((x) => <option key={x.id} value={x.id}>{x.libelle}</option>)}
        </select>
      </div>
    </Bloc>
  );
}
