/**
 * 🔔 LA CLOCHE DE L'EN-TETE — constat D24-002 (S1).
 *
 * ═══ CE QU'IL Y AVAIT AVANT ═══
 *
 *     <IconButton label="Notifications" variant="ghost" size="sm" className="…">
 *       <Bell className="h-4 w-4" />
 *     </IconButton>
 *
 * Pas de `onClick`. Le clic REUSSIT — c'est un vrai bouton, il prend le focus,
 * il s'anime — et rien ne s'ouvre. La sonde de l'agent 24 attendait 4 s un
 * `[role="dialog"]` : expiration. Le §23.4 fait pourtant partir le parcours le
 * plus frequent du produit de « notification ou boite de reception ».
 *
 * Un bouton qui ne fait rien coute PLUS CHER qu'un bouton absent : celui-ci
 * promettait un canal d'entrants, et l'utilisateur qui ne voyait jamais rien
 * s'y ouvrir en concluait qu'il n'avait rien recu.
 *
 * ═══ TROIS MOITIES DE MECANISME, AUCUNE RELIEE ═══
 *
 * Rien n'a eu besoin d'etre invente cote serveur — tout existait deja :
 *
 *  1. `GET /notifications` est REEL (correctif B12-007) : il lit la table
 *     `notifications`, filtre sur l'espace ET sur la personne, trie par date
 *     decroissante, plafonne a 50, et rend `{ data, unread_count }`.
 *  2. `lib/echo.ts:85` ecoutait deja `.notification.created` et levait un toast.
 *  3. Aucun fichier du frontend n'appelait `/notifications`.
 *
 * Ce composant relie la troisieme.
 *
 * ═══ CE QUE CE PANNEAU NE FAIT PAS, ET IL LE DIT ═══
 *
 * `POST /notifications/{n}/read` et `POST /notifications/read-all` rendent
 * `501` (`notImplemented('10')`). Le panneau n'offre donc AUCUN bouton de
 * marquage : en poser un recreerait D24-002 un cran plus bas — un geste qui
 * echoue en silence, ou pire, un « Erreur » anonyme sur une fonction qui
 * n'existe simplement pas. Il ECRIT que la fonction n'est pas disponible.
 *
 * ⚠️ LE JOUR OU LE SERVEUR IMPLEMENTE CES DEUX ROUTES : retirer la mention,
 * poser les boutons, et RETOURNER l'assertion de
 * `tests/components/cloche-notifications.test.tsx` (« marquage comme lu »).
 * Ne pas la contourner : c'est elle qui empeche le bouton mort de revenir.
 *
 * ═══ `degraded` : 200 N'EST PAS « IL N'Y A RIEN » ═══
 *
 * Le controleur attrape ses propres exceptions et rend `200` avec
 * `{ data: [], unread_count: 0, degraded: true }`. Cote React Query c'est un
 * SUCCES : sans lire ce drapeau, le panneau afficherait « Aucune notification »
 * au moment precis ou le serveur vient d'echouer a les lire. C'est exactement
 * le defaut D25-001, deguise en 200.
 */
import { useEffect, useRef, useState } from 'react';
import { Bell } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';

import { IconButton, QueryErrorState } from '@/components/ui';
import { api } from '@/lib/api';

export interface LigneNotification {
  id: string | number;
  type: string;
  title: string;
  body: string | null;
  action_url: string | null;
  read_at: string | null;
  created_at: string;
}

export interface ReponseNotifications {
  data: LigneNotification[];
  unread_count: number;
  /** Posé par le serveur quand SA lecture a échoué, sous un 200. */
  degraded?: boolean;
}

/** Clé partagée : `lib/echo.ts` pourra l'invalider sur `.notification.created`. */
export const CLE_NOTIFICATIONS = ['notifications'] as const;

function formaterDate(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export function NotificationsBell() {
  const [ouvert, setOuvert] = useState(false);
  const enveloppe = useRef<HTMLDivElement | null>(null);

  const requete = useQuery<ReponseNotifications>({
    queryKey: CLE_NOTIFICATIONS,
    queryFn: async () => (await api.get<ReponseNotifications>('/notifications')).data,
    staleTime: 60_000,
  });

  // Fermeture au clic dehors + `Echap`. Même contrat que `DropdownMenu`, mais
  // le contenu est un panneau (`role="dialog"`) et non une liste d'actions :
  // on ne réutilise pas `DropdownMenu`, dont chaque enfant est un `menuitem`.
  useEffect(() => {
    if (!ouvert) return;
    const auClic = (e: MouseEvent) => {
      if (enveloppe.current && !enveloppe.current.contains(e.target as Node)) setOuvert(false);
    };
    const auClavier = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOuvert(false);
    };
    document.addEventListener('mousedown', auClic);
    document.addEventListener('keydown', auClavier);
    return () => {
      document.removeEventListener('mousedown', auClic);
      document.removeEventListener('keydown', auClavier);
    };
  }, [ouvert]);

  const nonLues = requete.data?.unread_count ?? 0;

  /**
   * Le compteur entre dans le NOM ACCESSIBLE, pas seulement dans une pastille
   * coloree : une pastille ne dit rien a une lectrice d'ecran, et c'est
   * precisement l'information pour laquelle on ouvre la cloche.
   */
  const nomAccessible =
    nonLues === 0
      ? 'Notifications'
      : `Notifications (${nonLues} non lue${nonLues > 1 ? 's' : ''})`;

  // Même condition que partout ailleurs dans le produit (cf. `QueryErrorState`) :
  // React Query v5 GARDE la dernière réponse réussie quand un rafraîchissement
  // échoue — effacer la liste dans ce cas serait une régression, pas un correctif.
  const echec = requete.error !== null && requete.data === undefined;
  const degrade = requete.data?.degraded === true;
  const lignes = requete.data?.data ?? [];

  return (
    <div ref={enveloppe} className="relative">
      <IconButton
        label={nomAccessible}
        onClick={() => setOuvert((v) => !v)}
        aria-haspopup="dialog"
        aria-expanded={ouvert}
        variant="ghost"
        size="sm"
        className="relative text-sidebar-fg hover:bg-white/10 hover:text-white"
      >
        <Bell className="h-4 w-4" />
        {nonLues > 0 ? (
          // `aria-hidden` : le nombre est déjà dans le nom accessible du bouton.
          // Le laisser lisible deux fois ferait annoncer « 3 Notifications 3 non lues ».
          <span
            aria-hidden
            className="absolute -right-0.5 -top-0.5 inline-flex min-w-[1rem] items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-semibold leading-4 text-white"
          >
            {nonLues > 9 ? '9+' : nonLues}
          </span>
        ) : null}
      </IconButton>

      {ouvert ? (
        <div
          role="dialog"
          aria-label="Notifications"
          className="absolute right-0 z-40 mt-1.5 w-[22rem] max-w-[calc(100vw-2rem)] rounded-xl bg-white p-3 shadow-[var(--shadow-popover)] ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800"
        >
          <div className="mb-2 flex items-center justify-between gap-2">
            <h2 className="text-sm font-semibold text-slate-900 dark:text-white">Notifications</h2>
            <button
              type="button"
              onClick={() => setOuvert(false)}
              className="rounded-md px-2 py-0.5 text-xs text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-800 dark:hover:text-white"
            >
              Fermer le panneau
            </button>
          </div>

          <div className="max-h-[60vh] overflow-y-auto">
            {echec ? (
              <QueryErrorState
                error={requete.error}
                contexte="vos notifications"
                onRetry={() => void requete.refetch()}
              />
            ) : requete.isPending ? (
              <p className="px-1 py-6 text-center text-sm text-slate-500">
                Chargement des notifications…
              </p>
            ) : degrade ? (
              /* 200, mais le serveur a posé son drapeau : la liste est vide
                 SANS que cela veuille dire qu'il n'y a rien. On ne conclut pas. */
              <p
                role="status"
                className="rounded-lg border border-amber-300 bg-amber-50/60 px-3 py-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300"
              >
                Le serveur n’a pas pu lire vos notifications. Cette liste n’est donc pas
                fiable&nbsp;: elle est vide parce que la lecture a échoué, pas parce qu’il n’y a
                rien.
              </p>
            ) : lignes.length === 0 ? (
              <p className="px-1 py-6 text-center text-sm text-slate-500">
                Aucune notification pour l’instant.
              </p>
            ) : (
              <ul className="flex flex-col gap-1">
                {lignes.map((ligne) => {
                  const contenu = (
                    <>
                      <div className="flex items-baseline justify-between gap-2">
                        <span className="text-sm font-medium text-slate-900 dark:text-white">
                          {ligne.title}
                        </span>
                        <span className="shrink-0 font-mono text-[11px] text-slate-400">
                          {formaterDate(ligne.created_at)}
                        </span>
                      </div>
                      {ligne.body === null || ligne.body === '' ? null : (
                        <p className="mt-0.5 text-xs text-slate-600 dark:text-slate-300">
                          {ligne.body}
                        </p>
                      )}
                    </>
                  );

                  return (
                    <li
                      key={String(ligne.id)}
                      className={
                        ligne.read_at === null
                          ? 'rounded-lg border-l-2 border-brand-500 bg-slate-50 px-2.5 py-2 dark:bg-slate-800/60'
                          : 'rounded-lg px-2.5 py-2'
                      }
                    >
                      {ligne.action_url === null || ligne.action_url === '' ? (
                        <div>{contenu}</div>
                      ) : (
                        /* `action_url` vient du serveur : c'est une adresse
                           quelconque, pas un chemin typé du routeur. Un `<a>`
                           ordinaire est la seule forme honnête. */
                        <a
                          href={ligne.action_url}
                          className="block rounded-md hover:underline"
                          onClick={() => setOuvert(false)}
                        >
                          {contenu}
                        </a>
                      )}
                    </li>
                  );
                })}
              </ul>
            )}
          </div>

          {/*
            La seule chose que ce panneau AVOUE. `markRead` / `markAllRead`
            rendent 501 : plutôt qu'un bouton qui échoue en silence, on écrit
            que le geste n'existe pas encore. Voir l'en-tête de fichier.
          */}
          <p className="mt-2 border-t border-slate-100 pt-2 text-[11px] text-slate-500 dark:border-slate-800">
            Marquer une notification comme lue n’est pas encore disponible sur ce serveur.
          </p>
        </div>
      ) : null}
    </div>
  );
}
