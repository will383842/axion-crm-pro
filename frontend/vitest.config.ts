import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react-swc';
import path from 'node:path';

export default defineConfig({
  // pnpm.overrides force vite@6 partout (cf package.json), pas de mismatch type.
  plugins: [react()],
  resolve: {
    alias: { '@': path.resolve(__dirname, './src') },
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: ['./tests/setup.ts'],
    // ⚠️ Renseigner `exclude` ÉCRASE la liste par défaut de vitest : sans les
    // doubles astérisques, un `node_modules` imbriqué (ou atteint par une
    // jonction NTFS) n'est plus écarté et la collecte part dans les dépendances.
    exclude: ['**/node_modules/**', '**/dist/**', 'tests/e2e/**'],

    /**
     * 5 000 ms (le défaut) ne suffit PAS pour les tests d'écran.
     *
     * Mesuré : `LoginPage › aiguille vers /2fa` et
     * `AudienceBuilderPage › nommer puis créer` passent seuls en ~1,8 s et
     * EXPIRENT dans la suite complète, où une vingtaine de fichiers jsdom
     * tournent en parallèle. Ce n'est pas un défaut d'écran, c'est la charge :
     * un écran réel enchaîne frappe `user-event` (une frappe = un rendu),
     * anti-rebond de 500 ms et aller-retour MSW.
     *
     * Un faux rouge intermittent coûte plus cher qu'une marge : on la prend.
     */
    testTimeout: 20_000,
    hookTimeout: 20_000,

    coverage: {
      provider: 'v8',
      reporter: ['text', 'html', 'lcov'],
      /**
       * ⚠️ SEUILS DÉCORATIFS EN L'ÉTAT. La CI
       * (`.github/workflows/ci.yml:295-297`) lance `pnpm test`, jamais
       * `pnpm test:coverage` : ces nombres ne bloquent rien et n'ont jamais
       * rien bloqué. Les rendre mordants suppose de changer le workflow (hors
       * périmètre de ce lot) — voir `tests/README.md`.
       *
       * ═══ 🔴 CES SEUILS N'ONT JAMAIS ÉTÉ ATTEINTS (mesure du 2026-08-24) ═══
       *
       * Ils l'ont PARU, et c'est pire. Sous vitest 2, `pnpm test:coverage`
       * annonçait 64,13 % de fonctions — au-dessus de la cible de 60. La montée
       * en vitest 3 fait tomber le chiffre à 55,95 %, et le premier réflexe
       * serait d'y voir une régression. Ce n'en est pas une : c'est la mesure
       * qui cesse de mentir.
       *
       * Comparaison des deux rapports `lcov`, le même jour, sur le même code :
       *
       *              fichiers   fonctions   couvertes
       *   vitest 2      189         881        565   (64,13 %)
       *   vitest 3      112         597        334   (55,95 %)
       *
       * Les 77 fichiers de l'écart sont TOUS des fichiers de `tests/` —
       * vérifié un par un par différence des listes `SF:`. vitest 2 comptait
       * les fichiers de test EUX-MÊMES dans la couverture ; comme ils
       * s'exécutent par définition, ils y entraient couverts à ~100 % et
       * gonflaient le total. Les 284 fonctions retirées l'étaient à 81 %.
       *
       * Autrement dit : la couverture réelle du CODE SOURCE est de 55,95 %, et
       * elle l'a toujours été. Le dépassement du seuil était un artefact de
       * périmètre.
       *
       * ⚠️ LES SEUILS NE SONT DONC PAS BAISSÉS. Les aligner sur le chiffre réel
       * reviendrait à entériner un objectif qu'on n'a jamais tenu, au moment
       * précis où l'on découvre qu'on ne le tenait pas. Ils restent à 60 : il
       * manque 31 fonctions couvertes sur les 567 du code source pour les
       * atteindre pour de bon. Les plus gros déficits, mesurés :
       * `CompaniesListPage` (31), `CampaignDetailPage` (25), `ScraperRunsPage`
       * (21), `RgpdRequestsPage` (19), `AudienceDetailPage` (15).
       */
      thresholds: { lines: 60, statements: 60, functions: 60, branches: 50 },
      // `tests/` est DÉCLARÉ ici, et ne l'était pas. vitest 3 l'exclut déjà de
      // lui-même — c'est ce qui a révélé l'artefact ci-dessus. On l'écrit pour
      // que le périmètre soit un choix lisible et non un défaut de version :
      // si un jour l'outil changeait d'avis, la mesure ne bougerait pas.
      exclude: ['node_modules/', 'dist/', 'tests/', '**/*.config.{ts,js}'],
    },
  },
});
