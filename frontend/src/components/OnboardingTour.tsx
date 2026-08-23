import { useEffect, useState, type ReactElement } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import Joyride, { EVENTS, STATUS, type CallBackProps, type Step } from 'react-joyride';
import { api } from '@/lib/api';

/**
 * D23-010 — DÉPLIE LA SECTION QUI PORTE LA CIBLE D'UNE ÉTAPE.
 *
 * Le défaut, mesuré le 2026-08-22 : la visite compte SEPT étapes, dont deux
 * visent des entrées de la barre latérale (`nav-companies`, `nav-settings`).
 * Or la barre n'ouvre QU'UNE section à la fois et masque les listes des autres
 * (`Sidebar.tsx` : `<ul className={… !deplie && 'hidden'}>`). Au premier
 * démarrage l'utilisateur est sur `/`, donc seule « Aujourd'hui » est ouverte :
 * les deux étapes visaient des éléments masqués — CINQ étapes sur sept
 * s'affichaient réellement.
 *
 * POURQUOI PAR LE DOM, et non par un magasin partagé : la section ouverte est un
 * état local de `Sidebar`. L'exposer pour qu'un autre composant l'ÉCRIVE, c'est
 * risquer de refermer la section que l'utilisateur venait d'ouvrir et de laisser
 * la barre dans un état différent d'avant la visite. Ici on n'écrit rien : on
 * actionne l'accordéon par son propre bouton, exactement comme l'utilisateur le
 * ferait — `Sidebar` reste seul maître de son état.
 *
 * ⚠️ AUCUNE TABLE DE CORRESPONDANCE cible → section n'est écrite ici : elle
 * ferait doublon avec `Sidebar.tsx` et divergerait à la première section
 * ajoutée. Le lien est LU dans le DOM — de la cible à sa liste, de la liste au
 * bouton qui la commande (`aria-controls`).
 */
export function ouvrirSectionDeLaCible(selecteurDeCible: string): void {
  if (typeof document === 'undefined') return;

  const cible = document.querySelector(selecteurDeCible);
  // Une liste repliée reste dans le DOM (`hidden` = `display:none`) : on la
  // trouve donc, et c'est précisément ce qui permet de la déplier avant l'étape.
  const liste = cible?.closest('ul[id^="nav-section-"]');
  if (liste === null || liste === undefined) return;

  const bouton = document.querySelector<HTMLButtonElement>(`button[aria-controls="${liste.id}"]`);
  // Déjà ouverte : ne surtout pas cliquer, cela la REFERMERAIT.
  if (bouton === null || bouton.getAttribute('aria-expanded') === 'true') return;

  bouton.click();
}

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
    content: "La barre latérale suit votre journée : Aujourd'hui, Contacts, Collecte, Pilotage, Conformité, Réglages. Un mot par notion, toujours au même endroit.",
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

export function OnboardingTour(): ReactElement | null {
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
    if (!isSuccess || !data?.user) return undefined;
    if (data.user.onboarding_tour_completed_at === null) {
      // Petit délai pour laisser le DOM monter ses data-tour attributes
      const t = setTimeout(() => setRun(true), 800);
      return () => clearTimeout(t);
    }
    return undefined;
  }, [isSuccess, data]);

  const handleCallback = (props: CallBackProps): void => {
    const { status, type, step } = props;

    // D23-010 — AVANT d'afficher l'étape, on déplie la section qui porte sa
    // cible. `EVENTS.TARGET_NOT_FOUND` est traité aussi : si Joyride a déjà
    // conclu à l'absence, déplier maintenant remet l'élément à l'écran pour le
    // retour arrière et pour la relecture de la visite.
    const typeDEvenement: string = type;
    if (
      (typeDEvenement === EVENTS.STEP_BEFORE || typeDEvenement === EVENTS.TARGET_NOT_FOUND) &&
      typeof step?.target === 'string'
    ) {
      ouvrirSectionDeLaCible(step.target);
    }

    const finishedStatuses: string[] = [STATUS.FINISHED, STATUS.SKIPPED];
    if (finishedStatuses.includes(status)) {
      setRun(false);
      // ⚠️ RESTE OUVERT, et c'est une décision qui n'est pas technique : le
      // constat D23-010 demandait aussi de REFUSER de marquer la visite faite
      // quand une cible a manqué. Tel quel, ce refus rejouerait la visite à
      // chaque connexion, sans jamais rien réparer — on remplacerait une visite
      // tronquée par une visite inévitable. Le sort d'une visite tronquée
      // (rejouer ? proposer ? renoncer ?) revient à Will.
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
