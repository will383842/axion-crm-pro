import type { ReactNode } from 'react';
import { Link } from '@tanstack/react-router';
import { CalendarClock, CopyCheck, Scale } from 'lucide-react';
import { Skeleton, cn } from '@/components/ui';
import {
  LIBELLES_COMPTEUR,
  formaterNombre,
  useCompteursATraiter,
  type CompteursATraiter,
} from '@/features/a-traiter/compteurs';

const CHIFFRE_INDISPONIBLE = 'Chiffre indisponible pour le moment';

/** Ce que dit la carte « Événements à relancer » aux lecteurs d'écran. */
export function libelleRelances(n: number): string {
  return n === 1 ? '1 événement à relancer' : `${formaterNombre(n)} événements à relancer`;
}

interface ContenuCarteProps {
  icone: ReactNode;
  /** Fond et couleur de la pastille d'icône. */
  ton: string;
  libelle: string;
  /** `undefined` = en cours de chargement ; `null` = le serveur n'a pas pu compter. */
  valeur: number | null | undefined;
}

/** Les classes d'une carte « À faire » — le lien lui-même, cliquable en entier. */
const CLASSE_CARTE = cn(
  'group flex min-w-0 flex-col gap-3 rounded-2xl bg-white p-5 text-slate-900 ring-1 ring-slate-200/70',
  'shadow-[var(--shadow-card)] transition-[transform,box-shadow] hover:-translate-y-0.5 hover:shadow-[var(--shadow-card-hover)]',
);

/**
 * Le nom accessible du lien : la phrase avec le nombre exact (« 201 doublons
 * à vérifier »), ou le libellé et la raison quand le chiffre manque.
 */
function nomAccessible(libelle: string, valeur: number | null | undefined, direNombre: (n: number) => string): string {
  if (typeof valeur === 'number') return `${direNombre(valeur)} — ouvrir`;
  if (valeur === null) return `${libelle} : ${CHIFFRE_INDISPONIBLE.toLowerCase()} — ouvrir`;
  return `${libelle} — ouvrir`;
}

/**
 * Le contenu d'une grande carte : icône, grand nombre, libellé, « Ouvrir → ».
 * 0 se lit « À jour » ; `null` s'écrit « — » avec sa raison, jamais 0.
 */
function ContenuCarte({ icone, ton, libelle, valeur }: ContenuCarteProps) {
  return (
    <>
      <span className={cn('flex h-11 w-11 items-center justify-center rounded-xl', ton)} aria-hidden>
        {icone}
      </span>
      {valeur === undefined ? (
        <Skeleton className="h-10 w-24" />
      ) : (
        <span className="text-4xl font-bold leading-none tracking-tight tabular-nums">
          {valeur === null ? '—' : formaterNombre(valeur)}
        </span>
      )}
      {valeur === null ? <span className="-mt-1 text-xs text-slate-500">{CHIFFRE_INDISPONIBLE}</span> : null}
      <span className="flex flex-wrap items-center justify-between gap-2 text-sm font-semibold">
        <span>{libelle}</span>
        {valeur === 0 ? (
          <span className="text-emerald-700">À jour</span>
        ) : (
          <span className="text-brand-600 group-hover:text-brand-700">Ouvrir →</span>
        )}
      </span>
    </>
  );
}

/**
 * « À faire » — les files qui attendent un geste, en grandes cartes.
 *
 * Chiffres : `GET /crm/a-traiter/compteurs` (même requête que les pastilles du
 * menu, donc même cache et même cadence), calculés par les MÊMES constructeurs
 * que les écrans : le chiffre de la carte = le total de l'écran.
 *
 *  - « Personnes à rattacher » n'existe que console ouverte (comme son entrée
 *    de menu), et vaut `null` dans le vivier.
 *  - « Événements à relancer » (onglet « Relances à faire » de l'écran
 *    Événements) n'apparaît que si le serveur envoie le chiffre : une image
 *    antérieure ne le calcule pas, et un « — » permanent ferait croire à une
 *    panne passagère. Si le point d'API ÉCHOUE, en revanche, la carte reste
 *    et dit « — » : une panne n'est pas une absence de fonctionnalité. Pendant le chargement, on ne sait pas encore : la carte
 *    est réservée (squelette) pour que la grille ne saute pas.
 */
export function AFaire({ consoleOuverte }: { consoleOuverte: boolean }) {
  const { data, isPending } = useCompteursATraiter(consoleOuverte);
  // Échec (pas de réessai) : chaque compteur devient « — », y compris les
  // relances. Une PANNE ne fait pas disparaître la carte : seule une réponse
  // RÉUSSIE sans le chiffre (fonctionnalité absente du serveur) la retire.
  const compteurs: CompteursATraiter | undefined = isPending
    ? undefined
    : (data ?? { doublons: null, a_rattacher: null, relances: null });
  const montrerRelances = compteurs === undefined || compteurs.relances !== undefined;

  return (
    <section aria-labelledby="titre-a-faire" className="flex flex-col gap-3.5">
      <h2 id="titre-a-faire" className="text-lg font-bold text-slate-900">
        À faire
      </h2>
      <div className="grille-auto gap-4">
        <Link
          to="/doublons"
          className={CLASSE_CARTE}
          data-testid="a-faire-doublons"
          aria-label={nomAccessible('Doublons à vérifier', compteurs?.doublons, LIBELLES_COMPTEUR.doublons)}
        >
          <ContenuCarte
            icone={<CopyCheck className="h-5 w-5" />}
            ton="bg-orange-50 text-orange-700"
            libelle="Doublons à vérifier"
            valeur={compteurs?.doublons}
          />
        </Link>
        <Link
          to="/console/arbitrage"
          className={CLASSE_CARTE}
          data-testid="a-faire-a-rattacher"
          aria-label={nomAccessible('Personnes à rattacher', compteurs?.a_rattacher, LIBELLES_COMPTEUR.a_rattacher)}
        >
          <ContenuCarte
            icone={<Scale className="h-5 w-5" />}
            ton="bg-indigo-50 text-indigo-700"
            libelle="Personnes à rattacher"
            valeur={compteurs?.a_rattacher}
          />
        </Link>
        {montrerRelances ? (
          <Link
            to="/evenements"
            search={{ onglet: 'relances' }}
            className={CLASSE_CARTE}
            data-testid="a-faire-relances"
            aria-label={nomAccessible('Événements à relancer', compteurs?.relances, libelleRelances)}
          >
            <ContenuCarte
              icone={<CalendarClock className="h-5 w-5" />}
              ton="bg-emerald-50 text-emerald-700"
              libelle="Événements à relancer"
              valeur={compteurs?.relances}
            />
          </Link>
        ) : null}
      </div>
    </section>
  );
}
