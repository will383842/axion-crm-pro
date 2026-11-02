import { useQuery } from '@tanstack/react-query';
import { PageHeader } from '@/components/ui';
import { api } from '@/lib/api';
import { ChangerMotDePasse } from './ChangerMotDePasse';
import { AppareilsDeConfiance } from './securite/AppareilsDeConfiance';
import { CodesDeSecours } from './securite/CodesDeSecours';
import { RaccourcisClavier } from './securite/RaccourcisClavier';

interface ReponseMoi {
  user?: { two_factor_method?: 'email' | 'totp' | null };
}

/**
 * Les appareils de confiance et les codes de secours vont avec la connexion par
 * code e-mail (lots S3 et S12) : ils ne s'affichent, et leurs routes ne sont
 * appelées, que si le serveur annonce `two_factor_method: 'email'` dans
 * `/auth/me`, ou en démonstration (`VITE_DEMO`, figé au build, comme
 * `TwoFactorPage`). En production aujourd'hui : mot de passe et raccourcis seuls.
 */
function useSecuriteParCodeEmail(): boolean {
  const demo = import.meta.env.VITE_DEMO === '1';
  const me = useQuery({
    queryKey: ['compte', 'methode-2fa'],
    queryFn: async () => (await api.get<ReponseMoi>('/auth/me')).data,
    enabled: !demo,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });
  return demo || me.data?.user?.two_factor_method === 'email';
}

/** Mon compte et sécurité : écran commun aux deux produits (DM1-COM). */
export function ComptePage() {
  const securite = useSecuriteParCodeEmail();
  return (
    <div>
      <PageHeader
        surTitre="Commun"
        title="Mon compte et sécurité"
        subtitle={
          securite
            ? 'Votre mot de passe, vos appareils de confiance et vos codes de secours.'
            : 'Votre mot de passe et vos raccourcis clavier.'
        }
      />
      <div className="space-y-4">
        <ChangerMotDePasse />
        {securite ? <AppareilsDeConfiance /> : null}
        {securite ? <CodesDeSecours /> : null}
        <RaccourcisClavier />
      </div>
    </div>
  );
}
