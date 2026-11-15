/**
 * G4-UI (D14) — badge « opposée » : cette adresse a demandé à ne plus être sollicitée.
 *
 * Le serveur (`StatutOpposition`, G4-API) ne rend que `{ opposee, motifs }` : ni date, ni contenu, ni empreinte. Le MOT
 * « opposée » est écrit (jamais une couleur seule) ; l'infobulle regroupe les motifs en clair. Un motif inconnu n'est
 * jamais affiché tel quel (pas de slug technique à l'écran). Lecture seule : le badge n'écrit rien.
 */
import type { OppositionStatut } from './types';

const MOTIFS: Record<string, string> = {
  stop: 'demande d’arrêt (STOP)',
  art21: 'opposition (art. 21 du RGPD)',
  liste_refus: 'liste de refus',
};

export function BadgeOpposee({ opposition }: { opposition: OppositionStatut | null | undefined }) {
  if (opposition?.opposee !== true) return null;
  const motifs = [...new Set(opposition.motifs)].flatMap((m) => (MOTIFS[m] !== undefined ? [MOTIFS[m]] : []));
  const infobulle = motifs.length > 0 ? `Ne plus solliciter : ${motifs.join(', ')}.` : 'Ne plus solliciter cette adresse.';
  return (
    <span
      title={infobulle}
      className="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-800 dark:bg-rose-950 dark:text-rose-200"
    >
      opposée
    </span>
  );
}
