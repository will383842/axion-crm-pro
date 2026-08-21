import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * GARDE C18-001 (S1), MOITIÉ NODE — « l'endpoint répond "ingested: true" sans
 * rien ingérer ».
 *
 * Le correctif est côté PHP : quand `CRM_SCRAPE_FUNNEL_ENABLED` est faux — sa
 * valeur PAR DÉFAUT — `/internal/scraper-result` répond désormais
 * `200 {"ingested": false, "raison": "funnel_ferme"}` au lieu d'un
 * `{"ingested": true}` menteur.
 *
 * POURQUOI CE FICHIER EXISTE ICI, ET PAS SEULEMENT EN PEST.
 *
 * Le conteneur de test backend ne monte que `backend/` : la suite Pest ne PEUT
 * PAS lire `workers/`. Or ce correctif touche un contrat entre deux dépôts
 * écrits dans deux langages, dont ce dossier `tests/` documente déjà qu'il est
 * « la couture la plus fragile du système ». Deux choses doivent donc rester
 * vraies EN MÊME TEMPS, et rien d'autre ne le vérifie :
 *
 *   1. le producteur Node IGNORE le corps de la réponse — c'est ce qui rend le
 *      changement de corps sûr. S'il se met un jour à le lire, il lira `false`
 *      sur le chemin par défaut, et ce test doit rougir AVANT ;
 *   2. le vérificateur PHP conserve le STATUT 200 sur ce chemin. Axios lève sur
 *      tout ce qui n'est pas 2xx, et `base.ts` traite cette levée comme un échec
 *      de job : remise en file, puis abandon en `status: 'failed'`. Répondre 202
 *      ou 4xx pour dire « funnel fermé » ferait rejouer puis PERDRE les
 *      collectes — un défaut strictement pire que celui qu'on répare.
 */

const WORKERS_DIR = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const REPO_ROOT = resolve(WORKERS_DIR, '..');
const CONTROLEUR = 'backend/app/Http/Controllers/Internal/ScraperResultController.php';

function lire(cheminRelatifAuDepot: string): string {
  const chemin = resolve(REPO_ROOT, cheminRelatifAuDepot);
  try {
    // CRLF → LF : sous Windows toute regex cherchant un `\n` littéral est
    // aveugle, rouge en local et verte en CI (ou l'inverse).
    return readFileSync(chemin, 'utf-8').replace(/\r\n/g, '\n');
  } catch {
    throw new Error(
      `Fichier introuvable : ${chemin}. Ce test garde le contrat Node ↔ PHP sur la véracité de la ` +
        `réponse d'ingestion ; si le fichier a déménagé, corriger le chemin — ne pas neutraliser le test.`,
    );
  }
}

interface RequeteCapturee {
  url: string;
  body: unknown;
  config: { headers?: Record<string, string>; timeout?: number };
}

/**
 * Bouchon axios qui rend la réponse RÉELLE du funnel fermé — celle que le PHP
 * émet désormais. Le test qui suit prouve que `sendResult` s'en accommode.
 */
const bouchon = vi.hoisted(() => {
  const posts: RequeteCapturee[] = [];
  let reponse: unknown = { status: 200, data: { ingested: false, raison: 'funnel_ferme' } };
  return {
    posts,
    repondre(r: unknown): void {
      reponse = r;
    },
    post(url: string, body: unknown, config: RequeteCapturee['config']): Promise<unknown> {
      posts.push({ url, body, config });
      return Promise.resolve(reponse);
    },
  };
});

vi.mock('axios', () => ({ default: { post: bouchon.post } }));

import { sendResult, type ScrapeResult } from '../src/bridge/result-sender';

const RESULTAT: ScrapeResult = {
  run_id: 'run-c18',
  source: 'google-maps',
  status: 'success',
  latency_ms: 12,
  fetched_at: '2026-08-20T09:00:00.000Z',
};

beforeEach(() => {
  bouchon.posts.length = 0;
  bouchon.repondre({ status: 200, data: { ingested: false, raison: 'funnel_ferme' } });
  vi.unstubAllEnvs();
  vi.stubEnv('WORKER_INTERNAL_RESULT_URL', 'http://api.test/internal/scraper-result');
  vi.stubEnv('WORKER_INTERNAL_HMAC_SECRET', 'secret-c18');
});
afterEach(() => {
  vi.unstubAllEnvs();
});

describe('C18-001 — le producteur Node survit à une réponse « ingested: false »', () => {
  it("n'échoue pas quand le funnel est fermé (le corps de la réponse ne le concerne pas)", async () => {
    // C'est la vérification qu'il fallait faire AVANT de changer la réponse :
    // si `sendResult` branchait sur `ingested`, le correctif PHP transformerait
    // 100 % des envois en échecs de job.
    await expect(sendResult(RESULTAT)).resolves.toBeUndefined();
    expect(bouchon.posts).toHaveLength(1);
  });

  it("se comporte à l'identique quel que soit le corps rendu — y compris un corps absurde", async () => {
    // TÉMOIN : la garde ci-dessus ne serait qu'un « ça passe » si elle ne
    // montrait pas que le corps n'a AUCUNE influence. On lui rend trois corps
    // radicalement différents et on exige le même comportement.
    for (const corps of [
      { ingested: true },
      { ingested: false, raison: 'funnel_ferme' },
      { nawak: null },
    ]) {
      bouchon.repondre({ status: 200, data: corps });
      await expect(sendResult(RESULTAT)).resolves.toBeUndefined();
    }
    expect(bouchon.posts).toHaveLength(3);
  });

  it('la source de `result-sender.ts` ne lit NI la réponse NI le champ « ingested »', () => {
    // Garde statique : le test dynamique ci-dessus ne verrait pas un futur
    // `const { data } = await axios.post(...)` suivi d'un branchement, si ce
    // branchement n'était atteint que dans un cas non couvert. Ici on épingle
    // la forme : la valeur de retour d'axios est JETÉE.
    const node = readFileSync(resolve(WORKERS_DIR, 'src/bridge/result-sender.ts'), 'utf-8');

    expect(node.includes('await axios.post(')).toBe(true);
    expect(
      /=\s*await\s+axios\.post/.test(node),
      "result-sender.ts capture désormais la réponse d'axios. S'il en lit le corps, il lira " +
        "`ingested: false` sur le chemin PAR DÉFAUT (funnel fermé) : vérifier qu'il ne le traite " +
        "pas comme un échec avant de lever cette garde.",
    ).toBe(false);
    expect(
      node.includes('ingested'),
      "result-sender.ts mentionne « ingested » : le producteur branche sur un champ qui vaut " +
        'false par défaut. Voir C18-001.',
    ).toBe(false);

    // `base.ts` non plus : c'est lui qui décide du sort du job.
    const base = readFileSync(resolve(WORKERS_DIR, 'src/scrapers/base.ts'), 'utf-8');
    expect(base.includes('ingested')).toBe(false);
  });
});

describe('C18-001 — le vérificateur PHP dit la vérité, et garde son 200', () => {
  it('la branche « funnel fermé » répond ingested:false + raison:funnel_ferme', () => {
    // ⚠️ CE TEST A DÉJÀ ÉTÉ FAUX UNE FOIS, ET LA MESURE L'A MONTRÉ.
    //
    // Première rédaction : `controleur.includes("'ingested' => false")` sur le
    // fichier ENTIER. Témoin joué le 2026-08-20 en remettant le défaut en place
    // (`'ingested' => true` dans la réponse) : l'assertion est restée VERTE —
    // parce que le `Log::info` de la même branche contient lui aussi
    // `'ingested' => false`. Elle mesurait la ligne de journal, pas la réponse.
    // C'est exactement le motif que tout ce lot poursuit. On mesure donc le
    // TABLEAU RENDU, et rien d'autre.
    const controleur = lire(CONTROLEUR);

    const branche = controleur.slice(
      controleur.indexOf('scrape_funnel.enabled'),
      controleur.indexOf('try {'),
    );
    expect(branche.length).toBeGreaterThan(0);

    const rendu = branche.slice(branche.indexOf('return $this->ok('));
    expect(rendu.length).toBeGreaterThan(0);

    expect(
      rendu.includes("'ingested' => false"),
      'La réponse du funnel fermé ne porte plus `ingested => false` (C18-001).',
    ).toBe(true);
    expect(
      rendu.includes("'raison' => 'funnel_ferme'"),
      'La réponse ne dit plus POURQUOI rien n\'est ingéré : la clé `raison` est un contrat.',
    ).toBe(true);
    expect(
      rendu.includes("'ingested' => true"),
      'La branche « funnel fermé » réaffirme une ingestion qui n\'a pas lieu (C18-001).',
    ).toBe(false);
  });

  it("le STATUT reste 200 : `$this->ok(` et non un `response()->json(..., 4xx)`", () => {
    // Contrainte dure côté Node : axios lève sur tout non-2xx et `base.ts`
    // traite la levée comme un échec de job (remise en file, puis abandon en
    // `status: 'failed'`). Un 202 conviendrait sémantiquement mieux mais rien
    // ne l'exige, et `ok()` fixe 200 par défaut.
    const controleur = lire(CONTROLEUR);
    const branche = controleur.slice(
      controleur.indexOf('scrape_funnel.enabled'),
      controleur.indexOf('try {'),
    );

    expect(branche.includes('$this->ok(')).toBe(true);
    expect(
      /return response\(\)->json\([\s\S]*?,\s*[45]\d\d\)/.test(branche),
      'La branche « funnel fermé » rend un statut d\'erreur : les workers rejoueraient puis ' +
        'PERDRAIENT les résultats de collecte. Voir base.ts l. 185-201.',
    ).toBe(false);
  });
});
