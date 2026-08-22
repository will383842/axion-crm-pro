/**
 * Workspace selector — DropdownMenu avec avatar + ChevronsUpDown.
 * MVP : 1 workspace mocké + actions « Créer un workspace », « Gérer ».
 */
import { useQuery } from '@tanstack/react-query';
import { ChevronsUpDown, Plus, Settings as SettingsIcon } from 'lucide-react';
import { Avatar, DropdownMenu, type MenuItem } from '@/components/ui';
import { api } from '@/lib/api';

interface MeResponse {
  user: {
    id: string;
    name?: string;
    email?: string;
    current_workspace_id: string | null;
  };
}

export function WorkspaceSelector() {
  const { data } = useQuery<MeResponse>({
    queryKey: ['auth', 'me'],
    queryFn: async () => (await api.get<MeResponse>('/auth/me')).data,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });

  const workspaceName = data?.user?.current_workspace_id
    ? `Workspace ${data.user.current_workspace_id.slice(0, 6)}`
    : 'Mon workspace';

  const items: MenuItem[] = [
    {
      id: 'current',
      label: workspaceName,
      icon: <Avatar name={workspaceName} size="xs" />,
      onSelect: () => {},
    },
    { id: 'div1', label: '', divider: true },
    {
      id: 'create',
      label: 'Créer un workspace',
      icon: <Plus className="h-4 w-4" />,
      disabled: true,
      onSelect: () => {},
    },
    {
      id: 'manage',
      label: 'Gérer les workspaces',
      icon: <SettingsIcon className="h-4 w-4" />,
      disabled: true,
      onSelect: () => {},
    },
  ];

  return (
    <DropdownMenu
      align="left"
      items={items}
      // Ce sélecteur vit DANS la barre latérale : il suit ses jetons, pas ceux
      // du contenu — sinon il ressort comme une tache claire sur le fond coloré.
      // D28-004 — `<span>` avant le 2026-08-22 : la focalisation clavier venait
      // du `<button>` que `DropdownMenu` posait autour, wrapper depuis retiré
      // (il fabriquait un bouton-dans-un-bouton chez cinq autres appelants).
      trigger={
        <button
          type="button"
          className="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left transition hover:bg-white/10"
        >
          <Avatar name={workspaceName} size="xs" />
          <span className="flex-1 truncate text-xs font-medium text-sidebar-fg">{workspaceName}</span>
          <ChevronsUpDown className="h-3.5 w-3.5 shrink-0 text-sidebar-fg-muted" />
        </button>
      }
    />
  );
}
