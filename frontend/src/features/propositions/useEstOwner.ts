/**
 * Le compte courant a-t-il le rôle owner ? Lu dans `/auth/me` (`roles`), la
 * même requête que la coquille de l'application (`['auth', 'me']`) : aucune
 * lecture de plus.
 *
 * Sert à N'AFFICHER que ce que le serveur autorise : la décision, elle, est
 * prise par le serveur (403 pour tout autre rôle). Tant que la réponse n'est
 * pas là, ou si elle échoue : `false` — on ne montre pas une entrée qui
 * mènerait à un refus.
 */
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

interface MeRoles {
  roles?: unknown;
}

export function estOwner(me: MeRoles | undefined): boolean {
  return Array.isArray(me?.roles) && me.roles.includes('owner');
}

export function useEstOwner(): boolean {
  const { data } = useQuery<MeRoles>({
    queryKey: ['auth', 'me'],
    queryFn: async () => (await api.get<MeRoles>('/auth/me')).data,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });
  return estOwner(data);
}
