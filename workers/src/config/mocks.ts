import pino from 'pino';

const log = pino({ name: 'config-mocks', level: process.env['LOG_LEVEL'] ?? 'info' });

/**
 * C18-017 — LE DRAPEAU DES SIMULACRES, LU À UN SEUL ENDROIT.
 *
 * Mesure du 2026-08-22 : les ONZE workers portaient la MÊME ligne 7, recopiée
 * mot pour mot :
 *
 *     const useMock = (process.env['MOCK_SCRAPERS'] ?? process.env['MOCK_MODE'] ?? 'true') !== 'false';
 *
 * Trois choses en découlaient, et seule la première est bonne :
 *
 *   1. ✅ le simulacre ne peut PAS fuir : le défaut est `'true'`, donc un
 *      oubli de configuration laisse les workers sur les mocks, jamais sur les
 *      vrais services payants. Cette propriété est conservée telle quelle.
 *   2. 🔴 la bascule exige la chaîne EXACTE `'false'`. `MOCK_MODE=0`, `=off`,
 *      `=FALSE` laissent le simulacre en place — EN SILENCE. Celui qui croit
 *      avoir branché les vrais scrapers récolte des fixtures, et rien ne le
 *      lui dit.
 *   3. 🔴 `MOCKS-STRATEGY.md` promettait « basculement en 1 ligne
 *      (`MOCK_MODE=false`) » alors qu'il y a DEUX poignées et que la première
 *      (`MOCK_SCRAPERS`) masque la seconde.
 *
 * ── CE QUI N'A DÉLIBÉRÉMENT PAS ÉTÉ FAIT ────────────────────────────────────
 *
 * On n'a PAS rendu la lecture « tolérante » (accepter `0`, `off`, `FALSE`).
 * Ce serait une bascule RÉELLE : un environnement qui pose aujourd'hui
 * `MOCK_MODE=0` en croyant désactiver les mocks passerait, sans que personne
 * ne le demande, sur les VRAIS scrapers — trafic réseau sortant, proxies et
 * captchas facturés. Ce choix appartient à l'exploitant, pas à un correctif.
 *
 * À la place, la valeur ambiguë est rendue BRUYANTE : on continue de servir le
 * simulacre (comportement inchangé), mais on le DIT au démarrage.
 */

/** Les deux seules écritures qui décident, sans avertissement. */
const VALEURS_EXPLICITES = new Set(['true', 'false']);

/** La valeur effectivement retenue, après la priorité MOCK_SCRAPERS > MOCK_MODE. */
function valeurBrute(env: NodeJS.ProcessEnv): string {
  return env['MOCK_SCRAPERS'] ?? env['MOCK_MODE'] ?? 'true';
}

/**
 * La valeur lue RESSEMBLE-t-elle à une désactivation sans en être une ?
 *
 * Exporté pour être mesurable : c'est le silence — pas la règle — qui était le
 * défaut de C18-017, et une garde ne peut pas certifier un `log.warn`.
 */
export function drapeauSimulacresAmbigu(env: NodeJS.ProcessEnv = process.env): boolean {
  const brut = valeurBrute(env);

  return brut !== 'false' && !VALEURS_EXPLICITES.has(brut);
}

/**
 * @returns `true` si ce worker doit utiliser le simulacre.
 *
 * `env` est injectable pour que la garde puisse mesurer la règle sans toucher
 * au processus de test.
 */
export function useMockScrapers(env: NodeJS.ProcessEnv = process.env): boolean {
  const brut = valeurBrute(env);
  const utiliseLeSimulacre = brut !== 'false';

  if (drapeauSimulacresAmbigu(env)) {
    // Le point exact où le silence coûtait cher : la valeur ressemble à une
    // désactivation sans en être une. On ne tranche pas à la place de
    // l'exploitant — on refuse juste de le laisser croire le contraire.
    log.warn(
      {
        mock_scrapers: env['MOCK_SCRAPERS'] ?? null,
        mock_mode: env['MOCK_MODE'] ?? null,
        valeur_retenue: brut,
      },
      `MOCK_SCRAPERS/MOCK_MODE vaut « ${brut} », qui n'est pas 'false' : les SIMULACRES restent actifs. ` +
        "Pour appeler les vrais services, poser exactement MOCK_SCRAPERS=false (MOCK_SCRAPERS masque MOCK_MODE).",
    );
  }

  return utiliseLeSimulacre;
}
