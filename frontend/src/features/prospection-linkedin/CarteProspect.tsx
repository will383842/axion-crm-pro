/** LI-D1 — une carte « prêt à cliquer ». Rien ne s'ouvre ni ne part tout seul : chaque action est un clic du fondateur. */
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button, Card, Modal } from '@/components/ui';
import { GESTES, nouvelleCle, type Geste, type ProspectFile } from './api';

export function CarteProspect({ prospect, occupe, onGeste }: {
  prospect: ProspectFile;
  occupe: boolean;
  onGeste: (geste: Geste, cle: string) => void;
}) {
  // Une clé par geste, gardée tant que la carte vit : un nouvel essai (double clic, panne) rejoue la MÊME clé.
  const cles = useRef<Map<Geste, string>>(new Map());
  const [confirmer, setConfirmer] = useState(false);
  const cle = (g: Geste): string => {
    let c = cles.current.get(g);
    if (c === undefined) { c = nouvelleCle(); cles.current.set(g, c); }
    return c;
  };
  const copier = async () => {
    if (prospect.texte === null) return;
    try {
      await navigator.clipboard.writeText(prospect.texte);
      toast.success('Texte copié.');
    } catch {
      toast.error('Copie impossible : sélectionnez le texte à la main.');
    }
  };
  return (
    <Card>
      <article aria-label={`Prospect ${prospect.entreprise ?? ''}`} className="space-y-3 p-4">
        <header className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="text-base font-semibold text-slate-900 dark:text-white">{prospect.entreprise ?? 'Entreprise inconnue'}</h2>
          <span className="text-sm text-slate-600 dark:text-slate-300">Score {prospect.score}</span>
        </header>
        {prospect.texte === null ? (
          <p className="text-sm text-amber-700 dark:text-amber-300">Texte indisponible (non conforme) : rien à copier.</p>
        ) : (
          <p className="whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-sm text-slate-800 dark:bg-slate-800 dark:text-slate-100">{prospect.texte}</p>
        )}
        <div className="flex flex-wrap gap-2">
          <a
            href={prospect.profil}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium text-produit ring-1 ring-slate-200 focus-visible:ring-2 dark:ring-slate-700"
          >
            Ouvrir le profil
          </a>
          <Button variant="secondary" disabled={prospect.texte === null} onClick={() => void copier()}>Copier le texte</Button>
        </div>
        <div className="flex flex-wrap gap-2" role="group" aria-label="Gestes">
          {GESTES.map((g) => (
            <Button key={g.geste} variant="subtle" disabled={occupe} onClick={() => onGeste(g.geste, cle(g.geste))}>{g.libelle}</Button>
          ))}
          <Button variant="destructive" disabled={occupe} onClick={() => setConfirmer(true)}>Opposition</Button>
        </div>
      </article>
      <Modal
        open={confirmer}
        onClose={() => setConfirmer(false)}
        title="Confirmer l’opposition"
        description="La personne est inscrite au registre d’opposition et ne sera plus jamais contactée. Aucune donnée n’est supprimée."
        footer={(
          <>
            <Button variant="secondary" onClick={() => setConfirmer(false)}>Annuler</Button>
            <Button variant="destructive" onClick={() => { setConfirmer(false); onGeste('opposition', cle('opposition')); }}>Confirmer l’opposition</Button>
          </>
        )}
      >
        <p className="text-sm">{prospect.entreprise ?? 'Cette personne'}</p>
      </Modal>
    </Card>
  );
}
