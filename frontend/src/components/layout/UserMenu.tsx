/**
 * User menu — Avatar + nom + DropdownMenu (Paramètres / Déconnexion).
 *
 * - GET /api/v1/auth/me pour le user info (déjà cache via React Query)
 * - POST /api/v1/auth/logout pour la déconnexion → redirige /login
 *
 * Audit UX du 02/10/2026 (P0-2) :
 *  - le nom affiché retombe sur l'ADRESSE E-MAIL quand le nom est vide — il
 *    affichait « Utilisateur », qui ne dit pas qui est connecté ;
 *  - l'entrée « Profil » (qui menait au même écran que « Paramètres ») et
 *    l'entrée d'identité désactivée pour toujours ont été retirées ; l'adresse
 *    reste lisible au survol du déclencheur.
 */
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { Settings as SettingsIcon, LogOut, ShieldCheck } from 'lucide-react';
import { Avatar, DropdownMenu, type MenuItem } from '@/components/ui';
import { api } from '@/lib/api';
import { useProduitsVisibles } from '@/features/socle/produits/useProduits';

interface MeResponse {
  user: {
    id: string;
    name?: string | null;
    email?: string | null;
  };
}

/** Nom à afficher : le nom, sinon l'adresse e-mail, sinon « Mon compte ». */
export function nomAffiche(me: MeResponse | undefined): string {
  const nom = me?.user?.name?.trim();
  if (nom) return nom;
  const email = me?.user?.email?.trim();
  if (email) return email;
  return 'Mon compte';
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

  const name = nomAffiche(data);
  const email = data?.user?.email?.trim() ?? '';

  const handleLogout = async () => {
    try {
      await api.post('/auth/logout');
    } catch {
      /* ignore — on redirige quand même */
    }
    queryClient.clear();
    void navigate({ to: '/login' });
  };

  // DM0-COQ — « Mon compte et sécurité » (écran commun, `/compte`). Montré
  // seulement quand Envoi existe : sans lui, le menu d'Axion en production
  // reste celui d'aujourd'hui tant que l'écran est « en attente » (DM1-COM).
  const visibles = useProduitsVisibles();
  const compte: MenuItem[] = visibles.envoi
    ? [
        {
          id: 'compte',
          label: 'Mon compte et sécurité',
          icon: <ShieldCheck className="h-4 w-4" />,
          onSelect: () => void navigate({ to: '/compte' }),
        },
      ]
    : [];

  const items: MenuItem[] = [
    ...compte,
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
      // D28-004 — `<span>` avant le 2026-08-22 : la focalisation clavier venait
      // du `<button>` que `DropdownMenu` posait autour. Ce wrapper a disparu (il
      // fabriquait un bouton-dans-un-bouton chez cinq autres appelants), donc le
      // déclencheur porte lui-même son rôle, sans quoi le menu utilisateur
      // devenait inatteignable au clavier.
      trigger={
        <button
          type="button"
          className="flex items-center gap-2 rounded-lg px-1.5 py-1 transition hover:bg-slate-100 dark:hover:bg-slate-800"
          aria-label={`Menu utilisateur — ${name}`}
          title={email || name}
        >
          <Avatar name={name} size="sm" />
          <span className="hidden text-sm font-medium text-sidebar-fg lg:inline">{name}</span>
        </button>
      }
    />
  );
}
