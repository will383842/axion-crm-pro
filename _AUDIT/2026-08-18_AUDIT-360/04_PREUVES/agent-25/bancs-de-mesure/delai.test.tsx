/**
 * AGENT 25 — COMBIEN DE TEMPS L'ÉCRAN SE TAIT-IL AVANT DE DIRE QU'IL A ÉCHOUÉ ?
 * Référence : main 8db8229. Aucun fichier du produit n'est modifié.
 *
 * Trois constantes du produit se composent :
 *   src/lib/api.ts:9      timeout: 30_000
 *   src/main.tsx:19-22    retry : jusqu'à 3 tentatives (count < 2)
 *   react-query (défaut)  retryDelay = min(1000 * 2**n, 30_000) -> 1 s puis 2 s
 * Soit, sur un serveur qui ACCEPTE la connexion et ne répond jamais
 * (c'est exactement A-010 / A-009) : 30 + 1 + 30 + 2 + 30 ≈ 93 s de silence.
 *
 * On le CHRONOMÈTRE, avec le MÊME QueryClient que la production.
 * `/admin/observability` est choisi parce que c'est l'un des DEUX seuls écrans
 * qui savent afficher une erreur de lecture : s'il met 93 s, les autres — qui
 * n'affichent jamais d'erreur — se taisent pour toujours.
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { QueryClient } from '@tanstack/react-query';
import { http } from 'msw';
import { writeFileSync, mkdirSync } from 'node:fs';

import { renderScreen } from '../../tests/helpers/renderScreen';
import { apiUrl } from '../../tests/msw/handlers';
import { ObservabilityPage } from '@/features/observability/ObservabilityPage';

/** COPIE EXACTE de `src/main.tsx:14-26`. */
function queryClientDeProduction(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        gcTime: 5 * 60_000,
        retry: (count, err) => {
          const status = (err as { response?: { status?: number } } | null)?.response?.status;
          return status !== 401 && status !== 403 && count < 2;
        },
        refetchOnWindowFocus: false,
      },
    },
  });
}

describe('AGENT 25 — délai avant le premier aveu', () => {
  it('/admin/observability, serveur muet : combien de secondes de « Chargement… » ?', async () => {
    // Le serveur accepte et ne répond jamais — le cas A-010.
    const muet = http.get(apiUrl('/observability/summary'), () => new Promise<never>(() => {}));

    const t0 = Date.now();
    await renderScreen(<ObservabilityPage />, {
      path: '/admin/observability',
      url: '/admin/observability',
      handlers: [muet],
      queryClient: queryClientDeProduction(),
    });

    // Ce que l'utilisateur voit tout de suite :
    const auDebut = (document.body.textContent ?? '').trim();

    let secondes = -1;
    let final = '';
    try {
      await waitFor(
        () => {
          expect(screen.getByText(/Impossible de charger/i)).toBeTruthy();
        },
        { timeout: 150_000, interval: 500 },
      );
      secondes = Math.round((Date.now() - t0) / 1000);
      final = (document.body.textContent ?? '').trim();
    } catch {
      secondes = -1;
      final = `JAMAIS (toujours « ${(document.body.textContent ?? '').trim()} » après 150 s)`;
    }

    mkdirSync('tmp/agent25/out', { recursive: true });
    const l = [
      'AGENT 25 — DELAI AVANT LE PREMIER AVEU D ECHEC',
      'Reference : main 8db8229',
      '',
      'Ecran        : /admin/observability (l un des 2 SEULS a savoir dire une erreur de lecture)',
      'QueryClient  : COPIE EXACTE de src/main.tsx (retry jusqu a 3 tentatives)',
      'Serveur      : accepte la connexion, ne repond JAMAIS (cas A-010 / A-009)',
      '',
      `Ce que l utilisateur voit a t=0      : « ${auDebut} »`,
      `Delai avant le message d erreur      : ${secondes} s`,
      `Ce qu il voit alors                  : « ${final} »`,
      '',
      'Composition attendue (lue dans le code, pas supposee) :',
      '  src/lib/api.ts:9    timeout: 30_000                      -> 30 s par tentative',
      '  src/main.tsx:19-22  retry: count < 2                     -> 3 tentatives',
      '  react-query defaut  retryDelay = min(1000*2**n, 30_000)  -> 1 s puis 2 s',
      '  => 30 + 1 + 30 + 2 + 30 = 93 s',
      '',
      'PORTEE : sur les 19 ecrans qui n ont AUCUN etat d erreur, ce delai n a pas',
      'de fin — passe le squelette, ils affichent « 0 » et « aucun », et ne le',
      'corrigent jamais.',
    ];
    writeFileSync('tmp/agent25/out/releve-delai.txt', l.join('\n'), 'utf8');
    // eslint-disable-next-line no-console
    console.log(l.join('\n'));
    expect(true).toBe(true);
  }, 200_000);
});
