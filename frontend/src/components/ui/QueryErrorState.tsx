/**
 * `QueryErrorState` — l'écran DIT ce qui s'est passé, au lieu de dire « rien ».
 *
 * ═══ LE DÉFAUT QU'IL FERME (D25-001, S1) ═══
 *
 * Mesure de la passe P6 : `grep -rn isError src/features` → 9 occurrences dans
 * 4 fichiers, sur 35 écrans. Les 31 autres ne regardent jamais l'échec de leur
 * `useQuery` ; ils lisent `data?.data ?? []`, obtiennent `[]`, et affichent leur
 * état vide. Résultat mesuré, identique sur les 31 :
 *
 *     403 (droits manquants)  →  « Rien à arbitrer »
 *     500 (base tombée)       →  « Rien à arbitrer »
 *     200 + liste vide        →  « Rien à arbitrer »
 *
 * Le coût n'est pas cosmétique. Un opérateur sans le rôle `admin` ouvre
 * « Utilisateurs », lit « Aucun utilisateur — Invite ton premier
 * collaborateur », et croit l'équipe vide. Un vivier de candidatures que
 * l'API refuse s'affiche « À qualifier : 0 » : les candidatures ne sont jamais
 * traitées, et personne ne signale de panne puisque l'écran ne signale rien.
 *
 * ═══ CE QUE CE COMPOSANT GARANTIT ═══
 *
 *  1. Chaque nature d'échec (`qualifierErreur`, `src/lib/api.ts`) a SON titre et
 *     SA description. Un refus ne se lit pas comme une panne.
 *  2. Le code HTTP est AFFICHÉ. C'est la seule chose que l'utilisateur peut
 *     recopier au support ; sans lui, chaque signalement devient une enquête.
 *     Il tranche aussi entre 403 et 500 même si quelqu'un réécrit les libellés.
 *  3. « Réessayer » n'apparaît QUE quand réessayer a un sens. Sous 403, le
 *     serveur a répondu et il a dit non : un bouton qui redemande la même chose
 *     au même serveur avec les mêmes droits est un leurre.
 *  4. `role="alert"` : une lectrice d'écran doit apprendre l'échec sans avoir à
 *     relire la page. Un état vide, lui, n'est PAS une alerte — d'où la
 *     séparation d'avec `EmptyState`.
 *
 * ═══ COMMENT L'UTILISER DANS UN ÉCRAN ═══
 *
 *     const echec = list.error !== null && list.data === undefined;
 *     …
 *     {echec ? (
 *       <QueryErrorState error={list.error} contexte="la file d'arbitrage"
 *                        onRetry={() => void list.refetch()} />
 *     ) : list.isLoading ? <Skeleton/> : rows.length === 0 ? <EmptyState/> : <Liste/>}
 *
 * ⚠️ `data === undefined` fait partie de la condition, et ce n'est pas un
 * détail : React Query v5 CONSERVE la dernière page réussie quand un
 * rafraîchissement échoue. Effacer l'écran dans ce cas ferait PERDRE à
 * l'opérateur des données qu'il avait sous les yeux — on remplacerait un
 * mensonge par une régression.
 */
import { AlertTriangle, Lock, PlugZap, SearchX, ServerCrash } from 'lucide-react';
import type { ReactNode } from 'react';

import { qualifierErreur, type NatureErreurApi } from '@/lib/api';
import { Button } from './Button';

interface Message {
  titre: string;
  /** `contexte` = ce que l'écran essayait de charger, ex. « la file d'arbitrage ». */
  description: (contexte: string) => string;
  icone: ReactNode;
  /** Réessayer a-t-il une chance d'aboutir ? */
  reessayable: boolean;
  /** Teinte du cadre — le rouge est réservé à ce qui est cassé. */
  ton: 'ambre' | 'rouge';
}

const CLASSE_ICONE = 'h-9 w-9';

/**
 * ⚠️ Aucune de ces phrases ne doit contenir un mot d'une AUTRE nature : les
 * gardes de `tests/screens/etats-erreur.test.tsx` assurent, sous 500, que
 * « pas les droits » est ABSENT de l'écran, et réciproquement. Deux textes qui
 * se recouvrent, c'est le défaut D25-001 qui revient par la fenêtre.
 */
const MESSAGES: Record<NatureErreurApi, Message> = {
  refus: {
    // « pas les droits » : la phrase nomme la CAUSE (un rôle manquant) et le
    // GESTE (aller le demander). Un « Accès refusé » seul laissait
    // l'utilisateur sans porte de sortie.
    //
    // ⚠️ Les gardes cherchent la sous-chaîne SANS ACCENT « pas les droits » :
    // ne la casse pas en réécrivant ce titre.
    titre: 'Vous n’avez pas les droits sur cette vue',
    description: (contexte) =>
      `Le serveur a bien répondu, et il a refusé : votre compte n’est pas autorisé à consulter ${contexte}. ` +
      'Ce n’est donc pas une liste vide — demandez le rôle manquant à un administrateur.',
    icone: <Lock className={CLASSE_ICONE} />,
    // Redemander la même chose au même serveur avec les mêmes droits ne peut
    // pas aboutir. Proposer le bouton serait mentir une seconde fois.
    reessayable: false,
    ton: 'ambre',
  },
  session: {
    titre: 'Votre session n’est plus valide',
    description: (contexte) =>
      `Reconnectez-vous pour consulter ${contexte}. La redirection vers la page de connexion part normalement toute seule.`,
    icone: <Lock className={CLASSE_ICONE} />,
    reessayable: false,
    ton: 'ambre',
  },
  introuvable: {
    // « introuvable » ≠ « vide » : l'un veut dire « mauvais lien, ou fiche
    // supprimée », l'autre « la fiche existe et n'a rien dedans ». Deux gestes
    // différents. Sous-chaîne cherchée par la garde : « introuvable ».
    titre: 'Ressource introuvable',
    description: (contexte) =>
      `Le serveur ne connaît pas ${contexte}. Le lien est peut-être périmé, ou la fiche a été supprimée.`,
    icone: <SearchX className={CLASSE_ICONE} />,
    reessayable: false,
    ton: 'ambre',
  },
  requete: {
    titre: 'Demande rejetée par le serveur',
    description: (contexte) =>
      `Le serveur a refusé la demande envoyée pour ${contexte} : filtre invalide, ou trop d’appels en peu de temps. Modifiez les filtres, puis réessayez.`,
    icone: <AlertTriangle className={CLASSE_ICONE} />,
    reessayable: true,
    ton: 'ambre',
  },
  panne: {
    // On nomme le coupable, qui n'est ni l'utilisateur, ni l'absence de
    // données. Sous-chaîne cherchée par la garde : « serveur est en panne ».
    titre: 'Le serveur est en panne',
    description: (contexte) =>
      `Le serveur a échoué en chargeant ${contexte}. On ne sait donc PAS s’il y a des données : rien n’a pu être lu. ` +
      'Réessayez dans un instant, et signalez-le si cela persiste.',
    icone: <ServerCrash className={CLASSE_ICONE} />,
    reessayable: true,
    ton: 'rouge',
  },
  reseau: {
    // Sous-chaîne cherchée par la garde : « injoignable ».
    titre: 'Serveur injoignable',
    description: (contexte) =>
      `Aucune réponse pour ${contexte} : le serveur n’a rien renvoyé du tout (réseau coupé, VPN, ou délai de 30 s dépassé). Vérifiez la connexion, puis réessayez.`,
    icone: <PlugZap className={CLASSE_ICONE} />,
    reessayable: true,
    ton: 'rouge',
  },
  inconnue: {
    titre: 'Erreur inattendue',
    description: (contexte) =>
      `Le chargement s’est interrompu (${contexte}) sans que l’on sache pourquoi. Réessayez ; si cela recommence, signalez-le.`,
    icone: <AlertTriangle className={CLASSE_ICONE} />,
    reessayable: true,
    ton: 'rouge',
  },
};

const TONS = {
  ambre: {
    cadre: 'border-amber-300 bg-amber-50/60 dark:border-amber-900 dark:bg-amber-950/30',
    icone: 'text-amber-600 dark:text-amber-400',
    titre: 'text-amber-900 dark:text-amber-200',
    corps: 'text-amber-800 dark:text-amber-300/90',
  },
  rouge: {
    cadre: 'border-rose-300 bg-rose-50/60 dark:border-rose-900 dark:bg-rose-950/30',
    icone: 'text-rose-600 dark:text-rose-400',
    titre: 'text-rose-900 dark:text-rose-200',
    corps: 'text-rose-800 dark:text-rose-300/90',
  },
} as const;

export interface QueryErrorStateProps {
  /** Le rejet tel que React Query le donne (`query.error`). Non interprété par l'écran. */
  error: unknown;
  /**
   * Ce que l'écran essayait de charger, au complément d'objet :
   * « la file d'arbitrage », « les journaux d'audit ». Une phrase qui nomme la
   * chose manquante vaut mieux qu'un « une erreur est survenue » anonyme.
   */
  contexte: string;
  /** Branché sur `query.refetch()`. Ignoré quand réessayer ne peut pas aboutir. */
  onRetry?: () => void;
}

export function QueryErrorState({ error, contexte, onRetry }: QueryErrorStateProps) {
  const { nature, status } = qualifierErreur(error);
  const message = MESSAGES[nature];
  const ton = TONS[message.ton];

  return (
    <div
      // `alert` et non `status` : l'échec doit interrompre, pas se glisser dans
      // le flux. Un état VIDE, lui, n'a rien d'une alerte — c'est pourquoi ce
      // composant est distinct d'`EmptyState`.
      role="alert"
      className={`flex flex-col items-center justify-center rounded-xl border-2 border-dashed px-6 py-12 text-center ${ton.cadre}`}
    >
      <div className={`mb-3 ${ton.icone}`} aria-hidden>
        {message.icone}
      </div>
      <h2 className={`text-lg font-semibold ${ton.titre}`}>{message.titre}</h2>
      <p className={`mt-1 max-w-xl text-sm ${ton.corps}`}>{message.description(contexte)}</p>

      {/* Le code HTTP, en clair. C'est ce que l'utilisateur recopie au support,
          et c'est ce qui distingue 403 de 500 quoi qu'il advienne des libellés.
          Sans réponse du serveur, on l'écrit — plutôt qu'un « code 0 » qui
          laisserait croire que le serveur a parlé. */}
      <p className={`mt-3 font-mono text-xs opacity-80 ${ton.corps}`}>
        {status === null ? 'aucune réponse du serveur' : `code HTTP ${status}`}
      </p>

      {message.reessayable && onRetry !== undefined ? (
        <div className="mt-5">
          <Button variant="secondary" size="sm" onClick={onRetry}>
            Réessayer
          </Button>
        </div>
      ) : null}
    </div>
  );
}
