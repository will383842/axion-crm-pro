import { StatusPill } from '@/components/ui';
import { CLASSEMENTS, type Classement } from './libelles';

/** Pastille de classement : l'état garde `StatusPill` (jamais la couleur du produit). */
export function PastilleClassement({ classement }: { classement: Classement }) {
  const { libelle, ton } = CLASSEMENTS[classement];
  return <StatusPill tone={ton}>{libelle}</StatusPill>;
}

/** Rond d'initiales (décoratif). */
export function Initiales({ texte }: { texte: string }) {
  return (
    <span aria-hidden className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-produit-pale text-sm font-semibold text-produit">
      {texte}
    </span>
  );
}
