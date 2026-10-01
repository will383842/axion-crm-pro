/**
 * Espace courant — affiché dans la barre latérale, sous le logo.
 *
 * Audit UX du 02/10/2026 (P0-2). Avant : un menu déroulant qui affichait
 * « Mon workspace » ou « Workspace a1b2c3 » (6 caractères d'un identifiant,
 * jamais le nom), et deux entrées DÉSACTIVÉES pour toujours (« Créer un
 * workspace », « Gérer les workspaces »). Il n'y a qu'un espace et rien à
 * choisir : on affiche simplement son NOM, renvoyé par `/auth/me`.
 *
 * Règles du libellé (`libelleEspace`) :
 *  - le nom réel de l'espace dès que le serveur le connaît ;
 *  - « Espace » tant qu'il n'est pas connu (chargement, ancien serveur) ;
 *  - « Aucun espace actif » quand le compte n'a VRAIMENT pas d'espace courant —
 *    c'est une anomalie de données à voir, pas un espace par défaut à inventer.
 * Jamais « Mon workspace », jamais un morceau d'identifiant.
 */
import { useQuery } from '@tanstack/react-query';
import { Avatar } from '@/components/ui';
import { api } from '@/lib/api';

export interface MeAvecEspace {
  user: {
    id: string;
    name?: string | null;
    email?: string | null;
    current_workspace_id: string | null;
  };
  workspace?: { id: string; name?: string | null } | null;
}

export function libelleEspace(me: MeAvecEspace | undefined): string {
  const nom = me?.workspace?.name?.trim();
  if (nom) return nom;
  if (me !== undefined && !me.user?.current_workspace_id) return 'Aucun espace actif';
  return 'Espace';
}

export function WorkspaceSelector() {
  const { data } = useQuery<MeAvecEspace>({
    queryKey: ['auth', 'me'],
    queryFn: async () => (await api.get<MeAvecEspace>('/auth/me')).data,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });

  const libelle = libelleEspace(data);

  return (
    // Ce bloc vit DANS la barre latérale : il suit ses jetons, pas ceux du
    // contenu. Plus de bouton : il n'y a rien à ouvrir.
    <div className="flex w-full items-center gap-2 rounded-lg px-2 py-1.5" data-testid="espace-courant" title={libelle}>
      <Avatar name={libelle} size="xs" />
      <span className="flex-1 truncate text-xs font-medium text-sidebar-fg">{libelle}</span>
    </div>
  );
}
