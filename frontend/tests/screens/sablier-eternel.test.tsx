/**
 * D25-004 — UN SABLIER QUI NE S'ARRETE JAMAIS.
 *
 * ═══ LE DEFAUT MESURE ═══
 *
 * Trois ecrans ne rendaient pas une page blanche : ils restaient bloques sur
 * leur indicateur de chargement POUR TOUJOURS, parce que la condition de sortie
 * teste LA DONNEE et non L'ETAT DE LA REQUETE :
 *
 *   src/features/campaigns/CampaignDetailPage.tsx   `if (isLoading || !campaign)`
 *   src/features/audiences/AudienceDetailPage.tsx   `if (isLoading || !audience)`
 *   src/features/settings/SettingsPage.tsx          `ws.data ? <form/> : <p>Chargement…</p>`
 *
 * React Query v5 : sur un echec, `isLoading` retombe a `false` mais `data`
 * reste `undefined`. Le second terme de la disjonction reste donc VRAI
 * indefiniment — le sablier ne peut plus disparaitre. L'utilisateur attend une
 * page qui n'arrivera jamais, et rien ne lui dit d'arreter d'attendre.
 *
 * C'est pire qu'une erreur affichee : sur `/campaigns/$id` ce sablier masque
 * quatre actions destructrices (pause, reprise, annulation, demarrage) ; sur
 * `/settings` il masque le plafond de depenses LLM.
 *
 * ═══ LE CAS QUI CASSE, ET QU'ON OUBLIE ═══
 *
 * L'echec HTTP n'est pas le seul chemin vers le sablier eternel. Une reponse
 * **200 au corps vide** y mene aussi, et par une porte que la branche d'erreur
 * ne ferme PAS : il n'y a alors AUCUNE erreur (`query.error === null`), et
 * pourtant `data` est absente. Un ecran repare a coups de `error !== null`
 * reste bloque sur ce cas-la. Les trois cas sont donc joues separement :
 *
 *   500  -> le serveur a echoue           (`error !== null`, `data` absente)
 *   403  -> le serveur a refuse           (`error !== null`, `data` absente)
 *   200 vide -> le serveur a repondu RIEN (`error === null`, `data` absente)
 *
 * ═══ CE QUE LA GARDE EXIGE, POUR CHAQUE ECRAN ET CHAQUE CAS ═══
 *
 *  1. TEMOIN POSITIF — sous une reponse 200 PLEINE, l'indicateur de chargement
 *     disparait et le contenu apparait. Sans ce cas, la garde serait verte sur
 *     un ecran qui ne rendrait jamais rien du tout : l'instrument doit prouver
 *     qu'il sait voir la sortie de chargement quand elle a lieu.
 *  2. L'indicateur de chargement DISPARAIT. C'est le defaut D25-004 lui-meme.
 *  3. Quelque chose de LISIBLE prend sa place. Un ecran vide fait disparaitre
 *     le sablier lui aussi — ce serait un faux vert, et c'est exactement le
 *     piege qu'un test « le sablier n'est plus la » tout seul ne voit pas.
 *
 * ⚠️ Toutes les sous-chaines cherchees sont SANS LETTRE ACCENTUEE.
 *
 * ⚠️ `Spinner` porte `aria-label="Chargement"` (`src/components/ui/Spinner.tsx`)
 * et `SettingsPage` ecrit un `Chargement…` en texte nu : la sonde cherche LES
 * DEUX, sinon elle serait aveugle a l'un des trois ecrans du constat.
 */
import { describe, it, expect } from 'vitest';
import type { ReactElement } from 'react';
import { waitFor } from '@testing-library/react';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { CampaignDetailPage } from '@/features/campaigns/CampaignDetailPage';
import { AudienceDetailPage } from '@/features/audiences/AudienceDetailPage';
import { SettingsPage } from '@/features/settings/SettingsPage';

import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, getJson, http, HttpResponse, type HttpHandler } from '../msw/handlers';

// ---------------------------------------------------------------------------
// La sonde : « l'ecran est-il ENCORE en train de charger ? »
// ---------------------------------------------------------------------------

/**
 * Vrai tant qu'un indicateur de chargement est monte, sous l'une ou l'autre de
 * ses deux formes dans ce depot.
 *
 * TEMOIN DE COUVERTURE (`temoin de la sonde`, plus bas) : cette fonction doit
 * rendre VRAI sur un `<Spinner/>` comme sur un « Chargement… » nu. Une sonde
 * qui ne verrait qu'une des deux formes declarerait « plus de sablier » sur
 * l'ecran des reglages sans jamais le regarder.
 */
function chargementEnCours(): boolean {
  const sabliers = document.querySelectorAll('[aria-label="Chargement"]');
  if (sabliers.length > 0) return true;
  return (document.body.textContent ?? '').includes('Chargement…');
}

/** Le texte reellement lisible a l'ecran, accents compris. */
function texteEcran(): string {
  return document.body.textContent ?? '';
}

/**
 * Attend la FIN du chargement. C'est ici que le defaut D25-004 rougit : sur un
 * sablier eternel, la condition n'est jamais remplie et `waitFor` expire.
 */
async function attendreFinDuChargement(): Promise<void> {
  await waitFor(
    () => {
      expect(chargementEnCours()).toBe(false);
    },
    { timeout: 3_000 },
  );
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

/**
 * `GET <path>` -> code d'erreur, corps VIDE.
 *
 * ⚠️ Corps vide a dessein : un 403 portant `{"error":"two_factor_required"}`
 * declencherait la redirection `/2fa` de `src/lib/api.ts` et l'ecran ne serait
 * jamais rendu. On teste le 403 ORDINAIRE, celui du controle de roles.
 */
function erreurGet(path: string, status: number): HttpHandler {
  return http.get(apiUrl(path), () => new HttpResponse(null, { status }));
}

/**
 * `GET <path>` -> **200 avec un corps vide**, le cas qui casse.
 *
 * Ce n'est pas une hypothese d'ecole : c'est ce que rend un point d'API dont
 * la ressource a ete filtree par une portee (workspace, RGPD), un cache
 * intermediaire qui repond 200 sans corps, ou un serveur qui serialise `null`.
 * Axios laisse alors `response.data` a la chaine vide, et l'ecran recoit
 * `undefined` sans la moindre erreur a montrer.
 */
function videGet(path: string): HttpHandler {
  return http.get(apiUrl(path), () => new HttpResponse('', { status: 200 }));
}

// ---------------------------------------------------------------------------
// Fixtures — le strict necessaire pour que l'ecran se rende (temoin positif)
// ---------------------------------------------------------------------------

const CAMPAGNE_PLEINE = {
  id: 42,
  workspace_id: 'ws-1',
  created_by: 'u-1',
  name: 'Collecte Bretagne',
  description: null,
  status: 'running',
  sources: ['insee'],
  zones: [{ type: 'department', code: '35' }],
  max_companies: 100,
  max_duration_minutes: 60,
  max_requests_per_minute: 10,
  per_source_limits: null,
  scheduled_at: null,
  expires_at: null,
  companies_created: 7,
  requests_made: 12,
  runs_completed: 1,
  runs_total: 2,
  duration_seconds_used: 120,
  progress_percent: 7,
  elapsed_minutes: 2,
  remaining_minutes: 58,
  companies_remaining: 93,
  started_at: '2026-08-21T08:00:00Z',
  paused_at: null,
  finished_at: null,
  paused_reason: null,
  can_start: false,
  can_pause: true,
  can_resume: false,
  can_cancel: true,
  created_at: '2026-08-21T07:59:00Z',
  updated_at: '2026-08-21T08:02:00Z',
  runs_preview: [],
};

const STATS_PLEINES = {
  campaign: CAMPAGNE_PLEINE,
  per_source: [],
  last_events: [],
  companies_per_minute: 3.5,
};

const AUDIENCE_PLEINE = {
  id: 7,
  name: 'Dirigeants Bretagne',
  description: null,
  criteria: {},
  is_active: true,
  auto_refresh: false,
  member_count: 3,
  refreshed_at: '2026-08-21T08:00:00Z',
  created_at: '2026-08-20T08:00:00Z',
};

const WORKSPACE_PLEIN = {
  id: 'ws-1',
  name: 'Axion',
  slug: 'axion',
  cost_cap_eur: 250,
  settings: {},
};

// ---------------------------------------------------------------------------
// Les trois ecrans du constat
// ---------------------------------------------------------------------------

interface CasEcran {
  nom: string;
  rendre: () => ReactElement;
  path: string;
  url: string;
  /**
   * Les chemins d'API interroges AU MONTAGE, avec leur reponse PLEINE.
   * C'est aussi l'enumeration exhaustive de ce qu'il faut casser : le sablier
   * du haut d'ecran depend de la PREMIERE, mais les autres doivent repondre,
   * sinon MSW rend « requete non geree » et le rouge n'a plus de sens.
   */
  plein: Record<string, unknown>;
  /** Le chemin dont l'echec bloquait le sablier. */
  cheminBloquant: string;
  /** Sous-chaine SANS ACCENT prouvant que le CONTENU s'est rendu (temoin positif). */
  temoinPlein: string;
  /** Sous-chaine SANS ACCENT prouvant que l'ecran PARLE sur un 200 vide. */
  temoinVide: string;
}

const ECRANS: CasEcran[] = [
  {
    nom: '/campaigns/$campaignId',
    rendre: () => <CampaignDetailPage />,
    path: '/campaigns/$campaignId',
    url: '/campaigns/42',
    plein: {
      '/campaigns/42': CAMPAGNE_PLEINE,
      '/campaigns/42/stats': STATS_PLEINES,
    },
    cheminBloquant: '/campaigns/42',
    temoinPlein: 'Collecte Bretagne',
    temoinVide: 'vide du serveur',
  },
  {
    nom: '/audiences/$audienceId',
    rendre: () => <AudienceDetailPage />,
    path: '/audiences/$audienceId',
    url: '/audiences/7',
    plein: {
      '/audiences/7': { data: AUDIENCE_PLEINE },
      '/audiences/7/members': { data: [] },
    },
    cheminBloquant: '/audiences/7',
    temoinPlein: 'Dirigeants Bretagne',
    temoinVide: 'vide du serveur',
  },
  {
    nom: '/settings',
    rendre: () => <SettingsPage />,
    path: '/settings',
    url: '/settings',
    plein: {
      '/workspace': WORKSPACE_PLEIN,
    },
    cheminBloquant: '/workspace',
    // Le plafond LLM : ce que le sablier eternel masquait exactement.
    temoinPlein: 'Plafond LLM',
    temoinVide: 'vide du serveur',
  },
];

/** Les handlers « tout va bien », avec un seul chemin remplace. */
function handlersAvec(cas: CasEcran, remplacant?: HttpHandler): HttpHandler[] {
  const base = Object.entries(cas.plein)
    .filter(([chemin]) => remplacant === undefined || chemin !== cas.cheminBloquant)
    .map(([chemin, corps]) => getJson(chemin, corps));
  return remplacant === undefined ? base : [...base, remplacant];
}

// ---------------------------------------------------------------------------
// Temoin de la sonde — elle doit savoir DIRE OUI
// ---------------------------------------------------------------------------

describe('D25-004 — temoin de la sonde', () => {
  it('voit le sablier sous ses DEUX formes, sinon elle ne mesure rien', () => {
    document.body.innerHTML = '<svg aria-label="Chargement"></svg>';
    expect(chargementEnCours()).toBe(true);

    document.body.innerHTML = '<p>Chargement…</p>';
    expect(chargementEnCours()).toBe(true);

    document.body.innerHTML = '<p>Plafond LLM mensuel</p>';
    expect(chargementEnCours()).toBe(false);
  });
});

// ---------------------------------------------------------------------------
// La garde
// ---------------------------------------------------------------------------

describe.each(ECRANS)('D25-004 — $nom', (cas) => {
  it('TEMOIN — sous 200 plein, le sablier disparait et le contenu arrive', async () => {
    await renderScreen(cas.rendre(), {
      path: cas.path,
      url: cas.url,
      handlers: handlersAvec(cas),
    });

    await attendreFinDuChargement();
    expect(texteEcran()).toContain(cas.temoinPlein);
  });

  it.each([500, 403])('sous %i, le sablier disparait et l ecran DIT ce qui s est passe', async (status) => {
    await renderScreen(cas.rendre(), {
      path: cas.path,
      url: cas.url,
      handlers: handlersAvec(cas, erreurGet(cas.cheminBloquant, status)),
    });

    await attendreFinDuChargement();

    // Le code HTTP en clair : la seule chose que l'utilisateur peut recopier au
    // support, et ce qui prouve que la place du sablier est prise par un ETAT
    // D'ERREUR, pas par un ecran vide.
    expect(texteEcran()).toContain(`code HTTP ${status}`);
  });

  it('sous 200 VIDE — aucune erreur, aucune donnee — le sablier disparait quand meme', async () => {
    await renderScreen(cas.rendre(), {
      path: cas.path,
      url: cas.url,
      handlers: handlersAvec(cas, videGet(cas.cheminBloquant)),
    });

    await attendreFinDuChargement();
    expect(texteEcran()).toContain(cas.temoinVide);
  });
});

// ---------------------------------------------------------------------------
// Le quatrieme chemin vers le sablier eternel : la requete n'est jamais PARTIE
// ---------------------------------------------------------------------------

/**
 * `enabled: Number.isFinite(id) && id > 0` — sur une adresse dont le segment
 * n'est pas un nombre (`/campaigns/abc`), la requete est DESACTIVEE. React
 * Query v5 : `isPending` reste vrai, mais `isFetching` est faux, donc
 * `isLoading === false` ET `data === undefined`.
 *
 * L'ancienne condition `isLoading || !campaign` etait donc vraie pour toujours
 * sur ce chemin AUSSI — sans qu'aucune requete HTTP ne soit jamais partie. Ni
 * la branche d'erreur ni la branche « 200 vide » ne le voient : il n'y a ni
 * erreur, ni reponse.
 *
 * Les deux ecrans a parametre sont couverts ; `/settings` n'a pas de parametre
 * d'adresse et ne peut pas presenter ce cas.
 */
describe.each([
  {
    nom: '/campaigns/$campaignId',
    rendre: () => <CampaignDetailPage />,
    path: '/campaigns/$campaignId',
    url: '/campaigns/abc',
  },
  {
    nom: '/audiences/$audienceId',
    rendre: () => <AudienceDetailPage />,
    path: '/audiences/$audienceId',
    url: '/audiences/abc',
  },
])('D25-004 — $nom, identifiant illisible dans l adresse', (cas) => {
  it('aucune requete ne part, et le sablier disparait quand meme', async () => {
    await renderScreen(cas.rendre(), { path: cas.path, url: cas.url });

    await attendreFinDuChargement();
    // « invalide » sans accent : c'est l'ecran qui doit dire que le LIEN est
    // faux, pas laisser croire que la donnee arrive.
    expect(texteEcran()).toContain('invalide');
  });
});

// ---------------------------------------------------------------------------
// Le cas PARTIEL : la fiche arrive, l'onglet echoue
// ---------------------------------------------------------------------------

/**
 * Mesure du 2026-08-21, avant correctif : sous `/audiences/7` en 200 et
 * `/audiences/7/members` en 500, l'onglet Membres ne restait PAS un sablier
 * (`membersLoading` retombe a faux) — il affichait « Aucun membre pour
 * l'instant. Lance un refresh pour materialiser le segment. »
 *
 * ⚠️ Cette mesure CORRIGE la grille de l'agent 25, qui portait « l'onglet
 * Membres reste un sablier ». Ce n'etait pas un sablier : c'etait une
 * AFFIRMATION FAUSSE sur un segment que l'ecran n'avait pas pu lire, doublee
 * d'une invitation a un geste (« lance un refresh ») qui n'y peut rien.
 * Le defaut est reel, sa nature est autre — et sa forme est plus dangereuse,
 * puisqu'elle ne se voit pas.
 */
describe('D25-004 / partiel — /audiences/$audienceId, fiche OK et membres KO', () => {
  it('l onglet Membres n affirme PAS « aucun membre » quand il n a pas pu lire', async () => {
    await renderScreen(<AudienceDetailPage />, {
      path: '/audiences/$audienceId',
      url: '/audiences/7',
      handlers: [
        getJson('/audiences/7', { data: AUDIENCE_PLEINE }),
        erreurGet('/audiences/7/members', 500),
      ],
    });

    await attendreFinDuChargement();

    const texte = texteEcran();
    // La fiche, elle, est bien arrivee : le test porte donc sur l'ONGLET seul.
    expect(texte).toContain('Dirigeants Bretagne');
    // L'affirmation fausse a disparu…
    expect(texte).not.toContain('Aucun membre');
    // …et l'ecran dit ce qui s'est reellement passe.
    expect(texte).toContain('code HTTP 500');
  });

  it('TEMOIN — sous 200 et une liste VIDE, l onglet dit toujours qu il n y a aucun membre', async () => {
    await renderScreen(<AudienceDetailPage />, {
      path: '/audiences/$audienceId',
      url: '/audiences/7',
      handlers: [
        getJson('/audiences/7', { data: AUDIENCE_PLEINE }),
        getJson('/audiences/7/members', { data: [] }),
      ],
    });

    await attendreFinDuChargement();

    // Sans ce temoin, un onglet qui crierait « erreur » en toutes circonstances
    // passerait la garde precedente — et ce serait un autre mensonge.
    expect(texteEcran()).toContain('Aucun membre');
  });
});

// ---------------------------------------------------------------------------
// BALAYAGE — le motif ne doit pas revenir par un ecran qu'on n'a pas regarde
// ---------------------------------------------------------------------------

/**
 * Les trois ecrans du constat sont repares et joues au-dessus. Rien n'empeche
 * le motif de reapparaitre ailleurs : `if (isLoading || !data)` est court, il
 * se lit bien, et il est FAUX.
 *
 * ⚠️ Ce balayage est deliberement STATIQUE et SANS LISTE ECRITE A LA MAIN. Une
 * garde de completude qui enumere des fichiers ne verra jamais le fichier qu'on
 * a oublie — c'est le piege A-011, deja paye deux fois dans cet audit. On lit
 * donc TOUT `src/`, et on compte.
 *
 * ⚠️ Ce que le balayage NE prouve PAS, et qu'il ne faut pas lui faire dire : il
 * ne voit que cette ECRITURE-la. Un ecran qui obtiendrait le meme sablier
 * eternel par une variable intermediaire (`const pret = !data; if (isLoading ||
 * !pret)`) lui echapperait. Seules les gardes de rendu ci-dessus mesurent le
 * comportement ; celle-ci ne fige qu'un chiffre : ZERO occurrence textuelle,
 * au 2026-08-21.
 */
const ICI = path.dirname(fileURLToPath(import.meta.url));
const RACINE_SRC = path.resolve(ICI, '..', '..', 'src');

/** Liste recursivement les `*.ts`/`*.tsx` d'un dossier. */
function listerModules(dossier: string): string[] {
  const sorties: string[] = [];
  for (const entree of readdirSync(dossier)) {
    const complet = path.join(dossier, entree);
    if (statSync(complet).isDirectory()) sorties.push(...listerModules(complet));
    else if (entree.endsWith('.ts') || entree.endsWith('.tsx')) sorties.push(complet);
  }
  return sorties;
}

/**
 * `isLoading` (ou `isPending`, ou un `xLoading`) mis en disjonction avec la
 * NEGATION d'une donnee — dans l'un ou l'autre ordre. C'est l'ecriture exacte
 * du defaut D25-004.
 */
const MOTIF_SABLIER_ETERNEL =
  /(?:(?:is|[A-Za-z])(?:Loading|Pending)\b[^)\n]*\|\|[^)\n]*![A-Za-z_$])|(?:![A-Za-z_$][\w.$]*[^)\n]*\|\|[^)\n]*(?:is|[A-Za-z])(?:Loading|Pending)\b)/;

function releverSabliers(): string[] {
  const coupables: string[] = [];
  for (const complet of listerModules(RACINE_SRC)) {
    const source = readFileSync(complet, 'utf8');
    for (const [index, ligne] of source.split('\n').entries()) {
      // Les commentaires citent le defaut pour l'expliquer : ils ne l'ecrivent
      // pas. Sans ce filtre, la garde rougirait sur sa propre documentation.
      const nu = ligne.trim();
      if (nu.startsWith('//') || nu.startsWith('*') || nu.startsWith('/*')) continue;
      if (MOTIF_SABLIER_ETERNEL.test(ligne)) {
        coupables.push(`${path.relative(RACINE_SRC, complet).split(path.sep).join('/')}:${index + 1}  ${nu}`);
      }
    }
  }
  return coupables;
}

describe('D25-004 — balayage de `src/`', () => {
  it('TEMOIN DE COUVERTURE — le balayage lit vraiment des fichiers, et le motif sait mordre', () => {
    // 1. Il lit. Un balayage qui ne trouve aucun fichier rendrait [] et serait
    //    VERT en ne regardant rien : c'est le faux vert le plus courant.
    const modules = listerModules(RACINE_SRC);
    expect(modules.length).toBeGreaterThan(100);

    // 2. Il mord. Les quatre ecritures du defaut, dans les deux ordres.
    expect(MOTIF_SABLIER_ETERNEL.test('  if (isLoading || !campaign) {')).toBe(true);
    expect(MOTIF_SABLIER_ETERNEL.test('  if (isPending || !audience) {')).toBe(true);
    expect(MOTIF_SABLIER_ETERNEL.test('  if (membersLoading || !members) {')).toBe(true);
    expect(MOTIF_SABLIER_ETERNEL.test('  if (!campaign || isLoading) {')).toBe(true);

    // 3. Il ne mord pas a tort. Les formes CORRECTES doivent passer.
    expect(MOTIF_SABLIER_ETERNEL.test('  if (isLoading) {')).toBe(false);
    expect(MOTIF_SABLIER_ETERNEL.test('  const echec = error !== null && data === undefined;')).toBe(false);
    expect(MOTIF_SABLIER_ETERNEL.test('  if (!campaign) {')).toBe(false);
  });

  it('aucun ecran de `src/` n ecrit plus la condition qui bloque le sablier', () => {
    // Chiffre fige le 2026-08-21 : ZERO, apres reparation des trois ecrans du
    // constat. Si cette assertion rougit, la liste ci-dessous nomme l'ecran.
    expect(releverSabliers()).toEqual([]);
  });
});
