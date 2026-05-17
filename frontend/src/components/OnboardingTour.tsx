import { useEffect, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import Joyride, { CallBackProps, Step, STATUS } from 'react-joyride';
import { api } from '@/lib/api';

interface AuthMeResponse {
  user: {
    id: string;
    email: string;
    name: string;
    onboarding_tour_completed_at: string | null;
  };
}

const STEPS: Step[] = [
  {
    target: 'body',
    placement: 'center',
    content: (
      <div>
        <h3 className="text-lg font-semibold">Bienvenue dans Axion CRM Pro 👋</h3>
        <p className="mt-2 text-sm">
          On va vous montrer en 30 secondes l'essentiel de l'interface. Vous pouvez quitter
          à tout moment via la croix.
        </p>
      </div>
    ),
    disableBeacon: true,
  },
  {
    target: '[data-tour="sidebar"]',
    placement: 'right',
    content: 'La barre latérale regroupe vos espaces : Entreprises, Contacts, Couverture France, LLM router, RGPD, Admin.',
  },
  {
    target: '[data-tour="global-search"]',
    placement: 'bottom',
    content: (
      <span>
        Recherche globale ultra-rapide. Astuce : appuyez sur{' '}
        <kbd className="rounded bg-slate-100 px-1 py-0.5 text-xs">⌘K</kbd> (ou{' '}
        <kbd className="rounded bg-slate-100 px-1 py-0.5 text-xs">Ctrl+K</kbd>) où que vous soyez.
      </span>
    ),
  },
  {
    target: '[data-tour="nav-companies"]',
    placement: 'right',
    content: 'Cliquez ici pour parcourir vos entreprises enrichies. Vous pouvez en créer manuellement ou laisser les scrapers les remplir.',
  },
  {
    target: '[data-tour="nav-dashboard"]',
    placement: 'right',
    content: 'Le dashboard affiche vos KPIs : entreprises totales, contacts valides, taux de succès des scrapers.',
  },
  {
    target: '[data-tour="dark-mode"]',
    placement: 'bottom',
    content: 'Mode clair/sombre — la préférence est sauvegardée localement.',
  },
  {
    target: '[data-tour="nav-settings"]',
    placement: 'right',
    content: (
      <span>
        Pensez à activer la double authentification dans les Paramètres pour sécuriser votre compte.
        <br />
        <br />
        Bon démarrage avec Axion CRM Pro ! 🚀
      </span>
    ),
  },
];

export function OnboardingTour(): JSX.Element | null {
  const queryClient = useQueryClient();
  const [run, setRun] = useState(false);

  const { data, isSuccess } = useQuery<AuthMeResponse>({
    queryKey: ['auth', 'me'],
    queryFn: async () => {
      const { data } = await api.get<AuthMeResponse>('/auth/me');
      return data;
    },
    staleTime: 5 * 60 * 1000,
    retry: false,
  });

  const completeMutation = useMutation({
    mutationFn: async () => {
      await api.post('/auth/onboarding/complete');
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['auth', 'me'] });
    },
  });

  // Démarrage automatique si user présent et tour non fait
  useEffect(() => {
    if (!isSuccess || !data?.user) return;
    if (data.user.onboarding_tour_completed_at === null) {
      // Petit délai pour laisser le DOM monter ses data-tour attributes
      const t = setTimeout(() => setRun(true), 800);
      return () => clearTimeout(t);
    }
  }, [isSuccess, data]);

  const handleCallback = (props: CallBackProps): void => {
    const { status } = props;
    const finishedStatuses: string[] = [STATUS.FINISHED, STATUS.SKIPPED];
    if (finishedStatuses.includes(status)) {
      setRun(false);
      completeMutation.mutate();
    }
  };

  if (!isSuccess) return null;

  return (
    <Joyride
      steps={STEPS}
      run={run}
      continuous
      showSkipButton
      showProgress
      callback={handleCallback}
      locale={{
        back: 'Précédent',
        close: 'Fermer',
        last: 'Terminer',
        next: 'Suivant',
        skip: 'Passer',
        open: 'Ouvrir',
      }}
      styles={{
        options: {
          primaryColor: '#7c3aed',
          textColor: '#0f172a',
          zIndex: 10000,
        },
      }}
    />
  );
}
