/**
 * PREUVE DE LA CONSOLE EN LOCAL — ligne 1 des préalables CRM Pro.
 *
 * Critère de sortie : « la console tourne en local, connexion, 2FA, tous les
 * écrans v2 ouverts, sans NODE_TLS_REJECT_UNAUTHORIZED=0 ».
 *
 * Ce spec ne vérifie pas des composants : il ouvre RÉELLEMENT chaque écran de
 * l'application dans un navigateur, derrière l'ORIGINE UNIQUE `app.localhost`,
 * avec une session Sanctum obtenue par le formulaire de connexion puis par
 * l'écran 2FA. C'est le seul chemin qui prouve que le COOKIE fonctionne :
 * l'incident D-11 (401 puis 419) ne se reproduit pas sous curl, il se
 * reproduit sous navigateur, parce qu'il naît du couple SameSite/domaine.
 *
 * ── Ce que le spec refuse explicitement ────────────────────────────────────
 *  1. `NODE_TLS_REJECT_UNAUTHORIZED=0` : Caddy sert un certificat `tls
 *     internal`. `ignoreHTTPSErrors: true` (playwright.config.ts) suffit et
 *     reste borné au navigateur de test. Désarmer TLS au niveau du process
 *     Node contaminerait toute requête sortante, y compris celles qui n'ont
 *     rien à voir avec le test — on ne le fait pas, et un test le constate.
 *  2. Toute requête vers une origine autre que `https://app.localhost` :
 *     c'est la garde anti-« mon local parle à la prod ». Le frontend est
 *     construit avec `VITE_API_BASE_URL=https://app.localhost` ; si quelqu'un
 *     reconstruit l'image en pointant l'API ailleurs, ce test rougit.
 *  3. Tout 401 ou 419 pendant la visite d'un écran : c'est la signature
 *     exacte de D-11 (401 = cookie non envoyé, 419 = session/CSRF perdue).
 *
 * ── Le secret TOTP ─────────────────────────────────────────────────────────
 * Il n'est écrit NULLE PART dans le dépôt. Le spec le lit depuis
 * `E2E_TOTP_SECRET`, et à défaut le demande à la base via l'API (Eloquent
 * déchiffre le cast `encrypted`). Le code à 6 chiffres est ensuite calculé
 * ici, en RFC 6238 — s'il était faux, l'écran 2FA refuserait et le test
 * échouerait bruyamment : le calcul se contrôle donc lui-même.
 */
import { test, expect, type Page, type Browser } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// ── Paramètres du compte de vérification ──────────────────────────────────
const EMAIL = process.env['E2E_EMAIL'] ?? 'console-locale@axion-ia.test';
const PASSWORD = process.env['E2E_PASSWORD'] ?? 'ConsoleLocale2026!';
const API_CONTAINER = process.env['E2E_API_CONTAINER'] ?? 'axion-crm-api';

/** L'origine unique. Toute autre origine est une anomalie, pas une variante. */
const ORIGIN = new URL(process.env['E2E_BASE_URL'] ?? 'https://app.localhost').origin;

// Le dépôt est en modules ES (`"type": "module"`) : `__dirname` n'y existe pas.
const HERE = path.dirname(fileURLToPath(import.meta.url));
const CAPTURES = path.join(HERE, '__captures__', 'console-locale');

// ── TOTP RFC 6238 (SHA-1, 6 chiffres, pas de 30 s) ────────────────────────

function base32Decode(input: string): Buffer {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = 0;
  let value = 0;
  const out: number[] = [];
  for (const char of input.replace(/=+$/, '').toUpperCase()) {
    const index = alphabet.indexOf(char);
    if (index === -1) continue;
    value = (value << 5) | index;
    bits += 5;
    if (bits >= 8) {
      out.push((value >>> (bits - 8)) & 0xff);
      bits -= 8;
    }
  }
  return Buffer.from(out);
}

function totp(secret: string, atMs: number = Date.now()): string {
  const counter = Math.floor(atMs / 1000 / 30);
  const buf = Buffer.alloc(8);
  buf.writeUInt32BE(Math.floor(counter / 2 ** 32), 0);
  buf.writeUInt32BE(counter >>> 0, 4);
  const hmac = createHmac('sha1', base32Decode(secret)).update(buf).digest();
  const offset = hmac[hmac.length - 1]! & 0x0f;
  const code =
    (((hmac[offset]! & 0x7f) << 24) |
      ((hmac[offset + 1]! & 0xff) << 16) |
      ((hmac[offset + 2]! & 0xff) << 8) |
      (hmac[offset + 3]! & 0xff)) %
    1_000_000;
  return String(code).padStart(6, '0');
}

/**
 * Le secret vit chiffré en base (`users.totp_secret`, cast `encrypted`). Seule
 * l'application sait le relire — d'où le passage par artisan plutôt que par
 * psql, qui ne rendrait que le chiffré.
 *
 * 🔴 BUDGET DE TEMPS. `php artisan` coûte **79 s** d'horloge par invocation
 * dans ce conteneur (mesuré le 2026-08-18 : `time docker exec axion-crm-api
 * php artisan --version` → `real 1m18.889s`, pour 0,15 s de CPU). Le coût est
 * celui du bind-mount Windows : amorcer Laravel lit des milliers de fichiers,
 * et l'opcache du php-fpm ne sert à rien pour un processus CLI neuf. Un
 * `timeout` de 60 ou 120 s échoue donc SYSTÉMATIQUEMENT, sur une commande
 * parfaitement correcte — c'est ce qui a fait rougir ce test le 2026-08-18.
 *
 * Chemin rapide, à préférer : poser `E2E_TOTP_SECRET` (le secret est imprimé
 * par la commande de création du compte, cf. runbook §5.1).
 */
function readTotpSecret(): string {
  const fromEnv = process.env['E2E_TOTP_SECRET'];
  if (fromEnv && fromEnv.trim() !== '') return fromEnv.trim();

  const php = `$u = \\App\\Models\\User::where("email", "${EMAIL}")->first(); echo $u ? $u->totp_secret : "";`;
  const out = execFileSync(
    'docker',
    ['exec', API_CONTAINER, 'php', 'artisan', 'tinker', '--execute', php],
    { encoding: 'utf8', timeout: 300_000, input: '' },
  );
  const secret = out.trim().split(/\s+/).pop() ?? '';
  if (!/^[A-Z2-7]{16,}$/.test(secret)) {
    throw new Error(
      `Secret TOTP illisible pour ${EMAIL} (sortie artisan : ${JSON.stringify(out)}). ` +
        `Le compte de vérification existe-t-il ? Voir _REPORTS/RUNBOOK-CONSOLE-LOCALE.md §3.`,
    );
  }
  return secret;
}

// ── Écrans à ouvrir ───────────────────────────────────────────────────────
//
// Liste dérivée de `src/app/routeTree.tsx`, enfants de `layoutRoute` — c'est
// la seule définition des écrans sous coquille. Les 4 routes hors layout
// (/login, /2fa, /magic-link, /password-reset) sont traversées par le
// parcours de connexion lui-même, pas listées ici.
//
// ⚠️ `/crm` et `/analytics` (bouchons 501 de la Phase 2) sont volontairement
// ABSENTS : l'étape 0 les supprime. Les lister aurait figé dans un test ce que
// le chantier retire.
//
// La base locale est VIDE (0 company, 0 contact, 0 campagne, 0 audience). Les
// écrans de détail sont donc ouverts avec un identifiant syntaxiquement valide
// mais inexistant : ce qui est prouvé est que l'écran s'OUVRE, se route et
// rend sa coquille — pas qu'il affiche une fiche. C'est dit, et non maquillé.
const ABSENT_UUID = '00000000-0000-4000-8000-000000000000';
const ABSENT_PERSON_KEY = 'a'.repeat(64); // person_key = sha256, 64 hex

interface Screen {
  /** Chemin exact ouvert dans le navigateur. */
  readonly url: string;
  /** Nom lisible, repris dans le rapport et le nom de capture. */
  readonly name: string;
  /** Fichier de capture (sans dossier). */
  readonly shot: string;
  /** Écran de détail ouvert sur un identifiant inexistant (base vide). */
  readonly detailOnAbsentRecord?: boolean;
}

const SCREENS: readonly Screen[] = [
  { url: '/', name: 'Tableau de bord', shot: '01-dashboard.png' },
  { url: '/companies', name: 'Entreprises', shot: '02-companies.png' },
  { url: `/companies/${ABSENT_UUID}`, name: 'Entreprise — fiche', shot: '03-company-detail.png', detailOnAbsentRecord: true },
  { url: '/contacts', name: 'Contacts', shot: '04-contacts.png' },
  { url: '/international/roumanie', name: 'International — Roumanie', shot: '05-international-roumanie.png' },
  { url: '/media', name: 'Médias', shot: '06-media.png' },
  { url: `/media/${ABSENT_UUID}`, name: 'Média — fiche', shot: '07-media-detail.png', detailOnAbsentRecord: true },
  { url: '/journalists', name: 'Journalistes', shot: '08-journalists.png' },
  { url: '/coverage', name: 'Couverture territoriale', shot: '09-coverage.png' },
  { url: '/scraper-runs', name: 'Exécutions de collecte', shot: '10-scraper-runs.png' },
  { url: '/llm/router', name: 'LLM — routeur', shot: '11-llm-router.png' },
  { url: '/llm/proxy-providers', name: 'LLM — fournisseurs proxy', shot: '12-llm-proxy-providers.png' },
  { url: '/llm/rotations', name: 'LLM — rotations', shot: '13-llm-rotations.png' },
  { url: '/rgpd/requests', name: 'RGPD — demandes', shot: '14-rgpd-requests.png' },
  { url: '/rgpd/ai-act', name: 'RGPD — registre AI Act', shot: '15-rgpd-ai-act.png' },
  { url: '/audit-logs', name: "Journal d'audit", shot: '16-audit-logs.png' },
  { url: '/users', name: 'Utilisateurs', shot: '17-users.png' },
  { url: '/settings', name: 'Réglages', shot: '18-settings.png' },
  { url: '/campaigns', name: 'Campagnes', shot: '19-campaigns.png' },
  { url: '/campaigns/new', name: 'Campagne — assistant', shot: '20-campaigns-new.png' },
  { url: `/campaigns/${ABSENT_UUID}`, name: 'Campagne — détail', shot: '21-campaign-detail.png', detailOnAbsentRecord: true },
  { url: '/tags', name: 'Étiquettes', shot: '22-tags.png' },
  { url: '/audiences', name: 'Audiences', shot: '23-audiences.png' },
  { url: '/audiences/new', name: 'Audience — constructeur', shot: '24-audiences-new.png' },
  { url: `/audiences/${ABSENT_UUID}`, name: 'Audience — détail', shot: '25-audience-detail.png', detailOnAbsentRecord: true },
  { url: '/admin/observability', name: 'Observabilité', shot: '26-admin-observability.png' },
  // ── Console CRM v2 (lot L6) — les 4 écrans du critère de sortie ─────────
  { url: '/console/contacts', name: 'Console v2 — hub contacts', shot: '27-console-contacts.png' },
  { url: '/console/vivier', name: 'Console v2 — vivier candidats', shot: '28-console-vivier.png' },
  { url: '/console/arbitrage', name: 'Console v2 — arbitrage', shot: '29-console-arbitrage.png' },
  { url: `/console/personnes/${ABSENT_PERSON_KEY}`, name: 'Console v2 — fiche 360°', shot: '30-console-person.png', detailOnAbsentRecord: true },
  // ── Bouchons Phase 2 conservés à dessein (noms hors périmètre CRM) ──────
  { url: '/cold-email', name: 'Cold email (bouchon Phase 2)', shot: '31-cold-email.png' },
  { url: '/linkedin', name: 'LinkedIn (bouchon Phase 2)', shot: '32-linkedin.png' },
];

// ── Collecteurs réseau ────────────────────────────────────────────────────

interface NetworkIssue {
  readonly kind: 'origine-etrangere' | 'statut-401-419';
  readonly detail: string;
}

let issues: NetworkIssue[] = [];

function resetNetwork(): void {
  issues = [];
}

function attachNetworkGuards(page: Page): void {
  page.on('request', (request) => {
    const url = request.url();
    // Les schémas internes du navigateur ne sortent pas sur le réseau.
    if (/^(data|blob|about|chrome-extension|chrome|devtools):/.test(url)) return;
    const origin = new URL(url).origin;
    if (origin !== ORIGIN) {
      issues.push({
        kind: 'origine-etrangere',
        detail: `${request.method()} ${url} (origine ${origin}, attendue ${ORIGIN})`,
      });
    }
  });

  page.on('response', (response) => {
    const status = response.status();
    if (status === 401 || status === 419) {
      issues.push({
        kind: 'statut-401-419',
        detail: `HTTP ${status} sur ${response.request().method()} ${response.url()}`,
      });
    }
  });
}

/**
 * Le tour d'accueil s'ouvre en modale par-dessus n'importe quel écran tant que
 * `users.onboarding_tour_completed_at` est nul. Il ne masque aucune assertion
 * (le corps de la page reste dans le DOM) mais il OBSCURCIT les captures, qui
 * sont une pièce du dossier. On le referme une fois, après la connexion.
 *
 * Défensif à dessein : si le compte a déjà vu le tour, il n'y a rien à cliquer
 * et la fonction ne fait rien.
 */
async function dismissOnboardingTour(page: Page): Promise<void> {
  const skip = page.getByRole('button', { name: /^(passer|skip)$/i }).first();
  if (await skip.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await skip.click().catch(() => {});
    await page.waitForTimeout(500);
  }
}

/** Attente bornée : les requêtes de la console mettent 2 à 6 s à vide. */
async function settle(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle', { timeout: 45_000 }).catch(() => {
    /* une requête longue ou un sondage périodique ne doit pas faire échouer */
  });
}

// ── Parcours ──────────────────────────────────────────────────────────────

test.describe.configure({ mode: 'serial' });

test.describe('Console CRM Pro — vérification locale de bout en bout', () => {
  let browser0: Browser;
  let page: Page;

  test.beforeAll(async ({ browser }) => {
    browser0 = browser;
    mkdirSync(CAPTURES, { recursive: true });
    // 🔴 `locale` n'est pas un détail de confort.
    //
    // L'application détecte la langue par `['localStorage', 'navigator']`
    // (`src/lib/i18n.ts`). Le navigateur de Playwright annonce `en-US` par
    // défaut : sans cette ligne, la console s'affiche EN ANGLAIS, et tout
    // sélecteur français ne trouve rien. C'est exactement ce qui fait échouer
    // `auth.spec.ts` aujourd'hui — l'échec ressemble à « la page ne charge
    // pas », alors qu'elle charge parfaitement, dans une autre langue.
    const context = await browser0.newContext({
      ignoreHTTPSErrors: true,
      locale: 'fr-FR',
      timezoneId: 'Europe/Paris',
    });
    page = await context.newPage();
    attachNetworkGuards(page);
  });

  test.afterAll(async () => {
    await page?.context().close();
  });

  test("TLS n'est jamais désarmé au niveau du process Node", () => {
    // Le contournement de certificat est BORNÉ au navigateur de test
    // (`ignoreHTTPSErrors` dans playwright.config.ts). S'il fallait en plus
    // désarmer TLS pour tout Node, ce ne serait pas un réglage de test : ce
    // serait un trou, et il voyagerait avec chaque requête sortante.
    expect(
      process.env['NODE_TLS_REJECT_UNAUTHORIZED'],
      "NODE_TLS_REJECT_UNAUTHORIZED est posé : la vérification locale n'a alors plus de valeur de preuve.",
    ).not.toBe('0');
  });

  test('connexion par le formulaire /login', async () => {
    test.setTimeout(120_000);
    resetNetwork();

    await page.goto('/login');
    // Le titre atteste au passage que la console est bien servie en français.
    await expect(page.getByRole('heading', { name: /connexion/i })).toBeVisible({ timeout: 30_000 });

    // Sélecteurs structurels : ils survivent à un changement de libellé.
    await page.locator('input[type="email"]').fill(EMAIL);
    await page.locator('input[type="password"]').fill(PASSWORD);
    await page.locator('form button[type="submit"]').click();

    // `requires_2fa: true` → l'application doit router vers /2fa.
    await page.waitForURL('**/2fa', { timeout: 60_000 });
    await page.screenshot({ path: path.join(CAPTURES, '00a-login-vers-2fa.png'), fullPage: true });

    expect(
      issues.filter((i) => i.kind === 'statut-401-419'),
      'Un 401/419 pendant la connexion = régression D-11 (origine unique cassée).',
    ).toEqual([]);
    expect(issues.filter((i) => i.kind === 'origine-etrangere')).toEqual([]);
  });

  test("écran 2FA : un code TOTP réel ouvre la session", async () => {
    // Large : `readTotpSecret()` peut coûter 79 s si le secret n'est pas fourni
    // par `E2E_TOTP_SECRET` (voir le commentaire de cette fonction).
    test.setTimeout(420_000);
    resetNetwork();

    const secret = readTotpSecret();
    const codeInput = page.locator('input[autocomplete="one-time-code"]');
    await expect(codeInput).toBeVisible({ timeout: 30_000 });

    // Un code calculé à cheval sur une frontière de 30 s peut expirer entre le
    // calcul et la vérification. On réessaie une fois, avec un code frais :
    // c'est un aléa d'horloge, pas un échec de la 2FA.
    for (let attempt = 1; attempt <= 2; attempt++) {
      await codeInput.fill(totp(secret));
      await page.locator('form button[type="submit"]').click();
      try {
        await page.waitForURL((url) => new URL(url).pathname === '/', { timeout: 30_000 });
        break;
      } catch (error) {
        if (attempt === 2) throw error;
        await codeInput.fill('');
        await page.waitForTimeout(31_000); // fenêtre TOTP suivante
      }
    }

    await settle(page);
    await expect(page.locator('#main')).toBeVisible({ timeout: 30_000 });
    await dismissOnboardingTour(page);
    await page.screenshot({ path: path.join(CAPTURES, '00b-2fa-valide.png'), fullPage: true });

    expect(issues.filter((i) => i.kind === 'statut-401-419')).toEqual([]);
    expect(issues.filter((i) => i.kind === 'origine-etrangere')).toEqual([]);
  });

  for (const screen of SCREENS) {
    test(`écran ${screen.url} — ${screen.name}`, async () => {
      test.setTimeout(120_000);
      resetNetwork();

      await page.goto(screen.url, { waitUntil: 'domcontentloaded' });
      await settle(page);

      // 1. On est resté authentifié : ni renvoi vers /login, ni vers /2fa.
      expect(
        new URL(page.url()).pathname,
        "L'écran a renvoyé vers l'authentification : la session n'a pas survécu au chargement direct.",
      ).not.toMatch(/^\/(login|2fa)$/);

      // 2. La coquille admin est rendue. `#main` n'existe QUE dans RootLayout :
      //    son absence signifie soit le 404 (hors layout), soit un écran mort.
      await expect(
        page.locator('#main'),
        "La coquille RootLayout n'est pas rendue (écran 404, blanc, ou crash au montage).",
      ).toBeVisible({ timeout: 30_000 });

      const body = page.locator('body');

      // 3. Aucun écran d'erreur.
      await expect(body, "L'ErrorBoundary a capturé une exception de rendu.").not.toContainText(
        'Une erreur est survenue.',
      );
      await expect(body, 'La route est tombée sur le catch-all 404.').not.toContainText('Page introuvable');

      // 4. Aucun refus de la ConsoleGate — le drapeau CRM_CONSOLE_V2_ENABLED
      //    est censé être à true, et le compte membre des DEUX univers.
      await expect(
        body,
        "ConsoleGate refuse : le drapeau CRM_CONSOLE_V2_ENABLED n'est pas à true côté API.",
      ).not.toContainText('Console non activée');
      await expect(
        body,
        "ConsoleGate refuse le vivier : le compte n'est pas membre non révoqué du workspace vivier-candidats.",
      ).not.toContainText('Univers vivier candidats non accessible');

      await page.screenshot({ path: path.join(CAPTURES, screen.shot), fullPage: true });

      // 5. Réseau : ni 401/419 (D-11), ni origine étrangère (fuite vers la prod).
      expect(
        issues.filter((i) => i.kind === 'statut-401-419').map((i) => i.detail),
        'Signature D-11 : cookie de session non transmis (401) ou session/CSRF perdue (419).',
      ).toEqual([]);
      expect(
        issues.filter((i) => i.kind === 'origine-etrangere').map((i) => i.detail),
        `Requête hors de l'origine unique ${ORIGIN} : le local parle à un autre serveur.`,
      ).toEqual([]);
    });
  }
});
