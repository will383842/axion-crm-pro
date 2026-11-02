/** Brouillon de séquence proposé par l'IA : une suggestion à relire, jamais envoyée seule. */
import { useEffect, useState } from 'react';
import { Sparkles } from 'lucide-react';
import { Button } from '@/components/ui';
import { suggererSequence, useGestesMessages } from '../../creation/api';

type Suggestion = Awaited<ReturnType<typeof suggererSequence>>;

export function SuggestionIA({ ouverte, onFermer }: { ouverte: boolean; onFermer: () => void }) {
  const [suggestion, setSuggestion] = useState<Suggestion | null>(null);
  const [erreur, setErreur] = useState(false);
  const [cree, setCree] = useState(false);
  const { creer } = useGestesMessages();

  useEffect(() => {
    if (!ouverte || suggestion !== null || erreur) return;
    let annule = false;
    suggererSequence()
      .then((s) => { if (!annule) setSuggestion(s); })
      .catch(() => { if (!annule) setErreur(true); });
    return () => { annule = true; };
  }, [ouverte, suggestion, erreur]);

  if (!ouverte) return null;

  const creerBrouillons = async () => {
    if (!suggestion) return;
    for (const [i, m] of suggestion.messages.entries()) {
      await creer.mutateAsync({ nom: `Suggestion · message ${i + 1}`, objet: m.objet, corps: m.corps, bloc_information: true });
    }
    setCree(true);
  };

  return (
    <section aria-label="Suggestion de séquence" className="mb-4 rounded-2xl bg-violet-50 p-4 ring-1 ring-violet-200">
      <div className="mb-2 flex flex-wrap items-center gap-2">
        <Sparkles className="h-4 w-4 text-violet-700" aria-hidden />
        <h2 className="text-lg font-bold tracking-tight text-slate-900">Brouillon de séquence</h2>
        <span className="rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-800">Suggestion</span>
      </div>
      {erreur ? (
        <p role="alert" className="text-sm text-rose-800">La suggestion n’a pas pu être préparée. Réessayez dans un instant.</p>
      ) : suggestion === null ? (
        <p role="status" className="text-sm text-slate-700">Préparation du brouillon…</p>
      ) : (
        <>
          <p className="mb-3 text-sm text-slate-700">Une suggestion à relire et à corriger : rien n’est créé ni envoyé sans votre accord.</p>
          <ol className="space-y-2">
            {suggestion.messages.map((m, i) => (
              <li key={i} className="rounded-xl bg-white p-3 ring-1 ring-slate-200">
                <p className="text-xs text-slate-600">Message {i + 1} · {m.delai_jours === 0 ? 'jour 0' : `jour +${m.delai_jours}`}</p>
                <p className="text-sm font-semibold text-slate-900">{m.objet}</p>
                <p className="mt-1 whitespace-pre-wrap text-sm text-slate-700">{m.corps}</p>
              </li>
            ))}
          </ol>
          {cree ? <p role="status" className="mt-3 text-sm text-slate-900">Trois brouillons ont été ajoutés à la liste, à relire avant de les valider.</p> : null}
        </>
      )}
      <div className="mt-3 flex flex-wrap gap-2">
        {suggestion !== null && !cree ? (
          <Button variant="secondary" loading={creer.isPending} onClick={() => { void creerBrouillons(); }}>Créer ces brouillons</Button>
        ) : null}
        <Button variant="ghost" onClick={() => { setSuggestion(null); setErreur(false); setCree(false); onFermer(); }}>Fermer</Button>
      </div>
    </section>
  );
}
