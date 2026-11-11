/**
 * Droits de l'utilisateur courant pour les écrans « Origines » et « Effacements ».
 *
 * Le serveur reste l'AUTORITÉ (chaque route refuse sans le droit). Ici on ne
 * fait que dire à l'écran quoi griser, avec la raison. Source : `/auth/me`.
 * Si le serveur envoie `permissions` (liste de noms), on la lit telle quelle ;
 * sinon on déduit de `roles` la carte du semeur (`PermissionsAndRolesSeeder`) :
 *  - `contacts.view_pii` et `data.export` : owner, admin, operator ;
 *  - `rgpd.handle` : owner SEUL (L3c-0) ;
 *  - `users.manage` : owner, admin ; `workspaces.manage` : owner (boîtes d'envoi).
 */
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

interface Moi {
  roles?: string[];
  permissions?: string[];
}

export const PERMISSION_PII = 'contacts.view_pii';
export const PERMISSION_EXPORT = 'data.export';
export const PERMISSION_TRAITER = 'rgpd.handle';
/** Voir adresse, note et jeton des demandes RGPD : owner et admin seulement. */
export const PERMISSION_RGPD_PII = 'rgpd.view_pii';
/** Suspendre une boîte d'envoi : owner et admin (semeur : `users.manage`). */
export const PERMISSION_GERER_UTILISATEURS = 'users.manage';
/** Réactiver une boîte d'envoi : owner seul (semeur : `workspaces.manage`). */
export const PERMISSION_GERER_ESPACE = 'workspaces.manage';

const PAR_ROLE: Record<string, string[]> = {
  owner: [PERMISSION_PII, PERMISSION_EXPORT, PERMISSION_TRAITER, PERMISSION_RGPD_PII, PERMISSION_GERER_UTILISATEURS, PERMISSION_GERER_ESPACE],
  admin: [PERMISSION_PII, PERMISSION_EXPORT, PERMISSION_RGPD_PII, PERMISSION_GERER_UTILISATEURS],
  operator: [PERMISSION_PII, PERMISSION_EXPORT],
  viewer: [],
};

export function permissionsDe(moi: Moi | undefined): Set<string> {
  if (moi === undefined) return new Set();
  if (Array.isArray(moi.permissions)) return new Set(moi.permissions);
  return new Set((moi.roles ?? []).flatMap((r) => PAR_ROLE[r] ?? []));
}

export interface Droits {
  connus: boolean;
  voirPii: boolean;
  exporter: boolean;
  traiter: boolean;
  voirAdresseRgpd: boolean;
}

export function useDroits(): Droits {
  const { data } = useQuery<Moi>({
    queryKey: ['auth', 'me'],
    queryFn: async () => (await api.get<Moi>('/auth/me')).data,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });
  const p = permissionsDe(data);
  return { connus: data !== undefined, voirPii: p.has(PERMISSION_PII), exporter: p.has(PERMISSION_EXPORT), traiter: p.has(PERMISSION_TRAITER), voirAdresseRgpd: p.has(PERMISSION_RGPD_PII) };
}

/** Raison affichée sous le bouton d'export grisé ; `null` si l'export est permis. */
export function raisonExportRefuse(d: Pick<Droits, 'voirPii' | 'exporter'>): string | null {
  if (d.exporter && d.voirPii) return null;
  if (!d.voirPii && !d.exporter) return 'Export impossible : il vous manque les permissions data.export et contacts.view_pii. L’export exige les deux.';
  if (!d.voirPii) return 'Export impossible : il vous manque la permission contacts.view_pii. L’export exige data.export ET contacts.view_pii.';
  return 'Export impossible : vous avez contacts.view_pii mais pas data.export. L’export exige les deux.';
}
