/**
 * D25-001 — UN REFUS DE DROITS N'EST PAS UNE PANNE, ET AUCUN DES DEUX N'EST « IL N'Y A RIEN ».
 *
 * ═══ LE DÉFAUT MESURÉ ═══
 *
 * Passe P6 de l'audit 360 : `grep -rn isError src/features` rend **9 occurrences
 * dans 4 fichiers**, sur **35 écrans**. Les 31 autres ne consultent jamais l'état
 * d'erreur de leur `useQuery` : ils lisent `query.data?.data ?? []`, obtiennent
 * un tableau vide, et concluent « il n'y a rien à montrer ».
 *
 * Conséquence exacte, la même sur les 31 écrans :
 *
 *   • le serveur répond **403** (l'opérateur n'a pas le rôle) → « Rien à arbitrer »
 *   • le serveur répond **500** (la base est tombée)          → « Rien à arbitrer »
 *   • le serveur répond **200** avec une liste vide            → « Rien à arbitrer »
 *
 * Trois situations qui appellent trois gestes OPPOSÉS — demander un droit,
 * attendre/alerter, ne rien faire — rendues par une chaîne de caractères
 * STRICTEMENT IDENTIQUE. C'est ce que cette garde interdit.
 *
 * ═══ CE QUE LA GARDE VÉRIFIE, ÉCRAN PAR ÉCRAN ═══
 *
 *  1. TÉMOIN — sous 200 + liste vide, l'écran DIT TOUJOURS qu'il n'y a rien.
 *     Sans ce cas, la garde passerait au vert sur un écran qui afficherait
 *     « erreur » en toutes circonstances : ce serait un autre mensonge, aussi
 *     coûteux. Il assure aussi que la garde ne se contente pas d'un écran vide
 *     ou d'un composant qui ne rend rien.
 *  2. Sous 403, l'écran nomme le REFUS DE DROITS, et l'état vide DISPARAÎT.
 *  3. Sous 500, l'écran nomme la PANNE SERVEUR, l'état vide disparaît, et le
 *     texte diffère de celui du 403.
 *  4. Les compteurs fabriqués (« 0 événement(s) », « À qualifier : 0 ») ne
 *     s'affichent PAS quand la requête qui les alimente a échoué : un zéro
 *     inventé est le même mensonge que l'état vide, en plus discret.
 *
 * ⚠️ Toutes les sous-chaînes cherchées sont SANS LETTRE ACCENTUÉE. Une garde
 * qui cherche « refusé » ou « événement » se casse sur la première
 * normalisation Unicode ou le premier copier-coller, et rougit pour rien.
 *
 * ═══ PÉRIMÈTRE ═══
 *
 * 6 vues représentatives des familles où le défaut coûte le plus cher :
 *   - console v2 (3 écrans) : la file d'arbitrage, le vivier, le hub contacts ;
 *   - administration (2 écrans) : utilisateurs et journaux d'audit — là où un
 *     403 est le cas NOMINAL pour un opérateur non-admin, donc là où
 *     « Aucun utilisateur » se lit tous les jours.
 *   - accueil (1 bloc) : `ActivityFeed`, constat **P5-35-012** (S3). Mesure du
 *     2026-08-22 : la branche d'erreur et la branche « base vide » y étaient la
 *     MÊME (`isError || items.length === 0`), si bien qu'un 403 sur
 *     `GET /audit-logs` affichait « Activité bientôt disponible » — la promesse
 *     que le CRM commence à travailler, sur l'écran même qu'on ouvre pour
 *     savoir s'il travaille. Ce n'est pas un écran de route : c'est un bloc de
 *     l'accueil, et il est monté seul ici — la garde porte sur SES branches de
 *     rendu, pas sur la composition du tableau de bord.
 * Les écrans restants sont à porter ; le composant partagé est écrit pour ça.
 */
import { describe, it, expect } from 'vitest';
import type { ReactElement } from 'react';
// `waitFor` seul, sans `screen` : les assertions portent sur le TEXTE COMPLET
// de la page (`document.body.textContent`), et non sur un élément nommé. C'est
// délibéré — le défaut D25-001 est une PHRASE identique d'un cas à l'autre, pas
// un nœud manquant, et seule l'absence d'une chaîne dans tout l'écran le prouve.
import { waitFor } from '@testing-library/react';

import { ArbitragePage } from '@/features/crm-console/ArbitragePage';
import { CandidatesPage } from '@/features/crm-console/CandidatesPage';
import { ContactsHubPage } from '@/features/crm-console/ContactsHubPage';
import { UsersPage } from '@/features/users/UsersPage';
import { AuditLogsPage } from '@/features/rgpd/AuditLogsPage';
// P5-35-012 — le fil d'activité de l'accueil. Un bloc, pas un écran de route.
import { ActivityFeed } from '@/features/dashboard/components/ActivityFeed';

import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, getJson, http, HttpResponse, type HttpHandler } from '../msw/handlers';

// ---------------------------------------------------------------------------
// Les trois phrases que l'utilisateur doit pouvoir distinguer d'un coup d'œil.
// Sous-chaînes SANS ACCENT, tirées de `src/components/ui/QueryErrorState.tsx`.
// ---------------------------------------------------------------------------

/** Le serveur a dit « non » : c'est une question de rôle, pas de panne. */
const TEXTE_REFUS = 'pas les droits';
/** Le serveur a échoué : rien ne dit qu'il n'y a pas de données. */
const TEXTE_PANNE = 'serveur est en panne';

/**
 * Le code HTTP est AFFICHÉ. Deux raisons mesurées :
 *  - il tranche entre 403 et 500 même si quelqu'un réécrit les libellés ;
 *  - c'est la seule chose que l'utilisateur peut recopier au support, et son
 *    absence transformait chaque signalement en enquête.
 */
const CODE_AFFICHE = (status: number): string => `code HTTP ${status}`;

// ---------------------------------------------------------------------------
// Fabriques de handlers d'erreur (on n'édite pas `tests/msw/handlers.ts`, il est
// partagé avec les autres fichiers de test).
// ---------------------------------------------------------------------------

/**
 * `GET <path>` répond `status` avec un corps VIDE.
 *
 * ⚠️ Corps vide à dessein : un 403 porteur de `{"error":"two_factor_required"}`
 * déclencherait la redirection `/2fa` de `src/lib/api.ts:62` et l'écran ne
 * serait jamais rendu. Ici on teste le 403 ORDINAIRE — celui du RBAC.
 */
function erreurGet(path: string, status: number): HttpHandler {
  return http.get(apiUrl(path), () => new HttpResponse(null, { status }));
}

/** Le texte réellement lisible à l'écran, accents compris. */
function texteEcran(): string {
  return document.body.textContent ?? '';
}

// ---------------------------------------------------------------------------
// Les 5 écrans du lot
// ---------------------------------------------------------------------------

interface CasEcran {
  nom: string;
  rendre: () => ReactElement;
  path: string;
  consoleFeatures?: 'open' | 'vivier';
  /** Les chemins d'API interrogés AU MONTAGE, avec leur réponse « rien ». */
  vide: Record<string, unknown>;
  /** Sous-chaîne SANS ACCENT propre à l'état vide de CET écran. */
  texteVide: string;
  /**
   * Sous-chaînes de compteurs dont la valeur serait FABRIQUÉE (0) quand la
   * requête a échoué. Elles doivent disparaître sous 403 comme sous 500.
   */
  compteursMensongers?: string[];
}

/** Une page à curseur, vide. Forme réelle de `CursorResponse`. */
const PAGE_VIDE = {
  data: [],
  meta: { per_page: 50, next_cursor: null, prev_cursor: null, has_more: false },
};

const COMPTEURS_ZERO = { total: 0, by_relation_type: {}, by_lifecycle_stage: {} };

const ECRANS: CasEcran[] = [
  {
    nom: 'ArbitragePage',
    rendre: () => <ArbitragePage />,
    path: '/console/arbitrage',
    consoleFeatures: 'open',
    vide: { '/crm/arbitrage': { data: [], meta: { total: 0, per_page: 50 } } },
    // « Rien à arbitrer ». On ne cherche PAS « arbitrer » : le titre de la page
    // (« Rapprochements à arbitrer ») le contient aussi et resterait affiché.
    texteVide: 'Rien',
    // Le sous-titre annonce « 0 événement(s) reçus sans SIREN ». Sous 403 comme
    // sous 500, ce zéro est inventé : la file n'a JAMAIS été lue.
    compteursMensongers: ['nement(s) re'],
  },
  {
    nom: 'CandidatesPage',
    rendre: () => <CandidatesPage />,
    path: '/console/vivier',
    consoleFeatures: 'vivier',
    vide: { '/crm/candidates/counts': COMPTEURS_ZERO, '/crm/candidates': PAGE_VIDE },
    texteVide: 'Aucun candidat',
    // « À qualifier : 0 » est le compteur nommé par l'audit. Un vivier de
    // candidatures qu'on croit vide parce qu'on n'a pas le droit de le lire,
    // c'est une candidature jamais traitée.
    compteursMensongers: ['qualifier'],
  },
  {
    nom: 'ContactsHubPage',
    rendre: () => <ContactsHubPage />,
    path: '/console/contacts',
    consoleFeatures: 'open',
    vide: { '/crm/contacts-hub/counts': COMPTEURS_ZERO, '/crm/contacts-hub': PAGE_VIDE },
    texteVide: 'Aucun contact',
    compteursMensongers: ['Dormants'],
  },
  {
    nom: 'UsersPage',
    rendre: () => <UsersPage />,
    path: '/admin/users',
    vide: { '/users': { data: [] } },
    texteVide: 'Aucun utilisateur',
  },
  {
    nom: 'AuditLogsPage',
    rendre: () => <AuditLogsPage />,
    path: '/rgpd/audit-logs',
    vide: { '/audit-logs': { data: [] } },
    // « Aucun journal d'audit ». Sans accent, et absent du reste de l'écran
    // (le titre est « Journaux d'audit »).
    texteVide: 'Aucun journal',
  },
  {
    // P5-35-012 — le fil d'activité de l'accueil.
    nom: 'ActivityFeed',
    rendre: () => <ActivityFeed />,
    path: '/',
    vide: { '/audit-logs': { data: [] } },
    // « Activité bientôt disponible ». On ne cherche NI « Activit » (le titre de
    // la carte, « Activité récente », le contient et reste affiché en erreur),
    // NI un mot accentué. « disponible » n'apparaît nulle part ailleurs, ni dans
    // la carte, ni dans aucun message de `QueryErrorState`.
    texteVide: 'disponible',
  },
];

/** Monte l'écran avec toutes ses requêtes en 200-vide. */
async function monterVide(cas: CasEcran): Promise<void> {
  await renderScreen(cas.rendre(), {
    path: cas.path,
    ...(cas.consoleFeatures === undefined ? {} : { consoleFeatures: cas.consoleFeatures }),
    handlers: Object.entries(cas.vide).map(([chemin, corps]) => getJson(chemin, corps)),
  });
}

/** Monte l'écran avec TOUTES ses requêtes en erreur `status`. */
async function monterEnErreur(cas: CasEcran, status: number): Promise<void> {
  await renderScreen(cas.rendre(), {
    path: cas.path,
    ...(cas.consoleFeatures === undefined ? {} : { consoleFeatures: cas.consoleFeatures }),
    handlers: Object.keys(cas.vide).map((chemin) => erreurGet(chemin, status)),
  });
}

describe.each(ECRANS)('$nom — refus, panne et vide ne se confondent pas', (cas) => {
  it('TEMOIN — 200 + liste vide : l’ecran dit qu’il n’y a rien, et rien d’autre', async () => {
    await monterVide(cas);

    await waitFor(() => {
      expect(texteEcran()).toContain(cas.texteVide);
    });

    // Le témoin de la garde elle-même : si un écran affichait « panne » sur une
    // réponse SAINE, les deux cas suivants passeraient au vert pour de mauvaises
    // raisons et la garde ne prouverait rien.
    expect(texteEcran()).not.toContain(TEXTE_REFUS);
    expect(texteEcran()).not.toContain(TEXTE_PANNE);
  });

  it('403 — nomme le refus de droits, et n’affirme plus « il n’y a rien »', async () => {
    await monterEnErreur(cas, 403);

    await waitFor(() => {
      expect(texteEcran()).toContain(TEXTE_REFUS);
    });
    expect(texteEcran()).toContain(CODE_AFFICHE(403));

    // Le cœur du constat D25-001 : l'état vide ne doit PLUS être là.
    expect(texteEcran()).not.toContain(cas.texteVide);
    expect(texteEcran()).not.toContain(TEXTE_PANNE);

    for (const compteur of cas.compteursMensongers ?? []) {
      expect(texteEcran()).not.toContain(compteur);
    }
  });

  it('500 — nomme la panne serveur, avec un texte DIFFERENT du 403', async () => {
    await monterEnErreur(cas, 500);

    await waitFor(() => {
      expect(texteEcran()).toContain(TEXTE_PANNE);
    });
    expect(texteEcran()).toContain(CODE_AFFICHE(500));

    expect(texteEcran()).not.toContain(cas.texteVide);
    // 37 écrans sur 37 rendaient le MÊME texte sous 403 et sous 500 : c'est
    // exactement cette égalité que la ligne suivante interdit.
    expect(texteEcran()).not.toContain(TEXTE_REFUS);

    for (const compteur of cas.compteursMensongers ?? []) {
      expect(texteEcran()).not.toContain(compteur);
    }
  });
});

/**
 * Le 404 mérite son propre mot : sur un écran de détail, « cette fiche n'existe
 * pas » et « le serveur est en panne » n'appellent pas le même geste. On le
 * vérifie sur un seul écran — le composant est partagé, la démonstration vaut
 * pour tous.
 */
describe('QueryErrorState — les autres natures', () => {
  it('404 : dit que la ressource n’existe pas, pas qu’elle est vide', async () => {
    await renderScreen(<UsersPage />, {
      path: '/admin/users',
      handlers: [erreurGet('/users', 404)],
    });

    await waitFor(() => {
      expect(texteEcran()).toContain('introuvable');
    });
    expect(texteEcran()).not.toContain('Aucun utilisateur');
    expect(texteEcran()).not.toContain(TEXTE_REFUS);
    expect(texteEcran()).not.toContain(TEXTE_PANNE);
  });

  it('serveur injoignable (aucune reponse) : ni refus, ni panne applicative', async () => {
    await renderScreen(<UsersPage />, {
      path: '/admin/users',
      // `HttpResponse.error()` = échec réseau : axios ne reçoit AUCUNE réponse,
      // donc aucun code HTTP. C'est le cas du VPN coupé ou du DNS mort, et il
      // n'a rien à voir avec un serveur qui répond 500.
      handlers: [http.get(apiUrl('/users'), () => HttpResponse.error())],
    });

    await waitFor(() => {
      expect(texteEcran()).toContain('injoignable');
    });
    expect(texteEcran()).not.toContain('Aucun utilisateur');
    expect(texteEcran()).not.toContain(TEXTE_REFUS);
  });
});

// ---------------------------------------------------------------------------
// X39-028 — quand le serveur REFUSE la vue, l'ecran ne doit plus proposer
// l'action qui en decoule.
//
// ═══ LE DEFAUT MESURE, le 2026-08-23 ═══
//
// `GET /users` etait la SEULE des quatre routes utilisateur sans garde de
// permission. Un compte de role `viewer` obtenait 200 et lisait nom, ADRESSE
// E-MAIL, roles, etat de la 2FA et derniere connexion de chaque compte.
//
// La garde serveur posee (`permission:users.manage`), le refus arrive enfin —
// et l'ecran a montre son second defaut : il affichait
//
//     « Vous n'avez pas les droits sur cette vue »
//
// et, JUSTE AU-DESSUS, le bouton « Inviter un utilisateur ». Un compte qui ne
// peut meme pas consulter la liste des membres etait invite a en recruter. Le
// POST qui aurait suivi serait reparti en 403, sans plus d'explication —
// exactement le scenario que le commentaire de `UsersPage.tsx` decrit.
//
// ⚠️ CE QUE CETTE GARDE N'EST PAS. Elle ne verifie AUCUNE permission cote
// interface : le constat D22-006 (« 33 ecrans sur 37 n'interrogent jamais role
// ni permission ») reste ouvert. Elle verifie seulement que l'ecran cesse de
// proposer une action APRES que le serveur a refuse la lecture. La vraie
// protection est cote serveur, et c'est elle qui compte.
// ---------------------------------------------------------------------------

const BOUTON_INVITER = 'Inviter un utilisateur';

describe("X39-028 — l'action disparait quand le serveur refuse la vue", () => {
  it('TEMOIN — sous 200, le bouton « Inviter un utilisateur » EST propose', async () => {
    // Sans ce temoin, la garde passerait au vert sur un ecran qui n'afficherait
    // JAMAIS le bouton — un autre defaut, et elle ne saurait pas le voir.
    await renderScreen(<UsersPage />, {
      path: '/admin/users',
      handlers: [getJson('/users', { data: [] })],
    });

    await waitFor(() => {
      expect(texteEcran()).toContain('Aucun utilisateur');
    });
    expect(texteEcran()).toContain(BOUTON_INVITER);
  });

  it('sous 403, le bouton disparait — on ne propose pas de recruter a qui ne peut pas lire', async () => {
    await renderScreen(<UsersPage />, {
      path: '/admin/users',
      handlers: [erreurGet('/users', 403)],
    });

    await waitFor(() => {
      expect(texteEcran()).toContain(TEXTE_REFUS);
    });
    expect(texteEcran()).not.toContain(BOUTON_INVITER);
  });

  it('sous 500 aussi : une panne n est pas le moment de proposer une invitation', async () => {
    await renderScreen(<UsersPage />, {
      path: '/admin/users',
      handlers: [erreurGet('/users', 500)],
    });

    await waitFor(() => {
      expect(texteEcran()).toContain(TEXTE_PANNE);
    });
    expect(texteEcran()).not.toContain(BOUTON_INVITER);
  });
});
