/**
 * Pastille de compteur d'une entrée du menu (« Doublons à vérifier  12 »).
 *
 * Rien n'est rendu pour `null`, `undefined` ou 0 : une pastille annonce qu'il
 * y a quelque chose à traiter, elle ne dit jamais « 0 » et n'invente jamais
 * un chiffre qu'on ne connaît pas.
 *
 * Accessibilité : `role="img"` + `aria-label` portent la phrase complète
 * (« 12 doublons à vérifier ») au lieu du seul chiffre, qui ne voudrait rien
 * dire lu hors contexte ; le `title` la montre au survol. Pas de
 * `role="status"` : le menu est sur tous les écrans, et annoncer chaque
 * rafraîchissement de la minute serait du bruit.
 */
import { cn } from '@/components/ui';
import { texteDePastille } from './compteurs';

export interface PastilleCompteurProps {
  nombre: number | null | undefined;
  libelle: (n: number) => string;
  /** Barre réduite : la pastille se pose en exposant sur l'icône. */
  enExposant?: boolean;
}

export function PastilleCompteur({ nombre, libelle, enExposant = false }: PastilleCompteurProps) {
  if (typeof nombre !== 'number' || !Number.isFinite(nombre) || nombre <= 0) return null;

  const phrase = libelle(nombre);

  return (
    <span
      role="img"
      aria-label={phrase}
      title={phrase}
      data-pastille-compteur
      className={cn(
        // 12 px au plus petit, même en exposant (plancher de lisibilité,
        // `tests/styles/lisibilite.test.ts`).
        'inline-flex shrink-0 items-center justify-center rounded-full text-xs font-semibold tabular-nums leading-none text-white',
        enExposant
          ? 'absolute -right-2.5 -top-2 min-w-[1.25rem] bg-sidebar-active px-1 py-0.5 ring-2 ring-sidebar'
          : 'min-w-[1.5rem] bg-white/15 px-1.5 py-0.5',
      )}
    >
      {texteDePastille(nombre)}
    </span>
  );
}
