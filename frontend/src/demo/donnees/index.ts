/**
 * Le jeu factice complet de la démo, à graine fixe : `genererJeu()` rend
 * toujours exactement les mêmes données (garde : `tests/lib/demo-donnees-factices.test.ts`).
 */
import { genererCrm } from './crm';
import { genererEnvoi } from './envoi';
import { ESPACE_DEMO, UTILISATEURS_DEMO, notificationsDemo } from './socle';
import type { JeuDemo } from './types';

export type * from './types';
export { IDENTIFIANTS_DEMO } from './socle';

export function genererJeu(): JeuDemo {
  const crm = genererCrm();
  const envoi = genererEnvoi(crm.contacts, crm.entreprises);
  return {
    espaces: [{ ...ESPACE_DEMO }],
    utilisateurs: UTILISATEURS_DEMO.map((u) => ({ ...u })),
    notifications: notificationsDemo(),
    ...crm,
    ...envoi,
  };
}
