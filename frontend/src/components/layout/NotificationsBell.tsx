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
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { IconButton, QueryErrorState, StatusPill, type StatusTone } from '@/components/ui';
import { api } from '@/lib/api';
import { MARQUE_DU_PRODUIT, type Produit } from '@/features/socle/produit/useProduitCourant';

export interface LigneNotification {
  id: string | number;
  type: string;
  title: string;
  body: string | null;
  action_url: string | null;
  read_at: string | null;
  created_at: string;
  /**
   * DM0-COQ — contrat d'Envoi (`Notification` de `backend/openapi/envoi/commun.yaml`).
   * Absents du serveur d'aujourd'hui : la ligne s'affiche alors comme avant,
   * sans pastille.
   */
  produit?: Produit;
  /** Chemin de l'écran à ouvrir ; prime sur `action_url` (ancien nom). */
  lien?: string | null;
  niveau?: NiveauNotification;
}

export type NiveauNotification = 'a_faire' | 'attention' | 'nouveau' | 'fait';

/** L'ÉTAT d'une alerte : couleurs de SENS par `StatusPill`, jamais l'identité du produit. */
const ETAT_DU_NIVEAU: Record<NiveauNotification, { libelle: string; ton: StatusTone }> = {
  a_faire: { libelle: 'À faire', ton: 'danger' },
  attention: { libelle: 'Attention', ton: 'warning' },
  nouveau: { libelle: 'Nouveau', ton: 'info' },
  fait: { libelle: 'Fait', ton: 'success' },
};

const PRODUITS_CONNUS: ReadonlySet<string> = new Set(['crm', 'envoi', 'commun']);

/**
 * DM0-COQ (ADR-13 § 7) — la pastille d'IDENTITÉ d'une alerte : CARRÉE, pour ne
 * jamais la confondre avec une pastille d'état (ronde). Sa couleur vient de
 * son propre `data-produit`.
 */
function MarqueProduit({ produit }: { produit: Produit }) {
  return (
    <span
      data-marque-produit
      data-produit={produit}
      className="inline-flex h-5 shrink-0 items-center rounded-sm bg-produit-bandeau px-1.5 text-xs font-semibold text-white"
    >
      {MARQUE_DU_PRODUIT[produit]}
    </span>
  );
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

/** Un chemin interne de l'application (`/…`, mais pas `//…`), sinon `null`. */
export function cheminInterne(lien: unknown): string | null {
  return typeof lien === 'string' && lien.startsWith('/') && !lien.startsWith('//') ? lien : null;
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

  const queryClient = useQueryClient();
  const toutLire = useMutation({
    mutationFn: async () => {
      await api.post('/notifications/read-all');
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: CLE_NOTIFICATIONS }),
  });

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
            className="absolute -right-0.5 -top-0.5 inline-flex min-w-[1rem] items-center justify-center rounded-full bg-rose-500 px-1 text-xs font-semibold leading-4 text-white"
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
                  const produit = typeof ligne.produit === 'string' && PRODUITS_CONNUS.has(ligne.produit) ? ligne.produit : null;
                  const etat = ligne.niveau !== undefined ? ETAT_DU_NIVEAU[ligne.niveau] : undefined;
                  // `lien` (DM0-COQ) vient du serveur : seul un chemin INTERNE
                  // est suivi (comme `dernierePage.ts`). `action_url` garde
                  // son comportement d'avant DM0-COQ.
                  const lien = cheminInterne(ligne.lien) ?? ligne.action_url;
                  const contenu = (
                    <>
                      {produit !== null ? (
                        <div className="mb-1 flex items-center gap-1.5">
                          {etat !== undefined ? (
                            <span data-pastille-etat className="inline-flex rounded-full">
                              <StatusPill tone={etat.ton}>{etat.libelle}</StatusPill>
                            </span>
                          ) : null}
                          <MarqueProduit produit={produit} />
                        </div>
                      ) : null}
                      <div className="flex items-baseline justify-between gap-2">
                        <span className="text-sm font-medium text-slate-900 dark:text-white">
                          {ligne.title}
                        </span>
                        <span className="shrink-0 font-mono text-xs text-slate-400">
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
                      {lien === null || lien === undefined || lien === '' ? (
                        <div>{contenu}</div>
                      ) : (
                        /* `action_url` vient du serveur : c'est une adresse
                           quelconque, pas un chemin typé du routeur. Un `<a>`
                           ordinaire est la seule forme honnête. */
                        <a
                          href={lien}
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

          {nonLues > 0 ? (
            <div className="mt-2 border-t border-slate-100 pt-2 dark:border-slate-800">
              <button
                type="button"
                onClick={() => toutLire.mutate()}
                disabled={toutLire.isPending}
                className="rounded-md px-2 py-1 text-xs font-medium text-brand-700 hover:bg-slate-100 disabled:opacity-50 dark:text-brand-300 dark:hover:bg-slate-800"
              >
                Tout marquer comme lu
              </button>
              {toutLire.isError ? (
                <p role="alert" className="mt-1 text-xs text-rose-700">
                  Le marquage a échoué. Veuillez réessayer.
                </p>
              ) : null}
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}
