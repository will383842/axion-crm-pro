/**
 * GARDE DE REGISTRE — la scrutation periodique est DECLAREE, et la plus
 * rapide sait s'arreter.
 *
 * ── Le defaut (G42-007, S1) ───────────────────────────────────────────────
 * L'audit annonce « neuf requetes en scrutation periodique, dont deux toutes
 * les 5 secondes sur le meme ecran ».
 *
 * ⚠️ RECOMPTAGE DU 2026-08-20 : il y en a HUIT, pas neuf. Commande jouee :
 *     grep -rn "refetchInterval" src/
 * Neuf lignes sortent, mais l'une est un COMMENTAIRE
 * (`CampaignDetailPage.tsx:10`, « Refresh 5s via refetchInterval »). Les
 * quatre autres minuteurs du depot (`Tooltip.tsx:26`, `TwoFactorPage.tsx:117`,
 * `OnboardingTour.tsx:103`, `AudienceBuilderPage.tsx:170`) sont des `setTimeout`
 * A UN COUP, pas de la scrutation. Le reste du constat tient : la partie
 * « deux toutes les 5 secondes sur le meme ecran » est exacte.
 *
 * Les huit, releves :
 *   5 000 ms  CampaignDetailPage.tsx:64   GET /campaigns/{id}
 *   5 000 ms  CampaignDetailPage.tsx:71   GET /campaigns/{id}/stats
 *  10 000 ms  CampaignsListPage.tsx:75    GET /campaigns
 *  10 000 ms  ScraperRunsPage.tsx:237     GET /scraper-runs?per_page=50
 *  30 000 ms  DashboardPage.tsx:81        GET /dashboard/stats
 *  30 000 ms  ObservabilityPage.tsx:34    GET /admin/observability
 *  30 000 ms  AudiencesListPage.tsx:68    GET /audiences
 *  60 000 ms  CoveragePage.tsx:57         GET /coverage
 *
 * Sur `/campaigns/{id}`, les deux cadences a 5 s font **24 requetes par
 * minute**, indefiniment, y compris sur une campagne TERMINEE que le serveur
 * ne peut plus faire bouger.
 *
 * ── Ce que cette garde tient, et ce qu'elle ne tient pas ──────────────────
 * DEUX regles, et la deuxieme est la reparation :
 *   A. toute scrutation de `src/` est INSCRITE au registre ci-dessous. Une
 *      neuvieme ne peut plus apparaitre en silence.
 *   B. une scrutation a 5 s ou moins doit etre CONDITIONNELLE — sa cadence
 *      doit etre une fonction capable de rendre `false`, pas un nombre en dur.
 *      La cadence la plus rapide de l'application doit savoir s'arreter.
 *
 * ⚠️ CE QU'ELLE NE COUVRE PAS, et c'est le coeur du constat :
 *  · elle NE FUSIONNE PAS les deux appels de `/campaigns/{id}`. Pendant qu'une
 *    campagne tourne, l'ecran fait toujours 24 requetes par minute. Les fondre
 *    en un seul appel demande au SERVEUR d'exposer les statistiques dans la
 *    ressource campagne — un changement de contrat d'API, hors du perimetre
 *    d'un lot frontend. G42-007 reste donc PARTIEL, et le dit.
 *  · elle ne mesure pas la charge reelle : `refetchIntervalInBackground` vaut
 *    `false` par defaut dans React Query v5, donc un onglet en arriere-plan ne
 *    scrute deja pas. Ce n'est pas cette garde qui le prouve.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ICI = path.dirname(fileURLToPath(import.meta.url));
const RACINE_FRONT = path.resolve(ICI, '..', '..');
const SRC = path.join(RACINE_FRONT, 'src');

/** Au-dela de ce seuil, une scrutation en dur reste acceptable. */
const CADENCE_RAPIDE_MS = 5_000;

// ── Fonctions pures, pour que le TEMOIN puisse les eprouver ───────────────

function sansCommentaires(texte: string): string {
  return texte.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

export interface Scrutation {
  fichier: string;
  /** Cadence en millisecondes, ou `null` si elle est calculee (forme fonction). */
  cadenceMs: number | null;
  /** `true` si la cadence est une FONCTION, donc capable de rendre `false`. */
  conditionnelle: boolean;
}

/**
 * Releve les `refetchInterval` d'un module.
 *
 * ⚠️ Le commentaire de `CampaignDetailPage.tsx:10` cite `refetchInterval` en
 * prose. C'est LUI qui fait dire « neuf » a l'audit la ou il y en a huit : une
 * garde qui lirait la prose recompterait la meme erreur.
 */
export function relevesScrutation(source: string, fichier: string): Scrutation[] {
  const net = sansCommentaires(source);
  const trouves: Scrutation[] = [];
  const motif = /refetchInterval\s*:\s*([^,\n]+)/g;
  let m: RegExpExecArray | null;
  while ((m = motif.exec(net)) !== null) {
    const valeur = (m[1] ?? '').trim();
    // `5_000` — le separateur de milliers de JavaScript.
    const nombre = /^\d[\d_]*$/.test(valeur) ? Number(valeur.replace(/_/g, '')) : null;
    trouves.push({
      fichier,
      cadenceMs: nombre,
      // Une cadence qui n'est pas un nombre en dur est une expression : elle
      // peut rendre `false` et couper la scrutation.
      conditionnelle: nombre === null,
    });
  }
  return trouves;
}

/** Liste recursivement les `*.ts`/`*.tsx` d'un dossier. */
export function listerModules(dossier: string): string[] {
  const sorties: string[] = [];
  for (const entree of readdirSync(dossier)) {
    const complet = path.join(dossier, entree);
    if (statSync(complet).isDirectory()) sorties.push(...listerModules(complet));
    else if (entree.endsWith('.ts') || entree.endsWith('.tsx')) sorties.push(complet);
  }
  return sorties;
}

// ── Le registre ───────────────────────────────────────────────────────────

/**
 * Les huit scrutations du depot, relevees le 2026-08-20.
 *
 * Ajouter une scrutation sans l'inscrire ici fait rougir la regle A. Le motif
 * n'est pas decoratif : il doit dire POURQUOI l'ecran ne peut pas attendre un
 * evenement temps reel (`laravel-echo` est deja cable, cf. `src/lib/echo.ts`).
 */
const REGISTRE: ReadonlyArray<{ fichier: string; nombre: number; motif: string }> = [
  {
    fichier: 'src/features/campaigns/CampaignDetailPage.tsx',
    nombre: 2,
    motif:
      'Suivi live d une collecte : etat de la campagne + statistiques de debit. ' +
      'Les deux DOIVENT s arreter des que la campagne est dans un etat terminal.',
  },
  {
    fichier: 'src/features/campaigns/CampaignsListPage.tsx',
    nombre: 1,
    motif:
      'Liste des collectes : les statuts changent sans action de l operateur, '
      + 'et aucun evenement de campagne n est diffuse sur laravel-echo. 10 s.',
  },
  {
    fichier: 'src/features/scraping/ScraperRunsPage.tsx',
    nombre: 1,
    motif: 'Journal des executions de scraping, avance en continu. 10 s.',
  },
  {
    fichier: 'src/features/dashboard/DashboardPage.tsx',
    nombre: 1,
    motif:
      'Compteurs de la page d accueil, alimentes par des agregats que le serveur '
      + 'ne diffuse pas en temps reel. 30 s.',
  },
  {
    fichier: 'src/features/observability/ObservabilityPage.tsx',
    nombre: 1,
    motif:
      'Tableau de bord d exploitation : files BullMQ et sante des workers, '
      + 'aucun canal temps reel cote serveur. 30 s.',
  },
  {
    fichier: 'src/features/audiences/AudiencesListPage.tsx',
    nombre: 1,
    motif: 'Etat de rafraichissement des audiences. 30 s.',
  },
  {
    fichier: 'src/features/coverage/CoveragePage.tsx',
    nombre: 1,
    motif: 'Carte de couverture, l ecran annonce « Live · refresh 60s ». 60 s.',
  },
];

// ── Lecture du depot ──────────────────────────────────────────────────────

const modules = listerModules(SRC);
const scrutations: Scrutation[] = [];
for (const complet of modules) {
  const relatif = path.relative(RACINE_FRONT, complet).split(path.sep).join('/');
  scrutations.push(...relevesScrutation(readFileSync(complet, 'utf8'), relatif));
}

// ── Verrous anti-« vert sans mesure » ─────────────────────────────────────

describe('scrutation periodique — la garde a bien le depot sous les yeux', () => {
  it('parcourt de vrais modules', () => {
    // Au 2026-08-20 : 216 modules dans `src/`.
    expect(modules.length).toBeGreaterThanOrEqual(100);
  });

  it('trouve de vraies scrutations', () => {
    // Huit, recomptees a la main. Le plancher detecte l'effondrement (chemin
    // faux, dossier deplace) : sans lui, « zero scrutation trouvee » se lirait
    // comme « aucune scrutation fautive ».
    expect(scrutations.length).toBeGreaterThanOrEqual(8);
  });

  it('ne compte pas la prose comme une scrutation', () => {
    // `CampaignDetailPage.tsx:10` cite `refetchInterval` en commentaire.
    // Ce fichier doit en porter DEUX, pas trois.
    const surLaFiche = scrutations.filter(
      (s) => s.fichier === 'src/features/campaigns/CampaignDetailPage.tsx',
    );
    expect(surLaFiche).toHaveLength(2);
  });
});

// ── Les regles ────────────────────────────────────────────────────────────

describe('scrutation periodique — regle A : rien en silence', () => {
  it('toute scrutation de src/ est inscrite au registre', () => {
    const inscrits = new Set(REGISTRE.map((e) => e.fichier));
    const orphelines = [...new Set(scrutations.map((s) => s.fichier))].filter(
      (f) => !inscrits.has(f),
    );
    expect(orphelines).toEqual([]);
  });

  it('le registre annonce le bon nombre par fichier', () => {
    const ecarts: string[] = [];
    for (const { fichier, nombre } of REGISTRE) {
      const reel = scrutations.filter((s) => s.fichier === fichier).length;
      if (reel !== nombre) ecarts.push(`${fichier}: registre ${nombre}, mesure ${reel}`);
    }
    expect(ecarts).toEqual([]);
  });

  it('aucune inscription ne porte sur un fichier qui ne scrute plus', () => {
    // Un motif orphelin est un mensonge qui dort.
    const vus = new Set(scrutations.map((s) => s.fichier));
    expect(REGISTRE.map((e) => e.fichier).filter((f) => !vus.has(f))).toEqual([]);
  });

  it('chaque motif est ecrit, pas esquive', () => {
    const courts = REGISTRE.filter((e) => e.motif.length < 40).map((e) => e.fichier);
    expect(courts).toEqual([]);
  });
});

describe('scrutation periodique — regle B : la plus rapide sait s arreter', () => {
  it('aucune scrutation a 5 s ou moins n est une cadence en dur', () => {
    const fautives = scrutations
      .filter((s) => s.cadenceMs !== null && s.cadenceMs <= CADENCE_RAPIDE_MS)
      .map((s) => `${s.fichier} @ ${String(s.cadenceMs)} ms`);
    // Diff lisible : vitest nomme le fichier et la cadence.
    expect(fautives).toEqual([]);
  });

  it('aucun ecran ne porte DEUX scrutations inconditionnelles a 5 s ou moins', () => {
    // La formulation exacte du constat G42-007. Sur `/campaigns/{id}`, deux
    // cadences a 5 s font 24 requetes par minute.
    const parFichier = new Map<string, number>();
    for (const s of scrutations) {
      if (s.conditionnelle) continue;
      if (s.cadenceMs === null || s.cadenceMs > CADENCE_RAPIDE_MS) continue;
      parFichier.set(s.fichier, (parFichier.get(s.fichier) ?? 0) + 1);
    }
    const doubles = [...parFichier.entries()].filter(([, n]) => n >= 2).map(([f]) => f);
    expect(doubles).toEqual([]);
  });
});

// ── TEMOIN ────────────────────────────────────────────────────────────────

describe('scrutation periodique — TEMOIN de la garde', () => {
  it('voit les deux cadences a 5 s telles qu elles etaient ecrites', () => {
    // Recopie fidele de `CampaignDetailPage.tsx:61-73` avant reparation.
    const avant = [
      "  const { data: campaign } = useQuery({",
      "    queryKey: ['campaign', id],",
      "    refetchInterval: 5_000,",
      "  });",
      "  const { data: stats } = useQuery({",
      "    queryKey: ['campaign-stats', id],",
      "    refetchInterval: 5_000,",
      "  });",
    ].join('\n');
    const releves = relevesScrutation(avant, 'x.tsx');
    // C'EST le defaut G42-007 en miniature : deux, a 5 s, dans un fichier.
    expect(releves).toHaveLength(2);
    expect(releves.every((s) => s.cadenceMs === 5000)).toBe(true);
    expect(releves.every((s) => s.conditionnelle)).toBe(false);
  });

  it('reconnait une cadence conditionnelle', () => {
    const apres = "    refetchInterval: (q) => (estTermine(q) ? false : 5_000),";
    const releves = relevesScrutation(apres, 'x.tsx');
    expect(releves).toHaveLength(1);
    expect(releves[0]?.conditionnelle).toBe(true);
    expect(releves[0]?.cadenceMs).toBeNull();
  });

  it('ne prend pas le commentaire de la ligne 10 pour une scrutation', () => {
    const prose = [
      ' *  - Refresh 5s via refetchInterval',
      '// refetchInterval: 5_000,   <- ancienne forme',
      '    refetchInterval: 30_000,',
    ].join('\n');
    const releves = relevesScrutation(prose, 'x.tsx');
    // Une seule, celle qui s'execute. C'est cette confusion qui a fait
    // annoncer NEUF scrutations la ou il y en a huit.
    expect(releves).toHaveLength(1);
    expect(releves[0]?.cadenceMs).toBe(30000);
  });

  it('lit le separateur de milliers', () => {
    expect(relevesScrutation('refetchInterval: 60_000,', 'x')[0]?.cadenceMs).toBe(60000);
    expect(relevesScrutation('refetchInterval: 10000,', 'x')[0]?.cadenceMs).toBe(10000);
  });

  it('ne passe pas au vert sur une source vide', () => {
    expect(relevesScrutation('', 'x')).toEqual([]);
    // C'est le plancher de la premiere section qui refuse ce vert-la.
    expect(scrutations.length).toBeGreaterThanOrEqual(8);
    expect(REGISTRE.length).toBeGreaterThanOrEqual(7);
  });

  it('sait voir une scrutation NON inscrite au registre', () => {
    const inscrits = new Set(REGISTRE.map((e) => e.fichier));
    const intruse: Scrutation = {
      fichier: 'src/features/nouveau/EcranSurprise.tsx',
      cadenceMs: 2000,
      conditionnelle: false,
    };
    expect(inscrits.has(intruse.fichier)).toBe(false);
    // La regle A, appliquee a cette intruse, rougirait.
    expect([intruse.fichier].filter((f) => !inscrits.has(f))).toEqual([intruse.fichier]);
  });
});
