import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createHmac } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * LA COUTURE LA PLUS FRAGILE DU SYSTÈME : l'émetteur Node
 * (`src/bridge/result-sender.ts`) et le vérificateur PHP
 * (`backend/app/Http/Controllers/Internal/ScraperResultController.php`) sont
 * deux implémentations INDÉPENDANTES d'un même protocole, dans deux langages,
 * couvertes par deux suites qui ne se parlent pas.
 *
 * Si l'un des deux change de chaîne signée, d'algorithme, d'encodage ou
 * d'en-tête, RIEN ne rougit : le worker POSTe, Laravel répond 401, le job est
 * perdu en silence. D'où ces tests : ils LISENT le vérificateur PHP et prouvent
 * que l'émetteur Node produit ce que CE vérificateur-là accepterait.
 */

const WORKERS_DIR = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const REPO_ROOT = resolve(WORKERS_DIR, '..');

const CONTROLEUR = 'backend/app/Http/Controllers/Internal/ScraperResultController.php';
const PIVOT = 'backend/app/Crm/Scraping/ScrapedRecord.php';

function lirePhp(cheminRelatifAuDepot: string): string {
  const chemin = resolve(REPO_ROOT, cheminRelatifAuDepot);
  try {
    // CRLF → LF : sous Windows toute regex cherchant un `\n` littéral est
    // aveugle, rouge en local et verte en CI (ou l'inverse).
    return readFileSync(chemin, 'utf-8').replace(/\r\n/g, '\n');
  } catch {
    throw new Error(
      `Fichier introuvable : ${chemin}. Ce test prouve l'accord Node ↔ PHP sur la signature ; ` +
        `si le vérificateur a déménagé, corriger le chemin — ne pas neutraliser le test.`,
    );
  }
}

interface RequeteCapturee {
  url: string;
  body: unknown;
  config: { headers?: Record<string, string>; timeout?: number };
}

const bouchon = vi.hoisted(() => {
  const posts: RequeteCapturee[] = [];
  let echec: Error | null = null;
  return {
    posts,
    faireEchouer(e: Error | null): void {
      echec = e;
    },
    post(url: string, body: unknown, config: RequeteCapturee['config']): Promise<unknown> {
      posts.push({ url, body, config });
      return echec ? Promise.reject(echec) : Promise.resolve({ status: 200, data: { ingested: true } });
    },
  };
});

vi.mock('axios', () => ({ default: { post: bouchon.post } }));

import { sendResult, type ScrapeResult } from '../src/bridge/result-sender';

const RESULTAT: ScrapeResult = {
  run_id: 'run-42',
  source: 'google-maps',
  status: 'success',
  payload: { html_preview: '<html>…</html>' },
  emails: ['contact@exemple.fr'],
  phones: ['+33123456789'],
  latency_ms: 1234,
  fetched_at: '2026-08-18T10:00:00.000Z',
};

const SECRET = 'secret-partagé-hmac';

beforeEach(() => {
  bouchon.posts.length = 0;
  bouchon.faireEchouer(null);
  vi.unstubAllEnvs();
  vi.stubEnv('WORKER_INTERNAL_RESULT_URL', 'http://api.test/internal/scraper-result');
  vi.stubEnv('WORKER_INTERNAL_HMAC_SECRET', SECRET);
});
afterEach(() => {
  vi.unstubAllEnvs();
});

describe('result-sender — forme de la requête', () => {
  it("POSTe sur l'URL configurée, avec un délai de garde", async () => {
    await sendResult(RESULTAT);
    expect(bouchon.posts).toHaveLength(1);
    expect(bouchon.posts[0]!.url).toBe('http://api.test/internal/scraper-result');
    expect(bouchon.posts[0]!.config.timeout).toBe(10_000);
  });

  it('envoie une CHAÎNE, pas un objet — sinon axios re-sérialise et la signature ne porte plus sur le corps transmis', async () => {
    // Le piège : `axios.post(url, objet)` sérialise lui-même. La signature est
    // calculée sur `JSON.stringify(result)` ; si axios produit une autre chaîne
    // (ordre des clés, espaces), le HMAC vérifié côté PHP porte sur un corps
    // DIFFÉRENT de celui signé → 401 systématique et silencieux.
    await sendResult(RESULTAT);
    const corps = bouchon.posts[0]!.body;
    expect(typeof corps).toBe('string');
    expect(JSON.parse(corps as string)).toEqual(RESULTAT);
  });

  it("porte la signature dans l'en-tête X-Worker-Signature, en hexadécimal minuscule de 64 caractères", async () => {
    await sendResult(RESULTAT);
    const entetes = bouchon.posts[0]!.config.headers;
    expect(entetes?.['X-Worker-Signature']).toMatch(/^[0-9a-f]{64}$/);
    expect(entetes?.['Content-Type']).toBe('application/json');
  });

  it("propage l'échec réseau (le job doit être rejoué, pas tenu pour envoyé)", async () => {
    bouchon.faireEchouer(new Error('ECONNREFUSED'));
    await expect(sendResult(RESULTAT)).rejects.toThrow('ECONNREFUSED');
  });
});

describe('result-sender — accord avec le vérificateur PHP', () => {
  it('la signature émise est exactement celle que le contrôleur PHP recalculerait', async () => {
    await sendResult(RESULTAT);
    const corpsTransmis = bouchon.posts[0]!.body as string;
    const signatureEmise = bouchon.posts[0]!.config.headers!['X-Worker-Signature'];

    // Reproduction du calcul PHP : hash_hmac('sha256', $r->getContent(), $secret)
    // — clé = secret, message = corps BRUT transmis, sortie hexadécimale.
    const attenduCotePhp = createHmac('sha256', SECRET).update(corpsTransmis).digest('hex');

    expect(signatureEmise).toBe(attenduCotePhp);
  });

  it('une signature calculée sur un AUTRE corps ne vaut pas (le test lui-même sait échouer)', async () => {
    await sendResult(RESULTAT);
    const signatureEmise = bouchon.posts[0]!.config.headers!['X-Worker-Signature'];
    const surUnAutreCorps = createHmac('sha256', SECRET).update('{"run_id":"autre"}').digest('hex');
    expect(signatureEmise).not.toBe(surUnAutreCorps);
  });

  it('le vérificateur PHP signe toujours le corps NU (ni horodatage ni préfixe), en sha256 hexadécimal', () => {
    const php = lirePhp(CONTROLEUR);

    expect(php, "le contrôleur ne lit plus l'en-tête X-Worker-Signature").toContain(
      "$r->header('X-Worker-Signature')",
    );
    expect(php, 'le contrôleur ne signe plus le corps brut de la requête').toContain('$body = $r->getContent();');
    expect(php, "l'algorithme ou l'encodage a changé côté PHP").toContain("hash_hmac('sha256', $body, $secret)");
    expect(php, 'la comparaison à temps constant a disparu').toContain('hash_equals(');

    // `App\Support\HmacSignature::signedPayload()` existe déjà et signe
    // « <horodatage>.<corps> ». Le jour où le contrôleur migre dessus,
    // `result-sender.ts` DOIT émettre l'horodatage dans le MÊME commit.
    expect(
      php,
      'Le contrôleur semble migrer vers HmacSignature (corps signé « timestamp.body ») : ' +
        'result-sender.ts signe encore le corps NU et partira en 401. À corriger dans LE MÊME commit.',
    ).not.toContain('signedPayload');
  });

  it("le nom de la variable d'environnement du secret est le même des deux côtés", () => {
    const php = lirePhp(CONTROLEUR);
    const node = readFileSync(resolve(WORKERS_DIR, 'src/bridge/result-sender.ts'), 'utf-8');
    expect(php).toContain("env('WORKER_INTERNAL_HMAC_SECRET'");
    expect(node).toContain("process.env['WORKER_INTERNAL_HMAC_SECRET']");
  });
});

describe('result-sender — accord avec le schéma pivot ScrapedRecord', () => {
  /** `private const TOP_LEVEL_KEYS = [...]` du pivot PHP. */
  function clesPivot(): string[] {
    const php = lirePhp(PIVOT);
    const bloc = /private const TOP_LEVEL_KEYS = \[([\s\S]*?)\n {4}\];/.exec(php);
    if (!bloc?.[1]) throw new Error('`private const TOP_LEVEL_KEYS = [...]` introuvable dans ScrapedRecord.php');
    return [...bloc[1].matchAll(/'([a-z_]+)'/g)].map((m) => m[1]!);
  }

  it('toute clé émise par le worker est connue du pivot (sinon : rejet « clé inconnue »)', async () => {
    await sendResult(RESULTAT);
    const emises = Object.keys(JSON.parse(bouchon.posts[0]!.body as string) as Record<string, unknown>);
    const connues = clesPivot();
    expect(connues.length).toBeGreaterThan(0);
    for (const cle of emises) {
      expect(
        connues,
        `Le worker émet « ${cle} », que ScrapedRecord::fromArray() refuse (schéma STRICT : toute clé inconnue rejette le message).`,
      ).toContain(cle);
    }
  });

  it('CONSTAT — le corps émis serait REFUSÉ par le funnel strict (schema_version + ancre entreprise manquants)', async () => {
    // Ce test ÉPINGLE un défaut mesuré, il ne le valide pas.
    // `ScrapedRecord::fromArray()` exige `schema_version === 1`, puis au moins
    // une ancre (`company.siren`, `company.foreign_id` ou `company.match_hint`).
    // `result-sender.ts` n'émet ni l'un ni l'autre : sous
    // `CRM_SCRAPE_FUNNEL_ENABLED=true`, 100 % des résultats Node repartiraient
    // en 422 `unsupported_schema_version`. Le drapeau étant OFF par défaut, le
    // chemin historique (log + 200) masque entièrement le problème.
    //
    // QUAND l'émetteur sera corrigé, CE TEST DOIT ÊTRE INVERSÉ (pas supprimé).
    await sendResult(RESULTAT);
    const corps = JSON.parse(bouchon.posts[0]!.body as string) as Record<string, unknown>;
    expect(corps['schema_version'], 'défaut corrigé ? inverser ce test').toBeUndefined();
    expect(corps['company'], 'défaut corrigé ? inverser ce test').toBeUndefined();

    const php = lirePhp(PIVOT);
    expect(php).toContain('public const SCHEMA_VERSION = 1;');
    expect(php).toContain("'missing_company_anchor'");
  });
});
