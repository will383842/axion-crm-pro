/**
 * AutoBreadcrumbs — dérive les breadcrumbs depuis la route courante (TanStack Router).
 *
 * Mapping path -> label humain pour les routes connues de routeTree.tsx.
 * Les segments inconnus (UUIDs, IDs) sont affichés tronqués.
 */
import { useRouterState } from '@tanstack/react-router';
import { Home } from 'lucide-react';
import { Breadcrumbs, type Crumb } from '@/components/ui';

/**
 * D23-006 — cette table ne couvrait que 18 chemins pour un routeur qui en
 * déclare bien plus. Tout ce qui n'y figure pas retombe sur `humanize()`, qui
 * rend le segment d'URL brut : mesure du 2026-08-22, DIX routes s'affichaient
 * « Media / Journalists / Tags / Audiences / Admin › Observability / Console ›
 * … » — de l'anglais et des mots d'URL, dans un produit français, sur des écrans
 * qui portent partout ailleurs des libellés français.
 *
 * Les libellés sont ceux de la barre latérale (`Sidebar.tsx`), à dessein :
 * l'utilisateur doit relire dans le fil d'Ariane le mot qu'il a cliqué dans le
 * menu. La désynchronisation est le VRAI risque de cette table — c'est
 * exactement ce qui s'était produit — d'où la garde
 * `tests/components/fil-d-ariane.test.tsx`, qui énumère mécaniquement les
 * routes de `routeTree.tsx` et rougit dès qu'une nouvelle arrive sans libellé.
 *
 * ⚠️ `/cold-email` et `/linkedin` sont CONSERVÉS. Le constat proposait de les
 * retirer comme « morts » ; ils ne le sont pas : les deux routes existent
 * toujours dans `routeTree.tsx` et restent joignables par URL — elles ont
 * seulement quitté la barre (`Sidebar.tsx:76`). Les retirer d'ici ferait
 * afficher « Cold email » et « Linkedin » à qui y arrive par un signet : on
 * remplacerait une entrée jugée inutile par le défaut même qu'on répare. Leur
 * sort se décide avec celui des routes (A-005), pas dans cette table.
 */
const LABELS: Record<string, string> = {
  // Lot 2 UX (02/10/2026) — mêmes mots que la barre latérale, partout.
  '/': 'Tableau de bord',
  // À traiter
  '/doublons': 'Doublons à vérifier',
  '/console/arbitrage': 'Personnes à rattacher',
  '/console/propositions': 'Propositions à valider',
  // Ma base
  '/companies': 'Entreprises',
  '/contacts': 'Contacts',
  '/console/contacts': 'Contacts',
  '/console/lettre-et-guide': 'Newsletter et guide',
  '/console/vivier': 'Candidats',
  // Presse
  '/media': 'Médias',
  '/journalists': 'Journalistes',
  '/presse/envois': 'Communiqués envoyés',
  // Réseaux
  '/federations': 'Fédérations et ordres',
  '/evenements': 'Événements',
  // Ciblage
  '/audiences': 'Audiences',
  '/audiences/new': 'Nouvelle audience',
  '/listes': 'Listes',
  // Alimenter la base
  '/campaigns': 'Collectes',
  '/campaigns/new': 'Nouvelle collecte',
  '/coverage': 'Carte de France',
  // Réglages
  '/settings': 'Paramètres',
  '/users': 'Utilisateurs',
  '/tags': 'Étiquettes',
  '/rgpd/requests': 'Demandes RGPD',
  // Technique
  '/scraper-runs': 'Historique des collectes',
  '/admin/observability': 'Santé du système',
  '/llm/router': 'Moteurs d’IA',
  '/llm/proxy-providers': 'Serveurs relais',
  '/llm/rotations': 'Rotation des accès',
  '/rgpd/ai-act': 'Registre de l’IA',
  '/audit-logs': 'Journal des actions',
  // Ma base (rangée là après la revue A09)
  '/international/roumanie': 'Entreprises en Roumanie',
  // 2026-08-23 — §8.2 de `10_NAVIGATION-CIBLE.md` : ces deux adresses ne
  // montent plus d'écran, elles redirigent vers `/pas-encore-livre?lot=L7`.
  // Leur libellé RESTE : le fil d'Ariane peut être rendu pendant le temps très
  // court où la route est résolue mais la redirection pas encore appliquée, et
  // un libellé absent y ferait apparaître « cold-email » en anglais brut.
  '/cold-email': 'E-mails à froid',
  '/linkedin': 'Prospection LinkedIn',
  '/pas-encore-livre': 'Pas encore livré',
  // Anciennes adresses (F7) : elles redirigent, mais restent des routes.
  '/crm': 'Contacts',
  '/analytics': 'Tableau de bord',
};

/**
 * Lot 2 UX (audit du 02/10/2026, P1-3) — les segments INTERMÉDIAIRES qui n'ont
 * AUCUN écran : `/llm`, `/rgpd`, `/admin`, `/international`, `/console`,
 * `/presse`, `/console/personnes`.
 *
 * Ils avaient un libellé (« LLM », « Console CRM », « Relations presse »…),
 * et c'est ce libellé qui les rendait CLIQUABLES (`hasRoute = Boolean(LABELS[acc])`)
 * — vers des routes qui n'existent pas : un clic sur « Console CRM » menait à
 * la page introuvable. Ils sont désormais OMIS du fil : « Accueil › Contacts »
 * plutôt que « Accueil › Console CRM › Contacts ». Un mot qui ne mène nulle
 * part et ne nomme aucun écran n'apprend rien à qui lit le fil.
 *
 * Garde : `tests/components/fil-d-ariane.test.tsx` exige que chaque segment
 * intermédiaire soit soit une route réelle (libellé), soit déclaré ici.
 */
const SEGMENTS_SANS_ECRAN: ReadonlySet<string> = new Set([
  '/llm',
  '/rgpd',
  '/admin',
  '/international',
  '/console',
  '/console/personnes',
  '/presse',
]);

/** Exposé pour la garde : segments omis du fil. */
export const SEGMENTS_OMIS: ReadonlySet<string> = SEGMENTS_SANS_ECRAN;

/** Table exposée pour la garde D23-006. Lecture seule : jamais mutée. */
export const LIBELLES_DE_CHEMIN: Readonly<Record<string, string>> = LABELS;

/**
 * Libellé humain d'un chemin complet, pour qui doit NOMMER l'écran courant
 * ailleurs que dans le fil d'Ariane — la région d'annonce de `RootLayout`
 * (D28-014) en particulier. Retombe sur le dernier segment humanisé, comme le
 * fil lui-même : deux façons de nommer le même écran finiraient par diverger.
 */
export function libelleDeChemin(pathname: string): string {
  if (pathname === '/' || pathname === '') return LABELS['/'] as string;
  const direct = LABELS[pathname];
  if (direct !== undefined) return direct;
  const segments = pathname.split('/').filter(Boolean);
  const parent = `/${segments.slice(0, -1).join('/')}`;
  if (segments.length > 1 && (LABELS[parent] !== undefined || SEGMENTS_SANS_ECRAN.has(parent))) return 'Fiche';
  return humanize(segments[segments.length - 1] ?? '');
}

function humanize(segment: string): string {
  // Un identifiant (UUID ou nombre) ne nomme rien pour qui lit le fil :
  // « #a1b2c3d4 » était affiché tel quel. On dit ce que c'est — une fiche.
  if (/^[0-9a-f]{8}-[0-9a-f]{4}/i.test(segment)) return 'Fiche';
  if (/^\d+$/.test(segment)) return 'Fiche';
  // Clé de personne (empreinte hexadécimale de l'adresse) : même chose.
  if (/^[0-9a-f]{16,}$/i.test(segment)) return 'Fiche';
  return segment.charAt(0).toUpperCase() + segment.slice(1).replace(/-/g, ' ');
}

export function AutoBreadcrumbs() {
  const pathname = useRouterState({ select: (s) => s.location.pathname });

  const crumbs: Crumb[] = [{ label: 'Accueil', to: '/', icon: <Home className="h-3 w-3" /> }];

  if (pathname === '/' || pathname === '') {
    return <Breadcrumbs items={crumbs} tone="inverse" />;
  }

  const segments = pathname.split('/').filter(Boolean);
  let acc = '';
  segments.forEach((seg, idx) => {
    acc += `/${seg}`;
    const isLast = idx === segments.length - 1;
    // Segment sans écran : omis (voir `SEGMENTS_SANS_ECRAN`).
    if (!isLast && SEGMENTS_SANS_ECRAN.has(acc)) return;
    // Sous un écran de liste, un segment sans libellé est l'identifiant d'une
    // fiche (`/media/$mediaId`, `/console/personnes/$personKey`…) : « Fiche ».
    const parent = acc.slice(0, acc.length - seg.length - 1);
    const sousUneListe = LABELS[parent] !== undefined || SEGMENTS_SANS_ECRAN.has(parent);
    const label = LABELS[acc] ?? (sousUneListe ? 'Fiche' : humanize(seg));
    // Seul un chemin de la table est une route réelle : lui seul devient un lien.
    const hasRoute = Boolean(LABELS[acc]);
    crumbs.push({
      label,
      ...(hasRoute && !isLast ? { to: acc } : {}),
    });
  });

  return <Breadcrumbs items={crumbs} tone="inverse" />;
}
