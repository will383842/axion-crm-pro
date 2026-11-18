/** LI-D1 — bouton d'arrêt, toujours visible ; rouge quand l'arrêt est actif (alors : reprise, motif obligatoire). */
import { useState } from 'react';
import { Button } from '@/components/ui';

export function BoutonArret({ actif, occupe, onArret, onReprise }: {
  actif: boolean;
  occupe: boolean;
  onArret: (motif: string) => void;
  onReprise: (motif: string) => void;
}) {
  const [motif, setMotif] = useState('');
  const valide = motif.trim().length >= 10 && !motif.includes('@');
  return (
    <div className="flex flex-wrap items-center gap-2">
      <label className="sr-only" htmlFor="motif-arret">Motif (10 caractères au moins, sans adresse)</label>
      <input
        id="motif-arret"
        value={motif}
        onChange={(e) => setMotif(e.target.value)}
        placeholder="Motif (10 caractères au moins)"
        className="h-9 w-64 rounded-lg bg-white px-3 text-sm ring-1 ring-slate-200 focus-visible:ring-2 dark:bg-slate-900 dark:ring-slate-700"
      />
      {actif ? (
        <Button variant="destructive" disabled={!valide || occupe} onClick={() => onReprise(motif.trim())}>Arrêt actif — reprendre</Button>
      ) : (
        <Button variant="secondary" disabled={!valide || occupe} onClick={() => onArret(motif.trim())}>Arrêt d’urgence</Button>
      )}
    </div>
  );
}
