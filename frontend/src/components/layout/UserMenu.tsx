/**
 * User menu — Avatar + nom + DropdownMenu (Profil / Paramètres / Déconnexion).
 *
 * - GET /api/v1/auth/me pour le user info (déjà cache via React Query)
 * - POST /api/v1/auth/logout pour la déconnexion → redirige /login
 */
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { User as UserIcon, Settings as SettingsIcon, LogOut } from 'lucide-react';
import { Avatar, DropdownMenu, type MenuItem } from '@/components/ui';
import { api } from '@/lib/api';

interface MeResponse {
  user: {
    id: string;
    name: string;
    email: string;
  };
}

export function UserMenu() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const { data } = useQuery<MeResponse>({
    queryKey: ['auth', 'me'],
    queryFn: async () => (await api.get<MeResponse>('/auth/me')).data,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });

  const name = data?.user?.name ?? 'Utilisateur';
  const email = data?.user?.email ?? '';

  const handleLogout = async () => {
    try {
      await api.post('/auth/logout');
    } catch {
      /* ignore — on redirige quand même */
    }
    queryClient.clear();
    void navigate({ to: '/login' });
  };

  const items: MenuItem[] = [
    {
      id: 'identity',
      label: email || name,
      icon: <Avatar name={name} size="xs" />,
      disabled: true,
      onSelect: () => {},
    },
    { id: 'div0', label: '', divider: true },
    {
      id: 'profile',
      label: 'Profil',
      icon: <UserIcon className="h-4 w-4" />,
      onSelect: () => void navigate({ to: '/settings' }),
    },
    {
      id: 'settings',
      label: 'Paramètres',
      icon: <SettingsIcon className="h-4 w-4" />,
      onSelect: () => void navigate({ to: '/settings' }),
    },
    { id: 'div1', label: '', divider: true },
    {
      id: 'logout',
      label: 'Déconnexion',
      icon: <LogOut className="h-4 w-4" />,
      destructive: true,
      onSelect: () => void handleLogout(),
    },
  ];

  return (
    <DropdownMenu
      align="right"
      items={items}
      trigger={
        <span
          className="flex items-center gap-2 rounded-lg px-1.5 py-1 transition hover:bg-slate-100 dark:hover:bg-slate-800"
          aria-label={`Menu utilisateur — ${name}`}
        >
          <Avatar name={name} size="sm" />
          <span className="hidden text-sm font-medium text-slate-700 dark:text-slate-200 lg:inline">{name}</span>
        </span>
      }
    />
  );
}
